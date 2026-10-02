<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';

$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();

echo "Project dir: " . $container->getParameter('kernel.project_dir') . "\n";
echo "Test file dir: " . __DIR__ . "\n";
echo "Fixture path: " . __DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf' . "\n";
echo "Realpath: " . realpath(__DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf') . "\n";
echo "File exists: " . (file_exists(__DIR__ . '/../../../../fixtures/analysis/answer-sheet.pdf') ? 'yes' : 'no') . "\n";