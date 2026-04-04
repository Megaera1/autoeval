<?php

namespace App\Repository;

use App\Entity\QuestionnaireResponse;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionnaireResponse>
 */
class QuestionnaireResponseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionnaireResponse::class);
    }

    /**
     * Count distinct questionnaires for which this patient has at least one completed response.
     */
    public function countDistinctCompletedQuestionnaires(User $patient): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT IDENTITY(r.questionnaire))')
            ->where('r.patient = :patient')
            ->andWhere('r.isComplete = true')
            ->setParameter('patient', $patient)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
