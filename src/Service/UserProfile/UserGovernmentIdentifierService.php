<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\DTO\UserProfile\CreateUserGovernmentIdentifierRequestDTO;
use App\DTO\UserProfile\UpdateUserGovernmentIdentifierRequestDTO;
use App\DTO\UserProfile\UserGovernmentIdentifierResponseDTO;
use App\Entity\User;
use App\Entity\UserGovernmentIdentifier;
use App\Enum\VerificationStatus;
use App\Repository\UserGovernmentIdentifierRepository;
use App\Service\EncryptionService;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class UserGovernmentIdentifierService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserGovernmentIdentifierRepository $userGovernmentIdentifierRepository,
        private EncryptionService $encryptionService,
        private LoggerInterface $logger,
    ) {
    }

    public function listIdentifiers(User $user): array
    {
        $identifiers = $this->userGovernmentIdentifierRepository->findByUserId($user->getId());

        return array_map(
            fn(UserGovernmentIdentifier $identifier) => $this->mapToResponse($identifier),
            $identifiers
        );
    }

    public function createIdentifier(User $user, CreateUserGovernmentIdentifierRequestDTO $request): UserGovernmentIdentifierResponseDTO
    {
        $identifier = new UserGovernmentIdentifier();
        $identifier->setUserId($user->getId());
        $this->applyRequest($identifier, $request);
        $identifier->setVerificationStatus(VerificationStatus::Unverified);

        $this->entityManager->persist($identifier);
        $this->entityManager->flush();

        return $this->mapToResponse($identifier);
    }

    public function updateIdentifier(User $user, Uuid $identifierId, UpdateUserGovernmentIdentifierRequestDTO $request): UserGovernmentIdentifierResponseDTO
    {
        $identifier = $this->findUserIdentifier($user, $identifierId);

        if ($request->type !== null) {
            $identifier->setType(\App\Enum\GovernmentIdType::from($request->type));
        }

        if ($request->identifier !== null) {
            $identifier->setIdentifier($this->encryptionService->encrypt($request->identifier));
        }

        if ($request->issuingCountry !== null) {
            $identifier->setIssuingCountry($request->issuingCountry);
        }

        if ($request->issuingAuthority !== null) {
            $identifier->setIssuingAuthority($request->issuingAuthority);
        }

        if ($request->issueDate !== null) {
            try {
                $identifier->setIssueDate(new \DateTimeImmutable($request->issueDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid issue date format.');
            }
        }

        if ($request->expiryDate !== null) {
            try {
                $identifier->setExpiryDate(new \DateTimeImmutable($request->expiryDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid expiry date format.');
            }
        }

        $this->entityManager->flush();

        return $this->mapToResponse($identifier);
    }

    public function verifyIdentifier(User $user, Uuid $identifierId): UserGovernmentIdentifierResponseDTO
    {
        $identifier = $this->findUserIdentifier($user, $identifierId);
        $identifier->setVerificationStatus(VerificationStatus::Verified);
        $this->entityManager->flush();

        return $this->mapToResponse($identifier);
    }

    public function rejectIdentifier(User $user, Uuid $identifierId): UserGovernmentIdentifierResponseDTO
    {
        $identifier = $this->findUserIdentifier($user, $identifierId);
        $identifier->setVerificationStatus(VerificationStatus::Rejected);
        $this->entityManager->flush();

        return $this->mapToResponse($identifier);
    }

    public function deleteIdentifier(User $user, Uuid $identifierId): void
    {
        $identifier = $this->findUserIdentifier($user, $identifierId);

        $this->entityManager->remove($identifier);
        $this->entityManager->flush();
    }

    private function findUserIdentifier(User $user, Uuid $identifierId): UserGovernmentIdentifier
    {
        $identifier = $this->userGovernmentIdentifierRepository->find($identifierId);

        if (!$identifier instanceof UserGovernmentIdentifier) {
            throw new \App\Exception\AuthenticationException('Government identifier not found.');
        }

        if (!$identifier->getUserId()->equals($user->getId())) {
            throw new \App\Exception\AuthenticationException('Government identifier not found.');
        }

        return $identifier;
    }

    private function applyRequest(UserGovernmentIdentifier $identifier, CreateUserGovernmentIdentifierRequestDTO $request): void
    {
        $identifier->setType(\App\Enum\GovernmentIdType::from($request->type));
        $identifier->setIdentifier($this->encryptionService->encrypt($request->identifier));
        $identifier->setIssuingCountry($request->issuingCountry);
        $identifier->setIssuingAuthority($request->issuingAuthority);

        if ($request->issueDate !== null) {
            try {
                $identifier->setIssueDate(new \DateTimeImmutable($request->issueDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid issue date format.');
            }
        }

        if ($request->expiryDate !== null) {
            try {
                $identifier->setExpiryDate(new \DateTimeImmutable($request->expiryDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid expiry date format.');
            }
        }
    }

    private function mapToResponse(UserGovernmentIdentifier $identifier): UserGovernmentIdentifierResponseDTO
    {
        $decryptedIdentifier = $this->encryptionService->decrypt($identifier->getIdentifier());

        return new UserGovernmentIdentifierResponseDTO(
            id: $identifier->getId()->toRfc4122(),
            type: $identifier->getType()->value,
            maskedIdentifier: $this->maskIdentifier($decryptedIdentifier, $identifier->getType()),
            issuingCountry: $identifier->getIssuingCountry(),
            issuingAuthority: $identifier->getIssuingAuthority(),
            issueDate: $identifier->getIssueDate()?->format('Y-m-d'),
            expiryDate: $identifier->getExpiryDate()?->format('Y-m-d'),
            verificationStatus: $identifier->getVerificationStatus()->value,
            verifiedAt: $identifier->getVerifiedAt()?->format(DateTimeInterface::ATOM),
            createdAt: $identifier->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $identifier->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function maskIdentifier(string $identifier, \App\Enum\GovernmentIdType $type): string
    {
        $cleaned = preg_replace('/\s+/', '', $identifier);

        if (strlen($cleaned) <= 4) {
            return str_repeat('X', strlen($cleaned));
        }

        return match ($type) {
            \App\Enum\GovernmentIdType::Aadhaar => 'XXXX XXXX ' . substr($cleaned, -4),
            \App\Enum\GovernmentIdType::PAN => str_repeat('X', 5) . substr($cleaned, 5, 4) . str_repeat('X', 1),
            \App\Enum\GovernmentIdType::Passport => str_repeat('X', strlen($cleaned) - 4) . substr($cleaned, -4),
            default => str_repeat('X', strlen($cleaned) - 4) . substr($cleaned, -4),
        };
    }
}
