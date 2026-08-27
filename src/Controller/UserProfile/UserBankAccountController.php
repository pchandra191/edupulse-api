<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\CreateUserBankAccountRequestDTO;
use App\DTO\UserProfile\UpdateUserBankAccountRequestDTO;
use App\DTO\UserProfile\UserBankAccountResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\UserBankAccountService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserBankAccountController extends AbstractApiController
{
    public function __construct(
        private UserBankAccountService $bankAccountService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/bank-accounts', name: 'user_bank_accounts_list', methods: ['GET'])]
    public function list(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
            $accounts = $this->bankAccountService->listBankAccounts($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Bank accounts retrieved successfully.', $accounts);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/bank-accounts', name: 'user_bank_accounts_create', methods: ['POST'])]
    public function create(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.', code: 'INVALID_JSON');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
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
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BANK_ACCOUNT_CREATE_FAILED');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create bank account. Please check your data and try again.', code: 'BANK_ACCOUNT_CREATE_ERROR');
        }

        return $this->created('Bank account created successfully.', $account);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/bank-accounts/{accountId}', name: 'user_bank_accounts_update', methods: ['PUT'])]
    public function update(string $id, string $accountId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.', code: 'INVALID_JSON');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
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
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BANK_ACCOUNT_UPDATE_FAILED');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update bank account. Please check your data and try again.', code: 'BANK_ACCOUNT_UPDATE_ERROR');
        }

        return $this->updated('Bank account updated successfully.', $account);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/bank-accounts/{accountId}/verify', name: 'user_bank_accounts_verify', methods: ['POST'])]
    public function verify(string $id, string $accountId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
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
    #[Route('/users/{id}/bank-accounts/{accountId}/reject', name: 'user_bank_accounts_reject', methods: ['POST'])]
    public function reject(string $id, string $accountId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
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

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/bank-accounts/{accountId}', name: 'user_bank_accounts_delete', methods: ['DELETE'])]
    public function delete(string $id, string $accountId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
            $this->bankAccountService->deleteBankAccount($user, new Uuid($accountId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'BANK_ACCOUNT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete bank account. Please try again later.', code: 'BANK_ACCOUNT_DELETE_ERROR');
        }

        return $this->deleted('Bank account deleted successfully.');
    }

    private function resolveUser(string $id): User
    {
        try {
            $uuid = new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('User not found.');
        }

        $user = $this->userRepository->find($uuid);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        return $user;
    }
}
