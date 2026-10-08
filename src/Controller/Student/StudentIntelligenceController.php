<?php
declare(strict_types=1);

namespace App\Controller\Student;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\StudentIntelligence\StudentIntelligenceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;

class StudentIntelligenceController extends AbstractApiController
{
    public function __construct(
        private StudentIntelligenceService $studentIntelligenceService,
        private SymfonySecurity $security,
        private LoggerInterface $logger,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/intelligence', name: 'student_intelligence_get', methods: ['GET'])]
    public function getIntelligence(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        }

        try {
            $intelligence = $this->studentIntelligenceService->getIntelligence((string) $user->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to build student intelligence', [
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve intelligence. Please try again.');
        }

        return $this->success('Student intelligence retrieved successfully.', $intelligence);
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
