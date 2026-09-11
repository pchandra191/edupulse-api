<?php
declare(strict_types=1);

namespace App\Controller\ExaminationModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExaminationModule\ExamSubjectResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\ExaminationModule\ExaminationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamSubjectQuestionPaperController extends AbstractApiController
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
    #[Route('/examinations/{examinationId}/subjects/{examSubjectId}/question-paper', name: 'exam_subject_question_paper_attach', methods: ['PUT'])]
    public function attach(string $examinationId, string $examSubjectId, \Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || empty($data['questionPaperId'])) {
            return $this->unprocessableEntity('Validation failed.', ['questionPaperId' => ['Question paper ID is required.']]);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSubject = $this->examinationService->attachQuestionPaperToExamSubject(
                $this->parseUuid($examinationId),
                $this->parseUuid($examSubjectId),
                $instituteId,
                new Uuid($data['questionPaperId']),
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Question paper attached successfully.', $examSubject);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/examinations/{examinationId}/subjects/{examSubjectId}/question-paper', name: 'exam_subject_question_paper_remove', methods: ['DELETE'])]
    public function remove(string $examinationId, string $examSubjectId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSubject = $this->examinationService->removeQuestionPaperFromExamSubject(
                $this->parseUuid($examinationId),
                $this->parseUuid($examSubjectId),
                $instituteId,
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Question paper removed successfully.', $examSubject);
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
