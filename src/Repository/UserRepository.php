<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** @return User[] */
    public function findAllPatients(): array
    {
        return $this->findPatientsBySearch('');
    }

    /** @return User[] */
    public function findPatientsBySearch(string $search, string $sort = 'date_desc'): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_PATIENT%')
            ->andWhere('u.roles NOT LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_NEUROPSYCHOLOGUE%');

        if ($search !== '') {
            $q = '%' . mb_strtolower($search) . '%';
            $qb->andWhere('LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q OR LOWER(u.email) LIKE :q')
               ->setParameter('q', $q);
        }

        match ($sort) {
            'name_asc'  => $qb->orderBy('u.lastName', 'ASC')->addOrderBy('u.firstName', 'ASC'),
            'name_desc' => $qb->orderBy('u.lastName', 'DESC')->addOrderBy('u.firstName', 'DESC'),
            'date_asc'  => $qb->orderBy('u.createdAt', 'ASC'),
            default     => $qb->orderBy('u.createdAt', 'DESC'),
        };

        return $qb->getQuery()->getResult();
    }
}
