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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:encrypt-sensitive-data',
    description: 'Encrypt existing plaintext government identifiers and bank account numbers.',
)]
final class EncryptSensitiveDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserGovernmentIdentifierRepository $governmentIdentifierRepository,
        private UserBankAccountRepository $bankAccountRepository,
        private EncryptionService $encryptionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Encrypt Existing Sensitive Data');

        $identifierCount = 0;
        $bankAccountCount = 0;

        $identifiers = $this->governmentIdentifierRepository->findAll();
        foreach ($identifiers as $identifier) {
            $plaintext = $identifier->getIdentifier();

            if (!$this->encryptionService->isEncrypted($plaintext)) {
                $identifier->setIdentifier($this->encryptionService->encrypt($plaintext));
                $identifierCount++;
            }
        }

        $bankAccounts = $this->bankAccountRepository->findAll();
        foreach ($bankAccounts as $bankAccount) {
            $plaintext = $bankAccount->getAccountNumber();

            if (!$this->encryptionService->isEncrypted($plaintext)) {
                $bankAccount->setAccountNumber($this->encryptionService->encrypt($plaintext));
                $bankAccountCount++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Encrypted %d government identifier(s) and %d bank account(s).', $identifierCount, $bankAccountCount));

        return Command::SUCCESS;
    }
}
