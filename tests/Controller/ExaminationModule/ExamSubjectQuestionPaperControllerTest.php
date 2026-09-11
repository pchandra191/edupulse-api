<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExaminationModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ExaminationStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExamSubjectQuestionPaperControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private string $unique = '';

    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testExaminationId = null;
    private ?Uuid $testExamSubjectId = null;
    private ?Uuid $testQuestionPaperId = null;
    private array $sourceQuestionIds = [];
    private array $sourceOptionIds = [];
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;

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
        $this->testSubjectId = Uuid::v7();
        $this->testExaminationId = Uuid::v7();
        $this->testExamSubjectId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-qp-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();
        $superAdmin->setFirstName('Super');
        $superAdmin->setLastName('Admin');

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for QP');
        $institute->setCode('TESTQP' . $this->unique);
        $institute->setEmail('test-institute-qp-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year QP');
        $academicYear->setCode('AYQP' . $this->unique);
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
        $academicClass->setName('Test Class QP');
        $academicClass->setCode('CLSQP' . $this->unique);
        $academicClass->setStatus(\App\Enum\ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section QP');
        $section->setCode('SECQP' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject QP');
        $subject->setCode('SUBQP' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $examination = new Examination();
        $examination->setInstitute($institute);
        $examination->setAcademicYear($academicYear);
        $examination->setAcademicClass($academicClass);
        $examination->setName('Test Examination QP');
        $examination->setCode('TEQP' . $this->unique);
        $examination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $examination->setEndDate(new \DateTimeImmutable('2025-06-15'));
        $examination->setStatus(ExaminationStatus::Draft);

        $examReflection = new \ReflectionClass($examination);
        $examProperty = $examReflection->getProperty('id');
        $examProperty->setAccessible(true);
        $examProperty->setValue($examination, $this->testExaminationId);

        $examSubject = new ExamSubject();
        $examSubject->setExamination($examination);
        $examSubject->setSubject($subject);
        $examSubject->setSection($section);
        $examSubject->setMaxMarks(100);
        $examSubject->setPassingMarks(40);
        $examSubject->setStatus(ExaminationStatus::Draft);

        $esReflection = new \ReflectionClass($examSubject);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSubject, $this->testExamSubjectId);

        $questionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $this->testQuestionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Question Paper QP');
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks(100);
        $questionPaper->setStatus(QuestionPaperStatus::Finalized);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-qp-test-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($examination);
        $entityManager->persist($examSubject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($instituteUser);
        $entityManager->flush();

        $questionPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $question1 = new Question();
        $question1->setQuestionPaper($questionPaper);
        $question1->setType(\App\Enum\QuestionType::MCQ);
        $question1->setQuestionText('What is the capital of France?');
        $question1->setMarks(5);
        $question1->setDisplayOrder(1);
        $question1->setReferenceAnswer(null);

        $option1a = new QuestionOption();
        $option1a->setQuestion($question1);
        $option1a->setOptionText('Paris');
        $option1a->setDisplayOrder(1);
        $option1a->setIsCorrect(true);

        $option1b = new QuestionOption();
        $option1b->setQuestion($question1);
        $option1b->setOptionText('London');
        $option1b->setDisplayOrder(2);
        $option1b->setIsCorrect(false);

        $option1c = new QuestionOption();
        $option1c->setQuestion($question1);
        $option1c->setOptionText('Berlin');
        $option1c->setDisplayOrder(3);
        $option1c->setIsCorrect(false);

        $question2 = new Question();
        $question2->setQuestionPaper($questionPaper);
        $question2->setType(\App\Enum\QuestionType::Descriptive);
        $question2->setQuestionText('Explain photosynthesis.');
        $question2->setMarks(10);
        $question2->setDisplayOrder(2);
        $question2->setReferenceAnswer('Photosynthesis is the process by which green plants convert light energy into chemical energy.');

        $entityManager->persist($question1);
        $entityManager->persist($question2);
        $entityManager->persist($option1a);
        $entityManager->persist($option1b);
        $entityManager->persist($option1c);
        $entityManager->flush();

        $this->sourceQuestionIds = [$question1->getId()->toRfc4122(), $question2->getId()->toRfc4122()];
        $this->sourceOptionIds = [
            $option1a->getId()->toRfc4122(),
            $option1b->getId()->toRfc4122(),
            $option1c->getId()->toRfc4122(),
        ];

        $this->createdEmails = [
            'superadmin-qp-test-' . $this->unique . '@edupulse.ai',
            'institute-qp-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('superadmin-qp-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login('institute-qp-test-' . $this->unique . '@edupulse.ai', 'Institute123!');
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

        parent::tearDown();
    }

    private function login(string $email, string $password): ?string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $this->client->getResponse();
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

    public function testSuperAdminCanAttachQuestionPaperToExamSubject(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotSame((string) $this->testQuestionPaperId, $data['data']['questionPaper']['id']);
        $this->assertSame('Test Question Paper QP (Copy)', $data['data']['questionPaper']['title']);
        $this->assertSame(2, $data['data']['questionPaper']['totalQuestions']);
        $this->assertSame('DRAFT', $data['data']['questionPaper']['status']);
    }

    public function testInstituteUserCanAttachQuestionPaperToExamSubject(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testCannotAttachQuestionPaperFromAnotherSubject(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherSubject = new Subject();
        $otherSubject->setName('Other Subject');
        $otherSubject->setCode('OS' . $this->unique);
        $otherSubject->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherQuestionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($otherQuestionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($otherQuestionPaper, Uuid::v7());
        $otherQuestionPaper->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherQuestionPaper->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherQuestionPaper->setSubject($otherSubject);
        $otherQuestionPaper->setTitle('Other Subject Paper');
        $otherQuestionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherQuestionPaper->setDuration(60);
        $otherQuestionPaper->setMaximumMarks(100);
        $otherQuestionPaper->setStatus(QuestionPaperStatus::Finalized);

        $entityManager->persist($otherSubject);
        $entityManager->persist($otherQuestionPaper);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $otherQuestionPaper->getId(),
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('cannot be attached', strtolower($data['message']));
    }

    public function testCannotAttachQuestionPaperFromAnotherInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Institute');
        $otherInstitute->setCode('OI' . $this->unique);
        $otherInstitute->setEmail('other-inst-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherSubject = new Subject();
        $otherSubject->setName('Other Subject');
        $otherSubject->setCode('OS' . $this->unique);
        $otherSubject->setInstitute($otherInstitute);
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherAcademicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($otherAcademicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherAcademicYear, Uuid::v7());
        $otherAcademicYear->setInstitute($otherInstitute);
        $otherAcademicYear->setName('Other Academic Year');
        $otherAcademicYear->setCode('OAY' . $this->unique);
        $otherAcademicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherAcademicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherAcademicYear->setStatus(AcademicYearStatus::Active);
        $otherAcademicYear->setIsActive(true);

        $otherQuestionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($otherQuestionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($otherQuestionPaper, Uuid::v7());
        $otherQuestionPaper->setInstitute($otherInstitute);
        $otherQuestionPaper->setAcademicYear($otherAcademicYear);
        $otherQuestionPaper->setSubject($otherSubject);
        $otherQuestionPaper->setTitle('Other Institute Paper');
        $otherQuestionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherQuestionPaper->setDuration(60);
        $otherQuestionPaper->setMaximumMarks(100);
        $otherQuestionPaper->setStatus(QuestionPaperStatus::Finalized);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherSubject);
        $entityManager->persist($otherAcademicYear);
        $entityManager->persist($otherQuestionPaper);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $otherQuestionPaper->getId(),
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('same institute', strtolower($data['message']));
    }

    public function testCannotAttachQuestionPaperToNonDraftExamination(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examination = $entityManager->getRepository(Examination::class)->find($this->testExaminationId);
        $examination->setStatus(ExaminationStatus::Ongoing);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('draft', strtolower($data['message']));
    }

    public function testSuperAdminCanRemoveQuestionPaperFromExamSubject(): void
    {
        $client = $this->client;

        $attachPayload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);
        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $attachPayload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($data['data']['questionPaper']);
    }

    public function testCannotRemoveQuestionPaperFromNonDraftExamination(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examination = $entityManager->getRepository(Examination::class)->find($this->testExaminationId);
        $examination->setStatus(ExaminationStatus::Ongoing);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testReplaceExistingQuestionPaper(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $newQuestionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($newQuestionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($newQuestionPaper, Uuid::v7());
        $newQuestionPaper->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $newQuestionPaper->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $newQuestionPaper->setSubject($entityManager->getRepository(Subject::class)->find($this->testSubjectId));
        $newQuestionPaper->setTitle('New Question Paper');
        $newQuestionPaper->setDate(new \DateTimeImmutable('2025-05-02'));
        $newQuestionPaper->setDuration(90);
        $newQuestionPaper->setMaximumMarks(150);
        $newQuestionPaper->setStatus(QuestionPaperStatus::Finalized);
        $entityManager->persist($newQuestionPaper);
        $entityManager->flush();

        $attachPayload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);
        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $attachPayload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $replacePayload = json_encode([
            'questionPaperId' => (string) $newQuestionPaper->getId(),
        ]);
        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $replacePayload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotSame((string) $newQuestionPaper->getId(), $data['data']['questionPaper']['id']);
        $this->assertSame('New Question Paper (Copy)', $data['data']['questionPaper']['title']);

        $oldPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $this->assertNotNull($oldPaper);
    }

    public function testAttachCreatesCloneWithDifferentId(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $attachedPaperId = $data['data']['questionPaper']['id'];

        $this->assertNotSame((string) $this->testQuestionPaperId, $attachedPaperId);
    }

    public function testSourcePaperRemainsUnchangedAfterAttach(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $sourcePaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $this->assertNotNull($sourcePaper);
        $this->assertSame('Test Question Paper QP', $sourcePaper->getTitle());
        $this->assertSame(QuestionPaperStatus::Finalized, $sourcePaper->getStatus());

        $sourceQuestions = $entityManager->getRepository(Question::class)
            ->createQueryBuilder('q')
            ->where('q.questionPaper = :paper')
            ->setParameter('paper', $this->testQuestionPaperId)
            ->getQuery()
            ->getResult();

        $this->assertCount(2, $sourceQuestions);
    }

    public function testClonedPaperHasDraftStatus(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('DRAFT', $data['data']['questionPaper']['status']);
    }

    public function testClonedPaperHasSameQuestionCount(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['questionPaper']['totalQuestions']);
    }

    public function testClonedQuestionsHaveDifferentIds(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $clonedQuestionIds = array_column($questionData['data'], 'id');

        foreach ($this->sourceQuestionIds as $sourceId) {
            $this->assertNotContains($sourceId, $clonedQuestionIds);
        }
        $this->assertCount(2, $clonedQuestionIds);
    }

    public function testMcqOptionsClonedWithNewIds(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $mcqQuestion = null;
        foreach ($questionData['data'] as $q) {
            if ($q['type'] === 'MCQ') {
                $mcqQuestion = $q;
                break;
            }
        }

        $this->assertNotNull($mcqQuestion);
        $this->assertCount(3, $mcqQuestion['options']);

        $clonedOptionIds = array_column($mcqQuestion['options'], 'id');
        foreach ($this->sourceOptionIds as $sourceId) {
            $this->assertNotContains($sourceId, $clonedOptionIds);
        }
    }

    public function testCorrectAnswerFlagPreserved(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $mcqQuestion = null;
        foreach ($questionData['data'] as $q) {
            if ($q['type'] === 'MCQ') {
                $mcqQuestion = $q;
                break;
            }
        }

        $this->assertNotNull($mcqQuestion);

        $correctCount = 0;
        foreach ($mcqQuestion['options'] as $option) {
            if ($option['isCorrect']) {
                $correctCount++;
                $this->assertSame('Paris', $option['text']);
            }
        }
        $this->assertSame(1, $correctCount);
    }

    public function testQuestionOrderingPreserved(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(1, $questionData['data'][0]['displayOrder']);
        $this->assertSame(2, $questionData['data'][1]['displayOrder']);
    }

    public function testMarksPreserved(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(5, $questionData['data'][0]['marks']);
        $this->assertSame(10, $questionData['data'][1]['marks']);
    }

    public function testQuestionTypePreserved(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('MCQ', $questionData['data'][0]['type']);
        $this->assertSame('DESCRIPTIVE', $questionData['data'][1]['type']);
    }

    public function testQuestionTextPreserved(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('What is the capital of France?', $questionData['data'][0]['questionText']);
        $this->assertSame('Explain photosynthesis.', $questionData['data'][1]['questionText']);
    }

    public function testReferenceAnswersPreserved(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));

        $questionData = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($questionData['data'][0]['referenceAnswer']);
        $this->assertSame('Photosynthesis is the process by which green plants convert light energy into chemical energy.', $questionData['data'][1]['referenceAnswer']);
    }

    public function testExamSubjectReferencesNewPaper(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $attachedPaperId = $data['data']['questionPaper']['id'];

        $this->assertNotSame((string) $this->testQuestionPaperId, $attachedPaperId);

        $client->request('GET', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $subjectData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($attachedPaperId, $subjectData['data']['questionPaper']['id']);
    }

    public function testEditingClonedPaperDoesNotAffectSource(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));
        $questionData = json_decode($client->getResponse()->getContent(), true);
        $clonedQuestionId = $questionData['data'][0]['id'];

        $updatePayload = json_encode([
            'questionText' => 'Updated question text',
        ]);

        $client->request('PUT', '/api/v1/question-papers/' . $clonedPaperId . '/questions/' . $clonedQuestionId, [], [], $this->getAuthHeaders($this->superAdminToken), $updatePayload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/question-papers/' . $this->testQuestionPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));
        $sourceQuestionData = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('What is the capital of France?', $sourceQuestionData['data'][0]['questionText']);
    }

    public function testEditingSourceDoesNotAffectClone(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $data = json_decode($client->getResponse()->getContent(), true);
        $clonedPaperId = $data['data']['questionPaper']['id'];

        $client->request('GET', '/api/v1/question-papers/' . $this->testQuestionPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));
        $sourceQuestionData = json_decode($client->getResponse()->getContent(), true);
        $sourceQuestionId = $sourceQuestionData['data'][0]['id'];

        $updatePayload = json_encode([
            'questionText' => 'Modified source question text',
        ]);

        $client->request('PUT', '/api/v1/question-papers/' . $this->testQuestionPaperId . '/questions/' . $sourceQuestionId, [], [], $this->getAuthHeaders($this->superAdminToken), $updatePayload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/question-papers/' . $clonedPaperId . '/questions', [], [], $this->getAuthHeaders($this->superAdminToken));
        $clonedQuestionData = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('What is the capital of France?', $clonedQuestionData['data'][0]['questionText']);
    }

    public function testStandaloneCloneEndpointCreatesNewPaper(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/question-papers/' . $this->testQuestionPaperId . '/clone', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotSame((string) $this->testQuestionPaperId, $data['data']['id']);
        $this->assertSame('Test Question Paper QP (Copy)', $data['data']['title']);
        $this->assertSame('DRAFT', $data['data']['status']);
        $this->assertSame(2, $data['data']['questionsCount']);
    }

    public function testExamSubjectSyncedWithClonedPaperOnAttach(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setDuration(30);
        $examSubject->setMaxMarks(50);
        $examSubject->setPassingMarks(20);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(30, $data['data']['duration']);
        $this->assertSame(50, $data['data']['maxMarks']);
        $this->assertSame(20, $data['data']['passingMarks']);
        $this->assertSame(50, $data['data']['questionPaper']['totalMarks']);
        $this->assertSame('Super Admin', $data['data']['questionPaper']['createdByName']);
    }

    public function testUnauthorizedUserCannotCloneInaccessiblePaper(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Institute Clone Test');
        $otherInstitute->setCode('OICT' . $this->unique);
        $otherInstitute->setEmail('other-inst-clone-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherSubject = new Subject();
        $otherSubject->setName('Other Subject Clone');
        $otherSubject->setCode('OSCT' . $this->unique);
        $otherSubject->setInstitute($otherInstitute);
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherAcademicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($otherAcademicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherAcademicYear, Uuid::v7());
        $otherAcademicYear->setInstitute($otherInstitute);
        $otherAcademicYear->setName('Other AY Clone');
        $otherAcademicYear->setCode('OAYCT' . $this->unique);
        $otherAcademicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherAcademicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherAcademicYear->setStatus(AcademicYearStatus::Active);
        $otherAcademicYear->setIsActive(true);

        $otherPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($otherPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($otherPaper, Uuid::v7());
        $otherPaper->setInstitute($otherInstitute);
        $otherPaper->setAcademicYear($otherAcademicYear);
        $otherPaper->setSubject($otherSubject);
        $otherPaper->setTitle('Other Institute Clone Paper');
        $otherPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherPaper->setDuration(60);
        $otherPaper->setMaximumMarks(100);
        $otherPaper->setStatus(QuestionPaperStatus::Finalized);

        $otherUser = new User();
        $otherUser->setEmail('other-user-clone-' . $this->unique . '@edupulse.ai');
        $otherUser->setPassword(password_hash('OtherUser123!', PASSWORD_BCRYPT));
        $otherUser->setRoles([UserRole::Institute->value]);
        $otherUser->setStatus(UserStatus::Active);
        $otherUser->verify();
        $otherUser->setInstitute($otherInstitute);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherSubject);
        $entityManager->persist($otherAcademicYear);
        $entityManager->persist($otherPaper);
        $entityManager->persist($otherUser);
        $entityManager->flush();

        $this->createdEmails[] = 'other-user-clone-' . $this->unique . '@edupulse.ai';

        $otherToken = $this->login('other-user-clone-' . $this->unique . '@edupulse.ai', 'OtherUser123!');

        $client->request('POST', '/api/v1/question-papers/' . $this->testQuestionPaperId . '/clone', [], [], $this->getAuthHeaders($otherToken));

        $this->assertNotSame(200, $client->getResponse()->getStatusCode());

        $entityManager->remove($entityManager->getRepository(User::class)->find($otherUser->getId()));
        $entityManager->remove($entityManager->getRepository(QuestionPaper::class)->find($otherPaper->getId()));
        $entityManager->remove($entityManager->getRepository(Subject::class)->find($otherSubject->getId()));
        $entityManager->remove($entityManager->getRepository(AcademicYear::class)->find($otherAcademicYear->getId()));
        $entityManager->remove($entityManager->getRepository(Institute::class)->find($otherInstitute->getId()));
        $entityManager->flush();
    }
}
