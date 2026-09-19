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
use App\Entity\AttendanceCorrection;
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

class AttendanceCorrectionControllerTest extends WebTestCase
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
    private ?Uuid $testAttendanceRecordId = null;
    private ?string $testCorrectionId = null;
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

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-correction-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Corrections');
        $institute->setCode('TESTCOR' . $this->unique);
        $institute->setEmail('test-correction-' . $this->unique . '@edupulse.ai');
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
        $teacherEmail = 'teacher-correction-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $teacherEmail;
        $teacher->setEmail($teacherEmail);
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);

        $student = new User();
        $studentEmail = 'student-correction-test-' . $this->unique . '@edupulse.ai';
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


        $this->createdEmails[] = 'superadmin-correction-test-' . $this->unique . '@edupulse.ai';

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
        $entityManager->flush();

        $attendanceRecord = new AttendanceRecord();
        $this->testAttendanceRecordId = Uuid::v7();
        $arReflection = new \ReflectionClass($attendanceRecord);
        $arProperty = $arReflection->getProperty('id');
        $arProperty->setAccessible(true);
        $arProperty->setValue($attendanceRecord, $this->testAttendanceRecordId);
        $attendanceRecord->setInstitute($institute);
        $attendanceRecord->setAcademicYear($academicYear);
        $attendanceRecord->setStudent($student);
        $attendanceRecord->setAcademicClass($academicClass);
        $attendanceRecord->setSection($section);
        $attendanceRecord->setAttendanceDate(new \DateTimeImmutable('2025-06-15'));
        $attendanceRecord->setStatus(AttendanceStatus::Present);
        $attendanceRecord->setRemarks('Test attendance');
        $attendanceRecord->setSource(AttendanceRecord::SOURCE_MANUAL);
        $attendanceRecord->setMarkedBy($teacher);
        $attendanceRecord->setMarkedAt(new \DateTimeImmutable());

        $entityManager->persist($attendanceRecord);
        $entityManager->flush();

        $this->superAdminToken = $this->login($this->client, 'superadmin-correction-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->teacherToken = $this->login($this->client, $teacherEmail, 'Teacher123!');
        $this->studentToken = $this->login($this->client, $studentEmail, 'Student123!');
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $acRepo = $entityManager->getRepository(AttendanceCorrection::class);
            $corrections = $acRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($corrections as $correction) {
                $entityManager->remove($correction);
            }

            $arRepo = $entityManager->getRepository(AttendanceRecord::class);
            $records = $arRepo->findBy(['institute' => $this->testInstituteId]);
            foreach ($records as $record) {
                $entityManager->remove($record);
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

    public function testStudentCanRequestCorrection(): void
    {
        $this->client->request('POST', '/api/v1/attendance/corrections', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'attendanceRecordId' => (string) $this->testAttendanceRecordId,
            'correctedStatus' => 'ABSENT',
            'reason' => 'I was absent that day',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Attendance correction request submitted successfully.', $data['message']);
        $this->assertSame('PENDING', $data['data']['status']);
        $this->assertSame('ABSENT', $data['data']['correctedStatus']);

        $this->testCorrectionId = $data['data']['id'];
    }

    public function testStudentCannotRequestCorrectionForOthersRecord(): void
    {
        $this->client->request('POST', '/api/v1/attendance/corrections', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'attendanceRecordId' => Uuid::v7(),
            'correctedStatus' => 'ABSENT',
            'reason' => 'Test',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRequestCorrectionValidationFails(): void
    {
        $this->client->request('POST', '/api/v1/attendance/corrections', [], [], $this->getAuthHeaders($this->studentToken), json_encode([
            'attendanceRecordId' => Uuid::v7(),
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testTeacherCanListCorrections(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('GET', '/api/v1/attendance/corrections', [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['items']);
    }

    public function testGetCorrection(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('GET', '/api/v1/attendance/corrections/' . $this->testCorrectionId, [], [], $this->getAuthHeaders($this->teacherToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testGetCorrectionNotFound(): void
    {
        $this->client->request('GET', '/api/v1/attendance/corrections/' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTeacherCanApproveCorrection(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('POST', '/api/v1/attendance/corrections/' . $this->testCorrectionId . '/approve', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Approved',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Attendance correction approved successfully.', $data['message']);
    }

    public function testTeacherCanRejectCorrection(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('POST', '/api/v1/attendance/corrections/' . $this->testCorrectionId . '/reject', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Rejected - not valid',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Attendance correction rejected successfully.', $data['message']);
    }

    public function testStudentCannotApproveCorrection(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('POST', '/api/v1/attendance/corrections/' . $this->testCorrectionId . '/approve', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRejectWithoutRemarksFailsValidation(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('POST', '/api/v1/attendance/corrections/' . $this->testCorrectionId . '/reject', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testApproveAlreadyApprovedCorrectionFails(): void
    {
        $this->testStudentCanRequestCorrection();

        $this->client->request('POST', '/api/v1/attendance/corrections/' . $this->testCorrectionId . '/approve', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'First approval',
        ]));

        $this->client->request('POST', '/api/v1/attendance/corrections/' . $this->testCorrectionId . '/approve', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'remarks' => 'Second approval',
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testUnauthenticatedAccessDenied(): void
    {
        $this->client->request('GET', '/api/v1/attendance/corrections');

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
    }
}
