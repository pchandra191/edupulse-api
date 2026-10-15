<?php

declare(strict_types=1);

namespace App\Service\Analysis\Report;

use App\DTO\Analysis\Report\AiAnswerSheetReportData;
use App\DTO\Analysis\Report\ConceptMasteryReportData;
use App\DTO\Analysis\Report\ConceptReportData;
use App\DTO\Analysis\Report\ExamReportData;
use App\DTO\Analysis\Report\ImprovementReportData;
use App\DTO\Analysis\Report\QuestionReportData;
use App\DTO\Analysis\Report\ResultSummaryReportData;
use App\DTO\Analysis\Report\StudentReportData;
use App\Entity\ExamSet;
use App\Entity\User;
use App\Exception\AnalysisNotFoundException;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\AnalysisService;
use Mpdf\Output\Destination;
use Mpdf\Mpdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\Security;
use Twig\Environment;

readonly class AiAnswerSheetReportService
{
    private const STATUS_DONE = 'DONE';
    private const EVAL_CORRECT = 'CORRECT';
    private const EVAL_PARTIALLY_CORRECT = 'PARTIALLY_CORRECT';
    private const EVAL_INCORRECT = 'INCORRECT';
    private const EVAL_UNANSWERED = 'UNANSWERED';

    public function __construct(
        private AnalysisService $analysisService,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private Environment $twig,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%app.contact_email%')]
        private string $contactEmail,
        #[Autowire('%app.website_url%')]
        private string $websiteUrl,
        #[Autowire('%app.social_linkedin_url%')]
        private string $socialLinkedinUrl,
        #[Autowire('%app.social_twitter_url%')]
        private string $socialTwitterUrl,
        #[Autowire('%app.social_youtube_url%')]
        private string $socialYoutubeUrl,
        #[Autowire('%app.social_github_url%')]
        private string $socialGithubUrl,
    ) {
    }

    /**
     * @return string PDF binary content
     */
    public function generateReport(User $student, string $analysisId): string
    {
        $mongoAnalysis = $this->analysisService->getMongoAnalysis($student, $analysisId);

        $processing = $mongoAnalysis->getProcessing();
        $processingStatus = $processing->getStatus();

        if ($processingStatus !== self::STATUS_DONE) {
            throw new \LogicException('AI report is available only after analysis is completed.');
        }

        $aiAnalysis = $mongoAnalysis->getAiAnalysis();
        if (!$aiAnalysis || !is_array($aiAnalysis)) {
            throw new \LogicException('AI report is available only after analysis is completed.');
        }

        $questionAnalysis = $aiAnalysis['questionAnalysis'] ?? null;
        if (!$questionAnalysis || !is_array($questionAnalysis) || count($questionAnalysis) === 0) {
            throw new \LogicException('AI report is available only after analysis is completed.');
        }

        $reportData = $this->buildReportData($student, $mongoAnalysis, $aiAnalysis);

        return $this->renderPdf($reportData);
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function generateReportWithFilename(User $student, string $analysisId): array
    {
        $mongoAnalysis = $this->analysisService->getMongoAnalysis($student, $analysisId);

        $processing = $mongoAnalysis->getProcessing();
        $processingStatus = $processing->getStatus();

        if ($processingStatus !== self::STATUS_DONE) {
            throw new \LogicException('AI report is available only after analysis is completed.');
        }

        $aiAnalysis = $mongoAnalysis->getAiAnalysis();
        if (!$aiAnalysis || !is_array($aiAnalysis)) {
            throw new \LogicException('AI report is available only after analysis is completed.');
        }

        $questionAnalysis = $aiAnalysis['questionAnalysis'] ?? null;
        if (!$questionAnalysis || !is_array($questionAnalysis) || count($questionAnalysis) === 0) {
            throw new \LogicException('AI report is available only after analysis is completed.');
        }

        $reportData = $this->buildReportData($student, $mongoAnalysis, $aiAnalysis);
        $filename = $this->buildFilename($reportData);

        return [
            'filename' => $filename,
            'content' => $this->renderPdf($reportData),
        ];
    }

    private function buildReportData(User $student, \App\Document\Analysis $mongoAnalysis, array $aiAnalysis): AiAnswerSheetReportData
    {
        $exam = $mongoAnalysis->getExam();
        $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? [];
        $extractedMetadata = $exam->getExtractedMetadata() ?? [];

        $studentData = $this->buildStudentData($student, $mongoAnalysis, $extractedMetadata);
        $examData = $this->buildExamData($exam, $extractedMetadata);
        $resultData = $this->buildResultSummary($aiAnalysis, $mongoAnalysis, $student);
        $questions = $this->buildQuestions($aiAnalysis['questionAnalysis'] ?? []);
        $conceptMastery = $this->buildConceptMastery($currentExamAnalysis);

        return new AiAnswerSheetReportData(
            student: $studentData,
            exam: $examData,
            result: $resultData,
            questions: $questions,
            strengths: $currentExamAnalysis['strengths'] ?? [],
            weaknesses: $currentExamAnalysis['weaknesses'] ?? [],
            needsAttention: $currentExamAnalysis['needsAttention'] ?? [],
            conceptGaps: $currentExamAnalysis['conceptGaps'] ?? [],
            recommendations: $currentExamAnalysis['recommendations'] ?? [],
            conceptMastery: $conceptMastery,
        );
    }

    private function buildStudentData(User $student, \App\Document\Analysis $mongoAnalysis, array $extractedMetadata): StudentReportData
    {
        $pgAnalysis = $this->analysisService->getAnalysisEntity($student, $mongoAnalysis->getAnalysisId());

        $examSet = $pgAnalysis->getExamSet();
        $studentName = $pgAnalysis->getStudentName() ?? $student->getFullName();

        $metadataStudentName = $this->extractMetadataValue($extractedMetadata, 'studentName');
        if ($metadataStudentName !== null) {
            $studentName = $metadataStudentName;
        }

        $rollNumber = $this->extractMetadataValue($extractedMetadata, 'rollNumber') ??
            $pgAnalysis->getRollNumber();

        $class = $this->extractMetadataValue($extractedMetadata, 'class') ??
            ($examSet?->getAcademicClass() ?: null);

        $section = $this->extractMetadataValue($extractedMetadata, 'section') ??
            ($examSet?->getSection() ?: null);

        return new StudentReportData(
            name: $studentName ?: $student->getFullName(),
            rollNumber: $rollNumber,
            class: $class,
            section: $section,
        );
    }

    private function buildExamData(\App\Document\Exam $exam, array $extractedMetadata): ExamReportData
    {
        $subject = $this->extractMetadataValue($extractedMetadata, 'subject') ??
            $exam->getSubject();

        $title = $this->extractMetadataValue($extractedMetadata, 'examTitle') ??
            $this->extractMetadataValue($extractedMetadata, 'examSubType') ??
            $exam->getTitle();

        $totalMarks = $this->extractMetadataValue($extractedMetadata, 'totalMarks');
        if ($totalMarks === null) {
            $totalMarks = $exam->getTotalMarks();
        }

        $examDate = $this->extractMetadataValue($extractedMetadata, 'examDate');
        if ($examDate !== null) {
            try {
                $examDate = (new \DateTimeImmutable($examDate))->format('d F Y');
            } catch (\Throwable) {
                $examDate = null;
            }
        } else {
            $examDate = $exam->getExtractedAt()?->format('d F Y');
        }

        return new ExamReportData(
            title: $title,
            subject: $subject,
            totalMarks: $totalMarks !== null ? (int) $totalMarks : null,
            date: $examDate,
            extractedMetadata: $extractedMetadata,
        );
    }

    private function extractMetadataValue(array $extractedMetadata, string $key): ?string
    {
        $field = $extractedMetadata[$key] ?? null;

        if ($field === null) {
            return null;
        }

        if (is_array($field)) {
            $value = $field['value'] ?? ($field['value'] ?? null);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }

            return null;
        }

        if (is_object($field) && method_exists($field, 'getValue')) {
            $value = $field->getValue();
            if ($value !== null && $value !== '') {
                return (string) $value;
            }

            return null;
        }

        if ($field !== null && $field !== '') {
            return (string) $field;
        }

        return null;
    }

    private function buildResultSummary(array $aiAnalysis, \App\Document\Analysis $mongoAnalysis, User $student): ResultSummaryReportData
    {
        $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? [];
        $performance = $currentExamAnalysis['performance'] ?? [];

        // Use confirmed metadata from PostgreSQL as authoritative score
        $pgAnalysis = $this->analysisService->getAnalysisEntity($student, $mongoAnalysis->getAnalysisId());
        $scoredMarks = $pgAnalysis?->getScoredMarks();
        $totalMarks = $pgAnalysis?->getTotalMarks();

        $score = $scoredMarks !== null ? (int) $scoredMarks : ($performance['score'] ?? null);
        $percentage = null;
        if ($score !== null && $totalMarks !== null && $totalMarks > 0) {
            $percentage = round(($score / $totalMarks) * 100, 2);
        } elseif ($performance['percentage'] !== null) {
            $percentage = (float) $performance['percentage'];
        }

        $questionAnalysis = $aiAnalysis['questionAnalysis'] ?? [];
        $totalQuestions = count($questionAnalysis);

        $correct = 0;
        $partiallyCorrect = 0;
        $incorrect = 0;
        $unanswered = 0;

        foreach ($questionAnalysis as $q) {
            $eval = $q['evaluation'] ?? [];
            $status = strtoupper((string) ($eval['evaluationStatus'] ?? ''));

            match ($status) {
                self::EVAL_CORRECT => $correct++,
                self::EVAL_PARTIALLY_CORRECT => $partiallyCorrect++,
                self::EVAL_INCORRECT => $incorrect++,
                self::EVAL_UNANSWERED => $unanswered++,
                default => null,
            };
        }

        return new ResultSummaryReportData(
            score: $score !== null ? (int) $score : null,
            percentage: $percentage !== null ? (float) $percentage : null,
            totalQuestions: $totalQuestions,
            correct: $correct,
            partiallyCorrect: $partiallyCorrect,
            incorrect: $incorrect,
            unanswered: $unanswered,
        );
    }

    /**
     * @param array<array<string, mixed>> $questionAnalysis
     * @return array<QuestionReportData>
     */
    private function buildQuestions(array $questionAnalysis): array
    {
        $questions = [];

        foreach ($questionAnalysis as $q) {
            $eval = $q['evaluation'] ?? [];
            $evaluationStatus = strtoupper((string) ($eval['evaluationStatus'] ?? 'UNKNOWN'));
            $marksAwarded = $eval['marksAwarded'] ?? null;
            $maxMarks = $eval['maxMarks'] ?? null;

            if ($marksAwarded !== null) {
                $marksAwarded = (int) $marksAwarded;
            }
            if ($maxMarks !== null) {
                $maxMarks = (int) $maxMarks;
            }

            $conceptData = null;
            $concept = $q['concept'] ?? null;
            if (is_array($concept) && !empty($concept['name'])) {
                $conceptData = new ConceptReportData(
                    name: (string) $concept['name'],
                    status: strtoupper((string) ($concept['status'] ?? 'UNKNOWN')),
                    explanation: $concept['explanation'] ?? null,
                );
            }

            $improvementData = null;
            $improvement = $q['improvement'] ?? null;
            if (is_array($improvement)) {
                $hasImprovement = false;
                $whatToLearn = $improvement['whatToLearn'] ?? null;
                $whatToPractice = $improvement['whatToPractice'] ?? null;
                $recommendedAction = $improvement['recommendedAction'] ?? null;

                if ($whatToLearn || $whatToPractice || $recommendedAction) {
                    $hasImprovement = true;
                }

                if ($hasImprovement) {
                    $improvementData = new ImprovementReportData(
                        whatToLearn: $whatToLearn ?: null,
                        whatToPractice: $whatToPractice ?: null,
                        recommendedAction: $recommendedAction ?: null,
                    );
                }
            }

            $missingPoints = [];
            if (isset($eval['missingPoints']) && is_array($eval['missingPoints'])) {
                foreach ($eval['missingPoints'] as $point) {
                    $point = trim((string) $point);
                    if ($point !== '') {
                        $missingPoints[] = $point;
                    }
                }
            }

            $evidence = [];
            if (isset($q['evidence']) && is_array($q['evidence'])) {
                foreach ($q['evidence'] as $e) {
                    $evidence[] = $e;
                }
            }

            $questions[] = new QuestionReportData(
                number: (string) ($q['questionNumber'] ?? ''),
                question: (string) ($q['question'] ?? ''),
                studentAnswer: (string) ($q['studentAnswer'] ?? ''),
                marksAwarded: $marksAwarded,
                maxMarks: $maxMarks,
                evaluationStatus: $evaluationStatus,
                subQuestion: $q['subQuestion'] ?: null,
                correctness: $eval['correctness'] ?: null,
                whatStudentDidWell: $eval['whatStudentDidWell'] ?: null,
                mistake: $eval['mistake'] ?: null,
                whyItIsWrong: $eval['whyItIsWrong'] ?: null,
                missingPoints: $missingPoints,
                concept: $conceptData,
                improvement: $improvementData,
                evidence: $evidence,
            );
        }

        return $questions;
    }

    /**
     * @param array<string, mixed> $currentExamAnalysis
     * @return array<ConceptMasteryReportData>
     */
    private function buildConceptMastery(array $currentExamAnalysis): array
    {
        $conceptAssessments = $currentExamAnalysis['conceptAssessments'] ?? [];
        $mastery = [];

        foreach ($conceptAssessments as $assessment) {
            $concept = (string) ($assessment['concept'] ?? '');
            if ($concept === '') {
                continue;
            }

            $mastery[] = new ConceptMasteryReportData(
                concept: $concept,
                status: strtoupper((string) ($assessment['status'] ?? 'UNKNOWN')),
                confidence: isset($assessment['confidence']) ? (float) $assessment['confidence'] : null,
            );
        }

        return $mastery;
    }

    private function renderPdf(AiAnswerSheetReportData $data): string
    {
        $logoPath = $this->projectDir . '/assets/logo/logo.png';
        
        $html = $this->twig->render('reports/ai_answer_sheet.html.twig', [
            'report' => $data,
            'logo_path' => $logoPath,
            'contact_email' => $this->contactEmail,
            'website_url' => $this->websiteUrl,
            'social_linkedin_url' => $this->socialLinkedinUrl,
            'social_twitter_url' => $this->socialTwitterUrl,
            'social_youtube_url' => $this->socialYoutubeUrl,
            'social_github_url' => $this->socialGithubUrl,
        ]);

        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new Mpdf([
            'tempDir' => sys_get_temp_dir(),
            'fontDir' => array_merge($fontDirs, [$this->projectDir . '/var/fonts']),
            'fontdata' => $fontData + [
                'dejavusans' => [
                    'R' => 'DejaVuSans.ttf',
                    'B' => 'DejaVuSans-Bold.ttf',
                    'I' => 'DejaVuSans-Oblique.ttf',
                    'BI' => 'DejaVuSans-BoldOblique.ttf',
                ],
            ],
            'default_font' => 'dejavusans',
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 22,
            'margin_bottom' => 22,
        ]);

        $mpdf->SetTitle('EduPulse AI Answer Sheet Report');
        $mpdf->SetAuthor('EduPulse AI');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function buildFilename(AiAnswerSheetReportData $data): string
    {
        $studentPart = $this->sanitizeFilenamePart($data->student->name ?? 'Student');
        $subjectPart = $this->sanitizeFilenamePart($data->exam->subject ?? 'Subject');
        $examPart = $this->sanitizeFilenamePart($data->exam->title ?? 'Exam');

        return sprintf('EduPulse_AI_Answer_Sheet_%s_%s_%s.pdf', $studentPart, $subjectPart, $examPart);
    }

    private function sanitizeFilenamePart(string $part): string
    {
        $part = preg_replace('/[\/\\\\]+/', '-', $part) ?? $part;
        $part = preg_replace('/[^A-Za-z0-9_-]+/', '_', $part) ?? $part;
        $part = preg_replace('/_+/', '_', $part) ?? $part;
        $part = trim($part, '_-');

        if ($part === '') {
            return 'Unknown';
        }

        return $part;
    }
}
