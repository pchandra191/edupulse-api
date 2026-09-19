<?php
declare(strict_types=1);

namespace App\Tests\Controller\AttendanceModule;

use App\Enum\AcademicYearStatus;
use App\Enum\AttendanceStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\SectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\AttendancePolicy;
use App\Entity\EnrollmentSection;
use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

class AttendanceCheckInControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $superAdminToken = null;
    private ?string $studentAToken = null;
    private ?string $studentBToken = null;
    private ?string $teacherToken = null;
    private ?string $noEnrollmentStudentToken = null;

    private ?Uuid $instituteAId = null;
    private ?Uuid $instituteBId = null;
    private ?Uuid $studentAId = null;
    private ?Uuid $studentBId = null;
    private ?Uuid $noEnrollmentStudentId = null;
    private ?Uuid $classAId = null;
    private ?Uuid $sectionAId = null;
    private ?Uuid $subjectAId = null;
    private ?Uuid $teacherAId = null;
    private ?Uuid $classBId = null;
    private ?Uuid $sectionBId = null;
    private ?Uuid $academicYearAId = null;
    private ?Uuid $academicYearBId = null;
    private ?Uuid $leaveTypeId = null;

    private string $todayDate = '';
    private array $createdEmails = [];
    private array $createdInstituteIds = [];
    private string $unique = '';

    private const CHECK_IN_LAT = 23.123456;
    private const CHECK_IN_LNG = 86.123456;
    private const CHECK_IN_ACC = 12.5;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->todayDate = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->studentAId = Uuid::v7();
        $this->studentBId = Uuid::v7();
        $this->noEnrollmentStudentId = Uuid::v7();
        $this->instituteAId = Uuid::v7();
        $this->instituteBId = Uuid::v7();

        $superAdmin = $this->createUser(
            'superadmin-checkin-' . $this->unique . '@edupulse.ai',
            'SuperAdmin123!',
            [UserRole::SuperAdmin->value],
            null,
        );

        [$instituteA, $academicYearA, $classA, $sectionA, $subjectA, $teacherA] = $this->buildChain('A', $this->instituteAId);
        $this->instituteAId = $instituteA->getId();
        $this->academicYearAId = $academicYearA->getId();
        $this->classAId = $classA->getId();
        $this->sectionAId = $sectionA->getId();
        $this->subjectAId = $subjectA->getId();
        $this->teacherAId = $teacherA->getId();
        $this->createdInstituteIds[] = $this->instituteAId;

        [$instituteB, $academicYearB, $classB, $sectionB, $subjectB] = $this->buildChain('B', $this->instituteBId);
        $this->instituteBId = $instituteB->getId();
        $this->academicYearBId = $academicYearB->getId();
        $this->classBId = $classB->getId();
        $this->sectionBId = $sectionB->getId();
        $this->createdInstituteIds[] = $this->instituteBId;

        $studentA = $this->createUser(
            'student-a-checkin-' . $this->unique . '@edupulse.ai',
            'StudentA123!',
            [UserRole::Student->value],
            $instituteA,
        );
        $this->studentAId = $studentA->getId();

        $studentB = $this->createUser(
            'student-b-checkin-' . $this->unique . '@edupulse.ai',
            'StudentB123!',
            [UserRole::Student->value],
            $instituteB,
        );
        $this->studentBId = $studentB->getId();

        $noEnrollmentStudent = $this->createUser(
            'no-enrollment-checkin-' . $this->unique . '@edupulse.ai',
            'NoEnroll123!',
            [UserRole::Student->value],
            $instituteA,
        );
        $this->noEnrollmentStudentId = $noEnrollmentStudent->getId();

        $leaveType = new LeaveType();
        $ref = new \ReflectionClass($leaveType);
        $ref->getProperty('id')->setAccessible(true);
        $leaveTypeIdProp = Uuid::v7();
        $ref->getProperty('id')->setValue($leaveType, $leaveTypeIdProp);
        $leaveType->setInstitute($instituteA);
        $leaveType->setName('Medical Leave ' . $this->unique);
        $leaveType->setCode('ML' . $this->unique);
        $leaveType->setEnabled(true);
        $leaveType->setRequiresApproval(true);
        $leaveType->setMaximumDays(15);
        $this->leaveTypeId = $leaveTypeIdProp;

        $leaveBalanceA = new LeaveBalance();
        $ref = new \ReflectionClass($leaveBalanceA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveBalanceA, Uuid::v7());
        $leaveBalanceA->setInstitute($instituteA);
        $leaveBalanceA->setStudent($studentA);
        $leaveBalanceA->setAcademicYear($academicYearA);
        $leaveBalanceA->setLeaveType($leaveType);
        $leaveBalanceA->setAllocated(15);
        $leaveBalanceA->setUsed(0);
        $leaveBalanceA->setPending(0);

        $policy = new AttendancePolicy();
        $ref = new \ReflectionClass($policy);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($policy, Uuid::v7());
        $policy->setInstitute($instituteA);
        $policy->setAcademicYear($academicYearA);
        $policy->setMinimumAttendancePercentage(75);
        $policy->setIsActive(true);

        $enrollmentA = new StudentEnrollment();
        $ref = new \ReflectionClass($enrollmentA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentA, Uuid::v7());
        $enrollmentA->setStudent($studentA);
        $enrollmentA->setAcademicYear($academicYearA);
        $enrollmentA->setAcademicClass($classA);
        $enrollmentA->setAdmissionNumber('ADMA' . $this->unique);
        $enrollmentA->setRollNumber('RA' . $this->unique);
        $enrollmentA->setIsCurrent(true);
        $enrollmentA->setStatus(StudentEnrollmentStatus::Active);
        $enrollmentA->setInstituteId($this->instituteAId);

        $enrollmentSectionA = new EnrollmentSection();
        $ref = new \ReflectionClass($enrollmentSectionA);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentSectionA, Uuid::v7());
        $enrollmentSectionA->setStudentEnrollment($enrollmentA);
        $enrollmentSectionA->setSection($sectionA);

        $teacherAssignment = new TeacherAssignment();
        $ref = new \ReflectionClass($teacherAssignment);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($teacherAssignment, Uuid::v7());
        $teacherAssignment->setTeacher($teacherA);
        $teacherAssignment->setAcademicClass($classA);
        $teacherAssignment->setSection($sectionA);
        $teacherAssignment->setSubject($subjectA);
        $teacherAssignment->setAcademicYear($academicYearA);
        $teacherAssignment->setAssignmentType('CLASS_TEACHER');

        $enrollmentB = new StudentEnrollment();
        $ref = new \ReflectionClass($enrollmentB);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentB, Uuid::v7());
        $enrollmentB->setStudent($studentB);
        $enrollmentB->setAcademicYear($academicYearB);
        $enrollmentB->setAcademicClass($classB);
        $enrollmentB->setAdmissionNumber('ADMB' . $this->unique);
        $enrollmentB->setRollNumber('RB' . $this->unique);
        $enrollmentB->setIsCurrent(true);
        $enrollmentB->setStatus(StudentEnrollmentStatus::Active);
        $enrollmentB->setInstituteId($this->instituteBId);

        $enrollmentSectionB = new EnrollmentSection();
        $ref = new \ReflectionClass($enrollmentSectionB);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($enrollmentSectionB, Uuid::v7());
        $enrollmentSectionB->setStudentEnrollment($enrollmentB);
        $enrollmentSectionB->setSection($sectionB);

        $em->persist($leaveType);
        $em->persist($leaveBalanceA);
        $em->persist($policy);
        $em->persist($enrollmentA);
        $em->persist($enrollmentSectionA);
        $em->persist($teacherAssignment);
        $em->persist($enrollmentB);
        $em->persist($enrollmentSectionB);
        $em->flush();

        $this->superAdminToken = $this->login('superadmin-checkin-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->studentAToken = $this->login('student-a-checkin-' . $this->unique . '@edupulse.ai', 'StudentA123!');
        $this->studentBToken = $this->login('student-b-checkin-' . $this->unique . '@edupulse.ai', 'StudentB123!');
        $this->noEnrollmentStudentToken = $this->login('no-enrollment-checkin-' . $this->unique . '@edupulse.ai', 'NoEnroll123!');
        $this->teacherToken = $this->login('teacher-checkin-' . $this->unique . '@edupulse.ai', 'Teacher123!');
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdInstituteIds) || !empty($this->createdEmails)) {
            try {
                $em = $this->client->getContainer()->get('doctrine')->getManager();

                $arRepo = $em->getRepository(AttendanceRecord::class);
                $arQb = $arRepo->createQueryBuilder('ar')
                    ->where('ar.institute IN (:institutes)')
                    ->setParameter('institutes', $this->createdInstituteIds);
                foreach ($arQb->getQuery()->getResult() as $record) {
                    $em->remove($record);
                }

                $acRepo = $em->getRepository(\App\Entity\AttendanceCorrection::class);
                $acQb = $acRepo->createQueryBuilder('ac')
                    ->where('ac.institute IN (:institutes)')
                    ->setParameter('institutes', $this->createdInstituteIds);
                foreach ($acQb->getQuery()->getResult() as $correction) {
                    $em->remove($correction);
                }

                foreach (['LeaveRequest', 'LeaveBalance', 'AttendancePolicy', 'Holiday'] as $type) {
                    $repo = $em->getRepository("App\\Entity\\$type");
                    $qb = $repo->createQueryBuilder('e')
                        ->where('e.institute IN (:institutes)')
                        ->setParameter('institutes', $this->createdInstituteIds);
                    foreach ($qb->getQuery()->getResult() as $entity) {
                        $em->remove($entity);
                    }
                }

                $taRepo = $em->getRepository(TeacherAssignment::class);
                $taQb = $taRepo->createQueryBuilder('ta')
                    ->join('ta.academicClass', 'c')
                    ->where('c.institute IN (:institutes)')
                    ->setParameter('institutes', $this->createdInstituteIds);
                foreach ($taQb->getQuery()->getResult() as $assignment) {
                    $em->remove($assignment);
                }

                $seRepo = $em->getRepository(EnrollmentSection::class);
                $seQb = $seRepo->createQueryBuilder('es')
                    ->join('es.studentEnrollment', 'se')
                    ->where('se.instituteId IN (:institutes)')
                    ->setParameter('institutes', $this->createdInstituteIds);
                foreach ($seQb->getQuery()->getResult() as $enrollmentSection) {
                    $em->remove($enrollmentSection);
                }

                $enRepo = $em->getRepository(StudentEnrollment::class);
                $enQb = $enRepo->createQueryBuilder('en')
                    ->where('en.instituteId IN (:institutes)')
                    ->setParameter('institutes', $this->createdInstituteIds);
                foreach ($enQb->getQuery()->getResult() as $enrollment) {
                    $em->remove($enrollment);
                }

                $leaveTypeRepo = $em->getRepository(LeaveType::class);
                $leaveTypes = $leaveTypeRepo->findBy(['code' => 'ML' . $this->unique]);
                foreach ($leaveTypes as $lt) {
                    $em->remove($lt);
                }

                $userRepo = $em->getRepository(User::class);
                foreach ($userRepo->findBy(['email' => $this->createdEmails]) as $user) {
                    $em->remove($user);
                }

                $instituteRepo = $em->getRepository(Institute::class);
                foreach ($instituteRepo->findBy(['id' => $this->createdInstituteIds]) as $inst) {
                    $em->remove($inst);
                }

                $em->flush();
            } catch (\Throwable $e) {
            }
        }

        $this->client = null;
        $this->superAdminToken = null;
        $this->studentAToken = null;
        $this->studentBToken = null;
        $this->noEnrollmentStudentToken = null;
        $this->createdEmails = [];
        $this->createdInstituteIds = [];
        $this->unique = '';

        parent::tearDown();
    }

    public function testStudentCanCheckInWithValidEnrollment(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertTrue($data['success']);
        $this->assertSame('PRESENT', $data['data']['status']);
        $this->assertSame($this->todayDate, $data['data']['attendanceDate']);
        $this->assertSame('SYSTEM', $data['data']['source']);
        $this->assertSame((string) $this->studentAId, $data['data']['student']['id']);
        $this->assertNotNull($data['data']['markedAt']);
        $this->assertSame((string) $this->classAId, $data['data']['academicClass']['id']);
        $this->assertSame((string) $this->sectionAId, $data['data']['section']['id']);
        $this->assertEquals(self::CHECK_IN_LAT, $data['data']['latitude']);
        $this->assertEquals(self::CHECK_IN_LNG, $data['data']['longitude']);
        $this->assertEquals(self::CHECK_IN_ACC, $data['data']['accuracyMeters']);
        $this->assertNotNull($data['data']['locationCapturedAt']);
    }

    public function testLocationFieldsArePersistedInDatabase(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $record = $em->getRepository(AttendanceRecord::class)
            ->createQueryBuilder('ar')
            ->where('ar.student = :student')
            ->andWhere('ar.attendanceDate = :date')
            ->setParameter('student', $this->studentAId)
            ->setParameter('date', new \DateTimeImmutable($this->todayDate))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNotNull($record);
        $this->assertEquals(self::CHECK_IN_LAT, $record->getLatitude());
        $this->assertEquals(self::CHECK_IN_LNG, $record->getLongitude());
        $this->assertEquals(self::CHECK_IN_ACC, $record->getAccuracyMeters());
        $this->assertNotNull($record->getLocationCapturedAt());
    }

    public function testLocationCapturedAtIsGeneratedServerSide(): void
    {
        $clientSupplied = (new \DateTimeImmutable('yesterday'))->format(\DateTimeInterface::ATOM);
        $this->client->request(
            'POST',
            '/api/v1/attendance/check-in',
            [],
            [],
            $this->getAuthHeaders($this->studentAToken),
            json_encode([
                'latitude' => self::CHECK_IN_LAT,
                'longitude' => self::CHECK_IN_LNG,
                'accuracyMeters' => self::CHECK_IN_ACC,
                'locationCapturedAt' => $clientSupplied,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertNotNull($data['data']['locationCapturedAt']);
        $this->assertNotEquals($clientSupplied, $data['data']['locationCapturedAt'], 'Server must generate locationCapturedAt, not trust the client.');
        $this->assertGreaterThanOrEqual(
            (new \DateTimeImmutable('today'))->getTimestamp(),
            (new \DateTimeImmutable($data['data']['locationCapturedAt']))->getTimestamp()
        );
    }

    public function testMissingLocationFieldsRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), json_encode(['latitude' => self::CHECK_IN_LAT]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testInvalidLatitudeBelowMinusNinetyRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(-91.0, self::CHECK_IN_LNG, self::CHECK_IN_ACC));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testInvalidLatitudeAboveNinetyRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(90.1, self::CHECK_IN_LNG, self::CHECK_IN_ACC));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testInvalidLongitudeBelowMinusOneHundredEightyRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, -180.1, self::CHECK_IN_ACC));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testInvalidLongitudeAboveOneHundredEightyRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, 180.1, self::CHECK_IN_ACC));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testNegativeAccuracyRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, -1.0));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testNonNumericLocationValueRejected(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'latitude' => 'not-a-number',
            'longitude' => self::CHECK_IN_LNG,
            'accuracyMeters' => self::CHECK_IN_ACC,
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testStudentIdentityDerivedFromAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame((string) $this->studentAId, $data['data']['student']['id']);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $countB = (int) $em->getRepository(AttendanceRecord::class)
            ->createQueryBuilder('ar')
            ->select('COUNT(ar.id)')
            ->where('ar.student = :student')
            ->andWhere('ar.attendanceDate = :date')
            ->setParameter('student', $this->studentBId)
            ->setParameter('date', new \DateTimeImmutable($this->todayDate))
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertSame(0, $countB, 'No attendance record should exist for Student B.');
    }

    public function testStudentCannotProvideAnotherStudentId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/attendance/check-in',
            [],
            [],
            $this->getAuthHeaders($this->studentAToken),
            json_encode([
                'studentId' => (string) $this->studentBId,
                'latitude' => self::CHECK_IN_LAT,
                'longitude' => self::CHECK_IN_LNG,
                'accuracyMeters' => self::CHECK_IN_ACC,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame((string) $this->studentAId, $data['data']['student']['id']);
    }

    public function testStudentCannotChooseAnotherAcademicYearId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/attendance/check-in',
            [],
            [],
            $this->getAuthHeaders($this->studentAToken),
            json_encode([
                'academicYearId' => (string) Uuid::v7(),
                'latitude' => self::CHECK_IN_LAT,
                'longitude' => self::CHECK_IN_LNG,
                'accuracyMeters' => self::CHECK_IN_ACC,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame((string) $this->classAId, $data['data']['academicClass']['id']);
    }

    public function testStudentCannotChooseAnotherAcademicClassId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/attendance/check-in',
            [],
            [],
            $this->getAuthHeaders($this->studentAToken),
            json_encode([
                'academicClassId' => (string) Uuid::v7(),
                'latitude' => self::CHECK_IN_LAT,
                'longitude' => self::CHECK_IN_LNG,
                'accuracyMeters' => self::CHECK_IN_ACC,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = $this->decode($response);
        $this->assertSame((string) $this->classAId, $data['data']['academicClass']['id']);
    }

    public function testStudentCannotChooseAnotherSectionId(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/attendance/check-in',
            [],
            [],
            $this->getAuthHeaders($this->studentAToken),
            json_encode([
                'sectionId' => (string) Uuid::v7(),
                'latitude' => self::CHECK_IN_LAT,
                'longitude' => self::CHECK_IN_LNG,
                'accuracyMeters' => self::CHECK_IN_ACC,
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame((string) $this->sectionAId, $data['data']['section']['id']);
    }

    public function testStudentCannotCheckInWithoutValidEnrollment(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->noEnrollmentStudentToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertFalse($data['success']);
    }

    public function testDuplicateCheckInDoesNotCreateAnotherRecord(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(23.123456, 86.123456, 12.5));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(23.999999, 86.999999, 99.9));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $count = (int) $em->getRepository(AttendanceRecord::class)
            ->createQueryBuilder('ar')
            ->select('COUNT(ar.id)')
            ->where('ar.student = :student')
            ->andWhere('ar.attendanceDate = :date')
            ->andWhere('ar.source = :source')
            ->setParameter('student', $this->studentAId)
            ->setParameter('date', new \DateTimeImmutable($this->todayDate))
            ->setParameter('source', AttendanceRecord::SOURCE_SYSTEM)
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertSame(1, $count, 'Expected exactly one attendance record for today.');

        $data = $this->decode($this->client->getResponse());
        $this->assertEquals(23.123456, $data['data']['latitude'], 'First check-in location must be retained, not overwritten.');
        $this->assertEquals(86.123456, $data['data']['longitude']);
    }

    public function testExistingPresentRecordRemainsPresent(): void
    {
        $this->markAttendanceForStudentToday($this->studentAId, AttendanceStatus::Present->value);

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame('PRESENT', $data['data']['status']);
        $this->assertSame('MANUAL', $data['data']['source']);
        $this->assertNull($data['data']['latitude'], 'Existing teacher record location must not be overwritten by check-in.');

        $this->assertRecordCountForStudentToday($this->studentAId, 1);
    }

    public function testExistingAbsentRecordCannotBeChangedToPresent(): void
    {
        $this->markAttendanceForStudentToday($this->studentAId, AttendanceStatus::Absent->value);

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame('ABSENT', $data['data']['status']);
        $this->assertNull($data['data']['latitude']);

        $this->assertRecordCountForStudentToday($this->studentAId, 1);
    }

    public function testExistingOnLeaveRecordCannotBeChanged(): void
    {
        $this->markAttendanceForStudentToday($this->studentAId, AttendanceStatus::OnLeave->value);

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame('ON_LEAVE', $data['data']['status']);
        $this->assertNull($data['data']['latitude']);

        $this->assertRecordCountForStudentToday($this->studentAId, 1);
    }

    public function testExistingHolidayRecordCannotBeChanged(): void
    {
        $this->markAttendanceForStudentToday($this->studentAId, AttendanceStatus::Holiday->value);

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame('HOLIDAY', $data['data']['status']);
        $this->assertNull($data['data']['latitude']);

        $this->assertRecordCountForStudentToday($this->studentAId, 1);
    }

    public function testCheckInRefusedOnHolidayWithNoRecord(): void
    {
        $holiday = new Holiday();
        $ref = new \ReflectionClass($holiday);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($holiday, Uuid::v7());
        $holiday->setInstitute($this->getInstitute($this->instituteAId));
        $holiday->setAcademicYear($this->getAcademicYear('A'));
        $holiday->setHolidayDate(new \DateTimeImmutable('today'));
        $holiday->setName('Today Holiday ' . $this->unique);
        $holiday->setEnabled(true);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->persist($holiday);
        $em->flush();

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), $response->getContent());

        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testCheckInRefusedWhenOnApprovedLeave(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $studentA = $em->getRepository(User::class)->find($this->studentAId);
        $leaveType = $em->getRepository(LeaveType::class)->find($this->leaveTypeId);
        $this->assertNotNull($leaveType, 'Leave type should be seeded in setUp.');

        $leaveRequest = new LeaveRequest();
        $ref = new \ReflectionClass($leaveRequest);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($leaveRequest, Uuid::v7());
        $leaveRequest->setInstitute($this->getInstitute($this->instituteAId));
        $leaveRequest->setStudent($studentA);
        $leaveRequest->setAcademicYear($this->getAcademicYear('A'));
        $leaveRequest->setAcademicClass($this->getClass('A'));
        $leaveRequest->setSection($this->getSection('A'));
        $leaveRequest->setLeaveType($leaveType);
        $leaveRequest->setStartDate(new \DateTimeImmutable('today'));
        $leaveRequest->setEndDate(new \DateTimeImmutable('today'));
        $leaveRequest->setReason('Sick');
        $leaveRequest->setStatus(LeaveRequestStatus::Approved);

        $em->persist($leaveRequest);
        $em->flush();

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertFalse($data['success']);

        $this->assertRecordCountForStudentToday($this->studentAId, 0);
    }

    public function testStudentCannotUsePutToCheckIn(): void
    {
        $this->markAttendanceForStudentToday($this->studentAId, AttendanceStatus::Absent->value);

        $recordId = $this->getTodayRecordId($this->studentAId);

        $this->client->request('PUT', '/api/v1/attendance/' . $recordId, [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'status' => 'PRESENT',
        ]));

        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404], $response->getContent());

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $record = $em->getRepository(AttendanceRecord::class)->find($recordId);
        $this->assertNotNull($record);
        $this->assertSame('ABSENT', $record->getStatusValue());
    }

    public function testStudentCannotUseBulkToCheckIn(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->studentAToken), json_encode([
            'academicYearId' => (string) $this->getAcademicYear('A')->getId(),
            'classId' => (string) $this->classAId,
            'sectionId' => (string) $this->sectionAId,
            'attendanceDate' => $this->todayDate,
            'records' => [
                ['studentId' => (string) $this->studentAId, 'status' => 'PRESENT'],
            ],
        ]));

        $response = $this->client->getResponse();
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testInstituteIsolationPreserved(): void
    {
        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentAToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame((string) $this->instituteAId, $data['data']['institute']['id']);

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($this->studentBToken), $this->locationBody(23.999999, 86.999999, 33.3));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame((string) $this->instituteBId, $data['data']['institute']['id']);
        $this->assertSame((string) $this->studentBId, $data['data']['student']['id']);
        $this->assertEquals(23.999999, $data['data']['latitude']);

        $this->assertRecordCountForStudentToday($this->studentAId, 1);
        $this->assertRecordCountForStudentToday($this->studentBId, 1);
    }

    public function testTeacherBulkAttendanceRemainsUnchanged(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'academicYearId' => (string) $this->getAcademicYear('A')->getId(),
            'classId' => (string) $this->classAId,
            'sectionId' => (string) $this->sectionAId,
            'attendanceDate' => $this->todayDate,
            'records' => [
                ['studentId' => (string) $this->studentAId, 'status' => 'PRESENT', 'remarks' => 'On time'],
            ],
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertSame('PRESENT', $data['data'][0]['status']);
        $this->assertSame('BULK', $data['data'][0]['source']);
    }

    public function testSuperAdminCanBulkMarkAsBefore(): void
    {
        $this->client->request('POST', '/api/v1/attendance/bulk', [], [], $this->getAuthHeaders($this->superAdminToken), json_encode([
            'academicYearId' => (string) $this->getAcademicYear('A')->getId(),
            'classId' => (string) $this->classAId,
            'sectionId' => (string) $this->sectionAId,
            'attendanceDate' => $this->todayDate,
            'records' => [
                ['studentId' => (string) $this->studentAId, 'status' => 'PRESENT'],
            ],
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = $this->decode($response);
        $this->assertTrue($data['success']);
    }

    public function testStudentCheckInRouteIsStudentOnly(): void
    {
        $teacherToken = $this->login('teacher-checkin-' . $this->unique . '@edupulse.ai', 'Teacher123!');
        $this->assertNotEmpty($teacherToken, 'Teacher token should be available.');

        $this->client->request('POST', '/api/v1/attendance/check-in', [], [], $this->getAuthHeaders($teacherToken), $this->locationBody(self::CHECK_IN_LAT, self::CHECK_IN_LNG, self::CHECK_IN_ACC));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function buildChain(string $suffix, Uuid $instituteId): array
    {
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();

        $institute = new Institute();
        $ref = new \ReflectionClass($institute);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($institute, $instituteId);
        $institute->setName('Institute ' . $suffix . ' ' . $this->unique);
        $institute->setCode('INST' . $suffix . $this->unique);
        $institute->setEmail('inst-' . $suffix . '-checkin-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ref = new \ReflectionClass($academicYear);
        $ref->getProperty('id')->setAccessible(true);
        $ayId = Uuid::v7();
        $ref->getProperty('id')->setValue($academicYear, $ayId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('AY ' . $suffix . ' ' . $this->unique);
        $academicYear->setCode('AY' . $suffix . $this->unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $academicClass = new AcademicClass();
        $ref = new \ReflectionClass($academicClass);
        $ref->getProperty('id')->setAccessible(true);
        $classId = Uuid::v7();
        $ref->getProperty('id')->setValue($academicClass, $classId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Class ' . $suffix . ' ' . $this->unique);
        $academicClass->setCode('CLS' . $suffix . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $ref = new \ReflectionClass($section);
        $ref->getProperty('id')->setAccessible(true);
        $sectionId = Uuid::v7();
        $ref->getProperty('id')->setValue($section, $sectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Section ' . $suffix . ' ' . $this->unique);
        $section->setCode('SEC' . $suffix . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $ref = new \ReflectionClass($subject);
        $ref->getProperty('id')->setAccessible(true);
        $subjectId = Uuid::v7();
        $ref->getProperty('id')->setValue($subject, $subjectId);
        $subject->setInstitute($institute);
        $subject->setName('Subject ' . $suffix . ' ' . $this->unique);
        $subject->setCode('SUB' . $suffix . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $em->persist($institute);
        $em->persist($academicYear);
        $em->persist($academicClass);
        $em->persist($section);
        $em->persist($subject);
        $em->flush();

        $teacher = null;
        if ($suffix === 'A') {
            $teacher = new User();
            $ref = new \ReflectionClass($teacher);
            $ref->getProperty('id')->setAccessible(true);
            $ref->getProperty('id')->setValue($teacher, Uuid::v7());
            $teacherEmail = 'teacher-checkin-' . $this->unique . '@edupulse.ai';
            $this->createdEmails[] = $teacherEmail;
            $teacher->setEmail($teacherEmail);
            $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
            $teacher->setRoles([UserRole::Teacher->value]);
            $teacher->setStatus(UserStatus::Active);
            $teacher->verify();
            $teacher->setInstitute($institute);
            $teacher->setFirstName('Test');
            $teacher->setLastName('Teacher' . $suffix);
            $em->persist($teacher);
            $em->flush();
        }

        return [$institute, $academicYear, $academicClass, $section, $subject, $teacher];
    }

    private function createUser(string $email, string $password, array $roles, ?Institute $institute): User
    {
        $this->createdEmails[] = $email;

        $user = new User();
        $ref = new \ReflectionClass($user);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($user, Uuid::v7());
        $user->setEmail($email);
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        $user->setRoles($roles);
        $user->setStatus(UserStatus::Active);
        $user->verify();
        if ($institute !== null) {
            $user->setInstitute($institute);
        }
        $user->setFirstName('Test');
        $user->setLastName('User');

        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function login(string $email, string $password): ?string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['success'] ?? false ? ($data['data']['accessToken'] ?? null) : null;
    }

    private function getAuthHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function locationBody(float $lat, float $lng, float $acc): string
    {
        return json_encode(['latitude' => $lat, 'longitude' => $lng, 'accuracyMeters' => $acc]);
    }

    private function decode($response): array
    {
        return json_decode($response->getContent(), true);
    }

    private function getInstitute(Uuid $id): Institute
    {
        return $this->client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($id);
    }

    private function getAcademicYear(string $suffix): AcademicYear
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $id = $suffix === 'A' ? $this->academicYearAId : $this->academicYearBId;

        return $em->getRepository(AcademicYear::class)->find($id);
    }

    private function getClass(string $suffix): AcademicClass
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $id = $suffix === 'A' ? $this->classAId : $this->classBId;

        return $em->getRepository(AcademicClass::class)->find($id);
    }

    private function getSection(string $suffix): Section
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $id = $suffix === 'A' ? $this->sectionAId : $this->sectionBId;

        return $em->getRepository(Section::class)->find($id);
    }

    private function markAttendanceForStudentToday(Uuid $studentId, string $status): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $record = new AttendanceRecord();
        $ref = new \ReflectionClass($record);
        $ref->getProperty('id')->setAccessible(true);
        $ref->getProperty('id')->setValue($record, Uuid::v7());
        $record->setInstitute($this->getInstitute($this->instituteAId));
        $record->setAcademicYear($this->getAcademicYear('A'));
        $record->setStudent($em->getRepository(User::class)->find($studentId));
        $record->setAcademicClass($this->getClass('A'));
        $record->setSection($this->getSection('A'));
        $record->setAttendanceDate(new \DateTimeImmutable('today'));
        $record->setStatus(AttendanceStatus::from($status));
        $record->setSource(AttendanceRecord::SOURCE_MANUAL);
        $record->setRemarks('Pre-existing ' . $status);

        $em->persist($record);
        $em->flush();
    }

    private function getTodayRecordId(Uuid $studentId): string
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $record = $em->getRepository(AttendanceRecord::class)
            ->createQueryBuilder('ar')
            ->where('ar.student = :student')
            ->andWhere('ar.attendanceDate = :date')
            ->setParameter('student', $studentId)
            ->setParameter('date', new \DateTimeImmutable($this->todayDate))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNotNull($record, 'Expected a pre-existing attendance record for today.');
        return (string) $record->getId();
    }

    private function assertRecordCountForStudentToday(Uuid $studentId, int $expected): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $count = (int) $em->getRepository(AttendanceRecord::class)
            ->createQueryBuilder('ar')
            ->select('COUNT(ar.id)')
            ->where('ar.student = :student')
            ->andWhere('ar.attendanceDate = :date')
            ->setParameter('student', $studentId)
            ->setParameter('date', new \DateTimeImmutable($this->todayDate))
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertSame($expected, $count);
    }
}
