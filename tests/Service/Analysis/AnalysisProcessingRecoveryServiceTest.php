<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Document\Analysis;
use App\Document\Exam;
use App\Document\Processing;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\AnalysisProcessingRecoveryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AnalysisProcessingRecoveryServiceTest extends TestCase
{
    private function createStaleProcessingAnalysis(): Analysis
    {
        $analysis = new Analysis();
        $analysis->setStudentId('student-123');
        $analysis->setSource('EXTERNAL_UPLOAD');
        $analysis->setStatus('PROCESSING');

        $processing = new Processing();
        $processing->setStatus('PROCESSING');
        $processing->setStage('EXTRACTING_HANDWRITING');
        $processing->setStartedAt(new \DateTimeImmutable('-200 seconds'));
        $processing->setAttemptId(1);
        $analysis->setProcessing($processing);

        $exam = new Exam();
        $analysis->setExam($exam);

        return $analysis;
    }

    private function createActiveProcessingAnalysis(): Analysis
    {
        $analysis = $this->createStaleProcessingAnalysis();
        $analysis->getProcessing()->setStartedAt(new \DateTimeImmutable('-30 seconds'));
        return $analysis;
    }

    private function createCompletedAnalysis(): Analysis
    {
        $analysis = new Analysis();
        $analysis->setStudentId('student-123');
        $analysis->setSource('EXTERNAL_UPLOAD');
        $analysis->setStatus('NEEDS_REVIEW');

        $processing = new Processing();
        $processing->setStatus('NEEDS_REVIEW');
        $processing->setStage('COMPLETED');
        $processing->setCompletedAt(new \DateTimeImmutable('-100 seconds'));
        $processing->setStartedAt(new \DateTimeImmutable('-200 seconds'));
        $analysis->setProcessing($processing);

        return $analysis;
    }

    public function testRecoverStaleProcessingMarksAsFailed(): void
    {
        $analysis = $this->createStaleProcessingAnalysis();

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findStaleProcessing')->willReturn([$analysis]);
        $mongoRepo->expects($this->once())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $recoveredCount = $service->recoverStaleProcessing(180);

        $this->assertSame(1, $recoveredCount);
        $this->assertSame('FAILED', $analysis->getStatus());
        $this->assertSame('FAILED', $analysis->getProcessing()->getStatus());
        $this->assertSame('TIMEOUT', $analysis->getProcessing()->getStage());
        $this->assertNotNull($analysis->getProcessing()->getCompletedAt());
        $this->assertStringContainsString('timed out', $analysis->getProcessing()->getError());
    }

    public function testRecoverStaleProcessingWithMultipleAnalyses(): void
    {
        $analysis1 = $this->createStaleProcessingAnalysis();
        $analysis2 = $this->createStaleProcessingAnalysis();

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findStaleProcessing')->willReturn([$analysis1, $analysis2]);
        $mongoRepo->expects($this->exactly(2))->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $recoveredCount = $service->recoverStaleProcessing(180);

        $this->assertSame(2, $recoveredCount);
        $this->assertSame('FAILED', $analysis1->getStatus());
        $this->assertSame('FAILED', $analysis2->getStatus());
    }

    public function testRecoverStaleProcessingSkipsActiveProcessing(): void
    {
        $activeAnalysis = $this->createActiveProcessingAnalysis();

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findStaleProcessing')->willReturn([]);

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $recoveredCount = $service->recoverStaleProcessing(180);

        $this->assertSame(0, $recoveredCount);
        $this->assertSame('PROCESSING', $activeAnalysis->getStatus());
    }

    public function testRecoverStaleProcessingReturnsZeroWhenNoStale(): void
    {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findStaleProcessing')->willReturn([]);
        $mongoRepo->expects($this->never())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $recoveredCount = $service->recoverStaleProcessing(180);

        $this->assertSame(0, $recoveredCount);
    }

    public function testRecoverIfStaleReturnsTrueForStaleAnalysis(): void
    {
        $analysis = $this->createStaleProcessingAnalysis();

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisId')->willReturn($analysis);
        $mongoRepo->expects($this->once())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $result = $service->recoverIfStale($analysis->getAnalysisId());

        $this->assertTrue($result);
        $this->assertSame('FAILED', $analysis->getStatus());
    }

    public function testRecoverIfStaleReturnsFalseForActiveProcessing(): void
    {
        $analysis = $this->createActiveProcessingAnalysis();

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisId')->willReturn($analysis);
        $mongoRepo->expects($this->never())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $result = $service->recoverIfStale($analysis->getAnalysisId());

        $this->assertFalse($result);
        $this->assertSame('PROCESSING', $analysis->getStatus());
    }

    public function testRecoverIfStaleReturnsFalseForNonProcessingStatus(): void
    {
        $analysis = $this->createCompletedAnalysis();

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisId')->willReturn($analysis);
        $mongoRepo->expects($this->never())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $result = $service->recoverIfStale($analysis->getAnalysisId());

        $this->assertFalse($result);
    }

    public function testRecoverIfStaleReturnsFalseForNotFound(): void
    {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisId')->willReturn(null);
        $mongoRepo->expects($this->never())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $result = $service->recoverIfStale('nonexistent-id');

        $this->assertFalse($result);
    }

    public function testRecoverIfStaleReturnsFalseWhenStartedAtIsNull(): void
    {
        $analysis = new Analysis();
        $analysis->setStatus('PROCESSING');

        $processing = new Processing();
        $processing->setStatus('PROCESSING');
        $processing->setStage('EXTRACTING_HANDWRITING');
        $processing->setStartedAt(null);
        $analysis->setProcessing($processing);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisId')->willReturn($analysis);
        $mongoRepo->expects($this->never())->method('save');

        $service = new AnalysisProcessingRecoveryService($mongoRepo, new NullLogger());

        $result = $service->recoverIfStale($analysis->getAnalysisId());

        $this->assertFalse($result);
    }
}
