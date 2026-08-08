<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\EnrollmentSection;
use App\Entity\StudentEnrollment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class EnrollmentSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnrollmentSection::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?EnrollmentSection
    {
        $qb = $this->createQueryBuilder('es')
            ->where('es.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->innerJoin('es.studentEnrollment', 'se')
               ->innerJoin('se.academicClass', 'c')
               ->andWhere('c.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByEnrollment(Uuid $enrollmentId): array
    {
        return $this->createQueryBuilder('es')
            ->where('es.studentEnrollment = :enrollment')
            ->setParameter('enrollment', $enrollmentId)
            ->orderBy('es.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByEnrollmentAndSection(Uuid $enrollmentId, Uuid $sectionId): ?EnrollmentSection
    {
        return $this->createQueryBuilder('es')
            ->where('es.studentEnrollment = :enrollment')
            ->andWhere('es.section = :section')
            ->setParameter('enrollment', $enrollmentId)
            ->setParameter('section', $sectionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteByEnrollmentAndSection(Uuid $enrollmentId, Uuid $sectionId): void
    {
        $qb = $this->createQueryBuilder('es')
            ->delete()
            ->where('es.studentEnrollment = :enrollment')
            ->andWhere('es.section = :section')
            ->setParameter('enrollment', $enrollmentId)
            ->setParameter('section', $sectionId);

        $qb->getQuery()->execute();
    }
}
