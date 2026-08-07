<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subject;
use App\Entity\AcademicClass;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class SubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subject::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?Subject
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('s.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?string $search,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('s');

        if ($instituteId !== null) {
            $qb->where('s.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applySearch($qb, $search);
        $this->applyStatusFilter($qb, $status);
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

    public function codeExistsForInstitute(string $code, Uuid $instituteId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.institute = :institute')
            ->andWhere('s.code = :code')
            ->setParameter('institute', $instituteId)
            ->setParameter('code', mb_strtoupper($code));

        if (null !== $excludeId) {
            $qb->andWhere('s.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countByInstitute(Uuid $instituteId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->getQuery()
            ->getSingleScalarResult();
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

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['name', 'code', 'createdAt', 'updatedAt'];

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
