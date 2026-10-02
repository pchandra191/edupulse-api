<?php

declare(strict_types=1);

namespace App\Repository\MongoDB;

use App\Document\OcrCache;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

/**
 * @extends DocumentRepository<OcrCache>
 */
class OcrCacheRepository extends DocumentRepository
{
    public function findByCacheKey(string $cacheKey): ?OcrCache
    {
        return $this->findOneBy(['cacheKey' => $cacheKey]);
    }

    public function findByFileHash(string $fileHash): array
    {
        return $this->findBy(['fileHash' => $fileHash]);
    }

    public function findCompletedByFileHash(string $fileHash): array
    {
        return $this->findBy([
            'fileHash' => $fileHash,
            'status' => 'COMPLETED',
        ]);
    }

    public function findPendingOrProcessingByFileHash(string $fileHash): array
    {
        return $this->findBy([
            'fileHash' => $fileHash,
            'status' => ['$in' => ['PENDING', 'PROCESSING']],
        ]);
    }

    public function findFailedRetryableByFileHash(string $fileHash): array
    {
        return $this->findBy([
            'fileHash' => $fileHash,
            'status' => 'FAILED',
            'retryCount' => ['$lt' => 1],
        ]);
    }

    public function save(OcrCache $cacheEntry): void
    {
        $dm = $this->getDocumentManager();
        $dm->persist($cacheEntry);
        $dm->flush();
    }
}