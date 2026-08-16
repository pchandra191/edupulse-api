<?php
declare(strict_types=1);

namespace App\Controller\ExaminationModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExaminationModule\CreateExaminationRequestDTO;
use App\DTO\ExaminationModule\ExaminationFilterRequest;
use App\DTO\ExaminationModule\ExaminationResponseDTO;
use App\DTO\ExaminationModule\UpdateExaminationRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\ExaminationModule\ExaminationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExaminationController extends AbstractApiController
{
    public function __construct(
        private ExaminationService $examinationService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations', name: 'examination_list', methods: ['GET'])]
    public function list(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();

        $filter = new ExaminationFilterRequest(
            search: $request->query->get('search'),
            academicYearId: $request->query->get('academicYearId'),
            academicClassId: $request->query->get('academicClassId'),
            status: $request->query->get('status'),
            startDateFrom: $request->query->get('startDateFrom'),
            startDateTo: $request->query->get('startDateTo'),
            endDateFrom: $request->query->get('endDateFrom'),
            endDateTo: $request->query->get('endDateTo'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->examinationService->getExaminations($instituteId, $filter);

        return $this->success('Examinations retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations', name: 'examination_create', methods: ['POST'])]
    public function create(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateExaminationRequestDTO(
            instituteId: (string) ($data['instituteId'] ?? ''),
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            academicClassId: (string) ($data['academicClassId'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            description: $data['description'] ?? null,
            startDate: (string) ($data['startDate'] ?? ''),
            endDate: (string) ($data['endDate'] ?? ''),
            status: isset($data['status']) ? strtoupper((string) $data['status']) : 'DRAFT',
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

            $examination = $this->examinationService->createExamination($instituteId, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Examination created successfully.', $examination);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{id}', name: 'examination_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $examination = $this->examinationService->getExamination($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Examination retrieved successfully.', $examination);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{id}', name: 'examination_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateExaminationRequestDTO(
            name: $data['name'] ?? null,
            code: $data['code'] ?? null,
            description: $data['description'] ?? null,
            startDate: $data['startDate'] ?? null,
            endDate: $data['endDate'] ?? null,
            status: isset($data['status']) ? strtoupper((string) $data['status']) : null,
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
            $examination = $this->examinationService->updateExamination($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Examination updated successfully.', $examination);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{id}', name: 'examination_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->examinationService->deleteExamination($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Examination archived successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{id}/subjects', name: 'examination_subject_list', methods: ['GET'])]
    public function listSubjects(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->examinationService->getExamSubjects(
                $this->parseUuid($id),
                $instituteId,
                $request->query->get('search'),
                $request->query->get('status'),
                max(1, (int) $request->query->get('page', 1)),
                max(1, min(100, (int) $request->query->get('limit', 10))),
                $request->query->get('sortBy', 'examDate'),
                $request->query->get('sortOrder', 'ASC'),
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Exam subjects retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{id}/subjects', name: 'examination_subject_create', methods: ['POST'])]
    public function createSubject(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new \App\DTO\ExaminationModule\CreateExamSubjectRequestDTO(
            subjectId: (string) ($data['subjectId'] ?? ''),
            sectionId: $data['sectionId'] ?? null,
            examDate: (string) ($data['examDate'] ?? ''),
            startTime: $data['startTime'] ?? null,
            endTime: $data['endTime'] ?? null,
            duration: isset($data['duration']) ? (int) $data['duration'] : null,
            maxMarks: isset($data['maxMarks']) ? (int) $data['maxMarks'] : null,
            passingMarks: isset($data['passingMarks']) ? (int) $data['passingMarks'] : null,
            status: isset($data['status']) ? strtoupper((string) $data['status']) : 'DRAFT',
            remarks: $data['remarks'] ?? null,
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
            $examSubject = $this->examinationService->createExamSubject($this->parseUuid($id), $createRequest, $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Exam subject created successfully.', $examSubject);
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
            throw new AuthenticationException('Examination not found.', previous: $e);
        }
    }
}
