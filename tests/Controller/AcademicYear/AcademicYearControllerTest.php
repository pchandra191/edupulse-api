<?php
declare(strict_types=1);

namespace App\Tests\Controller\AcademicYear;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\EnrollmentSectionStatus;
use App\Enum\ExaminationStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\SubjectStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AcademicYearControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
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
        $superAdmin->setEmail('superadmin-year-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Years');
        $institute->setCode('TESTYR' . $this->unique);
        $institute->setEmail('test-institute-year-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year ' . $this->unique);
        $academicYear->setCode('AY' . $this->unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2024-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2024-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-year-test-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($instituteUser);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-year-test-' . $this->unique . '@edupulse.ai',
            'institute-year-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-year-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-year-test-' . $this->unique . '@edupulse.ai', 'InstituteUser123!');
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $userRepo = $entityManager->getRepository(User::class);
            $instituteRepo = $entityManager->getRepository(Institute::class);

            $users = $userRepo->findBy(['email' => $this->createdEmails]);
            foreach ($users as $user) {
                $entityManager->remove($user);
            }

            if ($this->testInstituteId) {
                $institute = $instituteRepo->find($this->testInstituteId);
                if ($institute) {
                    $entityManager->remove($institute);
                }
            }

            $entityManager->flush();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }

        $this->superAdminToken = null;
        $this->instituteToken = null;
        $this->testInstituteId = null;
        $this->testAcademicYearId = null;
        $this->createdEmails = [];

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
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    private function createClassForYear(Uuid $yearId, Uuid $instituteId, int $sectionCount = 0): AcademicClass
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $class = new AcademicClass();
        $classId = Uuid::v7();
        $reflection = new \ReflectionClass($class);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($class, $classId);
        $class->setInstitute($entityManager->getRepository(Institute::class)->find($instituteId));
        $class->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($yearId));
        $class->setName('Test Class ' . uniqid());
        $class->setCode('CLS' . uniqid());
        $class->setStatus(ClassStatus::Active);

        $entityManager->persist($class);

        for ($i = 0; $i < $sectionCount; $i++) {
            $section = new Section();
            $section->setAcademicClass($class);
            $section->setName('Test Section ' . uniqid());
            $section->setCode('SEC' . uniqid());
            $section->setStatus(\App\Enum\SectionStatus::Active);
            $entityManager->persist($section);
        }

        $entityManager->flush();

        return $class;
    }

    public function testSuperAdminCanListAcademicYears(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/academic-years', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testArchiveAcademicYearArchivesAllClasses(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $this->createClassForYear($this->testAcademicYearId, $this->testInstituteId);
        $class2 = $this->createClassForYear($this->testAcademicYearId, $this->testInstituteId);

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $this->assertNotNull($refreshedYear);
        $this->assertSame(AcademicYearStatus::Archived->value, $refreshedYear->getStatus()->value);

        $refreshedClass1 = $entityManager->getRepository(AcademicClass::class)->find($class1->getId());
        $this->assertNotNull($refreshedClass1);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass1->getStatus()->value);

        $refreshedClass2 = $entityManager->getRepository(AcademicClass::class)->find($class2->getId());
        $this->assertNotNull($refreshedClass2);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass2->getStatus()->value);
    }

    public function testArchiveAcademicYearArchivesAllNestedSections(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class = $this->createClassForYear($this->testAcademicYearId, $this->testInstituteId, 3);

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $sections = $entityManager->getRepository(Section::class)->findByClass($class->getId());
        foreach ($sections as $section) {
            $this->assertSame(\App\Enum\SectionStatus::Archived->value, $section->getStatus()->value);
        }
    }

    public function testArchiveAcademicYearWithZeroClassesWorks(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $this->assertNotNull($refreshedYear);
        $this->assertSame(AcademicYearStatus::Archived->value, $refreshedYear->getStatus()->value);
    }

    public function testArchiveAcademicYearIsAtomic(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class = $this->createClassForYear($this->testAcademicYearId, $this->testInstituteId, 2);

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($class->getId());
        $sections = $entityManager->getRepository(Section::class)->findByClass($class->getId());

        $this->assertSame(AcademicYearStatus::Archived->value, $refreshedYear->getStatus()->value);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass->getStatus()->value);
        $this->assertCount(2, $sections);
        foreach ($sections as $section) {
            $this->assertSame(\App\Enum\SectionStatus::Archived->value, $section->getStatus()->value);
        }
    }

    public function testExistingDataRemainsIntactAfterArchive(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class = $this->createClassForYear($this->testAcademicYearId, $this->testInstituteId, 2);
        $sectionIds = array_map(
            fn ($s) => (string) $s->getId(),
            $entityManager->getRepository(Section::class)->findByClass($class->getId())
        );

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $this->assertNotNull($refreshedYear);
        $this->assertSame((string) $this->testInstituteId, (string) $refreshedYear->getInstitute()->getId());
        $this->assertSame('Test Academic Year ' . $this->unique, $refreshedYear->getName());

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($class->getId());
        $this->assertNotNull($refreshedClass);
        $this->assertSame((string) $this->testAcademicYearId, (string) $refreshedClass->getAcademicYear()->getId());

        foreach ($sectionIds as $sectionId) {
            $section = $entityManager->getRepository(Section::class)->find($sectionId);
            $this->assertNotNull($section);
            $this->assertSame((string) $class->getId(), (string) $section->getAcademicClass()->getId());
        }
    }

    public function testArchivedAcademicYearExcludedFromDefaultList(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $client->request('GET', '/api/v1/academic-years', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        foreach ($data['data']['items'] as $item) {
            $this->assertNotSame((string) $this->testAcademicYearId, $item['id']);
        }
    }

    public function testArchivedAcademicYearCanBeExplicitlyListed(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $client->request('GET', '/api/v1/academic-years?status=ARCHIVED', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->testAcademicYearId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Archived academic year should be returned when explicitly requested.');
    }

    public function testInstituteUserCannotArchiveAnotherInstitutesAcademicYear(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstituteId = Uuid::v7();
        $reflection = new \ReflectionClass($otherInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($otherInstitute, $otherInstituteId);
        $otherInstitute->setName('Other Institute');
        $otherInstitute->setCode('OTHR' . uniqid());
        $otherInstitute->setEmail('other-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherYear = new AcademicYear();
        $otherYear->setInstitute($otherInstitute);
        $otherYear->setName('Other Year');
        $otherYear->setCode('OY' . uniqid());
        $otherYear->setStartDate(new \DateTimeImmutable('2024-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2024-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherYear);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/academic-years/' . $otherYear->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($otherYear->getId());
        $this->assertSame(AcademicYearStatus::Active->value, $refreshedYear->getStatus()->value);
    }

    public function testCannotReactivateArchivedAcademicYear(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'name' => 'Reactivated Year',
            'code' => 'REACT' . uniqid(),
            'startDate' => '2025-01-01',
            'endDate' => '2025-12-31',
            'isActive' => true,
            'status' => 'ACTIVE',
        ]);

        $client->request('PUT', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $this->assertSame(AcademicYearStatus::Archived->value, $refreshedYear->getStatus()->value);
    }

    public function testArchiveAcademicYearCascadesToAllDependentEntities(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $teacher = new User();
        $teacherId = Uuid::v7();
        $teacherReflection = new \ReflectionClass($teacher);
        $teacherProperty = $teacherReflection->getProperty('id');
        $teacherProperty->setAccessible(true);
        $teacherProperty->setValue($teacher, $teacherId);
        $teacher->setEmail('teacher-cascade-' . $this->unique . '@edupulse.ai');
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-cascade-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();

        $subject = new Subject();
        $subjectId = Uuid::v7();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $subjectId);
        $subject->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $subject->setName('Test Subject for Cascade');
        $subject->setCode('SUBJ' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $class = new AcademicClass();
        $classId = Uuid::v7();
        $classReflection = new \ReflectionClass($class);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($class, $classId);
        $class->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $class->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $class->setName('Test Class for Cascade');
        $class->setCode('CLS-CASC');
        $class->setName('Test Class for Cascade');

        $section = new Section();
        $sectionId = Uuid::v7();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $sectionId);
        $section->setAcademicClass($class);
        $section->setName('Test Section for Cascade');
        $section->setCode('SEC-CASC');
        $section->setStatus(SectionStatus::Active);

        $teacherAssignment = new TeacherAssignment();
        $teacherAssignment->setTeacher($teacher);
        $teacherAssignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $teacherAssignment->setAcademicClass($class);
        $teacherAssignment->setAssignmentType('SUBJECT_TEACHER');
        $teacherAssignment->setStatus(TeacherAssignmentStatus::Active);

        $studentEnrollment = new StudentEnrollment();
        $studentEnrollment->setStudent($student);
        $studentEnrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $studentEnrollment->setAcademicClass($class);
        $studentEnrollment->setInstituteId($this->testInstituteId);
        $studentEnrollment->setAdmissionNumber('ADM-CASC');
        $studentEnrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $studentEnrollment->setIsCurrent(true);
        $studentEnrollment->setStatus(StudentEnrollmentStatus::Active);

        $enrollmentSection = new EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($studentEnrollment);
        $enrollmentSection->setSection($section);
        $enrollmentSection->setStatus(EnrollmentSectionStatus::Active);

        $examination = new Examination();
        $examination->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $examination->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $examination->setAcademicClass($class);
        $examination->setName('Test Examination for Cascade');
        $examination->setCode('EXAM-CASC');
        $examination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $examination->setEndDate(new \DateTimeImmutable('2025-06-15'));
        $examination->setStatus(ExaminationStatus::Draft);

        $examSubject = new ExamSubject();
        $examSubject->setExamination($examination);
        $examSubject->setSubject($subject);
        $examSubject->setExamDate(new \DateTimeImmutable('2025-06-10'));
        $examSubject->setStartTime(new \DateTimeImmutable('09:00'));
        $examSubject->setEndTime(new \DateTimeImmutable('12:00'));
        $examSubject->setMaxMarks(100);
        $examSubject->setPassingMarks(40);
        $examSubject->setStatus(ExaminationStatus::Draft);

        $questionPaper = new QuestionPaper();
        $questionPaper->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $questionPaper->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Question Paper for Cascade');
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks(50);
        $questionPaper->setStatus(QuestionPaperStatus::Draft);

        $entityManager->persist($teacher);
        $entityManager->persist($student);
        $entityManager->persist($subject);
        $entityManager->persist($class);
        $entityManager->persist($section);
        $entityManager->persist($teacherAssignment);
        $entityManager->persist($studentEnrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->persist($examination);
        $entityManager->persist($examSubject);
        $entityManager->persist($questionPaper);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $refreshedYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $this->assertSame(AcademicYearStatus::Archived->value, $refreshedYear->getStatus()->value);

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($classId);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass->getStatus()->value);

        $refreshedSection = $entityManager->getRepository(Section::class)->find($sectionId);
        $this->assertSame(SectionStatus::Archived->value, $refreshedSection->getStatus()->value);

        $refreshedTeacherAssignment = $entityManager->getRepository(TeacherAssignment::class)->find($teacherAssignment->getId());
        $this->assertSame(TeacherAssignmentStatus::Archived->value, $refreshedTeacherAssignment->getStatus()->value);

        $refreshedStudentEnrollment = $entityManager->getRepository(StudentEnrollment::class)->find($studentEnrollment->getId());
        $this->assertSame(StudentEnrollmentStatus::Archived->value, $refreshedStudentEnrollment->getStatus()->value);

        $refreshedEnrollmentSection = $entityManager->getRepository(EnrollmentSection::class)->find($enrollmentSection->getId());
        $this->assertSame(EnrollmentSectionStatus::Archived->value, $refreshedEnrollmentSection->getStatus()->value);

        $refreshedExamination = $entityManager->getRepository(Examination::class)->find($examination->getId());
        $this->assertSame(ExaminationStatus::Archived->value, $refreshedExamination->getStatus()->value);

        $refreshedExamSubject = $entityManager->getRepository(ExamSubject::class)->find($examSubject->getId());
        $this->assertSame(ExaminationStatus::Archived->value, $refreshedExamSubject->getStatus()->value);

        $refreshedQuestionPaper = $entityManager->getRepository(QuestionPaper::class)->find($questionPaper->getId());
        $this->assertSame(QuestionPaperStatus::Archived->value, $refreshedQuestionPaper->getStatus()->value);
    }

    public function testArchiveAcademicYearDoesNotAffectOtherAcademicYear(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherYear = new AcademicYear();
        $otherYearId = Uuid::v7();
        $ayReflection = new \ReflectionClass($otherYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherYear, $otherYearId);
        $otherYear->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherYear->setName('Other Academic Year ' . $this->unique);
        $otherYear->setCode('OY' . $this->unique);
        $otherYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);
        $otherYear->setIsActive(true);

        $otherClass = new AcademicClass();
        $otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $otherClassId);
        $otherClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherClass->setAcademicYear($otherYear);
        $otherClass->setName('Other Class ' . $this->unique);
        $otherClass->setCode('OCLS' . $this->unique);
        $otherClass->setStatus(ClassStatus::Active);

        $otherSection = new Section();
        $otherSectionId = Uuid::v7();
        $sectionReflection = new \ReflectionClass($otherSection);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($otherSection, $otherSectionId);
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section ' . $this->unique);
        $otherSection->setCode('OSEC' . $this->unique);
        $otherSection->setStatus(SectionStatus::Active);

        $otherTeacher = new User();
        $otherTeacherId = Uuid::v7();
        $otherTeacherReflection = new \ReflectionClass($otherTeacher);
        $otherTeacherProperty = $otherTeacherReflection->getProperty('id');
        $otherTeacherProperty->setAccessible(true);
        $otherTeacherProperty->setValue($otherTeacher, $otherTeacherId);
        $otherTeacher->setEmail('other-teacher-' . $this->unique . '@edupulse.ai');
        $otherTeacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $otherTeacher->setRoles([UserRole::Teacher->value]);
        $otherTeacher->setStatus(UserStatus::Active);
        $otherTeacher->verify();
        $otherTeacher->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $otherStudentReflection = new \ReflectionClass($otherStudent);
        $otherStudentProperty = $otherStudentReflection->getProperty('id');
        $otherStudentProperty->setAccessible(true);
        $otherStudentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();

        $otherSubject = new Subject();
        $otherSubjectId = Uuid::v7();
        $otherSubjectReflection = new \ReflectionClass($otherSubject);
        $otherSubjectProperty = $otherSubjectReflection->getProperty('id');
        $otherSubjectProperty->setAccessible(true);
        $otherSubjectProperty->setValue($otherSubject, $otherSubjectId);
        $otherSubject->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherSubject->setName('Other Subject ' . $this->unique);
        $otherSubject->setCode('OSUBJ' . $this->unique);
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherAssignment = new TeacherAssignment();
        $otherAssignment->setTeacher($otherTeacher);
        $otherAssignment->setAcademicYear($otherYear);
        $otherAssignment->setAcademicClass($otherClass);
        $otherAssignment->setAssignmentType('SUBJECT_TEACHER');
        $otherAssignment->setStatus(TeacherAssignmentStatus::Active);

        $otherEnrollment = new StudentEnrollment();
        $otherEnrollment->setStudent($otherStudent);
        $otherEnrollment->setAcademicYear($otherYear);
        $otherEnrollment->setAcademicClass($otherClass);
        $otherEnrollment->setInstituteId($this->testInstituteId);
        $otherEnrollment->setAdmissionNumber('ADM-OTHER-' . $this->unique);
        $otherEnrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $otherEnrollment->setIsCurrent(true);
        $otherEnrollment->setStatus(StudentEnrollmentStatus::Active);

        $otherExamination = new Examination();
        $otherExamination->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherExamination->setAcademicYear($otherYear);
        $otherExamination->setAcademicClass($otherClass);
        $otherExamination->setName('Other Examination ' . $this->unique);
        $otherExamination->setCode('OEXAM' . $this->unique);
        $otherExamination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $otherExamination->setEndDate(new \DateTimeImmutable('2025-06-15'));
        $otherExamination->setStatus(ExaminationStatus::Draft);

        $otherQuestionPaper = new QuestionPaper();
        $otherQuestionPaper->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherQuestionPaper->setAcademicYear($otherYear);
        $otherQuestionPaper->setSubject($otherSubject);
        $otherQuestionPaper->setTitle('Other Question Paper ' . $this->unique);
        $otherQuestionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherQuestionPaper->setDuration(60);
        $otherQuestionPaper->setMaximumMarks(50);
        $otherQuestionPaper->setStatus(QuestionPaperStatus::Draft);

        $entityManager->persist($otherYear);
        $entityManager->persist($otherClass);
        $entityManager->persist($otherSection);
        $entityManager->persist($otherTeacher);
        $entityManager->persist($otherStudent);
        $entityManager->persist($otherSubject);
        $entityManager->persist($otherAssignment);
        $entityManager->persist($otherEnrollment);
        $entityManager->persist($otherExamination);
        $entityManager->persist($otherQuestionPaper);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $refreshedOtherYear = $entityManager->getRepository(AcademicYear::class)->find($otherYearId);
        $this->assertSame(AcademicYearStatus::Active->value, $refreshedOtherYear->getStatus()->value);

        $refreshedOtherClass = $entityManager->getRepository(AcademicClass::class)->find($otherClassId);
        $this->assertSame(ClassStatus::Active->value, $refreshedOtherClass->getStatus()->value);

        $refreshedOtherSection = $entityManager->getRepository(Section::class)->find($otherSectionId);
        $this->assertSame(SectionStatus::Active->value, $refreshedOtherSection->getStatus()->value);

        $refreshedOtherAssignment = $entityManager->getRepository(TeacherAssignment::class)->find($otherAssignment->getId());
        $this->assertSame(TeacherAssignmentStatus::Active->value, $refreshedOtherAssignment->getStatus()->value);

        $refreshedOtherEnrollment = $entityManager->getRepository(StudentEnrollment::class)->find($otherEnrollment->getId());
        $this->assertSame(StudentEnrollmentStatus::Active->value, $refreshedOtherEnrollment->getStatus()->value);

        $refreshedOtherExamination = $entityManager->getRepository(Examination::class)->find($otherExamination->getId());
        $this->assertSame(ExaminationStatus::Draft->value, $refreshedOtherExamination->getStatus()->value);

        $refreshedOtherQuestionPaper = $entityManager->getRepository(QuestionPaper::class)->find($otherQuestionPaper->getId());
        $this->assertSame(QuestionPaperStatus::Draft->value, $refreshedOtherQuestionPaper->getStatus()->value);
    }

    public function testCannotArchiveAlreadyArchivedAcademicYear(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/academic-years/' . $this->testAcademicYearId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }
}
