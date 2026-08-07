<?php
declare(strict_types=1);

namespace App\DTO\TeacherAssignmentModule;

readonly class TeacherAssignmentResponseDTO
{
    public function __construct(
        public string $id,
        public TeacherResponse $teacher,
        public AcademicYearResponse $academicYear,
        public AcademicClassResponse $academicClass,
        public ?SectionResponse $section,
        public ?SubjectResponse $subject,
        public string $assignmentType,
        public ?string $remarks,
        public string $status,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}

readonly class TeacherResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {
    }
}

readonly class AcademicYearResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}

readonly class AcademicClassResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}

readonly class SectionResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}

readonly class SubjectResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}
