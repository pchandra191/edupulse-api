<?php
declare(strict_types=1);

namespace App\Controller\StudentEnrollmentModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\StudentEnrollmentModule\CreateEnrollmentSectionRequestDTO;
use App\DTO\StudentEnrollmentModule\CreateStudentEnrollmentRequestDTO;
use App\DTO\StudentEnrollmentModule\StudentEnrollmentFilterRequest;
use App\DTO\StudentEnrollmentModule\StudentEnrollmentResponseDTO;
use App\DTO\StudentEnrollmentModule\StudentEnrollmentStudentsFilterRequest;
use App\DTO\Response\StudentEnrollmentListResponse;
use App\DTO\StudentEnrollmentModule\UpdateEnrollmentSectionRequestDTO;
use App\DTO\StudentEnrollmentModule\UpdateStudentEnrollmentRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\Role\RolePermissionService;
use App\Service\StudentEnrollmentModule\StudentEnrollmentService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class StudentEnrollmentController extends AbstractApiController
{
    public function __construct(
        private StudentEnrollmentService $studentEnrollmentService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments', name: 'student_enrollment_list', methods: ['GET'])]
    public function list(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();

        $filter = new StudentEnrollmentFilterRequest(
            search: $request->query->get('search'),
            studentId: $request->query->get('studentId'),
            academicYearId: $request->query->get('academicYearId'),
            classId: $request->query->get('classId'),
            sectionId: $request->query->get('sectionId'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->studentEnrollmentService->getStudentEnrollments($instituteId, $filter);

        return $this->success('Student enrollments retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/students', name: 'student_enrollment_students_list', methods: ['GET'])]
    public function studentsList(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();

        $filter = new StudentEnrollmentStudentsFilterRequest(
            academicYearId: $request->query->get('academicYearId', ''),
            academicClassId: $request->query->get('academicClassId', ''),
            sectionId: $request->query->get('sectionId'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
        );

        $errors = $this->validator->validate($filter);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        $result = $this->studentEnrollmentService->getStudentEnrollmentStudents($instituteId, $filter);

        return $this->success('Student enrollments retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments', name: 'student_enrollment_create', methods: ['POST'])]
    public function create(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateStudentEnrollmentRequestDTO(
            studentId: (string) ($data['studentId'] ?? ''),
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            academicClassId: (string) ($data['academicClassId'] ?? ''),
            rollNumber: $data['rollNumber'] ?? null,
            admissionNumber: (string) ($data['admissionNumber'] ?? ''),
            admissionDate: (string) ($data['admissionDate'] ?? ''),
            remarks: $data['remarks'] ?? null,
            isCurrent: (bool) ($data['isCurrent'] ?? false),
            status: (string) ($data['status'] ?? 'ACTIVE'),
            instituteId: $data['instituteId'] ?? null,
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

            if ($instituteId === null) {
                if (!$createRequest->instituteId) {
                    return $this->unprocessableEntity('Validation failed.', ['instituteId' => ['Institute ID is required.']]);
                }

                try {
                    $instituteId = new Uuid($createRequest->instituteId);
                } catch (\InvalidArgumentException $e) {
                    return $this->unprocessableEntity('Validation failed.', ['instituteId' => ['Invalid UUID format.']]);
                }
            }

            $enrollment = $this->studentEnrollmentService->createStudentEnrollment($instituteId, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Student enrollment created successfully.', $enrollment);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}', name: 'student_enrollment_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $enrollment = $this->studentEnrollmentService->getStudentEnrollment($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Student enrollment retrieved successfully.', $enrollment);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}', name: 'student_enrollment_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateStudentEnrollmentRequestDTO(
            rollNumber: $data['rollNumber'] ?? null,
            admissionNumber: $data['admissionNumber'] ?? null,
            admissionDate: $data['admissionDate'] ?? null,
            remarks: $data['remarks'] ?? null,
            isCurrent: isset($data['isCurrent']) ? (bool) $data['isCurrent'] : null,
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
            $enrollment = $this->studentEnrollmentService->updateStudentEnrollment($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Student enrollment updated successfully.', $enrollment);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}', name: 'student_enrollment_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->studentEnrollmentService->deleteStudentEnrollment($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->deleted('Student enrollment archived successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}/sections', name: 'student_enrollment_section_list', methods: ['GET'])]
    public function listSections(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $sections = $this->studentEnrollmentService->getEnrollmentSections($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Enrollment sections retrieved successfully.', $sections);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}/sections', name: 'student_enrollment_section_add', methods: ['POST'])]
    public function addSection(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateEnrollmentSectionRequestDTO(
            sectionId: (string) ($data['sectionId'] ?? ''),
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
            $this->studentEnrollmentService->addEnrollmentSection($this->parseUuid($id), $createRequest, $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Section added to enrollment successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}/sections/{sectionId}', name: 'student_enrollment_section_remove', methods: ['DELETE'])]
    public function removeSection(string $id, string $sectionId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->studentEnrollmentService->removeEnrollmentSection($this->parseUuid($id), $this->parseUuid($sectionId), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Section removed from enrollment successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/student-enrollments/{id}/sections/{sectionId}', name: 'student_enrollment_section_update', methods: ['PUT'])]
    public function updateSectionStatus(string $id, string $sectionId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateEnrollmentSectionRequestDTO(
            status: (string) ($data['status'] ?? ''),
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
            $this->studentEnrollmentService->updateEnrollmentSectionStatus($this->parseUuid($id), $this->parseUuid($sectionId), $updateRequest->status, $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Section status updated successfully.');
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
            throw new AuthenticationException('Not found.', previous: $e);
        }
    }
}
