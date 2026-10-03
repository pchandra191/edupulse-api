<?php
require_once 'vendor/autoload.php';
use Smalot\PdfParser\Parser;

$parser = new \Smalot\PdfParser\Parser();
$pdf = $parser->parseFile('tests/fixtures/analysis/answer-sheet.pdf');
$pages = $pdf->getPages();
$fullText = '';
foreach ($pages as $i => $page) {
    $fullText .= "\n--- PAGE " . ($i+1) . " ---\n" . $page->getText();
}

// Check the exact bytes around "Examination"
$pos = stripos($fullText, 'Examination');
if ($pos !== false) {
    $context = substr($fullText, $pos - 10, 50);
    echo "Context: " . json_encode($context) . PHP_EOL;
    echo "Hex: " . bin2hex(substr($fullText, $pos, 30)) . PHP_EOL;
    
    // Check each character
    $substr = substr($fullText, $pos, 30);
    for ($i = 0; $i < strlen($context); $i++) {
        $char = $context[$i];
        $byte = bin2hex($char);
        echo "Pos $i: char='$char' byte=" . bin2hex($char) . PHP_EOL;
    }
}

// Check the exact bytes around "Examination"
$pos = stripos($fullText, 'Examination');
if ($pos !== false) {
    $context = substr($fullText, $pos - 10, 50);
    echo "Context: " . json_encode($context) . PHP_EOL;
    
    // Check each character
    $snippet = substr($fullText, $pos, 30);
    for ($i = 0; $i < strlen($context); $i++) {
        $char = $context[$i];
        echo "Char $i: '" . $char . "' = " . bin2hex($char) . PHP_EOL;
    }
}