<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\ExamSet;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\DTO\Analysis\ExamSetCreateRequestDTO;
use App\DTO\Analysis\ExamSetUpdateRequestDTO;
use App\DTO\Analysis\ExamSetResponseDTO;
use App\DTO\Analysis\ExamSetSummaryDTO;
use App\DTO\Analysis\AnalysisSummaryDTO;
use App\Exception\AnalysisNotFoundException;
use App\Repository\AcademicClassRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\ExamSetRepository;
use App\Repository\SectionRepository;
use App\Repository\StudentEnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class ExamSetService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExamSetRepository $examSetRepository,
        private AcademicClassRepository $academicClassRepository,
        private AcademicYearRepository $academicYearRepository,
        private SectionRepository $sectionRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createExamSet(User $student, ExamSetCreateRequestDTO $request): ExamSetResponseDTO
    {
        $enrollment = $this->resolveEnrollment($student);
        $isInstituteStudent = $enrollment !== null;

        // Resolve effective academic context: explicit request values override enrollment
        $academicYearId = $request->academicYearId;
        $academicYear = $request->academicYear;
        $classId = $request->classId;
        $academicClass = $request->academicClass;
        $sectionId = $request->sectionId;
        $section = $request->section;

        if ($isInstituteStudent) {
            // Prefill from enrollment when request does not provide explicit values
            if ($academicYearId === null) {
                $academicYearId = $enrollment->getAcademicYear()->getId()->toRfc4122();
            }
            if ($academicYear === null) {
                $academicYear = $enrollment->getAcademicYear()->getName();
            }
            if ($classId === null) {
                $classId = $enrollment->getAcademicClass()->getId()->toRfc4122();
            }
            if ($academicClass === null) {
                $academicClass = $enrollment->getAcademicClass()->getName();
            }
            // Section: only prefill if enrollment actually has a section
            if ($sectionId === null && $section === null) {
                $enrollmentSection = $this->resolveEnrollmentSection($enrollment);
                if ($enrollmentSection !== null) {
                    $sectionId = $enrollmentSection->getSection()->getId()->toRfc4122();
                    $section = $enrollmentSection->getSection()->getName();
                }
            }
        }

        // Validate explicit IDs against master data
        $this->validateAcademicContext($student, $academicYearId, $classId, $sectionId);

        // Resolve display names from master data (authoritative source)
        $academicYear = $this->resolveAcademicYearName($academicYearId, $student, $academicYear);
        $academicClass = $this->resolveClassName($classId, $student, $academicClass);
        $section = $this->resolveSectionName($sectionId, $student, $section);

        // Check for existing exam set with same metadata
        $existing = $this->examSetRepository->findByStudentAndMetadata(
            $student,
            $academicYearId,
            $academicYear,
            $classId,
            $academicClass,
            $sectionId,
            $section,
            $request->examId,
            $request->examName,
            $request->examSubTypeId,
            $request->examSubType
        );

        if ($existing) {
            throw new \LogicException('Exam set with this metadata already exists');
        }

        $this->entityManager->beginTransaction();
        try {
            $examSet = new ExamSet();
            $examSet->setStudent($student);
            $examSet->setAcademicYearId($academicYearId);
            $examSet->setAcademicYear($academicYear);
            $examSet->setClassId($classId);
            $examSet->setAcademicClass($academicClass);
            $examSet->setSectionId($sectionId);
            $examSet->setSection($section);
            $examSet->setExamId($request->examId);
            $examSet->setExamName($request->examName);
            $examSet->setExamSubTypeId($request->examSubTypeId);
            $examSet->setExamSubType($request->examSubType);

            // Set institute from student's current enrollment if available
            if ($student->getInstitute()) {
                $examSet->setInstitute($student->getInstitute());
            }

            $this->entityManager->persist($examSet);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to create ExamSet', [
                'student_id' => (string) $student->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $this->mapToResponse($examSet);
    }

    public function getExamSet(User $student, string $examSetId): ExamSetResponseDTO
    {
        $examSet = $this->examSetRepository->findByIdAndStudent($examSetId, $student);
        if (!$examSet) {
            throw new AnalysisNotFoundException('Exam set not found.');
        }

        return $this->mapToResponse($examSet);
    }

    public function listExamSets(User $student, int $page = 1, int $limit = 20): array
    {
        $examSets = $this->examSetRepository->findByStudentPaginated($student, $page, $limit);
        return array_map(fn($es) => $this->mapToSummary($es), $examSets);
    }

    public function countExamSets(User $student): int
    {
        return $this->examSetRepository->countByStudent($student);
    }

    public function updateExamSet(User $student, string $examSetId, ExamSetUpdateRequestDTO $request): ExamSetResponseDTO
    {
        $examSet = $this->examSetRepository->findByIdAndStudent($examSetId, $student);
        if (!$examSet) {
            throw new AnalysisNotFoundException('Exam set not found.');
        }

        $this->entityManager->beginTransaction();
        try {
            if ($request->academicYearId !== null) {
                $this->validateAcademicYearId($student, $request->academicYearId);
                $examSet->setAcademicYearId($request->academicYearId);
            }
            if ($request->academicYear !== null) {
                $examSet->setAcademicYear($request->academicYear);
            }
            if ($request->classId !== null) {
                $this->validateClassId($student, $request->classId);
                $examSet->setClassId($request->classId);
                $resolved = $this->resolveClassName($request->classId, $student, null);
                if ($resolved !== null) {
                    $examSet->setAcademicClass($resolved);
                }
            }
            if ($request->academicClass !== null) {
                $examSet->setAcademicClass($request->academicClass);
            }
            if ($request->sectionId !== null) {
                $this->validateSectionId($student, $request->sectionId);
                $examSet->setSectionId($request->sectionId);
                $resolved = $this->resolveSectionName($request->sectionId, $student, null);
                if ($resolved !== null) {
                    $examSet->setSection($resolved);
                }
            }
            if ($request->section !== null) {
                $examSet->setSection($request->section);
            }
            if ($request->examId !== null) {
                $examSet->setExamId($request->examId);
            }
            if ($request->examName !== null) {
                $examSet->setExamName($request->examName);
            }
            if ($request->examSubTypeId !== null) {
                $examSet->setExamSubTypeId($request->examSubTypeId);
            }
            if ($request->examSubType !== null) {
                $examSet->setExamSubType($request->examSubType);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to update ExamSet', [
                'exam_set_id' => $examSetId,
                'student_id' => (string) $student->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $this->mapToResponse($examSet);
    }

    private function mapToResponse(ExamSet $examSet): ExamSetResponseDTO
    {
        $analyses = [];
        foreach ($examSet->getAnalyses() as $analysis) {
            $subjectName = $analysis->getSubject()?->getName() ?? $analysis->getSubjectName() ?? 'Unknown';
            $analyses[] = new \App\DTO\Analysis\AnalysisSummaryDTO(
                analysisId: $analysis->getAnalysisId(),
                source: $analysis->getSource(),
                status: $analysis->getStatus(),
                title: $analysis->getSubjectName() ?? $examSet->getExamName() ?? 'Untitled',
                subject: $subjectName,
                examSetId: $examSet->getId()->toRfc4122(),
                createdAt: $analysis->getCreatedAt()->format(\DateTimeInterface::ATOM),
                updatedAt: $analysis->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            );
        }

        $classId = $examSet->getClassId();
        $sectionId = $examSet->getSectionId();
        $academicClass = $examSet->getAcademicClass();
        $section = $examSet->getSection();

        // Always resolve display names from master data when IDs are present
        if ($classId !== null && $academicClass === null) {
            $resolved = $this->resolveClassName($classId, $examSet->getStudent(), null);
            if ($resolved !== null) {
                $academicClass = $resolved;
            }
        }
        if ($sectionId !== null && $section === null) {
            $resolved = $this->resolveSectionName($sectionId, $examSet->getStudent(), null);
            if ($resolved !== null) {
                $section = $resolved;
            }
        }

        return new \App\DTO\Analysis\ExamSetResponseDTO(
            id: $examSet->getId()->toRfc4122(),
            academicYearId: $examSet->getAcademicYearId(),
            academicYear: $examSet->getAcademicYear(),
            classId: $classId,
            academicClass: $academicClass,
            sectionId: $sectionId,
            section: $section,
            examId: $examSet->getExamId(),
            examName: $examSet->getExamName(),
            examSubTypeId: $examSet->getExamSubTypeId(),
            examSubType: $examSet->getExamSubType(),
            analyses: $analyses,
            createdAt: $examSet->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $examSet->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    private function mapToSummary(ExamSet $examSet): ExamSetSummaryDTO
    {
        $classId = $examSet->getClassId();
        $sectionId = $examSet->getSectionId();
        $academicClass = $examSet->getAcademicClass();
        $section = $examSet->getSection();

        // Always resolve display names from master data when IDs are present
        if ($classId !== null && $academicClass === null) {
            $resolved = $this->resolveClassName($classId, $examSet->getStudent(), null);
            if ($resolved !== null) {
                $academicClass = $resolved;
            }
        }
        if ($sectionId !== null && $section === null) {
            $resolved = $this->resolveSectionName($sectionId, $examSet->getStudent(), null);
            if ($resolved !== null) {
                $section = $resolved;
            }
        }

        return new \App\DTO\Analysis\ExamSetSummaryDTO(
            id: $examSet->getId()->toRfc4122(),
            academicYear: $examSet->getAcademicYear(),
            academicClass: $academicClass,
            section: $section,
            examName: $examSet->getExamName(),
            examSubType: $examSet->getExamSubType(),
            createdAt: $examSet->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $examSet->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    private function resolveEnrollment(User $student): ?StudentEnrollment
    {
        if ($student->getInstitute() === null) {
            return null;
        }

        return $this->studentEnrollmentRepository->findCurrentEnrollmentWithDetails($student->getId());
    }

    private function resolveEnrollmentSection(StudentEnrollment $enrollment): ?EnrollmentSection
    {
        foreach ($enrollment->getEnrollmentSections() as $enrollmentSection) {
            if ($enrollmentSection->getSection() !== null) {
                return $enrollmentSection;
            }
        }

        return null;
    }

    private function validateAcademicContext(
        User $student,
        ?string $academicYearId,
        ?string $classId,
        ?string $sectionId
    ): void {
        if ($student->getInstitute() === null) {
            // Independent student: no validation needed
            return;
        }

        if ($academicYearId !== null) {
            $this->validateAcademicYearId($student, $academicYearId);
        }
        if ($classId !== null) {
            $this->validateClassId($student, $classId);
        }
        if ($sectionId !== null) {
            $this->validateSectionId($student, $sectionId);
        }
    }

    private function validateAcademicYearId(User $student, string $academicYearId): void
    {
        $instituteId = $student->getInstitute()?->getId();
        $year = $this->academicYearRepository->findById(Uuid::fromString($academicYearId), $instituteId);
        if ($year === null) {
            throw new \LogicException('Academic year not found or not accessible.');
        }
    }

    private function validateClassId(User $student, string $classId): void
    {
        $instituteId = $student->getInstitute()?->getId();
        $class = $this->academicClassRepository->findById(Uuid::fromString($classId), $instituteId);
        if ($class === null) {
            throw new \LogicException('Class not found or not accessible.');
        }
    }

    private function validateSectionId(User $student, string $sectionId): void
    {
        $instituteId = $student->getInstitute()?->getId();
        $section = $this->sectionRepository->findById(Uuid::fromString($sectionId), $instituteId);
        if ($section === null) {
            throw new \LogicException('Section not found or not accessible.');
        }
    }

    private function resolveAcademicYearName(?string $academicYearId, User $student, ?string $fallback): ?string
    {
        if ($academicYearId === null) {
            return $fallback;
        }

        $instituteId = $student->getInstitute()?->getId();
        $name = $this->academicYearRepository->findNameById(Uuid::fromString($academicYearId), $instituteId);

        return $name ?? $fallback;
    }

    private function resolveClassName(?string $classId, User $student, ?string $fallback): ?string
    {
        if ($classId === null) {
            return $fallback;
        }

        $instituteId = $student->getInstitute()?->getId();
        $class = $this->academicClassRepository->findById(Uuid::fromString($classId), $instituteId);

        return $class?->getName() ?? $fallback;
    }

    private function resolveSectionName(?string $sectionId, User $student, ?string $fallback): ?string
    {
        if ($sectionId === null) {
            return $fallback;
        }

        $instituteId = $student->getInstitute()?->getId();
        $section = $this->sectionRepository->findById(Uuid::fromString($sectionId), $instituteId);

        return $section?->getName() ?? $fallback;
    }
}