<?php
declare(strict_types=1);

namespace App\Controller\ExamAnswerModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExamAnswerModule\ExamAnswerResponseDTO;
use App\DTO\ExamAnswerModule\ManualEvaluateAnswerRequestDTO;
use App\DTO\ExamAnswerModule\OrganizerExamAnswerResponseDTO;
use App\DTO\ExamAnswerModule\SaveExamAnswerRequestDTO;
use App\Exception\AuthenticationException;
use App\Service\ExamAnswerModule\ExamAnswerService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamAnswerController extends AbstractApiController
{
    public function __construct(
        private ExamAnswerService $examAnswerService,
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
    #[Route('/exam-attempts/{attemptId}/answers', name: 'exam_answer_save', methods: ['POST'])]
    public function save(Request $request, string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_answers.create');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $saveRequest = new SaveExamAnswerRequestDTO(
            questionId: (string) ($data['questionId'] ?? ''),
            selectedOptionId: $data['selectedOptionId'] ?? null,
            selectedOptionIds: $data['selectedOptionIds'] ?? null,
            answerText: $data['answerText'] ?? null,
        );

        $errors = $this->validator->validate($saveRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $answer = $this->examAnswerService->saveAnswer(Uuid::fromString($attemptId), $saveRequest);

            return $this->success('Answer saved successfully.', $answer);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{attemptId}/answers', name: 'exam_answer_list', methods: ['GET'])]
    public function list(string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_answers.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $answers = $this->examAnswerService->getAnswers(Uuid::fromString($attemptId), $instituteId);

            if ($this->isOrganizer()) {
                $items = array_map(
                    fn (OrganizerExamAnswerResponseDTO $answer) => $answer->toArray(),
                    $answers,
                );

                return $this->success('Exam answers retrieved successfully.', ['items' => $items]);
            }

            return $this->success('Answers retrieved successfully.', ['answers' => $answers]);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/exam-attempts/{attemptId}/answers/{answerId}', name: 'exam_answer_evaluate', methods: ['PUT'])]
    public function evaluate(Request $request, string $attemptId, string $answerId): JsonResponse
    {
        $this->requirePermission('exam_answers.edit');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $evaluateRequest = new ManualEvaluateAnswerRequestDTO(
            marksObtained: isset($data['marksObtained']) ? (int) $data['marksObtained'] : null,
            answerText: $data['answerText'] ?? null,
        );

        $errors = $this->validator->validate($evaluateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $answer = $this->examAnswerService->evaluateDescriptiveAnswer(
                Uuid::fromString($attemptId),
                Uuid::fromString($answerId),
                $evaluateRequest->marksObtained,
                $evaluateRequest->answerText,
            );

            return $this->success('Exam answer evaluated successfully.', $answer->toArray());
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    private function getCurrentInstituteId(): ?Uuid
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        $institute = $user->getInstitute();

        if (!$institute) {
            throw new AuthenticationException('Institute not found.');
        }

        return $institute->getId();
    }

    private function isOrganizer(): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            return false;
        }

        return in_array('ROLE_INSTITUTE', $user->getRoles(), true)
            || in_array('ROLE_TEACHER', $user->getRoles(), true)
            || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }
}
