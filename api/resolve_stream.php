<?php
ignore_user_abort(false);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/resolvers/ResolverManager.php';

$url = $_GET['url'] ?? $_POST['url'] ?? null;

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'URL parameter is required']);
    exit;
}

$manager = new ResolverManager();
$result = $manager->resolve($url);

echo json_encode([
    'status' => 'success',
    'data' => $result
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

