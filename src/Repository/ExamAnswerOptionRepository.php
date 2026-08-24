<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamAnswer;
use App\Entity\ExamAnswerOption;
use App\Entity\QuestionOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class ExamAnswerOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamAnswerOption::class);
    }

    /**
     * @return array<ExamAnswerOption>
     */
    public function findByExamAnswer(ExamAnswer $examAnswer): array
    {
        return $this->createQueryBuilder('eao')
            ->where('eao.examAnswer = :examAnswer')
            ->setParameter('examAnswer', $examAnswer)
            ->orderBy('eao.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteByExamAnswer(ExamAnswer $examAnswer): int
    {
        return $this->createQueryBuilder('eao')
            ->delete()
            ->where('eao.examAnswer = :examAnswer')
            ->setParameter('examAnswer', $examAnswer)
            ->getQuery()
            ->execute();
    }
}
