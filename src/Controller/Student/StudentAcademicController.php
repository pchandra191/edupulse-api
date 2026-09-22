<?php
declare(strict_types=1);

namespace App\Controller\Student;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Student\UpdateAcademicDetailsRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\Student\StudentAcademicService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class StudentAcademicController extends AbstractApiController
{
    public function __construct(
        private StudentAcademicService $academicService,
        private SymfonySecurity $security,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/academic-details', name: 'student_academic_details_get', methods: ['GET'])]
    public function getAcademicDetails(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            if ($user->getInstitute() !== null) {
                return $this->error('Academic details for institute students are managed through enrollment.', code: 'ENROLLMENT_MANAGED');
            }
            $details = $this->academicService->getAcademicDetails($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Academic details retrieved successfully.', $details);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/academic-details', name: 'student_academic_details_update', methods: ['PUT'])]
    public function updateAcademicDetails(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
            if ($user->getInstitute() !== null) {
                return $this->error('Academic details for institute students are managed through enrollment.', code: 'ENROLLMENT_MANAGED');
            }
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateAcademicDetailsRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $details = $this->academicService->updateAcademicDetails($user, $updateRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update academic details. Please check your data and try again.');
        }

        return $this->updated('Academic details updated successfully.', $details);
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