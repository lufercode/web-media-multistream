<?php
/**
 * Verifica si un dominio base (CDN) es accesible en la red.
 */
function is_domain_accessible(string $url): bool {
    if (connection_aborted()) {
        exit;
    }

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        return ($error === 0 && $http_code > 0);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'ignore_errors' => true,
            'timeout' => 2,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);
    $headers = @get_headers($url, 1, $context);
    return !empty($headers);
}

/**
 * Retorna los dominios base configurados que están actualmente accesibles.
 * Guarda en caché el resultado durante 60 segundos para no repetir pings innecesarios.
 */
function check_base_domains_accessible(): array {
    global $BASE_DOMAINS;
    if (empty($BASE_DOMAINS)) {
        return [];
    }

    $cache_file = defined('CACHE_DIR') ? CACHE_DIR . '/base_domains_health.json' : sys_get_temp_dir() . '/base_domains_health.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file) < 60)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $accessible = [];
    foreach ($BASE_DOMAINS as $domain) {
        if (is_domain_accessible($domain)) {
            $accessible[] = $domain;
        }
    }

    if (defined('CACHE_DIR') && !file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    @file_put_contents($cache_file, json_encode($accessible));

    return $accessible;
}

function url_exists(string $url): bool {
    if (connection_aborted()) {
        exit;
    }

    $timeout = defined('MAX_TIMEOUT') ? MAX_TIMEOUT : 2;

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($http_code === 200);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $headers = @get_headers($url, 1, $context);
    return $headers && strpos($headers[0], ' 200 ') !== false;
}

/**
 * Verifica múltiples URLs en paralelo usando curl_multi (mucho más rápido que verificarlas en serie).
 * Retorna un array con las URLs que respondieron con HTTP 200.
 */
function urls_exist_multi(array $urls, int $timeout = 2): array {
    if (empty($urls)) return [];

    if (extension_loaded('curl')) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_multi_add_handle($mh, $ch);
            $handles[$url] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running && $status === CURLM_OK);

        $found = [];
        foreach ($handles as $url => $ch) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($http_code === 200) {
                $found[] = $url;
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $found;
    }

    // Fallback secuencial si cURL no está disponible
    $found = [];
    foreach ($urls as $url) {
        if (url_exists($url)) {
            $found[] = $url;
        }
    }
    return $found;
}

/**
 * Normaliza un título para comparación eliminando años, corchetes, calidades, idiomas y temporadas.
 */
function clean_title_for_comparison(string $title): string {
    $title = mb_strtolower($title, 'UTF-8');
    
    // Eliminar años entre paréntesis o corchetes: (1999), [2023]
    $title = preg_replace('/[\(\[]\s*(?:19|20)\d{2}\s*[\)\]]/', ' ', $title);
    
    // Eliminar todo el contenido entre corchetes [ ... ]
    $title = preg_replace('/\[.*?\]/', ' ', $title);
    
    // Eliminar palabras de ruido técnico (calidades, idiomas, formatos)
    $noise_words = [
        '1080p', '720p', '480p', '2160p', '4k', 'uhd', 'hd', 'fullhd', 'full hd',
        'bluray', 'bdrip', 'dvdrip', 'web-dl', 'webrip', 'hdtv', 'microhd', 'cam', 'screener',
        'latino', 'castellano', 'espanol', 'español', 'subtitulado', 'sub', 'subs', 'vose', 'vost',
        'dual', 'ingles', 'audio', 'multi', 'temporada', 'temp'
    ];
    foreach ($noise_words as $w) {
        $title = preg_replace('/\b' . preg_quote($w, '/') . '\b/iu', ' ', $title);
    }

    // Transliterar acentos
    $title = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;
    
    // Normalizar conjunciones (& -> y)
    $title = str_replace('&', 'y', $title);
    
    // Conservar solo alfanuméricos
    $title = preg_replace('/[^a-z0-9]/', '', $title);
    return trim($title);
}

/**
 * Normaliza y compara títulos permitiendo variaciones comunes (añadidos de año, calidad, idioma)
 * y evitando falsos negativos o secuelas no deseadas.
 */
