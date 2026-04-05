<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\PatientProfileFormType;
use App\Repository\QuestionnaireRepository;
use App\Repository\QuestionnaireResponseRepository;
use App\Service\PatientService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/patient')]
#[IsGranted('PATIENT_ONLY')]
class PatientController extends AbstractController
{
    public function __construct(
        private PatientService $patientService,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/profile', name: 'app_patient_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(PatientProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hasError = false;

            $currentPassword = $form->get('currentPassword')->getData();
            $newPassword     = $form->get('newPassword')->getData();

            if ($currentPassword !== null && $currentPassword !== '') {
                if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $form->get('currentPassword')->addError(
                        new FormError('Mot de passe actuel incorrect.')
                    );
                    $hasError = true;
                } elseif ($newPassword !== null && $newPassword !== '') {
                    if (strlen($newPassword) < 8) {
                        $form->get('newPassword')->addError(
                            new FormError('Le mot de passe doit contenir au moins 8 caractères.')
                        );
                        $hasError = true;
                    } elseif (!preg_match('/\d/', $newPassword)) {
                        $form->get('newPassword')->addError(
                            new FormError('Le mot de passe doit contenir au moins un chiffre.')
                        );
                        $hasError = true;
                    } else {
                        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                    }
                }
            }

            if (!$hasError) {
                $this->em->flush();
                $this->addFlash('success', 'Votre profil a été mis à jour.');

                return $this->redirectToRoute('app_patient_profile');
            }
        }

