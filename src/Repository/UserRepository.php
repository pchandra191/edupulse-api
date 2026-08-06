<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\DTO\User\UserFilterRequest;
use App\DTO\PaginatedResult;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findActiveByIdentifier(string $identifier): ?User
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.email = :identifier')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('identifier', mb_strtolower(trim($identifier)));

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByFilters(
        ?string $search = null,
        ?string $role = null,
        ?string $status = null,
        ?string $department = null,
        int $page = 1,
        int $limit = 10,
        string $sortBy = 'createdAt',
        string $sortOrder = 'DESC'
    ): array {
        $filter = new UserFilterRequest(
            search: $search,
            role: $role,
            status: $status,
            department: $department,
            page: $page,
            limit: $limit,
            sortBy: $sortBy,
            sortOrder: $sortOrder,
        );

        $result = $this->findPaginated($filter);

        return [
            'items' => $result->items,
            'pagination' => $result->pagination,
        ];
    }

    public function findPaginated(
        UserFilterRequest $filter,
        ?string $instituteId = null,
        bool $excludeSuperAdmin = false
    ): PaginatedResult {
        $qb = $this->createQueryBuilder('u');

        $this->applySearch($qb, $filter->search);
        $this->applyRoleFilter($qb, $filter->role);
        $this->applyStatusFilter($qb, $filter->status);
        $this->applyDepartmentFilter($qb, $filter->department);
        $this->applyTenantFilter($qb, $instituteId, $excludeSuperAdmin);
        $this->applySorting($qb, $filter->sortBy, $filter->sortOrder);

        $totalItems = $this->getTotalCount($qb);

        $offset = ($filter->page - 1) * $filter->limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($filter->limit);

        $items = $qb->getQuery()->getResult();

        $totalPages = $filter->limit > 0 ? (int) ceil($totalItems / $filter->limit) : 0;

        return new PaginatedResult(
            items: $items,
            pagination: [
                'page' => $filter->page,
                'limit' => $filter->limit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        );
    }

    public function findByUuid(Uuid $id, ?string $instituteId = null, bool $excludeSuperAdmin = false): ?User
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.id = :id')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('id', $id);

        $this->applyTenantFilter($qb, $instituteId, $excludeSuperAdmin);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByEmail(string $email, ?string $instituteId = null, bool $excludeSuperAdmin = false): ?User
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('email', mb_strtolower(trim($email)));

        $this->applyTenantFilter($qb, $instituteId, $excludeSuperAdmin);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function emailExists(string $email, ?Uuid $ignoreUser = null): bool
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.email = :email')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('email', mb_strtolower(trim($email)));

        if (null !== $ignoreUser) {
            $qb->andWhere('u.id != :ignoreUser')
               ->setParameter('ignoreUser', $ignoreUser);
        }

        return (bool) $qb->getQuery()->getSingleScalarResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(u.firstName)', ':search'),
                $qb->expr()->like('LOWER(u.lastName)', ':search'),
                $qb->expr()->like('LOWER(u.email)', ':search'),
                $qb->expr()->like('LOWER(u.phone)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyRoleFilter(QueryBuilder $qb, ?string $role): void
    {
        if (null === $role || '' === $role) {
            return;
        }

        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT id FROM users WHERE CAST(roles AS TEXT) LIKE :role';
        $rows = $conn->fetchAllAssociative($sql, ['role' => '%' . $role . '%']);

        $ids = array_map(static fn(array $row): string => $row['id'], $rows);

        if ([] === $ids) {
            $qb->andWhere('1 = 0');
        } else {
            $qb->andWhere('u.id IN (:role_ids)')
               ->setParameter('role_ids', $ids);
        }
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('u.status = :status')
           ->setParameter('status', $status);
    }

    private function applyDepartmentFilter(QueryBuilder $qb, ?string $department): void
    {
        if (null === $department || '' === $department) {
            return;
        }

        $qb->andWhere('u.department = :department')
           ->setParameter('department', $department);
    }

    private function applyTenantFilter(QueryBuilder $qb, ?string $instituteId, bool $excludeSuperAdmin): void
    {
        if ($instituteId !== null) {
            $qb->andWhere('u.institute = :institute')
               ->setParameter('institute', Uuid::fromString($instituteId));
        }

        if ($excludeSuperAdmin) {
            $conn = $this->getEntityManager()->getConnection();
            $sql = 'SELECT id FROM users WHERE CAST(roles AS TEXT) LIKE :role';
            $rows = $conn->fetchAllAssociative($sql, ['role' => '%' . UserRole::SuperAdmin->value . '%']);

            $superAdminIds = array_map(static fn(array $row): string => $row['id'], $rows);

            if (!empty($superAdminIds)) {
                $qb->andWhere('u.id NOT IN (:super_admin_ids)')
                   ->setParameter('super_admin_ids', $superAdminIds);
            }
        }
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'updatedAt', 'firstName', 'email', 'department'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('u.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(u.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
