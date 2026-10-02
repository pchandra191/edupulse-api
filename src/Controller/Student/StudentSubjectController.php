<?php
declare(strict_types=1);

namespace App\Controller\Student;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\Student\StudentSubjectService;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;

class StudentSubjectController extends AbstractApiController
{
    public function __construct(
        private StudentSubjectService $studentSubjectService,
        private SymfonySecurity $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/subjects', name: 'student_subject_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $subjects = $this->studentSubjectService->getStudentSubjects($user);

        return $this->success('Subjects retrieved successfully.', $subjects);
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }
}
