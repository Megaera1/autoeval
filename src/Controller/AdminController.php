<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\NeuropsychologueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_NEUROPSYCHOLOGUE')]
class AdminController extends AbstractController
{
    public function __construct(
        private NeuropsychologueService $neuropsychologueService,
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
        $neuropsychologue   = $this->getUser();
        $questionnaireIds   = array_map('intval', $request->request->all('questionnaires') ?: []);

        $this->neuropsychologueService->assignQuestionnaires($patient, $questionnaireIds, $neuropsychologue);

        $this->addFlash('success', 'Questionnaires mis à jour pour ' . $patient->getFullName() . '.');

        return $this->redirectToRoute('app_admin_patient_profile', ['id' => $id]);
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
