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
foreach ($ocrRepo->findAll() as $doc) {
    echo $doc->getCacheKey() . ' | ' . $doc->getStatus() . ' | ' . ($doc->getResult() ? 'has-result' : 'no-result') . "\n";
}
