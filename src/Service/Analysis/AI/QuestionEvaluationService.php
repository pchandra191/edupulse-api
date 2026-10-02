<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use Psr\Log\LoggerInterface;

readonly class QuestionEvaluationService
{
    public function __construct(
        private AuditedAiProvider $aiProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function getAiProvider(): AiProviderInterface
    {
        return $this->aiProvider->getProvider();
    }

    /** @return array<QuestionEvaluationResult> */
    public function evaluateQuestions(Analysis $analysis): array
    {
        $questions = $analysis->getExam()->getExtractedQuestions();
        $results = [];

        foreach ($questions as $question) {
            // Extract flat values from ProvenanceValue-wrapped extracted questions
            $questionNumber = $question['questionNumber']['value'] ?? ($question['questionNumber'] ?? '');
            $questionText = $question['questionText']['value'] ?? ($question['questionText'] ?? '');
            $marks = $question['marks']['value'] ?? ($question['marks'] ?? 0);
            $type = $question['type']['value'] ?? ($question['type'] ?? 'SHORT_ANSWER');
            $studentAnswer = $question['answerText']['value'] ?? ($question['answerText'] ?? null);
            
            $request = new QuestionEvaluationRequest(
                questionNumber: is_string($questionNumber) ? $questionNumber : (string)($questionNumber ?? ''),
                questionText: is_string($questionText) ? $questionText : (string)($questionText ?? ''),
                maxMarks: is_int($marks) ? $marks : (int)($marks ?? 0),
                questionType: is_string($type) ? $type : (string)($type ?? 'SHORT_ANSWER'),
                studentAnswer: is_string($studentAnswer) ? $studentAnswer : null,
                subject: $analysis->getExam()->getSubject(),
                topics: $question['topics'] ?? [],
                concepts: $question['concepts'] ?? [],
            );

            $this->logger->info('Evaluating question', [
                'analysis_id' => $analysis->getAnalysisId(),
                'question_number' => $request->questionNumber,
            ]);

            $auditResult = $this->aiProvider->evaluateQuestion($request, $analysis);
            $results[] = $auditResult['result'];
        }

        return $results;
    }
}