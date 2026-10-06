<?php
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$bus = $kernel->getContainer()->get('messenger.default_bus');
$message = new \App\Message\Analysis\ProcessAnalysisMessage('01a0f3f6-23ef-7346-b209-ea4500f90d62', '01a08b37-b4c5-7f83-afaf-46603192bb7f');
$bus->dispatch($message);
echo 'Message dispatched' . PHP_EOL;