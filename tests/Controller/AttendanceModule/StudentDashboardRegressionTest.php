<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AttendanceStatus;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\SectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\Timezone;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class StudentDashboardRegressionTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private string $unique = '';
    private ?Uuid $instituteId = null;
    private ?Uuid $academicYearId = null;
    private ?Uuid $classId = null;
    private ?Uuid $sectionId = null;
    private ?Uuid $studentId = null;
    private ?string $studentToken = null;

    protected function setUp(): void
    {
        $mockClock = new MockClock(new \DateTimeImmutable('2026-09-19 12:00:00', new \DateTimeZone('UTC')));
        \Symfony\Component\Clock\Clock::set($mockClock);

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->instituteId = Uuid::v7();
        $this->academicYearId = Uuid::v7();
        $this->classId = Uuid::v7();
        $this->sectionId = Uuid::v7();
        $this->studentId = Uuid::v7();

        $institute = new Institute();
        $ref = new \ReflectionClass($institute);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($institute, $this->instituteId);
        $institute->setName('Regression ' . $this->unique);
        $institute->setCode('RG' . $this->unique);
        $institute->setEmail('regression-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);
        $institute->setTimezone(Timezone::INR);

        $academicYear = new AcademicYear();
        $ref = new \ReflectionClass($academicYear);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($academicYear, $this->academicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('AY ' . $this->unique);
        $academicYear->setCode('AY' . $this->unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2026-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $academicClass = new AcademicClass();
        $ref = new \ReflectionClass($academicClass);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($academicClass, $this->classId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Class ' . $this->unique);
        $academicClass->setCode('CL' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);
        $academicClass->setStartDate(new \DateTimeImmutable('2026-01-02'));
        $academicClass->setEndDate(new \DateTimeImmutable('2026-12-24'));

        $section = new Section();
        $ref = new \ReflectionClass($section);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($section, $this->sectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Section ' . $this->unique);
        $section->setCode('SC' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $student = new User();
        $ref = new \ReflectionClass($student);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($student, $this->studentId);
        $student->setEmail('student-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $enrollment = new StudentEnrollment();
        $ref = new \ReflectionClass($enrollment);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('ADM' . $this->unique);
        $enrollment->setRollNumber('RN' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->instituteId);
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2026-04-23'));

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $ref = new \ReflectionClass($enrollmentSection);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentSection, Uuid::v7());
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($section);

        $leaveType = new LeaveType();
        $ref = new \ReflectionClass($leaveType);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveType, Uuid::v7());
        $leaveType->setInstitute($institute);
        $leaveType->setName('Medical Leave ' . $this->unique);
        $leaveType->setCode('ML' . $this->unique);
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(15);

        $leaveRequest = new LeaveRequest();
        $ref = new \ReflectionClass($leaveRequest);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveRequest, Uuid::v7());
        $leaveRequest->setInstitute($institute);
        $leaveRequest->setAcademicYear($academicYear);
        $leaveRequest->setStudent($student);
        $leaveRequest->setAcademicClass($academicClass);
        $leaveRequest->setSection($section);
        $leaveRequest->setLeaveType($leaveType);
        $leaveRequest->setStartDate(new \DateTimeImmutable('2026-09-14'));
        $leaveRequest->setEndDate(new \DateTimeImmutable('2026-09-16'));
        $leaveRequest->setReason('Medical leave');
        $leaveRequest->setStatus(LeaveRequestStatus::Approved);
        $leaveRequest->setSubmittedAt(new \DateTimeImmutable());

        $em->persist($institute);
        $em->persist($academicYear);
        $em->persist($academicClass);
        $em->persist($section);
        $em->persist($student);
        $em->persist($enrollment);
        $em->persist($enrollmentSection);
        $em->persist($leaveType);
        $em->persist($leaveRequest);
        $em->flush();

        $this->studentToken = $this->login('student-' . $this->unique . '@edupulse.ai', 'Student123!');
    }

    public function testOverallSummaryCountsExplicitRecords(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        // 3 PRESENT records on 3 different weekday dates in September
        // All within the same month/reporting period (Apr 23 - Sep 19)
        foreach ([
            '2026-09-03', // Thursday
            '2026-09-10', // Thursday
            '2026-09-17', // Thursday (also a holiday - explicit record must take priority)
        ] as $dateStr) {
            $record = new AttendanceRecord();
            $record->setInstitute($em->find(Institute::class, $this->instituteId));
            $record->setAcademicYear($em->find(AcademicYear::class, $this->academicYearId));
            $record->setStudent($em->find(User::class, $this->studentId));
            $record->setAcademicClass($em->find(AcademicClass::class, $this->classId));
            $record->setAttendanceDate(new \DateTimeImmutable($dateStr));
            $record->setStatus(AttendanceStatus::Present);
            $record->setSource(AttendanceRecord::SOURCE_SYSTEM);
            $em->persist($record);
        }

        // Sep 17 is also a holiday - explicit record must take priority, NOT be counted as holiday
        $holiday = new Holiday();
        $ref = new \ReflectionClass($holiday);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($holiday, Uuid::v7());
        $holiday->setInstitute($em->find(Institute::class, $this->instituteId));
        $holiday->setAcademicYear($em->find(AcademicYear::class, $this->academicYearId));
        $holiday->setHolidayDate(new \DateTimeImmutable('2026-09-17'));
        $holiday->setName('Vishkarma Puja');
        $holiday->setEnabled(true);
        $em->persist($holiday);
        $em->flush();

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->studentToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $summary = $data['data']['summary'];

        // 3 PRESENT records on 3 different weekday dates → present must be 3
        // This is the critical regression check: explicit PRESENT records must NOT be lost
        $this->assertSame(3, $summary['present'], '3 PRESENT records on 3 dates must yield present=3');
        $this->assertSame(3, $data['data']['monthlySummary']['present'], 'monthlySummary.present must be 3');

        // Reconciliation: present + absent + onLeave + holiday must equal totalDays
        $totalDays = $summary['totalDays'];
        $categorySum = $summary['present'] + $summary['absent'] + $summary['onLeave'] + $summary['holiday'];
        $this->assertSame($categorySum, $totalDays, 'Category counts must sum to totalDays');

        // totalWorkingDays = totalDays - holiday
        $this->assertSame($totalDays - $summary['holiday'], $summary['totalWorkingDays'], 'totalWorkingDays must exclude holidays');

        // Holiday on Sep 17 is NOT counted as holiday because explicit PRESENT takes priority
        $this->assertSame(0, $summary['holiday'], 'Holiday with explicit record must not be counted as holiday');
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['data']['accessToken'] ?? '';
    }

    protected function tearDown(): void
    {
        try {
            $em = $this->client->getContainer()->get('doctrine')->getManager();
            $types = ['AttendanceRecord', 'AttendanceCorrection', 'LeaveRequest', 'Holiday', 'StudentEnrollment', 'EnrollmentSection', 'LeaveType'];
            foreach ($types as $type) {
                $repo = $em->getRepository("App\\Entity\\$type");
                $qb = $repo->createQueryBuilder('e')
                    ->where('e.institute = :inst')
                    ->setParameter('inst', $this->instituteId);
                foreach ($qb->getQuery()->getResult() as $entity) {
                    $em->remove($entity);
                }
            }
            $em->getRepository(User::class)->createQueryBuilder('u')
                ->delete('App\Entity\User', 'u')
                ->where('u.email LIKE :email')
                ->setParameter('email', '%' . $this->unique . '@edupulse.ai')
                ->getQuery()
                ->execute();
            $em->getRepository(AcademicClass::class)->createQueryBuilder('c')
                ->delete('App\Entity\AcademicClass', 'c')
                ->where('c.id = :id')
                ->setParameter('id', $this->classId)
                ->getQuery()
                ->execute();
            $em->getRepository(AcademicYear::class)->createQueryBuilder('a')
                ->delete('App\Entity\AcademicYear', 'a')
                ->where('a.id = :id')
                ->setParameter('id', $this->academicYearId)
                ->getQuery()
                ->execute();
            $em->getRepository(Institute::class)->createQueryBuilder('i')
                ->delete('App\Entity\Institute', 'i')
                ->where('i.id = :id')
                ->setParameter('id', $this->instituteId)
                ->getQuery()
                ->execute();
            $em->flush();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }
}
