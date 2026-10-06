<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI\Prompt;

use App\Service\Analysis\AI\Prompt\ExamAnalysisPromptBuilder;
use PHPUnit\Framework\TestCase;

class ExamAnalysisPromptBuilderTest extends TestCase
{
    private ExamAnalysisPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ExamAnalysisPromptBuilder();
    }

    public function testBuildIncludesStaticInstructions(): void
    {
        $prompt = $this->builder->build([]);

        $this->assertStringContainsString('EduPulse\'s Exam Analysis Engine', $prompt);
        $this->assertStringContainsString('AUTHORITATIVE', $prompt);
        $this->assertStringContainsString('questionAnalysis', $prompt);
        $this->assertStringContainsString('CONFIRMED EXAM METADATA', $prompt);
    }

    public function testBuildIncludesQuestionAnalysisInstructions(): void
    {
        $prompt = $this->builder->build([]);

        $this->assertStringContainsString('Analyze EVERY QUESTION THAT THE STUDENT ATTEMPTED', $prompt);
        $this->assertStringContainsString('questionAnalysis is REQUIRED', $prompt);
        $this->assertStringContainsString('whatStudentDidWell', $prompt);
        $this->assertStringContainsString('whyItIsWrong', $prompt);
        $this->assertStringContainsString('whatToLearn', $prompt);
        $this->assertStringContainsString('whatToPractice', $prompt);
    }

    public function testBuildIncludesNoHistoricalAnalysisInstruction(): void
    {
        $prompt = $this->builder->build([]);

        $this->assertStringContainsString('NO HISTORICAL ANALYSIS', $prompt);
        $this->assertStringContainsString('previous exams', $prompt);
    }

    public function testBuildWithMetadataIncludesFields(): void
    {
        $metadata = [
            'academicYear' => '2026-27',
            'class' => '10',
            'section' => 'A',
            'exam' => 'Mid Sem',
            'examSubType' => 'Written',
            'subject' => 'Physics',
            'examTitle' => 'Mid Sem - Physics - 30',
            'totalMarks' => '30',
            'scoredMarks' => '25',
            'studentName' => 'John Doe',
            'rollNumber' => '123',
        ];

        $prompt = $this->builder->build($metadata);

        $this->assertStringContainsString('Academic Year: 2026-27', $prompt);
        $this->assertStringContainsString('Class: 10', $prompt);
        $this->assertStringContainsString('Section: A', $prompt);
        $this->assertStringContainsString('Exam: Mid Sem', $prompt);
        $this->assertStringContainsString('Exam Sub Type: Written', $prompt);
        $this->assertStringContainsString('Subject: Physics', $prompt);
        $this->assertStringContainsString('Exam Title: Mid Sem - Physics - 30', $prompt);
        $this->assertStringContainsString('Total Marks: 30', $prompt);
        $this->assertStringContainsString('Scored Marks: 25', $prompt);
        $this->assertStringContainsString('Student Name: John Doe', $prompt);
        $this->assertStringContainsString('Roll Number: 123', $prompt);
    }

    public function testBuildWithNullMetadataOmitsFields(): void
    {
        $metadata = [
            'academicYear' => null,
            'class' => null,
            'subject' => null,
        ];

        $prompt = $this->builder->build($metadata);

        $this->assertStringContainsString('No confirmed metadata available.', $prompt);
        $this->assertStringNotContainsString('Academic Year:', $prompt);
        $this->assertStringNotContainsString('Class:', $prompt);
    }

    public function testBuildWithEmptyArrayShowsNoMetadata(): void
    {
        $prompt = $this->builder->build([]);

        $this->assertStringContainsString('No confirmed metadata available.', $prompt);
    }
}
