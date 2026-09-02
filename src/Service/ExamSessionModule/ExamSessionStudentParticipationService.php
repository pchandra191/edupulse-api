<?php
declare(strict_types=1);

namespace App\Service\ExamSessionModule;

use App\DTO\ExamSessionModule\ExamSessionStudentParticipationResponseDTO;
use App\DTO\ExamSessionModule\StudentParticipationAttemptDTO;
use App\DTO\ExamSessionModule\StudentParticipationSummaryDTO;
use App\DTO\Response\ExamSessionStudentParticipationListResponse;
use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\StudentEnrollment;
use App\Exception\AuthenticationException;
use App\Repository\ExamAttemptRepository;
use App\Repository\ExamSessionRepository;
use App\Repository\StudentEnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class ExamSessionStudentParticipationService
{
    public function __construct(
        private ExamSessionRepository $examSessionRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private ExamAttemptRepository $examAttemptRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getStudentParticipation(
        Uuid $examSessionId,
        ?Uuid $instituteId,
        ?string $search,
        ?string $status,
        ?Uuid $sectionId,
        int $page,
        int $limit
    ): ExamSessionStudentParticipationListResponse {
        $examSession = $this->examSessionRepository->find($examSessionId);

        if (!$examSession instanceof ExamSession) {
            throw new AuthenticationException('Exam session not found.');
        }

        if ($instituteId !== null && !$examSession->getInstitute()->getId()->equals($instituteId)) {
            throw new AuthenticationException('You are not authorized to access this exam session.');
        }

        $academicYearId = $examSession->getAcademicYear()->getId();
        $academicClassId = $examSession->getAcademicClass()->getId();
        $instituteId = $examSession->getInstitute()->getId();

        $paginatedResult = $this->examSessionRepository->findParticipationPaginated(
            $examSessionId,
            $instituteId,
            $academicYearId,
            $academicClassId,
            $search,
            $status,
            $sectionId,
            $page,
            $limit
        );

        $studentIds = [];
        foreach ($paginatedResult['items'] as $row) {
            $studentIds[] = (string) $row['student_id'];
        }

        $latestAttempts = $this->examAttemptRepository->findLatestByExamSessionAndStudentIds(
            $examSessionId,
            $studentIds
        );

        $items = [];
        foreach ($paginatedResult['items'] as $row) {
            $studentId = (string) $row['student_id'];
            $attempt = $latestAttempts[$studentId] ?? null;
            $participationStatus = $this->determineParticipationStatus($attempt);

            $items[] = ExamSessionStudentParticipationResponseDTO::fromData(
                studentId: $studentId,
                enrollmentId: (string) $row['enrollment_id'],
                firstName: $row['student_first_name'] ?? '',
                lastName: $row['student_last_name'] ?? '',
                email: $row['student_email'] ?? '',
                academicClass: [
                    'id' => isset($row['class_id']) ? (string) $row['class_id'] : null,
                    'name' => $row['class_name'] ?? null,
                ],
                section: $row['section_id'] !== null ? [
                    'id' => (string) $row['section_id'],
                    'name' => $row['section_name'] ?? null,
                ] : null,
                enrollmentStatus: $row['enrollment_status'] ?? 'ACTIVE',
                participationStatus: $participationStatus,
                attempt: $attempt,
            );
        }

        $summary = $this->buildSummary($examSessionId, $instituteId, $academicYearId, $academicClassId, $search, $sectionId);

        return new ExamSessionStudentParticipationListResponse(
            items: $items,
            summary: $summary,
            pagination: $paginatedResult['pagination'],
        );
    }

    private function determineParticipationStatus(?ExamAttempt $attempt): string
    {
        if (!$attempt instanceof ExamAttempt) {
            return 'NOT_ATTEMPTED';
        }

        return match ($attempt->getStatus()->value) {
            'IN_PROGRESS' => 'IN_PROGRESS',
            'SUBMITTED' => 'SUBMITTED',
            'AUTO_SUBMITTED' => 'AUTO_SUBMITTED',
            default => 'ATTEMPTED',
        };
    }

    private function buildSummary(Uuid $examSessionId, Uuid $instituteId, Uuid $academicYearId, Uuid $academicClassId, ?string $search, ?Uuid $sectionId): StudentParticipationSummaryDTO
    {
        $counts = $this->examSessionRepository->countParticipationSummary(
            $examSessionId,
            $instituteId,
            $academicYearId,
            $academicClassId,
            $search,
            $sectionId
        );

        return new StudentParticipationSummaryDTO(
            eligible: $counts['eligible'],
            attempted: $counts['attempted'],
            inProgress: $counts['inProgress'],
            submitted: $counts['submitted'],
            autoSubmitted: $counts['autoSubmitted'],
            notAttempted: $counts['notAttempted'],
        );
    }
}
