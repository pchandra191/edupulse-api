<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamSubmissionDocument;
use App\Entity\ExamAttempt;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class ExamSubmissionDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamSubmissionDocument::class);
    }

    /**
     * @return array<ExamSubmissionDocument>
     */
    public function findByAttempt(ExamAttempt $attempt): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.examAttempt = :attempt')
            ->setParameter('attempt', $attempt)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndAttempt(Uuid $documentId, Uuid $attemptId): ?ExamSubmissionDocument
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :documentId')
            ->andWhere('d.examAttempt = :attemptId')
            ->setParameter('documentId', $documentId)
            ->setParameter('attemptId', $attemptId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<ExamSubmissionDocument>
     */
    public function findByUploadedBy(User $uploadedBy): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedBy = :uploadedBy')
            ->setParameter('uploadedBy', $uploadedBy)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
