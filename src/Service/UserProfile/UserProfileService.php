<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\DTO\UserProfile\UpdateUserProfileRequestDTO;
use App\DTO\UserProfile\UserProfileResponseDTO;
use App\Entity\User;
use App\Entity\UserProfile;
use App\Exception\AuthenticationException;
use App\Repository\UserProfileRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class UserProfileService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserProfileRepository $userProfileRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getProfile(User $user): UserProfileResponseDTO
    {
        $profile = $this->userProfileRepository->findByUserId($user->getId());

        return $this->mapToResponse($user, $profile);
    }

    public function updateProfile(User $user, UpdateUserProfileRequestDTO $request): UserProfileResponseDTO
    {
        $profile = $this->userProfileRepository->findByUserId($user->getId());

        if (!$profile instanceof UserProfile) {
            $profile = new UserProfile();
            $profile->setUserId($user->getId());
            $this->entityManager->persist($profile);
        }

        $this->updateFields($profile, $request);
        $this->entityManager->flush();

        return $this->mapToResponse($user, $profile);
    }

    private function updateFields(UserProfile $profile, UpdateUserProfileRequestDTO $request): void
    {
        if ($request->middleName !== null) {
            $profile->setMiddleName($request->middleName);
        }

        if ($request->dateOfBirth !== null) {
            try {
                $profile->setDateOfBirth(new \DateTimeImmutable($request->dateOfBirth));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid date of birth format.');
            }
        }

        if ($request->gender !== null) {
            $profile->setGender(\App\Enum\UserGender::from($request->gender));
        }

        if ($request->bloodGroup !== null) {
            $profile->setBloodGroup(\App\Enum\BloodGroup::from($request->bloodGroup));
        }

        if ($request->mobile !== null) {
            $profile->setMobile($request->mobile);
        }

        if ($request->alternateMobile !== null) {
            $profile->setAlternateMobile($request->alternateMobile);
        }

        if ($request->profilePhotoReference !== null) {
            $profile->setProfilePhotoReference($request->profilePhotoReference);
        }

        if ($request->nationality !== null) {
            $profile->setNationality($request->nationality);
        }

        if ($request->maritalStatus !== null) {
            $profile->setMaritalStatus(\App\Enum\MaritalStatus::from($request->maritalStatus));
        }

        if ($request->emergencyContactName !== null) {
            $profile->setEmergencyContactName($request->emergencyContactName);
        }

        if ($request->emergencyContactPhone !== null) {
            $profile->setEmergencyContactPhone($request->emergencyContactPhone);
        }

        if ($request->emergencyContactRelationship !== null) {
            $profile->setEmergencyContactRelationship($request->emergencyContactRelationship);
        }
    }

    private function mapToResponse(User $user, ?UserProfile $profile): UserProfileResponseDTO
    {
        return new UserProfileResponseDTO(
            id: $user->getId()->toRfc4122(),
            firstName: $user->getFirstName(),
            middleName: $profile?->getMiddleName(),
            lastName: $user->getLastName(),
            fullName: $user->getFullName(),
            dateOfBirth: $profile?->getDateOfBirth()?->format('Y-m-d'),
            gender: $profile?->getGender()?->value,
            bloodGroup: $profile?->getBloodGroup()?->value,
            mobile: $profile?->getMobile() ?? $user->getPhone(),
            alternateMobile: $profile?->getAlternateMobile(),
            profilePhotoReference: $profile?->getProfilePhotoReference(),
            nationality: $profile?->getNationality(),
            maritalStatus: $profile?->getMaritalStatus()?->value,
            emergencyContactName: $profile?->getEmergencyContactName(),
            emergencyContactPhone: $profile?->getEmergencyContactPhone(),
            emergencyContactRelationship: $profile?->getEmergencyContactRelationship(),
            createdAt: $user->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $user->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
