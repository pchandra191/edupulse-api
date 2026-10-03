<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\Response\MockResponse;

$r = new MockResponse('{"error":{"type":"insufficient_quota","message":"quota exceeded"}}', ['http_code' => 429]);

try {
    $status = $r->getStatusCode();
    echo "Status: " . $status . "\n";
} catch (\Throwable $e) {
    echo "getStatusCode throws: " . get_class($e) . " - " . $e->getMessage() . "\n";
}

try {
    $content = $r->getContent(false);
    echo "Content(false): " . $content . "\n";
} catch (\Throwable $e) {
    echo "getContent(false) throws: " . get_class($e) . " - " . $e->getMessage() . "\n";
}

try {
    $content = $r->getContent(true);
    echo "Content(true): " . $content . "\n";
} catch (\Throwable $e) {
    echo "getContent(true) throws: " . get_class($e) . " - " . $e->getMessage() . "\n";
}
