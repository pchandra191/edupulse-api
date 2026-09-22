<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institute;
use App\Entity\InstituteStudentRegistrationLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InstituteStudentRegistrationLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstituteStudentRegistrationLink::class);
    }

    public function findOneActiveByToken(string $token): ?InstituteStudentRegistrationLink
    {
        return $this->createQueryBuilder('link')
            ->where('link.token = :token')
            ->andWhere('link.isActive = true')
            ->andWhere('link.deletedAt IS NULL')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByInstitute(Institute $institute): ?InstituteStudentRegistrationLink
    {
        return $this->createQueryBuilder('link')
            ->where('link.institute = :institute')
            ->andWhere('link.isActive = true')
            ->andWhere('link.deletedAt IS NULL')
            ->setParameter('institute', $institute)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