function is_strict_title_match(string $query_title, string $candidate_title, ?string $query_year = null, ?string $candidate_year = null): bool
{
    // 1. Comprobar años si ambos están presentes y son válidos
    if ($query_year && $candidate_year) {
        $y_q = (int)preg_replace('/\D/', '', $query_year);
        $y_c = (int)preg_replace('/\D/', '', $candidate_year);
        if ($y_q > 1900 && $y_c > 1900 && abs($y_q - $y_c) > 1) {
            return false;
        }
    }

    // 2. Detección estricta de secuelas (números, romanos, palabras numéricas, capítulos/partes)
    $get_sequel_tokens = function(string $str): array {
        $str = mb_strtolower($str, 'UTF-8');
        $str = preg_replace('/[\(\[]\s*(?:19|20)\d{2}\s*[\)\]]/', ' ', $str);
        $tokens = [];
        if (preg_match_all('/\b([2-9]|10)\b/i', $str, $m)) {
            $tokens = array_merge($tokens, $m[1]);
        }
        if (preg_match_all('/\b(ii|iii|iv|v|vi|vii|viii|ix|x)\b/i', $str, $m)) {
            $tokens = array_merge($tokens, $m[1]);
        }
        if (preg_match_all('/\b(dos|tres|cuatro|cinco|seis|two|three|four|five|six)\b/i', $str, $m)) {
            $tokens = array_merge($tokens, $m[1]);
        }
        if (preg_match_all('/\b(capitulo|capítulo|chapter|parte|part)\b/iu', $str, $m)) {
            $tokens = array_merge($tokens, $m[1]);
        }
        return array_unique($tokens);
    };

    $q_tokens = $get_sequel_tokens($query_title);
    $c_tokens = $get_sequel_tokens($candidate_title);

    // Si el candidato tiene indicadores de secuela que la búsqueda NO tiene -> rechazar
    foreach ($c_tokens as $t) {
        if (!in_array($t, $q_tokens)) {
            return false;
        }
    }

    // Si la búsqueda tiene indicadores de secuela que el candidato NO tiene -> rechazar
    foreach ($q_tokens as $t) {
        if (!in_array($t, $c_tokens)) {
            return false;
        }
    }

    $clean_query = clean_title_for_comparison($query_title);
    $clean_cand = clean_title_for_comparison($candidate_title);

    if (empty($clean_query) || empty($clean_cand)) {
        return false;
    }

    // 3. Coincidencia exacta tras limpieza
    if ($clean_query === $clean_cand) {
        return true;
    }

    $allowed_noise = [
        'subs', 'sub', 'integrados', 'integrado', 'vose', 'vost',
        'extended', 'extendida', 'unrated', 'directors', 'director', 'cut',
        'remastered', 'remasterizada', 'imax', 'version', 'edicion', 'edition',
        'pelicula', 'movie'
    ];

    // 4. Si el candidato empieza por la búsqueda (ej: "Insidious 1080p BluRay" o "Insidious: The Red Door")
    if (strpos($clean_cand, $clean_query) === 0) {
        $extra = substr($clean_cand, strlen($clean_query));
        // Permitir años de 4 dígitos en el sobrante
        $extra = preg_replace('/(19\d{2}|20\d{2})/', '', $extra);
        foreach ($allowed_noise as $n) {
            $extra = str_replace($n, '', $extra);
        }
        $extra = trim(preg_replace('/[^a-z0-9]/', '', $extra));
        // Si no queda nada, era sólo ruido técnico permitido
        if (empty($extra)) {
            return true;
        }
        // Si quedan letras (como "lapuertaroja", "thereddoor"), es una secuela o película distinta
        return false;
    }

    // 5. Si la búsqueda empieza por el candidato
    if (strpos($clean_query, $clean_cand) === 0) {
        $extra = substr($clean_query, strlen($clean_cand));
        $extra = preg_replace('/(19\d{2}|20\d{2})/', '', $extra);
        foreach ($allowed_noise as $n) {
            $extra = str_replace($n, '', $extra);
        }
        $extra = trim(preg_replace('/[^a-z0-9]/', '', $extra));
        if (empty($extra)) {
            return true;
        }
        return false;
    }

    // 6. Similitud textual razonable para pequeñas erratas tipográficas (mínimo 90%)
    similar_text($clean_query, $clean_cand, $percent);
    return ($percent >= 90.0);
}

function format_query($query) {
    $normalized = preg_replace('/[^\w\s-]/u', '', $query);
    $normalized = preg_replace('/[\s_-]+/', '-', $normalized);
    return trim(strtolower($normalized), '-');
}

