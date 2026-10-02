<?php

declare(strict_types=1);

namespace App\Repository\MongoDB;

use App\Document\Analysis;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @extends DocumentRepository<Analysis>
 */
class AnalysisRepository extends DocumentRepository
{
    public function __construct(DocumentManager $dm)
    {
        parent::__construct($dm, $dm->getUnitOfWork(), $dm->getClassMetadata(Analysis::class));
    }

    public function findByAnalysisId(string $analysisId): ?Analysis
    {
        return $this->createQueryBuilder()
            ->field('analysisId')->equals($analysisId)
            ->getQuery()
            ->getSingleResult();
    }

    public function findByStudentId(string $studentId): array
    {
        return $this->createQueryBuilder()
            ->field('studentId')->equals($studentId)
            ->sort('createdAt', 'desc')
            ->getQuery()
            ->execute()
            ->toArray();
    }

    public function findByStudentIdPaginated(string $studentId, int $page, int $limit): array
    {
        return $this->createQueryBuilder()
            ->field('studentId')->equals($studentId)
            ->sort('createdAt', 'desc')
            ->skip(($page - 1) * $limit)
            ->limit($limit)
            ->getQuery()
            ->execute()
            ->toArray();
    }

    public function countByStudentId(string $studentId): int
    {
        $qb = $this->createQueryBuilder()
            ->field('studentId')->equals($studentId);
        $query = $qb->getQuery();
        $cursor = $query->execute();
        return $cursor->count();
    }

    public function findByAnalysisIdAndStudentId(string $analysisId, string $studentId): ?Analysis
    {
        $qb = $this->createQueryBuilder()
            ->field('analysisId')->equals($analysisId)
            ->field('studentId')->equals($studentId);
        $query = $qb->getQuery();
        $cursor = $query->execute();
        $results = $cursor->toArray();
        
        if (count($results) > 0) {
            return $results[0];
        }
        
        return null;
    }

    public function save(Analysis $analysis): void
    {
        $this->getDocumentManager()->persist($analysis);
        $this->getDocumentManager()->flush();
    }

    public function remove(Analysis $analysis): void
    {
        $this->getDocumentManager()->remove($analysis);
        $this->getDocumentManager()->flush();
    }

    public function findStaleProcessing(int $staleThresholdSeconds): array
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$staleThresholdSeconds} seconds");

        $results = $this->createQueryBuilder()
            ->field('status')->equals('PROCESSING')
            ->field('processing.status')->equals('PROCESSING')
            ->field('processing.startedAt')->lt($threshold)
            ->getQuery()
            ->execute()
            ->toArray();

        return $results;
    }

    public function findByAnalysisIdWithProcessing(string $analysisId): ?Analysis
    {
        return $this->createQueryBuilder()
            ->field('analysisId')->equals($analysisId)
            ->getQuery()
            ->getSingleResult();
    }
}