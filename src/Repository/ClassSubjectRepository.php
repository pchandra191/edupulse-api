<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicClass;
use App\Entity\ClassSubject;
use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class ClassSubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassSubject::class);
    }

    public function findByClassAndSubject(Uuid $classId, Uuid $subjectId): ?ClassSubject
    {
        return $this->createQueryBuilder('cs')
            ->where('cs.academicClass = :classId')
            ->andWhere('cs.subject = :subjectId')
            ->setParameter('classId', $classId)
            ->setParameter('subjectId', $subjectId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findSubjectsByClass(Uuid $classId): array
    {
        return $this->createQueryBuilder('cs')
            ->innerJoin('cs.subject', 's')
            ->addSelect('s')
            ->where('cs.academicClass = :classId')
            ->setParameter('classId', $classId)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findClassesBySubject(Uuid $subjectId): array
    {
        return $this->createQueryBuilder('cs')
            ->innerJoin('cs.academicClass', 'c')
            ->addSelect('c')
            ->where('cs.subject = :subjectId')
            ->setParameter('subjectId', $subjectId)
            ->getQuery()
            ->getResult();
    }

    public function countBySubject(Uuid $subjectId): int
    {
        return (int) $this->createQueryBuilder('cs')
            ->select('COUNT(cs.id)')
            ->where('cs.subject = :subjectId')
            ->setParameter('subjectId', $subjectId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteByClassAndSubject(Uuid $classId, Uuid $subjectId): void
    {
        $qb = $this->createQueryBuilder('cs')
            ->delete()
            ->where('cs.academicClass = :classId')
            ->andWhere('cs.subject = :subjectId')
            ->setParameter('classId', $classId)
            ->setParameter('subjectId', $subjectId);

        $qb->getQuery()->execute();
    }
}
