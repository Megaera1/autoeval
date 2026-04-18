<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\LoginSuccessHandler;
use App\Service\AdminNotificationService;
use App\Service\WelcomeMailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        Security $security,
        WelcomeMailService $welcomeMailService,
        AdminNotificationService $adminNotificationService,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_patient_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setRoles(['ROLE_PATIENT']);
            $user->setConsentAccepted(true);
            $user->setConsentAcceptedAt(new \DateTimeImmutable());

            $entityManager->persist($user);
            $entityManager->flush();

            try {
                $welcomeMailService->sendWelcomeMail($user, $plainPassword);
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

            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
