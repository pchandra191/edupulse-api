<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\CreateUserAddressRequestDTO;
use App\DTO\UserProfile\CreateUserBankAccountRequestDTO;
use App\DTO\UserProfile\CreateUserDocumentRequestDTO;
use App\DTO\UserProfile\CreateUserEducationRequestDTO;
use App\DTO\UserProfile\CreateUserGovernmentIdentifierRequestDTO;
use App\DTO\UserProfile\UpdateUserAddressRequestDTO;
use App\DTO\UserProfile\UpdateUserBankAccountRequestDTO;
use App\DTO\UserProfile\UpdateUserEducationRequestDTO;
use App\DTO\UserProfile\UpdateUserGovernmentIdentifierRequestDTO;
use App\DTO\UserProfile\UpdateUserProfileRequestDTO;
use App\DTO\UserProfile\UserAddressResponseDTO;
use App\DTO\UserProfile\UserBankAccountResponseDTO;
use App\DTO\UserProfile\UserDocumentResponseDTO;
use App\DTO\UserProfile\UserEducationResponseDTO;
use App\DTO\UserProfile\UserGovernmentIdentifierResponseDTO;
use App\DTO\UserProfile\UserProfileResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\UserProfile\UserAddressService;
use App\Service\UserProfile\UserBankAccountService;
use App\Service\UserProfile\UserDocumentService;
use App\Service\UserProfile\UserEducationService;
use App\Service\UserProfile\UserGovernmentIdentifierService;
use App\Service\UserProfile\UserProfileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class MeProfileController extends AbstractApiController
{
    public function __construct(
        private UserProfileService $profileService,
        private UserAddressService $addressService,
        private UserEducationService $educationService,
        private UserGovernmentIdentifierService $identifierService,
        private UserBankAccountService $bankAccountService,
        private UserDocumentService $documentService,
        private SymfonySecurity $security,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/profile', name: 'me_profile_get', methods: ['GET'])]
    public function getProfile(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $profile = $this->profileService->getProfile($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Profile retrieved successfully.', $profile);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/profile', name: 'me_profile_update', methods: ['PUT'])]
    public function updateProfile(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateUserProfileRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $profile = $this->profileService->updateProfile($user, $updateRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update profile. Please check your data and try again.');
        }

        return $this->updated('Profile updated successfully.', $profile);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/addresses', name: 'me_addresses_list', methods: ['GET'])]
    public function listAddresses(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $addresses = $this->addressService->listAddresses($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Addresses retrieved successfully.', $addresses);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/addresses', name: 'me_addresses_create', methods: ['POST'])]
    public function createAddress(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $createRequest = CreateUserAddressRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $address = $this->addressService->createAddress($user, $createRequest);
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create address. Please check your data and try again.');
        }

        return $this->created('Address created successfully.', $address);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/addresses/{addressId}', name: 'me_addresses_update', methods: ['PUT'])]
    public function updateAddress(string $addressId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateUserAddressRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $address = $this->addressService->updateAddress($user, new Uuid($addressId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update address. Please check your data and try again.');
        }

        return $this->updated('Address updated successfully.', $address);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/addresses/{addressId}', name: 'me_addresses_delete', methods: ['DELETE'])]
    public function deleteAddress(string $addressId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->addressService->deleteAddress($user, new Uuid($addressId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete address. Please try again later.');
        }

        return $this->deleted('Address deleted successfully.');
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/education', name: 'me_education_list', methods: ['GET'])]
    public function listEducation(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $records = $this->educationService->listEducation($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Education records retrieved successfully.', $records);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/education', name: 'me_education_create', methods: ['POST'])]
    public function createEducation(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $createRequest = CreateUserEducationRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $record = $this->educationService->createEducation($user, $createRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create education record. Please check your data and try again.');
        }

        return $this->created('Education record created successfully.', $record);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/education/{educationId}', name: 'me_education_update', methods: ['PUT'])]
    public function updateEducation(string $educationId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateUserEducationRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $record = $this->educationService->updateEducation($user, new Uuid($educationId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update education record. Please check your data and try again.');
        }

        return $this->updated('Education record updated successfully.', $record);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/education/{educationId}', name: 'me_education_delete', methods: ['DELETE'])]
    public function deleteEducation(string $educationId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->educationService->deleteEducation($user, new Uuid($educationId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete education record. Please try again later.');
        }

        return $this->deleted('Education record deleted successfully.');
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/government-identifiers', name: 'me_gov_ids_list', methods: ['GET'])]
    public function listGovernmentIdentifiers(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $identifiers = $this->identifierService->listIdentifiers($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Government identifiers retrieved successfully.', $identifiers);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/government-identifiers', name: 'me_gov_ids_create', methods: ['POST'])]
    public function createGovernmentIdentifier(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $createRequest = CreateUserGovernmentIdentifierRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $identifier = $this->identifierService->createIdentifier($user, $createRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create government identifier. Please check your data and try again.');
        }

        return $this->created('Government identifier created successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/government-identifiers/{identifierId}', name: 'me_gov_ids_update', methods: ['PUT'])]
    public function updateGovernmentIdentifier(string $identifierId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateUserGovernmentIdentifierRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $identifier = $this->identifierService->updateIdentifier($user, new Uuid($identifierId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update government identifier. Please check your data and try again.');
        }

        return $this->updated('Government identifier updated successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/government-identifiers/{identifierId}', name: 'me_gov_ids_delete', methods: ['DELETE'])]
    public function deleteGovernmentIdentifier(string $identifierId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->identifierService->deleteIdentifier($user, new Uuid($identifierId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete government identifier. Please try again later.');
        }

        return $this->deleted('Government identifier deleted successfully.');
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/me/government-identifiers/{identifierId}/verify', name: 'me_gov_ids_verify', methods: ['POST'])]
    public function verifyGovernmentIdentifier(string $identifierId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->authService->assertCanVerify($user);
            $identifier = $this->identifierService->verifyIdentifier($user, new Uuid($identifierId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'IDENTIFIER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to verify government identifier. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Government identifier verified successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/me/government-identifiers/{identifierId}/reject', name: 'me_gov_ids_reject', methods: ['POST'])]
    public function rejectGovernmentIdentifier(string $identifierId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->authService->assertCanVerify($user);
            $identifier = $this->identifierService->rejectIdentifier($user, new Uuid($identifierId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'IDENTIFIER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to reject government identifier. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Government identifier rejected successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/bank-accounts', name: 'me_bank_accounts_list', methods: ['GET'])]
    public function listBankAccounts(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $accounts = $this->bankAccountService->listBankAccounts($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Bank accounts retrieved successfully.', $accounts);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/bank-accounts', name: 'me_bank_accounts_create', methods: ['POST'])]
    public function createBankAccount(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.', code: 'INVALID_JSON');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        }

        $createRequest = CreateUserBankAccountRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $account = $this->bankAccountService->createBankAccount($user, $createRequest);
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create bank account. Please check your data and try again.', code: 'BANK_ACCOUNT_CREATE_ERROR');
        }

        return $this->created('Bank account created successfully.', $account);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/bank-accounts/{accountId}', name: 'me_bank_accounts_update', methods: ['PUT'])]
    public function updateBankAccount(string $accountId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.', code: 'INVALID_JSON');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        }

        $updateRequest = UpdateUserBankAccountRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $account = $this->bankAccountService->updateBankAccount($user, new Uuid($accountId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'BANK_ACCOUNT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update bank account. Please check your data and try again.', code: 'BANK_ACCOUNT_UPDATE_ERROR');
        }

        return $this->updated('Bank account updated successfully.', $account);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT') or is_granted('ROLE_TEACHER')")]
    #[Route('/me/bank-accounts/{accountId}', name: 'me_bank_accounts_delete', methods: ['DELETE'])]
    public function deleteBankAccount(string $accountId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->bankAccountService->deleteBankAccount($user, new Uuid($accountId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'BANK_ACCOUNT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete bank account. Please try again later.', code: 'BANK_ACCOUNT_DELETE_ERROR');
        }

        return $this->deleted('Bank account deleted successfully.');
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/me/bank-accounts/{accountId}/verify', name: 'me_bank_accounts_verify', methods: ['POST'])]
    public function verifyBankAccount(string $accountId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->authService->assertCanVerify($user);
            $account = $this->bankAccountService->verifyBankAccount($user, new Uuid($accountId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'BANK_ACCOUNT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to verify bank account. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Bank account verified successfully.', $account);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/me/bank-accounts/{accountId}/reject', name: 'me_bank_accounts_reject', methods: ['POST'])]
    public function rejectBankAccount(string $accountId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->authService->assertCanVerify($user);
            $account = $this->bankAccountService->rejectBankAccount($user, new Uuid($accountId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'BANK_ACCOUNT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to reject bank account. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Bank account rejected successfully.', $account);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/documents', name: 'me_documents_list', methods: ['GET'])]
    public function listDocuments(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $documents = $this->documentService->listDocuments($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Documents retrieved successfully.', $documents);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/documents', name: 'me_documents_create', methods: ['POST'])]
    public function createDocument(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->unprocessableEntity('Validation failed.', ['file' => ['File upload is required.']], code: 'VALIDATION_ERROR');
            }
            $createRequest = CreateUserDocumentRequestDTO::fromArray($data);
        } else {
            $data = json_decode($request->getContent(), true) ?: [];
            $createRequest = CreateUserDocumentRequestDTO::fromArray($data, $uploadedFile);
        }

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $user = $this->getCurrentUser();
            $document = $this->documentService->createDocument($user, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create document. Please check your data and try again.', code: 'DOCUMENT_CREATE_ERROR');
        }

        return $this->created('Document created successfully.', $document);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/documents/{documentId}/download', name: 'me_documents_download', methods: ['GET'])]
    public function downloadDocument(string $documentId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $user = $this->getCurrentUser();
            $document = $this->documentService->findUserDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to download document.', code: 'DOCUMENT_DOWNLOAD_ERROR');
        }

        try {
            return $this->documentService->serveDocument($document);
        } catch (\RuntimeException $e) {
            return $this->serverError('Document file not found on server.', code: 'DOCUMENT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to download document.', code: 'DOCUMENT_DOWNLOAD_ERROR');
        }
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/me/documents/{documentId}', name: 'me_documents_delete', methods: ['DELETE'])]
    public function deleteDocument(string $documentId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->documentService->deleteDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete document. Please try again later.');
        }

        return $this->deleted('Document deleted successfully.');
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/me/documents/{documentId}/verify', name: 'me_documents_verify', methods: ['POST'])]
    public function verifyDocument(string $documentId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->authService->assertCanVerify($user);
            $document = $this->documentService->verifyDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'DOCUMENT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to verify document. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Document verified successfully.', $document);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/me/documents/{documentId}/reject', name: 'me_documents_reject', methods: ['POST'])]
    public function rejectDocument(string $documentId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $this->authService->assertCanVerify($user);
            $document = $this->documentService->rejectDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'DOCUMENT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to reject document. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Document rejected successfully.', $document);
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
