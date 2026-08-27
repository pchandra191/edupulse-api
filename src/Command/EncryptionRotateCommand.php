<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\UserBankAccount;
use App\Entity\UserGovernmentIdentifier;
use App\Exception\EncryptionException;
use App\Repository\UserBankAccountRepository;
use App\Repository\UserGovernmentIdentifierRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:encryption:rotate',
    description: 'Re-encrypt sensitive data from an old encryption version to the current version.',
)]
final class EncryptionRotateCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserGovernmentIdentifierRepository $governmentIdentifierRepository,
        private UserBankAccountRepository $bankAccountRepository,
        private EncryptionService $encryptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('from-version', InputArgument::REQUIRED, 'The encryption version to rotate from (e.g., v1).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fromVersion = $input->getArgument('from-version');
        $toVersion = $this->encryptionService->getCurrentVersion();

        if ($fromVersion === $toVersion) {
            $io->warning(sprintf('Source version "%s" is the same as current version "%s". Nothing to do.', $fromVersion, $toVersion));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Rotating Encryption Keys: %s -> %s', $fromVersion, $toVersion));

        $identifierCount = 0;
        $bankAccountCount = 0;
        $errors = 0;

        $identifiers = $this->governmentIdentifierRepository->findAll();
        foreach ($identifiers as $identifier) {
            $ciphertext = $identifier->getIdentifier();

            if (!$this->isVersion($ciphertext, $fromVersion)) {
                continue;
            }

            try {
                $identifier->setIdentifier($this->encryptionService->reencrypt($ciphertext, $toVersion));
                $identifierCount++;
            } catch (EncryptionException $e) {
                $io->error(sprintf('Failed to re-encrypt government identifier %s: %s', $identifier->getId()->toRfc4122(), $e->getMessage()));
                $errors++;
            }
        }

        $bankAccounts = $this->bankAccountRepository->findAll();
        foreach ($bankAccounts as $bankAccount) {
            $ciphertext = $bankAccount->getAccountNumber();

            if (!$this->isVersion($ciphertext, $fromVersion)) {
                continue;
            }

            try {
                $bankAccount->setAccountNumber($this->encryptionService->reencrypt($ciphertext, $toVersion));
                $bankAccountCount++;
            } catch (EncryptionException $e) {
                $io->error(sprintf('Failed to re-encrypt bank account %s: %s', $bankAccount->getId()->toRfc4122(), $e->getMessage()));
                $errors++;
            }
        }

        if ($errors > 0) {
            $io->warning(sprintf('Completed with %d error(s). Review the output above.', $errors));
        }

        $this->entityManager->flush();

        $io->success(sprintf('Rotated %d government identifier(s) and %d bank account(s) from %s to %s.', $identifierCount, $bankAccountCount, $fromVersion, $toVersion));

        return Command::SUCCESS;
    }

    private function isVersion(string $ciphertext, string $version): bool
    {
        return str_starts_with($ciphertext, $version . ':');
    }
}
