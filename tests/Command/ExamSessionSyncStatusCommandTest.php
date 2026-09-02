<?php
declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSession;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class ExamSessionSyncStatusCommandTest extends KernelTestCase
{
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?string $testExamSessionId = null;
    private array $createdEmails = [];
    private string $unique = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSectionId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-sync-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Sync');
        $institute->setCode('TESTSYNC' . $this->unique);
        $institute->setEmail('test-institute-sync-' . $this->unique . '@edupulse.ai');
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
        $subject->setInstitute($institute);
        $subject->setName('Test Subject ' . $this->unique);
        $subject->setCode('SUB' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $questionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $this->testQuestionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Question Paper ' . $this->unique);
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks(100);
        $questionPaper->setStatus(QuestionPaperStatus::Finalized);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-sync-test-' . $this->unique . '@edupulse.ai',
        ];
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = self::getContainer()->get('doctrine')->getManager();

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

        $this->createdEmails = [];
        $this->unique = '';

        parent::tearDown();
    }

    private function createExamSession(string $title, string $status, \DateTimeImmutable $availableFrom, \DateTimeImmutable $availableTo): ExamSession
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $session = new ExamSession();
        $session->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $session->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $session->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $session->setQuestionPaper($entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));
        $session->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => $this->createdEmails[0]]));
        $session->setTitle($title);
        $session->setAvailableFrom($availableFrom);
        $session->setAvailableTo($availableTo);
        $session->setStatus(ExamSessionStatus::from($status));

        $section = $entityManager->getRepository(Section::class)->find($this->testSectionId);
        if ($section) {
            $session->addSection($section);
        }

        $entityManager->persist($session);
        $entityManager->flush();

        return $session;
    }

    public function testFuturePublishedSessionRemainsPublished(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $futureStart = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));
        $futureEnd = new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Future Session', 'PUBLISHED', $futureStart, $futureEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('PUBLISHED', $updatedSession->getStatus()->value);
    }

    public function testPublishedSessionBecomesActiveWhenAvailableFromReached(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $pastStart = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        $futureEnd = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Ready Session', 'PUBLISHED', $pastStart, $futureEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('ACTIVE', $updatedSession->getStatus()->value);
    }

    public function testActiveSessionRemainsActiveBeforeAvailableTo(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $pastStart = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $futureEnd = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Active Session', 'ACTIVE', $pastStart, $futureEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('ACTIVE', $updatedSession->getStatus()->value);
    }

    public function testActiveSessionBecomesClosedWhenAvailableToReached(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $pastStart = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $pastEnd = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Expired Session', 'ACTIVE', $pastStart, $pastEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('CLOSED', $updatedSession->getStatus()->value);
    }

    public function testOverduePublishedSessionBecomesClosed(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $pastStart = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $pastEnd = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Overdue Session', 'PUBLISHED', $pastStart, $pastEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('CLOSED', $updatedSession->getStatus()->value);
    }

    public function testAvailableFromEqualsAvailableToIsHandledCorrectly(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $now = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Same Time Session', 'PUBLISHED', $now, $now);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('CLOSED', $updatedSession->getStatus()->value);
    }

    public function testClosedSessionRemainsUnchanged(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $pastStart = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $pastEnd = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Closed Session', 'CLOSED', $pastStart, $pastEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('CLOSED', $updatedSession->getStatus()->value);
    }

    public function testRunningCommandTwiceIsSafe(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $pastStart = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        $futureEnd = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));

        $session = $this->createExamSession('Idempotent Session', 'PUBLISHED', $pastStart, $futureEnd);

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-sessions:sync-status');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);
        $commandTester->assertCommandIsSuccessful();

        $commandTester->execute([]);
        $commandTester->assertCommandIsSuccessful();

        $updatedSession = $entityManager->getRepository(ExamSession::class)->find($session->getId());
        $this->assertSame('ACTIVE', $updatedSession->getStatus()->value);
    }
}
