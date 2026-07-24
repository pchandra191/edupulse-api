<?php
declare(strict_types=1);

namespace App\Common\Infrastructure\EventSubscriber;

use App\Common\Logging\RequestLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

readonly class RequestLoggingSubscriber implements EventSubscriberInterface
{
    public function __construct(private RequestLogger $requestLogger)
    {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $startTime = $request->attributes->get('_start_time');

        if ($startTime !== null) {
            $executionTime = microtime(true) - (float) $startTime;
        } else {
            $executionTime = 0.0;
        }

        $this->requestLogger->log(
            $request->getMethod(),
            $request->getPathInfo(),
            $response->getStatusCode(),
            $executionTime
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onKernelTerminate',
        ];
    }
}
