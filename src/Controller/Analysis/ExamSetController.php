<?php

declare(strict_types=1);

namespace App\Controller\Analysis;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Analysis\ExamSetCreateRequestDTO;
use App\DTO\Analysis\ExamSetResponseDTO;
use App\DTO\Analysis\ExamSetSummaryDTO;
use App\DTO\Analysis\ExamSetUpdateRequestDTO;
use App\Entity\User;
use App\Exception\AnalysisNotFoundException;
use App\Exception\AuthenticationException;
use App\Exception\AuthorizationException;
use App\Service\Analysis\ExamSetService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamSetController extends AbstractApiController
{
    public function __construct(
        private ExamSetService $examSetService,
        private SymfonySecurity $security,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/exam-sets', name: 'student_exam_set_create', methods: ['POST'])]
    public function createExamSet(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $createRequest = ExamSetCreateRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $result = $this->examSetService->createExamSet($user, $createRequest);
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create exam set', [
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to create exam set. Please try again.');
        }

        return $this->created('Exam set created successfully.', [
            'id' => $result->id,
            'academicYear' => $result->academicYear,
            'academicClass' => $result->academicClass,
            'section' => $result->section,
            'examName' => $result->examName,
            'examSubType' => $result->examSubType,
            'createdAt' => $result->createdAt,
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/exam-sets', name: 'student_exam_set_list', methods: ['GET'])]
    public function listExamSets(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $page = max(1, (int) ($request->query->get('page', 1)));
        $limit = min(100, max(1, (int) ($request->query->get('limit', 20))));

        try {
            $examSets = $this->examSetService->listExamSets($user, $page, $limit);
            $total = $this->examSetService->countExamSets($user);
            $totalPages = (int) ceil($total / $limit);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list exam sets', [
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            // Temporary: return actual error for debugging
            return $this->serverError('Unable to retrieve exam sets. Please try again. Debug: ' . $e->getMessage());
        }

        return $this->success('Exam sets retrieved successfully.', [
            'items' => array_map(fn($item) => [
                'id' => $item->id,
                'academicYear' => $item->academicYear,
                'academicClass' => $item->academicClass,
                'section' => $item->section,
                'examName' => $item->examName,
                'examSubType' => $item->examSubType,
                'createdAt' => $item->createdAt,
                'updatedAt' => $item->updatedAt,
            ], $examSets),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => count($examSets), // Approximate for pagination
                'totalPages' => $totalPages,
            ],
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/exam-sets/{examSetId}', name: 'student_exam_set_get', methods: ['GET'])]
    public function getExamSet(string $examSetId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        try {
            $result = $this->examSetService->getExamSet($user, $examSetId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get exam set', [
                'student_id' => (string) $user->getId(),
                'exam_set_id' => $examSetId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            // Temporary: return actual error for debugging
            return $this->serverError('Unable to retrieve exam set. Please try again. Debug: ' . $e->getMessage());
        }

        return $this->success('Exam set retrieved successfully.', [
            'id' => $result->id,
            'academicYearId' => $result->academicYearId,
            'academicYear' => $result->academicYear,
            'classId' => $result->classId,
            'academicClass' => $result->academicClass,
            'sectionId' => $result->sectionId,
            'section' => $result->section,
            'examId' => $result->examId,
            'examName' => $result->examName,
            'examSubTypeId' => $result->examSubTypeId,
            'examSubType' => $result->examSubType,
            'analyses' => array_map(fn($a) => [
                'analysisId' => $a->analysisId,
                'subject' => $a->subject,
                'title' => $a->title,
                'source' => $a->source,
                'status' => $a->status,
                'createdAt' => $a->createdAt,
                'updatedAt' => $a->updatedAt,
            ], $result->analyses),
            'createdAt' => $result->createdAt,
            'updatedAt' => $result->updatedAt,
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/exam-sets/{examSetId}', name: 'student_exam_set_update', methods: ['PUT'])]
    public function updateExamSet(string $examSetId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $updateRequest = \App\DTO\Analysis\ExamSetUpdateRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $result = $this->examSetService->updateExamSet($user, $examSetId, $updateRequest);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update exam set', [
                'student_id' => (string) $user->getId(),
                'exam_set_id' => $examSetId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to update exam set. Please try again.');
        }

        return $this->success('Exam set updated successfully.', [
            'id' => $result->id,
            'academicYear' => $result->academicYear,
            'academicClass' => $result->academicClass,
            'section' => $result->section,
            'examName' => $result->examName,
            'examSubType' => $result->examSubType,
            'updatedAt' => $result->updatedAt,
        ]);
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if ($user->getRole() !== \App\Enum\UserRole::Student) {
            throw AuthorizationException::create('Only students can access exam set endpoints.');
        }

        return $user;
    }
}