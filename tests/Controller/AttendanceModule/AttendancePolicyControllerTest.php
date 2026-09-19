<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\StudentEnrollment;
use App\Entity\AttendancePolicy;
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

class AttendancePolicyControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $superAdminToken = null;
    private ?string $teacherToken = null;
    private ?string $studentToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
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

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-policy-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Policies');
        $institute->setCode('TESTPOL' . $this->unique);
        $institute->setEmail('test-policy-' . $this->unique . '@edupulse.ai');
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
        $classId = Uuid::v7();
        $classReflection = new \ReflectionClass($academicClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($academicClass, $classId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Test Class ' . $this->unique);
        $academicClass->setCode('CLS' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionId = Uuid::v7();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $sectionId);
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
        $teacherEmail = 'teacher-policy-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $teacherEmail;
        $teacher->setEmail($teacherEmail);
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);

        $student = new User();
        $studentEmail = 'student-policy-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $studentEmail;
        $student->setEmail($studentEmail);
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $studentEnrollment = new StudentEnrollment();
        $enrollmentReflection = new \ReflectionClass($studentEnrollment);
        $enrollmentProperty = $enrollmentReflection->getProperty('id');
        $enrollmentProperty->setAccessible(true);
        $enrollmentProperty->setValue($studentEnrollment, Uuid::v7());
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


        $attendancePolicy = new AttendancePolicy();
        $this->policyId = Uuid::v7();
        $apReflection = new \ReflectionClass($attendancePolicy);
        $apProperty = $apReflection->getProperty('id');
        $apProperty->setAccessible(true);
        $apProperty->setValue($attendancePolicy, $this->policyId);
        $attendancePolicy->setInstitute($institute);
        $attendancePolicy->setAcademicYear($academicYear);
        $attendancePolicy->setMinimumAttendancePercentage(75);
        $attendancePolicy->setLateThresholdCount(3);
        $attendancePolicy->setHalfDayBehavior(null);
        $attendancePolicy->setApprovedLeaveCounts(true);
        $attendancePolicy->setWorkingDays('MON-FRI');
        $attendancePolicy->setIsActive(true);
        $attendancePolicy->setDescription('Standard policy');

        $this->createdEmails[] = 'superadmin-policy-test-' . $this->unique . '@edupulse.ai';

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
        $entityManager->persist($attendancePolicy);
        $entityManager->flush();

        $this->superAdminToken = $this->login($this->client, 'superadmin-policy-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->teacherToken = $this->login($this->client, $teacherEmail, 'Teacher123!');
        $this->studentToken = $this->login($this->client, $studentEmail, 'Student123!');
    }

    private ?Uuid $policyId = null;

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $apRepo = $entityManager->getRepository(AttendancePolicy::class);
            $policies = $apRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($policies as $policy) {
                $entityManager->remove($policy);
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

    public function testListPolicies(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['items']);
    }

    public function testSuperAdminCanCreatePolicy(): void
    {
        $this->client->request('POST', '/api/v1/attendance/policies', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'minimumAttendancePercentage' => 75,
            'academicYearId' => (string) $this->testAcademicYearId,
            'isActive' => false,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Attendance policy created successfully.', $data['message']);
    }

    public function testCannotCreateTwoActivePoliciesForSameInstituteYear(): void
    {
        $this->client->request('POST', '/api/v1/attendance/policies', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'minimumAttendancePercentage' => 80,
            'academicYearId' => (string) $this->testAcademicYearId,
            'isActive' => true,
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testTeacherCannotCreatePolicy(): void
    {
        $this->client->request('POST', '/api/v1/attendance/policies', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'minimumAttendancePercentage' => 75,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testGetPolicy(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies/' . $this->policyId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(75, $data['data']['minimumAttendancePercentage']);
    }

    public function testGetPolicyNotFound(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies/' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdatePolicy(): void
    {
        $this->client->request('PUT', '/api/v1/attendance/policies/' . $this->policyId, [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'minimumAttendancePercentage' => 80,
            'description' => 'Updated policy',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(80, $data['data']['minimumAttendancePercentage']);
    }

    public function testDeletePolicy(): void
    {
        $this->client->request('DELETE', '/api/v1/attendance/policies/' . $this->policyId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testGetActivePolicy(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies/active?academicYearId=' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testGetActivePolicyReturnsNullWhenNotSet(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies/active?academicYearId=' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentCannotAccessPolicies(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']['items']);
    }

    public function testUnauthenticatedAccessDenied(): void
    {
        $this->client->request('GET', '/api/v1/attendance/policies');

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
    }
}
