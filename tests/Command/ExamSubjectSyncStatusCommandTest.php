<?php
declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExaminationStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\ExaminationModule\ExaminationService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class ExamSubjectSyncStatusCommandTest extends KernelTestCase
{
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testExaminationId = null;
    private ?Uuid $testExamSubjectId = null;
    private ?Uuid $testQuestionPaperId = null;
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
        $this->testSubjectId = Uuid::v7();
        $this->testExaminationId = Uuid::v7();
        $this->testExamSubjectId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-sy-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute Sync');
        $institute->setCode('TISY' . $this->unique);
        $institute->setEmail('test-inst-sy-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);
        $institute->setTimezone(\App\Enum\Timezone::INR);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year Sync');
        $academicYear->setCode('AYSY' . $this->unique);
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
        $academicClass->setName('Test Class Sync');
        $academicClass->setCode('CLSSY' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section Sync');
        $section->setCode('SSY' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subject->setInstitute($institute);
        $subject->setName('Test Subject Sync');
        $subject->setCode('SUBY' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);

        $examination = new Examination();
        $examination->setInstitute($institute);
        $examination->setAcademicYear($academicYear);
        $examination->setAcademicClass($academicClass);
        $examination->setName('Test Examination Sync');
        $examination->setCode('EXSY' . $this->unique);
        $examination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $examination->setEndDate(new \DateTimeImmutable('2025-06-30'));
        $examination->setStatus(ExaminationStatus::Draft);

        $examReflection = new \ReflectionClass($examination);
        $examProperty = $examReflection->getProperty('id');
        $examProperty->setAccessible(true);
        $examProperty->setValue($examination, $this->testExaminationId);

        $questionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $this->testQuestionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Question Paper Sync');
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
        $entityManager->persist($examination);
        $entityManager->persist($questionPaper);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-sy-' . $this->unique . '@edupulse.ai',
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

    private function createExamSubject(
        string $status,
        ?\DateTimeImmutable $examDate = null,
        ?\DateTimeImmutable $startTime = null,
        ?\DateTimeImmutable $endTime = null
    ): ExamSubject {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $examination = $entityManager->getRepository(Examination::class)->find($this->testExaminationId);
        $subject = $entityManager->getRepository(Subject::class)->find($this->testSubjectId);
        $questionPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $examSubject = new ExamSubject();
        $examSubject->setExamination($examination);
        $examSubject->setSubject($subject);
        $examSubject->setExamDate($examDate);
        $examSubject->setStartTime($startTime);
        $examSubject->setEndTime($endTime);
        $examSubject->setDuration($startTime !== null && $endTime !== null ? 120 : null);
        $examSubject->setMaxMarks(100);
        $examSubject->setPassingMarks(40);
        $examSubject->setStatus(ExaminationStatus::from($status));
        $examSubject->setQuestionPaper($questionPaper);

        $esReflection = new \ReflectionClass($examSubject);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSubject, $this->testExamSubjectId);

        $entityManager->persist($examSubject);
        $entityManager->flush();

        return $examSubject;
    }

    private function getSubjectStartUtc(\DateTimeImmutable $examDate, \DateTimeImmutable $startTime): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $examDate->format('Y-m-d') . ' ' . $startTime->format('H:i'),
            new \DateTimeZone('Asia/Kolkata')
        );

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private function getSubjectEndUtc(\DateTimeImmutable $examDate, \DateTimeImmutable $endTime): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $examDate->format('Y-m-d') . ' ' . $endTime->format('H:i'),
            new \DateTimeZone('Asia/Kolkata')
        );

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private function runSync(\DateTimeImmutable $now): array
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examinationService = self::getContainer()->get(ExaminationService::class);

        return $examinationService->syncExamSubjectStatuses($now);
    }

    public function testScheduledSubjectBeforeStartRemainsScheduled(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('-1 second');

        $this->createExamSubject('SCHEDULED', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('SCHEDULED', $examSubject->getStatus()->value);
    }

    public function testScheduledSubjectExactlyAtStartBecomesOngoing(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);

        $this->createExamSubject('SCHEDULED', $examDate, $startTime, $endTime);

        $this->runSync($startUtc);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('ONGOING', $examSubject->getStatus()->value);
    }

    public function testScheduledSubjectDuringTimeWindowIsOngoing(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('+1 hour');

        $this->createExamSubject('SCHEDULED', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('ONGOING', $examSubject->getStatus()->value);
    }

    public function testOngoingSubjectExactlyAtEndBecomesCompleted(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $endUtc = $this->getSubjectEndUtc($examDate, $endTime);

        $this->createExamSubject('ONGOING', $examDate, $startTime, $endTime);

        $this->runSync($endUtc);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('COMPLETED', $examSubject->getStatus()->value);
    }

    public function testOngoingSubjectAfterEndBecomesCompleted(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $endUtc = $this->getSubjectEndUtc($examDate, $endTime);
        $now = $endUtc->modify('+1 hour');

        $this->createExamSubject('ONGOING', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('COMPLETED', $examSubject->getStatus()->value);
    }

    public function testDraftSubjectIsNotAutomaticallyChanged(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('+1 hour');

        $this->createExamSubject('DRAFT', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('DRAFT', $examSubject->getStatus()->value);
    }

    public function testCancelledSubjectIsNotAutomaticallyChanged(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('+1 hour');

        $this->createExamSubject('CANCELLED', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('CANCELLED', $examSubject->getStatus()->value);
    }

    public function testArchivedSubjectIsNotAutomaticallyChanged(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('+1 hour');

        $this->createExamSubject('ARCHIVED', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('ARCHIVED', $examSubject->getStatus()->value);
    }

    public function testRunningLifecycleProcessingTwiceIsIdempotent(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('+30 minutes');

        $this->createExamSubject('SCHEDULED', $examDate, $startTime, $endTime);

        $this->runSync($now);
        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('ONGOING', $examSubject->getStatus()->value);
    }

    public function testExaminationLifecycleRemainsUnaffected(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $now = $startUtc->modify('+1 hour');

        $this->createExamSubject('SCHEDULED', $examDate, $startTime, $endTime);

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examination = $entityManager->getRepository(Examination::class)->find($this->testExaminationId);
        $this->assertSame('DRAFT', $examination->getStatus()->value);
    }

    public function testCorrectApplicationTimezoneIsUsed(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $startUtc = $this->getSubjectStartUtc($examDate, $startTime);
        $oneSecondBeforeStart = $startUtc->modify('-1 second');

        $this->createExamSubject('SCHEDULED', $examDate, $startTime, $endTime);

        $this->runSync($oneSecondBeforeStart);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('SCHEDULED', $examSubject->getStatus()->value);

        $this->runSync($startUtc);

        $entityManager->clear();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('ONGOING', $examSubject->getStatus()->value);
    }

    public function testOneSecondBeforeEndRemainsOngoing(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $endUtc = $this->getSubjectEndUtc($examDate, $endTime);
        $oneSecondBeforeEnd = $endUtc->modify('-1 second');

        $this->createExamSubject('ONGOING', $examDate, $startTime, $endTime);

        $this->runSync($oneSecondBeforeEnd);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('ONGOING', $examSubject->getStatus()->value);
    }

    public function testOneSecondAfterEndBecomesCompleted(): void
    {
        $examDate = new \DateTimeImmutable('2025-06-15');
        $startTime = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime = new \DateTimeImmutable('2025-06-15 11:00:00');

        $endUtc = $this->getSubjectEndUtc($examDate, $endTime);
        $oneSecondAfterEnd = $endUtc->modify('+1 second');

        $this->createExamSubject('ONGOING', $examDate, $startTime, $endTime);

        $this->runSync($oneSecondAfterEnd);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('COMPLETED', $examSubject->getStatus()->value);
    }

    public function testSubjectWithoutTimesIsIgnored(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->createExamSubject('SCHEDULED');

        $this->runSync($now);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $this->assertSame('SCHEDULED', $examSubject->getStatus()->value);
    }

    public function testCommandOutputsCorrectCounts(): void
    {
        $examDate1 = new \DateTimeImmutable('2025-06-15');
        $startTime1 = new \DateTimeImmutable('2025-06-15 09:00:00');
        $endTime1 = new \DateTimeImmutable('2025-06-15 11:00:00');

        $examDate2 = new \DateTimeImmutable('2025-06-16');
        $startTime2 = new \DateTimeImmutable('2025-06-16 09:00:00');
        $endTime2 = new \DateTimeImmutable('2025-06-16 11:00:00');

        $startUtc1 = $this->getSubjectStartUtc($examDate1, $startTime1);
        $endUtc1 = $this->getSubjectEndUtc($examDate1, $endTime1);
        $startUtc2 = $this->getSubjectStartUtc($examDate2, $startTime2);
        $endUtc2 = $this->getSubjectEndUtc($examDate2, $endTime2);

        $now = $endUtc2->modify('+1 hour');

        $this->createExamSubject('SCHEDULED', $examDate1, $startTime1, $endTime1);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $examSubject2 = new ExamSubject();
        $examSubject2->setExamination($entityManager->getRepository(Examination::class)->find($this->testExaminationId));
        $examSubject2->setSubject($entityManager->getRepository(Subject::class)->find($this->testSubjectId));
        $examSubject2->setExamDate($examDate2);
        $examSubject2->setStartTime($startTime2);
        $examSubject2->setEndTime($endTime2);
        $examSubject2->setDuration(120);
        $examSubject2->setMaxMarks(100);
        $examSubject2->setPassingMarks(40);
        $examSubject2->setStatus(ExaminationStatus::Ongoing);
        $examSubject2->setQuestionPaper($entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));

        $esReflection = new \ReflectionClass($examSubject2);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSubject2, Uuid::v7());

        $entityManager->persist($examSubject2);
        $entityManager->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('app:exam-subjects:sync-status');
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['interactive' => false]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('1 started', $output);
        $this->assertStringContainsString('1 completed', $output);
    }
}
