<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamAttemptSecurityEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class ExamAttemptSecurityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamAttemptSecurityEvent::class);
    }

    public function findByAttempt(Uuid $attemptId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.examAttempt = :attemptId')
            ->setParameter('attemptId', $attemptId)
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
