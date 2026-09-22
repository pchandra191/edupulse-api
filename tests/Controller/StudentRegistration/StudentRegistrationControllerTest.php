<?php
declare(strict_types=1);

namespace App\Tests\Controller\StudentRegistration;

use App\Entity\Institute;
use App\Entity\InstituteStudentRegistrationLink;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstituteStudentRegistrationLinkRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StudentRegistrationControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?Institute $institute = null;
    private ?string $organizerToken = null;
    private array $createdEmails = [];
    private array $createdLinkTokens = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $unique = bin2hex(random_bytes(6));

        $this->institute = new Institute();
        $this->institute->setName('Registration Test Institute');
        $this->institute->setCode('REG' . $unique);
        $this->institute->setEmail('registration-' . $unique . '@edupulse.ai');
        $this->institute->setStatus(InstituteStatus::Active);

        $organizer = new User();
        $organizerEmail = 'registration-organizer-' . $unique . '@edupulse.ai';
        $organizer->setEmail($organizerEmail);
        $organizer->setPassword(password_hash('Organizer123!', PASSWORD_BCRYPT));
        $organizer->setRoles([UserRole::Institute->value]);
        $organizer->setStatus(UserStatus::Active);
        $organizer->verify();
        $organizer->setInstitute($this->institute);

        $entityManager->persist($this->institute);
        $entityManager->persist($organizer);
        $entityManager->flush();

        $this->createdEmails[] = $organizerEmail;
        $this->organizerToken = $this->login($organizerEmail, 'Organizer123!');
    }

    public function testIndependentStudentRegistrationReturnsLoginTokens(): void
    {
        $email = 'independent-student-' . bin2hex(random_bytes(6)) . '@edupulse.ai';

        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Independent',
            'lastName' => 'Student',
            'email' => $email,
            'phone' => null,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
            'instituteId' => null,
            'roles' => [UserRole::SuperAdmin->value],
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('accessToken', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
        $this->assertArrayHasKey('expires_at', $data['data']);
        $this->assertSame([UserRole::Student->value], $data['data']['user']['roles']);
        $this->assertArrayNotHasKey('institute', $data['data']['user']);

        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame([UserRole::Student->value], $user->getRoles());
        $this->assertNull($user->getInstitute());
        $this->assertTrue($user->isVerified());

        $this->client->request('GET', '/api/v1/auth/me', [], [], $this->authHeaders($data['data']['accessToken']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testOrganizerGetsReusableLinkAndStudentRegistersThroughToken(): void
    {
        $this->client->request('GET', '/api/v1/institutes/' . $this->institute->getId() . '/student-registration-link', [], [], $this->authHeaders($this->organizerToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $linkData = json_decode($response->getContent(), true);
        $this->assertTrue($linkData['success']);
        $this->assertArrayHasKey('registrationUrl', $linkData['data']);
        $this->assertStringContainsString('/register/institute/', $linkData['data']['registrationUrl']);
        
        // Extract token from registration URL for subsequent requests
        $registrationUrl = $linkData['data']['registrationUrl'];
        $token = basename(parse_url($registrationUrl, PHP_URL_PATH));
        $this->createdLinkTokens[] = $token;

        $this->client->request('GET', '/api/v1/student-registration/institute/' . $token);
        $infoResponse = $this->client->getResponse();
        $this->assertSame(200, $infoResponse->getStatusCode());

        $info = json_decode($infoResponse->getContent(), true);
        $this->assertSame(['id', 'name', 'logo'], array_keys($info['data']['institute']));
        $this->assertSame('Registration Test Institute', $info['data']['institute']['name']);

        $email = 'linked-student-' . bin2hex(random_bytes(6)) . '@edupulse.ai';
        $this->client->request('POST', '/api/v1/auth/student/register/institute/' . $token, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Linked',
            'lastName' => 'Student',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
            'instituteId' => (string) Uuid::v7(),
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/auth/student/register/institute/' . $token, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Linked',
            'lastName' => 'Student',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame([UserRole::Student->value], $data['data']['user']['roles']);

        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame((string) $this->institute->getId(), (string) $user->getInstitute()->getId());
    }

public function testInactiveInstituteAndInvalidTokenAreRejected(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->createdLinkTokens[] = $token;

        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $link = new InstituteStudentRegistrationLink();
        $link->setToken($token);
        $link->setInstitute($this->institute);
        $entityManager->persist($link);
        $entityManager->flush();

        $this->client->request('GET', '/api/v1/student-registration/institute/' . $token);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $institute = $entityManager->getRepository(Institute::class)->find($this->institute->getId());
        $this->assertInstanceOf(Institute::class, $institute);
        $institute->setStatus(InstituteStatus::SUSPENDED);
        $entityManager->flush();

        $this->client->request('GET', '/api/v1/student-registration/institute/' . $token);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $this->client->request('POST', '/api/v1/auth/student/register/institute/' . $token, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Blocked',
            'lastName' => 'Student',
            'email' => 'blocked-student-' . bin2hex(random_bytes(6)) . '@edupulse.ai',
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/student-registration/institute/invalid-token');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testInstituteUserCannotRetrieveAnotherInstituteLink(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $unique = bin2hex(random_bytes(6));
        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Registration Institute');
        $otherInstitute->setCode('OREG' . $unique);
        $otherInstitute->setEmail('other-registration-' . $unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherOrganizer = new User();
        $otherEmail = 'other-registration-organizer-' . $unique . '@edupulse.ai';
        $otherOrganizer->setEmail($otherEmail);
        $otherOrganizer->setPassword(password_hash('Organizer123!', PASSWORD_BCRYPT));
        $otherOrganizer->setRoles([UserRole::Institute->value]);
        $otherOrganizer->setStatus(UserStatus::Active);
        $otherOrganizer->verify();
        $otherOrganizer->setInstitute($otherInstitute);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherOrganizer);
        $entityManager->flush();
        $this->createdEmails[] = $otherEmail;

        $otherToken = $this->login($otherEmail, 'Organizer123!');
        $this->client->request('GET', '/api/v1/institutes/' . $this->institute->getId() . '/student-registration-link', [], [], $this->authHeaders($otherToken));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

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
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
            $linkRepository = $entityManager->getRepository(InstituteStudentRegistrationLink::class);

            foreach ($this->createdLinkTokens as $token) {
                $link = $linkRepository->findOneBy(['token' => $token]);
                if ($link instanceof InstituteStudentRegistrationLink) {
                    $entityManager->remove($link);
                }
            }

            $userRepository = $entityManager->getRepository(User::class);
            foreach ($userRepository->findBy(['email' => $this->createdEmails]) as $user) {
                $entityManager->remove($user);
            }

            if ($this->institute instanceof Institute) {
                $entityManager->remove($this->institute);
            }

            $entityManager->flush();
        } catch (\Throwable) {
        }

        $this->client = null;
        $this->institute = null;
        $this->organizerToken = null;
        $this->createdEmails = [];
        $this->createdLinkTokens = [];

        parent::tearDown();
    }
}
