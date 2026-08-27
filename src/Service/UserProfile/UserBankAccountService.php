<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\DTO\UserProfile\CreateUserBankAccountRequestDTO;
use App\DTO\UserProfile\UpdateUserBankAccountRequestDTO;
use App\DTO\UserProfile\UserBankAccountResponseDTO;
use App\Entity\User;
use App\Entity\UserBankAccount;
use App\Enum\VerificationStatus;
use App\Repository\UserBankAccountRepository;
use App\Service\EncryptionService;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class UserBankAccountService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserBankAccountRepository $userBankAccountRepository,
        private EncryptionService $encryptionService,
        private LoggerInterface $logger,
    ) {
    }

    public function listBankAccounts(User $user): array
    {
        $accounts = $this->userBankAccountRepository->findByUserId($user->getId());

        return array_map(
            fn(UserBankAccount $account) => $this->mapToResponse($account),
            $accounts
        );
    }

    public function createBankAccount(User $user, CreateUserBankAccountRequestDTO $request): UserBankAccountResponseDTO
    {
        $this->entityManager->beginTransaction();

        try {
            $account = new UserBankAccount();
            $account->setUserId($user->getId());
            $this->applyRequest($account, $request);
            $account->setVerificationStatus(VerificationStatus::Unverified);

            if ($account->isPrimary()) {
                $this->clearPrimaryAccount($user, $account);
                $this->entityManager->flush();
            }

            $this->entityManager->persist($account);
            $this->entityManager->flush();

            $this->entityManager->commit();

            return $this->mapToResponse($account);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            if ($e instanceof UniqueConstraintViolationException) {
                throw new \InvalidArgumentException('A primary bank account already exists for this user.');
            }

            $this->logger->error('Failed to create bank account.', [
                'user_id' => $user->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Unable to create bank account. Please check your data and try again.', 0, $e);
        }
    }

    public function updateBankAccount(User $user, Uuid $accountId, UpdateUserBankAccountRequestDTO $request): UserBankAccountResponseDTO
    {
        $this->entityManager->beginTransaction();

        try {
            $account = $this->findUserBankAccount($user, $accountId);

            if ($request->accountHolderName !== null) {
                $account->setAccountHolderName($request->accountHolderName);
            }

            if ($request->accountNumber !== null) {
                $account->setAccountNumber($this->encryptionService->encrypt($request->accountNumber));
            }

            if ($request->bankName !== null) {
                $account->setBankName($request->bankName);
            }

            if ($request->branchName !== null) {
                $account->setBranchName($request->branchName);
            }

            if ($request->ifsc !== null) {
                $account->setIfsc($request->ifsc);
            }

            if ($request->accountType !== null) {
                $account->setAccountType(\App\Enum\BankAccountType::from($request->accountType));
            }

            if ($request->isPrimary !== null && $request->isPrimary) {
                $currentPrimary = $this->userBankAccountRepository->findPrimaryByUserId($user->getId());

                if ($currentPrimary !== null && !$currentPrimary->getId()->equals($accountId)) {
                    $currentPrimary->setIsPrimary(false);
                    $this->entityManager->flush();
                }

                $account->setIsPrimary(true);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($account);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            if ($e instanceof UniqueConstraintViolationException) {
                throw new \InvalidArgumentException('A primary bank account already exists for this user.');
            }

            $this->logger->error('Failed to update bank account.', [
                'user_id' => $user->getId()->toRfc4122(),
                'account_id' => $accountId->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Unable to update bank account. Please check your data and try again.', 0, $e);
        }
    }

    public function verifyBankAccount(User $user, Uuid $accountId): UserBankAccountResponseDTO
    {
        $account = $this->findUserBankAccount($user, $accountId);
        $account->setVerificationStatus(VerificationStatus::Verified);
        $this->entityManager->flush();

        return $this->mapToResponse($account);
    }

    public function rejectBankAccount(User $user, Uuid $accountId): UserBankAccountResponseDTO
    {
        $account = $this->findUserBankAccount($user, $accountId);
        $account->setVerificationStatus(VerificationStatus::Rejected);
        $this->entityManager->flush();

        return $this->mapToResponse($account);
    }

    public function deleteBankAccount(User $user, Uuid $accountId): void
    {
        $account = $this->findUserBankAccount($user, $accountId);

        $this->entityManager->remove($account);
        $this->entityManager->flush();
    }

    private function findUserBankAccount(User $user, Uuid $accountId): UserBankAccount
    {
        $account = $this->userBankAccountRepository->find($accountId);

        if (!$account instanceof UserBankAccount) {
            throw new \App\Exception\AuthenticationException('Bank account not found.');
        }

        if (!$account->getUserId()->equals($user->getId())) {
            throw new \App\Exception\AuthenticationException('Bank account not found.');
        }

        return $account;
    }

    private function clearPrimaryAccount(User $user, ?UserBankAccount $exclude = null): void
    {
        $currentPrimary = $this->userBankAccountRepository->findPrimaryByUserId($user->getId());

        if ($currentPrimary !== null && $exclude !== null && !$currentPrimary->getId()->equals($exclude->getId())) {
            $currentPrimary->setIsPrimary(false);
        } elseif ($currentPrimary !== null && $exclude === null) {
            $currentPrimary->setIsPrimary(false);
        }
    }

    private function applyRequest(UserBankAccount $account, CreateUserBankAccountRequestDTO $request): void
    {
        $account->setAccountHolderName($request->accountHolderName);
        $account->setAccountNumber($this->encryptionService->encrypt($request->accountNumber));
        $account->setBankName($request->bankName);
        $account->setBranchName($request->branchName);
        $account->setIfsc($request->ifsc);
        $account->setAccountType(\App\Enum\BankAccountType::from($request->accountType));
        $account->setIsPrimary($request->isPrimary);
    }

    private function mapToResponse(UserBankAccount $account): UserBankAccountResponseDTO
    {
        $decryptedAccountNumber = $this->encryptionService->decrypt($account->getAccountNumber());

        return new UserBankAccountResponseDTO(
            id: $account->getId()->toRfc4122(),
            accountHolderName: $account->getAccountHolderName(),
            maskedAccountNumber: $this->maskAccountNumber($decryptedAccountNumber),
            bankName: $account->getBankName(),
            branchName: $account->getBranchName(),
            ifsc: $account->getIfsc(),
            accountType: $account->getAccountType()->value,
            isPrimary: $account->isPrimary(),
            verificationStatus: $account->getVerificationStatus()->value,
            verifiedAt: $account->getVerifiedAt()?->format(DateTimeInterface::ATOM),
            createdAt: $account->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $account->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function maskAccountNumber(string $accountNumber): string
    {
        $cleaned = preg_replace('/\s+/', '', $accountNumber);

        if (strlen($cleaned) <= 4) {
            return str_repeat('X', strlen($cleaned));
        }

        return str_repeat('X', strlen($cleaned) - 4) . substr($cleaned, -4);
    }
}
