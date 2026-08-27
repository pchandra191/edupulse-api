<?php
declare(strict_types=1);

namespace App\Controller\Institute;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Institute\InstituteProfileResponseDTO;
use App\DTO\Institute\InstituteProfileUpdateRequestDTO;
use App\Entity\Institute;
use App\Exception\AuthenticationException;
use App\Repository\InstituteRepository;
use App\Service\Institute\InstituteLogoService;
use App\Service\Institute\InstituteProfileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\SecurityBundle\Security as SecurityService;

class InstituteProfileController extends AbstractApiController
{
    public function __construct(
        private InstituteProfileService $instituteProfileService,
        private InstituteLogoService $instituteLogoService,
        private InstituteRepository $instituteRepository,
        private ValidatorInterface $validator,
        private SecurityService $security,
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
            return $this->notFound($e->getMessage());
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
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('Institute profile updated successfully.', $profile);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institutes/{id}/logo', name: 'institute_logo_upload', methods: ['POST'])]
    public function uploadLogo(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->assertCanModifyInstitute($id);

        $uploadedFile = $request->files->get('logo');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->unprocessableEntity('Validation failed.', ['logo' => ['Logo file is required.']], code: 'VALIDATION_ERROR');
        }

        try {
            $institute = $this->instituteRepository->findById((string) $this->parseUuid($id));

            if (!$institute instanceof Institute) {
                return $this->notFound('Institute not found.', code: 'INSTITUTE_NOT_FOUND');
            }

            $this->instituteLogoService->removeOldLogo($institute->getLogo());

            $relativePath = $this->instituteLogoService->uploadLogo($institute, $uploadedFile);
            $institute->setLogo($relativePath);

            $this->instituteRepository->getEntityManager()->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            error_log('LOGO UPLOAD ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            @file_put_contents(dirname(__DIR__, 3) . '/var/log/logo_upload_debug.log', date('c') . ' ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL, FILE_APPEND);
            return $this->serverError('Unable to upload institute logo. Please try again later.', code: 'LOGO_UPLOAD_ERROR');
        }

        return $this->updated('Institute logo uploaded successfully.', $this->mapInstituteToResponse($institute));
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institutes/{id}/logo', name: 'institute_logo_delete', methods: ['DELETE'])]
    public function deleteLogo(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->assertCanModifyInstitute($id);

        try {
            $institute = $this->instituteRepository->findById((string) $this->parseUuid($id));

            if (!$institute instanceof Institute) {
                return $this->notFound('Institute not found.', code: 'INSTITUTE_NOT_FOUND');
            }

            $this->instituteLogoService->removeOldLogo($institute->getLogo());
            $institute->setLogo(null);

            $this->instituteRepository->getEntityManager()->flush();
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete institute logo. Please try again later.', code: 'LOGO_DELETE_ERROR');
        }

        return $this->updated('Institute logo deleted successfully.', $this->mapInstituteToResponse($institute));
    }

    private function assertCanModifyInstitute(string $instituteId): void
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $user = $this->security->getUser();

            if (!$user instanceof \App\Entity\User) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You do not have permission to modify this institute.');
            }

            $userInstituteId = $user->getInstitute()?->getId();

            if ((string) $userInstituteId !== $instituteId) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You do not have permission to modify this institute.');
            }
        }
    }

    private function mapInstituteToResponse(Institute $institute): InstituteProfileResponseDTO
    {
        return new InstituteProfileResponseDTO(
            id: (string) $institute->getId(),
            name: $institute->getName(),
            code: $institute->getCode(),
            email: $institute->getEmail(),
            phone: $institute->getPhone(),
            website: $institute->getWebsite(),
            logo: $this->instituteLogoService->getLogoUrl($institute->getLogo()),
            addressLine1: $institute->getAddressLine1(),
            addressLine2: $institute->getAddressLine2(),
            city: $institute->getCity(),
            state: $institute->getState(),
            country: $institute->getCountry(),
            postalCode: $institute->getPostalCode(),
            timezone: $institute->getTimezone()?->value,
            currency: $institute->getCurrency()?->value,
            status: $institute->getStatus()->value,
            onboardingCompleted: $institute->isOnboardingCompleted(),
            onboardingCompletedAt: $institute->getOnboardingCompletedAt()?->format(\DateTimeInterface::ATOM),
            createdAt: $institute->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $institute->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        );
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
