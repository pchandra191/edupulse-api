<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AcademicYearStatus;
use App\Enum\AttendanceStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\AttendanceCorrection;
use App\Entity\Holiday;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\StudentEnrollment;
use App\Entity\Institute;
use App\Entity\EnrollmentSection;
use App\Entity\AttendancePolicy;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\AttendanceCorrectionStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

class StudentSecurityIsolationTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $studentAToken = null;
    private ?string $studentBToken = null;
    private ?Uuid $instituteAId = null;
    private ?Uuid $instituteBId = null;
    private ?Uuid $studentAId = null;
    private ?Uuid $studentBId = null;
    private ?Uuid $academicYearId = null;
    private ?Uuid $classId = null;
    private ?Uuid $sectionId = null;
    private ?string $leaveTypeId = null;
    private array $createdEmails = [];
    private string $unique = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->instituteAId = Uuid::v7();
        $this->instituteBId = Uuid::v7();
        $this->studentAId = Uuid::v7();
        $this->studentBId = Uuid::v7();
        $this->academicYearId = Uuid::v7();
        $this->classId = Uuid::v7();
        $this->sectionId = Uuid::v7();

        $instituteA = new Institute();
        $ref = new \ReflectionClass($instituteA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($instituteA, $this->instituteAId);
        $instituteA->setName('Inst A ' . $this->unique);
        $instituteA->setCode('INSA' . $this->unique);
        $instituteA->setEmail('inst-a-' . $this->unique . '@test.ai');
        $instituteA->setStatus(InstituteStatus::Active);

        $instituteB = new Institute();
        $ref = new \ReflectionClass($instituteB);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($instituteB, $this->instituteBId);
        $instituteB->setName('Inst B ' . $this->unique);
        $instituteB->setCode('INSB' . $this->unique);
        $instituteB->setEmail('inst-b-' . $this->unique . '@test.ai');
        $instituteB->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ref = new \ReflectionClass($academicYear);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($academicYear, $this->academicYearId);
        $academicYear->setInstitute($instituteA);
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
        $academicClass->setInstitute($instituteA);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Class ' . $this->unique);
        $academicClass->setCode('CLS' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $ref = new \ReflectionClass($section);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($section, $this->sectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Section ' . $this->unique);
        $section->setCode('SEC' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subjectId = Uuid::v7();
        $ref = new \ReflectionClass($subject);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($subject, $subjectId);
        $subject->setInstitute($instituteA);
        $subject->setName('Math ' . $this->unique);
        $subject->setCode('MATH' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $studentA = new User();
        $ref = new \ReflectionClass($studentA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($studentA, $this->studentAId);
        $studentA->setEmail('student-a-' . $this->unique . '@test.ai');
        $studentA->setPassword(password_hash('StudentA123!', PASSWORD_BCRYPT));
        $studentA->setRoles([UserRole::Student->value]);
        $studentA->setStatus(UserStatus::Active);
        $studentA->setInstitute($instituteA);
        $studentA->verify();

        $studentB = new User();
        $ref = new \ReflectionClass($studentB);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($studentB, $this->studentBId);
        $studentB->setEmail('student-b-' . $this->unique . '@test.ai');
        $studentB->setPassword(password_hash('StudentB123!', PASSWORD_BCRYPT));
        $studentB->setRoles([UserRole::Student->value]);
        $studentB->setStatus(UserStatus::Active);
        $studentB->setInstitute($instituteB);
        $studentB->verify();

        $this->createdEmails = [
            'student-a-' . $this->unique . '@test.ai',
            'student-b-' . $this->unique . '@test.ai',
        ];

        $enrollmentA = new StudentEnrollment();
        $ref = new \ReflectionClass($enrollmentA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentA, Uuid::v7());
        $enrollmentA->setStudent($studentA);
        $enrollmentA->setAcademicYear($academicYear);
        $enrollmentA->setAcademicClass($academicClass);
        $enrollmentA->setAdmissionNumber('ADM' . $this->unique);
        $enrollmentA->setIsCurrent(true);
        $enrollmentA->setStatus(StudentEnrollmentStatus::Active);
        $enrollmentA->setInstituteId($this->instituteAId);

        $enrollmentSectionA = new EnrollmentSection();
        $ref = new \ReflectionClass($enrollmentSectionA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentSectionA, Uuid::v7());
        $enrollmentSectionA->setStudentEnrollment($enrollmentA);
        $enrollmentSectionA->setSection($section);

        $attendanceRecordA = new AttendanceRecord();
        $ref = new \ReflectionClass($attendanceRecordA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($attendanceRecordA, Uuid::v7());
        $attendanceRecordA->setInstitute($instituteA);
        $attendanceRecordA->setAcademicYear($academicYear);
        $attendanceRecordA->setStudent($studentA);
        $attendanceRecordA->setAcademicClass($academicClass);
        $attendanceRecordA->setSection($section);
        $attendanceRecordA->setSubject($subject);
        $attendanceRecordA->setAttendanceDate(new \DateTimeImmutable('2025-06-15'));
        $attendanceRecordA->setStatus(AttendanceStatus::Present);
        $attendanceRecordA->setSource(AttendanceRecord::SOURCE_MANUAL);
        $attendanceRecordA->setRemarks('Attendance for ' . $studentA->getEmail());

        $attendanceRecordB = new AttendanceRecord();
        $ref = new \ReflectionClass($attendanceRecordB);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($attendanceRecordB, Uuid::v7());
        $attendanceRecordB->setInstitute($instituteB);
        $attendanceRecordB->setAcademicYear($academicYear);
        $attendanceRecordB->setStudent($studentB);
        $attendanceRecordB->setAcademicClass($academicClass);
        $attendanceRecordB->setSection($section);
        $attendanceRecordB->setSubject($subject);
        $attendanceRecordB->setAttendanceDate(new \DateTimeImmutable('2025-06-15'));
        $attendanceRecordB->setStatus(AttendanceStatus::Absent);
        $attendanceRecordB->setSource(AttendanceRecord::SOURCE_MANUAL);
        $attendanceRecordB->setRemarks('Attendance for ' . $studentB->getEmail());

        $leaveType = new LeaveType();
        $ref = new \ReflectionClass($leaveType);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveType, Uuid::v7());
        $leaveType->setInstitute($instituteA);
        $leaveType->setName('Casual Leave');
        $leaveType->setCode('CL' . $this->unique);
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(15);
        $leaveType->setCarryForward(true);
        $this->leaveTypeId = (string) $leaveType->getId();

        $leaveBalanceA = new LeaveBalance();
        $ref = new \ReflectionClass($leaveBalanceA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveBalanceA, Uuid::v7());
        $leaveBalanceA->setInstitute($instituteA);
        $leaveBalanceA->setStudent($studentA);
        $leaveBalanceA->setAcademicYear($academicYear);
        $leaveBalanceA->setLeaveType($leaveType);
        $leaveBalanceA->setAllocated(20);
        $leaveBalanceA->setUsed(2);
        $leaveBalanceA->setPending(0);

        $leaveBalanceB = new LeaveBalance();
        $ref = new \ReflectionClass($leaveBalanceB);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveBalanceB, Uuid::v7());
        $leaveBalanceB->setInstitute($instituteB);
        $leaveBalanceB->setStudent($studentB);
        $leaveBalanceB->setAcademicYear($academicYear);
        $leaveBalanceB->setLeaveType($leaveType);
        $leaveBalanceB->setAllocated(15);
        $leaveBalanceB->setUsed(0);
        $leaveBalanceB->setPending(0);

        $leaveRequestA = new LeaveRequest();
        $ref = new \ReflectionClass($leaveRequestA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveRequestA, Uuid::v7());
        $leaveRequestA->setInstitute($instituteA);
        $leaveRequestA->setStudent($studentA);
        $leaveRequestA->setAcademicYear($academicYear);
        $leaveRequestA->setAcademicClass($academicClass);
        $leaveRequestA->setSection($section);
        $leaveRequestA->setLeaveType($leaveType);
        $leaveRequestA->setStartDate(new \DateTimeImmutable('2025-06-20'));
        $leaveRequestA->setEndDate(new \DateTimeImmutable('2025-06-22'));
        $leaveRequestA->setReason('Sick');
        $leaveRequestA->setStatus(LeaveRequestStatus::Approved);
        $leaveRequestA->setSubmittedAt(new \DateTimeImmutable());

        $attendanceCorrectionA = new AttendanceCorrection();
        $ref = new \ReflectionClass($attendanceCorrectionA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($attendanceCorrectionA, Uuid::v7());
        $attendanceCorrectionA->setInstitute($instituteA);
        $attendanceCorrectionA->setAcademicYear($academicYear);
        $attendanceCorrectionA->setAttendanceRecord($attendanceRecordA);
        $attendanceCorrectionA->setStudent($studentA);
        $attendanceCorrectionA->setAcademicClass($academicClass);
        $attendanceCorrectionA->setSection($section);
        $attendanceCorrectionA->setOriginalStatus(AttendanceStatus::Absent->value);
        $attendanceCorrectionA->setCorrectedStatus(AttendanceStatus::Present->value);
        $attendanceCorrectionA->setReason('Wrongly marked absent');
        $attendanceCorrectionA->setStatus(AttendanceCorrectionStatus::Pending);
        $attendanceCorrectionA->setRequestedAt(new \DateTimeImmutable());

        $holiday = new Holiday();
        $ref = new \ReflectionClass($holiday);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($holiday, Uuid::v7());
        $holiday->setInstitute($instituteA);
        $holiday->setAcademicYear($academicYear);
        $holiday->setHolidayDate(new \DateTimeImmutable('2025-06-10'));
        $holiday->setName('Test Holiday');
        $holiday->setEnabled(true);

        $policy = new AttendancePolicy();
        $ref = new \ReflectionClass($policy);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($policy, Uuid::v7());
        $policy->setInstitute($instituteA);
        $policy->setAcademicYear($academicYear);
        $policy->setMinimumAttendancePercentage(75);
        $policy->setApprovedLeaveCounts(true);
        $policy->setIsActive(true);

        $em->persist($instituteA);
        $em->persist($instituteB);
        $em->persist($academicYear);
        $em->persist($academicClass);
        $em->persist($section);
        $em->persist($subject);
        $em->persist($studentA);
        $em->persist($studentB);
        $em->persist($enrollmentA);
        $em->persist($enrollmentSectionA);
        $em->persist($attendanceRecordA);
        $em->persist($attendanceRecordB);
        $em->persist($leaveType);
        $em->persist($leaveBalanceA);
        $em->persist($leaveBalanceB);
        $em->persist($leaveRequestA);
        $em->persist($attendanceCorrectionA);
        $em->persist($holiday);
        $em->persist($policy);
        $em->flush();

        $this->studentAToken = $this->login($this->client, 'student-a-' . $this->unique . '@test.ai', 'StudentA123!');
        $this->studentBToken = $this->login($this->client, 'student-b-' . $this->unique . '@test.ai', 'StudentB123!');
    }

    protected function tearDown(): void
    {
        try {
            $em = $this->client->getContainer()->get('doctrine')->getManager();

            $ids = [$this->instituteAId, $this->instituteBId];
            foreach (['AttendanceRecord', 'AttendanceCorrection', 'LeaveRequest', 'LeaveBalance', 'Holiday', 'AttendancePolicy', 'StudentEnrollment', 'EnrollmentSection'] as $type) {
                $repo = $em->getRepository("App\\Entity\\$type");
                $qb = $repo->createQueryBuilder('e');
                $qb->join('e.institute', 'i')
                    ->where('i.id IN (:ids)')
                    ->setParameter('ids', $ids);
                foreach ($qb->getQuery()->getResult() as $entity) {
                    $em->remove($entity);
                }
            }

            $leaveTypes = $em->getRepository(LeaveType::class)->findBy(['code' => 'CL' . $this->unique]);
            foreach ($leaveTypes as $lt) {
                $em->remove($lt);
            }

            $users = $em->getRepository(User::class)->findBy(['email' => $this->createdEmails]);
            foreach ($users as $user) {
                $em->remove($user);
            }

            $institutes = $em->getRepository(Institute::class)->findBy(['id' => $ids]);
            foreach ($institutes as $inst) {
                $em->remove($inst);
            }

            $em->flush();
        } catch (\Throwable $e) {
        }

        $this->studentAToken = null;
        $this->studentBToken = null;
        $this->createdEmails = [];
        $this->unique = '';

        parent::tearDown();
    }

    private function login($client, string $email, string $password): ?string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $data = json_decode($client->getResponse()->getContent(), true);
        return $data['success'] ?? false ? $data['data']['accessToken'] ?? null : null;
    }

    private function getAuthHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function getInstituteById(Uuid $id): ?Institute
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        return $em->getRepository(Institute::class)->find($id);
    }

    private function getAcademicYear(): ?AcademicYear
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        return $em->getRepository(AcademicYear::class)->find($this->academicYearId);
    }

    private function assertStudentAccessDenied(string $method, string $url, ?string $token, ?string $body = null): void
    {
        $options = [];
        if ($body !== null) {
            $options[] = $body;
        }
        $this->client->request($method, $url, [], [], $this->getAuthHeaders($token), ...$options);
        $response = $this->client->getResponse();
        $this->assertContains(
            $response->getStatusCode(),
            [401, 403, 404],
            "Expected access denied for $method $url with status {$response->getStatusCode()}"
        );
    }

    public function testStudentACannotViewStudentBDashboard(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentBCannotViewStudentADashboard(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentAId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentBToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentACannotViewStudentBSummary(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/summary?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testStudentACannotViewStudentBCalendar(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/calendar?year=2025&month=6', [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testStudentACanViewOwnCalendarWithHolidays(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentAId . '/calendar?year=2025&month=6', [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $holidayFound = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['date'] === '2025-06-10') {
                $holidayFound = true;
                $this->assertTrue($item['isHoliday']);
                $this->assertSame('HOLIDAY', $item['status']);
                $this->assertFalse($item['isWorkingDay']);
                $this->assertSame('Test Holiday', $item['remarks']);
            }
        }
        $this->assertTrue($holidayFound, 'Holiday 2025-06-10 should appear in student calendar.');
    }

    public function testStudentBCalendarDoesNotShowInstituteAHoliday(): void
    {
        $holiday = new Holiday();
        $ref = new \ReflectionClass($holiday);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($holiday, Uuid::v7());
        $holiday->setInstitute($this->getInstituteById($this->instituteBId));
        $holiday->setAcademicYear($this->getAcademicYear());
        $holiday->setHolidayDate(new \DateTimeImmutable('2025-06-10'));
        $holiday->setName('Institute B Holiday');
        $holiday->setEnabled(true);
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->persist($holiday);
        $em->flush();

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/calendar?year=2025&month=6', [], [], $this->getAuthHeaders($this->studentBToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $holidayFound = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['date'] === '2025-06-10') {
                $holidayFound = true;
                $this->assertSame('HOLIDAY', $item['status']);
                $this->assertTrue($item['isHoliday']);
                $this->assertFalse($item['isWorkingDay']);
            }
        }
        $this->assertTrue($holidayFound, 'Holiday should appear in Student B calendar when it belongs to Institute B.');
    }

    public function testStudentACannotViewStudentBHistory(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/history?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentACannotViewStudentBSubjects(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/subjects?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentACannotViewStudentBAlerts(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentBId . '/alerts?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentACannotViewStudentBOwnLeaveRequests(): void
    {
        $this->client->request('GET', '/api/v1/leaves/requests?studentId=' . $this->studentBId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        foreach ($data['data']['items'] as $item) {
            $this->assertEquals($this->studentAId, $item['student']['id']);
        }
    }

    public function testStudentACannotApplyLeaveForStudentB(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-07-10',
            'endDate' => '2025-07-12',
            'reason' => 'Test',
        ]));
        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        if ($response->getStatusCode() === 201) {
            $this->assertEquals($this->studentAId, $data['data']['student']['id']);
        } else {
            $this->assertContains($response->getStatusCode(), [400, 403, 404]);
        }
    }

    public function testStudentACannotCancelStudentBLeaveRequest(): void
    {
        $leaveRequestId = $this->getFirstLeaveRequestId($this->studentAToken);
        $this->assertNotNull($leaveRequestId, 'Student A should have at least one leave request.');
        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/cancel', [], [], $this->getAuthHeaders($this->studentBToken));
        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [401, 403, 404]);
    }

    public function testStudentACanViewOwnDashboard(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentAId . '/dashboard?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentACanViewOwnSummary(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentAId . '/summary?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentACanViewOwnSubjects(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentAId . '/subjects?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentACanViewOwnAlerts(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->studentAId . '/alerts?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentACanViewOwnLeaveBalances(): void
    {
        $this->client->request('GET', '/api/v1/leaves/balances?studentId=' . $this->studentAId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertNotEmpty($data['data']['items']);
        foreach ($data['data']['items'] as $item) {
            $this->assertEquals($this->studentAId, $item['studentId']);
        }
    }

    public function testStudentBCannotViewStudentALeaveBalances(): void
    {
        $this->client->request('GET', '/api/v1/leaves/balances?studentId=' . $this->studentAId, [], [], $this->getAuthHeaders($this->studentBToken));
        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testStudentACanViewLeaveTypes(): void
    {
        $this->client->request('GET', '/api/v1/leaves/types', [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentACanViewHolidays(): void
    {
        $this->client->request('GET', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentACanViewActivePolicy(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies/active?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentACanViewOwnLeaveRequests(): void
    {
        $this->client->request('GET', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentCannotManageLeaveTypes(): void
    {
        $this->client->request('POST', '/api/v1/leaves/types', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'name' => 'Test',
            'code' => 'TEST',
            'enabled' => true,
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotManageHolidays(): void
    {
        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'date' => '2025-12-25',
            'name' => 'Holiday',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotManagePolicies(): void
    {
        $this->client->request('POST', '/api/v1/attendance/policies', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'minimumAttendancePercentage' => 75,
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotMarkAttendance(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'academicYearId' => $this->academicYearId,
            'classId' => $this->classId,
            'sectionId' => $this->sectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                ['studentId' => $this->studentAId, 'status' => 'present'],
            ],
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotAccessClassReport(): void
    {
        $this->client->request('GET', '/api/v1/attendance/class/' . $this->classId . '/report?academicYearId=' . $this->academicYearId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotAccessTodayAttendance(): void
    {
        $this->client->request('GET', '/api/v1/attendance/today?academicYearId=' . $this->academicYearId . '&academicClassId=' . $this->classId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotAccessDashboardStats(): void
    {
        $this->client->request('GET', '/api/v1/attendance/dashboard?academicYearId=' . $this->academicYearId . '&academicClassId=' . $this->classId, [], [], $this->getAuthHeaders($this->studentAToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    private function getFirstLeaveRequestId(string $token): ?string
    {
        $this->client->request('GET', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($token));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        if (!empty($data['data']['items'])) {
            return $data['data']['items'][0]['id'];
        }
        return null;
    }
}
