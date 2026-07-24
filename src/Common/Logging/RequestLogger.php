<?php
declare(strict_types=1);

namespace App\Common\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\SecurityBundle\Security;

readonly class RequestLogger
{
    public function __construct(
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    public function log(string $method, string $url, int $statusCode, float $executionTime): void
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return;
        }

        $userId = null;

        if ($this->security->getUser() !== null) {
            $user = $this->security->getUser();
            $userId = method_exists($user, 'getId') ? (string) $user->getId() : null;
        }

        $this->logger->info('API request', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'user_id' => $userId,
            'ip' => $request->getClientIp(),
            'correlation_id' => $request->headers->get('X-Correlation-ID'),
        ]);
    }
}
