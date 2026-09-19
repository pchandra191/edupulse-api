<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AcademicYearStatus;
use App\Enum\AttendanceStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\SectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\Timezone;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class AttendanceTimezoneRegressionTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private string $unique = '';
    private string $studentEmail = '';
    private string $studentPassword = 'Student123!';
    private string $teacherEmail = '';
    private string $teacherPassword = 'Teacher123!';
    private string $superAdminEmail = '';
    private string $superAdminPassword = 'SuperAdmin123!';

    private ?Uuid $instituteId = null;
    private ?Uuid $academicYearId = null;
    private ?Uuid $classId = null;
    private ?Uuid $sectionId = null;
    private ?Uuid $studentId = null;
    private ?Uuid $teacherId = null;
    private ?Uuid $leaveTypeId = null;
    private ?Uuid $leaveBalanceId = null;

    private ?string $superAdminToken = null;

    private const CHECK_IN_LAT = 23.123456;
    private const CHECK_IN_LNG = 86.123456;
    private const CHECK_IN_ACC = 12.5;

    private const IST_TZ = 'Asia/Kolkata';
    private const LEAVE_START_DATE = '2026-09-14';
    private const LEAVE_END_DATE = '2026-09-16';

    protected function setUp(): void
    {
        // Set the global clock BEFORE creating the test client.
        // The test environment aliases ClockInterface → Clock (see services_test.yaml),
        // so Clock::set() will be seen by ALL services, including those created
        // during HTTP sub-requests.
        // UTC 2026-09-16 20:27:00 → IST (Asia/Kolkata, +5:30) = 2026-09-17 01:57:00
        // So the business date for IST institute is 2026-09-17.
        $mockClock = new MockClock(
            new \DateTimeImmutable('2026-09-16 20:27:00', new \DateTimeZone('UTC'))
        );
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
        $this->teacherId = Uuid::v7();
        $this->leaveTypeId = Uuid::v7();
        $this->leaveBalanceId = Uuid::v7();

        $this->studentEmail = 'tz-student-' . $this->unique . '@edupulse.ai';
        $this->teacherEmail = 'tz-teacher-' . $this->unique . '@edupulse.ai';
        $this->superAdminEmail = 'tz-superadmin-' . $this->unique . '@edupulse.ai';

        $institute = new Institute();
        $ref = new \ReflectionClass($institute);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($institute, $this->instituteId);
        $institute->setName('TZ Institute ' . $this->unique);
        $institute->setCode('TZINST' . $this->unique);
        $institute->setEmail('tz-inst-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);
        $institute->setTimezone(Timezone::INR);

        $academicYear = new AcademicYear();
        $ref = new \ReflectionClass($academicYear);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($academicYear, $this->academicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('AY ' . $this->unique);
        $academicYear->setCode('AY' . $this->unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $academicClass = new AcademicClass();
        $ref = new \ReflectionClass($academicClass);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($academicClass, $this->classId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Class TZ ' . $this->unique);
        $academicClass->setCode('TZCLS' . $this->unique);
        $academicClass->setStatus(\App\Enum\ClassStatus::Active);
        $academicClass->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicClass->setEndDate(new \DateTimeImmutable('2025-12-31'));

        $section = new Section();
        $ref = new \ReflectionClass($section);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($section, $this->sectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Section TZ ' . $this->unique);
        $section->setCode('TZSEC' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $superAdmin = new User();
        $ref = new \ReflectionClass($superAdmin);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($superAdmin, Uuid::v7());
        $superAdmin->setEmail($this->superAdminEmail);
        $superAdmin->setPassword(password_hash($this->superAdminPassword, PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $teacher = new User();
        $ref = new \ReflectionClass($teacher);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($teacher, $this->teacherId);
        $teacher->setEmail($this->teacherEmail);
        $teacher->setPassword(password_hash($this->teacherPassword, PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);
        $teacher->setFirstName('TZ');
        $teacher->setLastName('Teacher');

        $student = new User();
        $ref = new \ReflectionClass($student);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($student, $this->studentId);
        $student->setEmail($this->studentEmail);
        $student->setPassword(password_hash($this->studentPassword, PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);
        $student->setFirstName('TZ');
        $student->setLastName('Student');

        $enrollment = new StudentEnrollment();
        $ref = new \ReflectionClass($enrollment);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('TZAD' . $this->unique);
        $enrollment->setRollNumber('TZRA' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->instituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $ref = new \ReflectionClass($enrollmentSection);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentSection, Uuid::v7());
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($section);

        $teacherAssignment = new TeacherAssignment();
        $ref = new \ReflectionClass($teacherAssignment);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($teacherAssignment, Uuid::v7());
        $teacherAssignment->setTeacher($teacher);
        $teacherAssignment->setAcademicClass($academicClass);
        $teacherAssignment->setSection($section);
        $teacherAssignment->setAcademicYear($academicYear);
        $teacherAssignment->setAssignmentType('CLASS_TEACHER');

        $leaveType = new LeaveType();
        $ref = new \ReflectionClass($leaveType);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveType, $this->leaveTypeId);
        $leaveType->setInstitute($institute);
        $leaveType->setName('Medical Leave ' . $this->unique);
        $leaveType->setCode('TZML' . $this->unique);
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(15);

        $leaveBalance = new LeaveBalance();
        $ref = new \ReflectionClass($leaveBalance);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveBalance, $this->leaveBalanceId);
        $leaveBalance->setInstitute($institute);
        $leaveBalance->setStudent($student);
        $leaveBalance->setAcademicYear($academicYear);
        $leaveBalance->setLeaveType($leaveType);
        $leaveBalance->setAllocated(15);
        $leaveBalance->setUsed(0);
        $leaveBalance->setPending(0);

        $em->persist($institute);
        $em->persist($academicYear);
        $em->persist($academicClass);
        $em->persist($section);
        $em->persist($teacher);
        $em->persist($student);
        $em->persist($enrollment);
        $em->persist($enrollmentSection);
        $em->persist($teacherAssignment);
        $em->persist($leaveType);
        $em->persist($leaveBalance);
        $em->flush();

        $this->superAdminToken = $this->login($this->superAdminEmail, $this->superAdminPassword);
    }

    protected function tearDown(): void
    {
        try {
            $em = $this->client->getContainer()->get('doctrine')->getManager();

            $types = ['AttendanceRecord', 'AttendanceCorrection', 'LeaveRequest', 'LeaveBalance', 'AttendancePolicy', 'Holiday'];
            foreach ($types as $type) {
                $repo = $em->getRepository("App\\Entity\\$type");
                $qb = $repo->createQueryBuilder('e')
                    ->where('e.institute = :inst')
                    ->setParameter('inst', $this->instituteId);
                foreach ($qb->getQuery()->getResult() as $entity) {
                    $em->remove($entity);
                }
            }

            $taRepo = $em->getRepository(TeacherAssignment::class);
            foreach ($taRepo->createQueryBuilder('ta')
                ->join('ta.academicClass', 'c')
                ->where('c.institute = :inst')
                ->setParameter('inst', $this->instituteId)
                ->getQuery()->getResult() as $assignment) {
                $em->remove($assignment);
            }

            $seRepo = $em->getRepository(\App\Entity\EnrollmentSection::class);
            foreach ($seRepo->createQueryBuilder('es')
                ->join('es.studentEnrollment', 'se')
                ->join('se.academicClass', 'c')
                ->where('c.institute = :inst')
                ->setParameter('inst', $this->instituteId)
                ->getQuery()->getResult() as $es) {
                $em->remove($es);
            }

            $sr = $em->getRepository(\App\Entity\StudentEnrollment::class);
            foreach ($em->getRepository(\App\Entity\StudentEnrollment::class)
                ->createQueryBuilder('se')
                ->join('se.academicClass', 'c')
                ->where('c.institute = :inst')
                ->setParameter('inst', $this->instituteId)
                ->getQuery()->getResult() as $enrollment) {
                $em->remove($enrollment);
            }

            $userRepo = $em->getRepository(User::class);
            foreach ($userRepo->findBy(['email' => [$this->studentEmail, $this->teacherEmail, $this->superAdminEmail]]) as $user) {
                $em->remove($user);
            }

            $inst = $em->find(Institute::class, $this->instituteId);
            if ($inst) {
                $em->remove($inst);
            }

            $em->flush();
        } catch (\Throwable $e) {
        }

         $this->client = null;
        $this->superAdminToken = null;

        parent::tearDown();
    }

    public function testBusinessDateResolvesInInstituteTimezone(): void
    {
        $provider = $this->client->getContainer()->get(\App\Service\AttendanceModule\AttendanceDateTimeProvider::class);
        $businessDate = $provider->currentBusinessDate($this->instituteId);

        $this->assertSame('2026-09-17', $businessDate->format('Y-m-d'));
    }

    public function testLeaveEndedYesterdayDoesNotBlockCheckIn(): void
    {
        $this->createApprovedLeaveRequest();

        $token = $this->login($this->studentEmail, $this->studentPassword);
        $this->assertNotNull($token, 'Student token should be available.');

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'latitude' => self::CHECK_IN_LAT,
            'longitude' => self::CHECK_IN_LNG,
            'accuracyMeters' => self::CHECK_IN_ACC,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('2026-09-17', $data['data']['attendanceDate']);
    }

    public function testLeaveEndedYesterdayDoesNotShowOnLeaveOnDashboard(): void
    {
        $this->createApprovedLeaveRequest();

        $token = $this->login($this->studentEmail, $this->studentPassword);
        $this->assertNotNull($token, 'Student token should be available.');

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $todayAttendance = $data['data']['todayAttendance'] ?? null;
        $this->assertNotNull($todayAttendance, 'todayAttendance should be present');
        $this->assertSame(0, $todayAttendance['onLeave'], 'Student should NOT be on leave for 17 Sep (leave ended 16 Sep)');
    }

    public function testLeaveCurrentlyActiveShowsOnLeave(): void
    {
        $this->createApprovedLeaveRequest(
            '2026-09-17',
            '2026-09-17'
        );

        $token = $this->login($this->studentEmail, $this->studentPassword);
        $this->assertNotNull($token, 'Student token should be available.');

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $todayAttendance = $data['data']['todayAttendance'] ?? null;
        $this->assertNotNull($todayAttendance);
        $this->assertGreaterThanOrEqual(1, $todayAttendance['onLeave'], 'Student should be on leave for 17 Sep (IST business date)');

        // Check-in should be blocked
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'latitude' => self::CHECK_IN_LAT,
            'longitude' => self::CHECK_IN_LNG,
            'accuracyMeters' => self::CHECK_IN_ACC,
        ]));

        $checkInResponse = $this->client->getResponse();
        $this->assertContains($checkInResponse->getStatusCode(), [403, 422], 'Check-in should be blocked while on leave');
    }

    public function testHolidayOnBusinessDateIsRecognized(): void
    {
        $this->createHoliday('2026-09-17', 'TZ Holiday ' . $this->unique);

        $provider = $this->client->getContainer()->get(\App\Service\AttendanceModule\AttendanceDateTimeProvider::class);
        $businessDate = $provider->currentBusinessDate($this->instituteId);
        $holidayRepo = $this->client->getContainer()->get('doctrine')->getManager()->getRepository(Holiday::class);

        $this->assertTrue(
            $holidayRepo->isHoliday($businessDate, $this->instituteId, $this->academicYearId),
            'Holiday on 17 Sep should be recognized as holiday for business date'
        );
    }

    public function testHolidayOnPreviousDayDoesNotAffectBusinessDate(): void
    {
        $this->createHoliday('2026-09-16', 'Previous Day Holiday ' . $this->unique);

        $provider = $this->client->getContainer()->get(\App\Service\AttendanceModule\AttendanceDateTimeProvider::class);
        $businessDate = $provider->currentBusinessDate($this->instituteId);
        $holidayRepo = $this->client->getContainer()->get('doctrine')->getManager()->getRepository(Holiday::class);

        $this->assertFalse(
            $holidayRepo->isHoliday($businessDate, $this->instituteId, $this->academicYearId),
            'Holiday on 16 Sep should NOT be recognized as holiday for business date 17 Sep'
        );
    }

    public function testCheckInAndDashboardUseSameBusinessDate(): void
    {
        $token = $this->login($this->studentEmail, $this->studentPassword);
        $this->assertNotNull($token);

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'latitude' => self::CHECK_IN_LAT,
            'longitude' => self::CHECK_IN_LNG,
            'accuracyMeters' => self::CHECK_IN_ACC,
        ]));

        $checkInResponse = json_decode($this->client->getResponse()->getContent(), true);
        $checkInDate = $checkInResponse['data']['attendanceDate'] ?? null;

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $dashboardResponse = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame('2026-09-17', $checkInDate);
        $this->assertNotNull($dashboardResponse['data']['todayAttendance']);
    }

    public function testUtcTimeDoesNotMismatchBusinessDate(): void
    {
        $container = $this->client->getContainer();

        // Use the frozen clock, not the real system time
        $mockClock = $container->get('clock');
        $utcNow = $mockClock->now()->setTimezone(new \DateTimeZone('UTC'));
        $utcDate = $utcNow->format('Y-m-d');
        $istNow = $mockClock->now()->setTimezone(new \DateTimeZone(self::IST_TZ));
        $istDate = $istNow->format('Y-m-d');

        $provider = $container->get(\App\Service\AttendanceModule\AttendanceDateTimeProvider::class);
        $businessDate = $provider->currentBusinessDate($this->instituteId);

        if ($utcDate !== $istDate) {
            $this->assertSame($istDate, $businessDate->format('Y-m-d'),
                'Business date (' . $businessDate->format('Y-m-d') . ') should match IST date (' . $istDate . ') not UTC date (' . $utcDate . ')');
        } else {
            $this->assertSame($istDate, $businessDate->format('Y-m-d'));
        }
    }

    private function createApprovedLeaveRequest(string $startDate = self::LEAVE_START_DATE, string $endDate = self::LEAVE_END_DATE): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $student = $em->find(User::class, $this->studentId);
        $academicYear = $em->find(AcademicYear::class, $this->academicYearId);
        $class = $em->find(AcademicClass::class, $this->classId);
        $section = $em->find(Section::class, $this->sectionId);

        $leaveType = $em->find(LeaveType::class, $this->leaveTypeId);

        $leaveRequest = new LeaveRequest();
        $ref = new \ReflectionClass($leaveRequest);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveRequest, Uuid::v7());
        $leaveRequest->setInstitute($em->find(Institute::class, $this->instituteId));
        $leaveRequest->setAcademicYear($academicYear);
        $leaveRequest->setStudent($student);
        $leaveRequest->setAcademicClass($class);
        $leaveRequest->setSection($section);
        $leaveRequest->setLeaveType($leaveType);
        $leaveRequest->setStartDate(\DateTimeImmutable::createFromFormat('Y-m-d', $startDate));
        $leaveRequest->setEndDate(\DateTimeImmutable::createFromFormat('Y-m-d', $endDate));
        $leaveRequest->setReason('Medical leave');
        $leaveRequest->setStatus(LeaveRequestStatus::Approved);
        $leaveRequest->setSubmittedAt(new \DateTimeImmutable());

        $em->persist($leaveRequest);
        $em->flush();

        $balance = $em->find(LeaveBalance::class, $this->leaveBalanceId);
        if ($balance) {
            $balance->setUsed($balance->getUsed() + $leaveRequest->getTotalDays());
            $em->flush();
        }
    }

    private function createHoliday(string $dateStr, string $name): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $holiday = new Holiday();
        $ref = new \ReflectionClass($holiday);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($holiday, Uuid::v7());
        $holiday->setInstitute($em->find(Institute::class, $this->instituteId));
        $holiday->setAcademicYear($em->find(AcademicYear::class, $this->academicYearId));
        $holiday->setHolidayDate(\DateTimeImmutable::createFromFormat('Y-m-d', $dateStr));
        $holiday->setName($name);
        $holiday->setEnabled(true);

        $em->persist($holiday);
        $em->flush();
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['data']['accessToken'] ?? '';
    }
}
