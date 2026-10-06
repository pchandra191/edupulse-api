<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Document\Analysis;
use App\Document\DocumentFile;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\AnalysisStorageResolver;
use App\Service\Analysis\DirectAiAnalysisService;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\DocumentTextExtractor;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * TEST 15: the two real sample answer sheets, extracted with the REAL
 * DocumentTextExtractor (SetaPDF) and the REAL AnswerSheetExtractor.
 *
 * Verifies 6 answers from the 6-question file + 3 answers from the science
 * file = 9 combined, each carrying its source file provenance.
 */
final class MultiFileAggregationRealExtractionTest extends TestCase
{
    public function testTwoSampleFilesAggregateIntoNineAnswers(): void
    {
        $dir = sys_get_temp_dir() . '/agg_' . uniqid();
        mkdir($dir, 0777, true);

        $analysisId = 'agg-analysis-1';
        $fileOne = $dir . '/file-1.pdf';
        $fileTwo = $dir . '/file-2.pdf';

        file_put_contents($fileOne, $this->buildPdf(
            'sample_answer_sheet_6_questions',
            "Student Name: Aarav Kumar\nRoll Number: 21\nSubject: Mathematics\nTotal Marks: 40\n\n"
            . "Question 1: Algebra\nAnswer: 42\n\n"
            . "Question 2: Geometry\nAnswer: Seven\n\n"
            . "Question 3: Trigonometry\nAnswer: Sine equals one\n\n"
            . "Question 4: Calculus\nAnswer: Derivative of x squared\n\n"
            . "Question 5: Statistics\nAnswer: Mean is five\n\n"
            . "Question 6: Probability\nAnswer: One half\n\n"
        ));
        file_put_contents($fileTwo, $this->buildPdf(
            'sample_answer_sheet_3_science',
            "Student Name: Aarav Kumar\nRoll Number: 21\nSubject: Science\nTotal Marks: 30\n\n"
            . "Question 1: Photosynthesis\nAnswer: Glucose and oxygen\n\n"
            . "Question 2: Cell division\nAnswer: Mitosis\n\n"
            . "Question 3: Gravity\nAnswer: Nine point eight\n\n"
        ));

        $analysis = new Analysis();
        $analysis->setAnalysisId($analysisId);
        $analysis->setStudentId('agg-student-1');
        $analysis->setStatus('DRAFT');
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->setProcessing(new Processing());

        foreach ([['file-1', $fileOne, 'sample_answer_sheet_6_questions.pdf'],
                  ['file-2', $fileTwo, 'sample_answer_sheet_3_science.pdf']] as $i => [$fileId, $path, $name]) {
            $doc = new DocumentFile();
            $doc->setFileId($fileId);
            $doc->setStoredFilename($fileId . '.pdf');
            $doc->setOriginalFilename($name);
            $doc->setMimeType('application/pdf');
            $doc->setStatus('UPLOADED');
            $doc->setPage($i + 1);
            $analysis->getDocuments()->addAnswerSheet($doc);
        }

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($analysis);

        $storage = $this->createMock(AnalysisStorageResolver::class);
        $storage->method('getFilePathByFileId')->willReturnCallback(
            static fn(string $aid, string $fid): string => $fid === 'file-1' ? $fileOne : $fileTwo
        );

        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $storage,
            $logger,
            $this->createMock(MessageBusInterface::class),
            new DocumentTextExtractor(new NullLogger(), sys_get_temp_dir()),
            new AnswerSheetExtractor(new NullLogger()),
        );

        try {
            $service->runDirectExtraction($analysisId, 'agg-student-1');

            $exam = $analysis->getExam();
            $answers = $exam->getExtractedAnswers();

            fwrite(STDERR, "\n--- PREPARATION LOGS ---\n");
            foreach ($logger->lines as $line) {
                if (str_contains($line, 'preparing') || str_contains($line, 'extracting')
                    || str_contains($line, 'extracted') || str_contains($line, 'combined')) {
                    fwrite(STDERR, $line . "\n");
                }
            }
            fwrite(STDERR, '--- END --' . "\n");
            fwrite(STDERR, 'answerCount=' . var_export($exam->getAnswerCount(), true) . "\n");
            fwrite(STDERR, 'extractionResult=' . var_export($exam->getExtractionResult(), true) . "\n");
            foreach ($answers as $a) {
                fwrite(STDERR, sprintf(
                    "  Q%s file=%s page=%s text=%s\n",
                    $a['questionNumber'],
                    $a['source']['fileId'],
                    var_export($a['source']['page'], true),
                    substr($a['answerText'], 0, 22)
                ));
            }

            $byFile = [];
            foreach ($answers as $a) {
                $byFile[$a['source']['fileId']] = ($byFile[$a['source']['fileId']] ?? 0) + 1;
            }

            $this->assertSame(9, count($answers), 'Both files must contribute: 6 + 3');
            $this->assertSame(9, $exam->getAnswerCount());
            $this->assertSame(6, $byFile['file-1'] ?? 0);
            $this->assertSame(3, $byFile['file-2'] ?? 0);
            $this->assertSame('sample_answer_sheet_6_questions.pdf', $answers[0]['source']['filename']);
            $this->assertSame('sample_answer_sheet_3_science.pdf', $answers[6]['source']['filename']);
            $this->assertSame('READY_FOR_REVIEW', $analysis->getProcessing()->getStage());
            $this->assertSame('NEEDS_REVIEW', $analysis->getProcessing()->getStatus());
        } finally {
            @unlink($fileOne);
            @unlink($fileTwo);
            @rmdir($dir);
        }
    }

    /** Build a minimal single-stream PDF whose content stream holds $text. */
    private function buildPdf(string $title, string $text): string
    {
        $escaped = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', '\n'], $text);
        $stream = "BT /F1 11 Tf 40 750 Td ({$escaped}) Tj ET";
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>\nendobj\n",
            "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }

        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info << /Title ({$title}) >> >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}