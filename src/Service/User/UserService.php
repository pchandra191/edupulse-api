<?php
declare(strict_types=1);

namespace App\Service\User;

use App\DTO\PaginatedResult;
use App\DTO\Response\UserListResponse;
use App\DTO\User\CreateUserRequest;
use App\DTO\User\UserFilterRequest;
use App\DTO\User\UpdateUserRequest;
use App\DTO\User\UserResponse;
use App\DTO\User\AdminResetPasswordRequestDTO;
use App\Common\Api\Response\PaginationResponse;
use App\Common\Exception\BusinessException;
use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AuthenticationException;
use App\Repository\InstituteRepository;
use App\Repository\UserRepository;
use DateTimeInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private Security $security,
    ) {
    }

    public function getUsers(UserFilterRequest $filter): UserListResponse
    {
        [$instituteId, $excludeSuperAdmin] = $this->getTenantRestrictions();

        $result = $this->userRepository->findPaginated(
            $filter,
            $instituteId,
            $excludeSuperAdmin,
        );

        $items = array_map(
            fn (User $user) => $this->mapToResponse($user),
            $result->items,
        );

        return new UserListResponse(
            items: $items,
            pagination: new PaginationResponse(
                page: $result->pagination['page'],
                limit: $result->pagination['limit'],
                totalItems: $result->pagination['totalItems'],
                totalPages: $result->pagination['totalPages'],
            ),
        );
    }

    public function listUsers(
        ?string $search = null,
        ?string $role = null,
        ?string $status = null,
        ?string $department = null,
        int $page = 1,
        int $limit = 10,
        string $sortBy = 'createdAt',
        string $sortOrder = 'DESC'
    ): array {
        $filter = new UserFilterRequest(
            search: $search,
            role: $role,
            status: $status,
            department: $department,
            page: $page,
            limit: $limit,
            sortBy: $sortBy,
            sortOrder: $sortOrder,
        );

        $result = $this->getUsers($filter);

        return [
            'items' => $result->items,
            'pagination' => $result->pagination,
        ];
    }

    public function getUser(Uuid $id): UserResponse
    {
        [$instituteId, $excludeSuperAdmin] = $this->getTenantRestrictions();

        $user = $this->userRepository->findByUuid($id, $instituteId, $excludeSuperAdmin);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        return $this->mapToResponse($user);
    }

    public function getUserById(string $id): UserResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        return $this->mapToResponse($user);
    }

    public function createUser(CreateUserRequest $request): UserResponse
    {
        $this->validateUniqueEmail($request->email);
        $this->validateTeacherInstitute($request);

        try {
            $this->entityManager->beginTransaction();

            $user = $this->mapToEntity($request);
            $institute = $this->createInstituteIfNeeded($request, $user);
            $teacherInstitute = $this->resolveTeacherInstitute($request);

            if ($institute instanceof Institute) {
                $user->setInstitute($institute);
            } elseif ($teacherInstitute instanceof Institute) {
                $user->setInstitute($teacherInstitute);
            }

            $hashedPassword = $this->passwordHasher->hashPassword($user, $request->password);
            $user->setPassword($hashedPassword);

            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($user);
        } catch (BusinessException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create user.', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessException('Failed to create user. Please try again.', previous: $e);
        }
    }

    public function updateUser(Uuid $id, UpdateUserRequest $request): UserResponse
    {
        [$instituteId, $excludeSuperAdmin] = $this->getTenantRestrictions();

        $user = $this->userRepository->findByUuid($id, $instituteId, $excludeSuperAdmin);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateUserFields($user, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($user);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update user.', [
                'user_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessException('Failed to update user. Please try again.', previous: $e);
        }
    }

    public function changeStatus(Uuid $id, UserStatus $status): UserResponse
    {
        [$instituteId, $excludeSuperAdmin] = $this->getTenantRestrictions();

        $user = $this->userRepository->findByUuid($id, $instituteId, $excludeSuperAdmin);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        $user->setStatus($status);

        $this->entityManager->flush();

        return $this->mapToResponse($user);
    }

    public function updateUserStatus(User $user, UserStatus $status): UserResponse
    {
        $user->setStatus($status);

        $this->entityManager->flush();

        return $this->mapToResponse($user);
    }

    public function resetPassword(Uuid $id, AdminResetPasswordRequestDTO $request): array
    {
        $user = $this->userRepository->findByUuid($id);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        if ($request->newPassword !== $request->confirmPassword) {
            throw new BusinessException('Passwords do not match.');
        }

        try {
            $this->entityManager->beginTransaction();

            $hashedPassword = $this->passwordHasher->hashPassword($user, $request->newPassword);
            $user->setPassword($hashedPassword);

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to reset password.', [
                'user_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessException('Failed to reset password. Please try again.', previous: $e);
        }

        return ['message' => 'Password reset successfully.'];
    }

    private function mapToEntity(CreateUserRequest $request): User
    {
        $user = new User();
        $user->setEmail($request->email);
        $user->setFirstName($request->firstName);
        $user->setLastName($request->lastName);
        $user->setPhone($request->phone);
        $user->setDepartment($request->department);
        $user->setRoles($request->roles);
        $user->setStatus(UserStatus::Active);

        return $user;
    }

    private function mapToResponse(User $user): UserResponse
    {
        return new UserResponse(
            id: (string) $user->getId(),
            email: $user->getEmail(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            phone: $user->getPhone(),
            department: $user->getDepartment(),
            roles: $user->getRoles(),
            status: $user->getStatus(),
            isVerified: $user->isVerified(),
            createdAt: $user->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $user->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function validateUniqueEmail(string $email, ?Uuid $ignoreUser = null): void
    {
        $qb = $this->userRepository->createQueryBuilder('u');
        $qb->where('u.email = :email')
           ->setParameter('email', $email);

        if (null !== $ignoreUser) {
            $qb->andWhere('u.id != :ignoreUser')
               ->setParameter('ignoreUser', $ignoreUser);
        }

        if ((bool) $qb->getQuery()->getOneOrNullResult()) {
            throw new BusinessException('Email already exists.');
        }
    }

    private function validateUniquePhone(string $phone, ?Uuid $ignoreUser = null): void
    {
        $qb = $this->userRepository->createQueryBuilder('u');
        $qb->where('u.phone = :phone')
           ->setParameter('phone', $phone);

        if (null !== $ignoreUser) {
            $qb->andWhere('u.id != :ignoreUser')
               ->setParameter('ignoreUser', $ignoreUser);
        }

        if ((bool) $qb->getQuery()->getOneOrNullResult()) {
            throw new BusinessException('Phone number already exists.');
        }
    }

    private function generateTemporaryPassword(): string
    {
        $length = 16;
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()-_=+[]{}|;:,.<>?';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $all = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    private function updateUserFields(User $user, UpdateUserRequest $request): void
    {
        if ($request->email !== null && $request->email !== $user->getEmail()) {
            $this->validateUniqueEmail($request->email, $user->getId());
            $user->setEmail($request->email);
        }

        if ($request->firstName !== null) {
            $user->setFirstName($request->firstName);
        }

        if ($request->lastName !== null) {
            $user->setLastName($request->lastName);
        }

        if ($request->phone !== null) {
            $this->validateUniquePhone($request->phone, $user->getId());
            $user->setPhone($request->phone);
        }

        if ($request->department !== null) {
            $user->setDepartment($request->department);
        }

        if ($request->status !== null) {
            $user->setStatus($request->status);
        }

        if ($request->roles !== null) {
            $currentUser = $this->security->getUser();

            if ($currentUser instanceof User && $currentUser->getId()->equals($user->getId())) {
                throw new BusinessException('You cannot change your own role.');
            }

            if (in_array(UserRole::SuperAdmin->value, $request->roles, true)) {
                throw new BusinessException('Super Admin role cannot be assigned via API.');
            }

            $user->setRoles($request->roles);
        }
    }

    private function getTenantRestrictions(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [null, false];
        }

        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return [null, false];
        }

        if (in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            $institute = $user->getInstitute();

            if ($institute) {
                return [(string) $institute->getId(), true];
            }
        }

        return [null, false];
    }

    private function createInstituteIfNeeded(CreateUserRequest $request, User $user): ?Institute
    {
        if (!in_array(UserRole::Institute->value, $request->roles, true)) {
            return null;
        }

        $name = $request->instituteName ?? $this->deriveInstituteNameFromEmail($request->email);

        $institute = new Institute();
        $institute->setName($name);
        $institute->setCode($this->instituteRepository->getNextCode());
        $institute->setEmail($request->email);
        $institute->setStatus(InstituteStatus::Active);

        $this->entityManager->persist($institute);

        return $institute;
    }

    private function validateTeacherInstitute(CreateUserRequest $request): void
    {
        if (!in_array(UserRole::Teacher->value, $request->roles, true)) {
            return;
        }

        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            throw new BusinessException('Unauthenticated.');
        }

        if (in_array(UserRole::SuperAdmin->value, $currentUser->getRoles(), true)) {
            if (empty($request->instituteId)) {
                throw new BusinessException('Institute is required when creating a teacher.');
            }
        }
    }

    private function resolveTeacherInstitute(CreateUserRequest $request): ?Institute
    {
        if (!in_array(UserRole::Teacher->value, $request->roles, true)) {
            return null;
        }

        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            throw new BusinessException('Unauthenticated.');
        }

        if (in_array(UserRole::Institute->value, $currentUser->getRoles(), true)) {
            $institute = $currentUser->getInstitute();

            if ($institute) {
                return $institute;
            }

            throw new BusinessException('Institute not found for authenticated user.');
        }

        if (in_array(UserRole::SuperAdmin->value, $currentUser->getRoles(), true)) {
            if (empty($request->instituteId)) {
                throw new BusinessException('Institute is required when creating a teacher.');
            }

            $institute = $this->instituteRepository->find($request->instituteId);

            if (!$institute instanceof Institute) {
                throw new BusinessException('Selected institute not found.');
            }

            return $institute;
        }

        throw new BusinessException('Unable to determine institute for teacher.');
    }

    private function deriveInstituteNameFromEmail(string $email): string
    {
        $localPart = explode('@', $email)[0] ?? $email;

        $localPart = preg_replace('/[^a-zA-Z]/', ' ', $localPart) ?: $localPart;

        $localPart = ucwords(strtolower($localPart));

        $localPart = preg_replace('/\s+/', ' ', $localPart) ?: $localPart;

        $localPart = trim($localPart);

        if ($localPart === '') {
            return 'New Institute';
        }

        return $localPart;
    }
}
