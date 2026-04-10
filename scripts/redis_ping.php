<?php

require __DIR__ . '/../vendor/autoload.php';

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);

try {
    $client = new Predis\Client("tcp://{$host}:{$port}");
    echo $client->ping(), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

