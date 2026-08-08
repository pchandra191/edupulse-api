<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InstituteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Institute::class);
    }

    public function findByCode(string $code): ?Institute
    {
        return $this->findOneBy(['code' => mb_strtoupper($code)]);
    }

    public function findByEmail(string $email): ?Institute
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function getNextCode(): string
    {
        $sql = 'SELECT MAX(CAST(SUBSTRING(code FROM 4) AS INTEGER)) AS max_num FROM institutes WHERE code LIKE :prefix';
        $result = $this->getEntityManager()->getConnection()->fetchAssociative($sql, ['prefix' => 'INS%']);

        $nextNum = 1;

        if ($result && isset($result['max_num']) && is_numeric($result['max_num'])) {
            $nextNum = (int) $result['max_num'] + 1;
        }

        return 'INS' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }

    public function findActiveOptions(): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id, i.name, i.code, i.email, i.phone, i.website, i.logo, i.addressLine1, i.addressLine2, i.city, i.state, i.country, i.postalCode, i.timezone, i.currency, i.status, i.createdAt, i.updatedAt')
            ->where('i.status = :status')
            ->setParameter('status', 'ACTIVE')
            ->orderBy('i.name', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function findOneActiveById(string $id): ?Institute
    {
        return $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->andWhere('i.status = :status')
            ->setParameter('status', 'ACTIVE')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
