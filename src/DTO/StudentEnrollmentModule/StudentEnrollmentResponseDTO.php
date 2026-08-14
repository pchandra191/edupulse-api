<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use DateTimeInterface;

readonly class StudentEnrollmentResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteResponse $institute,
        public StudentResponse $student,
        public AcademicYearResponse $academicYear,
        public AcademicClassResponse $academicClass,
        public ?string $rollNumber,
        public string $admissionNumber,
        public ?string $admissionDate,
        public ?string $remarks,
        public bool $isCurrent,
        public string $status,
        public array $sections,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}

readonly class InstituteResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}

readonly class StudentResponse
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
