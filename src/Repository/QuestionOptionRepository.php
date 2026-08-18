<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Question;
use App\Entity\QuestionOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class QuestionOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionOption::class);
    }

    public function findById(Uuid $id, ?Uuid $questionId = null): ?QuestionOption
    {
        $qb = $this->createQueryBuilder('qo')
            ->where('qo.id = :id')
            ->setParameter('id', $id);

        if ($questionId !== null) {
            $qb->andWhere('qo.question = :question')
               ->setParameter('question', $questionId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByQuestion(Uuid $questionId): array
    {
        return $this->createQueryBuilder('qo')
            ->where('qo.question = :question')
            ->setParameter('question', $questionId)
            ->orderBy('qo.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteByQuestion(Uuid $questionId): void
    {
        $this->createQueryBuilder('qo')
            ->delete()
            ->where('qo.question = :question')
            ->setParameter('question', $questionId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function findByQuestionIds(array $questionIds): array
    {
        if (empty($questionIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('qo')
            ->select('qo.id, IDENTITY(qo.question) AS questionId, qo.optionText, qo.displayOrder')
            ->where('qo.question IN (:questionIds)')
            ->setParameter('questionIds', $questionIds)
            ->orderBy('qo.displayOrder', 'ASC');

        $results = $qb->getQuery()->getArrayResult();

        $byQuestion = [];
        foreach ($results as $row) {
            $byQuestion[(string) $row['questionId']][] = $row;
        }

        return $byQuestion;
    }
}
