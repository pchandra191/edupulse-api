<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamAttempt;
use App\Entity\ExamAttemptQuestionProgress;
use App\Entity\Question;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class ExamAttemptQuestionProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamAttemptQuestionProgress::class);
    }

    public function findByAttempt(Uuid $attemptId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.examAttempt = :attemptId')
            ->setParameter('attemptId', $attemptId)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAttemptAndQuestion(Uuid $attemptId, Uuid $questionId): ?ExamAttemptQuestionProgress
    {
        return $this->createQueryBuilder('p')
            ->where('p.examAttempt = :attemptId')
            ->andWhere('p.question = :questionId')
            ->setParameter('attemptId', $attemptId)
            ->setParameter('questionId', $questionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveQuestion(Uuid $attemptId): ?ExamAttemptQuestionProgress
    {
        return $this->createQueryBuilder('p')
            ->where('p.examAttempt = :attemptId')
            ->andWhere('p.lastViewedAt IS NOT NULL')
            ->setParameter('attemptId', $attemptId)
            ->orderBy('p.lastViewedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForAttemptAndQuestion(Uuid $attemptId, Uuid $questionId): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('1')
            ->where('p.examAttempt = :attemptId')
            ->andWhere('p.question = :questionId')
            ->setParameter('attemptId', $attemptId)
            ->setParameter('questionId', $questionId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
