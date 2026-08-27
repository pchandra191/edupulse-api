<?php
declare(strict_types=1);

namespace App\Common\Api\Response;

readonly class ApiResponseFactory
{
    public function __construct(private ?string $apiVersion = null)
    {
    }

    public function success(string $message = '', mixed $data = null, array $meta = []): ApiSuccessResponse
    {
        return new ApiSuccessResponse(
            message: $message,
            data: $data,
            meta: $this->buildMeta($meta),
        );
    }

    public function error(string $message, array $errors = [], array $meta = [], ?string $code = null): ApiErrorResponse
    {
        return new ApiErrorResponse(
            message: $message,
            errors: $errors,
            meta: $this->buildMeta($meta),
            code: $code,
        );
    }

    public function paginated(string $message, mixed $data, PaginationResponse $pagination, array $meta = []): ApiSuccessResponse
    {
        return new ApiSuccessResponse(
            message: $message,
            data: [
                'items' => $data,
                'pagination' => $pagination,
            ],
            meta: $this->buildMeta($meta),
        );
    }

    private function buildMeta(array $meta): array
    {
        if ($this->apiVersion !== null) {
            $meta['version'] = $this->apiVersion;
        }

        return $meta;
    }
}
