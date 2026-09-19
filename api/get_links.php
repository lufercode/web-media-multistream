<?php
ignore_user_abort(false);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/ProviderManager.php';

$action = $_GET['action'] ?? null;
$manager = new ProviderManager();

// Endpoint rápido para obtener lista de proveedores habilitados
if ($action === 'providers') {
    echo json_encode([
        'status' => 'success',
        'providers' => $manager->getEnabledProvidersList()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$id = $_GET['id'] ?? null;
$type = $_GET['type'] ?? null;

if (!$id || !$type) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan parámetros id o type']);
    exit;
}

$item = get_tmdb_data("{$type}/{$id}");
if (!$item) {
    http_response_code(404);
    echo json_encode(['error' => 'No se encontraron datos en TMDB']);
    exit;
}

$title = $item['title'] ?? $item['name'] ?? null;
$original_title = $item['original_title'] ?? $item['original_name'] ?? null;
$requested_season = isset($_GET['season']) ? (int)$_GET['season'] : null;
$requested_episode = isset($_GET['episode']) ? (int)$_GET['episode'] : null;
$release_year = null;
if (!empty($item['release_date'])) {
    $release_year = substr($item['release_date'], 0, 4);
} elseif (!empty($item['first_air_date'])) {
    $release_year = substr($item['first_air_date'], 0, 4);
}

if (!$title) {
    http_response_code(404);
    echo json_encode(['error' => 'No se pudo determinar el título del contenido']);
    exit;
}

$tmdb_id = (int)$item['id'];
$metadata_only = isset($_GET['metadata_only']) && $_GET['metadata_only'] === '1';
$requested_provider = isset($_GET['provider']) ? trim($_GET['provider']) : null;

// Caso 1: Búsqueda atómica de un único proveedor en paralelo
if ($requested_provider !== null && $requested_provider !== '') {
    $provObj = $manager->getProvider($requested_provider);
    if ($type === 'movie') {
        $single_links = $manager->searchMovieSingleProvider($requested_provider, $title, $release_year, $tmdb_id, $original_title);
    } elseif ($requested_season !== null && $requested_episode !== null) {
        $single_links = $manager->searchSeriesSingleProvider($requested_provider, $title, $requested_season, $requested_episode, $tmdb_id, $original_title);
    } else {
        $single_links = ['direct' => [], 'streaming' => [], 'torrent' => []];
    }

    echo json_encode([
        'status' => 'success',
        'provider' => $requested_provider,
        'provider_name' => $provObj ? $provObj->getName() : $requested_provider,
        'provider_type' => $provObj ? $provObj->getType() : 'unknown',
        'links' => $single_links
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Para series sin episodio específico, construir siempre la estructura de episodios
$all_links = [];
if ($type === 'tv' && ($requested_season === null || $requested_episode === null)) {
    $seasons = $item['seasons'] ?? [];
    foreach ($seasons as $season_info) {
        $s_num = $season_info['season_number'] ?? null;
        if ($s_num === null || $s_num == 0) continue;

        $s_padded = str_pad((string)$s_num, 2, '0', STR_PAD_LEFT);
        $season_key = "T{$s_padded}";

        $season_details = get_tmdb_season_details($item['id'], $s_num);
        $episodes_data = $season_details['episodes'] ?? [];
        $episode_list = [];

        foreach ($episodes_data as $ep) {
            $e_num = $ep['episode_number'];
            $e_padded = str_pad((string)$e_num, 2, '0', STR_PAD_LEFT);
            $episode_key = "E{$e_padded}";

            $ep_name = trim($ep['name'] ?? '');
            if (empty($ep_name)) {
                $ep_name = 'Episodio ' . $e_num;
            }

            $episode_list[$episode_key] = [
                'name' => $ep_name,
                'season_number' => $s_num,
                'episode_number' => $e_num,
                'overview' => $ep['overview'] ?? '',
                'air_date' => $ep['air_date'] ?? null,
                'still_path' => !empty($ep['still_path']) ? "https://image.tmdb.org/t/p/w300{$ep['still_path']}" : null,
                'runtime' => $ep['runtime'] ?? null,
                'vote_average' => isset($ep['vote_average']) && $ep['vote_average'] > 0 ? round($ep['vote_average'], 1) : null
            ];
        }

        $all_links[$season_key] = [
            'season_number' => $s_num,
            'name' => $season_info['name'] ?? 'Temporada ' . $s_num,
            'episode_count' => count($episode_list),
            'episodes' => $episode_list
        ];
    }
}

// Caso 2: Solicitud de solo metadatos (para renderizar de inmediato y disparar búsquedas paralelas)
if ($metadata_only) {
    echo json_encode([
        'status' => 'success',
        'id' => $id,
        'type' => $type,
        'title' => $title,
        'original_title' => $original_title,
        'year' => $release_year,
        'backdrop' => !empty($item['backdrop_path']) ? "https://image.tmdb.org/t/p/w780{$item['backdrop_path']}" : null,
        'poster' => !empty($item['poster_path']) ? "https://image.tmdb.org/t/p/w500{$item['poster_path']}" : null,
        'overview' => $item['overview'] ?? '',
        'runtime' => $item['runtime'] ?? (!empty($item['episode_run_time']) ? $item['episode_run_time'][0] : null),
        'providers' => $manager->getEnabledProvidersList(),
        'links' => $all_links
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Caso 3: Búsqueda monolítica tradicional (compatibilidad hacia atrás)
if ($type === 'movie') {
    $all_links = $manager->searchMovie($title, $release_year, $tmdb_id, $original_title);
} elseif ($requested_season !== null && $requested_episode !== null) {
    $all_links = $manager->searchSeries($title, $requested_season, $requested_episode, $tmdb_id, $original_title);
}

echo json_encode([
    'status' => 'success',
    'id' => $id,
    'type' => $type,
    'title' => $title,
    'year' => $release_year,
    'backdrop' => !empty($item['backdrop_path']) ? "https://image.tmdb.org/t/p/w780{$item['backdrop_path']}" : null,
    'poster' => !empty($item['poster_path']) ? "https://image.tmdb.org/t/p/w500{$item['poster_path']}" : null,
    'overview' => $item['overview'] ?? '',
    'runtime' => $item['runtime'] ?? (!empty($item['episode_run_time']) ? $item['episode_run_time'][0] : null),
    'links' => $all_links
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
