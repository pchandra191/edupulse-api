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
}
