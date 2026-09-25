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
    
    // Eliminar sufijo de grupo de release al final (ej: "-btm", "-flux", "-yg⭐")
    $title = preg_replace('/-[a-z0-9_\x{1F300}-\x{1F9FF}⭐]+$/iu', '', $title);
    
    // Eliminar años de 4 dígitos (1900-2035) delimitados por puntos, espacios, paréntesis o guiones
    $title = preg_replace('/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/', ' ', ' ' . $title . ' ');
    
    // Eliminar todo el contenido entre corchetes [ ... ]
    $title = preg_replace('/\[.*?\]/', ' ', $title);
    
    // Eliminar palabras de ruido técnico (calidades, fuentes, plataformas, codecs, audios)
    $noise_words = [
        '1080p', '720p', '480p', '2160p', '4k', 'uhd', 'hd', 'fullhd', 'full hd',
        'bluray', 'bdrip', 'brrip', 'dvdrip', 'web-dl', 'webdl', 'web-rip', 'webrip', 'hdtv', 'microhd', 'cam', 'screener', 'ts', 'telesync', 'hdcam',
        'amzn', 'dsnp', 'atvp', 'nflx', 'hmax', 'max', 'paramount', 'disney',
        'h264', 'h.264', 'x264', 'h265', 'h.265', 'x265', 'hevc', 'avc', '10bit', '10-bit', '8bit', 'hdr', 'hdr10', 'hdr10plus', 'hdr10+', 'dv', 'dovi', 'sdr',
        'ddp5.1', 'ddp51', 'ddp5', 'dd5.1', 'dd51', 'ddp', 'dd+', 'atmos', 'dts-hd', 'dts', 'truehd', 'aac2.0', 'aac', 'ac3', 'eac3', 'mp3', 'flac',
        'latino', 'lat', 'castellano', 'espanol', 'español', 'spanish', 'subtitulado', 'sub', 'subs', 'subbed', 'vose', 'vost', 'spa-lat', 'esp-mx', 'es-la', 'es-mx',
        'dual', 'ingles', 'english', 'eng', 'audio', 'multi', 'multiaudio', 'temporada', 'temp', 'mkv', 'mp4', 'avi'
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
    // Auto-extraer año del candidato si no fue provisto explícitamente
    if (!$candidate_year) {
        if (preg_match('/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/', ' ' . $candidate_title . ' ', $my)) {
            $candidate_year = $my[1];
        }
    }

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
        $str = preg_replace('/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/', ' ', ' ' . $str . ' ');
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
        'pelicula', 'movie', 'amzn', 'dsnp', 'nflx', 'webdl', 'webrip', 'bluray',
        'h264', 'x264', 'h265', 'x265', 'hevc', 'ddp51', 'ddp', 'atmos', 'dts',
        'aac', 'ac3', 'latino', 'lat', 'castellano', 'dual', 'multi', 'mp4', 'mkv'
    ];

    // 4. Si el candidato empieza por la búsqueda
    if (strpos($clean_cand, $clean_query) === 0) {
        $extra = substr($clean_cand, strlen($clean_query));
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

/**
 * Analiza el nombre crudo de un release de torrent (ej: "Moana.2026.1080p.AMZN.WEB-DL.MULTi.LATINO.DDP5.1.H264.MP4-BTM")
 * y extrae con precisión:
 * - 'title': El título limpio antes del año o etiquetas de calidad.
 * - 'year': El año de 4 dígitos si está presente.
 */
function parse_torrent_release_name(string $raw): array {
    $raw = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
    
    // Quitar sufijo del grupo de release al final si existe (ej. "-BTM", "-FLUX", "-YG⭐")
    $clean = preg_replace('/-[a-zA-Z0-9_\x{1F300}-\x{1F9FF}⭐]+$/u', '', $raw);
    
    $cand_year = null;
    $title_part = $clean;
    
    // 1. Año de 4 dígitos (1900-2035)
    if (preg_match('/^(.*?)(?:[\s._\-\(\[]+)(19\d{2}|20[0-2]\d|203[0-5])(?:[\s._\-\)\]]+|$)/i', $clean, $m)) {
        $title_part = $m[1];
        $cand_year = $m[2];
    }
    // 2. Si no tiene año pero tiene etiquetas típicas de calidad (1080p, 720p, etc.)
    elseif (preg_match('/^(.*?)(?:[\s._\-\(\[]+)(?:1080p|720p|2160p|4k|bluray|web-?dl|webrip|bdrip|dvdrip|hdtv)(?:[\s._\-\)\]]+|$)/i', $clean, $m)) {
        $title_part = $m[1];
    }
    
    $cand_title = trim(str_replace(['.', '_', '-'], ' ', $title_part));
    return [
        'title' => $cand_title,
        'year' => $cand_year
    ];
}

/**
 * Detecta si un título o release de torrent contiene pistas de audio Latino
 * en sus diversas variantes: Latino, Audio Latino, Dual Lat, Lat, Esp-MX, ES-LA, Spa-Lat, Multi Audio Lat, etc.
 */
function is_latino_audio(string $title): bool {
    $patterns = [
        '/\b(latino|audio[\s\.\-_]*latino|doblaje[\s\.\-_]*latino)\b/i',
        '/\bdual[\s\.\-_]*lat(?:ino)?\b/i',
        '/\b(esp?[\s\.\-_]*(?:mx|la)|spa[\s\.\-_]*lat(?:ino)?)\b/i',
        '/\bspanish[\s\.\-_]*latino\b/i',
        '/(?:[\.\[_\-\s]|^)lat(?:[\.\]_\-\s]|$)/i',
        '/\bmulti(?:[\s\.\-_]*(?:audio|subs?))?[\s\.\-_].*?\b(lat|latino|mx)\b/i',
        '/\b(lat|latino|mx)\b.*?[\s\.\-_]multi\b/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $title)) {
            return true;
        }
    }
    return false;
}

