<?php

echo "cwd: " . getcwd() . "\n";
echo "fixture exists: " . (file_exists('tests/fixtures/analysis/answer-sheet.pdf') ? 'yes' : 'no') . "\n";
echo "fixture realpath: " . realpath('tests/fixtures/analysis/answer-sheet.pdf') . "\n";
echo "test file dir: " . __DIR__ . "\n";
echo "fixture from test dir: " . __DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf' . "\n";
echo "realpath from test dir: " . realpath(__DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf') . "\n";
echo "file exists from test dir: " . (file_exists(__DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf') ? 'yes' : 'no') . "\n";