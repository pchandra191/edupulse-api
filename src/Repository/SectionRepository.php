<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Section;
use App\Entity\AcademicClass;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class SectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Section::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?Section
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->innerJoin('s.academicClass', 'c')
               ->andWhere('c.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByClass(Uuid $classId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.academicClass = :classId')
            ->setParameter('classId', $classId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $classId,
        ?string $search,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.academicClass', 'c')
            ->addSelect('c')
            ->leftJoin('c.institute', 'i')
            ->addSelect('i');

        if ($instituteId !== null) {
            $qb->where('i.id = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applyClassFilter($qb, $classId);
        $this->applySearch($qb, $search);
        $this->applyStatusFilter($qb, $status);
        $this->applyDefaultActiveFilter($qb, $status);
        $this->applySorting($qb, $sortBy, $sortOrder);

        $totalItems = $this->getTotalCount($qb);

        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        $items = $qb->getQuery()->getResult();

        $totalPages = $limit > 0 ? (int) ceil($totalItems / $limit) : 0;

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public function codeExistsForClass(string $code, Uuid $classId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.academicClass = :classId')
            ->andWhere('s.code = :code')
            ->setParameter('classId', $classId)
            ->setParameter('code', mb_strtoupper($code));

        if (null !== $excludeId) {
            $qb->andWhere('s.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countByClass(Uuid $classId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.academicClass = :classId')
            ->setParameter('classId', $classId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByAcademicYear(Uuid $academicYearId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->innerJoin('s.academicClass', 'c')
            ->where('c.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function archiveByAcademicYear(Uuid $academicYearId): void
    {
        $classIds = $this->getEntityManager()->createQueryBuilder()
            ->select('c.id')
            ->from(AcademicClass::class, 'c')
            ->where('c.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->getScalarResult();

        if (empty($classIds)) {
            return;
        }

        $this->createQueryBuilder('s')
            ->update()
            ->set('s.status', ':status')
            ->where('s.academicClass IN (:classIds)')
            ->setParameter('status', 'ARCHIVED')
            ->setParameter('classIds', array_column($classIds, 'id'))
            ->getQuery()
            ->execute();
    }

    private function applyClassFilter(QueryBuilder $qb, ?Uuid $classId): void
    {
        if (null === $classId) {
            return;
        }

        $qb->andWhere('s.academicClass = :classId')
           ->setParameter('classId', $classId);
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(s.name)', ':search'),
                $qb->expr()->like('LOWER(s.code)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('s.status = :status')
           ->setParameter('status', $status);
    }

    private function applyDefaultActiveFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null !== $status && '' !== $status) {
            return;
        }

        $qb->andWhere('s.status != :archivedStatus')
           ->setParameter('archivedStatus', 'ARCHIVED');
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['name', 'code', 'capacity', 'createdAt', 'updatedAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('s.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(s.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
