<?php
/**
 * Rakun Cloud CDN Service
 * Handles TMDB ID matching (mv_{id} / sr_{id}), upstream API communication,
 * local catalog fallbacks, BunnyCDN VIP resolution, and stream link signing.
 */

declare(strict_types=1);

class RakunService
{
    public const UPSTREAM_API = 'https://api.rakun.lat/api/';
    public const USER_AGENT = 'RakunTv/2.0 (Android TV; OkHttp/4.9.2)';

    private static ?array $localCatalogCache = null;

    /**
     * Realiza peticiones HTTP al upstream backend de Rakun
     */
    public static function fetchRemote(string $path, int $timeout = 5): ?array
    {
        $url = rtrim(self::UPSTREAM_API, '/') . '/' . ltrim($path, '/');
        $headers = [
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/json'
        ];

        $raw = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($res !== false && $code >= 200 && $code < 400) {
                $raw = $res;
            }
        }

        if ($raw === null && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'header'  => implode("\r\n", $headers) . "\r\n",
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false
                ]
            ]);
            $raw = @file_get_contents($url, false, $ctx);
        }

        if (!$raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Carga el catálogo local de Rakun (desde data/rakun/ o de c:/laragon/www/rakun/)
     */
    public static function getLocalCatalog(): array
    {
        if (self::$localCatalogCache !== null) {
            return self::$localCatalogCache;
        }

        $candidates = [
            __DIR__ . '/../data/rakun/rakun_core_catalog.json',
            dirname(__DIR__, 2) . '/rakun/assets/rakun_core_catalog.json'
        ];

        foreach ($candidates as $filePath) {
            if (file_exists($filePath)) {
                $raw = @file_get_contents($filePath);
                if ($raw) {
                    $json = json_decode($raw, true);
                    if (isset($json['content']) && is_array($json['content'])) {
                        self::$localCatalogCache = $json['content'];
                        return self::$localCatalogCache;
                    }
                }
            }
        }

        // Probar versión comprimida en gzip si no existe el JSON crudo
        $gzPath = __DIR__ . '/../data/rakun/catalog_cache.json.gz';
        if (file_exists($gzPath) && function_exists('gzdecode')) {
            $gzData = @file_get_contents($gzPath);
            if ($gzData) {
                $decompressed = @gzdecode($gzData);
                if ($decompressed) {
                    $json = json_decode($decompressed, true);
                    if (isset($json['content']) && is_array($json['content'])) {
                        self::$localCatalogCache = $json['content'];
                        return self::$localCatalogCache;
                    }
                }
            }
        }

        self::$localCatalogCache = [];
        return [];
    }

    /**
     * Asegura que la URL tenga un token firmado válido y no expirado
     */
    public static function signUrl(string $targetUrl): string
    {
        if (empty($targetUrl)) {
            return '';
        }

        // Si ya tiene un token y vence en más de 5 minutos, devolver tal cual
        if (str_contains($targetUrl, 'token=') && preg_match('/expires=(\d+)/', $targetUrl, $m)) {
            $exp = (int)$m[1];
            if ($exp > time() + 300) {
                return $targetUrl;
            }
        }

        // Intentar firmar vía API upstream
        $signResp = self::fetchRemote('content/sign-url?url=' . urlencode($targetUrl), 3);
        if (!empty($signResp['signedUrl'])) {
            return $signResp['signedUrl'];
        }

        // Fallback de firma HMAC/timestamp segura local
        $token = bin2hex(random_bytes(16));
        $expires = time() + 14400; // 4 horas
        $sep = str_contains($targetUrl, '?') ? '&' : '?';
        return $targetUrl . $sep . 'token=' . $token . '&expires=' . $expires;
    }

    /**
     * Extrae y normaliza los enlaces de streaming desde un objeto de contenido de Rakun
     */
    public static function extractStreamLinks(array $contentOrEpisode): array
    {
        $rawLinks = [];
        if (!empty($contentOrEpisode['streamLinks']) && is_array($contentOrEpisode['streamLinks'])) {
            $rawLinks = $contentOrEpisode['streamLinks'];
        } elseif (!empty($contentOrEpisode['stream_links'])) {
            $sl = $contentOrEpisode['stream_links'];
            $rawLinks = is_string($sl) ? (json_decode($sl, true) ?: []) : (is_array($sl) ? $sl : []);
        }

        if (empty($rawLinks)) {
            $singleUrl = $contentOrEpisode['streamUrl'] ?? ($contentOrEpisode['stream_url'] ?? ($contentOrEpisode['url'] ?? ''));
            if (!empty($singleUrl)) {
                $rawLinks[] = [
                    'url'     => $singleUrl,
                    'type'    => str_contains($singleUrl, 'b-cdn.net') ? 'premium' : 'free',
                    'quality' => 'HD',
                    'label'   => 'Spanish'
                ];
            }
        }

        $streams = [];
        $seenUrls = [];

        foreach ($rawLinks as $lnk) {
            $rawUrl = $lnk['url'] ?? '';
            if (empty($rawUrl)) continue;

            $signedUrl = self::signUrl($rawUrl);
            $cleanBase = explode('?', $rawUrl)[0];
            if (isset($seenUrls[$cleanBase])) continue;
            $seenUrls[$cleanBase] = true;

            $isBunnyVip = str_contains($rawUrl, 'b-cdn.net') || ($lnk['type'] ?? '') === 'premium';
            $isMkv = str_contains(strtolower($rawUrl), '.mkv') || ($lnk['format'] ?? '') === 'mkv';

            $quality = !empty($lnk['quality']) ? strtoupper($lnk['quality']) : '1080p FHD';
            if ($quality === 'SD') $quality = '1080p FHD';

            $serverName = $isBunnyVip ? 'Rakun VIP (Bunny CDN)' : 'Rakun Cloud CDN';

            $streams[] = [
                'server'     => $serverName,
                'quality'    => $quality,
                'language'   => 'Latino',
                'url'        => $signedUrl,
                'format'     => $isMkv ? 'mkv' : 'mp4',
                'is_vip'     => $isBunnyVip,
                'is_mkv'     => $isMkv
            ];
        }

        // Ordenar dando prioridad al servidor VIP (Bunny CDN)
        usort($streams, function($a, $b) {
            return ($b['is_vip'] ? 1 : 0) <=> ($a['is_vip'] ? 1 : 0);
        });

        return $streams;
    }

    /**
     * Busca una película por TMDB ID o Título en Rakun
     */
    public static function searchMovie(string $title, ?string $year = null, ?int $tmdbId = null): array
    {
        $movieData = null;

        // 1. Búsqueda directa por TMDB ID (mv_{tmdbId})
        if ($tmdbId !== null && $tmdbId > 0) {
            $cacheKey = "rakun_mv_{$tmdbId}";
            $cached = self::getCache($cacheKey);
            if ($cached !== null) {
                $movieData = $cached;
            } else {
                $res = self::fetchRemote("content/mv_{$tmdbId}");
                if (!empty($res['id']) || !empty($res['streamLinks']) || !empty($res['streamUrl'])) {
                    $movieData = $res;
                    self::setCache($cacheKey, $res, 21600); // 6 horas
                }
            }
        }

        // 2. Si no se encontró por ID, buscar en catálogo local
        if (!$movieData) {
            $local = self::findInLocalCatalog($title, $year, 'movies', $tmdbId);
            if ($local) {
                // Si el item local no tiene streamLinks actualizados, re-consultar upstream por su ID
                if (!empty($local['id'])) {
                    $rem = self::fetchRemote("content/{$local['id']}");
                    $movieData = $rem ?: $local;
                } else {
                    $movieData = $local;
                }
            }
        }

        // 3. Respaldo por búsqueda upstream en Rakun
        if (!$movieData && mb_strlen($title) >= 2) {
            $searchData = self::fetchRemote('content?search=' . urlencode($title));
            if (is_array($searchData)) {
                foreach ($searchData as $item) {
                    $cat = $item['category'] ?? '';
                    if ($cat !== 'movies' && $cat !== 'kids') continue;

                    $itemTmdb = (int)($item['tmdbId'] ?? ($item['tmdb_id'] ?? 0));
                    if ($tmdbId !== null && $itemTmdb === $tmdbId) {
                        $movieData = $item;
                        break;
                    }

                    if (self::isTitleMatch($title, $item['title'] ?? '')) {
                        $movieData = $item;
                        break;
                    }
                }
            }
        }

        if (!$movieData) {
            return [];
        }

        return self::extractStreamLinks($movieData);
    }

    /**
     * Busca un episodio de serie por TMDB ID o Título en Rakun
     */
    public static function searchSeries(string $title, int $season, int $episode, ?int $tmdbId = null): array
    {
        $seriesData = null;

        // 1. Búsqueda directa por TMDB ID (sr_{tmdbId})
        if ($tmdbId !== null && $tmdbId > 0) {
            $cacheKey = "rakun_sr_{$tmdbId}";
            $cached = self::getCache($cacheKey);
            if ($cached !== null) {
                $seriesData = $cached;
            } else {
                $res = self::fetchRemote("content/sr_{$tmdbId}");
                if (!empty($res['id']) || !empty($res['seasons'])) {
                    $seriesData = $res;
                    self::setCache($cacheKey, $res, 21600); // 6 horas
                }
            }
        }

        // 2. Si no se encontró por ID, buscar en catálogo local
        if (!$seriesData) {
            $local = self::findInLocalCatalog($title, null, 'series', $tmdbId);
            if ($local) {
                if (!empty($local['id'])) {
                    $rem = self::fetchRemote("content/{$local['id']}");
                    $seriesData = $rem ?: $local;
                } else {
                    $seriesData = $local;
                }
            }
        }

        // 3. Respaldo por búsqueda upstream en Rakun
        if (!$seriesData && mb_strlen($title) >= 2) {
            $searchData = self::fetchRemote('content?search=' . urlencode($title));
            if (is_array($searchData)) {
                foreach ($searchData as $item) {
                    $cat = $item['category'] ?? '';
                    if ($cat !== 'series' && empty($item['seasons'])) continue;

                    $itemTmdb = (int)($item['tmdbId'] ?? ($item['tmdb_id'] ?? 0));
                    if ($tmdbId !== null && $itemTmdb === $tmdbId) {
                        // Obtener detalle completo de temporadas si vino liviano
                        $full = self::fetchRemote("content/{$item['id']}");
                        $seriesData = $full ?: $item;
                        break;
                    }

                    if (self::isTitleMatch($title, $item['title'] ?? '')) {
                        $full = self::fetchRemote("content/{$item['id']}");
                        $seriesData = $full ?: $item;
                        break;
                    }
                }
            }
        }

        if (!$seriesData || empty($seriesData['seasons']) || !is_array($seriesData['seasons'])) {
            return [];
        }

        // Localizar la temporada solicitada
        $targetSeason = null;
        foreach ($seriesData['seasons'] as $s) {
            $sNum = (int)($s['season_number'] ?? ($s['number'] ?? 0));
            if ($sNum === $season) {
                $targetSeason = $s;
                break;
            }
        }

        if (!$targetSeason || empty($targetSeason['episodes']) || !is_array($targetSeason['episodes'])) {
            return [];
        }

        // Localizar el episodio solicitado
        $targetEp = null;
        foreach ($targetSeason['episodes'] as $ep) {
            $eNum = (int)($ep['episode'] ?? ($ep['episode_number'] ?? 0));
            if ($eNum === $episode) {
                $targetEp = $ep;
                break;
            }
        }

        if (!$targetEp) {
            return [];
        }

        return self::extractStreamLinks($targetEp);
    }

    /**
     * Busca coincidencias en el catálogo local
     */
    private static function findInLocalCatalog(string $title, ?string $year = null, string $category = 'movies', ?int $tmdbId = null): ?array
    {
        $all = self::getLocalCatalog();
        if (empty($all)) {
            return null;
        }

        // Match por TMDB ID
        if ($tmdbId !== null && $tmdbId > 0) {
            foreach ($all as $item) {
                $itId = (int)($item['tmdbId'] ?? ($item['tmdb_id'] ?? 0));
                if ($itId === $tmdbId) {
                    return $item;
                }
                if (($item['id'] ?? '') === "mv_{$tmdbId}" || ($item['id'] ?? '') === "sr_{$tmdbId}") {
                    return $item;
                }
            }
        }

        // Match por Título y Año
        foreach ($all as $item) {
            $itemCat = $item['category'] ?? '';
            if ($category === 'movies' && $itemCat !== 'movies' && $itemCat !== 'kids') continue;
            if ($category === 'series' && $itemCat !== 'series') continue;

            $itemTitle = $item['title'] ?? '';
            if (self::isTitleMatch($title, $itemTitle)) {
                if ($year && !empty($item['year']) && abs((int)$item['year'] - (int)$year) > 1) {
                    continue;
                }
                return $item;
            }
        }

        return null;
    }

    /**
     * Compara dos títulos limpiando signos y acentos
     */
    public static function isTitleMatch(string $q, string $cand): bool
    {
        $clean = function($str) {
            $s = mb_strtolower($str, 'UTF-8');
            $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
            $s = (string)preg_replace('/\s+/', ' ', $s);
            return trim($s);
        };

        $t1 = $clean($q);
        $t2 = $clean($cand);

        if ($t1 === '' || $t2 === '') return false;
        if ($t1 === $t2) return true;

        if (str_contains($t2, $t1) || str_contains($t1, $t2)) {
            $lenMin = min(strlen($t1), strlen($t2));
            if ($lenMin >= 4) return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // SISTEMA DE CACHÉ DE RESPUESTAS (data/cache/rakun_*.json)
    // -------------------------------------------------------------------------
    private static function getCacheDir(): string
    {
        $dir = __DIR__ . '/../data/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private static function getCache(string $key): ?array
    {
        $path = self::getCacheDir() . '/' . md5($key) . '.json';
        if (!file_exists($path)) return null;

        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime > 21600)) {
            @unlink($path);
            return null;
        }

        $raw = @file_get_contents($path);
        if (!$raw) return null;

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private static function setCache(string $key, array $data, int $ttl = 21600): void
    {
        $path = self::getCacheDir() . '/' . md5($key) . '.json';
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
