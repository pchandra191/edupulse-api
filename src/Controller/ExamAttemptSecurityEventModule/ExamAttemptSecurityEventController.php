<?php
declare(strict_types=1);

namespace App\Controller\ExamAttemptSecurityEventModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExamAttemptSecurityEventModule\ExamAttemptSecurityEventRequestDTO;
use App\DTO\ExamAttemptSecurityEventModule\ExamAttemptSecurityEventResponseDTO;
use App\Exception\AuthenticationException;
use App\Service\ExamAttemptSecurityEventModule\ExamAttemptSecurityEventService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamAttemptSecurityEventController extends AbstractApiController
{
    public function __construct(
        private ExamAttemptSecurityEventService $securityEventService,
        private RolePermissionService $rolePermissionService,
        private ValidatorInterface $validator,
        private Security $security,
        private ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    private function normalizePermissionCode(string $code): string
    {
        return str_replace('-', '_', mb_strtolower($code));
    }

    private function hasPermission(string $permissionCode): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            return false;
        }

        return true;
    }

    private function requirePermission(string $permissionCode): void
    {
        if (!$this->hasPermission($permissionCode)) {
            throw new AuthenticationException('Access denied.');
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/security-events', name: 'exam_attempt_security_event_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requirePermission('exam_attempts.view');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $eventType = null;

        if (isset($data['eventType'])) {
            try {
                $eventType = \App\Enum\ExamSecurityEventType::from($data['eventType']);
            } catch (\ValueError $e) {
                return $this->unprocessableEntity('Invalid event type.', ['eventType' => ['Invalid event type.']]);
            }
        }

        $occurredAt = null;

        if (isset($data['occurredAt'])) {
            try {
                $occurredAt = new \DateTimeImmutable($data['occurredAt']);
            } catch (\Exception $e) {
                return $this->unprocessableEntity('Invalid occurred at timestamp.', ['occurredAt' => ['Invalid occurred at timestamp.']]);
            }
        }

        $dto = new ExamAttemptSecurityEventRequestDTO(
            attemptId: (string) ($data['attemptId'] ?? ''),
            examSessionId: (string) ($data['examSessionId'] ?? ''),
            count: (int) ($data['count'] ?? 0),
            eventType: $eventType ?? \App\Enum\ExamSecurityEventType::TabSwitch,
            occurredAt: $occurredAt ?? new \DateTimeImmutable(),
        );

        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $response = $this->securityEventService->recordSecurityEvent($dto);

            return $this->created('Security event recorded successfully.', $response);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{attemptId}/security-events', name: 'exam_attempt_security_events_list', methods: ['GET'])]
    public function list(string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_attempts.view');

        try {
            $events = $this->securityEventService->getSecurityEventsByAttempt(Uuid::fromString($attemptId));

            return $this->success('Security events retrieved successfully.', ['items' => $events]);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }
    }
}
