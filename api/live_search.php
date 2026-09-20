<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';

$query = trim($_GET['q'] ?? '');

if (mb_strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$cache_key = 'live_search_' . md5(mb_strtolower($query));
$cache_file = CACHE_DIR . '/' . $cache_key . '.json';

if (file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) {
    $cached = @file_get_contents($cache_file);
    if ($cached) {
        echo $cached;
        exit;
    }
}

$search_data = get_tmdb_data('search/multi', ['query' => $query]);
$raw_results = $search_data['results'] ?? [];

$results = [];
foreach ($raw_results as $item) {
    $media_type = $item['media_type'] ?? '';
    if ($media_type !== 'movie' && $media_type !== 'tv') {
        continue;
    }

    $title = $media_type === 'movie' ? ($item['title'] ?? '') : ($item['name'] ?? '');
    if (empty($title)) {
        continue;
    }

    $release_date = $item['release_date'] ?? $item['first_air_date'] ?? '';
    $year = !empty($release_date) ? substr($release_date, 0, 4) : '';
    $rating = isset($item['vote_average']) && $item['vote_average'] > 0
        ? number_format((float)$item['vote_average'], 1)
        : null;

    $poster = !empty($item['poster_path'])
        ? "https://image.tmdb.org/t/p/w185{$item['poster_path']}"
        : 'assets/img/no-poster.jpg';

    $results[] = [
        'id' => (int)$item['id'],
        'type' => $media_type,
        'title' => $title,
        'year' => $year,
        'rating' => $rating,
        'poster' => $poster,
        'url' => "details.php?id={$item['id']}&type={$media_type}"
    ];

    if (count($results) >= 8) {
        break;
    }
}

$response = ['results' => $results];
$json_encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!file_exists(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}
@file_put_contents($cache_file, $json_encoded);

echo $json_encoded;
