<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamAnswer;
use App\Entity\ExamAttempt;
use App\Entity\Question;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class ExamAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamAnswer::class);
    }

    /**
     * @return array<ExamAnswer>
     */
    public function findByAttempt(ExamAttempt $attempt): array
    {
        return $this->createQueryBuilder('ea')
            ->where('ea.examAttempt = :attempt')
            ->setParameter('attempt', $attempt)
            ->orderBy('ea.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAttemptAndQuestion(Uuid $attemptId, Uuid $questionId): ?ExamAnswer
    {
        return $this->createQueryBuilder('ea')
            ->where('ea.examAttempt = :attemptId')
            ->andWhere('ea.question = :questionId')
            ->setParameter('attemptId', $attemptId)
            ->setParameter('questionId', $questionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForAttemptAndQuestion(Uuid $attemptId, Uuid $questionId): bool
    {
        return null !== $this->createQueryBuilder('ea')
            ->select('1')
            ->where('ea.examAttempt = :attemptId')
            ->andWhere('ea.question = :questionId')
            ->setParameter('attemptId', $attemptId)
            ->setParameter('questionId', $questionId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
