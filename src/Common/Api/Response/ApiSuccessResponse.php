<?php
declare(strict_types=1);

namespace App\Common\Api\Response;

use JsonSerializable;

readonly class ApiSuccessResponse implements JsonSerializable
{
    public function __construct(
        public bool $success = true,
        public ?string $message = null,
        public mixed $data = null,
        public array $meta = [],
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

        if (!empty($this->meta)) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }
}
