<?php

declare(strict_types=1);

namespace App\Controller\Student;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\StudentIntelligence\AcademicIntelligenceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;

class StudentDashboardController extends AbstractApiController
{
    public function __construct(
        private AcademicIntelligenceService $academicIntelligenceService,
        private SymfonySecurity $security,
        private LoggerInterface $logger,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/dashboard', name: 'student_dashboard_get', methods: ['GET'])]
    public function getDashboard(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        }

        try {
            $dashboard = $this->academicIntelligenceService->getDashboard((string) $user->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to build student dashboard', [
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve dashboard. Please try again.');
        }

        return $this->success('Student dashboard retrieved successfully.', $this->toArray($dashboard));
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function toArray(object $dto): array
    {
        $result = [];
        foreach ($dto as $key => $value) {
            $result[$key] = $this->serializeValue($value);
        }
        return $result;
    }

    private function serializeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn($item) => $this->serializeValue($item), $value);
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_object($value)) {
            return $this->toArray($value);
        }
        return $value;
    }
}
