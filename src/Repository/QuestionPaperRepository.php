<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Examination;
use App\Entity\Question;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class QuestionPaperRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionPaper::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?QuestionPaper
    {
        $qb = $this->createQueryBuilder('qp')
            ->where('qp.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('qp.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('qp')
            ->where('qp.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('qp.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?string $search,
        ?Uuid $academicYearId,
        ?Uuid $subjectId,
        ?Uuid $examinationId,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('qp')
            ->leftJoin('qp.institute', 'i')
            ->addSelect('i')
            ->leftJoin('qp.academicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('qp.subject', 's')
            ->addSelect('s')
            ->leftJoin('qp.examination', 'e')
            ->addSelect('e');

        if ($instituteId !== null) {
            $qb->where('qp.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applySearch($qb, $search);
        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applySubjectFilter($qb, $subjectId);
        $this->applyExaminationFilter($qb, $examinationId);
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

    public function titleExistsForInstitute(string $title, Uuid $instituteId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('qp')
            ->select('COUNT(qp.id)')
            ->where('qp.institute = :institute')
            ->andWhere('qp.title = :title')
            ->setParameter('institute', $instituteId)
            ->setParameter('title', $title);

        if (null !== $excludeId) {
            $qb->andWhere('qp.id != :excludeId')
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
                $qb->expr()->like('LOWER(qp.title)', ':search'),
                $qb->expr()->like('LOWER(qp.instructions)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('qp.academicYear = :academicYear')
           ->setParameter('academicYear', $academicYearId);
    }

    private function applySubjectFilter(QueryBuilder $qb, ?Uuid $subjectId): void
    {
        if (null === $subjectId) {
            return;
        }

        $qb->andWhere('qp.subject = :subject')
           ->setParameter('subject', $subjectId);
    }

    private function applyExaminationFilter(QueryBuilder $qb, ?Uuid $examinationId): void
    {
        if (null === $examinationId) {
            return;
        }

        $qb->andWhere('qp.examination = :examination')
           ->setParameter('examination', $examinationId);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('qp.status = :status')
           ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['title', 'date', 'duration', 'maximumMarks', 'createdAt', 'updatedAt', 'status'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('qp.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(qp.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, Collection<int, AcademicClass>>
     */
    public function findClassesByPaperIds(array $paperIds): array
    {
        if (empty($paperIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('qp')
            ->select('qp.id AS paperId, c.id, c.name, c.code')
            ->innerJoin('qp.classes', 'c')
            ->where('qp.id IN (:paperIds)')
            ->setParameter('paperIds', $paperIds);

        $results = $qb->getQuery()->getArrayResult();

        $byPaper = [];
        foreach ($results as $row) {
            $paperId = (string) $row['paperId'];
            $byPaper[$paperId][] = $row;
        }

        return $byPaper;
    }

    /**
     * @return array<string, Collection<int, Section>>
     */
    public function findSectionsByPaperIds(array $paperIds): array
    {
        if (empty($paperIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('qp')
            ->select('qp.id AS paperId, s.id, s.name, s.code')
            ->innerJoin('qp.sections', 's')
            ->where('qp.id IN (:paperIds)')
            ->setParameter('paperIds', $paperIds);

        $results = $qb->getQuery()->getArrayResult();

        $byPaper = [];
        foreach ($results as $row) {
            $paperId = (string) $row['paperId'];
            $byPaper[$paperId][] = $row;
        }

        return $byPaper;
    }

    /**
     * @return array<string, int>
     */
    public function findQuestionCountsByPaperIds(array $paperIds): array
    {
        if (empty($paperIds)) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(q.questionPaper) AS paperId, COUNT(q.id) AS questionCount')
            ->from(Question::class, 'q')
            ->where('q.questionPaper IN (:paperIds)')
            ->setParameter('paperIds', $paperIds)
            ->groupBy('q.questionPaper');

        $results = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[(string) $row['paperId']] = (int) $row['questionCount'];
        }

        return $counts;
    }
}
