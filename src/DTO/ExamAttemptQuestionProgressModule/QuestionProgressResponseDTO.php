<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptQuestionProgressModule;

use App\Enum\ExamQuestionProgressStatus;
use DateTimeInterface;

readonly class QuestionProgressResponseDTO
{
    public function __construct(
        public string $questionId,
        public ExamQuestionProgressStatus $status,
        public ?int $totalTimeSeconds,
        public ?int $visitCount,
        public ?int $navigationCount,
        public ?string $firstViewedAt,
        public ?string $lastViewedAt,
        public ?string $firstAnsweredAt,
        public ?string $lastAnsweredAt,
        public ?int $answerChangeCount,
    ) {
    }
}
