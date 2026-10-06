<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Document\Analysis as MongoAnalysis;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\MongoDB\AnalysisRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * TEST 9 + end-to-end: upload dispatches exactly one prepare message, the
 * message contains only stable identifiers, and the existing GET analysis
 * endpoint exposes a valid processing state.
 */
class AnalysisPreparationDispatchTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $token = null;
    private ?string $email = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();

        $this->email = 'prep-' . uniqid() . '@edupulse.ai';
        $user = new User();
        $r = new \ReflectionClass($user);
        $p = $r->getProperty('id');
        $p->setAccessible(true);
        $p->setValue($user, Uuid::v7());
        $user->setEmail($this->email);
        $user->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $user->setRoles([UserRole::Student->value]);
        $user->setStatus(UserStatus::Active);
        $user->verify();
        $user->setFirstName('Prep');
        $user->setLastName('Student');
        $em->persist($user);
        $em->flush();

        $this->token = $this->login($this->email, 'Student123!');
    }

    public function testUploadDispatchesExactlyOnePrepareMessageAndGetExposesState(): void
    {
        $analysisId = $this->createAnalysisId();

        // Start from a clean transport.
        $this->clearQueuedMessages();

        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $files[] = $this->answerSheet("page{$i}.pdf");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders());

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Inspect what the upload actually put on the async transport.
        $dispatched = $this->fetchQueuedPrepareMessages();

        // TEST 1: exactly ONE prepare message for 3 uploaded files.
        $prepareMessages = array_values(array_filter(
            $dispatched,
            static fn(object $m): bool => $m instanceof ProcessAnalysisMessage && $m->action === 'prepare'
        ));
        $this->assertCount(1, $prepareMessages, 'Uploading 3 files must dispatch exactly ONE prepare message');

        // TEST 3 + 4: only stable identifiers, no UploadedFile / temp path.
        $message = $prepareMessages[0];
        $this->assertSame($analysisId, $message->analysisId);
        $this->assertFalse($message->isRetry);
        $serialized = serialize($message);
        $this->assertStringNotContainsString(UploadedFile::class, $serialized);
        $this->assertStringNotContainsString('.tmp', $serialized);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $serialized);

        // TEST 7: upload must NOT dispatch analyze.
        $analyzeMessages = array_values(array_filter(
            $dispatched,
            static fn(object $m): bool => $m instanceof ProcessAnalysisMessage && $m->action === 'analyze'
        ));
        $this->assertCount(0, $analyzeMessages, 'Upload must never dispatch action=analyze');

        // TEST 1: preparation stage + PENDING recorded on the document.
        $mongoRepo = $this->client->getContainer()->get(AnalysisRepository::class);
        $mongo = $mongoRepo->findByAnalysisId($analysisId);
        $this->assertNotNull($mongo);
        $this->assertSame('UPLOADED', $mongo->getProcessing()->getStage());
        $this->assertSame('PENDING', $mongo->getProcessing()->getStatus());

        // TEST 9: GET analysis returns HTTP 200 with a valid processing state.
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders());
        $getResponse = $this->client->getResponse();
        $this->assertSame(200, $getResponse->getStatusCode(), $getResponse->getContent());

        $data = json_decode($getResponse->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('processing', $data['data']);
        $this->assertContains($data['data']['processing']['status'], ['PENDING', 'PROCESSING', 'NEEDS_REVIEW']);
        $this->assertArrayHasKey('exam', $data['data']);
    }

    /**
     * Drain the async transport and return the ProcessAnalysisMessages found.
     *
     * @return ProcessAnalysisMessage[]
     */
    private function fetchQueuedPrepareMessages(): array
    {
        $transport = $this->client->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(TransportInterface::class, $transport);

        $messages = [];
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ProcessAnalysisMessage) {
                $messages[] = $message;
            }
            $transport->ack($envelope);
        }

        return $messages;
    }

    /**
     * Discard messages left on the transport by earlier runs so the assertions
     * only observe what the request under test produced.
     */
    private function clearQueuedMessages(): void
    {
        $transport = $this->client->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(TransportInterface::class, $transport);

        foreach ($transport->get() as $stale) {
            $transport->ack($stale);
        }
    }

    private function createAnalysisId(): string
    {
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders(), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        return $data['data']['analysisId'];
    }

    private function answerSheet(string $name): UploadedFile
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 12 Tf 100 700 Td (Student Name: Jane Doe Roll: 21 Subject: Mathematics Total Marks: 40) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \n0000000216 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n293\n%%EOF";
        $path = sys_get_temp_dir() . '/prep_' . uniqid() . '.pdf';
        file_put_contents($path, $pdf);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));
        $data = json_decode($this->client->getResponse()->getContent(), true);

        return $data['data']['accessToken'];
    }

    private function authHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
    }

    protected function tearDown(): void
    {
        try {
            $em = $this->client->getContainer()->get('doctrine')->getManager();
            foreach ($em->getRepository(User::class)->findBy(['email' => $this->email]) as $u) {
                $em->remove($u);
            }
            $em->flush();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }
}