function sort_tmdb_results_by_popularity($a, $b) {
    return ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0);
}

/**
 * Lee el progreso de visualización desde el archivo JSON.
 * @return array
 */
function read_watched_progress(): array {
    if (!defined('WATCHED_PROGRESS_FILE')) {
        return [];
    }
    if (!file_exists(WATCHED_PROGRESS_FILE)) {
        return [];
    }
    $content = file_get_contents(WATCHED_PROGRESS_FILE);
    if ($content === false) {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

/**
 * Escribe el progreso de visualización en el archivo JSON.
 * @param array $progress
 * @return bool
 */
function write_watched_progress(array $progress): bool {
    if (!defined('WATCHED_PROGRESS_FILE')) {
        return false;
    }
    $dir = dirname(WATCHED_PROGRESS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents(WATCHED_PROGRESS_FILE, json_encode($progress, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Obtiene una lista de series recientemente vistas.
 * @return array Un array de arrays, cada uno con 'id' y 'type'.
 */
function get_recently_watched_series(): array {
    $progress = read_watched_progress();
    $recently_watched = [];

    if (isset($progress['tv'])) {
        foreach ($progress['tv'] as $series_id => $series_progress) {
            $recently_watched[] = ['id' => $series_id, 'type' => 'tv'];
        }
    }
    if (isset($progress['movie'])) {
        foreach ($progress['movie'] as $movie_id => $movie_progress) {
            $recently_watched[] = ['id' => $movie_id, 'type' => 'movie'];
        }
    }
    return $recently_watched;
}

/**
 * Resuelve nombres de dominio a través de Cloudflare DoH (1.1.1.1) si el DNS local falla o bloquea.
 */
function resolve_domain_doh(string $domain): ?string {
    static $doh_cache = [
        'serieskao.top' => '104.21.1.197',
        'serieskao.tv'  => '104.21.1.197',
        'serieskao.org' => '104.21.1.197'
    ];

    if (isset($doh_cache[$domain])) {
        return $doh_cache[$domain];
    }

    // Solo consultar DoH para dominios que requieren bypass DNS específico (ej: serieskao).
    // Evita hacer peticiones HTTP externas adicionales a 1.1.1.1 para dominios ordinarios.
    if (strpos($domain, 'serieskao') === false) {
        $doh_cache[$domain] = null;
        return null;
    }

    if (!extension_loaded('curl')) {
        $doh_cache[$domain] = null;
        return null;
    }

    $ch = curl_init("https://1.1.1.1/dns-query?name=" . urlencode($domain) . "&type=A");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dns-json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);

    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data['Answer'])) {
            foreach ($data['Answer'] as $ans) {
                if (($ans['type'] ?? 0) === 1 && !empty($ans['data'])) {
                    $doh_cache[$domain] = $ans['data'];
                    return $ans['data'];
                }
            }
        }
    }

    return null;
}

/**
 * Petición HTTP GET robusta con soporte para cURL y fallback a stream_context
 */
function http_get(string $url, array $options = []): ?string {
    if (connection_aborted()) {
        exit;
    }

    $timeout = $options['timeout'] ?? 4;
    $custom_headers = $options['headers'] ?? [];
    $user_agent = $options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    if (extension_loaded('curl')) {
        $parsed = parse_url($url);
        $domain = $parsed['host'] ?? null;
        $resolve_ip = $domain ? resolve_domain_doh($domain) : null;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

        if ($resolve_ip && $domain) {
            $port = $parsed['port'] ?? (isset($parsed['scheme']) && $parsed['scheme'] === 'http' ? 80 : 443);
            $resolve_entry = ["{$domain}:{$port}:{$resolve_ip}"];
            if ($port === 443) $resolve_entry[] = "{$domain}:80:{$resolve_ip}";
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve_entry);
        }

        // Encabezados estándar limpios (evita rechazos HTTP 444 causados por Sec-Fetch contradictorios)
        $default_headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8'
        ];

        $merged_headers = array_merge($default_headers, $custom_headers);
        $formatted_headers = [];
        foreach ($merged_headers as $k => $v) {
            $formatted_headers[] = is_numeric($k) ? $v : "$k: $v";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted_headers);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        if ($error === 0 && $http_code >= 200 && $http_code < 400) {
            return $result;
        }
        return null;
    }

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$user_agent}\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    return $result !== false ? $result : null;
}

