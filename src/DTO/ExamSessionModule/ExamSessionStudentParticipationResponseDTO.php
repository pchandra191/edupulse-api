<?php
declare(strict_types=1);

namespace App\DTO\ExamSessionModule;

use App\Entity\ExamAttempt;

readonly class ExamSessionStudentParticipationResponseDTO
{
    public function __construct(
        public string $studentId,
        public string $enrollmentId,
        public string $firstName,
        public string $lastName,
        public string $fullName,
        public string $email,
        public array $academicClass,
        public array $section,
        public string $enrollmentStatus,
        public string $participationStatus,
        public ?StudentParticipationAttemptDTO $attempt = null,
    ) {
    }

    public static function fromData(
        string $studentId,
        string $enrollmentId,
        string $firstName,
        string $lastName,
        string $email,
        array $academicClass,
        ?array $section,
        string $enrollmentStatus,
        string $participationStatus,
        ?ExamAttempt $attempt,
    ): self {
        $sectionArray = $section !== null ? [
            'id' => $section['id'] ?? null,
            'name' => $section['name'] ?? null,
        ] : null;

        return new self(
            studentId: $studentId,
            enrollmentId: $enrollmentId,
            firstName: $firstName,
            lastName: $lastName,
            fullName: trim(($firstName ?? '') . ' ' . ($lastName ?? '')),
            email: $email,
            academicClass: $academicClass,
            section: $sectionArray ?? ['id' => null, 'name' => null],
            enrollmentStatus: $enrollmentStatus,
            participationStatus: $participationStatus,
            attempt: $attempt !== null ? StudentParticipationAttemptDTO::fromExamAttempt($attempt) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'studentId' => $this->studentId,
            'enrollmentId' => $this->enrollmentId,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'fullName' => $this->fullName,
            'email' => $this->email,
            'academicClass' => $this->academicClass,
            'section' => $this->section,
            'enrollmentStatus' => $this->enrollmentStatus,
            'participationStatus' => $this->participationStatus,
            'attempt' => $this->attempt?->toArray(),
        ];
    }
}
