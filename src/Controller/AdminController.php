<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\AdminPatientCredentialsFormType;
use App\Repository\UserRepository;
use App\Service\AssignmentNotificationService;
use App\Service\NeuropsychologueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_NEUROPSYCHOLOGUE')]
class AdminController extends AbstractController
{
    public function __construct(
        private NeuropsychologueService $neuropsychologueService,
        private AssignmentNotificationService $assignmentNotificationService,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/dashboard', name: 'app_admin_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'patientRows' => $this->neuropsychologueService->getAllPatients(),
            'stats'       => $this->neuropsychologueService->getStats(),
        ]);
    }

    #[Route('/patient/{id}', name: 'app_admin_patient_profile', requirements: ['id' => '\d+'])]
    public function patientProfile(int $id): Response
    {
        $profile = $this->neuropsychologueService->getPatientFullProfile($id);

        return $this->render('admin/patient_profile.html.twig', $profile);
    }

    #[Route('/patient/{id}/questionnaires', name: 'app_admin_assign_questionnaires', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignQuestionnaires(int $id, Request $request, UserRepository $userRepository): Response
    {
        $patient = $userRepository->find($id);
        if (!$patient) {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        /** @var User $neuropsychologue */
        $neuropsychologue = $this->getUser();
        $questionnaireIds = array_map('intval', $request->request->all('questionnaires') ?: []);

        $newlyAssigned = $this->neuropsychologueService->assignQuestionnaires($patient, $questionnaireIds, $neuropsychologue);

        if (!empty($newlyAssigned)) {
            try {
                $this->assignmentNotificationService->notifyPatient($patient, $newlyAssigned);
                $this->addFlash('success', 'Les questionnaires ont été assignés et le patient notifié par email.');
            } catch (\Throwable $e) {
                $this->logger->error('Échec de la notification d\'assignation pour {email} : {message}', [
                    'email' => $patient->getEmail(),
                    'message' => $e->getMessage(),
                ]);
                $this->addFlash('success', 'Les questionnaires ont été assignés. (La notification email n\'a pas pu être envoyée.)');
            }
        } else {
            $this->addFlash('success', 'Questionnaires mis à jour pour ' . $patient->getFullName() . '.');
        }

        return $this->redirectToRoute('app_admin_patient_profile', ['id' => $id]);
    }

    #[Route('/patient/{id}/credentials', name: 'app_admin_patient_credentials', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editCredentials(int $id, Request $request, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_NEUROPSYCHOLOGUE');

        $patient = $userRepository->find($id);
        if (!$patient) {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        $form = $this->createForm(AdminPatientCredentialsFormType::class, $patient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hasError = false;

            // Unicité email (exclure le patient lui-même)
            $newEmail = $form->get('email')->getData();
            $existing = $userRepository->findOneBy(['email' => $newEmail]);
            if ($existing !== null && $existing->getId() !== $patient->getId()) {
                $form->get('email')->addError(new FormError('Cette adresse email est déjà utilisée par un autre compte.'));
                $hasError = true;
            }

            $newPassword = $form->get('newPassword')->getData();
            $newPasswordConfirm = $form->get('newPasswordConfirm')->getData();

            if ($newPassword !== null && $newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    $form->get('newPassword')->addError(new FormError('Le mot de passe doit contenir au moins 8 caractères.'));
                    $hasError = true;
                } elseif ($newPassword !== $newPasswordConfirm) {
                    $form->get('newPasswordConfirm')->addError(new FormError('Les mots de passe ne correspondent pas.'));
                    $hasError = true;
                }
            }

            if (!$hasError) {
                $patient->setEmail($newEmail);

                if ($newPassword !== null && $newPassword !== '') {
                    $patient->setPassword($passwordHasher->hashPassword($patient, $newPassword));
                }

                $this->em->flush();

                $this->logger->info('Admin a modifié les identifiants du patient {id} à {datetime}', [
                    'id' => $patient->getId(),
                    'datetime' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);

                $this->addFlash('success', 'Identifiants mis à jour.');
                return $this->redirectToRoute('app_admin_patient_profile', ['id' => $id]);
            }
        }

        return $this->render('admin/patient_credentials.html.twig', [
            'form' => $form,
            'patient' => $patient,
        ]);
    }

    #[Route('/patient/{id}/delete', name: 'app_admin_patient_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_NEUROPSYCHOLOGUE')]
    public function deletePatient(int $id, Request $request, UserRepository $userRepository, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('delete_patient_' . $id, $request->request->get('_token')))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $patient = $userRepository->find($id);
        if (!$patient) {
            throw $this->createNotFoundException('Patient introuvable.');
        }
        if (!in_array('ROLE_PATIENT', $patient->getRoles(), true)) {
            throw $this->createAccessDeniedException('Ce compte n\'est pas un patient.');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->neuropsychologueService->deletePatientAccount($patient);

        $this->logger->info('Patient #{patientId} supprimé par neuropsychologue #{adminId}', [
            'patientId' => $id,
            'adminId'   => $admin->getId(),
        ]);

        $this->addFlash('success', 'Le compte du patient et toutes ses données ont été supprimés définitivement.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/patient/{id}/export', name: 'app_admin_patient_export', requirements: ['id' => '\d+'])]
    public function exportPatient(int $id, UserRepository $userRepository): Response
    {
        $patient = $userRepository->find($id);
        if (!$patient) {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        $content  = $this->neuropsychologueService->exportPatientTxt($patient);
        $filename = sprintf(
            'dossier_%s_%s_%s.txt',
            strtolower($patient->getLastName()),
            strtolower($patient->getFirstName()),
            (new \DateTime())->format('Ymd'),
        );

        return new Response($content, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
