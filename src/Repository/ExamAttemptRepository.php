<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\User;
use App\Enum\ExamAttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class ExamAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamAttempt::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?ExamAttempt
    {
        $qb = $this->createQueryBuilder('ea')
            ->where('ea.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->innerJoin('ea.examSession', 'es')
               ->andWhere('es.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByExamSessionAndStudent(Uuid $examSessionId, Uuid $studentId): ?ExamAttempt
    {
        return $this->createQueryBuilder('ea')
            ->where('ea.examSession = :examSession')
            ->andWhere('ea.student = :student')
            ->setParameter('examSession', $examSessionId)
            ->setParameter('student', $studentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByExamSessionAndStudent(Uuid $examSessionId, Uuid $studentId): ?ExamAttempt
    {
        return $this->createQueryBuilder('ea')
            ->where('ea.examSession = :examSession')
            ->andWhere('ea.student = :student')
            ->andWhere('ea.status IN (:statuses)')
            ->setParameter('examSession', $examSessionId)
            ->setParameter('student', $studentId)
            ->setParameter('statuses', ['NOT_STARTED', 'IN_PROGRESS'])
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStudent(Uuid $studentId, ?Uuid $instituteId = null): array
    {
        $qb = $this->createQueryBuilder('ea')
            ->innerJoin('ea.examSession', 'es')
            ->addSelect('es')
            ->innerJoin('ea.student', 'u')
            ->addSelect('u')
            ->where('ea.student = :student')
            ->setParameter('student', $studentId)
            ->orderBy('ea.createdAt', 'DESC');

        if ($instituteId !== null) {
            $qb->andWhere('es.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getResult();
    }

    public function findQuestionsByAttempt(Uuid $attemptId): array
    {
        $qb = $this->createQueryBuilder('ea')
            ->innerJoin('ea.examSession', 'es')
            ->innerJoin('es.questionPaper', 'qp')
            ->where('ea.id = :attemptId')
            ->setParameter('attemptId', $attemptId)
            ->select('qp.id');

        $paperId = $qb->getQuery()->getSingleScalarResult();

        if (!$paperId) {
            return [];
        }

        $questionRepository = $this->getEntityManager()->getRepository(\App\Entity\Question::class);

        return $questionRepository->createQueryBuilder('q')
            ->leftJoin('q.options', 'qo')
            ->addSelect('qo')
            ->where('q.questionPaper = :paperId')
            ->setParameter('paperId', $paperId)
            ->orderBy('q.displayOrder', 'ASC')
            ->addOrderBy('qo.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findEvaluatedAttempts(
        ?Uuid $instituteId,
        ?string $search,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('ea')
            ->innerJoin('ea.examSession', 'es')
            ->addSelect('es')
            ->innerJoin('es.institute', 'i')
            ->addSelect('i')
            ->innerJoin('ea.student', 'u')
            ->addSelect('u')
            ->where('ea.status = :status')
            ->setParameter('status', ExamAttemptStatus::Evaluated->value);

        if ($instituteId !== null) {
            $qb->andWhere('es.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applySearch($qb, $search);
        $this->applyEvaluatedSorting($qb, $sortBy, $sortOrder);

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

    public function findByExamSession(Uuid $examSessionId, ?string $status = null, int $page = 1, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('ea')
            ->innerJoin('ea.examSession', 'es')
            ->addSelect('es')
            ->innerJoin('es.institute', 'i')
            ->addSelect('i')
            ->innerJoin('ea.student', 'u')
            ->addSelect('u')
            ->where('ea.examSession = :examSession')
            ->setParameter('examSession', $examSessionId);

        if ($status !== null && $status !== '') {
            $qb->andWhere('ea.status = :status')
               ->setParameter('status', $status);
        }

        $qb->orderBy('ea.createdAt', 'DESC');

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

    public function findPaginatedByStudent(
        Uuid $studentId,
        ?Uuid $instituteId,
        ?string $search,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('ea')
            ->innerJoin('ea.examSession', 'es')
            ->addSelect('es')
            ->innerJoin('es.institute', 'i')
            ->addSelect('i')
            ->innerJoin('es.questionPaper', 'qp')
            ->addSelect('qp')
            ->innerJoin('ea.student', 'u')
            ->addSelect('u')
            ->where('ea.student = :student')
            ->setParameter('student', $studentId);

        if ($instituteId !== null) {
            $qb->andWhere('es.institute = :institute')
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

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('ea.status = :status')
           ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['startedAt', 'submittedAt', 'status', 'createdAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('ea.' . $sortBy, $sortOrder);
    }

    private function applyEvaluatedSorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['startedAt', 'submittedAt', 'score', 'percentage', 'timeTakenSeconds'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'submittedAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('ea.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(ea.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
