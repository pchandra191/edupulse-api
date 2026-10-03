<?php
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$appEnv = $_ENV['APP_ENV'] ?? 'dev';
$appDebug = $_ENV['APP_DEBUG'] ?? '1';

// Force clear all caches
@unlink(__DIR__ . '/var/cache/dev/App_KernelDevDebugContainer.ser');
@unlink(__DIR__ . '/var/cache/dev/App_KernelDevDebugContainer.php');

$kernel = new \App\Kernel($appEnv, (bool) $appDebug);
$kernel->boot();
$container = $kernel->getContainer();

// Get the AnalysisProcessingService
$processingService = $container->get('App\Service\Analysis\AnalysisProcessingService');

// Get the MongoDB analysis
$dm = $container->get('doctrine_mongodb.odm.default_document_manager');
$analysisRepo = new \App\Repository\MongoDB\AnalysisRepository($dm);
$analysis = $analysisRepo->findByAnalysisId('01a0e970-a37c-7ef7-8a61-30e7136cdb93');

if ($analysis) {
    // Reset status
    $analysis->setStatus('PUBLISHED');
    $processing = $analysis->getProcessing();
    if ($processing) {
        $processing->setStatus('PUBLISHED');
        $processing->setStage('FAILED');
        $processing->setError(null);
        $processing->setRetryCount(0);
    }
    $dm->persist($analysis);
    $dm->flush();
    echo "Analysis reset\n";
}

// Clear OCR cache
$fileHash = 'd8217bd268331694c579de018303028bd38814fedab8c9bcf6685970855746a4';
$ocrRepo = $dm->getRepository('App\Document\OcrCache');
$count = 0;
foreach ($ocrRepo->findAll() as $cache) {
    if (str_starts_with($cache->getCacheKey(), $fileHash)) {
        $dm->remove($cache);
        $count++;
    }
}
$dm->flush();
echo "OCR cache cleared: {$count} entries\n";

echo "Starting direct processing...\n";
$processingService->processAnalysis('01a0e970-a37c-7ef7-8a61-30e7136cdb93', '01a0c5d3-b8d6-7452-bb78-fcd99b386fc1');
echo "Processing complete\n";
