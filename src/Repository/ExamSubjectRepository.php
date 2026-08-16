<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class ExamSubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamSubject::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?ExamSubject
    {
        $qb = $this->createQueryBuilder('es')
            ->where('es.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->innerJoin('es.examination', 'e')
               ->andWhere('e.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByExamination(Uuid $examinationId): array
    {
        return $this->createQueryBuilder('es')
            ->where('es.examination = :examination')
            ->setParameter('examination', $examinationId)
            ->orderBy('es.examDate', 'ASC')
            ->addOrderBy('es.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginatedByExamination(
        Uuid $examinationId,
        ?Uuid $instituteId,
        ?string $search,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('es')
            ->where('es.examination = :examination')
            ->setParameter('examination', $examinationId);

        if ($instituteId !== null) {
            $qb->innerJoin('es.examination', 'e')
               ->andWhere('e.institute = :institute')
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

    public function existsByExaminationSubjectAndSection(
        Uuid $examinationId,
        Uuid $subjectId,
        ?Uuid $sectionId,
        ?Uuid $excludeId = null
    ): bool {
        $qb = $this->createQueryBuilder('es')
            ->select('COUNT(es.id)')
            ->where('es.examination = :examination')
            ->andWhere('es.subject = :subject')
            ->andWhere('es.section IS NULL')
            ->setParameter('examination', $examinationId)
            ->setParameter('subject', $subjectId);

        if ($sectionId !== null) {
            $qb->andWhere('es.section = :section')
               ->setParameter('section', $sectionId);
        }

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

        $qb->innerJoin('es.subject', 's')
           ->andWhere(
               $qb->expr()->orX(
                   $qb->expr()->like('LOWER(s.name)', ':search'),
                   $qb->expr()->like('LOWER(s.code)', ':search'),
                   $qb->expr()->like('LOWER(es.remarks)', ':search')
               )
           )->setParameter('search', '%' . $search . '%');
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('es.status = :status')
           ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['examDate', 'startTime', 'duration', 'maxMarks', 'status', 'createdAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'examDate';
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
