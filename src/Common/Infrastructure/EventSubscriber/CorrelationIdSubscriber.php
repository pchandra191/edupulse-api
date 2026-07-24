<?php
declare(strict_types=1);

namespace App\Common\Infrastructure\EventSubscriber;

use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Logging\RequestLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

readonly class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public function __construct(private ApiResponseFactory $responseFactory)
    {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $correlationId = $request->headers->get('X-Correlation-ID');

        if (!$correlationId) {
            $correlationId = Uuid::v4()->toRfc4122();
        }

        $response->headers->set('X-Correlation-ID', $correlationId);

        $request->attributes->set('correlationId', $correlationId);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}
