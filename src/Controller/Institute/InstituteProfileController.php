<?php
declare(strict_types=1);

namespace App\Controller\Institute;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Institute\InstituteProfileResponseDTO;
use App\DTO\Institute\InstituteProfileUpdateRequestDTO;
use App\Exception\AuthenticationException;
use App\Service\Institute\InstituteProfileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InstituteProfileController extends AbstractApiController
{
    public function __construct(
        private InstituteProfileService $instituteProfileService,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institute/profile', name: 'institute_profile_get', methods: ['GET'])]
    public function getProfile(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $profile = $this->instituteProfileService->getProfile();
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Institute profile retrieved successfully.', $profile);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institute/profile', name: 'institute_profile_update', methods: ['PUT'])]
    public function updateProfile(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new InstituteProfileUpdateRequestDTO(
            name: $data['name'] ?? '',
            email: $data['email'] ?? '',
            phone: $data['phone'] ?? null,
            website: $data['website'] ?? null,
            logo: $data['logo'] ?? null,
            addressLine1: $data['addressLine1'] ?? null,
            addressLine2: $data['addressLine2'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
            country: $data['country'] ?? null,
            postalCode: $data['postalCode'] ?? null,
            timezone: $data['timezone'] ?? null,
            currency: $data['currency'] ?? null,
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
            $profile = $this->instituteProfileService->updateProfile($updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('Institute profile updated successfully.', $profile);
    }
}
