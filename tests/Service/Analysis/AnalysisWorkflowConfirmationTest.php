<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Document\Analysis as MongoAnalysis;
use App\Document\Exam;
use App\Document\Processing;
use App\Entity\User;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Service\Analysis\AnalysisWorkflowService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class AnalysisWorkflowConfirmationTest extends TestCase
{
    /**
     * @param object[] $dispatched
     */
    private function buildService(array &$dispatched, MongoAnalysis $mongoAnalysis): AnalysisWorkflowService
    {
        $mongoRepo = $this->createMock(\App\Repository\MongoDB\AnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);
        $mongoRepo->method('save')->willReturnCallback(static function ($a) { return $a; });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            }
        );

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        $pgAnalysis = new \App\Entity\Analysis();
        $pgRepo = $this->createMock(\App\Repository\AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);
        $pgRepo->method('findByAnalysisIdAndStudent')->willReturn($pgAnalysis);

        return new AnalysisWorkflowService(
            $entityManager,
            $pgRepo,
            $this->createMock(\App\Repository\ExamSetRepository::class),
            $mongoRepo,
            $bus,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function mongoAnalysis(string $status): MongoAnalysis
    {
        $analysis = new MongoAnalysis();
        $analysis->setAnalysisId('test-analysis-id');
        $analysis->setStudentId('student-id');
        $analysis->setStatus($status);
        $analysis->setExam(new Exam());
        $analysis->setProcessing(new Processing());

        return $analysis;
    }

    public function testFirstConfirmationDispatchesExactlyOneAnalyzeAndMarksProcessing(): void
    {
        $mongo = $this->createMongoForReview();
        $dispatched = [];

        $service = $this->buildService($dispatched, $mongo);
        $pgAnalysis = new \App\Entity\Analysis();
        $pgAnalysis->setAnalysisId('test-analysis-id');
        $pgAnalysis->setStatus('NEEDS_REVIEW');

        $this->callSaveMetadata($service, $pgAnalysis);

        $analyze = array_values(array_filter(
            $dispatched,
            static fn(object $m): bool => $m instanceof ProcessAnalysisMessage && $m->action === 'analyze'
        ));

        $this->assertCount(1, $analyze, 'First confirmation must dispatch exactly one analyze message');
        $this->assertSame('test-analysis-id', $analyze[0]->analysisId);

        // The Mongo processing document must reflect PROCESSING immediately.
        $processing = $mongo->getProcessing();
        $this->assertSame('ANALYZING', $processing->getStage());
        $this->assertSame('PROCESSING', $processing->getStatus());
        $this->assertSame('PROCESSING', $mongo->getStatus());

        // PostgreSQL must no longer sit at CONFIRMED.
        $this->assertSame('PROCESSING', $pgAnalysis->getStatus());
    }

    public function testRepeatedConfirmationIsRejectedAndDoesNotDispatchSecondAnalyze(): void
    {
        $mongo = $this->createMongoForReview();
        $dispatched = [];

        $service = $this->buildService($dispatched, $mongo);
        $pgAnalysis = new \App\Entity\Analysis();
        $pgAnalysis->setAnalysisId('test-analysis-id');
        $pgAnalysis->setStatus('NEEDS_REVIEW');

        $this->callSaveMetadata($service, $pgAnalysis);
        $this->assertCount(1, $dispatched);

        // Second confirmation: the analysis is now PROCESSING, so the existing
        // workflow guard rejects it outright and nothing is dispatched again.
        $this->expectException(\LogicException::class);
        try {
            $this->callSaveMetadata($service, $pgAnalysis);
        } finally {
            $this->assertCount(
                1,
                $dispatched,
                'Repeated confirmation must not dispatch a second analyze message'
            );
        }
    }

    public function testConfirmationDoesNotDispatchWhenAiAnalysisAlreadyExists(): void
    {
        $mongo = $this->createMongoForReview();
        $mongo->setAiAnalysis(['questionAnalysis' => [], 'currentExamAnalysis' => []]);

        $dispatched = [];
        $service = $this->buildService($dispatched, $mongo);

        $pgAnalysis = new \App\Entity\Analysis();
        $pgAnalysis->setAnalysisId('test-analysis-id');
        $pgAnalysis->setStatus('NEEDS_REVIEW');

        $this->callSaveMetadata($service, $pgAnalysis);

        $this->assertCount(0, $dispatched, 'An already analyzed analysis must not be re-dispatched');
    }

    private function createMongoForReview(): MongoAnalysis
    {
        $analysis = $this->mongoAnalysis('NEEDS_REVIEW');
        $analysis->getProcessing()->setStage('READY_FOR_REVIEW');
        $analysis->getProcessing()->setStatus('NEEDS_REVIEW');

        return $analysis;
    }

    private function callSaveMetadata(
        AnalysisWorkflowService $service,
        \App\Entity\Analysis $pgAnalysis
    ): void {
        $metadata = new \App\DTO\Analysis\AnalysisMetadataRequestDTO(
            examSetId: null,
            subjectId: null,
            subjectName: 'Mathematics',
            totalMarks: 40,
            scoredMarks: null,
            studentName: 'Jane Doe',
            rollNumber: '21',
        );

        $service->saveMetadata($pgAnalysis, $metadata, new User());
    }
}