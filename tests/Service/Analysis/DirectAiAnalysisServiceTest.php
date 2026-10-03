<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Exception\AnalysisNotFoundException;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\AnalysisStorageResolver;
use App\Service\Analysis\DirectAiAnalysisService;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class DirectAiAnalysisServiceTest extends TestCase
{
    private function createAnalysis(string $status = 'CONFIRMED', bool $hasAiAnalysis = false): Analysis
    {
        $analysis = new Analysis();
        $analysis->setAnalysisId('test-analysis-id');
        $analysis->setStudentId('test-student-id');
        $analysis->setStatus($status);
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->setProcessing(new Processing());

        if ($hasAiAnalysis) {
            $analysis->setAiAnalysis(['currentExamAnalysis' => ['strengths' => ['test']]]);
        }

        return $analysis;
    }

    public function testRunDirectAnalysisThrowsWhenAnalysisNotFound(): void
    {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn(null);

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $this->createMock(AnalysisStorageResolver::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $this->expectException(AnalysisNotFoundException::class);
        $service->runDirectAnalysis('missing-id', 'student-id');
    }

    public function testRunDirectAnalysisThrowsOnDoneStatus(): void
    {
        $mongoAnalysis = $this->createAnalysis('DONE', true);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $this->createMock(AnalysisStorageResolver::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $this->expectException(\LogicException::class);
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
    }

    public function testRunDirectAnalysisThrowsOnInvalidStatus(): void
    {
        $mongoAnalysis = $this->createAnalysis('DRAFT');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $this->createMock(AnalysisStorageResolver::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $this->expectException(\LogicException::class);
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
    }

    public function testRunDirectAnalysisThrowsWhenNoExtractedMetadata(): void
    {
        $mongoAnalysis = $this->createAnalysis('CONFIRMED');
        $mongoAnalysis->getExam()->setExtractedMetadata([]);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $this->createMock(AnalysisStorageResolver::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class)
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No extracted metadata found');
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
    }
}
