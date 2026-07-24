<?php
declare(strict_types=1);

namespace App\DTO\Response;

use JsonSerializable;

readonly class SuccessResponse implements JsonSerializable
{
    public function __construct(
        public bool $success = true,
        public ?string $message = null,
        public mixed $data = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = ['success' => $this->success];

        if ($this->message !== null && $this->message !== '') {
            $result['message'] = $this->message;
        }

        if ($this->data !== null) {
            $result['data'] = $this->data;
        }

        return $result;
    }
}
