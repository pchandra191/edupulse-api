<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Service\Analysis\Processing\QuestionExtractor;
use App\Service\Analysis\Processing\ProvenanceValue;
use App\Service\Analysis\Processing\ExtractedQuestion;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class QuestionExtractorTest extends KernelTestCase
{
    private QuestionExtractor $extractor;

    protected function setUp(): void
    {
        $kernel = static::getContainer();
        $this->extractor = new QuestionExtractor(new NullLogger());
    }

    public function testExtractQuestionsFromText(): void
    {
        $questionPaperPages = [
            ['page' => 1, 'text' => 'Question 1: What is 2+2? (2 marks)'],
            ['page' => 2, 'text' => 'Question 2: Solve for x: 2x + 5 = 15 (5 marks)'],
        ];

        $questions = $this->extractor->extractQuestions($questionPaperPages);

        $this->assertCount(2, $questions);
        
        foreach ($questions as $question) {
            $this->assertInstanceOf(ExtractedQuestion::class, $question);
            $this->assertInstanceOf(ProvenanceValue::class, $question->questionNumber);
            $this->assertInstanceOf(ProvenanceValue::class, $question->questionText);
            $this->assertInstanceOf(ProvenanceValue::class, $question->marks);
            $this->assertInstanceOf(ProvenanceValue::class, $question->type);
            
            // Check provenance statuses
            $this->assertSame('EXTRACTED', $question->questionNumber->status->value);
            $this->assertSame('EXTRACTED', $question->questionText->status->value);
            $this->assertSame('INFERRED', $question->type->status->value);
        }

        // Check specific question numbers
        $questionNumbers = array_map(fn($q) => $q->questionNumber->value, $questions);
        $this->assertContains('1', $questionNumbers);
        $this->assertContains('2', $questionNumbers);
    }

    public function testMissingMarksReturnsMissingProvenance(): void
    {
        $questionPaperPages = [
            ['page' => 1, 'text' => 'Question 1: What is the capital of France?'],
        ];

        $questions = $this->extractor->extractQuestions($questionPaperPages);

        $this->assertCount(1, $questions);
        $this->assertSame('MISSING', $questions[0]->marks->status->value);
        $this->assertNull($questions[0]->marks->value);
        $this->assertSame(0.0, $questions[0]->marks->confidence);
    }

    public function testInferredValuesRetainInferredProvenance(): void
    {
        $questionPaperPages = [
            ['page' => 1, 'text' => 'Question 1: What is 2+2?'],
        ];

        $questions = $this->extractor->extractQuestions($questionPaperPages);

        $this->assertSame('INFERRED', $questions[0]->type->status->value);
        $this->assertLessThan(0.9, $questions[0]->type->confidence);
    }

    public function testSourcePageMetadataPreserved(): void
    {
        $questionPaperPages = [
            ['page' => 3, 'text' => 'Question 1: What is 2+2?'],
        ];

        $questions = $this->extractor->extractQuestions($questionPaperPages);

        $this->assertSame(3, $questions[0]->source['page']);
    }

    public function testMapAnswersToQuestions(): void
    {
        $questions = [
            new ExtractedQuestion(
                questionNumber: ProvenanceValue::extracted('1', 0.95),
                questionText: ProvenanceValue::extracted('What is 2+2?', 0.9),
                marks: ProvenanceValue::extracted(2, 0.85),
                type: ProvenanceValue::inferred('numerical', 0.7),
                source: ['page' => 1, 'bbox' => null],
            ),
            new ExtractedQuestion(
                questionNumber: ProvenanceValue::extracted('2', 0.95),
                questionText: ProvenanceValue::extracted('Solve for x', 0.9),
                marks: ProvenanceValue::extracted(5, 0.85),
                type: ProvenanceValue::inferred('numerical', 0.7),
                source: ['page' => 1, 'bbox' => null],
            ),
        ];

        $answerSheetPages = [
            ['page' => 5, 'text' => "Q1: 4\nQ2: x = 5"],
        ];

        $questionsWithAnswers = $this->extractor->mapAnswersToQuestions($questions, $answerSheetPages);

        $this->assertNotNull($questionsWithAnswers[0]->answerText);
        $this->assertSame('EXTRACTED', $questionsWithAnswers[0]->answerText->status->value);
        $this->assertSame('4', $questionsWithAnswers[0]->answerText->value);
        
        $this->assertNotNull($questionsWithAnswers[1]->answerText);
        $this->assertSame('x = 5', $questionsWithAnswers[1]->answerText->value);
    }

    public function testRetryDoesNotDuplicateQuestions(): void
    {
        $questionPaperPages = [
            ['page' => 1, 'text' => 'Question 1: What is 2+2?'],
        ];

        // First extraction
        $questions1 = $this->extractor->extractQuestions($questionPaperPages);
        
        // Second extraction (simulating retry)
        $questions2 = $this->extractor->extractQuestions($questionPaperPages);
        
        // Should have same number of questions
        $this->assertCount(1, $questions1);
        $this->assertCount(1, $questions2);
    }
}