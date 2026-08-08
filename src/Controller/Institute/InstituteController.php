<?php
declare(strict_types=1);

namespace App\Controller\Institute;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Institute\InstituteOptionResponse;
use App\Exception\AuthenticationException;
use App\Service\Institute\InstituteService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Uid\Uuid;

class InstituteController extends AbstractApiController
{
    public function __construct(
        private InstituteService $instituteService,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institutes/options', name: 'institute_options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        $options = $this->instituteService->getOptions();

        return $this->success('Institutes retrieved successfully.', $options);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institutes/{id}', name: 'institute_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            $institute = $this->instituteService->getById($this->parseUuid($id));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Institute retrieved successfully.', $institute);
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('Institute not found.', previous: $e);
        }
    }
}
