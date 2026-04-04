<?php

namespace App\Repository;

use App\Entity\AssignedQuestionnaire;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssignedQuestionnaire>
 */
class AssignedQuestionnaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssignedQuestionnaire::class);
    }

    /**
     * Returns the IDs of questionnaires assigned to a patient.
     *
     * @return int[]
     */
    public function findAssignedIds(User $patient): array
    {
        return array_map(
            'intval',
            $this->createQueryBuilder('aq')
                ->select('IDENTITY(aq.questionnaire)')
                ->where('aq.patient = :patient')
                ->setParameter('patient', $patient)
                ->getQuery()
                ->getSingleColumnResult()
        );
    }
}
