<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Report;

use App\Document\Exam;
use App\DTO\Analysis\Report\AiAnswerSheetReportData;
use App\DTO\Analysis\Report\ConceptMasteryReportData;
use App\DTO\Analysis\Report\ExamReportData;
use App\DTO\Analysis\Report\QuestionReportData;
use App\DTO\Analysis\Report\ResultSummaryReportData;
use App\DTO\Analysis\Report\StudentReportData;
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
            'test@example.com',
            'https://example.com',
            'https://linkedin.com',
            'https://twitter.com',
            'https://youtube.com',
            'https://github.com',
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

    /**
     * Regression test: Ensure "Overall Feedback from EduPulse AI" and "Concept Mastery"
     * sections appear exactly once in the rendered HTML (which becomes the PDF).
     * This prevents duplicate rendering bugs where the overall feedback section
     * was accidentally rendered twice (once correctly, once duplicated at the end).
     */
    public function testPdfTemplateRendersOverallFeedbackAndConceptMasteryOnce(): void
    {
        $service = $this->createService();

        // Create real report data using actual DTO classes
        $reportData = new AiAnswerSheetReportData(
            student: new StudentReportData(name: 'Test Student', rollNumber: '01', class: '10', section: 'A'),
            exam: new ExamReportData(subject: 'Math', title: 'Test Exam', totalMarks: 100, date: '2026-01-01'),
            result: new ResultSummaryReportData(score: 90, percentage: 90.0, totalQuestions: 1, correct: 1, partiallyCorrect: 0, incorrect: 0),
            questions: [
                new QuestionReportData(
                    number: '1',
                    question: 'Test question',
                    studentAnswer: 'Test answer',
                    marksAwarded: 1,
                    maxMarks: 1,
                    evaluationStatus: 'CORRECT',
                    subQuestion: null,
                    correctness: null,
                    whatStudentDidWell: 'Good job',
                    mistake: '',
                    whyItIsWrong: '',
                    missingPoints: [],
                    concept: null,
                    improvement: null,
                    evidence: [],
                ),
            ],
            strengths: ['Strength 1', 'Strength 2'],
            weaknesses: ['Weakness 1'],
            needsAttention: ['Attention item'],
            conceptGaps: ['Gap 1'],
            recommendations: ['Recommendation 1'],
            conceptMastery: [
                new ConceptMasteryReportData(concept: 'Test Concept', status: 'STRONG', confidence: 0.9),
            ],
        );

        // Use a real Twig environment to render the actual template
        $loader = new \Twig\Loader\FilesystemLoader('F:\Projects\React\edupulse-api\templates\reports');
        $twigEnv = new Environment($loader);

        $logoPath = sys_get_temp_dir() . '/logo.png';
        // Create a dummy logo file for testing
        if (!file_exists($logoPath)) {
            // Create a minimal PNG (1x1 transparent)
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            file_put_contents($logoPath, $png);
        }

        $html = $twigEnv->render('ai_answer_sheet.html.twig', [
            'report' => $reportData,
            'logo_path' => $logoPath,
            'contact_email' => 'test@example.com',
            'website_url' => 'https://example.com',
            'social_linkedin_url' => 'https://linkedin.com',
            'social_twitter_url' => 'https://twitter.com',
            'social_youtube_url' => 'https://youtube.com',
            'social_github_url' => 'https://github.com',
        ]);

        // Verify "Overall Feedback from EduPulse AI" appears exactly once
        $overallFeedbackCount = substr_count($html, 'Overall Feedback from EduPulse AI');
        $this->assertSame(1, $overallFeedbackCount, 'Overall Feedback section should appear exactly once');

        // Verify "Concept Mastery" appears exactly once (as a table header)
        $conceptMasteryCount = substr_count($html, '<th>Concept</th>');
        $this->assertSame(1, $conceptMasteryCount, 'Concept Mastery table should appear exactly once');

        // Verify "Answer Sheet Evaluation" appears exactly once
        $answerSheetCount = substr_count($html, 'Answer Sheet Evaluation');
        $this->assertSame(1, $answerSheetCount, 'Answer Sheet Evaluation section should appear exactly once');

        // Verify all question evaluations are present (question numbers in headers)
        $this->assertStringContainsString('Question 1', $html, 'Question 1 should be present');
        $this->assertStringContainsString('Test question', $html, 'Question text should be present');
        $this->assertStringContainsString('Test answer', $html, 'Student answer should be present');
    }

    private function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
