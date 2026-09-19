<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\AttendanceStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\AttendancePolicy;
use App\Entity\LeaveType;
use App\Entity\StudentEnrollment;
use App\Entity\AttendanceCorrection;
use App\Entity\Institute;
use App\Entity\EnrollmentSection;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\StudentEnrollmentStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

class AttendanceControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $superAdminToken = null;
    private ?string $teacherToken = null;
    private ?string $studentToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testStudentId = null;
    private ?Uuid $testTeacherId = null;
    private ?string $testStudentRollNumber = null;
    private array $createdEmails = [];
    private string $unique = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSectionId = Uuid::v7();
        $this->testStudentId = Uuid::v7();
        $this->testTeacherId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-attendance-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Attendance');
        $institute->setCode('TESTATT' . $this->unique);
        $institute->setEmail('test-attendance-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year ' . $this->unique);
        $academicYear->setCode('AY' . $this->unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $academicClass = new AcademicClass();
        $classReflection = new \ReflectionClass($academicClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($academicClass, $this->testClassId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Test Class ' . $this->unique);
        $academicClass->setCode('CLS' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section ' . $this->unique);
        $section->setCode('SEC' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subjectId = Uuid::v7();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $subjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject ' . $this->unique);
        $subject->setCode('SUB' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $teacher = new User();
        $teacherEmail = 'teacher-attendance-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $teacherEmail;
        $teacherReflection = new \ReflectionClass($teacher);
        $teacherProperty = $teacherReflection->getProperty('id');
        $teacherProperty->setAccessible(true);
        $teacherProperty->setValue($teacher, $this->testTeacherId);
        $teacher->setEmail($teacherEmail);
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);
        $teacher->setFirstName('Test');
        $teacher->setLastName('Teacher');

        $student = new User();
        $studentEmail = 'student-attendance-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $studentEmail;
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $this->testStudentId);
        $student->setEmail($studentEmail);
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);
        $student->setFirstName('Test');
        $student->setLastName('Student');

        $studentEnrollment = new StudentEnrollment();
        $enrollmentReflection = new \ReflectionClass($studentEnrollment);
        $enrollmentProperty = $enrollmentReflection->getProperty('id');
        $enrollmentProperty->setAccessible(true);
        $enrollmentProperty->setValue($studentEnrollment, Uuid::v7());
        $studentEnrollment->setStudent($student);
        $studentEnrollment->setAcademicYear($academicYear);
        $studentEnrollment->setAcademicClass($academicClass);
        $studentEnrollment->setAdmissionNumber('ADM' . $this->unique);
        $studentEnrollment->setRollNumber('R' . $this->unique);
        $studentEnrollment->setIsCurrent(true);
        $studentEnrollment->setStatus(StudentEnrollmentStatus::Active);
        $studentEnrollment->setInstituteId($this->testInstituteId);
        $this->testStudentRollNumber = 'R' . $this->unique;

        $enrollmentSection = new EnrollmentSection();
        $esReflection = new \ReflectionClass($enrollmentSection);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($enrollmentSection, Uuid::v7());
        $enrollmentSection->setStudentEnrollment($studentEnrollment);
        $enrollmentSection->setSection($section);

        $teacherAssignment = new TeacherAssignment();
        $taReflection = new \ReflectionClass($teacherAssignment);
        $taProperty = $taReflection->getProperty('id');
        $taProperty->setAccessible(true);
        $taProperty->setValue($teacherAssignment, Uuid::v7());
        $teacherAssignment->setTeacher($teacher);
        $teacherAssignment->setAcademicClass($academicClass);
        $teacherAssignment->setSection($section);
        $teacherAssignment->setSubject($subject);
        $teacherAssignment->setAcademicYear($academicYear);
        $teacherAssignment->setAssignmentType('CLASS_TEACHER');


        $leaveType = new LeaveType();
        $leaveTypeId = Uuid::v7();
        $ltReflection = new \ReflectionClass($leaveType);
        $ltProperty = $ltReflection->getProperty('id');
        $ltProperty->setAccessible(true);
        $ltProperty->setValue($leaveType, $leaveTypeId);
        $leaveType->setInstitute($institute);
        $leaveType->setName('Medical Leave ' . $this->unique);
        $leaveType->setCode('ML' . $this->unique);
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(10);

        $holiday = new \App\Entity\Holiday();
        $holidayReflection = new \ReflectionClass($holiday);
        $holidayProperty = $holidayReflection->getProperty('id');
        $holidayProperty->setAccessible(true);
        $holidayProperty->setValue($holiday, Uuid::v7());
        $holiday->setInstitute($institute);
        $holiday->setAcademicYear($academicYear);
        $holiday->setHolidayDate(new \DateTimeImmutable('2025-06-15'));
        $holiday->setName('Test Holiday ' . $this->unique);
        $holiday->setEnabled(true);

        $leaveBalance = new \App\Entity\LeaveBalance();
        $lbReflection = new \ReflectionClass($leaveBalance);
        $lbProperty = $lbReflection->getProperty('id');
        $lbProperty->setAccessible(true);
        $lbProperty->setValue($leaveBalance, Uuid::v7());
        $leaveBalance->setInstitute($institute);
        $leaveBalance->setStudent($student);
        $leaveBalance->setAcademicYear($academicYear);
        $leaveBalance->setLeaveType($leaveType);
        $leaveBalance->setAllocated(10);
        $leaveBalance->setUsed(0);
        $leaveBalance->setPending(0);

        $this->createdEmails[] = 'superadmin-attendance-test-' . $this->unique . '@edupulse.ai';
        $this->leaveTypeId = (string) $leaveTypeId;

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($teacher);
        $entityManager->persist($student);
        $entityManager->persist($studentEnrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->persist($teacherAssignment);
        $entityManager->persist($leaveType);
        $entityManager->persist($leaveBalance);
        $entityManager->persist($holiday);
        $entityManager->flush();

        $this->superAdminToken = $this->login($this->client, 'superadmin-attendance-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->teacherToken = $this->login($this->client, $teacherEmail, 'Teacher123!');
        $this->studentToken = $this->login($this->client, $studentEmail, 'Student123!');
    }

    private ?string $leaveTypeId = null;

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $arRepo = $entityManager->getRepository(AttendanceRecord::class);
            $arQb = $arRepo->createQueryBuilder('ar')
                ->where('ar.institute = :institute')
                ->setParameter('institute', $this->testInstituteId);
            $attendanceRecords = $arQb->getQuery()->getResult();
            foreach ($attendanceRecords as $record) {
                $entityManager->remove($record);
            }

            $acRepo = $entityManager->getRepository(AttendanceCorrection::class);
            $acQb = $acRepo->createQueryBuilder('ac')
                ->where('ac.institute = :institute')
                ->setParameter('institute', $this->testInstituteId);
            $corrections = $acQb->getQuery()->getResult();
            foreach ($corrections as $correction) {
                $entityManager->remove($correction);
            }

            $apRepo = $entityManager->getRepository(AttendancePolicy::class);
            $apQb = $apRepo->createQueryBuilder('ap')
                ->where('ap.institute = :institute')
                ->setParameter('institute', $this->testInstituteId);
            $policies = $apQb->getQuery()->getResult();
            foreach ($policies as $policy) {
                $entityManager->remove($policy);
            }

            $ltRepo = $entityManager->getRepository(LeaveType::class);
            $leaveTypes = $ltRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($leaveTypes as $lt) {
                $entityManager->remove($lt);
            }

            $lbRepo = $entityManager->getRepository(\App\Entity\LeaveBalance::class);
            $leaveBalances = $lbRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($leaveBalances as $lb) {
                $entityManager->remove($lb);
            }

            $lrRepo = $entityManager->getRepository(\App\Entity\LeaveRequest::class);
            $leaveRequests = $lrRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($leaveRequests as $lr) {
                $entityManager->remove($lr);
            }

            $holidayRepo = $entityManager->getRepository(\App\Entity\Holiday::class);
            $holidays = $holidayRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($holidays as $holiday) {
                $entityManager->remove($holiday);
            }

            $userRepo = $entityManager->getRepository(User::class);
            $users = $userRepo->findBy(['email' => $this->createdEmails]);
            foreach ($users as $user) {
                $entityManager->remove($user);
            }

            $instituteRepo = $entityManager->getRepository(Institute::class);
            if ($this->testInstituteId) {
                $institute = $instituteRepo->find($this->testInstituteId);
                if ($institute) {
                    $entityManager->remove($institute);
                }
            }

            $entityManager->flush();
        } catch (\Throwable $e) {
        }

        $this->superAdminToken = null;
        $this->teacherToken = null;
        $this->studentToken = null;
        $this->testInstituteId = null;
        $this->testAcademicYearId = null;
        $this->testClassId = null;
        $this->testSectionId = null;
        $this->testStudentId = null;
        $this->createdEmails = [];
        $this->unique = '';

        parent::tearDown();
    }

    private function login($client, string $email, string $password): ?string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['success'] ?? false ? $data['data']['accessToken'] ?? null : null;
    }

    private function getAuthHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testRosterReturnsStudentList(): void
    {
        $this->client->request('GET', '/api/v1/attendance/roster?academicYearId=' . $this->testAcademicYearId . '&academicClassId=' . $this->testClassId . '&sectionId=' . $this->testSectionId . '&date=2025-06-15', [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Attendance roster retrieved successfully.', $data['message']);
        $this->assertNotEmpty($data['data']['roster']);
        $this->assertCount(1, $data['data']['roster']);
        $this->assertSame((string) $this->testStudentId, $data['data']['roster'][0]['studentId']);
    }

    public function testRosterUnauthorizedForStudent(): void
    {
        $this->client->request('GET', '/api/v1/attendance/roster?academicYearId=' . $this->testAcademicYearId . '&academicClassId=' . $this->testClassId . '&sectionId=' . $this->testSectionId . '&date=2025-06-15', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testRosterNotFoundForInvalidContext(): void
    {
        $this->client->request('GET', '/api/v1/attendance/roster?academicYearId=' . Uuid::v7() . '&academicClassId=' . $this->testClassId . '&sectionId=' . $this->testSectionId . '&date=2025-06-15', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testBulkMarkAttendance(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                    'remarks' => 'On time',
                ],
            ],
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Attendance marked successfully.', $data['message']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('PRESENT', $data['data'][0]['status']);
    }

    public function testBulkMarkValidationFailsOnMissingRecords(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testBulkMarkFailsForStudent(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                ],
            ],
        ]));

        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testListAttendanceRecords(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                ],
            ],
        ]));

        $this->client->request('GET', '/api/v1/attendance?academicYearId=' . $this->testAcademicYearId . '&classId=' . $this->testClassId . '&sectionId=' . $this->testSectionId, [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['items']);
    }

    public function testGetAttendanceRecord(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                ],
            ],
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $recordId = $data['data'][0]['id'];

        $this->client->request('GET', '/api/v1/attendance/' . $recordId, [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('PRESENT', $data['data']['status']);
    }

    public function testGetAttendanceRecordNotFound(): void
    {
        $this->client->request('GET', '/api/v1/attendance/' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateAttendanceRecord(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                ],
            ],
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $recordId = $data['data'][0]['id'];

        $this->client->request('PUT', '/api/v1/attendance/' . $recordId, [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'status' => 'ABSENT',
            'remarks' => 'Left early',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ABSENT', $data['data']['status']);
    }

    public function testStudentCanViewOwnAttendance(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                ],
            ],
        ]));

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->testStudentId . '?academicYearId=' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentCannotViewOthersAttendance(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . Uuid::v7() . '?academicYearId=' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentSummary(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->testStudentId . '/summary?academicYearId=' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('summary', $data['data']);
    }

    public function testStudentCalendar(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->testStudentId . '/calendar?year=2025&month=6', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
    }

    public function testStudentCalendarReflectsHolidays(): void
    {
        $this->client->request('GET', '/api/v1/attendance/students/' . $this->testStudentId . '/calendar?year=2025&month=6', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $holidayFound = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['date'] === '2025-06-15') {
                $this->assertTrue($item['isHoliday'], 'isHoliday should be true for holiday date 2025-06-15');
                $this->assertSame('HOLIDAY', $item['status']);
                $this->assertFalse($item['isWorkingDay']);
                $this->assertNotNull($item['remarks'], 'remarks should contain the holiday name');
                $holidayFound = true;
            }
        }
        $this->assertTrue($holidayFound, 'Holiday date 2025-06-15 should be present in calendar.');
    }

    public function testStudentHistory(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'classId' => (string) $this->testClassId,
            'sectionId' => (string) $this->testSectionId,
            'attendanceDate' => '2025-06-15',
            'records' => [
                [
                    'studentId' => (string) $this->testStudentId,
                    'status' => 'PRESENT',
                ],
            ],
        ]));

        $this->client->request('GET', '/api/v1/attendance/students/' . $this->testStudentId . '/history?academicYearId=' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testUnauthenticatedAccessDenied(): void
    {
        $this->client->request('GET', '/api/v1/attendance/roster?academicYearId=' . $this->testAcademicYearId . '&academicClassId=' . $this->testClassId . '&sectionId=' . $this->testSectionId . '&date=2025-06-15');

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
    }
}
