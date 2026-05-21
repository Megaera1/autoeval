<?php

namespace App\Service;

use App\Entity\Anamnesis;
use App\Entity\AssignedQuestionnaire;
use App\Entity\Questionnaire;
use App\Entity\QuestionnaireResponse;
use App\Entity\User;
use App\Repository\AnamnesisRepository;
use App\Repository\AssignedQuestionnaireRepository;
use App\Repository\QuestionnaireRepository;
use App\Repository\QuestionnaireResponseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PatientService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AnamnesisRepository $anamnesisRepository,
        private QuestionnaireRepository $questionnaireRepository,
        private QuestionnaireResponseRepository $responseRepository,
        private AssignedQuestionnaireRepository $assignedQuestionnaireRepository,
        #[Autowire('%kernel.project_dir%')] private string $projectDir = '',
    ) {
    }

    public function getAnamnesisFormType(User $user): string
    {
        $age = $user->getCurrentAge();
        if ($age === null) {
            return 'adulte';
        }

        return match (true) {
            $age <= 12 => 'enfant',
            $age <= 18 => 'adolescent',
            $age <= 64 => 'adulte',
            default    => 'senior',
        };
    }

    public function loadAnamnesisDefinition(string $formType): array
    {
        $path = $this->projectDir . '/questionnaires/anamnese_' . $formType . '.json';

        return json_decode(file_get_contents($path), true);
    }

    public function getOrCreateAnamnesis(User $user): Anamnesis
    {
        $anamnesis = $this->anamnesisRepository->findOneBy(['patient' => $user]);

        if (!$anamnesis) {
            $anamnesis = new Anamnesis();
            $anamnesis->setPatient($user);
            $this->em->persist($anamnesis);
            $this->em->flush();
        }

        return $anamnesis;
    }

    /**
     * Returns the questionnaires assigned to this patient, with their passation history.
     *
     * @return array{
     *   questionnaire: Questionnaire,
     *   inProgress: ?QuestionnaireResponse,
     *   responses: QuestionnaireResponse[]
     * }[]
     */
    public function getAvailableQuestionnaires(User $user): array
    {
        $assigned = $this->assignedQuestionnaireRepository->findBy(['patient' => $user]);

        usort($assigned, static function (AssignedQuestionnaire $a, AssignedQuestionnaire $b): int {
            $cmp = strcmp(
                $a->getQuestionnaire()->getCategory() ?? '',
                $b->getQuestionnaire()->getCategory() ?? ''
            );

            return $cmp !== 0
                ? $cmp
                : strcmp($a->getQuestionnaire()->getTitle() ?? '', $b->getQuestionnaire()->getTitle() ?? '');
        });

        $result = [];
        foreach ($assigned as $assignment) {
            $questionnaire = $assignment->getQuestionnaire();
            if (!$questionnaire->isActive()) {
                continue;
            }

            $responses = $this->responseRepository->findBy(
                ['patient' => $user, 'questionnaire' => $questionnaire],
                ['startedAt' => 'DESC']
            );

            $result[] = [
                'questionnaire' => $questionnaire,
                'inProgress'    => $this->findResumableResponse($user, $questionnaire),
                'responses'     => $responses,
            ];
        }

        return $result;
    }

    /**
     * Returns the most recent in-progress response that actually contains at least one
     * real item answer (i.e. is genuinely "resumable"). Empty rows (no item answer yet)
     * are ignored so they never appear as "En cours / Reprendre".
     */
    public function findResumableResponse(User $user, Questionnaire $questionnaire): ?QuestionnaireResponse
    {
        $candidates = $this->responseRepository->findBy(
            ['patient' => $user, 'questionnaire' => $questionnaire, 'isComplete' => false],
            ['startedAt' => 'DESC']
        );

        foreach ($candidates as $candidate) {
            if (self::hasRealAnswers($candidate->getAnswers())) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the most recent empty in-progress response for this couple — used to
     * recycle a phantom row instead of stacking a new one on top.
     */
    private function findEmptyResponse(User $user, Questionnaire $questionnaire): ?QuestionnaireResponse
    {
        $candidates = $this->responseRepository->findBy(
            ['patient' => $user, 'questionnaire' => $questionnaire, 'isComplete' => false],
            ['startedAt' => 'DESC']
        );

        foreach ($candidates as $candidate) {
            if (!self::hasRealAnswers($candidate->getAnswers())) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the response to load when the patient resumes work on a questionnaire.
     * Order of preference:
     *   1. an existing resumable (non-empty in-progress) response,
     *   2. an existing empty in-progress row that can be reused (avoids stacking phantoms),
     *   3. a brand-new response.
     */
    public function getOrCreateResponse(User $user, Questionnaire $questionnaire): QuestionnaireResponse
    {
        return $this->findResumableResponse($user, $questionnaire)
            ?? $this->findEmptyResponse($user, $questionnaire)
            ?? $this->createNewResponse($user, $questionnaire);
    }

    /**
     * Starts a fresh passation. To avoid stacking empty "phantom" rows when the user
     * clicks « + Nouvelle passation » without ever filling the previous attempt, an
     * existing empty in-progress row for this couple is recycled if present.
     */
    public function createNewResponse(User $user, Questionnaire $questionnaire): QuestionnaireResponse
    {
        $empty = $this->findEmptyResponse($user, $questionnaire);
        if ($empty) {
            return $empty;
        }

        $response = new QuestionnaireResponse();
        $response->setPatient($user);
        $response->setQuestionnaire($questionnaire);
        $this->em->persist($response);
        $this->em->flush();

        return $response;
    }

    /**
     * A response is considered "real" (resumable) as soon as it contains at least one
     * item answer. Keys prefixed with "_" are reserved for meta data and scores:
     *   - "_meta_*"  → header / evaluator fields filled before any item (parent_mode,
     *                  teacher_mode, info_fields). Present alone does NOT mean the
     *                  patient/evaluator has started answering items.
     *   - "_score_*" → sub-scale and total scores written by the controller at
     *                  submission time. Never present on a still-in-progress row.
     * Item answers (HAD: "A1"/"D1", Brown: "1".."58", DIVA: "<id>_adult"/"<id>_child",
     * RAADS: item.id) never start with "_", so any non-underscore key with a non-empty
     * value indicates a real answer.
     *
     * Public + static so other services can share the same "vide vs partiel" definition
     * (e.g. NeuropsychologueService when an admin deletes an incomplete passation).
     */
    public static function hasRealAnswers(?array $answers): bool
    {
        if (!$answers) {
            return false;
        }

        foreach ($answers as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            return true;
        }

        return false;
    }

    public function getResponseHistory(User $user, Questionnaire $questionnaire): array
    {
        return $this->responseRepository->findBy(
            ['patient' => $user, 'questionnaire' => $questionnaire],
            ['startedAt' => 'DESC']
        );
    }

    public function countCompletedQuestionnaires(User $user): int
    {
        return $this->responseRepository->countDistinctCompletedQuestionnaires($user);
    }

    public function countAvailableQuestionnaires(User $user): int
    {
        return $this->assignedQuestionnaireRepository->count(['patient' => $user]);
    }
}
