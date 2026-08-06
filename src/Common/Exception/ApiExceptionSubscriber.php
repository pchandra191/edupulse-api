<?php
declare(strict_types=1);

namespace App\Common\Exception;

use App\Common\Api\Response\ApiErrorResponse;
use App\Exception\AuthenticationException;
use App\Exception\ExpiredResetTokenException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidRefreshTokenException;
use App\Exception\InstituteNotActiveException;
use App\Exception\PasswordPolicyException;
use App\Exception\UserDisabledException;
use App\Exception\UserNotVerifiedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException as SecurityAuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $response = $this->buildResponse($exception);

        if ($response instanceof JsonResponse) {
            $event->setResponse($response);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    private function buildResponse(\Throwable $exception): ?JsonResponse
    {
        if ($exception instanceof ValidationFailedException) {
            return $this->handleValidation($exception);
        }

        if ($exception instanceof InvalidCredentialsException) {
            return $this->json(401, 'Invalid credentials.');
        }

        if ($exception instanceof InvalidRefreshTokenException) {
            return $this->json(401, 'Invalid or expired refresh token.');
        }

        if ($exception instanceof UserNotVerifiedException) {
            return $this->json(403, $exception->getMessage());
        }

        if ($exception instanceof UserDisabledException) {
            return $this->json(403, $exception->getMessage());
        }

        if ($exception instanceof InstituteNotActiveException) {
            return $this->json(403, $exception->getMessage());
        }

        if ($exception instanceof AuthenticationException) {
            return $this->json(404, $exception->getMessage());
        }

        if ($exception instanceof EntityNotFoundException) {
            return $this->json(404, 'Resource not found.');
        }

        if ($exception instanceof AccessDeniedHttpException) {
            return $this->json(403, 'Access denied.');
        }

        if ($exception instanceof AccessDeniedException) {
            return $this->json(401, 'Authentication required.');
        }

        if ($exception instanceof SecurityAuthenticationException) {
            return $this->json(401, 'Authentication required.');
        }

        if ($exception instanceof BusinessException) {
            return $this->json(400, $exception->getMessage());
        }

        if ($exception instanceof ExpiredResetTokenException) {
            return $this->json(400, $exception->getMessage());
        }

        if ($exception instanceof PasswordPolicyException) {
            return $this->json(400, $exception->getMessage());
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->json($exception->getStatusCode(), $exception->getMessage() ?: 'An error occurred.');
        }

        $this->logger->error('Unhandled exception.', [
            'message' => $exception->getMessage(),
            'class' => $exception::class,
        ]);

        $message = 'Internal server error.';

        if ($_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? false) {
            $message = $exception->getMessage();
        }

        return $this->json(500, $message);
    }

    private function handleValidation(ValidationFailedException $exception): JsonResponse
    {
        $errors = [];

        foreach ($exception->getViolations() as $violation) {
            $field = $violation instanceof ConstraintViolation
                ? $violation->getPropertyPath()
                : (string) $violation;

            $errors[$field][] = $violation->getMessage();
        }

        return $this->json(400, 'Validation failed.', $errors);
    }

    private function json(int $statusCode, string $message, array $errors = []): JsonResponse
    {
        return new JsonResponse(
            (new ApiErrorResponse(
                message: $message,
                errors: $errors,
            ))->jsonSerialize(),
            $statusCode
        );
    }
}