/**
 * Detecta el idioma formal de un release para mostrar en la interfaz.
 */
function detect_release_language(string $title): string {
    $is_latino = is_latino_audio($title);
    $is_dual = (bool)preg_match('/\b(dual|multi|eng|english|ingles|audio[\s\.\-_]*dual)\b/i', $title);
    
    if ($is_latino) {
        if ($is_dual) {
            return 'Español Latino (Dual)';
        }
        return 'Español Latino';
    }
    
    if (preg_match('/\b(castellano|spanish|español|espanol|spa)\b/i', $title) && !preg_match('/\b(mx|la|latino|lat)\b/i', $title)) {
        if ($is_dual) {
            return 'Español Castellano (Dual)';
        }
        return 'Español Castellano';
    }
    
    if (preg_match('/\b(dual|multi)\b/i', $title)) {
        return 'Dual Audio / Multi';
    }
    
    if (preg_match('/\b(subtitulado|sub|subs|subbed|vose|vost)\b/i', $title)) {
        return 'Subtitulado';
    }
    
    return 'Inglés / VO';
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
/**
 * Obtiene la identidad activa (Usuario autenticado con Google o Dispositivo individual).
 * @return array ['type' => 'user'|'device', 'id' => string, 'name' => string, 'email' => string, 'picture' => ?string]
 */
function get_current_identity(): array {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
        session_write_close();
    }

    // 1. ¿Hay usuario autenticado con Google?
    if (!empty($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
        return [
            'type' => 'user',
            'id' => (string)$_SESSION['user']['id'],
            'email' => (string)($_SESSION['user']['email'] ?? ''),
            'name' => (string)($_SESSION['user']['name'] ?? 'Usuario'),
            'picture' => !empty($_SESSION['user']['picture']) ? (string)$_SESSION['user']['picture'] : null
        ];
    }

    // 2. Si no hay sesión, usar identidad por dispositivo (Cookie persistente)
    $device_cookie_name = 'stream_device_id';
    $device_id = null;

    if (!empty($_COOKIE[$device_cookie_name])) {
        $candidate = trim((string)$_COOKIE[$device_cookie_name]);
        if (preg_match('/^[a-f0-9\-]{16,64}$/i', $candidate)) {
            $device_id = $candidate;
        }
    }

    if (!$device_id) {
        $device_id = bin2hex(random_bytes(16));
        $_COOKIE[$device_cookie_name] = $device_id;
        if (!headers_sent()) {
            setcookie($device_cookie_name, $device_id, [
                'expires' => time() + (86400 * 365 * 2), // 2 años de persistencia
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax'
            ]);
        }
    }

    return [
        'type' => 'device',
        'id' => $device_id,
        'email' => '',
        'name' => 'Dispositivo ' . substr($device_id, 0, 6),
        'picture' => null
    ];
}

/**
 * Retorna la ruta al archivo de progreso según la identidad (dispositivo o usuario).
 * @param array|null $identity
 * @return string
 */
function get_watched_progress_file(?array $identity = null): string {
    if ($identity === null) {
        $identity = get_current_identity();
    }

    $base_dir = defined('PROGRESS_DIR') ? PROGRESS_DIR : (defined('ROOT_DIR') ? ROOT_DIR . '/data/progress' : __DIR__ . '/../data/progress');
    if (!is_dir($base_dir)) {
        @mkdir($base_dir, 0755, true);
    }

    $prefix = $identity['type'] === 'user' ? 'user_' : 'device_';
    $safe_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $identity['id']);
    return "{$base_dir}/{$prefix}{$safe_id}.json";
}

