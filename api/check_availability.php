<?php
ignore_user_abort(false);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/ProviderManager.php';

$query = $_POST['query'] ?? $_GET['query'] ?? null;
$type = $_POST['type'] ?? $_GET['type'] ?? 'movie';
$year = $_POST['year'] ?? $_GET['year'] ?? null;
$original_title = $_POST['original_title'] ?? $_GET['original_title'] ?? null;
$tmdb_id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

if (!$query) {
    http_response_code(400);
    echo json_encode(['error' => 'Query parameter is required']);
    exit;
}

$cache_key = 'avail_' . md5($type . '_' . $query . '_' . ($year ?? '') . '_' . ($tmdb_id ?? ''));
$cache_file = CACHE_DIR . '/' . $cache_key . '.json';

if (file_exists($cache_file) && (time() - filemtime($cache_file) < AVAILABILITY_CACHE_TIME)) {
    $cached = @file_get_contents($cache_file);
    if ($cached) {
        echo $cached;
        exit;
    }
}

$manager = new ProviderManager();
$is_anime = true;
if ($tmdb_id) {
    $item_data = get_tmdb_data("{$type}/{$tmdb_id}");
    if (!empty($item_data['genres'])) {
        $is_anime = false;
        foreach ($item_data['genres'] as $g) {
            if (($g['id'] ?? 0) === 16 || stripos($g['name'] ?? '', 'animaci') !== false || stripos($g['name'] ?? '', 'animation') !== false) {
                $is_anime = true;
                break;
            }
        }
    }
}
$available = $manager->checkAvailability($query, $type, $year, $tmdb_id, $original_title, $is_anime);

$response = [
    'status' => $available ? 'found' : 'not_found',
    'query' => $query,
    'type' => $type
];

$json = json_encode($response);
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}
@file_put_contents($cache_file, $json);

echo $json;

