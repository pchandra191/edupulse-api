<?php
declare(strict_types=1);

namespace App\Controller\Student;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\StudyPlan\CreateStudyTaskRequestDTO;
use App\DTO\StudyPlan\UpdateStudyTaskRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\StudyPlan\StudyPlanService;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class StudyPlanController extends AbstractApiController
{
    public function __construct(
        private StudyPlanService $studyPlanService,
        private SymfonySecurity $security,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/study-plan', name: 'student_study_plan_get', methods: ['GET'])]
    public function getPlan(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        }

        return $this->success('Study plan retrieved successfully.', $this->studyPlanService->getPlan($user));
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/study-plan/tasks', name: 'student_study_plan_task_create', methods: ['POST'])]
    public function createTask(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $dto = CreateStudyTaskRequestDTO::fromArray($data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $formatted = [];
            foreach ($errors as $error) {
                $formatted[$error->getPropertyPath()][] = $error->getMessage();
            }
            return $this->unprocessableEntity('Validation failed.', $formatted);
        }

        try {
            $task = $this->studyPlanService->createManualTask(
                $user,
                $dto->title,
                $dto->description,
                $dto->subject,
                $dto->topic,
                $dto->priority,
                $dto->estimatedMinutes,
                $dto->dueDate,
            );
        } catch (\Throwable) {
            return $this->serverError('Unable to create study task. Please try again.');
        }

        return $this->created('Study task created successfully.', $this->studyPlanService->taskToDto($task));
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/study-plan/tasks/{taskId}', name: 'student_study_plan_task_update', methods: ['PUT'])]
    public function updateTask(string $taskId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $dto = UpdateStudyTaskRequestDTO::fromArray($data);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $formatted = [];
            foreach ($errors as $error) {
                $formatted[$error->getPropertyPath()][] = $error->getMessage();
            }
            return $this->unprocessableEntity('Validation failed.', $formatted);
        }

        try {
            $task = $this->studyPlanService->findTaskForStudent($user, $taskId);
            $this->studyPlanService->updateTask($user, $task, $this->changesFromDto($dto));
        } catch (AuthenticationException $e) {
            return $this->notFound('Study task not found.');
        } catch (\Throwable) {
            return $this->serverError('Unable to update study task. Please try again.');
        }

        return $this->updated('Study task updated successfully.', $this->studyPlanService->taskToDto($task));
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/study-plan/tasks/{taskId}', name: 'student_study_plan_task_delete', methods: ['DELETE'])]
    public function deleteTask(string $taskId): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $task = $this->studyPlanService->findTaskForStudent($user, $taskId);
            $this->studyPlanService->deleteTask($user, $task);
        } catch (AuthenticationException $e) {
            return $this->notFound('Study task not found.');
        } catch (\Throwable) {
            return $this->serverError('Unable to delete study task. Please try again.');
        }

        return $this->deleted('Study task deleted successfully.');
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function changesFromDto(UpdateStudyTaskRequestDTO $dto): array
    {
        $changes = [];
        $fields = ['title', 'description', 'subject', 'topic', 'priority', 'estimatedMinutes', 'dueDate', 'isCompleted', 'isAiRecommended'];
        foreach ($fields as $field) {
            $value = $dto->$field;
            if ($value !== null) {
                $changes[$field] = $value;
            }
        }
        return $changes;
    }
}