/**
 * Lee el progreso de visualización de la identidad activa (con caché estática en memoria).
 * @param bool $force_reload
 * @return array
 */
function read_watched_progress(bool $force_reload = false): array {
    static $memory_cache = [];

    $identity = get_current_identity();
    $cache_key = "{$identity['type']}_{$identity['id']}";

    if (!$force_reload && isset($memory_cache[$cache_key])) {
        return $memory_cache[$cache_key];
    }

    $file = get_watched_progress_file($identity);

    if (!file_exists($file)) {
        $memory_cache[$cache_key] = [];
        return [];
    }

    $content = file_get_contents($file);
    if ($content === false) {
        $memory_cache[$cache_key] = [];
        return [];
    }

    $data = json_decode($content, true);
    $result = is_array($data) ? $data : [];
    $memory_cache[$cache_key] = $result;
    return $result;
}

/**
 * Escribe el progreso de visualización de la identidad activa.
 * @param array $progress
 * @return bool
 */
function write_watched_progress(array $progress): bool {
    $identity = get_current_identity();
    $file = get_watched_progress_file($identity);

    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $success = file_put_contents($file, json_encode($progress, JSON_PRETTY_PRINT)) !== false;
    if ($success) {
        read_watched_progress(true); // Refrescar caché estática
    }
    return $success;
}

/**
 * Fusiona el progreso de un dispositivo local dentro de la cuenta del usuario logueado.
 * @param string $device_id
 * @param string $user_id
 * @return bool
 */
