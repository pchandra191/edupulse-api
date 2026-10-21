<?php
declare(strict_types=1);

namespace App\DTO\StudyPlan;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateStudyTaskRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'task.title.required')]
        #[Assert\Length(max: 255)]
        public string $title,

        #[Assert\Length(max: 2000)]
        public ?string $description = null,

        #[Assert\Length(max: 180)]
        public ?string $subject = null,

        #[Assert\Length(max: 255)]
        public ?string $topic = null,

        #[Assert\Choice(choices: ['low', 'medium', 'high'], message: 'task.priority.invalid')]
        public string $priority = 'medium',

        #[Assert\PositiveOrZero(message: 'task.estimatedMinutes.invalid')]
        public ?int $estimatedMinutes = null,

        #[Assert\Type(\DateTimeImmutable::class, message: 'task.dueDate.invalid')]
        public ?\DateTimeImmutable $dueDate = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? '',
            description: $data['description'] ?? null,
            subject: $data['subject'] ?? null,
            topic: $data['topic'] ?? null,
            priority: $data['priority'] ?? 'medium',
            estimatedMinutes: isset($data['estimatedMinutes']) ? (int) $data['estimatedMinutes'] : null,
            dueDate: self::parseDate($data['dueDate'] ?? null),
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
