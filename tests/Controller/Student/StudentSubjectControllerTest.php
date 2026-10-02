<?php

declare(strict_types=1);

namespace App\Tests\Controller\Student;

use App\Entity\Analysis;
use App\Entity\Institute;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Enum\InstituteStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StudentSubjectControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?Institute $institute = null;
    private ?Subject $mathSubject = null;
    private ?Subject $englishSubject = null;
    private ?Subject $scienceSubject = null;
    private ?Uuid $instituteStudentId = null;
    private ?Uuid $independentStudentId = null;
    private ?Uuid $otherStudentId = null;
    private ?string $instituteStudentToken = null;
    private ?string $independentStudentToken = null;
    private ?string $otherStudentToken = null;
    private array $createdEmails = [];
    private ?string $instituteCode = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->instituteCode = 'INS' . $unique;

        // Create institute
        $this->institute = new Institute();
        $this->institute->setName('Subject Test Institute ' . $unique);
        $this->institute->setCode($this->instituteCode);
        $this->institute->setEmail('subject-test-' . $unique . '@edupulse.ai');
        $this->institute->setStatus(InstituteStatus::Active);

        // Create active subjects for the institute
        $this->mathSubject = new Subject();
        $this->mathSubject->setInstitute($this->institute);
        $this->mathSubject->setName('Mathematics');
        $this->mathSubject->setCode('MATH');
        $this->mathSubject->setStatus(SubjectStatus::Active);

        $this->englishSubject = new Subject();
        $this->englishSubject->setInstitute($this->institute);
        $this->englishSubject->setName('English');
        $this->englishSubject->setCode('ENG');
        $this->englishSubject->setStatus(SubjectStatus::Active);

        $this->scienceSubject = new Subject();
        $this->scienceSubject->setInstitute($this->institute);
        $this->scienceSubject->setName('Science');
        $this->scienceSubject->setCode('SCI');
        $this->scienceSubject->setStatus(SubjectStatus::Active);

        // Create institute student
        $this->instituteStudentId = Uuid::v7();
        $instituteStudent = new User();
        $reflection = new \ReflectionClass($instituteStudent);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($instituteStudent, $this->instituteStudentId);
        $instituteStudentEmail = 'institute-student-' . $unique . '@edupulse.ai';
        $instituteStudent->setEmail($instituteStudentEmail);
        $instituteStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $instituteStudent->setRoles([UserRole::Student->value]);
        $instituteStudent->setStatus(UserStatus::Active);
        $instituteStudent->verify();
        $instituteStudent->setFirstName('Institute');
        $instituteStudent->setLastName('Student');
        $instituteStudent->setInstitute($this->institute);

        // Create independent student
        $this->independentStudentId = Uuid::v7();
        $independentStudent = new User();
        $indReflection = new \ReflectionClass($independentStudent);
        $indProperty = $indReflection->getProperty('id');
        $indProperty->setAccessible(true);
        $indProperty->setValue($independentStudent, $this->independentStudentId);
        $independentStudentEmail = 'independent-student-' . $unique . '@edupulse.ai';
        $independentStudent->setEmail($independentStudentEmail);
        $independentStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $independentStudent->setRoles([UserRole::Student->value]);
        $independentStudent->setStatus(UserStatus::Active);
        $independentStudent->verify();
        $independentStudent->setFirstName('Independent');
        $independentStudent->setLastName('Student');

        // Create other student (for isolation test)
        $this->otherStudentId = Uuid::v7();
        $otherStudent = new User();
        $otherReflection = new \ReflectionClass($otherStudent);
        $otherProperty = $otherReflection->getProperty('id');
        $otherProperty->setAccessible(true);
        $otherProperty->setValue($otherStudent, $this->otherStudentId);
        $otherStudentEmail = 'other-student-' . $unique . '@edupulse.ai';
        $otherStudent->setEmail($otherStudentEmail);
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other');
        $otherStudent->setLastName('Student');

        $entityManager->persist($this->institute);
        $entityManager->persist($this->mathSubject);
        $entityManager->persist($this->englishSubject);
        $entityManager->persist($this->scienceSubject);
        $entityManager->persist($instituteStudent);
        $entityManager->persist($independentStudent);
        $entityManager->persist($otherStudent);
        $entityManager->flush();

        $this->createdEmails = [
            $instituteStudentEmail,
            $independentStudentEmail,
            $otherStudentEmail,
        ];

        $this->instituteStudentToken = $this->login($instituteStudentEmail, 'Student123!');
        $this->independentStudentToken = $this->login($independentStudentEmail, 'Student123!');
        $this->otherStudentToken = $this->login($otherStudentEmail, 'Student123!');
    }

    public function testInstituteStudentReceivesInstituteSubjects(): void
    {
        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->instituteStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        $this->assertCount(3, $subjects);

        $names = array_column($subjects, 'name');
        $this->assertContains('Mathematics', $names);
        $this->assertContains('English', $names);
        $this->assertContains('Science', $names);

        foreach ($subjects as $subject) {
            $this->assertSame('INSTITUTE', $subject['source']);
            $this->assertNotEmpty($subject['id']);
        }
    }

    public function testInstituteStudentReceivesPreviouslyUsedSubjects(): void
    {
        // Create history analysis with a custom subject not in institute
        $this->createAnalysisWithSubject($this->instituteStudentId, 'History');

        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->instituteStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        $this->assertCount(4, $subjects);

        $names = array_column($subjects, 'name');
        $this->assertContains('Mathematics', $names);
        $this->assertContains('English', $names);
        $this->assertContains('Science', $names);
        $this->assertContains('History', $names);

        // Verify History is STUDENT_HISTORY
        $historySubject = $this->findSubjectByName($subjects, 'History');
        $this->assertNotNull($historySubject);
        $this->assertSame('STUDENT_HISTORY', $historySubject['source']);
        $this->assertSame('History', $historySubject['id']);
    }

    public function testDuplicateInstituteAndHistorySubjectReturnedOnce(): void
    {
        // Create history analysis with subject matching an institute subject
        $this->createAnalysisWithSubject($this->instituteStudentId, 'Mathematics');

        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->instituteStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        // Should still have 3 subjects (deduplicated)
        $this->assertCount(3, $subjects);

        $names = array_column($subjects, 'name');
        $this->assertContains('Mathematics', $names);
        $this->assertContains('English', $names);
        $this->assertContains('Science', $names);

        // Verify Mathematics has INSTITUTE_AND_HISTORY source and institute UUID
        $mathSubject = $this->findSubjectByName($subjects, 'Mathematics');
        $this->assertNotNull($mathSubject);
        $this->assertSame('INSTITUTE_AND_HISTORY', $mathSubject['source']);
        $this->assertSame((string) $this->mathSubject->getId(), $mathSubject['id']);
    }

    public function testIndependentStudentReceivesPreviouslyUsedSubjects(): void
    {
        // Create history analysis for independent student
        $this->createAnalysisWithSubject($this->independentStudentId, 'Biology');

        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->independentStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        $this->assertCount(1, $subjects);
        $this->assertSame('Biology', $subjects[0]['name']);
        $this->assertSame('STUDENT_HISTORY', $subjects[0]['source']);
        $this->assertSame('Biology', $subjects[0]['id']);
    }

    public function testIndependentStudentDoesNotReceiveInstituteSubjects(): void
    {
        // Independent student has no history in this test
        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->independentStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        $this->assertCount(0, $subjects);

        // Verify no institute subjects leaked
        $names = array_column($subjects, 'name');
        $this->assertNotContains('Mathematics', $names);
        $this->assertNotContains('English', $names);
        $this->assertNotContains('Science', $names);
    }

    public function testStudentCannotAccessAnotherStudentHistory(): void
    {
        // Create history for independent student A
        $this->createAnalysisWithSubject($this->independentStudentId, 'UniqueSubjectA');

        // Student B tries to access
        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->otherStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        // Student B should not see Student A's history
        $names = array_column($subjects, 'name');
        $this->assertNotContains('UniqueSubjectA', $names);
    }

    public function testNullEmptyHistoricalSubjectNamesAreIgnored(): void
    {
        // Create analyses with null, empty, and whitespace subject names
        $this->createAnalysisWithSubject($this->independentStudentId, null);
        $this->createAnalysisWithSubject($this->independentStudentId, '');
        $this->createAnalysisWithSubject($this->independentStudentId, '   ');

        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->independentStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        // All should be ignored
        $this->assertCount(0, $subjects);
    }

    public function testAlphabeticalOrdering(): void
    {
        // Create history with unordered subjects
        $this->createAnalysisWithSubject($this->independentStudentId, 'Zebra');
        $this->createAnalysisWithSubject($this->independentStudentId, 'Apple');
        $this->createAnalysisWithSubject($this->independentStudentId, 'Mango');

        $this->client->request('GET', '/api/v1/student/subjects', [], [], $this->authHeaders($this->independentStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $subjects = $data['data'];

        $this->assertCount(3, $subjects);
        $names = array_column($subjects, 'name');
        $this->assertSame(['Apple', 'Mango', 'Zebra'], $names);
    }

    private function createAnalysisWithSubject(Uuid $studentId, ?string $subjectName): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $student = $entityManager->getReference(User::class, $studentId);

        $analysis = new Analysis();
        $analysis->setStudent($student);
        $analysis->setAnalysisId(Uuid::v7()->toRfc4122());
        $analysis->setSource(AnalysisSource::ExternalUpload->value);
        $analysis->setStatus(AnalysisStatus::Draft->value);
        if ($subjectName !== null) {
            $analysis->setSubjectName($subjectName);
        }

        $entityManager->persist($analysis);
        $entityManager->flush();
    }

    private function findSubjectByName(array $subjects, string $name): ?array
    {
        foreach ($subjects as $subject) {
            if ($subject['name'] === $name) {
                return $subject;
            }
        }
        return null;
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('accessToken', $data['data'] ?? []);

        return $data['data']['accessToken'];
    }

    private function authHeaders(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    protected function tearDown(): void
    {
        if ($this->client === null) {
            parent::tearDown();
            return;
        }

        try {
            $container = $this->client->getContainer();
            $entityManager = $container->get('doctrine')->getManager();

            // Remove users (cascades to analyses)
            $userRepo = $entityManager->getRepository(User::class);
            foreach ($userRepo->findBy(['email' => $this->createdEmails]) as $user) {
                $entityManager->remove($user);
            }

            // Remove institute by code (cascades to subjects)
            $instituteRepo = $entityManager->getRepository(Institute::class);
            $institute = $instituteRepo->findOneBy(['code' => $this->instituteCode]);
            if ($institute !== null) {
                $entityManager->remove($institute);
            }

            $entityManager->flush();
        } catch (\Throwable) {
        }

        $this->client = null;
        $this->instituteStudentToken = null;
        $this->independentStudentToken = null;
        $this->otherStudentToken = null;
        $this->createdEmails = [];

        parent::tearDown();
    }
}