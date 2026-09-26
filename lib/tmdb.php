<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Realiza una consulta directa a la API de TMDB con caché local y soporte cURL.
 */
function get_tmdb_data(string $endpoint, array $params = []): array {
    $params['api_key'] = TMDB_API_KEY;
    if (!isset($params['language'])) {
        $params['language'] = TMDB_LANG;
    }
    $query_string = http_build_query($params);
    $url = "https://api.themoviedb.org/3/{$endpoint}?{$query_string}";

    $cache_key = md5($url);
    $cache_file = CACHE_DIR . '/' . $cache_key . '.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file) < CACHE_TIME)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $timeout = defined('MAX_TIMEOUT') ? MAX_TIMEOUT : 4;
    $output = false;
    $status_code = 0;

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $output = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        $output = @file_get_contents($url, false, $context);
        if (!empty($http_response_header[0])) {
            sscanf($http_response_header[0], 'HTTP/%*d.%*d %d', $status_code);
        }
    }

    if ($output === false || $status_code !== 200) {
        return [];
    }

    $data = json_decode($output, true);
    if (is_array($data)) {
        if (!file_exists(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        @file_put_contents($cache_file, json_encode($data));
    }

    return is_array($data) ? $data : [];
}

/**
 * Obtiene los detalles de una temporada enriquecidos con fallback multi-idioma (es-MX -> es-ES -> en-US).
 * Garantiza que los episodios no queden con nombres genéricos ("Episodio X") o sinopsis vacías si existen en otros idiomas.
 */
function get_tmdb_season_details(int|string $tv_id, int $season_num): array {
    $cache_file = CACHE_DIR . "/season_enriched_{$tv_id}_{$season_num}.json";

    // Verificar si existe caché enriquecida válida
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < CACHE_TIME)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached) && !empty($cached['episodes'])) {
            // Comprobar si la caché previa contiene nombres genéricos residuales que deban enriquecerse
            $needs_refresh = false;
            foreach ($cached['episodes'] as $cep) {
                $cn = trim($cep['name'] ?? '');
                if (empty($cn) || preg_match('/^(?:episodio|episode|cap[ií]tulo)\s*\d+$/iu', $cn)) {
                    $needs_refresh = true;
                    break;
                }
            }
            if (!$needs_refresh) {
                return $cached;
            }
        }
    }

    // 1. Obtener datos en idioma principal (es-MX)
    $data_mx = get_tmdb_data("tv/{$tv_id}/season/{$season_num}", ['language' => 'es-MX']);
    $episodes = $data_mx['episodes'] ?? [];

    // Si no se encontraron episodios en es-MX, probar directamente es-ES
    if (empty($episodes)) {
        $data_es = get_tmdb_data("tv/{$tv_id}/season/{$season_num}", ['language' => 'es-ES']);
        $episodes = $data_es['episodes'] ?? [];
        if (!empty($episodes)) {
            $data_mx = $data_es;
        } else {
            // Probar en-US como último recurso
            $data_en = get_tmdb_data("tv/{$tv_id}/season/{$season_num}", ['language' => 'en-US']);
            $episodes = $data_en['episodes'] ?? [];
            if (!empty($episodes)) {
                $data_mx = $data_en;
            }
        }
    }

    if (empty($episodes)) {
        return $data_mx;
    }

    // Comprobar si algún episodio tiene título genérico, sinopsis vacía o sin miniatura
    $needs_es_fallback = false;
    foreach ($episodes as $ep) {
        $name = trim($ep['name'] ?? '');
        $overview = trim($ep['overview'] ?? '');
        $still = $ep['still_path'] ?? null;

        if (empty($name) || preg_match('/^(?:episodio|episode|cap[ií]tulo)\s*\d+$/iu', $name) || empty($overview) || empty($still)) {
            $needs_es_fallback = true;
            break;
        }
    }

    if ($needs_es_fallback) {
        // Fallback 1: es-ES (Español de España)
        $data_es = get_tmdb_data("tv/{$tv_id}/season/{$season_num}", ['language' => 'es-ES']);
        $eps_es = [];
        if (!empty($data_es['episodes']) && is_array($data_es['episodes'])) {
            foreach ($data_es['episodes'] as $ep_item) {
                $eps_es[$ep_item['episode_number']] = $ep_item;
            }
        }

        $still_pending_en = false;
        foreach ($episodes as &$ep) {
            $eNum = $ep['episode_number'];
            $match_es = $eps_es[$eNum] ?? null;

            $name = trim($ep['name'] ?? '');
            if (empty($name) || preg_match('/^(?:episodio|episode|cap[ií]tulo)\s*\d+$/iu', $name)) {
                $es_name = trim($match_es['name'] ?? '');
                if (!empty($es_name) && !preg_match('/^(?:episodio|episode|cap[ií]tulo)\s*\d+$/iu', $es_name)) {
                    $ep['name'] = $es_name;
                } else {
                    $still_pending_en = true;
                }
            }

            if (empty(trim($ep['overview'] ?? ''))) {
                $es_over = trim($match_es['overview'] ?? '');
                if (!empty($es_over)) {
                    $ep['overview'] = $es_over;
                } else {
                    $still_pending_en = true;
                }
            }

            if (empty($ep['still_path']) && !empty($match_es['still_path'])) {
                $ep['still_path'] = $match_es['still_path'];
            }
        }
        unset($ep);

        // Fallback 2: en-US (Inglés / Título original de producción)
        if ($still_pending_en) {
            $data_en = get_tmdb_data("tv/{$tv_id}/season/{$season_num}", ['language' => 'en-US']);
            $eps_en = [];
            if (!empty($data_en['episodes']) && is_array($data_en['episodes'])) {
                foreach ($data_en['episodes'] as $ep_item) {
                    $eps_en[$ep_item['episode_number']] = $ep_item;
                }
            }

            foreach ($episodes as &$ep) {
                $eNum = $ep['episode_number'];
                $match_en = $eps_en[$eNum] ?? null;

                $name = trim($ep['name'] ?? '');
                if (empty($name) || preg_match('/^(?:episodio|episode|cap[ií]tulo)\s*\d+$/iu', $name)) {
                    $en_name = trim($match_en['name'] ?? '');
                    if (!empty($en_name)) {
                        $ep['name'] = $en_name;
                    }
                }

                if (empty(trim($ep['overview'] ?? ''))) {
                    $en_over = trim($match_en['overview'] ?? '');
                    if (!empty($en_over)) {
                        $ep['overview'] = $en_over;
                    }
                }

                if (empty($ep['still_path']) && !empty($match_en['still_path'])) {
                    $ep['still_path'] = $match_en['still_path'];
                }
            }
            unset($ep);
        }
    }

    // Asegurar que ningún episodio quede con nombre vacío
    foreach ($episodes as &$ep) {
        if (empty(trim($ep['name'] ?? ''))) {
            $ep['name'] = 'Episodio ' . $ep['episode_number'];
        }
    }
    unset($ep);

    $data_mx['episodes'] = $episodes;

    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    @file_put_contents($cache_file, json_encode($data_mx));

    return $data_mx;
}

