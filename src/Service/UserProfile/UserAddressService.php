<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\DTO\UserProfile\CreateUserAddressRequestDTO;
use App\DTO\UserProfile\UpdateUserAddressRequestDTO;
use App\DTO\UserProfile\UserAddressResponseDTO;
use App\Entity\User;
use App\Entity\UserAddress;
use App\Repository\UserAddressRepository;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class UserAddressService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAddressRepository $userAddressRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function listAddresses(User $user): array
    {
        $addresses = $this->userAddressRepository->findByUserId($user->getId());

        return array_map(
            fn(UserAddress $address) => $this->mapToResponse($address),
            $addresses
        );
    }

    public function createAddress(User $user, CreateUserAddressRequestDTO $request): UserAddressResponseDTO
    {
        $this->entityManager->beginTransaction();

        try {
            $address = new UserAddress();
            $address->setUserId($user->getId());
            $this->applyRequest($address, $request);

            if ($address->isPrimary()) {
                $this->clearPrimaryAddress($user, $address);
                $this->entityManager->flush();
            }

            $this->entityManager->persist($address);
            $this->entityManager->flush();

            $this->entityManager->commit();

            return $this->mapToResponse($address);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            if ($e instanceof UniqueConstraintViolationException) {
                throw new \InvalidArgumentException('A primary address already exists for this user.');
            }

            $this->logger->error('Failed to create address.', [
                'user_id' => $user->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Unable to create address. Please check your data and try again.', 0, $e);
        }
    }

    public function updateAddress(User $user, Uuid $addressId, UpdateUserAddressRequestDTO $request): UserAddressResponseDTO
    {
        $this->entityManager->beginTransaction();

        try {
            $address = $this->findUserAddress($user, $addressId);

            if ($request->addressType !== null) {
                $address->setAddressType(\App\Enum\AddressType::from($request->addressType));
            }

            if ($request->addressLine1 !== null) {
                $address->setAddressLine1($request->addressLine1);
            }

            if ($request->addressLine2 !== null) {
                $address->setAddressLine2($request->addressLine2);
            }

            if ($request->city !== null) {
                $address->setCity($request->city);
            }

            if ($request->district !== null) {
                $address->setDistrict($request->district);
            }

            if ($request->state !== null) {
                $address->setState($request->state);
            }

            if ($request->country !== null) {
                $address->setCountry($request->country);
            }

            if ($request->postalCode !== null) {
                $address->setPostalCode($request->postalCode);
            }

            if ($request->isPrimary !== null && $request->isPrimary) {
                $currentPrimary = $this->userAddressRepository->findPrimaryByUserId($user->getId());

                if ($currentPrimary !== null && !$currentPrimary->getId()->equals($addressId)) {
                    $currentPrimary->setIsPrimary(false);
                    $this->entityManager->flush();
                }

                $address->setIsPrimary(true);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($address);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            if ($e instanceof UniqueConstraintViolationException) {
                throw new \InvalidArgumentException('A primary address already exists for this user.');
            }

            $this->logger->error('Failed to update address.', [
                'user_id' => $user->getId()->toRfc4122(),
                'address_id' => $addressId->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Unable to update address. Please check your data and try again.', 0, $e);
        }
    }

    public function deleteAddress(User $user, Uuid $addressId): void
    {
        $address = $this->findUserAddress($user, $addressId);

        $this->entityManager->remove($address);
        $this->entityManager->flush();
    }

    private function findUserAddress(User $user, Uuid $addressId): UserAddress
    {
        $address = $this->userAddressRepository->find($addressId);

        if (!$address instanceof UserAddress) {
            throw new \App\Exception\AuthenticationException('Address not found.');
        }

        if (!$address->getUserId()->equals($user->getId())) {
            throw new \App\Exception\AuthenticationException('Address not found.');
        }

        return $address;
    }

    private function clearPrimaryAddress(User $user, ?UserAddress $exclude = null): void
    {
        $currentPrimary = $this->userAddressRepository->findPrimaryByUserId($user->getId());

        if ($currentPrimary !== null && $exclude !== null && !$currentPrimary->getId()->equals($exclude->getId())) {
            $currentPrimary->setIsPrimary(false);
        } elseif ($currentPrimary !== null && $exclude === null) {
            $currentPrimary->setIsPrimary(false);
        }
    }

    private function applyRequest(UserAddress $address, CreateUserAddressRequestDTO $request): void
    {
        $address->setAddressType(\App\Enum\AddressType::from($request->addressType));
        $address->setAddressLine1($request->addressLine1);
        $address->setAddressLine2($request->addressLine2);
        $address->setCity($request->city);
        $address->setDistrict($request->district);
        $address->setState($request->state);
        $address->setCountry($request->country);
        $address->setPostalCode($request->postalCode);
        $address->setIsPrimary($request->isPrimary);
    }

    private function mapToResponse(UserAddress $address): UserAddressResponseDTO
    {
        return new UserAddressResponseDTO(
            id: $address->getId()->toRfc4122(),
            addressType: $address->getAddressType()->value,
            addressLine1: $address->getAddressLine1(),
            addressLine2: $address->getAddressLine2(),
            city: $address->getCity(),
            district: $address->getDistrict(),
            state: $address->getState(),
            country: $address->getCountry(),
            postalCode: $address->getPostalCode(),
            isPrimary: $address->isPrimary(),
            createdAt: $address->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $address->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
