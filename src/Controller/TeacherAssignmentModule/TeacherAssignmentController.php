<?php
declare(strict_types=1);

namespace App\Controller\TeacherAssignmentModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\TeacherAssignmentModule\CreateTeacherAssignmentRequestDTO;
use App\DTO\TeacherAssignmentModule\TeacherAssignmentFilterRequest;
use App\DTO\TeacherAssignmentModule\TeacherAssignmentResponseDTO;
use App\DTO\TeacherAssignmentModule\UpdateTeacherAssignmentRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\TeacherAssignmentModule\TeacherAssignmentService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TeacherAssignmentController extends AbstractApiController
{
    public function __construct(
        private TeacherAssignmentService $teacherAssignmentService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/teacher-assignments', name: 'teacher_assignment_list', methods: ['GET'])]
    public function list(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();

        $filter = new TeacherAssignmentFilterRequest(
            teacherId: $request->query->get('teacherId'),
            academicYearId: $request->query->get('academicYearId'),
            classId: $request->query->get('classId'),
            sectionId: $request->query->get('sectionId'),
            subjectId: $request->query->get('subjectId'),
            search: $request->query->get('search'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->teacherAssignmentService->getTeacherAssignments($instituteId, $filter);

        return $this->success('Teacher assignments retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/teacher-assignments', name: 'teacher_assignment_create', methods: ['POST'])]
    public function create(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateTeacherAssignmentRequestDTO(
            teacherId: (string) ($data['teacherId'] ?? ''),
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            academicClassId: (string) ($data['academicClassId'] ?? ''),
            sectionId: $data['sectionId'] ?? null,
            subjectId: $data['subjectId'] ?? null,
            assignmentType: (string) ($data['assignmentType'] ?? 'SUBJECT_TEACHER'),
            remarks: $data['remarks'] ?? null,
            status: (string) ($data['status'] ?? 'ACTIVE'),
        );

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $assignment = $this->teacherAssignmentService->createTeacherAssignment($instituteId, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Teacher assignment created successfully.', $assignment);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/teacher-assignments/{id}', name: 'teacher_assignment_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $assignment = $this->teacherAssignmentService->getTeacherAssignment($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Teacher assignment retrieved successfully.', $assignment);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/teacher-assignments/{id}', name: 'teacher_assignment_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateTeacherAssignmentRequestDTO(
            teacherId: $data['teacherId'] ?? null,
            academicYearId: $data['academicYearId'] ?? null,
            academicClassId: $data['academicClassId'] ?? null,
            sectionId: $data['sectionId'] ?? null,
            subjectId: $data['subjectId'] ?? null,
            assignmentType: $data['assignmentType'] ?? null,
            remarks: $data['remarks'] ?? null,
            status: $data['status'] ?? null,
        );

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $assignment = $this->teacherAssignmentService->updateTeacherAssignment($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Teacher assignment updated successfully.', $assignment);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/teacher-assignments/{id}', name: 'teacher_assignment_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->teacherAssignmentService->deleteTeacherAssignment($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Teacher assignment archived successfully.');
    }

    private function getCurrentInstituteId(): ?Uuid
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
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

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('Teacher assignment not found.', previous: $e);
        }
    }
}
