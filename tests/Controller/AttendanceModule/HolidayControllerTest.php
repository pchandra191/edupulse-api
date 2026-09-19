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
use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

class HolidayControllerTest extends WebTestCase
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
        $superAdmin->setEmail('superadmin-holiday-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Holidays');
        $institute->setCode('TESTHOL' . $this->unique);
        $institute->setEmail('test-holiday-' . $this->unique . '@edupulse.ai');
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
        $teacherEmail = 'teacher-holiday-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $teacherEmail;
        $teacher->setEmail($teacherEmail);
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);

        $student = new User();
        $studentEmail = 'student-holiday-test-' . $this->unique . '@edupulse.ai';
        $this->createdEmails[] = $studentEmail;
        $student->setEmail($studentEmail);
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

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

        $this->createdEmails[] = 'superadmin-holiday-test-' . $this->unique . '@edupulse.ai';

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($teacher);
        $entityManager->persist($student);
        $entityManager->persist($teacherAssignment);
        $entityManager->flush();

        $this->superAdminToken = $this->login($this->client, 'superadmin-holiday-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->teacherToken = $this->login($this->client, $teacherEmail, 'Teacher123!');
        $this->studentToken = $this->login($this->client, $studentEmail, 'Student123!');
    }

    protected function tearDown(): void
    {
        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $holidayRepo = $entityManager->getRepository(Holiday::class);
            $holidays = $holidayRepo->createQueryBuilder('h')
                ->where('h.name LIKE :prefix')
                ->setParameter('prefix', '%' . $this->unique . '%')
                ->getQuery()
                ->getResult();
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

    private function getUniqueDate(int $month, int $day): string
    {
        $hash = crc32($this->unique . $month . $day);
        $year = 2025;
        $month = ($hash % 12) + 1;
        $day = ($hash % 28) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public function testListHolidaysReturnsPaginatedResponse(): void
    {
        $this->client->request('GET', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);
        $this->assertArrayHasKey('pagination', $data['data']);
    }

    public function testSuperAdminCanCreateHoliday(): void
    {
        $date = $this->getUniqueDate(12, 25);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'Christmas Day ' . $this->unique,
            'description' => 'Christmas holiday',
            'enabled' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Holiday created successfully.', $data['message']);
        $this->assertSame('Christmas Day ' . $this->unique, $data['data']['name']);
        $this->assertSame($date, $data['data']['date']);
        $this->assertNotNull($data['data']['id']);
    }

    public function testTeacherCanCreateHoliday(): void
    {
        $date = $this->getUniqueDate(7, 4);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->teacherToken), json_encode([
            'date' => $date,
            'name' => 'Independence Day ' . $this->unique,
            'enabled' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Independence Day ' . $this->unique, $data['data']['name']);
    }

    public function testCreateHolidayValidationFailsOnInvalidDate(): void
    {
        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => 'not-a-date',
            'name' => 'Test Holiday',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testCreateHolidayValidationFailsOnMissingName(): void
    {
        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $this->getUniqueDate(1, 1),
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testCreateDuplicateHolidayFails(): void
    {
        $date = $this->getUniqueDate(6, 15);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'First Holiday ' . $this->unique,
        ]));

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'Duplicate Holiday ' . $this->unique,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGetHoliday(): void
    {
        $date = $this->getUniqueDate(9, 15);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'Teacher Day ' . $this->unique,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $holidayId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/holidays/' . $holidayId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher Day ' . $this->unique, $data['data']['name']);
    }

    public function testGetHolidayNotFound(): void
    {
        $this->client->request('GET', '/api/v1/holidays/' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testUpdateHoliday(): void
    {
        $date = $this->getUniqueDate(10, 10);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'Initial Name ' . $this->unique,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $holidayId = $data['data']['id'];

        $this->client->request('PUT', '/api/v1/holidays/' . $holidayId, [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'name' => 'Updated Name ' . $this->unique,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Name ' . $this->unique, $data['data']['name']);
    }

    public function testDeleteHoliday(): void
    {
        $date = $this->getUniqueDate(11, 11);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'Delete Test Holiday ' . $this->unique,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $holidayId = $data['data']['id'];

        $this->client->request('DELETE', '/api/v1/holidays/' . $holidayId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testListHolidaysWithDateFilter(): void
    {
        $date = $this->getUniqueDate(3, 20);

        $this->client->request('POST', '/api/v1/holidays', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'date' => $date,
            'name' => 'Filtered Holiday ' . $this->unique,
            'enabled' => true,
        ]));

        $this->client->request('GET', '/api/v1/holidays?date=' . $date, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['items']);
        $this->assertSame('Filtered Holiday ' . $this->unique, $data['data']['items'][0]['name']);
    }

    public function testUnauthenticatedAccessDenied(): void
    {
        $this->client->request('GET', '/api/v1/holidays');

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
    }
}