/**
 * Obtiene el grupo de episodios de temporadas canónicas de TMDB (type = 6),
 * resolviendo la anomalía de animes donde TMDB agrupa múltiples temporadas en una sola por emisión japonesa.
 */
function get_tmdb_episode_groups_seasons(int|string $tv_id): ?array {
    $cache_file = CACHE_DIR . "/eg_seasons_{$tv_id}.json";
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < CACHE_TIME)) {
        $cached = json_decode((string)@file_get_contents($cache_file), true);
        if (is_array($cached) && !empty($cached['groups'])) {
            return $cached;
        }
    }

    $groups_data = get_tmdb_data("tv/{$tv_id}/episode_groups");
    $results = $groups_data['results'] ?? [];
    if (empty($results)) {
        return null;
    }

    $season_group_id = null;
    foreach ($results as $g) {
        if (($g['type'] ?? 0) == 6) {
            $season_group_id = $g['id'];
            break;
        }
    }

    if (!$season_group_id) {
        foreach ($results as $g) {
            $name = strtolower($g['name'] ?? '');
            if (strpos($name, 'season') !== false || strpos($name, 'temporada') !== false) {
                $season_group_id = $g['id'];
                break;
            }
        }
    }

    if (!$season_group_id) {
        return null;
    }

    $group_details = get_tmdb_data("tv/episode_group/{$season_group_id}");
    if (empty($group_details['groups'])) {
        return null;
    }

    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    @file_put_contents($cache_file, json_encode($group_details));

    return $group_details;
}

/**
 * Obtiene el IMDb ID (ej: tt9679542) para una película o serie desde TMDB external_ids.
 */
function get_tmdb_imdb_id(int|string $tmdb_id, string $type = 'tv'): ?string {
    $endpoint_type = ($type === 'movie') ? 'movie' : 'tv';
    $ext = get_tmdb_data("{$endpoint_type}/{$tmdb_id}/external_ids");
    $imdb = trim((string)($ext['imdb_id'] ?? ''));
    if (!empty($imdb) && strpos($imdb, 'tt') === 0) {
        return $imdb;
    }
    return null;
}

/**
 * Obtiene el nombre canónico de una temporada o arco de anime desde TMDB (en inglés/internacional),
 * útil para resolver subtítulos de temporadas como "Stone Wars", "New World", "Science Future", etc.
 */
function get_tmdb_anime_season_name(int|string $tmdb_id, int $season_num): ?string {
    if ($season_num <= 0) return null;

    // 1. Consultar metadatos de la serie en inglés (donde TMDB suele guardar el nombre internacional del arco)
    $data_en = get_tmdb_data("tv/{$tmdb_id}", ['language' => 'en-US']);
    foreach ($data_en['seasons'] ?? [] as $s) {
        if ((int)($s['season_number'] ?? -1) === $season_num) {
            $name = trim((string)($s['name'] ?? ''));
            if (!empty($name) && !preg_match('/^(?:season|temporada|specials|especiales)\s*\d*$/iu', $name)) {
                return $name;
            }
        }
    }

    // 2. Fallback: consultar en español
    $data_es = get_tmdb_data("tv/{$tmdb_id}");
    foreach ($data_es['seasons'] ?? [] as $s) {
        if ((int)($s['season_number'] ?? -1) === $season_num) {
            $name = trim((string)($s['name'] ?? ''));
            if (!empty($name) && !preg_match('/^(?:season|temporada|specials|especiales)\s*\d*$/iu', $name)) {
                return $name;
            }
        }
    }

    return null;
}


