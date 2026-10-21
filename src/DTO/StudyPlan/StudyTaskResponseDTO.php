<?php
declare(strict_types=1);

namespace App\DTO\StudyPlan;

readonly class StudyTaskResponseDTO
{
    public function __construct(
        public string $id,
        public ?string $title,
        public ?string $description,
        public ?string $subject,
        public ?string $topic,
        public string $priority,
        public ?int $estimatedMinutes,
        public ?string $dueDate,
        public bool $isCompleted,
        public bool $isAiRecommended,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
