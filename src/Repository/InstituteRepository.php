<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institute;
use App\DTO\Institute\InstituteFilterRequest;
use App\DTO\PaginatedResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class InstituteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Institute::class);
    }

    public function findByCode(string $code): ?Institute
    {
        return $this->findOneBy(['code' => mb_strtoupper($code)]);
    }

    public function findByEmail(string $email): ?Institute
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function findById(string $id): ?Institute
    {
        return $this->find($id);
    }

    public function getNextCode(): string
    {
        $sql = 'SELECT MAX(CAST(SUBSTRING(code FROM 4) AS INTEGER)) AS max_num FROM institutes WHERE code LIKE :prefix';
        $result = $this->getEntityManager()->getConnection()->fetchAssociative($sql, ['prefix' => 'INS%']);

        $nextNum = 1;

        if ($result && isset($result['max_num']) && is_numeric($result['max_num'])) {
            $nextNum = (int) $result['max_num'] + 1;
        }

        return 'INS' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }

    public function findActiveOptions(): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id, i.name, i.code, i.email, i.phone, i.website, i.logo, i.addressLine1, i.addressLine2, i.city, i.state, i.country, i.postalCode, i.timezone, i.currency, i.status, i.onboardingCompletedAt, i.createdAt, i.updatedAt')
            ->where('i.status = :status')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('status', 'ACTIVE')
            ->orderBy('i.name', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findOneActiveById(string $id): ?Institute
    {
        return $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->andWhere('i.status = :status')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('status', 'ACTIVE')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPaginated(InstituteFilterRequest $filter): PaginatedResult
    {
        $qb = $this->createQueryBuilder('i');

        $this->applySearch($qb, $filter->search);
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

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(i.name)', ':search'),
                $qb->expr()->like('LOWER(i.code)', ':search'),
                $qb->expr()->like('LOWER(i.email)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['name', 'code', 'email', 'status', 'createdAt', 'updatedAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('i.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(i.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
