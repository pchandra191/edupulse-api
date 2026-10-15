<?php
declare(strict_types=1);

namespace App\Controller\Institute;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Institute\InstituteFilterRequest;
use App\DTO\Institute\InstituteOptionResponse;
use App\DTO\Institute\InstituteProfileResponseDTO;
use App\DTO\Institute\InstituteProfileUpdateRequestDTO;
use App\DTO\Institute\PublicInstituteResponse;
use App\Enum\Currency;
use App\Enum\Timezone;
use App\Exception\AuthenticationException;
use App\Common\Exception\BusinessException;
use App\Service\Institute\InstituteService;
use App\Service\Auth\StudentRegistrationService;
use App\Repository\InstituteRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security as SecurityService;

class InstituteController extends AbstractApiController
{
    public function __construct(
        private InstituteService $instituteService,
        private StudentRegistrationService $studentRegistrationService,
        private InstituteRepository $instituteRepository,
        private ValidatorInterface $validator,
        private SecurityService $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes/options', name: 'institute_options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        $options = $this->instituteService->getOptions();

        return $this->success('Institutes retrieved successfully.', $options);
    }

    #[Route('/institutes/public', name: 'institute_public_list', methods: ['GET'])]
    public function publicList(): JsonResponse
    {
        $institutes = $this->instituteService->getPublicInstitutes();

        return $this->success('Active institutes retrieved successfully.', $institutes);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes', name: 'institute_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = new InstituteFilterRequest(
            search: $request->query->get('search'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->instituteService->getInstitutes($filter);

        return $this->paginated('Institutes retrieved successfully.', $result->items, $result->pagination);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
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

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes/{id}', name: 'institute_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
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
            $institute = $this->instituteService->updateInstitute($this->parseUuid($id), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('Institute updated successfully.', $institute);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/institutes/{id}/onboarding/complete', name: 'institute_onboarding_complete', methods: ['POST'])]
    public function completeOnboarding(string $id): JsonResponse
    {
        $instituteId = $this->parseUuid($id);

        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $user = $this->security->getUser();

            if (!$user instanceof User) {
                return $this->accessDenied('You do not have permission to modify this institute.');
            }

            $userInstituteId = $user->getInstitute()?->getId();

            if ((string) $userInstituteId !== (string) $instituteId) {
                return $this->accessDenied('You do not have permission to modify this institute.');
            }
        }

        try {
            $institute = $this->instituteService->completeOnboarding($instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Onboarding completed successfully.', $institute);
    }

    #[Route('/institutes/{id}/student-registration-link', name: 'institute_student_registration_link', methods: ['GET'])]
    public function studentRegistrationLink(string $id): JsonResponse
    {
        try {
            $instituteId = $this->parseUuid($id);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        try {
            $institute = $this->instituteRepository->findById((string) $instituteId);

            if (!$institute instanceof \App\Entity\Institute) {
                throw new AuthenticationException('Institute not found.');
            }

            $link = $this->studentRegistrationService->getOrCreateRegistrationLink($institute);

            return $this->success('Student registration link retrieved successfully.', [
                'registrationUrl' => $link->registrationUrl,
            ]);
        } catch (\App\Exception\InstituteNotActiveException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[Route('/institutes/{id}/student-registration-link/public', name: 'institute_student_registration_link_public', methods: ['GET'])]
    public function publicStudentRegistrationLink(string $id): JsonResponse
    {
        try {
            $instituteId = $this->parseUuid($id);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        try {
            $institute = $this->instituteRepository->findById((string) $instituteId);

            if (!$institute instanceof \App\Entity\Institute) {
                throw new AuthenticationException('Institute not found.');
            }

            if (!$institute->isActive()) {
                return $this->accessDenied('Institute is not active.');
            }

            $link = $this->studentRegistrationService->getOrCreateRegistrationLink($institute);

            return $this->success('Student registration link retrieved successfully.', [
                'registrationUrl' => $link->registrationUrl,
            ]);
        } catch (\App\Exception\InstituteNotActiveException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/currencies', name: 'currency_list', methods: ['GET'])]
    public function currencies(): JsonResponse
    {
        $currencies = array_map(
            fn (Currency $currency) => [
                'id' => $currency->value,
                'code' => $currency->value,
                'name' => match ($currency) {
                    Currency::INR => 'Indian Rupee',
                    Currency::USD => 'US Dollar',
                    Currency::EUR => 'Euro',
                    Currency::GBP => 'British Pound',
                    Currency::AED => 'UAE Dirham',
                    Currency::SGD => 'Singapore Dollar',
                },
            ],
            Currency::cases()
        );

        return $this->success('Currencies retrieved successfully.', $currencies);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/timezones', name: 'timezone_list', methods: ['GET'])]
    public function timezones(): JsonResponse
    {
        $timezones = array_map(
            fn (Timezone $timezone) => [
                'id' => $timezone->value,
                'code' => $timezone->value,
                'name' => match ($timezone) {
                    Timezone::INR => 'India',
                    Timezone::USD => 'United States',
                    Timezone::EUR => 'Germany',
                    Timezone::GBP => 'United Kingdom',
                    Timezone::AED => 'United Arab Emirates',
                    Timezone::SGD => 'Singapore',
                },
            ],
            Timezone::cases()
        );

        return $this->success('Timezones retrieved successfully.', $timezones);
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
