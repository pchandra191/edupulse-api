<?php
declare(strict_types=1);

namespace App\Controller\ExaminationModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExaminationModule\ExamSubjectResponseDTO;
use App\DTO\ExaminationModule\UpdateExamSubjectRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\ExaminationModule\ExaminationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamSubjectController extends AbstractApiController
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
    #[Route('/examinations/{examinationId}/subjects/{examSubjectId}', name: 'exam_subject_get', methods: ['GET'])]
    public function get(string $examinationId, string $examSubjectId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSubject = $this->examinationService->getExamSubject(
                $this->parseUuid($examinationId),
                $this->parseUuid($examSubjectId),
                $instituteId,
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Exam subject retrieved successfully.', $examSubject);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{examinationId}/subjects/{examSubjectId}', name: 'exam_subject_update', methods: ['PUT'])]
    public function update(string $examinationId, string $examSubjectId, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateExamSubjectRequestDTO(
            subjectId: $data['subjectId'] ?? null,
            sectionId: $data['sectionId'] ?? null,
            examDate: $data['examDate'] ?? null,
            startTime: $data['startTime'] ?? null,
            endTime: $data['endTime'] ?? null,
            duration: isset($data['duration']) ? (int) $data['duration'] : null,
            maxMarks: isset($data['maxMarks']) ? (int) $data['maxMarks'] : null,
            passingMarks: isset($data['passingMarks']) ? (int) $data['passingMarks'] : null,
            status: isset($data['status']) ? strtoupper((string) $data['status']) : null,
            remarks: $data['remarks'] ?? null,
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
            $examSubject = $this->examinationService->updateExamSubject(
                $this->parseUuid($examinationId),
                $this->parseUuid($examSubjectId),
                $instituteId,
                $updateRequest,
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Exam subject updated successfully.', $examSubject);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{examinationId}/subjects/{examSubjectId}', name: 'exam_subject_delete', methods: ['DELETE'])]
    public function delete(string $examinationId, string $examSubjectId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->examinationService->deleteExamSubject(
                $this->parseUuid($examinationId),
                $this->parseUuid($examSubjectId),
                $instituteId,
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Exam subject archived successfully.');
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
            throw new AuthenticationException('Resource not found.', previous: $e);
        }
    }
}
