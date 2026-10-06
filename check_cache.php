<?php
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');
$kernel = new \App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$dm = $container->get('doctrine_mongodb.odm.default_document_manager');
$ocrRepo = $dm->getRepository('App\Document\OcrCache');
$cache = $ocrRepo->findOneBy(['cacheKey' => 'd8217bd268331694c579de018303028bd38814fedab8c9bcf6685970855746a4:2:VISION_OCR:llm:1.0']);
if ($cache) {
    $result = $cache->getResult();
    echo "Keys: " . implode(', ', array_keys($result ?? [])) . "\n";
    echo "Questions count: " . count($result['questions'] ?? []) . "\n";
    echo "Questions: " . json_encode($result['questions'] ?? [], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Cache entry not found\n";
}
