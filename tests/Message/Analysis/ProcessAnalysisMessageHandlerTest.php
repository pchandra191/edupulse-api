<?php

declare(strict_types=1);

namespace App\Tests\Message\Analysis;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Entity\Analysis as PgAnalysis;
use App\Handler\Analysis\ProcessAnalysisMessageHandler;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\AnalysisModeResolver;
use App\Service\Analysis\AnalysisProcessingRecoveryService;
use App\Service\Analysis\AnalysisProcessingService;
use App\Service\Analysis\DirectAiAnalysisService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessAnalysisMessageHandlerTest extends TestCase
{
    private function createHandler(
        ?DirectAiAnalysisService $directService = null,
        ?MongoAnalysisRepository $mongoRepo = null,
        ?AnalysisRepository $pgRepo = null,
    ): ProcessAnalysisMessageHandler {
        return new ProcessAnalysisMessageHandler(
            $this->createMock(AnalysisProcessingService::class),
            $directService ?? $this->createMock(DirectAiAnalysisService::class),
            $this->createMock(AnalysisProcessingRecoveryService::class),
            new \App\Service\Analysis\AnalysisModeResolver('direct', $this->createMock(LoggerInterface::class)),
            $mongoRepo ?? $this->createMock(MongoAnalysisRepository::class),
            $pgRepo ?? $this->createMock(AnalysisRepository::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function createMongoAnalysis(string $analysisId, string $studentId, string $status = 'PROCESSING'): Analysis
    {
        $analysis = new Analysis();
        $analysis->setAnalysisId($analysisId);
        $analysis->setStudentId($studentId);
        $analysis->setStatus($status);
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->setProcessing(new Processing());
        $analysis->getProcessing()->setStatus($status);
        $analysis->getProcessing()->setStage('ANALYZING');
        return $analysis;
    }

    public function testDirectModeFailureMarksAnalysisAsFailedInMongoAndPostgres(): void
    {
        $analysisId = 'analysis-1';
        $studentId = 'student-1';

        $mongoAnalysis = $this->createMongoAnalysis($analysisId, $studentId, 'PROCESSING');
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);
        $mongoRepo->expects($this->once())->method('save')->with($this->callback(function (Analysis $a) use ($analysisId) {
            $this->assertSame('FAILED', $a->getStatus());
            $this->assertSame('FAILED', $a->getProcessing()->getStatus());
            $this->assertSame('FAILED', $a->getProcessing()->getStage());
            $this->assertNotNull($a->getProcessing()->getCompletedAt());
            return true;
        }));

        $pgAnalysis = $this->createMock(PgAnalysis::class);
        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisIdAndStudent')->willReturn($pgAnalysis);

        $directService = $this->createMock(DirectAiAnalysisService::class);
        $directService->method('runDirectAnalysis')->willThrowException(new \RuntimeException('OpenAI failure'));

        $handler = new ProcessAnalysisMessageHandler(
            $this->createMock(AnalysisProcessingService::class),
            $directService,
            $this->createMock(AnalysisProcessingRecoveryService::class),
            new \App\Service\Analysis\AnalysisModeResolver('direct', $this->createMock(LoggerInterface::class)),
            $mongoRepo,
            $pgRepo,
            $this->createMock(LoggerInterface::class),
        );

        $message = new ProcessAnalysisMessage($analysisId, $studentId, 'analyze', false, 0);

        try {
            $handler($message);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('OpenAI failure', $e->getMessage());
        }

        $updatedAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, $studentId);
        $this->assertSame('FAILED', $updatedAnalysis->getStatus());
        $this->assertSame('FAILED', $updatedAnalysis->getProcessing()->getStatus());
        $this->assertSame('FAILED', $updatedAnalysis->getProcessing()->getStage());
    }

    public function testScannedPdfWithConfirmedMetadataDoesNotRemainProcessing(): void
    {
        $analysisId = 'analysis-1';
        $studentId = 'student-1';

        $mongoAnalysis = $this->createMongoAnalysis($analysisId, $studentId, 'PROCESSING');
        $mongoAnalysis->getExam()->setExtractedMetadata([]);
        $mongoAnalysis->setMetadataConfirmationStatus('CONFIRMED');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgAnalysis = $this->createMock(PgAnalysis::class);
        $pgAnalysis->method('getMetadataConfirmationStatus')->willReturn('CONFIRMED');
        $pgAnalysis->method('getSubjectName')->willReturn('Mathematics');
        $pgAnalysis->method('getTotalMarks')->willReturn(30);
        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $directService = $this->createMock(DirectAiAnalysisService::class);
        $directService->method('runDirectAnalysis')->willThrowException(new \RuntimeException('Simulated AI failure'));

        $legacyService = $this->createMock(AnalysisProcessingService::class);
        $legacyService->method('processAnalysis')->willThrowException(new \RuntimeException('Legacy fallback failed'));

        $handler = new ProcessAnalysisMessageHandler(
            $legacyService,
            $directService,
            $this->createMock(AnalysisProcessingRecoveryService::class),
            new \App\Service\Analysis\AnalysisModeResolver('direct', $this->createMock(LoggerInterface::class)),
            $mongoRepo,
            $pgRepo,
            $this->createMock(LoggerInterface::class),
        );

        $message = new ProcessAnalysisMessage($analysisId, $studentId, 'analyze', false, 0);

        try {
            $handler($message);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated AI failure', $e->getMessage());
        }

        $updatedAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, $studentId);
        $this->assertSame('FAILED', $updatedAnalysis->getStatus());
        $this->assertSame('FAILED', $updatedAnalysis->getProcessing()->getStatus());
    }

    public function testConfirmedMetadataAfterDirectAiFailureWithLegacyFallbackExtractionOnlyBecomesFailed(): void
    {
        $analysisId = 'analysis-1';
        $studentId = 'student-1';

        $mongoAnalysis = $this->createMongoAnalysis($analysisId, $studentId, 'PROCESSING');
        $mongoAnalysis->getExam()->setExtractedMetadata([]);
        $mongoAnalysis->setMetadataConfirmationStatus('CONFIRMED');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgAnalysis = $this->createMock(PgAnalysis::class);
        $pgAnalysis->method('getMetadataConfirmationStatus')->willReturn('CONFIRMED');
        $pgAnalysis->method('getSubjectName')->willReturn('Mathematics');
        $pgAnalysis->method('getTotalMarks')->willReturn(30);
        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $directService = $this->createMock(DirectAiAnalysisService::class);
        $directService->method('runDirectAnalysis')->willThrowException(new \RuntimeException('Direct AI validation failed'));

        $legacyService = $this->createMock(AnalysisProcessingService::class);
        $legacyService->method('processAnalysis')->willReturnCallback(function () use ($mongoAnalysis) {
            $mongoAnalysis->setStatus('NEEDS_REVIEW');
            $mongoAnalysis->getProcessing()->setStatus('NEEDS_REVIEW');
            $mongoAnalysis->getProcessing()->setStage('VALIDATING_EXTRACTION');
        });

        $handler = new ProcessAnalysisMessageHandler(
            $legacyService,
            $directService,
            $this->createMock(AnalysisProcessingRecoveryService::class),
            new \App\Service\Analysis\AnalysisModeResolver('direct', $this->createMock(LoggerInterface::class)),
            $mongoRepo,
            $pgRepo,
            $this->createMock(LoggerInterface::class),
        );

        $message = new ProcessAnalysisMessage($analysisId, $studentId, 'analyze', false, 0);

        $handler($message);

        $updatedAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, $studentId);
        $this->assertSame('FAILED', $updatedAnalysis->getStatus(), 'Confirmed metadata must not return to NEEDS_REVIEW after extraction-only fallback');
        $this->assertSame('FAILED', $updatedAnalysis->getProcessing()->getStatus());
        $this->assertSame('FAILED', $updatedAnalysis->getProcessing()->getStage());
    }

    public function testConfirmedMetadataAfterDirectAiFailureWithLegacyFallbackAiAnalysisBecomesDone(): void
    {
        $analysisId = 'analysis-1';
        $studentId = 'student-1';

        $mongoAnalysis = $this->createMongoAnalysis($analysisId, $studentId, 'PROCESSING');
        $mongoAnalysis->getExam()->setExtractedMetadata([]);
        $mongoAnalysis->setMetadataConfirmationStatus('CONFIRMED');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgAnalysis = $this->createMock(PgAnalysis::class);
        $pgAnalysis->method('getMetadataConfirmationStatus')->willReturn('CONFIRMED');
        $pgAnalysis->method('getSubjectName')->willReturn('Mathematics');
        $pgAnalysis->method('getTotalMarks')->willReturn(30);
        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $directService = $this->createMock(DirectAiAnalysisService::class);
        $directService->method('runDirectAnalysis')->willThrowException(new \RuntimeException('Direct AI validation failed'));

        $legacyService = $this->createMock(AnalysisProcessingService::class);
        $legacyService->method('processAnalysis')->willReturnCallback(function () use ($mongoAnalysis) {
            $mongoAnalysis->setStatus('NEEDS_REVIEW');
            $mongoAnalysis->getProcessing()->setStatus('NEEDS_REVIEW');
            $mongoAnalysis->getProcessing()->setStage('VALIDATING_EXTRACTION');
            $mongoAnalysis->setAiAnalysis([
                'currentExamAnalysis' => [
                    'performance' => ['score' => 20, 'totalMarks' => 30, 'percentage' => 66.67],
                    'strengths' => [],
                    'weaknesses' => [],
                    'needsAttention' => [],
                    'conceptGaps' => [],
                    'recommendations' => [],
                    'conceptAssessments' => [],
                    'mistakes' => [],
                    'evidence' => [],
                ],
                'questionEvaluations' => [['questionNumber' => '1']],
            ]);
        });

        $handler = new ProcessAnalysisMessageHandler(
            $legacyService,
            $directService,
            $this->createMock(AnalysisProcessingRecoveryService::class),
            new \App\Service\Analysis\AnalysisModeResolver('direct', $this->createMock(LoggerInterface::class)),
            $mongoRepo,
            $pgRepo,
            $this->createMock(LoggerInterface::class),
        );

        $message = new ProcessAnalysisMessage($analysisId, $studentId, 'analyze', false, 0);

        $handler($message);

        $updatedAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, $studentId);
        $this->assertSame('DONE', $updatedAnalysis->getStatus(), 'Confirmed metadata with valid fallback AI analysis must become DONE');
        $this->assertSame('DONE', $updatedAnalysis->getProcessing()->getStatus());
        $this->assertSame('COMPLETED', $updatedAnalysis->getProcessing()->getStage());
    }

    public function testUnconfirmedMetadataAfterFallbackRemainsNeedsReview(): void
    {
        $analysisId = 'analysis-1';
        $studentId = 'student-1';

        $mongoAnalysis = $this->createMongoAnalysis($analysisId, $studentId, 'PROCESSING');
        $mongoAnalysis->getExam()->setExtractedMetadata([]);
        $mongoAnalysis->setMetadataConfirmationStatus('PENDING');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgAnalysis = $this->createMock(PgAnalysis::class);
        $pgAnalysis->method('getMetadataConfirmationStatus')->willReturn('PENDING');
        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $directService = $this->createMock(DirectAiAnalysisService::class);
        $directService->method('runDirectAnalysis')->willThrowException(new \RuntimeException('Direct AI response validation failed: malformed schema'));

        $legacyService = $this->createMock(AnalysisProcessingService::class);
        $legacyService->method('processAnalysis')->willReturnCallback(function () use ($mongoAnalysis) {
            $mongoAnalysis->setStatus('NEEDS_REVIEW');
            $mongoAnalysis->getProcessing()->setStatus('NEEDS_REVIEW');
            $mongoAnalysis->getProcessing()->setStage('VALIDATING_EXTRACTION');
        });

        $handler = new ProcessAnalysisMessageHandler(
            $legacyService,
            $directService,
            $this->createMock(AnalysisProcessingRecoveryService::class),
            new \App\Service\Analysis\AnalysisModeResolver('direct', $this->createMock(LoggerInterface::class)),
            $mongoRepo,
            $pgRepo,
            $this->createMock(LoggerInterface::class),
        );

        $message = new ProcessAnalysisMessage($analysisId, $studentId, 'analyze', false, 0);

        $handler($message);

        $updatedAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, $studentId);
        $this->assertSame('NEEDS_REVIEW', $updatedAnalysis->getStatus(), 'Unconfirmed metadata must remain NEEDS_REVIEW after fallback');
        $this->assertSame('NEEDS_REVIEW', $updatedAnalysis->getProcessing()->getStatus());
    }
}