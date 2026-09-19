<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\StudentEnrollment;
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

class LeaveControllerTest extends WebTestCase
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
    private ?Uuid $testStudentEnrollmentId = null;
    private array $createdEmails = [];
    private string $unique = '';
    private ?string $leaveTypeId = null;

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

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-leave-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Leaves');
        $institute->setCode('TESTLEAVE' . $this->unique);
        $institute->setEmail('test-leave-' . $this->unique . '@edupulse.ai');
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
        $teacherEmail = 'teacher-leave-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $teacherEmail;
        $teacher->setEmail($teacherEmail);
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);

        $student = new User();
        $this->testStudentId = Uuid::v7();
        $studentEmail = 'student-leave-test-' . $this->unique . '@edupulse.ai';
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

        $studentEnrollment = new StudentEnrollment();
        $this->testStudentEnrollmentId = Uuid::v7();
        $enrollmentReflection = new \ReflectionClass($studentEnrollment);
        $enrollmentProperty = $enrollmentReflection->getProperty('id');
        $enrollmentProperty->setAccessible(true);
        $enrollmentProperty->setValue($studentEnrollment, $this->testStudentEnrollmentId);
        $studentEnrollment->setStudent($student);
        $studentEnrollment->setAcademicYear($academicYear);
        $studentEnrollment->setAcademicClass($academicClass);
        $studentEnrollment->setAdmissionNumber('ADM' . $this->unique);
        $studentEnrollment->setIsCurrent(true);
        $studentEnrollment->setStatus(StudentEnrollmentStatus::Active);
        $studentEnrollment->setInstituteId($this->testInstituteId);

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
        $leaveType->setName('Casual Leave ' . $this->unique);
        $leaveType->setCode('CL' . $this->unique);
        $leaveType->setDescription('Casual leave');
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(15);
        $leaveType->setCarryForward(true);

        $leaveBalance = new LeaveBalance();
        $lbReflection = new \ReflectionClass($leaveBalance);
        $lbProperty = $lbReflection->getProperty('id');
        $lbProperty->setAccessible(true);
        $lbProperty->setValue($leaveBalance, Uuid::v7());
        $leaveBalance->setInstitute($institute);
        $leaveBalance->setStudent($student);
        $leaveBalance->setAcademicYear($academicYear);
        $leaveBalance->setLeaveType($leaveType);
        $leaveBalance->setAllocated(20);
        $leaveBalance->setUsed(0);
        $leaveBalance->setPending(0);

        $this->createdEmails[] = 'superadmin-leave-test-' . $this->unique . '@edupulse.ai';

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
        $entityManager->flush();

        $this->superAdminToken = $this->login($this->client, 'superadmin-leave-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->teacherToken = $this->login($this->client, $teacherEmail, 'Teacher123!');
        $this->studentToken = $this->login($this->client, $studentEmail, 'Student123!');

        $this->leaveTypeId = (string) $leaveTypeId;
    }

    protected function tearDown(): void
    {
        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $leaveRequestRepo = $entityManager->getRepository(\App\Entity\LeaveRequest::class);
            $leaveRequests = $leaveRequestRepo->createQueryBuilder('lr')
                ->join('lr.student', 's')
                ->where('s.email IN (:emails)')
                ->setParameter('emails', $this->createdEmails)
                ->getQuery()
                ->getResult();
            foreach ($leaveRequests as $lr) {
                $entityManager->remove($lr);
            }

            $leaveBalanceRepo = $entityManager->getRepository(LeaveBalance::class);
            $leaveBalances = $leaveBalanceRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($leaveBalances as $lb) {
                $entityManager->remove($lb);
            }

            $leaveTypeRepo = $entityManager->getRepository(LeaveType::class);
            $leaveTypes = $leaveTypeRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($leaveTypes as $lt) {
                $entityManager->remove($lt);
            }

            $attendanceRepo = $entityManager->getRepository(\App\Entity\AttendanceRecord::class);
            $attendances = $attendanceRepo->createQueryBuilder('ar')
                ->join('ar.student', 's')
                ->where('s.email IN (:emails)')
                ->setParameter('emails', $this->createdEmails)
                ->getQuery()
                ->getResult();
            foreach ($attendances as $ar) {
                $entityManager->remove($ar);
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

    public function testListLeaveTypesReturnsData(): void
    {
        $this->client->request('GET', '/api/v1/leaves/types', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['items']);
    }

    public function testSuperAdminCanCreateLeaveType(): void
    {
        $this->client->request('POST', '/api/v1/leaves/types', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'name' => 'Sick Leave ' . $this->unique,
            'code' => 'SL' . $this->unique,
            'description' => 'Sick leave',
            'enabled' => true,
            'requiresApproval' => true,
            'maximumDays' => 10,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Sick Leave ' . $this->unique, $data['data']['name']);
        $this->assertSame('Leave type created successfully.', $data['message']);
    }

    public function testCreateLeaveTypeValidationFails(): void
    {
        $this->client->request('POST', '/api/v1/leaves/types', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'code' => 'X',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGetLeaveType(): void
    {
        $this->client->request('GET', '/api/v1/leaves/types/' . $this->leaveTypeId, [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Leave type retrieved successfully.', $data['message']);
    }

    public function testGetLeaveTypeNotFound(): void
    {
        $this->client->request('GET', '/api/v1/leaves/types/' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateLeaveType(): void
    {
        $this->client->request('PUT', '/api/v1/leaves/types/' . $this->leaveTypeId, [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'name' => 'Updated Leave Type',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Leave Type', $data['data']['name']);
    }

    public function testDeleteLeaveType(): void
    {
        $leaveTypeId = Uuid::v7();
        $ltReflection = new \ReflectionClass(\App\Entity\LeaveType::class);
        $leaveType = new \App\Entity\LeaveType();
        $ltProperty = $ltReflection->getProperty('id');
        $ltProperty->setAccessible(true);
        $ltProperty->setValue($leaveType, $leaveTypeId);
        $leaveType->setInstitute($this->client->getContainer()->get('doctrine')->getManager()->getReference(Institute::class, $this->testInstituteId));
        $leaveType->setName('Delete Test ' . $this->unique);
        $leaveType->setCode('DEL' . $this->unique);
        $leaveType->setDescription('Delete test');
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(10);
        $leaveType->setCarryForward(true);

        $this->client->getContainer()->get('doctrine')->getManager()->persist($leaveType);
        $this->client->getContainer()->get('doctrine')->getManager()->flush();

        $this->client->request('DELETE', '/api/v1/leaves/types/' . $leaveTypeId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentCanApplyLeave(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-03',
            'reason' => 'Medical leave',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Leave request submitted successfully.', $data['message']);
        $this->assertSame('PENDING', $data['data']['status']);
        $this->assertSame('2025-06-01', $data['data']['startDate']);
        $this->assertSame('2025-06-03', $data['data']['endDate']);

        $this->leaveRequestId = $data['data']['id'];
    }

    private ?string $leaveRequestId = null;

    public function testStudentCannotApplyLeaveWithoutBalance(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => Uuid::v7(),
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-03',
            'reason' => 'Test leave',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTeacherCanListLeaveRequests(): void
    {
        $this->client->request('GET', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentCannotApplyLeaveWhenInsufficientBalance(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-30',
            'reason' => 'Too many days',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStudentCanListTheirLeaveRequests(): void
    {
        $this->client->request('GET', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentCanCancelLeaveRequest(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-07-15',
            'endDate' => '2025-07-17',
            'reason' => 'Cancel test',
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/cancel', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testTeacherCanApproveLeaveRequest(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-07-20',
            'endDate' => '2025-07-22',
            'reason' => 'Approval test',
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/approve', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Approved by teacher',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Leave request approved successfully.', $data['message']);
    }

    public function testTeacherCanRejectLeaveRequest(): void
    {
        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-07-25',
            'endDate' => '2025-07-27',
            'reason' => 'Rejection test',
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/reject', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Rejected by teacher',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Leave request rejected successfully.', $data['message']);
    }

    public function testStudentCannotCancelOthersLeave(): void
    {
        $leaveRequestId = Uuid::v7();

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/cancel', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanViewLeaveBalances(): void
    {
        $this->client->request('GET', '/api/v1/leaves/balances', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']);
    }

    public function testSuperAdminCanInitializeLeaveBalances(): void
    {
        $this->client->request('POST', '/api/v1/leaves/balances/initialize', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'studentId' => (string) $this->testStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Leave balances initialized successfully.', $data['message']);
    }

    public function testUnauthenticatedAccessDenied(): void
    {
        $this->client->request('GET', '/api/v1/leaves/types');

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== LEAVE BALANCE REGRESSION TESTS ====================

    private function setBalanceAllocated(int $allocated): LeaveBalance
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $balance = $em->getRepository(LeaveBalance::class)->findOneBy(['student' => $this->testStudentId]);
        $balance->setAllocated($allocated);
        $balance->setUsed(0);
        $balance->setPending(0);

        $leaveType = $em->getRepository(LeaveType::class)->find(Uuid::fromString($this->leaveTypeId));
        $leaveType->setMaximumDays($allocated);

        $em->flush();

        return $balance;
    }

    private function getBalanceResponse(): array
    {
        $this->client->request('GET', '/api/v1/leaves/balances', [], [], $this->getAuthHeaders($this->studentToken));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['data']['items'][0];
    }

    private function createDirectLeaveRequest(string $startDate, string $endDate, LeaveRequestStatus $status): LeaveRequest
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $leaveRequest = new LeaveRequest();
        $leaveRequest->setInstitute($em->getReference(Institute::class, $this->testInstituteId));
        $leaveRequest->setAcademicYear($em->getReference(AcademicYear::class, $this->testAcademicYearId));
        $leaveRequest->setStudent($em->getReference(User::class, $this->testStudentId));
        $leaveRequest->setAcademicClass($em->getReference(AcademicClass::class, $this->testClassId));
        $leaveRequest->setSection($em->getReference(Section::class, $this->testSectionId));
        $leaveRequest->setLeaveType($em->getReference(LeaveType::class, Uuid::fromString($this->leaveTypeId)));
        $leaveRequest->setStartDate(new \DateTimeImmutable($startDate));
        $leaveRequest->setEndDate(new \DateTimeImmutable($endDate));
        $leaveRequest->setReason('Direct leave request test');
        $leaveRequest->setStatus($status);
        $leaveRequest->setSubmittedAt(new \DateTimeImmutable());
        if ($status === LeaveRequestStatus::Approved) {
            $leaveRequest->setApprovedAt(new \DateTimeImmutable());
        }
        $em->persist($leaveRequest);
        $em->flush();

        return $leaveRequest;
    }

    public function testApprovedLeaveContributesToUsedEvenWithStalePersistedValue(): void
    {
        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Approved);

        $item = $this->getBalanceResponse();

        $this->assertSame(6, $item['allocated']);
        $this->assertSame(3, $item['used'], 'Used should reflect 3 approved days despite stale persisted value');
        $this->assertSame(0, $item['pending']);
        $this->assertSame(3, $item['remaining']);
    }

    public function testPendingLeaveContributesToPendingOnly(): void
    {
        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Pending);

        $item = $this->getBalanceResponse();

        $this->assertSame(0, $item['used']);
        $this->assertSame(3, $item['pending']);
        $this->assertSame(3, $item['remaining']);
    }

    public function testApprovedAndPendingLeavesCalculatedSeparately(): void
    {
        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Approved);
        $this->createDirectLeaveRequest('2025-09-20', '2025-09-21', LeaveRequestStatus::Pending);

        $item = $this->getBalanceResponse();

        $this->assertSame(3, $item['used']);
        $this->assertSame(2, $item['pending']);
        $this->assertSame(1, $item['remaining']);
    }

    public function testLifecycleApproveMovesPendingToUsed(): void
    {
        $this->setBalanceAllocated(6);
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $balance = $em->getRepository(LeaveBalance::class)->findOneBy(['student' => $this->testStudentId]);

        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-09-14',
            'endDate' => '2025-09-16',
            'reason' => 'Lifecycle approve test',
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $item = $this->getBalanceResponse();
        $this->assertSame(0, $item['used']);
        $this->assertSame(3, $item['pending']);
        $this->assertSame(3, $item['remaining']);

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/approve', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Approved',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $item = $this->getBalanceResponse();
        $this->assertSame(3, $item['used'], 'Used should be 3 after approval');
        $this->assertSame(0, $item['pending']);
        $this->assertSame(3, $item['remaining']);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $persistedBalance = $em->getRepository(LeaveBalance::class)->findOneBy(['student' => $this->testStudentId]);
        $this->assertSame(3, $persistedBalance->getUsed(), 'Persisted used should be 3 after approval');
    }

    public function testLifecycleRejectClearsPendingLeave(): void
    {
        $this->setBalanceAllocated(6);

        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-09-14',
            'endDate' => '2025-09-16',
            'reason' => 'Lifecycle reject test',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/reject', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Rejected',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $item = $this->getBalanceResponse();
        $this->assertSame(0, $item['used'], 'Used unchanged after rejection');
        $this->assertSame(0, $item['pending']);
        $this->assertSame(6, $item['remaining']);
    }

    public function testLifecycleCancelPendingRestoresBalance(): void
    {
        $this->setBalanceAllocated(6);

        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2025-09-14',
            'endDate' => '2025-09-16',
            'reason' => 'Lifecycle cancel pending test',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $item = $this->getBalanceResponse();
        $this->assertSame(3, $item['pending']);

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/cancel', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $item = $this->getBalanceResponse();
        $this->assertSame(0, $item['used']);
        $this->assertSame(0, $item['pending']);
        $this->assertSame(6, $item['remaining']);
    }

    public function testLifecycleCancelApprovedRestoresUsed(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $academicYear = $em->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $academicYear->setEndDate(new \DateTimeImmutable('2026-12-31'));
        $em->flush();

        $this->setBalanceAllocated(6);

        $this->client->request('POST', '/api/v1/leaves/requests', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'leaveTypeId' => $this->leaveTypeId,
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-03',
            'reason' => 'Lifecycle cancel approved test',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $leaveRequestId = $data['data']['id'];

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/approve', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Approved',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $item = $this->getBalanceResponse();
        $this->assertSame(3, $item['used']);

        $this->client->request('POST', '/api/v1/leaves/requests/' . $leaveRequestId . '/cancel', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $item = $this->getBalanceResponse();
        $this->assertSame(0, $item['used'], 'Used should be 0 after cancelling approved leave');
        $this->assertSame(0, $item['pending']);
        $this->assertSame(6, $item['remaining']);
    }

    public function testLeaveDateCalculationInclusiveEnd(): void
    {
        $totalDays = (int) (new \DateTimeImmutable('2025-09-14'))->diff(new \DateTimeImmutable('2025-09-16'))->days + 1;
        $this->assertSame(3, $totalDays, '14 Sep to 16 Sep should be 3 calendar days');

        $totalDays2 = (int) (new \DateTimeImmutable('2025-09-14'))->diff(new \DateTimeImmutable('2025-09-14'))->days + 1;
        $this->assertSame(1, $totalDays2, 'Same start and end date should be 1 day');
    }

    public function testLeaveTypeIsolation(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $secondLeaveType = new LeaveType();
        $sltId = Uuid::v7();
        $sltRef = new \ReflectionClass($secondLeaveType);
        $sltRef->getProperty('id')->setAccessible(true);
        $sltRef->getProperty('id')->setValue($secondLeaveType, $sltId);
        $secondLeaveType->setInstitute($em->getReference(Institute::class, $this->testInstituteId));
        $secondLeaveType->setName('Sick Leave ' . $this->unique);
        $secondLeaveType->setCode('SL' . $this->unique);
        $secondLeaveType->setEnabled(true);
        $secondLeaveType->setRequiresApproval(true);
        $secondLeaveType->setMaximumDays(10);
        $secondLeaveType->setCarryForward(true);
        $em->persist($secondLeaveType);
        $em->flush();

        $secondBalance = new LeaveBalance();
        $sbRef = new \ReflectionClass($secondBalance);
        $sbRef->getProperty('id')->setAccessible(true);
        $sbRef->getProperty('id')->setValue($secondBalance, Uuid::v7());
        $secondBalance->setInstitute($em->getReference(Institute::class, $this->testInstituteId));
        $secondBalance->setStudent($em->getReference(User::class, $this->testStudentId));
        $secondBalance->setAcademicYear($em->getReference(AcademicYear::class, $this->testAcademicYearId));
        $secondBalance->setLeaveType($secondLeaveType);
        $secondBalance->setAllocated(6);
        $em->persist($secondBalance);
        $em->flush();

        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Approved);

        $this->client->request('GET', '/api/v1/leaves/balances', [], [], $this->getAuthHeaders($this->studentToken));
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $balances = $data['data']['items'];
        $this->assertGreaterThanOrEqual(2, count($balances));
        $clBalance = null;
        $slBalance = null;
        foreach ($balances as $b) {
            if ($b['leaveType']['id'] === $this->leaveTypeId) {
                $clBalance = $b;
            } else {
                $slBalance = $b;
            }
        }
        $this->assertNotNull($clBalance, 'Casual Leave balance should exist');
        $this->assertNotNull($slBalance, 'Sick Leave balance should exist');

        $this->assertSame(3, $clBalance['used'], 'Casual Leave used should be 3');
        $this->assertSame(0, $slBalance['used'], 'Sick Leave used should be 0');
    }

    public function testStudentIsolation(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $secondStudent = new User();
        $sRef = new \ReflectionClass($secondStudent);
        $sRef->getProperty('id')->setAccessible(true);
        $sRef->getProperty('id')->setValue($secondStudent, Uuid::v7());
        $sEmail = 'student2-isolation-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $sEmail;
        $secondStudent->setEmail($sEmail);
        $secondStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $secondStudent->setRoles([UserRole::Student->value]);
        $secondStudent->setStatus(UserStatus::Active);
        $secondStudent->verify();
        $secondStudent->setInstitute($em->getReference(Institute::class, $this->testInstituteId));
        $em->persist($secondStudent);

        $sBalance = new LeaveBalance();
        $sbRef = new \ReflectionClass($sBalance);
        $sbRef->getProperty('id')->setAccessible(true);
        $sbRef->getProperty('id')->setValue($sBalance, Uuid::v7());
        $sBalance->setInstitute($em->getReference(Institute::class, $this->testInstituteId));
        $sBalance->setStudent($secondStudent);
        $sBalance->setAcademicYear($em->getReference(AcademicYear::class, $this->testAcademicYearId));
        $sBalance->setLeaveType($em->getReference(LeaveType::class, Uuid::fromString($this->leaveTypeId)));
        $sBalance->setAllocated(6);
        $em->persist($sBalance);
        $em->flush();

        // Create approved leave for first student
        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Approved);

        // First student should show used=3
        $item = $this->getBalanceResponse();
        $this->assertSame(3, $item['used']);

        // Second student should show used=0
        $secondStudentToken = $this->login($this->client, $sEmail, 'Student123!');
        $this->client->request('GET', '/api/v1/leaves/balances', [], [], $this->getAuthHeaders($secondStudentToken));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['data']['items'][0]['used'], 'Second student should not be affected by first student leave');
    }

    public function testRejectedLeaveDoesNotContributeToUsed(): void
    {
        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Rejected);

        $item = $this->getBalanceResponse();
        $this->assertSame(0, $item['used'], 'Rejected leave should not contribute to used');
        $this->assertSame(0, $item['pending']);
        $this->assertSame(6, $item['remaining']);
    }

    public function testCancelledLeaveDoesNotContributeToUsed(): void
    {
        $this->setBalanceAllocated(6);
        $this->createDirectLeaveRequest('2025-09-14', '2025-09-16', LeaveRequestStatus::Cancelled);

        $item = $this->getBalanceResponse();
        $this->assertSame(0, $item['used'], 'Cancelled leave should not contribute to used');
        $this->assertSame(0, $item['pending']);
        $this->assertSame(6, $item['remaining']);
    }

    public function testLeaveBalanceAutoInitializedWhenNoUsage(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $newStudent = new User();
        $sRef = new \ReflectionClass($newStudent);
        $sRef->getProperty('id')->setAccessible(true);
        $sRef->getProperty('id')->setValue($newStudent, Uuid::v7());
        $sEmail = 'student-auto-init-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $sEmail;
        $newStudent->setEmail($sEmail);
        $newStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $newStudent->setRoles([UserRole::Student->value]);
        $newStudent->setStatus(UserStatus::Active);
        $newStudent->verify();
        $newStudent->setInstitute($em->getReference(Institute::class, $this->testInstituteId));
        $em->persist($newStudent);

        $newEnrollment = new StudentEnrollment();
        $eRef = new \ReflectionClass($newEnrollment);
        $eRef->getProperty('id')->setAccessible(true);
        $eRef->getProperty('id')->setValue($newEnrollment, Uuid::v7());
        $newEnrollment->setStudent($newStudent);
        $newEnrollment->setAcademicYear($em->getReference(AcademicYear::class, $this->testAcademicYearId));
        $newEnrollment->setAcademicClass($em->getReference(AcademicClass::class, $this->testClassId));
        $newEnrollment->setAdmissionNumber('ADM' . $this->unique);
        $newEnrollment->setIsCurrent(true);
        $newEnrollment->setStatus(StudentEnrollmentStatus::Active);
        $newEnrollment->setInstituteId($this->testInstituteId);
        $em->persist($newEnrollment);
        $em->flush();

        $leaveTypes = $em->getRepository(LeaveType::class)->findBy(['institute' => $this->testInstituteId]);
        $expectedAllocated = 0;
        foreach ($leaveTypes as $lt) {
            $expectedAllocated = max($expectedAllocated, $lt->getMaximumDays() ?? 0);
        }

        $newStudentToken = $this->login($this->client, $sEmail, 'Student123!');

        $this->client->request('GET', '/api/v1/leaves/balances', [], [], $this->getAuthHeaders($newStudentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['items'], 'Balance should be auto-initialized even with no usage');

        $item = $data['data']['items'][0];
        $this->assertGreaterThanOrEqual(1, $item['allocated'], 'Allocated should come from configured entitlement');
        $this->assertSame(0, $item['used'], 'Used should be 0 when no leave taken');
        $this->assertSame(0, $item['pending'], 'Pending should be 0 when no leave requested');
        $this->assertSame($item['allocated'], $item['remaining'], 'Remaining should equal allocated when no usage');
    }
}
