<?php
declare(strict_types=1);

namespace App\DTO\StudyPlan;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateStudyTaskRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $title = null,

        #[Assert\Length(max: 2000)]
        public ?string $description = null,

        #[Assert\Length(max: 180)]
        public ?string $subject = null,

        #[Assert\Length(max: 255)]
        public ?string $topic = null,

        #[Assert\Choice(choices: ['low', 'medium', 'high'], message: 'task.priority.invalid')]
        public ?string $priority = null,

        #[Assert\PositiveOrZero(message: 'task.estimatedMinutes.invalid')]
        public ?int $estimatedMinutes = null,

        #[Assert\Type(\DateTimeImmutable::class, message: 'task.dueDate.invalid')]
        public ?\DateTimeImmutable $dueDate = null,

        public ?bool $isCompleted = null,

        public ?bool $isAiRecommended = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: array_key_exists('title', $data) ? $data['title'] : null,
            description: array_key_exists('description', $data) ? $data['description'] : null,
            subject: array_key_exists('subject', $data) ? $data['subject'] : null,
            topic: array_key_exists('topic', $data) ? $data['topic'] : null,
            priority: array_key_exists('priority', $data) ? $data['priority'] : null,
            estimatedMinutes: array_key_exists('estimatedMinutes', $data) ? (int) $data['estimatedMinutes'] : null,
            dueDate: array_key_exists('dueDate', $data) ? self::parseDate($data['dueDate']) : null,
            isCompleted: array_key_exists('isCompleted', $data) ? (bool) $data['isCompleted'] : null,
            isAiRecommended: array_key_exists('isAiRecommended', $data) ? (bool) $data['isAiRecommended'] : null,
        );
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
