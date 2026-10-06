<?php
$path = 'tests/fixtures/analysis/answer-sheet.pdf';
echo "Path: \$path" . PHP_EOL;
echo "File exists: " . (file_exists($path) ? 'YES' : 'NO') . PHP_EOL;
echo "Realpath: " . (realpath($path) ?: 'NOT FOUND') . PHP_EOL;
echo "CWD: " . getcwd() . PHP_EOL;
echo "File exists (absolute): " . (file_exists(__DIR__ . '/tests/fixtures/analysis/answer-sheet.pdf') ? 'YES' : 'NO') . PHP_EOL;