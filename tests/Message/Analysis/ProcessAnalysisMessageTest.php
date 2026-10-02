<?php

declare(strict_types=1);

namespace App\Tests\Message\Analysis;

use App\Message\Analysis\ProcessAnalysisMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class ProcessAnalysisMessageTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $normalizers = [new ObjectNormalizer()];
        $encoders = [new JsonEncoder()];
        $this->serializer = new Serializer($normalizers, $encoders);
    }

    public function testNewNormalMessage(): void
    {
        $message = ProcessAnalysisMessage::create('analysis-123', 'student-456');

        $this->assertSame('analysis-123', $message->analysisId);
        $this->assertSame('student-456', $message->studentId);
        $this->assertFalse($message->isRetry);
        $this->assertSame(0, $message->attemptId);
    }

    public function testNewRetryMessage(): void
    {
        $message = ProcessAnalysisMessage::createRetry('analysis-123', 'student-456', 2);

        $this->assertSame('analysis-123', $message->analysisId);
        $this->assertSame('student-456', $message->studentId);
        $this->assertTrue($message->isRetry);
        $this->assertSame(2, $message->attemptId);
    }

    public function testOldMessageDeserialization(): void
    {
        // Simulate old serialized message without isRetry and attemptId
        $oldJson = '{"analysisId":"analysis-123","studentId":"student-456"}';

        $message = $this->serializer->deserialize($oldJson, ProcessAnalysisMessage::class, 'json');

        $this->assertSame('analysis-123', $message->analysisId);
        $this->assertSame('student-456', $message->studentId);
        $this->assertFalse($message->isRetry, 'Old messages should default isRetry to false');
        $this->assertSame(0, $message->attemptId, 'Old messages should default attemptId to 0');
    }

    public function testOldMessageWithOnlyAnalysisIdAndStudentId(): void
    {
        // Test with array that mimics old deserialized data
        $oldData = [
            'analysisId' => 'analysis-789',
            'studentId' => 'student-101',
        ];

        $message = $this->serializer->denormalize($oldData, ProcessAnalysisMessage::class);

        $this->assertSame('analysis-789', $message->analysisId);
        $this->assertSame('student-101', $message->studentId);
        $this->assertFalse($message->isRetry);
        $this->assertSame(0, $message->attemptId);
    }

    public function testNewMessageSerialization(): void
    {
        $message = ProcessAnalysisMessage::createRetry('analysis-123', 'student-456', 3);

        $json = $this->serializer->serialize($message, 'json');
        $decoded = json_decode($json, true);

        $this->assertSame('analysis-123', $decoded['analysisId']);
        $this->assertSame('student-456', $decoded['studentId']);
        $this->assertTrue($decoded['isRetry']);
        $this->assertSame(3, $decoded['attemptId']);

        // Round-trip
        $message2 = $this->serializer->deserialize($json, ProcessAnalysisMessage::class, 'json');
        $this->assertSame($message->analysisId, $message2->analysisId);
        $this->assertSame($message->studentId, $message2->studentId);
        $this->assertSame($message->isRetry, $message2->isRetry);
        $this->assertSame($message->attemptId, $message2->attemptId);
    }

    public function testNormalMessageRoundTrip(): void
    {
        $message = ProcessAnalysisMessage::create('analysis-123', 'student-456');

        $json = $this->serializer->serialize($message, 'json');
        $message2 = $this->serializer->deserialize($json, ProcessAnalysisMessage::class, 'json');

        $this->assertSame($message->analysisId, $message2->analysisId);
        $this->assertSame($message->studentId, $message2->studentId);
        $this->assertSame($message->isRetry, $message2->isRetry);
        $this->assertSame($message->attemptId, $message2->attemptId);
    }
}