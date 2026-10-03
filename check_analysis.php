<?php
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$dm = $kernel->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
$repo = new \App\Repository\MongoDB\AnalysisRepository($dm);
$a = $repo->findByAnalysisId('01a0f3f6-23ef-7346-b209-ea4500f90d62');
if ($a) {
    echo 'Status: ' . $a->getStatus() . "\n";
    $p = $a->getProcessing();
    if ($p) {
        echo 'Stage: ' . $p->getStage() . "\n";
        echo 'Processing Status: ' . $p->getStatus() . "\n";
        echo 'Error: ' . ($p->getError() ?? 'none') . "\n";
    }
} else {
    echo 'Analysis not found';
}