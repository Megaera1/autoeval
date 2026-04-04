<?php

namespace App\Controller;

use App\Entity\QuestionnaireResponse;
use App\Entity\User;
use App\Repository\QuestionnaireRepository;
use App\Repository\QuestionnaireResponseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_PATIENT')]
class ApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private QuestionnaireRepository $questionnaireRepository,
        private QuestionnaireResponseRepository $responseRepository,
    ) {
    }

    /**
     * POST /api/questionnaires/save
     *
     * Body (JSON):
     * {
     *   "patient_id":       int,
     *   "questionnaire_id": int,
     *   "answers":          object,   // { "A1": 2, "D1": 0, ... }
     *   "scores":           object,   // { "anxiete": 12, "depression": 5 } — optional
     *   "date":             string    // ISO-8601, optional — defaults to now
     * }
     *
     * Authorization:
     *   - ROLE_PATIENT  → may only save for themselves (patient_id must match session user)
     *   - ROLE_NEUROPSYCHOLOGUE → may save for any patient
     */
    #[Route('/questionnaires/save', name: 'api_questionnaires_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        // Enforce JSON content-type (also prevents classic CSRF via HTML forms)
        if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
            return $this->json(['error' => 'Content-Type must be application/json.'], 415);
        }

        $body = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON body.'], 400);
        }

        // ── Validate required fields ──────────────────────────────────────────
        $patientId       = $body['patient_id']       ?? null;
        $questionnaireId = $body['questionnaire_id'] ?? null;
        $answers         = $body['answers']          ?? null;

        if (!is_int($patientId) || !is_int($questionnaireId) || !is_array($answers)) {
            return $this->json([
                'error' => 'patient_id (int), questionnaire_id (int) and answers (object) are required.',
            ], 422);
        }

        // ── Authorization ─────────────────────────────────────────────────────
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $isNeuropsychologue = in_array('ROLE_NEUROPSYCHOLOGUE', $currentUser->getRoles(), true);

        if (!$isNeuropsychologue && $currentUser->getId() !== $patientId) {
            return $this->json(['error' => 'You may only save your own questionnaire responses.'], 403);
        }

        // ── Load entities ─────────────────────────────────────────────────────
        $patient = $this->userRepository->find($patientId);
        if (!$patient) {
            return $this->json(['error' => "Patient $patientId not found."], 404);
        }

        $questionnaire = $this->questionnaireRepository->find($questionnaireId);
        if (!$questionnaire) {
            return $this->json(['error' => "Questionnaire $questionnaireId not found."], 404);
        }

        // ── Merge sub-scores into answers ─────────────────────────────────────
        $scores = $body['scores'] ?? [];
        if (is_array($scores)) {
            foreach ($scores as $key => $value) {
                // Store as _score_anxiete, _score_depression, etc.
                $answers['_score_' . $key] = $value;
            }
        }

        // ── Find or create response ───────────────────────────────────────────
        // Reuse the latest incomplete response for this patient+questionnaire,
        // or create a new one.
        $response = $this->responseRepository->findOneBy(
            ['patient' => $patient, 'questionnaire' => $questionnaire, 'isComplete' => false],
            ['startedAt' => 'DESC'],
        );

        if (!$response) {
            $response = new QuestionnaireResponse();
            $response->setPatient($patient);
            $response->setQuestionnaire($questionnaire);
            $this->em->persist($response);
        }

        // ── Persist ───────────────────────────────────────────────────────────
        $response->setAnswers($answers);
        $response->setIsComplete(true);
        $response->setCompletedAt(new \DateTime());

        // Parse optional date
        $dateRaw = $body['date'] ?? null;
        if (is_string($dateRaw)) {
            try {
                $response->setCompletedAt(new \DateTime($dateRaw));
            } catch (\Exception) {
                // Invalid date — fall back to now, already set above
            }
        }

        // Total score = sum of all numeric non-meta answers
        $totalScore = 0.0;
        foreach ($answers as $key => $value) {
            if (!str_starts_with((string) $key, '_') && is_numeric($value)) {
                $totalScore += (float) $value;
            }
        }
        $response->setScore($totalScore);

        $this->em->flush();

        return $this->json([
            'success'     => true,
            'response_id' => $response->getId(),
            'patient_id'  => $patient->getId(),
            'score'       => $response->getScore(),
            'scores'      => $scores,
            'completed_at'=> $response->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ], 201);
    }
}
