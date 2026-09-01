<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamSession;
use App\Entity\Institute;
use App\Entity\StudentEnrollment;
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

    public function findPaginatedForStudent(
        Uuid $studentId,
        Uuid $instituteId,
        ?string $search,
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
            ->addSelect('u')
            ->where('es.institute = :institute')
            ->andWhere('es.status != :archivedStatus')
            ->setParameter('institute', $instituteId)
            ->setParameter('archivedStatus', 'ARCHIVED')
            ->setParameter('studentId', $studentId)
            ->setParameter('enrollmentStatus', 'ACTIVE')
            ->setParameter('sectionStatus', 'ACTIVE');

        $qb->andWhere(
            $qb->expr()->exists(
                'SELECT 1 FROM App\\Entity\\StudentEnrollment se2 ' .
                'WHERE se2.student = :studentId ' .
                'AND se2.status = :enrollmentStatus ' .
                'AND se2.academicClass = es.academicClass ' .
                'AND se2.academicYear = es.academicYear'
            )
        );

        $qb->andWhere(
            $qb->expr()->orX(
                'SIZE(es.sections) = 0',
                'NOT EXISTS (SELECT 1 FROM App\\Entity\\EnrollmentSection ens_n WHERE ens_n.studentEnrollment IN (SELECT se_inner.id FROM App\\Entity\\StudentEnrollment se_inner WHERE se_inner.student = :studentId AND se_inner.status = :enrollmentStatus AND se_inner.academicClass = es.academicClass AND se_inner.academicYear = es.academicYear))',
                $qb->expr()->exists(
                    'SELECT 1 FROM App\\Entity\\EnrollmentSection ens2 ' .
                    'INNER JOIN ens2.studentEnrollment se3 ' .
                    'WITH se3.student = :studentId AND se3.status = :enrollmentStatus ' .
                    'WHERE ens2.section MEMBER OF es.sections ' .
                    'AND ens2.status = :sectionStatus'
                )
            )
        );

        if (null !== $search && '' !== $search) {
            $search = trim(mb_strtolower($search));
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(es.title)', ':search')
                )
            )->setParameter('search', '%' . $search . '%');
        }

        if (null !== $status && '' !== $status) {
            $qb->andWhere('es.status = :status')
               ->setParameter('status', $status);
        }

        $allowedSortFields = ['title', 'availableFrom', 'availableTo', 'status', 'createdAt'];
        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }
        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        $qb->addOrderBy('es.' . $sortBy, $sortOrder);

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

    public function findByIdForStudent(Uuid $id, Uuid $studentId, Uuid $instituteId): ?ExamSession
    {
        $qb = $this->createQueryBuilder('es')
            ->where('es.id = :id')
            ->andWhere('es.institute = :institute')
            ->andWhere('es.status != :archivedStatus')
            ->setParameter('id', $id)
            ->setParameter('institute', $instituteId)
            ->setParameter('archivedStatus', 'ARCHIVED')
            ->setParameter('studentId', $studentId)
            ->setParameter('enrollmentStatus', 'ACTIVE')
            ->setParameter('sectionStatus', 'ACTIVE');

        $qb->andWhere(
            $qb->expr()->exists(
                'SELECT 1 FROM App\\Entity\\StudentEnrollment se2 ' .
                'WHERE se2.student = :studentId ' .
                'AND se2.status = :enrollmentStatus ' .
                'AND se2.academicClass = es.academicClass ' .
                'AND se2.academicYear = es.academicYear'
            )
        );

        $qb->andWhere(
            $qb->expr()->orX(
                'SIZE(es.sections) = 0',
                'NOT EXISTS (SELECT 1 FROM App\\Entity\\EnrollmentSection ens_n WHERE ens_n.studentEnrollment IN (SELECT se_inner.id FROM App\\Entity\\StudentEnrollment se_inner WHERE se_inner.student = :studentId AND se_inner.status = :enrollmentStatus AND se_inner.academicClass = es.academicClass AND se_inner.academicYear = es.academicYear))',
                $qb->expr()->exists(
                    'SELECT 1 FROM App\\Entity\\EnrollmentSection ens2 ' .
                    'INNER JOIN ens2.studentEnrollment se3 ' .
                    'WITH se3.student = :studentId AND se3.status = :enrollmentStatus ' .
                    'WHERE ens2.section MEMBER OF es.sections ' .
                    'AND ens2.status = :sectionStatus'
                )
            )
        );

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findPublishedReadyForActivation(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('es')
            ->where('es.status = :publishedStatus')
            ->andWhere('es.availableFrom <= :now')
            ->setParameter('publishedStatus', 'PUBLISHED')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function findActiveReadyForClosure(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('es')
            ->where('es.status = :activeStatus')
            ->andWhere('es.availableTo <= :now')
            ->setParameter('activeStatus', 'ACTIVE')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function findPublishedOverdueForClosure(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('es')
            ->where('es.status = :publishedStatus')
            ->andWhere('es.availableTo <= :now')
            ->setParameter('publishedStatus', 'PUBLISHED')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function findParticipationPaginated(
        Uuid $examSessionId,
        Uuid $instituteId,
        Uuid $academicYearId,
        Uuid $academicClassId,
        ?string $search,
        ?string $status,
        ?Uuid $sectionId,
        int $page,
        int $limit
    ): array {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('se')
            ->from(StudentEnrollment::class, 'se')
            ->innerJoin('se.student', 'u')
            ->innerJoin('se.academicClass', 'c')
            ->innerJoin('c.institute', 'i')
            ->leftJoin('se.enrollmentSections', 'ens')
            ->leftJoin('ens.section', 's')
            ->where('se.academicYear = :academicYear')
            ->andWhere('se.academicClass = :academicClass')
            ->andWhere('se.status = :enrollmentStatus')
            ->andWhere('u.status = :userStatus')
            ->andWhere('i.id = :instituteId')
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('academicClass', $academicClassId)
            ->setParameter('enrollmentStatus', 'ACTIVE')
            ->setParameter('userStatus', 'ACTIVE')
            ->setParameter('instituteId', $instituteId);

        $qb->andWhere(
            $qb->expr()->orX(
                '(SELECT COUNT(sec.id) FROM App\\Entity\\ExamSession esx JOIN esx.sections sec WHERE esx.id = :examSession) = 0',
                'NOT EXISTS (SELECT ens2.id FROM App\\Entity\\EnrollmentSection ens2 WHERE ens2.studentEnrollment = se)',
                $qb->expr()->exists(
                    'SELECT ens3.id FROM App\\Entity\\EnrollmentSection ens3 ' .
                    'WHERE ens3.studentEnrollment = se ' .
                    'AND ens3.status = :sectionStatus ' .
                    'AND ens3.section IN (SELECT sec2 FROM App\\Entity\\ExamSession esy JOIN esy.sections sec2 WHERE esy.id = :examSession)'
                )
            )
        );

        $qb->setParameter('sectionStatus', 'ACTIVE');
        $qb->setParameter('examSession', $examSessionId);

        $this->applyParticipationSearch($qb, $search);
        $this->applyParticipationStatusFilter($qb, $status);
        $this->applyParticipationSectionFilter($qb, $sectionId);

        $qb->select(
            'se.id AS enrollment_id',
            'se.status AS enrollment_status',
            'u.id AS student_id',
            'u.firstName AS student_first_name',
            'u.lastName AS student_last_name',
            'u.email AS student_email',
            'c.id AS class_id',
            'c.name AS class_name',
            's.id AS section_id',
            's.name AS section_name'
        );

        $totalItems = $this->getParticipationTotalCount($qb);

        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        $totalPages = $limit > 0 ? (int) ceil($totalItems / $limit) : 0;

        return [
            'items' => $results,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function countParticipationSummary(Uuid $examSessionId, Uuid $instituteId, Uuid $academicYearId, Uuid $academicClassId, ?string $search = null, ?Uuid $sectionId = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('se')
            ->from(StudentEnrollment::class, 'se')
            ->innerJoin('se.student', 'u')
            ->innerJoin('se.academicClass', 'c')
            ->innerJoin('c.institute', 'i')
            ->leftJoin('se.enrollmentSections', 'ens')
            ->leftJoin('App\\Entity\\ExamAttempt', 'ea', 'WITH', 'ea.examSession = :examSession AND ea.student = u.id')
            ->where('se.academicYear = :academicYear')
            ->andWhere('se.academicClass = :academicClass')
            ->andWhere('se.status = :enrollmentStatus')
            ->andWhere('u.status = :userStatus')
            ->andWhere('i.id = :instituteId')
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('academicClass', $academicClassId)
            ->setParameter('enrollmentStatus', 'ACTIVE')
            ->setParameter('userStatus', 'ACTIVE')
            ->setParameter('instituteId', $instituteId)
            ->setParameter('examSession', $examSessionId);

        $qb->andWhere(
            $qb->expr()->orX(
                '(SELECT COUNT(sec.id) FROM App\\Entity\\ExamSession esx JOIN esx.sections sec WHERE esx.id = :examSession) = 0',
                'NOT EXISTS (SELECT ens2.id FROM App\\Entity\\EnrollmentSection ens2 WHERE ens2.studentEnrollment = se)',
                $qb->expr()->exists(
                    'SELECT ens3.id FROM App\\Entity\\EnrollmentSection ens3 ' .
                    'WHERE ens3.studentEnrollment = se ' .
                    'AND ens3.status = :sectionStatus ' .
                    'AND ens3.section IN (SELECT sec2 FROM App\\Entity\\ExamSession esy JOIN esy.sections sec2 WHERE esy.id = :examSession)'
                )
            )
        );

        $qb->setParameter('sectionStatus', 'ACTIVE');

        if ($sectionId !== null) {
            $qb->andWhere('ens.section = :summarySectionId')
                ->setParameter('summarySectionId', $sectionId);
        }

        $this->applyParticipationSearch($qb, $search);

        $qb->select(
            $qb->expr()->count('DISTINCT se.id') . ' AS eligible',
            'SUM(CASE WHEN ea.id IS NULL THEN 1 ELSE 0 END) AS notAttempted',
            'SUM(CASE WHEN ea.status = :inProgressStatus THEN 1 ELSE 0 END) AS inProgress',
            'SUM(CASE WHEN ea.status = :submittedStatus THEN 1 ELSE 0 END) AS submitted',
            'SUM(CASE WHEN ea.status = :autoSubmittedStatus THEN 1 ELSE 0 END) AS autoSubmitted',
            'SUM(CASE WHEN ea.id IS NOT NULL AND ea.status NOT IN (:inProgressStatus, :submittedStatus, :autoSubmittedStatus) THEN 1 ELSE 0 END) AS attempted'
        );

        $qb->setParameter('inProgressStatus', 'IN_PROGRESS');
        $qb->setParameter('submittedStatus', 'SUBMITTED');
        $qb->setParameter('autoSubmittedStatus', 'AUTO_SUBMITTED');

        $result = $qb->getQuery()->getSingleResult();

        return [
            'eligible' => (int) ($result['eligible'] ?? 0),
            'attempted' => (int) ($result['attempted'] ?? 0),
            'inProgress' => (int) ($result['inProgress'] ?? 0),
            'submitted' => (int) ($result['submitted'] ?? 0),
            'autoSubmitted' => (int) ($result['autoSubmitted'] ?? 0),
            'notAttempted' => (int) ($result['notAttempted'] ?? 0),
        ];
    }

    private function applyParticipationSearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(u.firstName)', ':search'),
                $qb->expr()->like('LOWER(u.lastName)', ':search'),
                $qb->expr()->like('LOWER(u.email)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyParticipationStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        switch ($status) {
            case 'NOT_ATTEMPTED':
                $qb->andWhere(
                    'NOT EXISTS (SELECT ea1.id FROM App\\Entity\\ExamAttempt ea1 WHERE ea1.examSession = :filterExamSession AND ea1.student = se.student)'
                );
                $qb->setParameter('filterExamSession', $qb->getParameter('examSession')->getValue());
                break;

            case 'IN_PROGRESS':
                $qb->andWhere(
                    $qb->expr()->exists(
                        'SELECT ea1.id FROM App\\Entity\\ExamAttempt ea1 ' .
                        'WHERE ea1.examSession = :filterExamSession ' .
                        'AND ea1.student = se.student ' .
                        'AND ea1.status = :statusInProgress ' .
                        'AND ea1.createdAt = (SELECT MAX(ea2.createdAt) FROM App\\Entity\\ExamAttempt ea2 WHERE ea2.examSession = :filterExamSession AND ea2.student = se.student)'
                    )
                );
                $qb->setParameter('filterExamSession', $qb->getParameter('examSession')->getValue());
                $qb->setParameter('statusInProgress', 'IN_PROGRESS');
                break;

            case 'SUBMITTED':
                $qb->andWhere(
                    $qb->expr()->exists(
                        'SELECT ea1.id FROM App\\Entity\\ExamAttempt ea1 ' .
                        'WHERE ea1.examSession = :filterExamSession ' .
                        'AND ea1.student = se.student ' .
                        'AND ea1.status = :statusSubmitted ' .
                        'AND ea1.createdAt = (SELECT MAX(ea2.createdAt) FROM App\\Entity\\ExamAttempt ea2 WHERE ea2.examSession = :filterExamSession AND ea2.student = se.student)'
                    )
                );
                $qb->setParameter('filterExamSession', $qb->getParameter('examSession')->getValue());
                $qb->setParameter('statusSubmitted', 'SUBMITTED');
                break;

            case 'AUTO_SUBMITTED':
                $qb->andWhere(
                    $qb->expr()->exists(
                        'SELECT ea1.id FROM App\\Entity\\ExamAttempt ea1 ' .
                        'WHERE ea1.examSession = :filterExamSession ' .
                        'AND ea1.student = se.student ' .
                        'AND ea1.status = :statusAutoSubmitted ' .
                        'AND ea1.createdAt = (SELECT MAX(ea2.createdAt) FROM App\\Entity\\ExamAttempt ea2 WHERE ea2.examSession = :filterExamSession AND ea2.student = se.student)'
                    )
                );
                $qb->setParameter('filterExamSession', $qb->getParameter('examSession')->getValue());
                $qb->setParameter('statusAutoSubmitted', 'AUTO_SUBMITTED');
                break;
        }
    }

    private function applyParticipationSectionFilter(QueryBuilder $qb, ?Uuid $sectionId): void
    {
        if (null === $sectionId) {
            return;
        }

        $qb->andWhere('ens.section = :sectionId')
            ->setParameter('sectionId', $sectionId);
    }

    private function getParticipationTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT se.id)');
        $countQb->resetDQLPart('orderBy');
        $countQb->resetDQLPart('groupBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
