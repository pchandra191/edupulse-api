<?php
declare(strict_types=1);

namespace App\Service\SubjectModule;

use App\DTO\SubjectModule\CreateSubjectRequestDTO;
use App\DTO\SubjectModule\SubjectFilterRequest;
use App\DTO\SubjectModule\SubjectResponseDTO;
use App\DTO\SubjectModule\UpdateSubjectRequestDTO;
use App\DTO\Response\ClassSubjectListResponse;
use App\Entity\AcademicClass;
use App\Entity\ClassSubject;
use App\Entity\Institute;
use App\Entity\Subject;
use App\Enum\SubjectStatus;
use App\Exception\AuthenticationException;
use App\Repository\ClassSubjectRepository;
use App\Repository\InstituteRepository;
use App\Repository\SubjectRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class SubjectService
{
    public function __construct(
        private Security $security,
        private SubjectRepository $subjectRepository,
        private ClassSubjectRepository $classSubjectRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getSubjects(?Uuid $instituteId, SubjectFilterRequest $filter): ClassSubjectListResponse
    {
        $result = $this->subjectRepository->findPaginated(
            $instituteId,
            $filter->search,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (Subject $subject) => $this->mapToResponse($subject),
            $result['items'],
        );

        return new ClassSubjectListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getSubject(Uuid $id, ?Uuid $instituteId): SubjectResponseDTO
    {
        $subject = $this->subjectRepository->findById($id, $instituteId);

        if (!$subject instanceof Subject) {
            throw new AuthenticationException('Subject not found.');
        }

        return $this->mapToResponse($subject);
    }

    public function createSubject(Uuid $instituteId, CreateSubjectRequestDTO $request): SubjectResponseDTO
    {
        $institute = $this->instituteRepository->find($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        if ($this->subjectRepository->codeExistsForInstitute($request->code, $instituteId)) {
            throw new \App\Common\Exception\BusinessException('Subject code already exists for this institute.');
        }

        try {
            $this->entityManager->beginTransaction();

            $subject = new Subject();
            $subject->setInstitute($institute);
            $subject->setName($request->name);
            $subject->setCode($request->code);
            $subject->setDescription($request->description);
            $subject->setStatus(SubjectStatus::from($request->status));

            $this->entityManager->persist($subject);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($subject);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create subject.', [
                'institute_id' => (string) $instituteId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create subject. Please try again.', previous: $e);
        }
    }

    public function updateSubject(Uuid $id, ?Uuid $instituteId, UpdateSubjectRequestDTO $request): SubjectResponseDTO
    {
        $subject = $this->subjectRepository->findById($id, $instituteId);

        if (!$subject instanceof Subject) {
            throw new AuthenticationException('Subject not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($subject, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($subject);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update subject.', [
                'subject_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update subject. Please try again.', previous: $e);
        }
    }

    public function deleteSubject(Uuid $id, ?Uuid $instituteId): void
    {
        $subject = $this->subjectRepository->findById($id, $instituteId);

        if (!$subject instanceof Subject) {
            throw new AuthenticationException('Subject not found.');
        }

        $subject->setStatus(SubjectStatus::Archived);

        $this->entityManager->flush();
    }

    public function getClassSubjects(Uuid $classId, ?Uuid $instituteId): array
    {
        $class = $this->entityManager->getRepository(AcademicClass::class)->find($classId);

        if (!$class instanceof AcademicClass) {
            throw new AuthenticationException('Class not found.');
        }

        if ($instituteId !== null && !$class->getInstitute()->getId()->equals($instituteId)) {
            throw new AuthenticationException('Class not found.');
        }

        $subjects = $this->classSubjectRepository->findSubjectsByClass($classId);

        return array_map(
            fn (Subject $subject) => $this->mapToResponse($subject),
            $subjects,
        );
    }

    public function assignSubjectsToClass(Uuid $classId, array $subjectIds, ?Uuid $instituteId): void
    {
        $class = $this->entityManager->getRepository(AcademicClass::class)->find($classId);

        if (!$class instanceof AcademicClass) {
            throw new AuthenticationException('Class not found.');
        }

        if ($instituteId !== null && !$class->getInstitute()->getId()->equals($instituteId)) {
            throw new AuthenticationException('Class not found.');
        }

        try {
            $this->entityManager->beginTransaction();

            foreach ($subjectIds as $subjectId) {
                $subject = $this->subjectRepository->findById(Uuid::fromString($subjectId), $instituteId);

                if (!$subject instanceof Subject) {
                    throw new \InvalidArgumentException('Subject not found: ' . $subjectId);
                }

                $existing = $this->classSubjectRepository->findByClassAndSubject($classId, $subject->getId());

                if ($existing instanceof ClassSubject) {
                    continue;
                }

                $classSubject = new ClassSubject();
                $classSubject->setAcademicClass($class);
                $classSubject->setSubject($subject);

                $this->entityManager->persist($classSubject);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to assign subjects to class.', [
                'class_id' => (string) $classId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to assign subjects. Please try again.', previous: $e);
        }
    }

    public function removeSubjectFromClass(Uuid $classId, Uuid $subjectId, ?Uuid $instituteId): void
    {
        $class = $this->entityManager->getRepository(AcademicClass::class)->find($classId);

        if (!$class instanceof AcademicClass) {
            throw new AuthenticationException('Class not found.');
        }

        if ($instituteId !== null && !$class->getInstitute()->getId()->equals($instituteId)) {
            throw new AuthenticationException('Class not found.');
        }

        $subject = $this->subjectRepository->findById($subjectId, $instituteId);

        if (!$subject instanceof Subject) {
            throw new AuthenticationException('Subject not found.');
        }

        $this->classSubjectRepository->deleteByClassAndSubject($classId, $subjectId);
    }

    private function updateFields(Subject $subject, UpdateSubjectRequestDTO $request): void
    {
        if ($request->name !== null && $request->name !== $subject->getName()) {
            $subject->setName($request->name);
        }

        if ($request->code !== null && $request->code !== $subject->getCode()) {
            $instituteId = $subject->getInstitute()->getId();

            if ($this->subjectRepository->codeExistsForInstitute($request->code, $instituteId, $subject->getId())) {
                throw new \App\Common\Exception\BusinessException('Subject code already exists for this institute.');
            }

            $subject->setCode($request->code);
        }

        if ($request->description !== null && $request->description !== $subject->getDescription()) {
            $subject->setDescription($request->description);
        }

        if ($request->status !== null) {
            $subject->setStatus(SubjectStatus::from($request->status));
        }
    }

    private function mapToResponse(Subject $subject): SubjectResponseDTO
    {
        return new SubjectResponseDTO(
            id: (string) $subject->getId(),
            instituteId: (string) $subject->getInstitute()->getId(),
            name: $subject->getName(),
            code: $subject->getCode(),
            description: $subject->getDescription(),
            status: $subject->getStatus()->value,
            createdAt: $subject->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $subject->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
