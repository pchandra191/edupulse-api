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
use App\Enum\ClassStatus;
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

        try {
            $this->entityManager->beginTransaction();

            $class = new AcademicClass();
            $class->setInstitute($institute);
            $class->setAcademicYear($academicYear);
            $class->setName($request->name);
            $class->setCode($request->code);
            $class->setDescription($request->description);
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

        $class->setStatus(ClassStatus::Archived);

        $this->entityManager->flush();
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
