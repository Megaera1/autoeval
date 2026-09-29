<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use App\Service\AdminNotificationService;
use App\Service\EmailVerificationService;
use App\Service\WelcomeMailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationInterface;

class RegistrationController extends AbstractController
{
    /** Délai minimum (secondes) entre l'affichage du formulaire et sa soumission. */
    private const MIN_FILL_SECONDS = 3;
    private const SESSION_FORM_STARTED_AT = 'registration_form_started_at';

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        EmailVerificationService $emailVerificationService,
        UserRepository $userRepository,
        #[Target('registration.limiter')] RateLimiterFactoryInterface $registrationLimiter,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_patient_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $limit = $registrationLimiter->create($request->getClientIp())->consume();
            if (!$limit->isAccepted()) {
                $this->addFlash('error', 'Trop de tentatives d\'inscription. Veuillez réessayer plus tard.');

                return $this->renderRegistrationForm($request, $form, Response::HTTP_TOO_MANY_REQUESTS);
            }

            // Formulaire soumis sans avoir été affiché dans cette session (robot qui poste
            // directement, ou session expirée) : on le ré-affiche sans créer de compte.
            if (!is_int($request->getSession()->get(self::SESSION_FORM_STARTED_AT))) {
                $this->addFlash('error', 'Votre session a expiré. Vérifiez vos informations et validez à nouveau le formulaire.');

                return $this->renderRegistrationForm($request, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($this->looksLikeBot($request, $form->get('contactRef')->getData())) {
                $logger->warning('Inscription rejetée (anti-bot) depuis {ip}', ['ip' => $request->getClientIp()]);

                // Même réponse qu'une inscription réussie : le bot n'apprend rien
                return $this->redirectToRoute('app_register_check_email');
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setRoles(['ROLE_PATIENT']);
            $user->setIsVerified(false);
            $user->setConsentAccepted(true);
            $user->setConsentAcceptedAt(new \DateTimeImmutable());

            $entityManager->persist($user);
            $entityManager->flush();

            try {
                $emailVerificationService->sendVerificationMail($user);
            } catch (\Throwable $e) {
                $logger->error('Échec de l\'envoi de l\'email de vérification pour {email} : {message}', [
                    'email' => $user->getEmail(),
                    'message' => $e->getMessage(),
                ]);
            }

            $request->getSession()->remove(self::SESSION_FORM_STARTED_AT);

            return $this->redirectToRoute('app_register_check_email');
        }

        // Email déjà inscrit (seule erreur du formulaire) : même réponse qu'une inscription
        // réussie, pour ne pas révéler à l'écran qui possède un compte. Le titulaire est
        // prévenu par email.
        if ($form->isSubmitted() && $this->hasOnlyDuplicateEmailError($form)) {
            $existing = $userRepository->findOneBy(['email' => $user->getEmail()]);

            if ($existing !== null) {
                try {
                    $existing->isVerified()
                        ? $emailVerificationService->sendAlreadyRegisteredMail($existing)
                        : $emailVerificationService->sendVerificationMail($existing);
                } catch (\Throwable $e) {
                    $logger->error('Échec de l\'envoi de l\'email « compte existant » pour {email} : {message}', [
                        'email' => $existing->getEmail(),
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $request->getSession()->remove(self::SESSION_FORM_STARTED_AT);

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->renderRegistrationForm($request, $form);
    }

    #[Route('/register/check-email', name: 'app_register_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('registration/check_email.html.twig');
    }

    #[Route('/register/verify/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(
        string $token,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        WelcomeMailService $welcomeMailService,
        AdminNotificationService $adminNotificationService,
        LoggerInterface $logger,
    ): Response {
        $user = $userRepository->findOneBy(['verificationToken' => $token]);

        if ($user === null) {
            $this->addFlash('error', 'Ce lien de confirmation est invalide ou a déjà été utilisé.');

            return $this->redirectToRoute('app_login');
        }

        if ($user->getVerificationTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('error', 'Ce lien de confirmation a expiré. Demandez-en un nouveau ci-dessous.');

            return $this->redirectToRoute('app_verify_email_resend');
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $user->setVerificationTokenExpiresAt(null);
        $entityManager->flush();

        try {
            $welcomeMailService->sendWelcomeMail($user);
        } catch (\Throwable $e) {
            $logger->error('Échec de l\'envoi de l\'email de bienvenue pour {email} : {message}', [
                'email' => $user->getEmail(),
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $adminNotificationService->sendNewPatientNotification($user);
        } catch (\Throwable $e) {
            $logger->error('Échec de la notification admin pour le patient {email} : {message}', [
                'email' => $user->getEmail(),
                'message' => $e->getMessage(),
            ]);
        }

        $this->addFlash('success', 'Votre adresse email est confirmée. Vous pouvez maintenant vous connecter.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/register/verify-resend', name: 'app_verify_email_resend', methods: ['GET', 'POST'])]
    public function resendVerificationEmail(
        Request $request,
        UserRepository $userRepository,
        EmailVerificationService $emailVerificationService,
        #[Target('verification_resend.limiter')] RateLimiterFactoryInterface $resendLimiter,
        LoggerInterface $logger,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $resendLimiter->create($request->getClientIp())->consume();
            if (!$limit->isAccepted()) {
                $this->addFlash('error', 'Trop de demandes. Veuillez réessayer plus tard.');

                return $this->redirectToRoute('app_verify_email_resend');
            }

            $user = $userRepository->findOneBy(['email' => $form->get('email')->getData()]);

            if ($user !== null && !$user->isVerified()) {
                try {
                    $emailVerificationService->sendVerificationMail($user);
                } catch (\Throwable $e) {
                    $logger->error('Échec du renvoi de l\'email de vérification pour {email} : {message}', [
                        'email' => $user->getEmail(),
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            // Message générique : ne révèle pas si l'email existe ni son statut
            $this->addFlash('success', 'Si un compte en attente de confirmation correspond à cet email, un nouveau lien vous a été envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/resend_verification.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Vrai si le formulaire n'est invalide QUE parce que l'email est déjà utilisé
     * (contrainte UniqueEntity de User) : toutes les autres données sont correctes.
     */
    private function hasOnlyDuplicateEmailError(FormInterface $form): bool
    {
        $errors = iterator_to_array($form->getErrors(true), false);

        if ($errors === []) {
            return false;
        }

        foreach ($errors as $error) {
            $cause = $error->getCause();
            if (!$cause instanceof ConstraintViolationInterface || !$cause->getConstraint() instanceof UniqueEntity) {
                return false;
            }
        }

        return true;
    }

    /**
     * Honeypot rempli, ou formulaire soumis trop vite après son affichage : comportement de robot.
     */
    private function looksLikeBot(Request $request, ?string $honeypot): bool
    {
        if ($honeypot !== null && trim($honeypot) !== '') {
            return true;
        }

        $startedAt = (int) $request->getSession()->get(self::SESSION_FORM_STARTED_AT);

        return (time() - $startedAt) < self::MIN_FILL_SECONDS;
    }

    private function renderRegistrationForm(Request $request, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $request->getSession()->set(self::SESSION_FORM_STARTED_AT, time());

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ], new Response(null, $status));
    }
}
