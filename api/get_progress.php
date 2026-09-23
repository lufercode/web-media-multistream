<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$content_id = $_GET['id'] ?? null;
$content_type = $_GET['type'] ?? null;

if (!$content_id || !$content_type) {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$progress = read_watched_progress();
$content_progress = $progress[$content_type][$content_id] ?? [];

if ($content_type === 'movie') {
    if (is_string($content_progress)) {
        $content_progress = ['status' => $content_progress];
    } elseif (is_array($content_progress) && isset($content_progress['status'])) {
        $content_progress = ['status' => $content_progress['status']];
    }
}

echo json_encode($content_progress);

