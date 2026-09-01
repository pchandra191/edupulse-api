<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptQuestionProgressModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SaveQuestionProgressRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Action is required.')]
        #[Assert\Choice(choices: ['ENTER', 'LEAVE', 'NEXT', 'PREVIOUS'], message: 'Invalid action.')]
        public string $action,

        #[Assert\Uuid(message: 'Invalid question ID format.')]
        public ?string $questionId = null,

        #[Assert\Uuid(message: 'Invalid question ID format.')]
        public ?string $fromQuestionId = null,

        #[Assert\Uuid(message: 'Invalid question ID format.')]
        public ?string $toQuestionId = null,
    ) {
    }
}
