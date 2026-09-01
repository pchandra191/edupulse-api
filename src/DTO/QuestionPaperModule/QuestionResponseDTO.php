<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

readonly class QuestionResponseDTO
{
    /**
     * @param list<QuestionOptionResponseDTO> $options
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $questionText,
        public int $marks,
        public int $displayOrder,
        public ?string $image,
        public ?string $imageUrl,
        public ?string $imageSource,
        public ?string $referenceAnswer,
        public array $options,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
