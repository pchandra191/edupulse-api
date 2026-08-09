<?php
declare(strict_types=1);

namespace App\Service\Institute;

use App\DTO\Institute\InstituteFilterRequest;
use App\DTO\Institute\InstituteOptionResponse;
use App\DTO\Institute\InstituteProfileResponseDTO;
use App\DTO\Institute\InstituteProfileUpdateRequestDTO;
use App\DTO\Response\InstituteListResponse;
use App\Common\Exception\BusinessException;
use App\Repository\InstituteRepository;
use App\Entity\Institute;
use App\Exception\AuthenticationException;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class InstituteService
{
    public function __construct(
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getOptions(): array
    {
        $rows = $this->instituteRepository->findActiveOptions();

        return array_map(
            function (array $row): InstituteOptionResponse {
                $createdAt = $row['createdAt'] ?? null;
                $updatedAt = $row['updatedAt'] ?? null;

                if ($createdAt instanceof DateTimeInterface) {
                    $createdAt = $createdAt->format(DateTimeInterface::ATOM);
                }

                if ($updatedAt instanceof DateTimeInterface) {
                    $updatedAt = $updatedAt->format(DateTimeInterface::ATOM);
                }

                return new InstituteOptionResponse(
                    id: (string) $row['id'],
                    name: (string) $row['name'],
                    code: (string) $row['code'],
                    email: (string) $row['email'],
                    phone: isset($row['phone']) ? (string) $row['phone'] : null,
                    website: isset($row['website']) ? (string) $row['website'] : null,
                    logo: isset($row['logo']) ? (string) $row['logo'] : null,
                    addressLine1: isset($row['addressLine1']) ? (string) $row['addressLine1'] : null,
                    addressLine2: isset($row['addressLine2']) ? (string) $row['addressLine2'] : null,
                    city: isset($row['city']) ? (string) $row['city'] : null,
                    state: isset($row['state']) ? (string) $row['state'] : null,
                    country: isset($row['country']) ? (string) $row['country'] : null,
                    postalCode: isset($row['postalCode']) ? (string) $row['postalCode'] : null,
                    timezone: isset($row['timezone']) ? (string) $row['timezone'] : null,
                    currency: isset($row['currency']) ? (string) $row['currency'] : null,
                    status: (string) $row['status'],
                    createdAt: (string) $createdAt,
                    updatedAt: $updatedAt !== null ? (string) $updatedAt : null,
                );
            },
            $rows,
        );
    }

    public function getById(Uuid $id): InstituteOptionResponse
    {
        $institute = $this->instituteRepository->findOneActiveById((string) $id);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        return $this->mapToResponse($institute);
    }

    public function getInstitutes(InstituteFilterRequest $filter): InstituteListResponse
    {
        $result = $this->instituteRepository->findPaginated($filter);

        $items = array_map(
            fn (Institute $institute) => $this->mapToProfileResponse($institute),
            $result->items,
        );

        return new InstituteListResponse(
            items: $items,
            pagination: new \App\Common\Api\Response\PaginationResponse(
                page: $result->pagination['page'],
                limit: $result->pagination['limit'],
                totalItems: $result->pagination['totalItems'],
                totalPages: $result->pagination['totalPages'],
            ),
        );
    }

    public function updateInstitute(Uuid $id, InstituteProfileUpdateRequestDTO $request): InstituteProfileResponseDTO
    {
        $institute = $this->instituteRepository->findById((string) $id);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $this->validateUniqueCode($request->name, $institute->getCode());
        $this->validateUniqueEmail($request->email, $institute->getEmail());

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($institute, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToProfileResponse($institute);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update institute.', [
                'institute_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessException('Failed to update institute. Please try again.', previous: $e);
        }
    }

    private function validateUniqueCode(string $name, string $currentCode): void
    {
        $normalized = mb_strtoupper($name);

        if ($normalized === mb_strtoupper($currentCode)) {
            return;
        }

        $existing = $this->instituteRepository->findByCode($normalized);

        if ($existing instanceof Institute) {
            throw new BusinessException('Institute code already exists.');
        }
    }

    private function validateUniqueEmail(string $email, string $currentEmail): void
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === mb_strtolower($currentEmail)) {
            return;
        }

        $existing = $this->instituteRepository->findByEmail($normalized);

        if ($existing instanceof Institute) {
            throw new BusinessException('Institute email already exists.');
        }
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

    private function mapToResponse(Institute $institute): InstituteOptionResponse
    {
        return new InstituteOptionResponse(
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

    private function mapToProfileResponse(Institute $institute): InstituteProfileResponseDTO
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
