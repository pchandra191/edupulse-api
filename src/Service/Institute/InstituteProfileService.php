<?php
declare(strict_types=1);

namespace App\Service\Institute;

use App\DTO\Institute\InstituteProfileResponseDTO;
use App\DTO\Institute\InstituteProfileUpdateRequestDTO;
use App\Entity\Institute;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\InstituteRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

readonly class InstituteProfileService
{
    public function __construct(
        private Security $security,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getProfile(): InstituteProfileResponseDTO
    {
        $user = $this->getCurrentUser();
        $institute = $user->getInstitute();

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        return $this->mapToResponse($institute);
    }

    public function updateProfile(InstituteProfileUpdateRequestDTO $request): InstituteProfileResponseDTO
    {
        $user = $this->getCurrentUser();
        $institute = $user->getInstitute();

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($institute, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($institute);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update institute profile.', [
                'institute_id' => (string) $institute->getId(),
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update institute profile. Please try again.', previous: $e);
        }
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function updateFields(Institute $institute, InstituteProfileUpdateRequestDTO $request): void
    {
        if ($request->name !== $institute->getName()) {
            $institute->setName($request->name);
        }

        if ($request->email !== $institute->getEmail()) {
            $institute->setEmail($request->email);
        }

        if ($request->phone !== $institute->getPhone()) {
            $institute->setPhone($request->phone);
        }

        if ($request->website !== $institute->getWebsite()) {
            $institute->setWebsite($request->website);
        }

        if ($request->logo !== $institute->getLogo()) {
            $institute->setLogo($request->logo);
        }

        if ($request->addressLine1 !== $institute->getAddressLine1()) {
            $institute->setAddressLine1($request->addressLine1);
        }

        if ($request->addressLine2 !== $institute->getAddressLine2()) {
            $institute->setAddressLine2($request->addressLine2);
        }

        if ($request->city !== $institute->getCity()) {
            $institute->setCity($request->city);
        }

        if ($request->state !== $institute->getState()) {
            $institute->setState($request->state);
        }

        if ($request->country !== $institute->getCountry()) {
            $institute->setCountry($request->country);
        }

        if ($request->postalCode !== $institute->getPostalCode()) {
            $institute->setPostalCode($request->postalCode);
        }

        if ($request->timezone !== $institute->getTimezone()) {
            $institute->setTimezone($request->timezone);
        }

        if ($request->currency !== $institute->getCurrency()) {
            $institute->setCurrency($request->currency);
        }
    }

    private function mapToResponse(Institute $institute): InstituteProfileResponseDTO
    {
        return new InstituteProfileResponseDTO(
            id: (string) $institute->getId(),
            name: $institute->getName(),
            code: $institute->getCode(),
            email: $institute->getEmail(),
            phone: $institute->getPhone(),
            website: $institute->getWebsite(),
            logo: $institute->getLogo(),
            addressLine1: $institute->getAddressLine1(),
            addressLine2: $institute->getAddressLine2(),
            city: $institute->getCity(),
            state: $institute->getState(),
            country: $institute->getCountry(),
            postalCode: $institute->getPostalCode(),
            timezone: $institute->getTimezone(),
            currency: $institute->getCurrency(),
            status: $institute->getStatus()->value,
            createdAt: $institute->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $institute->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
