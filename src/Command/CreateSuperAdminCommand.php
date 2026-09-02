<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create:super-admin',
    description: 'Create a super admin user.',
)]
class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address', 'admin@edupulse.ai')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password', 'Password@123');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create Super Admin User');

        $email = (string) $input->getOption('email');
        $password = (string) $input->getOption('password');

        $userRepository = $this->entityManager->getRepository(User::class);
        $existingUser = $userRepository->findByEmail($email);

        if ($existingUser) {
            $io->warning(sprintf('User with email "%s" already exists.', $email));

            $existingUser->setRoles([UserRole::SuperAdmin->value]);
            $existingUser->setStatus(UserStatus::Active);
            $existingUser->verify();

            $this->entityManager->flush();

            $io->success('Existing user updated to Super Admin role.');

            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles([UserRole::SuperAdmin->value]);
        $user->setStatus(UserStatus::Active);
        $user->verify();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->logger->info('Super admin user created.', ['email' => $email]);

        $io->success(sprintf('Super admin user created successfully with email "%s".', $email));

        return Command::SUCCESS;
    }
}
