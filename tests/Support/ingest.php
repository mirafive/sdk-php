<?php

declare(strict_types=1);

// A stand-in ingest for the transport tests, served by `php -S`.
$body = (string) file_get_contents('php://input');
$batch = json_decode($body, true);
$status = (int) ($_SERVER['HTTP_X_ANSWER'] ?? 202);

if ($status === 429) {
    header('Retry-After: 10');
}

http_response_code($status);
header('Content-Type: application/json');
echo json_encode([
    'accepted' => is_array($batch) && is_array($batch['events'] ?? null) ? count($batch['events']) : 0,
    'duplicates' => 0,
    'rejected' => [],
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
]);
