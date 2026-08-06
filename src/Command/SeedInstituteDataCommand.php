<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstituteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed:institute',
    description: 'Seed default institute and assign institute admin user.',
)]
class SeedInstituteDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Institute Data');

        $institute = $this->seedInstitute();
        $this->seedInstituteUser($institute);

        $this->entityManager->flush();

        $this->logger->info('Institute data seeding completed successfully.');

        $io->success('Default institute and admin user seeded successfully.');

        return Command::SUCCESS;
    }

    private function seedInstitute(): Institute
    {
        $instituteRepository = $this->entityManager->getRepository(Institute::class);
        $institute = $instituteRepository->findByCode('EPS001');

        if (!$institute) {
            $institute = new Institute();
            $institute->setName('EduPulse Public School');
            $institute->setCode('EPS001');
            $institute->setEmail('institute@edupulse.ai');
            $institute->setStatus(InstituteStatus::Active);
            $this->entityManager->persist($institute);

            $this->logger->info('Institute created.', ['code' => 'EPS001']);
        } else {
            $institute->setName('EduPulse Public School');
            $institute->setEmail('institute@edupulse.ai');
            $institute->setStatus(InstituteStatus::Active);

            $this->logger->info('Institute already exists.', ['code' => 'EPS001']);
        }

        return $institute;
    }

    private function seedInstituteUser(Institute $institute): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findByEmail('institute@edupulse.ai');

        if (!$user) {
            $user = new User();
            $user->setEmail('institute@edupulse.ai');
            $user->setFirstName('Institute');
            $user->setLastName('Admin');
            $user->setRole(UserRole::Institute);
            $user->setStatus(UserStatus::Active);
            $user->verify();
            $user->setPassword($this->passwordHasher->hashPassword($user, 'Temp@123'));
            $user->setInstitute($institute);
            $this->entityManager->persist($user);

            $this->logger->warning('Institute admin user created with default password. Please change it immediately.', ['email' => 'institute@edupulse.ai']);
        } else {
            $user->setRole(UserRole::Institute);
            $user->setStatus(UserStatus::Active);
            $user->verify();
            $user->setInstitute($institute);

            $this->logger->info('Institute admin user updated.', ['email' => 'institute@edupulse.ai']);
        }
    }
}
