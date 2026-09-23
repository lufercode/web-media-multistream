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
$requested_absolute = isset($_GET['absolute']) && $_GET['absolute'] !== '' ? (int)$_GET['absolute'] : null;
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

// Determinar si el contenido es animación o anime según géneros de TMDB
$is_anime = false;
foreach ($item['genres'] ?? [] as $g) {
    if (($g['id'] ?? 0) === 16 || stripos($g['name'] ?? '', 'animaci') !== false || stripos($g['name'] ?? '', 'animation') !== false) {
        $is_anime = true;
        break;
    }
}

// Caso 1: Búsqueda atómica de un único proveedor en paralelo
if ($requested_provider !== null && $requested_provider !== '') {
    $provObj = $manager->getProvider($requested_provider);
    if ($type === 'movie') {
        $single_links = $manager->searchMovieSingleProvider($requested_provider, $title, $release_year, $tmdb_id, $original_title, $is_anime);
    } elseif ($requested_season !== null && $requested_episode !== null) {
        $single_links = $manager->searchSeriesSingleProvider($requested_provider, $title, $requested_season, $requested_episode, $tmdb_id, $original_title, $requested_absolute, $is_anime);
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
    // Comprobar si existen grupos de episodios canónicos (type 6 "Seasons")
    $use_episode_groups = false;
    $canonical_groups = get_tmdb_episode_groups_seasons($item['id']);
    $valid_groups = [];

    if ($canonical_groups && !empty($canonical_groups['groups'])) {
        foreach ($canonical_groups['groups'] as $g) {
            $name = strtolower($g['name'] ?? '');
            if (($g['order'] ?? 0) > 0 && strpos($name, 'special') === false && strpos($name, 'especial') === false) {
                $valid_groups[] = $g;
            }
        }
        usort($valid_groups, function($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        $default_regular_seasons = array_filter($item['seasons'] ?? [], function($s) {
            return ($s['season_number'] ?? 0) > 0;
        });

        if (count($valid_groups) > count($default_regular_seasons)) {
            $use_episode_groups = true;
        }
    }

    if ($use_episode_groups) {
        foreach ($valid_groups as $g) {
            $s_num = (int)($g['order'] ?? 1);
            $s_padded = str_pad((string)$s_num, 2, '0', STR_PAD_LEFT);
            $season_key = "T{$s_padded}";

            $episodes_data = $g['episodes'] ?? [];
            if (empty($episodes_data)) continue;

            usort($episodes_data, function($a, $b) {
                return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
            });

            $episode_list = [];
            foreach ($episodes_data as $ep) {
                $e_num = ($ep['order'] ?? 0) + 1;
                $e_padded = str_pad((string)$e_num, 2, '0', STR_PAD_LEFT);
                $episode_key = "E{$e_padded}";

                $abs_num = (int)($ep['episode_number'] ?? $e_num);

                $ep_name = trim($ep['name'] ?? '');
                if (empty($ep_name)) {
                    $ep_name = 'Episodio ' . $e_num;
                }

                $episode_list[$episode_key] = [
                    'name' => $ep_name,
                    'season_number' => $s_num,
                    'episode_number' => $e_num,
                    'absolute_number' => $abs_num,
                    'overview' => $ep['overview'] ?? '',
                    'air_date' => $ep['air_date'] ?? null,
                    'still_path' => !empty($ep['still_path']) ? "https://image.tmdb.org/t/p/w300{$ep['still_path']}" : null,
                    'runtime' => $ep['runtime'] ?? null,
                    'vote_average' => isset($ep['vote_average']) && $ep['vote_average'] > 0 ? round($ep['vote_average'], 1) : null
                ];
            }

            $all_links[$season_key] = [
                'season_number' => $s_num,
                'name' => !empty($g['name']) ? $g['name'] : 'Temporada ' . $s_num,
                'episode_count' => count($episode_list),
                'episodes' => $episode_list
            ];
        }
    } else {
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
                    'absolute_number' => $e_num,
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
        'is_anime' => $is_anime,
        'providers' => $manager->getEnabledProvidersList($is_anime),
        'links' => $all_links
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Caso 3: Búsqueda monolítica tradicional (compatibilidad hacia atrás)
if ($type === 'movie') {
    $all_links = $manager->searchMovie($title, $release_year, $tmdb_id, $original_title, $is_anime);
} elseif ($requested_season !== null && $requested_episode !== null) {
    $all_links = $manager->searchSeries($title, $requested_season, $requested_episode, $tmdb_id, $original_title, $requested_absolute, $is_anime);
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