        return $this->render('patient/profile.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/dashboard', name: 'app_patient_dashboard')]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $anamnesis = $this->patientService->getOrCreateAnamnesis($user);

        return $this->render('patient/dashboard.html.twig', [
            'anamnesis'      => $anamnesis,
            'availableCount' => $this->patientService->countAvailableQuestionnaires($user),
            'completedCount' => $this->patientService->countCompletedQuestionnaires($user),
        ]);
    }

    #[Route('/anamnesis', name: 'app_patient_anamnesis', methods: ['GET', 'POST'])]
    public function anamnesis(Request $request): Response
    {
        /** @var User $user */
        $user      = $this->getUser();
        $anamnesis = $this->patientService->getOrCreateAnamnesis($user);

        // Determine form type: use saved _form_type if present, otherwise derive from age
        $existingData = $anamnesis->getData();
        $formType     = $existingData['_form_type'] ?? $this->patientService->getAnamnesisFormType($user);
        $definition   = $this->patientService->loadAnamnesisDefinition($formType);

        if ($request->isMethod('POST')) {
            $fields   = $request->request->all('fields');
            $action   = $request->request->get('action');
            $formType = $request->request->get('_form_type', $formType);

            // Sanitize: only keep field IDs defined in the JSON
            $allowedIds = [];
            foreach ($definition['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    $allowedIds[] = $field['id'];
                }
            }
            $filtered = array_filter(
                $fields,
                static fn($key) => in_array($key, $allowedIds, true),
                ARRAY_FILTER_USE_KEY
            );

            $data = array_merge(['_form_type' => $formType], $filtered);
            $anamnesis->setData($data);

            if ($action === 'complete') {
                $anamnesis->setIsComplete(true);
                $this->addFlash('success', 'Votre anamnèse a été enregistrée et marquée comme terminée.');
            } else {
                $this->addFlash('success', 'Votre anamnèse a été enregistrée.');
            }

            $this->em->flush();

            return $this->redirectToRoute('app_patient_anamnesis');
        }

        return $this->render('patient/anamnesis.html.twig', [
            'anamnesis'    => $anamnesis,
            'definition'   => $definition,
            'formType'     => $formType,
            'existingData' => $existingData,
        ]);
    }

    #[Route('/questionnaires', name: 'app_patient_questionnaires')]
    public function questionnaires(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('patient/questionnaires.html.twig', [
            'items' => $this->patientService->getAvailableQuestionnaires($user),
        ]);
    }

    /**
     * Always creates a brand-new blank response and redirects to the fill page.
     */
    #[Route('/questionnaires/{slug}/new', name: 'app_patient_questionnaire_new', methods: ['GET'])]
    public function newQuestionnaire(
        QuestionnaireRepository $questionnaireRepository,
        string $slug,
    ): Response {
        $questionnaire = $questionnaireRepository->findOneBy(['slug' => $slug, 'isActive' => true]);
        if (!$questionnaire) {
            throw $this->createNotFoundException('Questionnaire introuvable.');
        }

        /** @var User $user */
        $user     = $this->getUser();
        $response = $this->patientService->createNewResponse($user, $questionnaire);

        return $this->redirectToRoute('app_patient_questionnaire_fill', [
            'slug'       => $slug,
            'responseId' => $response->getId(),
        ]);
    }

    #[Route('/questionnaires/{slug}/autosave', name: 'app_patient_questionnaire_autosave', methods: ['POST'])]
    public function autosaveQuestionnaire(
        Request $request,
        QuestionnaireRepository $questionnaireRepository,
        QuestionnaireResponseRepository $responseRepository,
        string $slug,
    ): JsonResponse {
        $questionnaire = $questionnaireRepository->findOneBy(['slug' => $slug, 'isActive' => true]);
        if (!$questionnaire) {
            return $this->json(['status' => 'error'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        $responseId = $request->query->getInt('responseId', 0);
        if ($responseId > 0) {
            $response = $responseRepository->find($responseId);
            if (!$response
                || $response->getPatient() !== $user
                || $response->getQuestionnaire() !== $questionnaire
                || $response->isComplete()
            ) {
                return $this->json(['status' => 'error'], 403);
            }
        } else {
            $response = $this->patientService->getOrCreateResponse($user, $questionnaire);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['answers']) && is_array($data['answers'])) {
            $merged = array_merge($response->getAnswers() ?? [], $data['answers']);
            $response->setAnswers($merged);
            $this->em->flush();
        }

        return $this->json(['status' => 'ok']);
    }

    #[Route('/questionnaires/{slug}', name: 'app_patient_questionnaire_fill', methods: ['GET', 'POST'])]
    public function fillQuestionnaire(
        Request $request,
        QuestionnaireRepository $questionnaireRepository,
        QuestionnaireResponseRepository $responseRepository,
        string $slug,
    ): Response {
        $questionnaire = $questionnaireRepository->findOneBy(['slug' => $slug, 'isActive' => true]);
        if (!$questionnaire) {
            throw $this->createNotFoundException('Questionnaire introuvable.');
        }

        /** @var User $user */
        $user     = $this->getUser();
        $readOnly = false;

        $responseId = $request->query->getInt('responseId', 0);
        if ($responseId > 0) {
            $response = $responseRepository->find($responseId);
            if (!$response
                || $response->getPatient() !== $user
                || $response->getQuestionnaire() !== $questionnaire
            ) {
                throw $this->createNotFoundException('Passation introuvable.');
            }
            $readOnly = $response->isComplete();
        } else {
            $response = $this->patientService->getOrCreateResponse($user, $questionnaire);
        }

        $questions = $questionnaire->getQuestions();

        if ($request->isMethod('POST')) {
            if ($readOnly) {
                return $this->redirectToRoute('app_patient_questionnaires');
            }

            $submittedAnswers = $request->request->all('answers');
            $action           = $request->request->get('action');

            // DIVA mode: compute section sub-scores on final submission
            if ($action === 'complete' && !empty($questions['diva_mode'])) {
                $totalOui = 0;
                foreach ($questions['sections'] as $section) {
                    if ($section['has_periods']) {
                        $adultScore = 0;
                        $childScore = 0;
                        foreach ($section['items'] as $item) {
                            if (($submittedAnswers[$item['id'] . '_adult'] ?? '') === 'oui') {
                                ++$adultScore;
                                ++$totalOui;
                            }
                            if (($submittedAnswers[$item['id'] . '_child'] ?? '') === 'oui') {
                                ++$childScore;
                            }
                        }
                        $submittedAnswers['_score_' . $section['id'] . '_adult'] = $adultScore;
                        $submittedAnswers['_score_' . $section['id'] . '_child'] = $childScore;
                    } else {
                        $sectionScore = 0;
                        foreach ($section['items'] as $item) {
                            if (($submittedAnswers[$item['id']] ?? '') === 'oui') {
                                ++$sectionScore;
                            }
                        }
                        $submittedAnswers['_score_' . $section['id']] = $sectionScore;
                        $totalOui += $sectionScore;
                    }
                }
            }

            // Rich APMT format: compute sub-scores and embed them in answers
            if ($action === 'complete' && isset($questions['sous_echelles'])) {
                $hasOmissionCorrection = !empty($questions['omission_correction']);
                $omissionDefaultScore  = isset($questions['omission_default_score'])
                    ? (float) $questions['omission_default_score']
                    : null;
                foreach ($questions['sous_echelles'] as $se) {
                    $seScore   = 0;
                    $omissions = 0;
                    foreach ($se['items_ids'] as $itemId) {
                        $val = $submittedAnswers[$itemId] ?? null;
                        if ($val === null || $val === '') {
                            ++$omissions;
                            if ($omissionDefaultScore !== null) {
                                $seScore += $omissionDefaultScore;
                            }
                        } else {
                            $seScore += (float) $val;
                        }
                    }
                    $submittedAnswers['_score_' . $se['id']] = $seScore;
                    if ($omissionDefaultScore !== null) {
                        $submittedAnswers['_score_' . $se['id'] . '_omissions'] = $omissions;
                    }
                    if ($hasOmissionCorrection) {
                        $nbItems    = count($se['items_ids']);
                        $nbAnswered = $nbItems - $omissions;
                        $submittedAnswers['_score_' . $se['id'] . '_omissions'] = $omissions;
                        $submittedAnswers['_score_' . $se['id'] . '_corrected'] = ($omissions <= 2 && $nbAnswered > 0)
                            ? (int) round($seScore * $nbItems / $nbAnswered)
                            : null;
                    }
                }
            }

            $response->setAnswers($submittedAnswers);

            if ($action === 'complete') {
                $response->setIsComplete(true);
                $response->setCompletedAt(new \DateTime());
                $response->setScore($this->calculateScore($questions, $submittedAnswers));
                $this->addFlash('success', 'Questionnaire terminé ! Vos réponses ont été enregistrées.');
            } else {
                $this->addFlash('success', 'Vos réponses ont été sauvegardées.');
            }

            $this->em->flush();

            if ($action === 'complete') {
                return $this->redirectToRoute('app_patient_questionnaires');
            }

            return $this->redirectToRoute('app_patient_questionnaire_fill', [
                'slug'       => $slug,
                'responseId' => $response->getId(),
            ]);
        }

        $template = !empty($questions['diva_mode'])
            ? 'patient/diva_fill.html.twig'
            : 'patient/questionnaire_fill.html.twig';

        return $this->render($template, [
            'questionnaire' => $questionnaire,
            'response'      => $response,
            'readOnly'      => $readOnly,
        ]);
    }

    #[Route('/questionnaires/{slug}/history', name: 'app_patient_questionnaire_history')]
    public function questionnaireHistory(
        QuestionnaireRepository $questionnaireRepository,
        string $slug,
    ): Response {
        $questionnaire = $questionnaireRepository->findOneBy(['slug' => $slug]);
        if (!$questionnaire) {
            throw $this->createNotFoundException('Questionnaire introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        return $this->render('patient/questionnaire_history.html.twig', [
            'questionnaire' => $questionnaire,
            'responses' => $this->patientService->getResponseHistory($user, $questionnaire),
        ]);
    }

    private function calculateScore(array $questions, array $answers): float
    {
        // DIVA mode: count total "oui" responses in adult sections
        if (!empty($questions['diva_mode'])) {
            $total = 0;
            foreach ($questions['sections'] as $section) {
                $key = '_score_' . $section['id'] . ($section['has_periods'] ? '_adult' : '');
                if (isset($answers[$key]) && is_numeric($answers[$key])) {
                    $total += (float) $answers[$key];
                }
            }
            return $total;
        }

        // Rich format (HAD-like): sum all item answers
        if (isset($questions['items'])) {
            $total = 0;
            foreach ($questions['items'] as $item) {
                $val = $answers[$item['id']] ?? null;
                if ($val !== null && is_numeric($val)) {
                    $total += (float) $val;
                }
            }
            return $total;
        }

        // Legacy simple format: sum numeric answers indexed by position
        $total = 0;
        foreach ($questions as $index => $question) {
            $val = $answers[(string) $index] ?? null;
            if ($val !== null && is_numeric($val)) {
                $total += (float) $val;
            }
        }
        return $total;
    }
}
