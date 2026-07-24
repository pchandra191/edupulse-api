<?php
declare(strict_types=1);

namespace App\Common\Api\Response;

use JsonSerializable;

readonly class ApiErrorResponse implements JsonSerializable
{
    public function __construct(
        public string $message,
        public bool $success = false,
        public array $errors = [],
        public array $meta = [],
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

        if (!empty($this->meta)) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }
}
