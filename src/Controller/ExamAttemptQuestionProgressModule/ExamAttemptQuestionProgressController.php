<?php
declare(strict_types=1);

namespace App\Controller\ExamAttemptQuestionProgressModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExamAttemptQuestionProgressModule\SaveQuestionProgressRequestDTO;
use App\DTO\ExamAttemptQuestionProgressModule\QuestionProgressListResponseDTO;
use App\Exception\AuthenticationException;
use App\Service\ExamAttemptQuestionProgressModule\ExamAttemptQuestionProgressService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamAttemptQuestionProgressController extends AbstractApiController
{
    public function __construct(
        private ExamAttemptQuestionProgressService $progressService,
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
    #[Route('/exam-attempts/{attemptId}/question-progress', name: 'exam_question_progress', methods: ['POST'])]
    public function record(Request $request, string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_attempts.view');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $progressRequest = new SaveQuestionProgressRequestDTO(
            action: (string) ($data['action'] ?? ''),
            questionId: $data['questionId'] ?? null,
            fromQuestionId: $data['fromQuestionId'] ?? null,
            toQuestionId: $data['toQuestionId'] ?? null,
        );

        $errors = $this->validator->validate($progressRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $result = $this->progressService->recordProgress(Uuid::fromString($attemptId), $progressRequest);

            return $this->success('Question progress recorded successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{attemptId}/question-progress', name: 'exam_question_progress_list', methods: ['GET'])]
    public function list(string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_attempts.view');

        try {
            $result = $this->progressService->getProgress(Uuid::fromString($attemptId));

            return $this->success('Question progress retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }
    }
}
