<?php

echo "cwd: " . getcwd() . "\n";
echo "Fixture exists: " . (file_exists('tests/fixtures/analysis/answer-sheet.pdf') ? 'yes' : 'no') . "\n";
echo "Fixture realpath: " . realpath('tests/fixtures/analysis/answer-sheet.pdf') . "\n";
echo "Test file dir: " . __DIR__ . "\n";
echo "Fixture from test dir: " . __DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf' . "\n";
echo "Realpath from test dir: " . realpath(__DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf') . "\n";
echo "File exists from test dir: " . (file_exists(__DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf') ? 'yes' : 'no') . "\n";
echo "File exists from root: " . (file_exists('tests/fixtures/analysis/answer-sheet.pdf') ? 'yes' : 'no') . "\n";