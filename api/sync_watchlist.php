<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$identity = get_current_identity();

$base_dir = defined('WATCHLIST_DIR') ? WATCHLIST_DIR : (defined('ROOT_DIR') ? ROOT_DIR . '/data/watchlist' : __DIR__ . '/../data/watchlist');
if (!is_dir($base_dir)) {
    @mkdir($base_dir, 0755, true);
}

$safe_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $identity['id']);
$prefix = $identity['type'] === 'user' ? 'user_' : 'device_';
$file = "{$base_dir}/{$prefix}{$safe_id}.json";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists($file)) {
        echo json_encode(['status' => 'success', 'items' => []]);
        exit;
    }
    $items = json_decode(file_get_contents($file), true) ?: [];
    echo json_encode(['status' => 'success', 'items' => $items]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? [];

    if (!is_array($items)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Formato inválido.']);
        exit;
    }

    file_put_contents($file, json_encode($items, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success', 'count' => count($items)]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
