<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\Entity\Institute;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

readonly class AttendanceDateTimeProvider
{
    public function __construct(
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveTimezone(?Uuid $instituteId = null): string
    {
        if ($instituteId === null) {
            return 'UTC';
        }

        $institute = $this->entityManager->find(Institute::class, $instituteId);

        return $this->resolveTimezoneForInstitute($institute);
    }

    public function resolveTimezoneForInstitute(?Institute $institute): string
    {
        if ($institute !== null && $institute->getTimezone() !== null) {
            return $institute->getTimezone()->value;
        }

        return 'UTC';
    }

    public function currentBusinessDate(?Uuid $instituteId = null): \DateTimeImmutable
    {
        $timezone = $this->resolveTimezone($instituteId);

        return $this->clock->now()->setTimezone(new \DateTimeZone($timezone))->setTime(0, 0, 0);
    }

    public function currentBusinessDateForInstitute(?Institute $institute): \DateTimeImmutable
    {
        $timezone = $this->resolveTimezoneForInstitute($institute);

        return $this->clock->now()->setTimezone(new \DateTimeZone($timezone))->setTime(0, 0, 0);
    }
}
