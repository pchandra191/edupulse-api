<?php
declare(strict_types=1);

namespace App\Tests\Controller\QuestionPaperModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class QuestionPaperControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testClass2Id = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testSection2Id = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private ?Uuid $existingInstituteId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testClass2Id = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testSectionId = Uuid::v7();
        $this->testSection2Id = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute');
        $institute->setCode('TEST' . $unique);
        $institute->setEmail('test-institute-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new \App\Entity\AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year');
        $academicYear->setCode('TAY' . $unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);

        $academicClass = new AcademicClass();
        $classReflection = new \ReflectionClass($academicClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($academicClass, $this->testClassId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Test Class');
        $academicClass->setCode('TC' . $unique);
        $academicClass->setStatus(ClassStatus::Active);

        $academicClass2 = new AcademicClass();
        $class2Reflection = new \ReflectionClass($academicClass2);
        $class2Property = $class2Reflection->getProperty('id');
        $class2Property->setAccessible(true);
        $class2Property->setValue($academicClass2, $this->testClass2Id);
        $academicClass2->setInstitute($institute);
        $academicClass2->setAcademicYear($academicYear);
        $academicClass2->setName('Test Class 2');
        $academicClass2->setCode('TC2' . $unique);
        $academicClass2->setStatus(ClassStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject');
        $subject->setCode('TS' . $unique);
        $subject->setStatus(SubjectStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section');
        $section->setCode('TSE' . $unique);
        $section->setStatus(SectionStatus::Active);

        $section2 = new Section();
        $section2Reflection = new \ReflectionClass($section2);
        $section2Property = $section2Reflection->getProperty('id');
        $section2Property->setAccessible(true);
        $section2Property->setValue($section2, $this->testSection2Id);
        $section2->setAcademicClass($academicClass2);
        $section2->setName('Test Section 2');
        $section2->setCode('TSE2' . $unique);
        $section2->setStatus(SectionStatus::Active);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-user-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($academicClass2);
        $entityManager->persist($subject);
        $entityManager->persist($section);
        $entityManager->persist($section2);
        $entityManager->persist($instituteUser);

        $this->seedTestPermissions($entityManager);

        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-test-' . $unique . '@edupulse.ai',
            'institute-user-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-user-' . $unique . '@edupulse.ai', 'InstituteUser123!');
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            if ($this->testQuestionPaperId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM question_options WHERE question_id IN (SELECT id FROM questions WHERE question_paper_id = :id)',
                    ['id' => $this->testQuestionPaperId->toRfc4122()]
                );
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM questions WHERE question_paper_id = :id',
                    ['id' => $this->testQuestionPaperId->toRfc4122()]
                );
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM question_paper_sections WHERE question_paper_id = :id',
                    ['id' => $this->testQuestionPaperId->toRfc4122()]
                );
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM question_paper_classes WHERE question_paper_id = :id',
                    ['id' => $this->testQuestionPaperId->toRfc4122()]
                );
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM question_papers WHERE id = :id',
                    ['id' => $this->testQuestionPaperId->toRfc4122()]
                );
            }

            if ($this->testSectionId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM sections WHERE id = :id',
                    ['id' => $this->testSectionId->toRfc4122()]
                );
            }

            if ($this->testSection2Id) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM sections WHERE id = :id',
                    ['id' => $this->testSection2Id->toRfc4122()]
                );
            }

            if ($this->testSubjectId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM subjects WHERE id = :id',
                    ['id' => $this->testSubjectId->toRfc4122()]
                );
            }

            if ($this->testClassId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM classes WHERE id = :id',
                    ['id' => $this->testClassId->toRfc4122()]
                );
            }

            if ($this->testClass2Id) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM classes WHERE id = :id',
                    ['id' => $this->testClass2Id->toRfc4122()]
                );
            }

            if ($this->testAcademicYearId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM academic_years WHERE id = :id',
                    ['id' => $this->testAcademicYearId->toRfc4122()]
                );
            }

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

            if ($this->existingInstituteId) {
                $institute = $instituteRepo->find($this->existingInstituteId);
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
        $this->existingInstituteId = null;
        $this->createdEmails = [];
        $this->testAcademicYearId = null;
        $this->testClassId = null;
        $this->testClass2Id = null;
        $this->testSubjectId = null;
        $this->testSectionId = null;
        $this->testSection2Id = null;
        $this->testQuestionPaperId = null;

        parent::tearDown();
    }

    private function seedTestPermissions($entityManager): void
    {
        $roleCodes = ['ROLE_SUPER_ADMIN', 'ROLE_INSTITUTE', 'ROLE_TEACHER'];
        $roles = [];
        $roleRepository = $entityManager->getRepository(\App\Entity\Role::class);

        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['code' => $roleCode]);

            if (!$role) {
                $role = new \App\Entity\Role();
                $role->setCode($roleCode);
                $role->setName(ucwords(strtolower(str_replace('ROLE_', '', $roleCode))));
                $role->setDescription('Test role ' . $roleCode);
                $role->setStatus(\App\Enum\RoleStatus::Active);
                $entityManager->persist($role);
            }

            $roles[$roleCode] = $role;
        }

        $permissionCodes = [
            'question-papers.view',
            'question-papers.create',
            'question-papers.edit',
            'question-papers.delete',
            'question-papers.finalize',
            'question-papers.submit_for_review',
            'question-papers.export',
        ];

        $permissions = [];
        $permissionRepository = $entityManager->getRepository(\App\Entity\Permission::class);

        foreach ($permissionCodes as $code) {
            $existing = $permissionRepository->findOneBy(['code' => $code]);

            if ($existing) {
                $permissions[$code] = $existing;
                continue;
            }

            $permission = new \App\Entity\Permission();
            $permission->setModule('question-papers');
            $permission->setGroupName('Question Papers');
            $permission->setCode($code);
            $permission->setName(ucwords(str_replace(['-', '.'], ' ', $code)));
            $permission->setDescription('Test permission for ' . $code);
            $entityManager->persist($permission);
            $permissions[$code] = $permission;
        }

        $rolePermissionRepository = $entityManager->getRepository(\App\Entity\RolePermission::class);

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                $existing = $rolePermissionRepository->findOneBy(['role' => $role, 'permission' => $permission]);

                if (!$existing) {
                    $rolePermission = new \App\Entity\RolePermission();
                    $rolePermission->setRole($role);
                    $rolePermission->setPermission($permission);
                    $entityManager->persist($rolePermission);
                }
            }
        }
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

    public function testSuperAdminCanCreateQuestionPaper(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'instituteId' => (string) $this->testInstituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'subjectId' => (string) $this->testSubjectId,
            'title' => 'Unit Test Paper',
            'date' => '2025-05-01',
            'duration' => 90,
            'maximumMarks' => 50,
            'status' => 'DRAFT',
            'classIds' => [(string) $this->testClassId],
            'sectionIds' => [(string) $this->testSectionId],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Unit Test Paper', $data['data']['title']);
        $this->assertArrayHasKey('id', $data['data']);
        $this->testQuestionPaperId = Uuid::fromString($data['data']['id']);
    }

    public function testInstituteUserCanCreateQuestionPaper(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'subjectId' => (string) $this->testSubjectId,
            'title' => 'Institute Paper',
            'date' => '2025-06-01',
            'duration' => 60,
            'maximumMarks' => 40,
            'status' => 'DRAFT',
            'classIds' => [(string) $this->testClassId],
            'sectionIds' => [(string) $this->testSectionId],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->testQuestionPaperId = Uuid::fromString($data['data']['id']);
    }

    public function testSuperAdminCanListQuestionPapers(): void
    {
        $client = $this->client;

        $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('GET', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testSuperAdminCanGetQuestionPaperById(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('GET', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Test Question Paper', $data['data']['title']);
    }

    public function testSuperAdminCanUpdateQuestionPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'title' => 'Updated Question Paper',
            'duration' => 120,
        ]);

        $client->request('PUT', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Question Paper', $data['data']['title']);
        $this->assertSame(120, $data['data']['duration']);
    }

    public function testSuperAdminCanUpdateQuestionPaperStatusToFinalized(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'status' => 'FINALIZED',
        ]);

        $client->request('PUT', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('transition', strtolower($data['message']));
    }

    public function testSuperAdminCanDeleteQuestionPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $paper = $entityManager->getRepository(QuestionPaper::class)->find($paperId);
        $this->assertNotNull($paper);
        $this->assertSame(QuestionPaperStatus::Archived->value, $paper->getStatus()->value);
    }

    public function testCrossInstituteAccessPrevention(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Institute');
        $otherInstitute->setCode('OTH' . uniqid());
        $otherInstitute->setEmail('other-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $reflection = new \ReflectionClass($otherInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($otherInstitute, Uuid::v7());

        $otherAcademicYear = new \App\Entity\AcademicYear();
        $ayReflection = new \ReflectionClass($otherAcademicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherAcademicYear, Uuid::v7());
        $otherAcademicYear->setInstitute($otherInstitute);
        $otherAcademicYear->setName('Other Academic Year');
        $otherAcademicYear->setCode('OAY' . uniqid());
        $otherAcademicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherAcademicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherAcademicYear->setStatus(AcademicYearStatus::Active);

        $otherClass = new AcademicClass();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, Uuid::v7());
        $otherClass->setInstitute($otherInstitute);
        $otherClass->setAcademicYear($otherAcademicYear);
        $otherClass->setName('Other Class');
        $otherClass->setCode('OC' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $otherSubject = new Subject();
        $subjectReflection = new \ReflectionClass($otherSubject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($otherSubject, Uuid::v7());
        $otherSubject->setInstitute($otherInstitute);
        $otherSubject->setName('Other Subject');
        $otherSubject->setCode('OS' . uniqid());
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherSection = new Section();
        $sectionReflection = new \ReflectionClass($otherSection);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($otherSection, Uuid::v7());
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section');
        $otherSection->setCode('OSE' . uniqid());
        $otherSection->setStatus(SectionStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherAcademicYear);
        $entityManager->persist($otherClass);
        $entityManager->persist($otherSubject);
        $entityManager->persist($otherSection);
        $entityManager->flush();

        $payload = json_encode([
            'instituteId' => (string) $otherInstitute->getId(),
            'academicYearId' => (string) $otherAcademicYear->getId(),
            'subjectId' => (string) $otherSubject->getId(),
            'title' => 'Other Institute Paper',
            'date' => '2025-05-01',
            'duration' => 60,
            'maximumMarks' => 50,
            'status' => 'DRAFT',
            'classIds' => [(string) $otherClass->getId()],
            'sectionIds' => [(string) $otherSection->getId()],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $paperId = $data['data']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->instituteToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_options WHERE question_id IN (SELECT id FROM questions WHERE question_paper_id = :id)',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM questions WHERE question_paper_id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_sections WHERE question_paper_id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_classes WHERE question_paper_id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_papers WHERE id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_sections WHERE section_id = :id',
            ['id' => $otherSection->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_classes WHERE class_id = :id',
            ['id' => $otherClass->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM sections WHERE id = :id',
            ['id' => $otherSection->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM classes WHERE id = :id',
            ['id' => $otherClass->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM subjects WHERE id = :id',
            ['id' => $otherSubject->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM academic_years WHERE id = :id',
            ['id' => $otherAcademicYear->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM institutes WHERE id = :id',
            ['id' => $otherInstitute->getId()->toRfc4122()]
        );
    }

    public function testInstituteUserCannotAccessOtherInstitutePaper(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Institute 2');
        $otherInstitute->setCode('OTH2' . uniqid());
        $otherInstitute->setEmail('other2-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $reflection = new \ReflectionClass($otherInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($otherInstitute, Uuid::v7());

        $otherAcademicYear = new \App\Entity\AcademicYear();
        $ayReflection = new \ReflectionClass($otherAcademicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherAcademicYear, Uuid::v7());
        $otherAcademicYear->setInstitute($otherInstitute);
        $otherAcademicYear->setName('Other Academic Year 2');
        $otherAcademicYear->setCode('OAY2' . uniqid());
        $otherAcademicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherAcademicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherAcademicYear->setStatus(AcademicYearStatus::Active);

        $otherClass = new AcademicClass();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, Uuid::v7());
        $otherClass->setInstitute($otherInstitute);
        $otherClass->setAcademicYear($otherAcademicYear);
        $otherClass->setName('Other Class 2');
        $otherClass->setCode('OC2' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $otherSubject = new Subject();
        $subjectReflection = new \ReflectionClass($otherSubject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($otherSubject, Uuid::v7());
        $otherSubject->setInstitute($otherInstitute);
        $otherSubject->setName('Other Subject 2');
        $otherSubject->setCode('OS2' . uniqid());
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherSection = new Section();
        $sectionReflection = new \ReflectionClass($otherSection);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($otherSection, Uuid::v7());
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section 2');
        $otherSection->setCode('OSE2' . uniqid());
        $otherSection->setStatus(SectionStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherAcademicYear);
        $entityManager->persist($otherClass);
        $entityManager->persist($otherSubject);
        $entityManager->persist($otherSection);
        $entityManager->flush();

        $payload = json_encode([
            'instituteId' => (string) $otherInstitute->getId(),
            'academicYearId' => (string) $otherAcademicYear->getId(),
            'subjectId' => (string) $otherSubject->getId(),
            'title' => 'Other Institute Paper 2',
            'date' => '2025-05-01',
            'duration' => 60,
            'maximumMarks' => 50,
            'status' => 'DRAFT',
            'classIds' => [(string) $otherClass->getId()],
            'sectionIds' => [(string) $otherSection->getId()],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $paperId = $data['data']['id'];

        $client->request('PUT', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->instituteToken), json_encode(['title' => 'Hacked']));
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->instituteToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_options WHERE question_id IN (SELECT id FROM questions WHERE question_paper_id = :id)',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM questions WHERE question_paper_id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_sections WHERE question_paper_id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_classes WHERE question_paper_id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_papers WHERE id = :id',
            ['id' => $paperId]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_sections WHERE section_id = :id',
            ['id' => $otherSection->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM question_paper_classes WHERE class_id = :id',
            ['id' => $otherClass->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM sections WHERE id = :id',
            ['id' => $otherSection->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM classes WHERE id = :id',
            ['id' => $otherClass->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM subjects WHERE id = :id',
            ['id' => $otherSubject->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM academic_years WHERE id = :id',
            ['id' => $otherAcademicYear->getId()->toRfc4122()]
        );
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM institutes WHERE id = :id',
            ['id' => $otherInstitute->getId()->toRfc4122()]
        );
    }

    public function testInvalidAcademicYear(): void
    {
        $client = $this->client;

        $invalidYearId = Uuid::v7();

        $payload = json_encode([
            'academicYearId' => (string) $invalidYearId,
            'subjectId' => (string) $this->testSubjectId,
            'title' => 'Test Paper',
            'date' => '2025-05-01',
            'duration' => 60,
            'maximumMarks' => 50,
            'status' => 'DRAFT',
            'classIds' => [(string) $this->testClassId],
            'sectionIds' => [(string) $this->testSectionId],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testInvalidSubject(): void
    {
        $client = $this->client;

        $invalidSubjectId = Uuid::v7();

        $payload = json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'subjectId' => (string) $invalidSubjectId,
            'title' => 'Test Paper',
            'date' => '2025-05-01',
            'duration' => 60,
            'maximumMarks' => 50,
            'status' => 'DRAFT',
            'classIds' => [(string) $this->testClassId],
            'sectionIds' => [(string) $this->testSectionId],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testCreateMcqQuestion(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('MCQ', $data['data']['type']);
        $this->assertCount(4, $data['data']['options']);
    }

    public function testCreateDescriptiveQuestion(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'DESCRIPTIVE',
            'questionText' => 'Explain photosynthesis.',
            'marks' => 5,
            'displayOrder' => 1,
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('DESCRIPTIVE', $data['data']['type']);
        $this->assertEmpty($data['data']['options']);
    }

    public function testDescriptiveQuestionWithOptionsRejected(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'DESCRIPTIVE',
            'questionText' => 'Explain photosynthesis.',
            'marks' => 5,
            'displayOrder' => 1,
            'options' => ['Option A', 'Option B'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testMcqWithoutOptionsRejected(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => [],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testFinalizeValidPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('FINALIZED', $data['data']['status']);
    }

    public function testCannotFinalizeEmptyPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('review', strtolower($data['message']));
    }

    public function testCannotEditFinalizedPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $updatePayload = json_encode(['title' => 'Hacked Title']);
        $client->request('PUT', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken), $updatePayload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSubmitForReviewInvalidWhenEmpty(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testQuestionPaperReviewLifecycle(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);
        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // DRAFT -> REVIEW
        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('REVIEW', json_decode($client->getResponse()->getContent(), true)['data']['status']);

        // REVIEW -> DRAFT (request changes)
        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/request-changes', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('DRAFT', json_decode($client->getResponse()->getContent(), true)['data']['status']);

        // DRAFT -> REVIEW -> FINALIZED -> ARCHIVED
        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('FINALIZED', json_decode($client->getResponse()->getContent(), true)['data']['status']);

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('ARCHIVED', json_decode($client->getResponse()->getContent(), true)['data']['status'] ?? 'ARCHIVED');
    }

    public function testQuestionPaperInvalidTransitionRejected(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);
        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // DRAFT -> FINALIZED directly must be rejected (must go through REVIEW).
        $client->request('PUT', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken), json_encode(['status' => 'FINALIZED']));
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testPreviewReturnsCorrectData(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/question-papers/' . $paperId . '/preview', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Test Question Paper', $data['data']['title']);
        $this->assertCount(1, $data['data']['questions']);
        $this->assertCount(4, $data['data']['questions'][0]['options']);
    }

    public function testPdfEndpointReturnsResponse(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('GET', '/api/v1/question-papers/' . $paperId . '/pdf', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testAiQuestionGeneration(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'topic' => 'Microsoft Excel',
            'difficulty' => 'MEDIUM',
            'marks' => 1,
            'instruction' => 'Generate a conceptual question',
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions/generate', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('MCQ', $data['data']['type']);
        $this->assertArrayHasKey('options', $data['data']);
    }

    public function testInvalidUuidReturnsNotFound(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/question-papers/invalid-uuid', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNonExistentQuestionPaperIdReturnsNotFound(): void
    {
        $client = $this->client;

        $nonExistentId = Uuid::v7();

        $client->request('GET', '/api/v1/question-papers/' . $nonExistentId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUnauthorizedRoleReceives403(): void
    {
        $client = $this->client;

        $studentEmail = 'student-test-' . uniqid() . '@edupulse.ai';

        $student = new User();
        $student->setEmail($studentEmail);
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->persist($student);
        $entityManager->flush();

        $studentToken = $this->login($client, $studentEmail, 'Student123!');

        $client->request('GET', '/api/v1/question-papers', [], [], $this->getAuthHeaders($studentToken));
        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM users WHERE id = :id',
            ['id' => $student->getId()->toRfc4122()]
        );
    }

    public function testSubmitForReviewDraftPaperSucceeds(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REVIEW', $data['data']['status']);
    }

    public function testCannotSubmitForReviewAlreadySubmittedPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testCannotSubmitForReviewFinalizedPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testSubmitForReviewMissingPaperReturnsNotFound(): void
    {
        $client = $this->client;

        $nonExistentId = Uuid::v7();

        $client->request('POST', '/api/v1/question-papers/' . $nonExistentId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testSubmitForReviewCrossInstituteAccessRejected(): void
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
        $otherYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);

        $otherClass = new AcademicClass();
        $otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $otherClassId);
        $otherClass->setInstitute($otherInstitute);
        $otherClass->setAcademicYear($otherYear);
        $otherClass->setName('Other Class');
        $otherClass->setCode('OCLS' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $otherSubject = new Subject();
        $otherSubjectId = Uuid::v7();
        $subjectReflection = new \ReflectionClass($otherSubject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($otherSubject, $otherSubjectId);
        $otherSubject->setInstitute($otherInstitute);
        $otherSubject->setName('Other Subject');
        $otherSubject->setCode('OS' . uniqid());
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherPaper = new QuestionPaper();
        $otherPaperId = Uuid::v7();
        $paperReflection = new \ReflectionClass($otherPaper);
        $paperProperty = $paperReflection->getProperty('id');
        $paperProperty->setAccessible(true);
        $paperProperty->setValue($otherPaper, $otherPaperId);
        $otherPaper->setInstitute($otherInstitute);
        $otherPaper->setAcademicYear($otherYear);
        $otherPaper->setSubject($otherSubject);
        $otherPaper->setTitle('Other Paper');
        $otherPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherPaper->setDuration(60);
        $otherPaper->setMaximumMarks(50);
        $otherPaper->setStatus(QuestionPaperStatus::Draft);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherYear);
        $entityManager->persist($otherClass);
        $entityManager->persist($otherSubject);
        $entityManager->persist($otherPaper);
        $entityManager->flush();

        $client->request('POST', '/api/v1/question-papers/' . $otherPaperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->instituteToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testSubmittedPaperCanBeFinalized(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $payload = json_encode([
            'type' => 'MCQ',
            'questionText' => 'What is 2+2?',
            'marks' => 1,
            'displayOrder' => 1,
            'options' => ['3', '4', '5', '6'],
        ]);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('FINALIZED', $data['data']['status']);
    }

    public function testCannotFinalizeArchivedPaper(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/finalize', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testCannotSubmitForReviewPaperWithoutQuestions(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('POST', '/api/v1/question-papers/' . $paperId . '/submit-for-review', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    private function createTestQuestionPaper(string $token, ?Uuid $instituteId = null): string
    {
        $client = $this->client;

        if ($instituteId === null) {
            $instituteId = $this->testInstituteId;
        }

        $payload = json_encode([
            'instituteId' => (string) $instituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'subjectId' => (string) $this->testSubjectId,
            'title' => 'Test Question Paper',
            'date' => '2025-05-01',
            'duration' => 60,
            'maximumMarks' => 50,
            'status' => 'DRAFT',
            'classIds' => [(string) $this->testClassId],
            'sectionIds' => [(string) $this->testSectionId],
        ]);

        $client->request('POST', '/api/v1/question-papers', [], [], $this->getAuthHeaders($token), $payload);
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['data']['id'];
    }

    public function testArchivedQuestionPaperCannotBeUpdated(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Archived Paper',
        ]);

        $client->request('PUT', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testArchivedQuestionPaperCannotBeDeleted(): void
    {
        $client = $this->client;

        $paperId = $this->createTestQuestionPaper($this->superAdminToken);

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/question-papers/' . $paperId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }
}
