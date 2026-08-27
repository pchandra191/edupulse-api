<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use Symfony\Bundle\SecurityBundle\Security;

readonly class ProfileAuthorizationService
{
    public function __construct(private Security $security)
    {
    }

    public function assertCanAccess(User $targetUser): void
    {
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $currentUserId = $currentUser->getId();
        $targetUserId = $targetUser->getId();

        if ($currentUserId->equals($targetUserId)) {
            return;
        }

        $currentRoles = $currentUser->getRoles();

        if (in_array(UserRole::SuperAdmin->value, $currentRoles, true)) {
            return;
        }

        if (!$this->hasDetailedProfile($targetUser)) {
            throw new AuthenticationException('You do not have permission to access this resource.');
        }

        if (in_array(UserRole::Institute->value, $currentRoles, true)) {
            $currentInstitute = $currentUser->getInstitute();
            $targetInstitute = $targetUser->getInstitute();

            if ($currentInstitute && $targetInstitute && $currentInstitute->getId()->equals($targetInstitute->getId())) {
                return;
            }
        }

        if (in_array(UserRole::Teacher->value, $currentRoles, true)) {
            $currentInstitute = $currentUser->getInstitute();
            $targetInstitute = $targetUser->getInstitute();

            if ($currentInstitute && $targetInstitute && $currentInstitute->getId()->equals($targetInstitute->getId())) {
                return;
            }
        }

        throw new AuthenticationException('You do not have permission to access this resource.');
    }

    private function hasDetailedProfile(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(UserRole::Teacher->value, $roles, true)
            || in_array(UserRole::Student->value, $roles, true);
    }

    public function assertCanAccessSensitive(User $targetUser): void
    {
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if ($currentUser->getId()->equals($targetUser->getId())) {
            return;
        }

        $currentRoles = $currentUser->getRoles();

        if (in_array(UserRole::SuperAdmin->value, $currentRoles, true)) {
            return;
        }

        if (in_array(UserRole::Institute->value, $currentRoles, true)) {
            $currentInstitute = $currentUser->getInstitute();
            $targetInstitute = $targetUser->getInstitute();

            if ($currentInstitute && $targetInstitute && $currentInstitute->getId()->equals($targetInstitute->getId())) {
                return;
            }

            throw new AuthenticationException('User does not belong to your institute.');
        }

        if (in_array(UserRole::Teacher->value, $currentRoles, true)) {
            $currentInstitute = $currentUser->getInstitute();
            $targetInstitute = $targetUser->getInstitute();

            if ($currentInstitute && $targetInstitute && $currentInstitute->getId()->equals($targetInstitute->getId())) {
                return;
            }
        }

        throw new AuthenticationException('You do not have permission to access this sensitive data.');
    }

    public function assertCanVerify(User $targetUser): void
    {
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if ($currentUser->getId()->equals($targetUser->getId())) {
            throw new AuthenticationException('You cannot verify your own data.');
        }

        $currentRoles = $currentUser->getRoles();

        if (in_array(UserRole::SuperAdmin->value, $currentRoles, true)) {
            return;
        }

        if (in_array(UserRole::Institute->value, $currentRoles, true)) {
            $currentInstitute = $currentUser->getInstitute();
            $targetInstitute = $targetUser->getInstitute();

            if ($currentInstitute && $targetInstitute && $currentInstitute->getId()->equals($targetInstitute->getId())) {
                return;
            }

            throw new AuthenticationException('User does not belong to your institute.');
        }

        throw new AuthenticationException('You do not have permission to verify this data.');
    }

    public function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }
}
