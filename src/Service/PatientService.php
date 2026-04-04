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

            $inProgress = $this->responseRepository->findOneBy(
                ['patient' => $user, 'questionnaire' => $questionnaire, 'isComplete' => false],
                ['startedAt' => 'DESC']
            );

            $responses = $this->responseRepository->findBy(
                ['patient' => $user, 'questionnaire' => $questionnaire],
                ['startedAt' => 'DESC']
            );

            $result[] = [
                'questionnaire' => $questionnaire,
                'inProgress'    => $inProgress,
                'responses'     => $responses,
            ];
        }

        return $result;
    }

    /**
     * Returns the latest in-progress response, or creates a fresh one.
     */
    public function getOrCreateResponse(User $user, Questionnaire $questionnaire): QuestionnaireResponse
    {
        $response = $this->responseRepository->findOneBy(
            ['patient' => $user, 'questionnaire' => $questionnaire, 'isComplete' => false],
            ['startedAt' => 'DESC']
        );

        if (!$response) {
            $response = $this->createNewResponse($user, $questionnaire);
        }

        return $response;
    }

    /**
     * Always creates a brand-new blank response (ignores any in-progress ones).
     */
    public function createNewResponse(User $user, Questionnaire $questionnaire): QuestionnaireResponse
    {
        $response = new QuestionnaireResponse();
        $response->setPatient($user);
        $response->setQuestionnaire($questionnaire);
        $this->em->persist($response);
        $this->em->flush();

        return $response;
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
