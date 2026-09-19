<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\LeaveType;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class LeaveTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveType::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?LeaveType
    {
        $qb = $this->createQueryBuilder('lt')
            ->where('lt.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('(lt.institute = :institute OR lt.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('lt')
            ->where('(lt.institute = :institute OR lt.institute IS NULL)')
            ->setParameter('institute', $instituteId)
            ->andWhere('lt.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('lt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findEnabledByInstitute(?Uuid $instituteId): array
    {
        $qb = $this->createQueryBuilder('lt')
            ->andWhere('lt.enabled = :enabled')
            ->setParameter('enabled', true);

        if ($instituteId !== null) {
            $qb->andWhere('(lt.institute = :institute OR lt.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        $qb->orderBy('lt.name', 'ASC');

        return $qb->getQuery()->getResult();
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
        $qb = $this->createQueryBuilder('lt');

        if ($instituteId !== null) {
            $qb->leftJoin('lt.institute', 'i')
                ->addSelect('i')
                ->where('(lt.institute = :institute OR lt.institute IS NULL)')
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

    public function codeExists(string $code, ?Uuid $instituteId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('lt')
            ->select('COUNT(lt.id)')
            ->where('lt.code = :code')
            ->setParameter('code', mb_strtoupper($code));

        if ($instituteId !== null) {
            $qb->andWhere('(lt.institute = :institute OR lt.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        if (null !== $excludeId) {
            $qb->andWhere('lt.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countByInstitute(Uuid $instituteId): int
    {
        return (int) $this->createQueryBuilder('lt')
            ->select('COUNT(lt.id)')
            ->where('(lt.institute = :institute OR lt.institute IS NULL)')
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
                $qb->expr()->like('LOWER(lt.name)', ':search'),
                $qb->expr()->like('LOWER(lt.code)', ':search'),
                $qb->expr()->like('LOWER(lt.description)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $isEnabled = in_array($status, ['true', '1', 'ENABLED'], true);
        $qb->andWhere('lt.enabled = :status')
            ->setParameter('status', $isEnabled);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'name', 'code', 'enabled'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('lt.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(lt.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
