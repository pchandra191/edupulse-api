<?php
declare(strict_types=1);

namespace App\Common\Api\Controller;

use App\Common\Api\Response\ApiErrorResponse;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\ApiSuccessResponse;
use App\Common\Api\Response\PaginationResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class AbstractApiController
{
    public function __construct(private ApiResponseFactory $responseFactory)
    {
    }

    protected function success(string $message = '', mixed $data = null, array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->success($message, $data, $meta), JsonResponse::HTTP_OK);
    }

    protected function created(string $message = '', mixed $data = null, array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->success($message, $data, $meta), JsonResponse::HTTP_CREATED);
    }

    protected function updated(string $message = '', mixed $data = null, array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->success($message, $data, $meta), JsonResponse::HTTP_OK);
    }

    protected function deleted(string $message = '', mixed $data = null, array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->success($message, $data, $meta), JsonResponse::HTTP_OK);
    }

    protected function error(string $message, array $errors = [], array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->error($message, $errors, $meta), JsonResponse::HTTP_BAD_REQUEST);
    }

    protected function notFound(string $message = 'Resource not found.', array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->error($message, meta: $meta), JsonResponse::HTTP_NOT_FOUND);
    }

    protected function accessDenied(string $message = 'Access denied.', array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->error($message, meta: $meta), JsonResponse::HTTP_FORBIDDEN);
    }

    protected function unprocessableEntity(string $message = '', array $errors = [], array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->error($message, $errors, $meta), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    protected function unauthorized(string $message = 'Authentication required.', array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->error($message, meta: $meta), JsonResponse::HTTP_UNAUTHORIZED);
    }

    protected function paginated(string $message, mixed $data, PaginationResponse $pagination, array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->paginated($message, $data, $pagination, $meta), JsonResponse::HTTP_OK);
    }

    protected function serverError(string $message = 'Internal server error.', array $meta = []): JsonResponse
    {
        return new JsonResponse($this->responseFactory->error($message, meta: $meta), JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
