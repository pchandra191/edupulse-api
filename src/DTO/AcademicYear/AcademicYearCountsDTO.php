<?php
declare(strict_types=1);

namespace App\DTO\AcademicYear;

readonly class AcademicYearCountsDTO
{
    public function __construct(
        public int $classesCount,
        public int $sectionsCount,
        public int $studentsCount,
        public int $teachersCount,
        public int $examinationsCount,
        public int $questionPapersCount,
    ) {
    }
}
