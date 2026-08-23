<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamSession;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class ExamSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamSession::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?ExamSession
    {
        $qb = $this->createQueryBuilder('es')
            ->where('es.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('es.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('es')
            ->where('es.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('es.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?string $search,
        ?Uuid $questionPaperId,
        ?Uuid $examinationId,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('es')
            ->leftJoin('es.institute', 'i')
            ->addSelect('i')
            ->leftJoin('es.questionPaper', 'qp')
            ->addSelect('qp')
            ->leftJoin('es.examination', 'e')
            ->addSelect('e')
            ->leftJoin('es.createdBy', 'u')
            ->addSelect('u');

        if ($instituteId !== null) {
            $qb->where('es.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applySearch($qb, $search);
        $this->applyQuestionPaperFilter($qb, $questionPaperId);
        $this->applyExaminationFilter($qb, $examinationId);
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

    public function existsByInstituteAndTitle(Uuid $instituteId, string $title, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('es')
            ->select('COUNT(es.id)')
            ->where('es.institute = :institute')
            ->andWhere('es.title = :title')
            ->setParameter('institute', $instituteId)
            ->setParameter('title', $title);

        if (null !== $excludeId) {
            $qb->andWhere('es.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(es.title)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyQuestionPaperFilter(QueryBuilder $qb, ?Uuid $questionPaperId): void
    {
        if (null === $questionPaperId) {
            return;
        }

        $qb->andWhere('es.questionPaper = :questionPaper')
           ->setParameter('questionPaper', $questionPaperId);
    }

    private function applyExaminationFilter(QueryBuilder $qb, ?Uuid $examinationId): void
    {
        if (null === $examinationId) {
            return;
        }

        $qb->andWhere('es.examination = :examination')
           ->setParameter('examination', $examinationId);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('es.status = :status')
           ->setParameter('status', $status);
    }

    private function applyDefaultActiveFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null !== $status && '' !== $status) {
            return;
        }

        $qb->andWhere('es.status != :archivedStatus')
           ->setParameter('archivedStatus', 'ARCHIVED');
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['title', 'availableFrom', 'availableTo', 'status', 'createdAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('es.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(es.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
