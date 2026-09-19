<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\DTO\AttendanceModule\AcademicYearReferenceDTO;
use App\DTO\AttendanceModule\AttendancePolicyCreateRequestDTO;
use App\DTO\AttendanceModule\AttendancePolicyResponseDTO;
use App\DTO\AttendanceModule\AttendancePolicyUpdateRequestDTO;
use App\DTO\AttendanceModule\InstituteReferenceDTO;
use App\DTO\AttendanceModule\StudentAttendancePolicyDTO;
use App\DTO\Response\AttendancePolicyListResponse;
use App\Entity\AcademicYear;
use App\Entity\AttendancePolicy;
use App\Entity\Institute;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\AttendancePolicyRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class AttendancePolicyService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AttendancePolicyRepository $policyRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getPolicies(
        ?Uuid $instituteId,
        ?string $academicYearId = null,
        ?string $status = null,
        int $page = 1,
        int $limit = 10,
        string $sortBy = 'createdAt',
        string $sortOrder = 'DESC'
    ): AttendancePolicyListResponse {
        $user = $this->security->getUser();

        if (in_array(UserRole::Student->value, $user?->getRoles() ?? [], true)) {
            return new AttendancePolicyListResponse(
                items: [],
                pagination: ['page' => $page, 'limit' => $limit, 'totalItems' => 0, 'totalPages' => 0],
            );
        }

        $result = $this->policyRepository->findPaginated(
            $instituteId,
            $academicYearId ? Uuid::fromString($academicYearId) : null,
            $status,
            $page,
            $limit,
            $sortBy,
            $sortOrder,
        );

        $items = array_map(
            fn (AttendancePolicy $policy) => $this->mapToResponse($policy),
            $result['items'],
        );

        return new AttendancePolicyListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getPolicy(?Uuid $instituteId, string $id): AttendancePolicyResponseDTO
    {
        $policy = $this->policyRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$policy instanceof AttendancePolicy) {
            throw new AuthenticationException('Attendance policy not found.');
        }

        return $this->mapToResponse($policy);
    }

    public function createPolicy(?Uuid $instituteId, AttendancePolicyCreateRequestDTO $request): AttendancePolicyResponseDTO
    {
        $this->validateCreatePolicyAccess();

        $existing = $this->policyRepository->findActiveByInstituteAndYear($instituteId, $request->academicYearId ? Uuid::fromString($request->academicYearId) : null);

        if ($request->isActive && $existing instanceof AttendancePolicy) {
            throw new \App\Common\Exception\BusinessException(
                'An active attendance policy already exists for this institute and academic year.'
            );
        }

        $policy = new AttendancePolicy();
        $policy->setMinimumAttendancePercentage($request->minimumAttendancePercentage);
        $policy->setLateThresholdCount($request->lateThresholdCount);
        $policy->setHalfDayBehavior($request->halfDayBehavior);
        $policy->setApprovedLeaveCounts($request->approvedLeaveCounts);
        $policy->setWorkingDays($request->workingDays);
        $policy->setIsActive($request->isActive);
        $policy->setDescription($request->description);

        if ($instituteId !== null) {
            $policy->setInstitute($this->entityManager->getReference(Institute::class, $instituteId));
        }

        if ($request->academicYearId !== null) {
            $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);
            if (!$academicYear instanceof AcademicYear) {
                throw new \InvalidArgumentException('Academic year not found.');
            }

            if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Academic year does not belong to your institute.');
            }

            $policy->setAcademicYear($academicYear);
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->persist($policy);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create attendance policy.', [
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create attendance policy. Please try again.', previous: $e);
        }

        return $this->mapToResponse($policy);
    }

    public function updatePolicy(?Uuid $instituteId, string $id, AttendancePolicyUpdateRequestDTO $request): AttendancePolicyResponseDTO
    {
        $this->validateCreatePolicyAccess();

        $policy = $this->policyRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$policy instanceof AttendancePolicy) {
            throw new AuthenticationException('Attendance policy not found.');
        }

        try {
            $this->entityManager->beginTransaction();

            if ($request->minimumAttendancePercentage !== null) {
                $policy->setMinimumAttendancePercentage($request->minimumAttendancePercentage);
            }

            if ($request->lateThresholdCount !== null) {
                $policy->setLateThresholdCount($request->lateThresholdCount);
            }

            if ($request->halfDayBehavior !== null) {
                $policy->setHalfDayBehavior($request->halfDayBehavior);
            }

            if ($request->approvedLeaveCounts !== null) {
                $policy->setApprovedLeaveCounts($request->approvedLeaveCounts);
            }

            if ($request->workingDays !== null) {
                $policy->setWorkingDays($request->workingDays);
            }

            if ($request->academicYearId !== null) {
                $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);
                if (!$academicYear instanceof AcademicYear) {
                    throw new \InvalidArgumentException('Academic year not found.');
                }

                if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
                    throw new \InvalidArgumentException('Academic year does not belong to your institute.');
                }

                $policy->setAcademicYear($academicYear);
            }

            if ($request->isActive !== null) {
                $policy->setIsActive($request->isActive);
            }

            if ($request->description !== null) {
                $policy->setDescription($request->description);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            if ($e instanceof \App\Common\Exception\BusinessException) {
                throw $e;
            }

            $this->logger->error('Failed to update attendance policy.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update attendance policy. Please try again.', previous: $e);
        }

        return $this->mapToResponse($policy);
    }

    public function deletePolicy(?Uuid $instituteId, string $id): void
    {
        $this->validateCreatePolicyAccess();

        $policy = $this->policyRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$policy instanceof AttendancePolicy) {
            throw new AuthenticationException('Attendance policy not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->remove($policy);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to delete attendance policy.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete attendance policy. Please try again.', previous: $e);
        }
    }

    public function getPolicyForInstitute(?Uuid $instituteId, ?string $academicYearId = null): ?object
    {
        $user = $this->security->getUser();

        $policy = $this->policyRepository->findActiveByInstituteAndYear(
            $instituteId,
            $academicYearId ? Uuid::fromString($academicYearId) : null
        );

        if (!$policy instanceof AttendancePolicy) {
            return null;
        }

        if ($user instanceof User && in_array(UserRole::Student->value, $user->getRoles(), true)) {
            return new StudentAttendancePolicyDTO(
                minimumAttendancePercentage: (string) $policy->getMinimumAttendancePercentage(),
                lateThresholdCount: $policy->getLateThresholdCount(),
                approvedLeaveCounts: $policy->isApprovedLeaveCounts(),
                description: $policy->getDescription(),
            );
        }

        return $this->mapToResponse($policy);
    }

    private function mapToResponse(AttendancePolicy $policy): AttendancePolicyResponseDTO
    {
        $institute = $policy->getInstitute();
        $academicYear = $policy->getAcademicYear();

        return new AttendancePolicyResponseDTO(
            id: (string) $policy->getId(),
            institute: $institute ? new InstituteReferenceDTO(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ) : null,
            academicYear: $academicYear ? new AcademicYearReferenceDTO(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ) : null,
            minimumAttendancePercentage: $policy->getMinimumAttendancePercentage(),
            lateThresholdCount: $policy->getLateThresholdCount(),
            halfDayBehavior: $policy->getHalfDayBehavior(),
            approvedLeaveCounts: $policy->isApprovedLeaveCounts(),
            workingDays: $policy->getWorkingDays(),
            isActive: $policy->isActive(),
            description: $policy->getDescription(),
            createdAt: $policy->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $policy->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function validateCreatePolicyAccess(): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $roles = $user->getRoles();

        if (in_array(UserRole::SuperAdmin->value, $roles, true) || in_array(UserRole::Institute->value, $roles, true)) {
            return;
        }

        throw new AuthenticationException('You do not have permission to create attendance policies.');
    }
}