function merge_device_progress_to_user(string $device_id, string $user_id): bool {
    $dev_file = get_watched_progress_file(['type' => 'device', 'id' => $device_id]);
    if (!file_exists($dev_file)) return false;

    $dev_content = file_get_contents($dev_file);
    $dev_data = json_decode($dev_content, true);
    if (!is_array($dev_data) || empty($dev_data)) return false;

    $user_file = get_watched_progress_file(['type' => 'user', 'id' => $user_id]);
    $user_data = [];
    if (file_exists($user_file)) {
        $u_content = file_get_contents($user_file);
        $user_data = json_decode($u_content, true) ?: [];
    }

    // Fusionar series
    if (isset($dev_data['tv']) && is_array($dev_data['tv'])) {
        if (!isset($user_data['tv'])) $user_data['tv'] = [];
        foreach ($dev_data['tv'] as $s_id => $s_val) {
            if (!isset($user_data['tv'][$s_id])) {
                $user_data['tv'][$s_id] = $s_val;
            } else {
                foreach ($s_val as $s_num => $ep_val) {
                    if ($s_num === '_last_watched') {
                        $dev_time = $s_val['_last_watched']['updated_at'] ?? 0;
                        $user_time = $user_data['tv'][$s_id]['_last_watched']['updated_at'] ?? 0;
                        if ($dev_time > $user_time) {
                            $user_data['tv'][$s_id]['_last_watched'] = $s_val['_last_watched'];
                        }
                    } elseif (is_array($ep_val)) {
                        if (!isset($user_data['tv'][$s_id][$s_num])) {
                            $user_data['tv'][$s_id][$s_num] = [];
                        }
                        foreach ($ep_val as $e_num => $e_st) {
                            $user_data['tv'][$s_id][$s_num][$e_num] = $e_st;
                        }
                    }
                }
            }
        }
    }

    // Fusionar películas
    if (isset($dev_data['movie']) && is_array($dev_data['movie'])) {
        if (!isset($user_data['movie'])) $user_data['movie'] = [];
        foreach ($dev_data['movie'] as $m_id => $m_st) {
            if (!isset($user_data['movie'][$m_id]) || $user_data['movie'][$m_id] !== 'watched') {
                $user_data['movie'][$m_id] = $m_st;
            }
        }
    }

    return file_put_contents($user_file, json_encode($user_data, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Obtiene una lista de series y películas recientemente vistas ordenadas por actividad.
 * @return array Un array de arrays, cada uno con 'id' y 'type'.
 */
function get_recently_watched_series(): array {
    $progress = read_watched_progress();
    $recently_watched = [];

    if (isset($progress['tv']) && is_array($progress['tv'])) {
        foreach ($progress['tv'] as $series_id => $series_progress) {
            $updated_at = $series_progress['_last_watched']['updated_at'] ?? 0;
            $recently_watched[] = [
                'id' => $series_id,
                'type' => 'tv',
                'updated_at' => $updated_at
            ];
        }
    }
    if (isset($progress['movie']) && is_array($progress['movie'])) {
        foreach ($progress['movie'] as $movie_id => $movie_progress) {
            $updated_at = is_array($movie_progress) ? ($movie_progress['updated_at'] ?? 0) : 0;
            $recently_watched[] = [
                'id' => $movie_id,
                'type' => 'movie',
                'updated_at' => $updated_at
            ];
        }
    }

    // Ordenar de más reciente a más antiguo si hay fecha disponible
    usort($recently_watched, function($a, $b) {
        return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
    });

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

/**
 * Comparación flexible de títulos para Anime, permitiendo sufijos comunes de temporadas y formatos
 * como (TV), 2nd Season, Season 2, etc., sin descartar falsos negativos.
 */
function is_anime_title_match(string $query_title, string $candidate_title): bool {
    $clean = function(string $s) {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[\(\[]\s*(?:tv|pelicula|movie|bd|audio\s*latino|sub\s*español)\s*[\)\]]/iu', ' ', $s);
        $s = preg_replace('/\b(?:tv|ova|ona|special|especial)\b/i', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    };

    $q = $clean($query_title);
    $c = $clean($candidate_title);
    if (empty($q) || empty($c)) return false;
    if ($q === $c) return true;

    if (strpos($c, $q) === 0) {
        $remainder = trim(substr($c, strlen($q)));
        if (empty($remainder)) return true;

        // Debe coincidir estrictamente con patrones numéricos de temporada, secuelas o arcos canónicos (NO palabras arbitrarias)
        $valid_season_pattern = '/^(?:(?:\d+|[1-9]nd|[1-9]rd|[1-9]th|s\d+|season|temporada|part|parte|the|final|movie|pelicula|cour|kanketsu|hen|arc|arco|zenpen|kouhen|ii|iii|iv|v|vi|vii|viii|ix|x|shibuya\s+jihen|sennen\s+kessen\s+hen|yuukaku\s+hen|katanakaji\s+no\s+sato\s+hen|hashira\s+geiko\s+hen|shimetsu\s+kaiyuu)\s*)+$/iu';

        if (preg_match($valid_season_pattern, $remainder)) {
            return true;
        }
    }
    return false;
}

/**
 * Limpiador automático de archivos caducados en la carpeta de caché (Garbage Collector).
 * Se ejecuta silenciosamente máximo una vez al día para evitar sobrecarga y proteger el límite de inodos.
 *
 * @param bool $force Si es true, omite la verificación de intervalo diario.
 * @return int Cantidad de archivos eliminados.
 */
function clean_expired_cache(bool $force = false): int {
    if (!defined('CACHE_DIR') || !is_dir(CACHE_DIR)) {
        return 0;
    }

    $gc_marker = CACHE_DIR . '/.last_gc';
    $now = time();

    // Ejecutar como máximo una vez cada 24 horas (86400s)
    if (!$force && file_exists($gc_marker) && ($now - filemtime($gc_marker) < 86400)) {
        return 0;
    }

    @touch($gc_marker);

    $deleted_count = 0;
    $max_days = defined('CACHE_MAX_RETENTION_DAYS') ? CACHE_MAX_RETENTION_DAYS : 15;
    $max_retention_sec = $max_days * 86400;

    $avail_found_ttl = defined('AVAILABILITY_CACHE_FOUND') ? AVAILABILITY_CACHE_FOUND : 86400;
    $avail_not_found_ttl = defined('AVAILABILITY_CACHE_NOT_FOUND') ? AVAILABILITY_CACHE_NOT_FOUND : 14400;
    $links_ttl = defined('LINKS_CACHE_TIME') ? LINKS_CACHE_TIME * 2 : 3600; // 1 hora máx para enlaces temporales

    $files = @scandir(CACHE_DIR);
    if (!is_array($files)) {
        return 0;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.last_gc' || $file === '.gitignore') {
            continue;
        }

        $file_path = CACHE_DIR . '/' . $file;
        if (!is_file($file_path)) {
            continue;
        }

        $mtime = @filemtime($file_path);
        if (!$mtime) {
            continue;
        }

        $age = $now - $mtime;

        // 1. Enlaces por proveedor: eliminar si tienen más de 1 hora
        if (strpos($file, 'links_') === 0) {
            if ($age > $links_ttl) {
                @unlink($file_path);
                $deleted_count++;
            }
            continue;
        }

        // 2. Disponibilidad: eliminar según TTL asimétrico
        if (strpos($file, 'avail_') === 0) {
            if ($age > $avail_found_ttl) {
                @unlink($file_path);
                $deleted_count++;
            } elseif ($age > $avail_not_found_ttl) {
                $content = @file_get_contents($file_path);
                if ($content && strpos($content, '"not_found"') !== false) {
                    @unlink($file_path);
                    $deleted_count++;
                }
            }
            continue;
        }

        // 3. Fichas de TMDB y otros: eliminar si superan la retención máxima (15 días)
        if ($age > $max_retention_sec) {
            @unlink($file_path);
            $deleted_count++;
        }
    }

    return $deleted_count;
}



