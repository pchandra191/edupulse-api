<?php
require_once 'vendor/autoload.php';

use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\DocumentTextExtractor;
use Psr\Log\NullLogger;

$textExtractor = new \App\Service\Analysis\Processing\DocumentTextExtractor(
    new \Psr\Log\NullLogger(),
    'F:\Projects\React\edupulse-api'
);

$result = $textExtractor->extractFromPdf('tests/fixtures/analysis/answer-sheet.pdf');
echo 'Has extractable text: ' . ($extractedData['hasExtractableText'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Page count: ' . $result['pageCount'] . PHP_EOL;
echo 'Text length: ' . strlen($result['text']) . PHP_EOL;

$extractedData = $textExtractor->extractFromPdf('tests/fixtures/analysis/answer-sheet.pdf');
echo 'Has extractable text: ' . ($extractedData['hasExtractableText'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Page count: ' . $extractedData['pageCount'] . PHP_EOL;

$extractor = new \App\Service\Analysis\Processing\AnswerSheetExtractor(new \Psr\Log\NullLogger());
$result = $extractor->extractFromAnswerSheet($extractedData['pages']);

echo 'Extraction result: ' . $result['extractionResult'] . PHP_EOL;
echo 'Answers found: ' . count($result['answers']) . PHP_EOL;

$metadata = $result['metadata'];
echo 'Student name: ' . ($metadata->studentName?->value ?? 'NULL') . PHP_EOL;
echo 'Roll number: ' . ($metadata->rollNumber?->value ?? 'NULL') . PHP_EOL;
echo 'Subject: ' . ($metadata->subject?->value ?? 'NULL') . PHP_EOL;
echo 'Exam title: ' . ($result['metadata']->examTitle?->value ?? 'NULL') . PHP_EOL;
echo 'Total marks: ' . ($result['metadata']->totalMarks?->value ?? 'NULL') . PHP_EOL;
echo 'Answers count: ' . count($result['answers']) . PHP_EOL;