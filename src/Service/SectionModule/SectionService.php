<?php
declare(strict_types=1);

namespace App\Service\SectionModule;

use App\DTO\SectionModule\AcademicClassResponse;
use App\DTO\SectionModule\AcademicYearResponse;
use App\DTO\SectionModule\CreateSectionRequestDTO;
use App\DTO\SectionModule\InstituteResponse;
use App\DTO\SectionModule\SectionFilterRequest;
use App\DTO\SectionModule\SectionResponseDTO;
use App\DTO\SectionModule\UpdateSectionRequestDTO;
use App\DTO\Response\SectionListResponse;
use App\Entity\AcademicClass;
use App\Entity\Institute;
use App\Entity\Section;
use App\Enum\SectionStatus;
use App\Exception\AuthenticationException;
use App\Repository\InstituteRepository;
use App\Repository\SectionRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class SectionService
{
    public function __construct(
        private Security $security,
        private SectionRepository $sectionRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getSections(?Uuid $instituteId, SectionFilterRequest $filter): SectionListResponse
    {
        $result = $this->sectionRepository->findPaginated(
            $instituteId,
            $filter->academicClassId ? Uuid::fromString($filter->academicClassId) : null,
            $filter->search,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (Section $section) => $this->mapToResponse($section),
            $result['items'],
        );

        return new SectionListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getSection(Uuid $id, ?Uuid $instituteId): SectionResponseDTO
    {
        $section = $this->sectionRepository->findById($id, $instituteId);

        if (!$section instanceof Section) {
            throw new AuthenticationException('Section not found.');
        }

        return $this->mapToResponse($section);
    }

    public function createSection(Uuid $instituteId, CreateSectionRequestDTO $request): SectionResponseDTO
    {
        $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->academicClassId);

        if (!$academicClass instanceof AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        if ($instituteId !== null && !$academicClass->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic class does not belong to the authenticated institute.');
        }

        if ($this->sectionRepository->codeExistsForClass($request->code, $academicClass->getId())) {
            throw new \App\Common\Exception\BusinessException('Section code already exists for this class.');
        }

        try {
            $this->entityManager->beginTransaction();

            $section = new Section();
            $section->setAcademicClass($academicClass);
            $section->setName($request->name);
            $section->setCode($request->code);
            $section->setCapacity($request->capacity);
            $section->setDescription($request->description);
            $section->setStatus(SectionStatus::from($request->status));

            $this->entityManager->persist($section);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($section);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create section.', [
                'class_id' => (string) $academicClass->getId(),
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create section. Please try again.', previous: $e);
        }
    }

    public function updateSection(Uuid $id, ?Uuid $instituteId, UpdateSectionRequestDTO $request): SectionResponseDTO
    {
        $section = $this->sectionRepository->findById($id, $instituteId);

        if (!$section instanceof Section) {
            throw new AuthenticationException('Section not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($section, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($section);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update section.', [
                'section_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update section. Please try again.', previous: $e);
        }
    }

    public function deleteSection(Uuid $id, ?Uuid $instituteId): void
    {
        $section = $this->sectionRepository->findById($id, $instituteId);

        if (!$section instanceof Section) {
            throw new AuthenticationException('Section not found.');
        }

        $section->setStatus(SectionStatus::Archived);

        $this->entityManager->flush();
    }

    private function updateFields(Section $section, UpdateSectionRequestDTO $request): void
    {
        if ($request->name !== null && $request->name !== $section->getName()) {
            $section->setName($request->name);
        }

        if ($request->code !== null && $request->code !== $section->getCode()) {
            $classId = $section->getAcademicClass()->getId();

            if ($this->sectionRepository->codeExistsForClass($request->code, $classId, $section->getId())) {
                throw new \App\Common\Exception\BusinessException('Section code already exists for this class.');
            }

            $section->setCode($request->code);
        }

        if ($request->capacity !== null && $request->capacity !== $section->getCapacity()) {
            $section->setCapacity($request->capacity);
        }

        if ($request->description !== null && $request->description !== $section->getDescription()) {
            $section->setDescription($request->description);
        }

        if ($request->status !== null) {
            $section->setStatus(SectionStatus::from($request->status));
        }
    }

    private function mapToResponse(Section $section): SectionResponseDTO
    {
        $academicClass = $section->getAcademicClass();
        $academicYear = $academicClass->getAcademicYear();
        $institute = $academicYear->getInstitute();

        return new SectionResponseDTO(
            institute: new InstituteResponse(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ),
            id: (string) $section->getId(),
            academicYear: new AcademicYearResponse(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ),
            academicClass: new AcademicClassResponse(
                id: (string) $academicClass->getId(),
                name: $academicClass->getName(),
            ),
            name: $section->getName(),
            code: $section->getCode(),
            capacity: $section->getCapacity(),
            description: $section->getDescription(),
            status: $section->getStatus()->value,
            createdAt: $section->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $section->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
