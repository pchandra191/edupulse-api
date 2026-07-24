<?php
declare(strict_types=1);

namespace App\DTO\Response;

use JsonSerializable;

readonly class ErrorResponse implements JsonSerializable
{
    public function __construct(
        public string $message,
        public bool $success = false,
        public array $errors = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if (!empty($this->errors)) {
            $result['errors'] = $this->errors;
        }

        return $result;
    }
}
