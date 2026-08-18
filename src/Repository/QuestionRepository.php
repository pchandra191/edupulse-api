<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Question;
use App\Entity\QuestionPaper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    public function findById(Uuid $id, ?Uuid $paperId = null): ?Question
    {
        $qb = $this->createQueryBuilder('q')
            ->where('q.id = :id')
            ->setParameter('id', $id);

        if ($paperId !== null) {
            $qb->andWhere('q.questionPaper = :paper')
               ->setParameter('paper', $paperId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByPaper(Uuid $paperId): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.questionPaper = :paper')
            ->setParameter('paper', $paperId)
            ->orderBy('q.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findMaxOrder(Uuid $paperId): int
    {
        $result = $this->createQueryBuilder('q')
            ->select('MAX(q.displayOrder)')
            ->where('q.questionPaper = :paper')
            ->setParameter('paper', $paperId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function countByPaper(Uuid $paperId): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.questionPaper = :paper')
            ->setParameter('paper', $paperId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
