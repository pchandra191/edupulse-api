<?php
declare(strict_types=1);

namespace App\Service\ClassModule;

use App\DTO\ClassModule\AcademicYearResponse;
use App\DTO\ClassModule\ClassFilterRequest;
use App\DTO\ClassModule\ClassResponseDTO;
use App\DTO\ClassModule\CreateClassRequestDTO;
use App\DTO\ClassModule\UpdateClassRequestDTO;
use App\DTO\Response\ClassListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\SectionStatus;
use App\Exception\AuthenticationException;
use App\Repository\AcademicClassRepository;
use App\Repository\InstituteRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class ClassService
{
    public function __construct(
        private Security $security,
        private AcademicClassRepository $classRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getClasses(?Uuid $instituteId, ClassFilterRequest $filter): ClassListResponse
    {
        $result = $this->classRepository->findPaginated(
            $instituteId,
            $filter->search,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (AcademicClass $class) => $this->mapToResponse($class),
            $result['items'],
        );

        return new ClassListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getClass(Uuid $id, ?Uuid $instituteId): ClassResponseDTO
    {
        $class = $this->classRepository->findById($id, $instituteId);

        if (!$class instanceof AcademicClass) {
            throw new AuthenticationException('Class not found.');
        }

        return $this->mapToResponse($class);
    }

    public function createClass(Uuid $instituteId, CreateClassRequestDTO $request): ClassResponseDTO
    {
        $institute = $this->instituteRepository->find($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);

        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if (!$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to the authenticated institute.');
        }

        if ($this->classRepository->codeExistsForInstitute($request->code, $instituteId)) {
            throw new \App\Common\Exception\BusinessException('Class code already exists for this institute.');
        }

        if ($academicYear->getStatus() === AcademicYearStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Cannot create class for an archived academic year.');
        }

        try {
            $this->entityManager->beginTransaction();

            $class = new AcademicClass();
            $class->setInstitute($institute);
            $class->setAcademicYear($academicYear);
            $class->setName($request->name);
            $class->setCode($request->code);
            $class->setDescription($request->description);

            if ($request->startDate !== null && $request->startDate !== '') {
                $class->setStartDate(new \DateTimeImmutable($request->startDate));
            }

            if ($request->endDate !== null && $request->endDate !== '') {
                $class->setEndDate(new \DateTimeImmutable($request->endDate));
            }

            $class->setStatus(ClassStatus::from($request->status));

            $this->entityManager->persist($class);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($class);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create class.', [
                'institute_id' => (string) $instituteId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create class. Please try again.', previous: $e);
        }
    }

    public function updateClass(Uuid $id, ?Uuid $instituteId, UpdateClassRequestDTO $request): ClassResponseDTO
    {
        $class = $this->classRepository->findById($id, $instituteId);

        if (!$class instanceof AcademicClass) {
            throw new AuthenticationException('Class not found.');
        }

        if ($class->getStatus() === ClassStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived classes cannot be modified.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($class, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($class);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update class.', [
                'class_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update class. Please try again.', previous: $e);
        }
    }

    public function deleteClass(Uuid $id, ?Uuid $instituteId): void
    {
        $class = $this->classRepository->findById($id, $instituteId);

        if (!$class instanceof AcademicClass) {
            throw new AuthenticationException('Class not found.');
        }

        try {
            $this->entityManager->beginTransaction();

            foreach ($class->getSections() as $section) {
                $section->setStatus(SectionStatus::Archived);
            }

            $class->setStatus(ClassStatus::Archived);

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to archive class.', [
                'class_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to archive class. Please try again.', previous: $e);
        }
    }

    private function updateFields(AcademicClass $class, UpdateClassRequestDTO $request): void
    {
        if ($request->name !== null && $request->name !== $class->getName()) {
            $class->setName($request->name);
        }

        if ($request->code !== null && $request->code !== $class->getCode()) {
            $instituteId = $class->getInstitute()->getId();

            if ($this->classRepository->codeExistsForInstitute($request->code, $instituteId, $class->getId())) {
                throw new \App\Common\Exception\BusinessException('Class code already exists for this institute.');
            }

            $class->setCode($request->code);
        }

        if ($request->description !== null && $request->description !== $class->getDescription()) {
            $class->setDescription($request->description);
        }

        if ($request->startDate !== null && $request->startDate !== '') {
            $currentStart = $class->getStartDate();
            $newStart = new \DateTimeImmutable($request->startDate);
            if ($currentStart === null || $currentStart->format('Y-m-d') !== $newStart->format('Y-m-d')) {
                $class->setStartDate($newStart);
            }
        }

        if ($request->endDate !== null && $request->endDate !== '') {
            $currentEnd = $class->getEndDate();
            $newEnd = new \DateTimeImmutable($request->endDate);
            if ($currentEnd === null || $currentEnd->format('Y-m-d') !== $newEnd->format('Y-m-d')) {
                $class->setEndDate($newEnd);
            }
        }

        if ($request->status !== null) {
            $class->setStatus(ClassStatus::from($request->status));
        }
    }

    private function mapToResponse(AcademicClass $class): ClassResponseDTO
    {
        $academicYear = $class->getAcademicYear();

        return new ClassResponseDTO(
            id: (string) $class->getId(),
            instituteId: (string) $class->getInstitute()->getId(),
            name: $class->getName(),
            code: $class->getCode(),
            description: $class->getDescription(),
            startDate: $class->getStartDate()?->format(DateTimeInterface::ATOM),
            endDate: $class->getEndDate()?->format(DateTimeInterface::ATOM),
            status: $class->getStatus()->value,
            academicYear: new AcademicYearResponse(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ),
            createdAt: $class->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $class->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
