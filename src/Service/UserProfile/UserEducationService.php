<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\DTO\UserProfile\CreateUserEducationRequestDTO;
use App\DTO\UserProfile\UpdateUserEducationRequestDTO;
use App\DTO\UserProfile\UserEducationResponseDTO;
use App\Entity\User;
use App\Entity\UserEducation;
use App\Repository\UserEducationRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class UserEducationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserEducationRepository $userEducationRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function listEducation(User $user): array
    {
        $records = $this->userEducationRepository->findByUserId($user->getId());

        return array_map(
            fn(UserEducation $record) => $this->mapToResponse($record),
            $records
        );
    }

    public function createEducation(User $user, CreateUserEducationRequestDTO $request): UserEducationResponseDTO
    {
        $education = new UserEducation();
        $education->setUserId($user->getId());
        $this->applyRequest($education, $request);

        $this->entityManager->persist($education);
        $this->entityManager->flush();

        return $this->mapToResponse($education);
    }

    public function updateEducation(User $user, Uuid $educationId, UpdateUserEducationRequestDTO $request): UserEducationResponseDTO
    {
        $education = $this->findUserEducation($user, $educationId);

        if ($request->institutionName !== null) {
            $education->setInstitutionName($request->institutionName);
        }

        if ($request->educationLevel !== null) {
            $education->setEducationLevel(\App\Enum\EducationLevel::from($request->educationLevel));
        }

        if ($request->qualification !== null) {
            $education->setQualification($request->qualification);
        }

        if ($request->specialization !== null) {
            $education->setSpecialization($request->specialization);
        }

        if ($request->boardUniversity !== null) {
            $education->setBoardUniversity($request->boardUniversity);
        }

        if ($request->startDate !== null) {
            try {
                $education->setStartDate(new \DateTimeImmutable($request->startDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid start date format.');
            }
        }

        if ($request->endDate !== null) {
            try {
                $education->setEndDate(new \DateTimeImmutable($request->endDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid end date format.');
            }
        }

        if ($request->result !== null) {
            $education->setResult(\App\Enum\EducationResult::from($request->result));
        }

        if ($request->percentage !== null) {
            $education->setPercentage(number_format($request->percentage, 2, '.', ''));
        }

        if ($request->grade !== null) {
            $education->setGrade($request->grade);
        }

        $this->entityManager->flush();

        return $this->mapToResponse($education);
    }

    public function deleteEducation(User $user, Uuid $educationId): void
    {
        $education = $this->findUserEducation($user, $educationId);

        $this->entityManager->remove($education);
        $this->entityManager->flush();
    }

    private function findUserEducation(User $user, Uuid $educationId): UserEducation
    {
        $education = $this->userEducationRepository->find($educationId);

        if (!$education instanceof UserEducation) {
            throw new \App\Exception\AuthenticationException('Education record not found.');
        }

        if (!$education->getUserId()->equals($user->getId())) {
            throw new \App\Exception\AuthenticationException('Education record not found.');
        }

        return $education;
    }

    private function applyRequest(UserEducation $education, CreateUserEducationRequestDTO $request): void
    {
        $education->setInstitutionName($request->institutionName);
        $education->setEducationLevel(\App\Enum\EducationLevel::from($request->educationLevel));
        $education->setQualification($request->qualification);
        $education->setSpecialization($request->specialization);
        $education->setBoardUniversity($request->boardUniversity);

        if ($request->startDate !== null) {
            try {
                $education->setStartDate(new \DateTimeImmutable($request->startDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid start date format.');
            }
        }

        if ($request->endDate !== null) {
            try {
                $education->setEndDate(new \DateTimeImmutable($request->endDate));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid end date format.');
            }
        }

        if ($request->result !== null) {
            $education->setResult(\App\Enum\EducationResult::from($request->result));
        } else {
            $education->setResult(null);
        }

        if ($request->percentage !== null) {
            $education->setPercentage(number_format($request->percentage, 2, '.', ''));
        }

        $education->setGrade($request->grade);
    }

    private function mapToResponse(UserEducation $education): UserEducationResponseDTO
    {
        return new UserEducationResponseDTO(
            id: $education->getId()->toRfc4122(),
            institutionName: $education->getInstitutionName(),
            educationLevel: $education->getEducationLevel()->value,
            qualification: $education->getQualification(),
            specialization: $education->getSpecialization(),
            boardUniversity: $education->getBoardUniversity(),
            startDate: $education->getStartDate()?->format('Y-m-d'),
            endDate: $education->getEndDate()?->format('Y-m-d'),
            result: $education->getResult()?->value,
            percentage: $education->getPercentage(),
            grade: $education->getGrade(),
            createdAt: $education->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $education->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
