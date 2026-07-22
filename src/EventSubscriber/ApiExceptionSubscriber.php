<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\AuthenticationException;
use App\Exception\ExpiredResetTokenException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidRefreshTokenException;
use App\Exception\PasswordPolicyException;
use App\Exception\UserDisabledException;
use App\Exception\UserNotVerifiedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof InvalidCredentialsException) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [],
            ], Response::HTTP_UNAUTHORIZED));
            return;
        }

        if ($exception instanceof InvalidRefreshTokenException) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [],
            ], Response::HTTP_UNAUTHORIZED));
            return;
        }

        if ($exception instanceof UserDisabledException) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [],
            ], Response::HTTP_FORBIDDEN));
            return;
        }

        if ($exception instanceof UserNotVerifiedException) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [],
            ], Response::HTTP_FORBIDDEN));
            return;
        }

        if ($exception instanceof ExpiredResetTokenException) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST));
            return;
        }

        if ($exception instanceof PasswordPolicyException) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST));
            return;
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }
}
