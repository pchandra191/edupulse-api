<?php
require 'vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');
$dotenv->load(__DIR__ . '/.env.local');

$raw = getenv('PDF_RENDERER_BINARY');
if ($raw === false) {
    $raw = $_ENV['PDF_RENDERER_BINARY'] ?? $_SERVER['PDF_RENDERER_BINARY'] ?? 'NOT SET';
}
echo "Dotenv loaded value: " . var_export($raw, true) . "\n";
echo "file_exists: " . (file_exists($raw) ? 'true' : 'false') . "\n";

if ($raw !== 'NOT SET' && $raw !== false && file_exists($raw)) {
    echo "Binary path is valid!\n";
} else {
    echo "Binary path is NOT valid!\n";
    echo "  raw value length: " . strlen($raw) . "\n";
    if ($raw !== 'NOT SET' && $raw !== false) {
        echo "  binary path repr: " . bin2hex($raw) . "\n";
    }
}
