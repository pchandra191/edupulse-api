<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\DTO\AttendanceModule\HolidayCreateRequestDTO;
use App\DTO\AttendanceModule\HolidayFilterRequest;
use App\DTO\AttendanceModule\HolidayResponseDTO;
use App\DTO\AttendanceModule\HolidayUpdateRequestDTO;
use App\DTO\AttendanceModule\InstituteReferenceDTO;
use App\DTO\Response\HolidayListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\HolidayRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class HolidayService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private HolidayRepository $holidayRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getHolidays(?Uuid $instituteId, HolidayFilterRequest $filter): HolidayListResponse
    {
        $result = $this->holidayRepository->findPaginated(
            $instituteId,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->date ? \DateTimeImmutable::createFromFormat('Y-m-d', $filter->date) : null,
            $filter->enabled,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (Holiday $holiday) => $this->mapToResponse($holiday),
            $result['items'],
        );

        return new HolidayListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getHoliday(?Uuid $instituteId, string $id): HolidayResponseDTO
    {
        $holiday = $this->holidayRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$holiday instanceof Holiday) {
            throw new AuthenticationException('Holiday not found.');
        }

        return $this->mapToResponse($holiday);
    }

    public function createHoliday(?Uuid $instituteId, HolidayCreateRequestDTO $request): HolidayResponseDTO
    {
        $this->validateOrganizerAccess();

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $request->date);
        if (!$date) {
            throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
        }
        $date = $date->setTime(0, 0, 0);

        if ($request->instituteId !== null) {
            $instituteId = Uuid::fromString($request->instituteId);
        }

        if ($this->holidayRepository->dateExists($date, $instituteId)) {
            throw new \App\Common\Exception\BusinessException('A holiday already exists for this date in this institute.');
        }

        $holiday = new Holiday();
        $holiday->setHolidayDate($date);
        $holiday->setName($request->name);
        $holiday->setDescription($request->description);
        $holiday->setEnabled($request->enabled);

        if ($instituteId !== null) {
            $holiday->setInstitute($this->entityManager->getReference(Institute::class, $instituteId));
        }

        if ($request->academicYearId !== null) {
            $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);
            if (!$academicYear instanceof AcademicYear) {
                throw new \InvalidArgumentException('Academic year not found.');
            }

            if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Academic year does not belong to your institute.');
            }

            $holiday->setAcademicYear($academicYear);
        }

        if ($request->academicClassId !== null) {
            $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->academicClassId);
            if (!$academicClass instanceof AcademicClass) {
                throw new \InvalidArgumentException('Academic class not found.');
            }

            $holiday->setAcademicClass($academicClass);
        }

        if ($request->sectionId !== null) {
            $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);
            if (!$section instanceof Section) {
                throw new \InvalidArgumentException('Section not found.');
            }

            $holiday->setSection($section);
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->persist($holiday);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create holiday.', [
                'date' => $request->date,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create holiday. Please try again.', previous: $e);
        }

        return $this->mapToResponse($holiday);
    }

    public function updateHoliday(?Uuid $instituteId, string $id, HolidayUpdateRequestDTO $request): HolidayResponseDTO
    {
        $this->validateOrganizerAccess();

        $holiday = $this->holidayRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$holiday instanceof Holiday) {
            throw new AuthenticationException('Holiday not found.');
        }

        try {
            $this->entityManager->beginTransaction();

            if ($request->date !== null) {
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', $request->date);
                if (!$date) {
                    throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
                }
                $date = $date->setTime(0, 0, 0);

                $existingInstituteId = $holiday->getInstitute() ? $holiday->getInstitute()->getId() : $instituteId;
                if ($this->holidayRepository->dateExists($date, $existingInstituteId, $holiday->getId())) {
                    throw new \App\Common\Exception\BusinessException('A holiday already exists for this date in this institute.');
                }

                $holiday->setHolidayDate($date);
            }

            if ($request->name !== null) {
                $holiday->setName($request->name);
            }

            if ($request->description !== null) {
                $holiday->setDescription($request->description);
            }

            if ($request->enabled !== null) {
                $holiday->setEnabled($request->enabled);
            }

            if ($request->academicYearId !== null) {
                $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);
                if (!$academicYear instanceof AcademicYear) {
                    throw new \InvalidArgumentException('Academic year not found.');
                }

                $holiday->setAcademicYear($academicYear);
            }

            if ($request->academicClassId !== null) {
                $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->academicClassId);
                if (!$academicClass instanceof AcademicClass) {
                    throw new \InvalidArgumentException('Academic class not found.');
                }

                $holiday->setAcademicClass($academicClass);
            }

            if ($request->sectionId !== null) {
                $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);
                if (!$section instanceof Section) {
                    throw new \InvalidArgumentException('Section not found.');
                }

                $holiday->setSection($section);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            if ($e instanceof \App\Common\Exception\BusinessException) {
                throw $e;
            }

            $this->logger->error('Failed to update holiday.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update holiday. Please try again.', previous: $e);
        }

        return $this->mapToResponse($holiday);
    }

    public function deleteHoliday(?Uuid $instituteId, string $id): void
    {
        $this->validateOrganizerAccess();

        $holiday = $this->holidayRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$holiday instanceof Holiday) {
            throw new AuthenticationException('Holiday not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->remove($holiday);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to delete holiday.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete holiday. Please try again.', previous: $e);
        }
    }

    private function validateOrganizerAccess(): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Students are not authorized to manage holidays.');
        }
    }

    private function mapToResponse(Holiday $holiday): HolidayResponseDTO
    {
        $institute = $holiday->getInstitute();
        $academicYear = $holiday->getAcademicYear();
        $academicClass = $holiday->getAcademicClass();
        $section = $holiday->getSection();

        return new HolidayResponseDTO(
            id: (string) $holiday->getId(),
            institute: $institute ? new InstituteReferenceDTO(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ) : null,
            academicYear: $academicYear ? new \App\DTO\AttendanceModule\AcademicYearReferenceDTO(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ) : null,
            academicClass: $academicClass ? new \App\DTO\AttendanceModule\AcademicClassReferenceDTO(
                id: (string) $academicClass->getId(),
                name: $academicClass->getName(),
                code: $academicClass->getCode(),
                startDate: $academicClass->getStartDate()?->format(DateTimeInterface::ATOM),
                endDate: $academicClass->getEndDate()?->format(DateTimeInterface::ATOM),
            ) : null,
            section: $section ? new \App\DTO\AttendanceModule\SectionReferenceDTO(
                id: (string) $section->getId(),
                name: $section->getName(),
                code: $section->getCode(),
            ) : null,
            date: $holiday->getHolidayDate()->format('Y-m-d'),
            name: $holiday->getName(),
            description: $holiday->getDescription(),
            enabled: $holiday->isEnabled(),
            createdAt: $holiday->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $holiday->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
