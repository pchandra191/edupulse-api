<?php
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
use App\Message\Analysis\ProcessAnalysisMessage;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$appEnv = $_ENV['APP_ENV'] ?? 'dev';
$appDebug = $_ENV['APP_DEBUG'] ?? '1';

$kernel = new \App\Kernel($appEnv, (bool) $appDebug);
$kernel->boot();
$container = $kernel->getContainer();
$dm = $container->get('doctrine_mongodb.odm.default_document_manager');

// Reset analysis status
$analysisRepo = $dm->getRepository('App\Document\Analysis');
$analysisId = '01a0e970-a37c-7ef7-8a61-30e7136cdb93';
$analysis = $analysisRepo->findOneBy(['analysisId' => $analysisId]);

if ($analysis) {
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
    echo "Analysis status reset to PUBLISHED\n";
}

// Clear OCR cache entries
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
echo "OCR cache cleared: {$count} entries removed\n";

$bus = $container->get('messenger.default_bus');
$bus->dispatch(new ProcessAnalysisMessage($analysisId, '01a0c5d3-b8d6-7452-bb78-fcd99b386fc1'));
echo "Message dispatched for reprocessing\n";
