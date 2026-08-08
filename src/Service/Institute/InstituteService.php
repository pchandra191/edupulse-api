<?php
declare(strict_types=1);

namespace App\Service\Institute;

use App\DTO\Institute\InstituteOptionResponse;
use App\Repository\InstituteRepository;
use App\Entity\Institute;
use App\Exception\AuthenticationException;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

readonly class InstituteService
{
    public function __construct(private InstituteRepository $instituteRepository)
    {
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
}
