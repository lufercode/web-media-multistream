<?php
ignore_user_abort(false);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';

$title = trim($_GET['title'] ?? $_POST['title'] ?? '');
$season = isset($_GET['season']) ? (int)$_GET['season'] : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;
$absolute = isset($_GET['absolute']) && $_GET['absolute'] !== '' ? (int)$_GET['absolute'] : null;
$is_anime = isset($_GET['is_anime']) ? (bool)$_GET['is_anime'] : true;
$original_title = trim($_GET['original_title'] ?? $_POST['original_title'] ?? '');
$tmdb_id = isset($_GET['tmdb_id']) ? (int)$_GET['tmdb_id'] : null;

if (empty($title)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Falta el parámetro title']);
    exit;
}

// Si se pasa TMDB ID, podemos corroborar si es animación si no se especificó is_anime
if ($tmdb_id && !isset($_GET['is_anime'])) {
    $item_data = get_tmdb_data("tv/{$tmdb_id}");
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

$cache_dir = defined('CACHE_DIR') ? CACHE_DIR : (dirname(__DIR__) . '/data/cache');
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

$cache_key = 'skip_' . md5(strtolower("{$title}_{$season}_{$episode}"));
$cache_file = "{$cache_dir}/{$cache_key}.json";

// Comprobar caché
if (file_exists($cache_file)) {
    $cached = @file_get_contents($cache_file);
    if ($cached) {
        $cached_data = json_decode($cached, true);
        $ttl = !empty($cached_data['found']) ? (86400 * 30) : 86400;
        if (time() - filemtime($cache_file) < $ttl) {
            echo $cached;
            exit;
        }
    }
}

// Si no es anime, devolvemos opciones estándar para series
if (!$is_anime) {
    $resp = [
        'status' => 'success',
        'is_anime' => false,
        'found' => false,
        'fallback_op_length' => 85, // Duración habitual de intro en series
        'op' => null,
        'ed' => null
    ];
    @file_put_contents($cache_file, json_encode($resp));
    echo json_encode($resp);
    exit;
}

// 1. Obtener ID de MyAnimeList a través de AniList GraphQL
$search_candidates = [];
if ($season > 1) {
    $search_candidates[] = "{$title} Season {$season}";
    $search_candidates[] = "{$title} {$season}";
}
$search_candidates[] = $title;
if (!empty($original_title) && $original_title !== $title) {
    if ($season > 1) {
        $search_candidates[] = "{$original_title} Season {$season}";
    }
    $search_candidates[] = $original_title;
}

$query = '
query ($search: String) {
  Page (page: 1, perPage: 3) {
    media (search: $search, type: ANIME, format_in: [TV, TV_SHORT, ONA]) {
      id
      idMal
      title {
        romaji
        english
      }
    }
  }
}';

$mal_id = null;
foreach ($search_candidates as $cand) {
    $ch = curl_init('https://graphql.anilist.co');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => ['search' => $cand]]),
        CURLOPT_TIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    $media_list = $json['data']['Page']['media'] ?? [];
    if (!empty($media_list[0]['idMal'])) {
        $mal_id = (int)$media_list[0]['idMal'];
        break;
    }
}

if (!$mal_id) {
    $resp = [
        'status' => 'not_found',
        'is_anime' => true,
        'found' => false,
        'fallback_op_length' => 90,
        'message' => 'Anime no localizado en base de datos externa'
    ];
    @file_put_contents($cache_file, json_encode($resp));
    echo json_encode($resp);
    exit;
}

// 2. Consultar AniSkip API
$skip_url = "https://api.aniskip.com/v2/skip-times/{$mal_id}/{$episode}?types=op&types=ed&episodeLength=0";
$ch2 = curl_init($skip_url);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_USERAGENT => 'Mozilla/5.0'
]);
$skip_res = curl_exec($ch2);
$http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

if ($http_code !== 200 || !$skip_res) {
    $resp = [
        'status' => 'no_skip_data',
        'is_anime' => true,
        'found' => false,
        'mal_id' => $mal_id,
        'fallback_op_length' => 90
    ];
    @file_put_contents($cache_file, json_encode($resp));
    echo json_encode($resp);
    exit;
}

$skip_json = json_decode($skip_res, true);
if ((empty($skip_json['found']) || empty($skip_json['results'])) && $absolute && $absolute !== $episode) {
    $skip_url_abs = "https://api.aniskip.com/v2/skip-times/{$mal_id}/{$absolute}?types=op&types=ed&episodeLength=0";
    $ch_abs = curl_init($skip_url_abs);
    curl_setopt_array($ch_abs, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $skip_res_abs = curl_exec($ch_abs);
    $http_code_abs = curl_getinfo($ch_abs, CURLINFO_HTTP_CODE);
    curl_close($ch_abs);
    if ($http_code_abs === 200 && $skip_res_abs) {
        $skip_json_abs = json_decode($skip_res_abs, true);
        if (!empty($skip_json_abs['found']) && !empty($skip_json_abs['results'])) {
            $skip_json = $skip_json_abs;
        }
    }
}

if (empty($skip_json['found']) || empty($skip_json['results'])) {
    $resp = [
        'status' => 'no_skip_data',
        'is_anime' => true,
        'found' => false,
        'mal_id' => $mal_id,
        'fallback_op_length' => 90
    ];
    @file_put_contents($cache_file, json_encode($resp));
    echo json_encode($resp);
    exit;
}

$op = null;
$ed = null;

foreach ($skip_json['results'] as $item) {
    $type = $item['skipType'] ?? '';
    $interval = $item['interval'] ?? [];
    if ($type === 'op' && isset($interval['startTime'], $interval['endTime'])) {
        $op = [
            'start' => round((float)$interval['startTime'], 2),
            'end' => round((float)$interval['endTime'], 2)
        ];
    } elseif ($type === 'ed' && isset($interval['startTime'], $interval['endTime'])) {
        $ed = [
            'start' => round((float)$interval['startTime'], 2),
            'end' => round((float)$interval['endTime'], 2)
        ];
    }
}

$final_res = [
    'status' => 'success',
    'is_anime' => true,
    'found' => ($op !== null || $ed !== null),
    'mal_id' => $mal_id,
    'op' => $op,
    'ed' => $ed,
    'fallback_op_length' => 90
];

@file_put_contents($cache_file, json_encode($final_res));
echo json_encode($final_res);
