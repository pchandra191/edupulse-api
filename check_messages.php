<?php
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$conn = $kernel->getContainer()->get('doctrine.dbal.default_connection');
$msg = $conn->fetchAssociative("SELECT * FROM messenger_messages WHERE body LIKE '%ProcessAnalysisMessage%' ORDER BY id DESC LIMIT 5");
print_r($msg);