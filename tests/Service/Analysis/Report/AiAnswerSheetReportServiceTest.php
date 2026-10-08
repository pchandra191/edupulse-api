<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Report;

use App\Document\Exam;
use App\Entity\ExamSet;
use App\Entity\User;
use App\Repository\MongoDB\AnalysisRepository;
use App\Service\Analysis\AnalysisService;
use App\Service\Analysis\Report\AiAnswerSheetReportService;
use App\ValueObject\Ulid;
use MongoDB\Collection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class AiAnswerSheetReportServiceTest extends \PHPUnit\Framework\TestCase
{
    private function createService(): AiAnswerSheetReportService
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context): string {
            return $name;
        });

        return new AiAnswerSheetReportService(
            $this->createMock(AnalysisService::class),
            $this->createMock(AnalysisRepository::class),
            $twig,
            $this->createMock(LoggerInterface::class),
            '/tmp',
        );
    }

    public function testExtractMetadataValueReadsValueFromProvenanceArray(): void
    {
        $service = $this->createService();

        $metadata = [
            'studentName' => ['value' => 'Aarav Kumar', 'status' => 'EXTRACTED', 'confidence' => 0.95],
            'rollNumber' => ['value' => '08', 'status' => 'EXTRACTED', 'confidence' => 0.9],
            'class' => ['value' => '8', 'status' => 'EXTRACTED', 'confidence' => 0.92],
            'section' => ['value' => 'A', 'status' => 'EXTRACTED', 'confidence' => 0.88],
            'subject' => ['value' => 'English', 'status' => 'EXTRACTED', 'confidence' => 0.96],
            'examTitle' => ['value' => 'Mid Term Examination', 'status' => 'EXTRACTED', 'confidence' => 0.94],
            'totalMarks' => ['value' => '30', 'status' => 'EXTRACTED', 'confidence' => 0.97],
            'examDate' => ['value' => '2026-10-03', 'status' => 'EXTRACTED', 'confidence' => 0.91],
        ];

        $this->assertSame('Aarav Kumar', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'studentName']));
        $this->assertSame('08', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'rollNumber']));
        $this->assertSame('8', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'class']));
        $this->assertSame('A', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'section']));
        $this->assertSame('English', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'subject']));
        $this->assertSame('Mid Term Examination', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'examTitle']));
        $this->assertSame('30', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'totalMarks']));
        $this->assertSame('2026-10-03', $this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'examDate']));
    }

    public function testExtractMetadataValueFallsBackToNullForMissingKeys(): void
    {
        $service = $this->createService();
        $metadata = ['studentName' => ['value' => 'Test', 'status' => 'EXTRACTED', 'confidence' => 0.9]];

        $this->assertNull($this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'rollNumber']));
    }

    public function testExtractMetadataValueHandlesEmptyValue(): void
    {
        $service = $this->createService();
        $metadata = ['studentName' => ['value' => '', 'status' => 'EXTRACTED', 'confidence' => 0.9]];

        $this->assertNull($this->invokeMethod($service, 'extractMetadataValue', [$metadata, 'studentName']));
    }

    private function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
