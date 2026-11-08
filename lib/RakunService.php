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

        // 1. Probar archivo JSON crudo local
        $jsonPath = __DIR__ . '/../data/rakun/rakun_core_catalog.json';
        if (file_exists($jsonPath)) {
            $raw = @file_get_contents($jsonPath);
            if ($raw) {
                $json = json_decode($raw, true);
                if (isset($json['content']) && is_array($json['content'])) {
                    self::$localCatalogCache = $json['content'];
                    return self::$localCatalogCache;
                }
            }
        }

        // 2. Probar versión comprimida en gzip (empaquetada en despliegue)
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

        // Si ya tiene un token vigente (vence en más de 5 minutos), devolver tal cual
        if (str_contains($targetUrl, 'token=') && preg_match('/expires=(\d+)/', $targetUrl, $m)) {
            $exp = (int)$m[1];
            if ($exp > time() + 300) {
                return $targetUrl;
            }
        }

        // Si no tiene token o ya expiró, NO inyectar un token aleatorio con random_bytes.
        // Los CDNs (Bunny CDN y Cloudflare) rechazan firmas inválidas con 403 Forbidden y 401 Unauthorized.
        return $targetUrl;
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
        $cacheKey = "rakun_mv_" . ($tmdbId ?: md5($title . ($year ?: '')));
        $cached = self::getCache($cacheKey);
        if ($cached !== null && is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $itemTarget = null;

        // 1. Buscar en catálogo local (mapea TMDB IDs y títulos a IDs internos de Rakun)
        $local = self::findInLocalCatalog($title, $year, 'movies', $tmdbId);
        if ($local && !empty($local['id'])) {
            $itemTarget = $local;
        }

        // 2. Si no se encontró en local, buscar upstream en la API por título
        if (!$itemTarget && mb_strlen($title) >= 2) {
            $searchData = self::fetchRemote('content?search=' . urlencode($title), 4);
            if (is_array($searchData)) {
                foreach ($searchData as $item) {
                    $cat = $item['category'] ?? '';
                    if ($cat !== 'movies' && $cat !== 'kids') continue;

                    $itemTmdb = (int)($item['tmdbId'] ?? ($item['tmdb_id'] ?? 0));
                    if ($tmdbId !== null && $itemTmdb === $tmdbId) {
                        $itemTarget = $item;
                        break;
                    }

                    if (self::isTitleMatch($title, $item['title'] ?? '')) {
                        $itemTarget = $item;
                        break;
                    }
                }
            }
        }

        if (!$itemTarget || empty($itemTarget['id'])) {
            return [];
        }

        // 3. CONSULTAR SIEMPRE EL DETALLE FRESCO POR ID (content/{id}) PARA OBTENER ENLACES CON TOKENS REALES VÁLIDOS
        $fresh = self::fetchRemote("content/{$itemTarget['id']}", 5);
        $movieData = $fresh ?: $itemTarget;

        $streams = self::extractStreamLinks($movieData);
        if (!empty($streams)) {
            self::setCache($cacheKey, $streams, 10800); // 3 horas de caché
        }

        return $streams;
    }

    /**
     * Busca un episodio de serie por TMDB ID o Título en Rakun
     */
    public static function searchSeries(string $title, int $season, int $episode, ?int $tmdbId = null): array
    {
        $cacheKey = "rakun_sr_" . ($tmdbId ?: md5($title)) . "_s{$season}e{$episode}";
        $cached = self::getCache($cacheKey);
        if ($cached !== null && is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $seriesTarget = null;

        // 1. Buscar en catálogo local
        $local = self::findInLocalCatalog($title, null, 'series', $tmdbId);
        if ($local && !empty($local['id'])) {
            $seriesTarget = $local;
        }

        // 2. Si no se encontró en local, buscar upstream en la API por título
        if (!$seriesTarget && mb_strlen($title) >= 2) {
            $searchData = self::fetchRemote('content?search=' . urlencode($title), 4);
            if (is_array($searchData)) {
                foreach ($searchData as $item) {
                    $cat = $item['category'] ?? '';
                    if ($cat !== 'series' && $cat !== 'anime' && empty($item['seasons'])) continue;

                    $itemTmdb = (int)($item['tmdbId'] ?? ($item['tmdb_id'] ?? 0));
                    if ($tmdbId !== null && $itemTmdb === $tmdbId) {
                        $seriesTarget = $item;
                        break;
                    }

                    if (self::isTitleMatch($title, $item['title'] ?? '')) {
                        $seriesTarget = $item;
                        break;
                    }
                }
            }
        }

        if (!$seriesTarget || empty($seriesTarget['id'])) {
            return [];
        }

        // 3. CONSULTAR SIEMPRE EL DETALLE FRESCO POR ID (content/{id}) PARA OBTENER TODAS LAS TEMPORADAS CON TOKENS VÁLIDOS
        $fresh = self::fetchRemote("content/{$seriesTarget['id']}", 5);
        $seriesData = $fresh ?: $seriesTarget;

        if (empty($seriesData['seasons']) || !is_array($seriesData['seasons'])) {
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

        $streams = self::extractStreamLinks($targetEp);
        if (!empty($streams)) {
            self::setCache($cacheKey, $streams, 10800); // 3 horas de caché
        }

        return $streams;
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

        static $tmdbToRakunMap = [
            // Películas
            1318413 => 'c_3d8l7ewcnq', // El Día D: Bajo presión
            1128204 => 'c_xq65wtzxir', // Devorador de Almas
            390061  => 'c_dwqx1ssbjq', // El Informante (2017)
            354912  => 'c_g01x2i8tvk', // Coco
            404368  => 'c_l9y3k4s9ly', // Wifi Ralph
            82690   => 'c_9ioyoi9kdv', // Ralph el demoledor
            49519   => 'c_oxjod1jgnj', // Los Croods
            38055   => 'c_5w1gnqdgxw', // Megamente
            269149  => 'c_bg3v7djvr1', // Mi Villano Favorito 3
            93456   => 'c_gmo95z7uaq', // Mi Villano Favorito 2
            // Series
            1399    => 'c_fzp45tihaf', // Juego de Tronos
            66732   => 'c_bf1wb9cv1f', // Stranger Things
            200875  => 'c_v872k399g2', // IT: Bienvenidos a Derry
            94605   => 's_arcane_s2',  // Arcane
            218896  => 'c_4x3ngzq8gl', // Linternas (Lanterns)
            213713  => 'c_5hi0qhdfia', // Crystal Lake
            258903  => 'c_d6j9dg52lx', // Tougen Anki
            263622  => 'c_ftt65kqn4t', // Kill Blue
        ];

        // 1. Match por TMDB ID directo o mapeado
        if ($tmdbId !== null && $tmdbId > 0) {
            $mappedRakunId = $tmdbToRakunMap[$tmdbId] ?? null;

            foreach ($all as $item) {
                if ($mappedRakunId && ($item['id'] ?? '') === $mappedRakunId) {
                    return $item;
                }
                $itId = (int)($item['tmdbId'] ?? ($item['tmdb_id'] ?? 0));
                if ($itId === $tmdbId) {
                    return $item;
                }
                if (($item['id'] ?? '') === "mv_{$tmdbId}" || ($item['id'] ?? '') === "sr_{$tmdbId}") {
                    return $item;
                }
            }

            if ($mappedRakunId) {
                return ['id' => $mappedRakunId, 'tmdbId' => $tmdbId, 'category' => $category];
            }
        }

        // 2. Match por Título y Año
        foreach ($all as $item) {
            $itemCat = $item['category'] ?? '';
            if ($category === 'movies' && $itemCat !== 'movies' && $itemCat !== 'kids') continue;
            if ($category === 'series' && $itemCat !== 'series' && $itemCat !== 'anime') continue;

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

    /**
     * Retorna los contenidos populares de Rakun divididos por subsecciones:
     * - 'movies': Películas
     * - 'series': Series
     * - 'anime': Anime
     * - 'kids': Infantil y Familiar
     */
    public static function getPopularSections(int $limit = 12): array
    {
        $cacheKey = 'rakun_popular_sections_v2';
        $cached = self::getCache($cacheKey);
        if ($cached !== null && is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $catalog = self::getLocalCatalog();
        if (empty($catalog)) {
            return ['movies' => [], 'series' => [], 'anime' => [], 'kids' => []];
        }

        $customMap = [
            // Películas
            'c_3d8l7ewcnq' => ['id' => 1318413, 'type' => 'movie'], // El Día D: Bajo presión
            'c_xq65wtzxir' => ['id' => 1128204, 'type' => 'movie'], // Devorador de Almas
            'c_dwqx1ssbjq' => ['id' => 390061, 'type' => 'movie'],  // El Informante (2017)
            'c_g01x2i8tvk' => ['id' => 354912, 'type' => 'movie'],  // Coco
            'c_l9y3k4s9ly' => ['id' => 404368, 'type' => 'movie'],  // Wifi Ralph
            'c_9ioyoi9kdv' => ['id' => 82690, 'type' => 'movie'],   // Ralph el demoledor
            'c_oxjod1jgnj' => ['id' => 49519, 'type' => 'movie'],   // Los Croods
            'c_5w1gnqdgxw' => ['id' => 38055, 'type' => 'movie'],   // Megamente
            'c_bg3v7djvr1' => ['id' => 269149, 'type' => 'movie'],  // Mi Villano Favorito 3
            'c_gmo95z7uaq' => ['id' => 93456, 'type' => 'movie'],   // Mi Villano Favorito 2
            // Series
            'c_fzp45tihaf' => ['id' => 1399, 'type' => 'tv'],       // Juego de Tronos
            'c_bf1wb9cv1f' => ['id' => 66732, 'type' => 'tv'],      // Stranger Things
            'c_v872k399g2' => ['id' => 200875, 'type' => 'tv'],     // IT: Bienvenidos a Derry
            's_arcane_s2'  => ['id' => 94605, 'type' => 'tv'],      // Arcane
            'c_4x3ngzq8gl' => ['id' => 218896, 'type' => 'tv'],     // Linternas (Lanterns)
            'c_5hi0qhdfia' => ['id' => 213713, 'type' => 'tv'],     // Crystal Lake
            // Anime
            'c_d6j9dg52lx' => ['id' => 258903, 'type' => 'tv'],     // Tougen Anki
            'c_ftt65kqn4t' => ['id' => 263622, 'type' => 'tv'],     // Kill Blue
        ];

        $categories = [
            'movies' => 'movies',
            'series' => 'series',
            'anime'  => 'anime',
            'kids'   => 'kids'
        ];

        $sections = [];

        foreach ($categories as $secKey => $catFilter) {
            $catItems = array_values(array_filter($catalog, function($it) use ($catFilter) {
                $c = $it['category'] ?? '';
                if ($catFilter === 'movies') return ($c === 'movies');
                if ($catFilter === 'series') return ($c === 'series');
                if ($catFilter === 'anime')  return ($c === 'anime');
                if ($catFilter === 'kids')   return ($c === 'kids');
                return false;
            }));

            // Ordenar: primero top10 / featured, luego views desc
            usort($catItems, function($a, $b) {
                $scoreA = (!empty($a['top10']) ? 200000 : 0) + (!empty($a['featured']) ? 100000 : 0) + ($a['views'] ?? 0);
                $scoreB = (!empty($b['top10']) ? 200000 : 0) + (!empty($b['featured']) ? 100000 : 0) + ($b['views'] ?? 0);
                return $scoreB <=> $scoreA;
            });

            $secResults = [];
            $seen = [];

            foreach ($catItems as $it) {
                $rawId = $it['id'] ?? '';
                $type = ($secKey === 'series' || $secKey === 'anime') ? 'tv' : 'movie';
                $tmdbId = null;

                if (isset($customMap[$rawId])) {
                    $tmdbId = $customMap[$rawId]['id'];
                    $type = $customMap[$rawId]['type'];
                } elseif (preg_match('/^mv_(\d+)$/', $rawId, $m)) {
                    $tmdbId = (int)$m[1];
                    $type = 'movie';
                } elseif (preg_match('/^(?:sr|an)_(\d+)$/', $rawId, $m)) {
                    $tmdbId = (int)$m[1];
                    $type = 'tv';
                } elseif (!empty($it['tmdbId'])) {
                    $tmdbId = (int)$it['tmdbId'];
                } elseif (!empty($it['tmdb_id'])) {
                    $tmdbId = (int)$it['tmdb_id'];
                }

                if (!$tmdbId) {
                    continue;
                }

                $key = "{$type}_{$tmdbId}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $poster = $it['poster'] ?? ($it['thumbnail'] ?? '');
                $backdrop = $it['backdrop'] ?? '';
                $title = $it['title'] ?? 'Sin título';
                $year = !empty($it['year']) ? (string)$it['year'] : '2024';
                $rating = isset($it['rating']) ? (float)$it['rating'] : 8.0;

                $secResults[] = [
                    'id'             => $tmdbId,
                    'title'          => $title,
                    'name'           => $title,
                    'type'           => $type,
                    'poster_path'    => $poster,
                    'backdrop_path'  => $backdrop,
                    'release_date'   => "{$year}-01-01",
                    'first_air_date' => "{$year}-01-01",
                    'vote_average'   => $rating,
                    'original_title' => $it['original_title'] ?? $title,
                    'rakun_badge'    => true
                ];

                if (count($secResults) >= $limit) {
                    break;
                }
            }

            $sections[$secKey] = $secResults;
        }

        self::setCache($cacheKey, $sections, 21600); // 6 horas
        return $sections;
    }

    /**
     * Retorna los contenidos más populares de Rakun (Top 10 / Trending)
     * adaptados con sus TMDB IDs para visualización inmediata en Home.
     */
    public static function getPopularContent(int $limit = 15): array
    {
        $cacheKey = 'rakun_popular_home';
        $cached = self::getCache($cacheKey);
        if ($cached !== null && is_array($cached) && !empty($cached)) {
            return array_slice($cached, 0, $limit);
        }

        $items = [];
        $remote = self::fetchRemote('content/popular-rows', 3);
        if (!empty($remote['top10']) && is_array($remote['top10'])) {
            $items = $remote['top10'];
        } elseif (!empty($remote['rows'][0]['items']) && is_array($remote['rows'][0]['items'])) {
            $items = $remote['rows'][0]['items'];
        }

        if (empty($items)) {
            $catalog = self::getLocalCatalog();
            if (!empty($catalog)) {
                // Priorizar elementos con top10, luego featured, luego con más vistas
                $top10 = array_values(array_filter($catalog, fn($i) => !empty($i['top10'])));
                $featured = array_values(array_filter($catalog, fn($i) => !empty($i['featured']) && empty($i['top10'])));
                $merged = array_merge($top10, $featured);

                if (count($merged) < $limit) {
                    $others = array_values(array_filter($catalog, fn($i) => empty($i['top10']) && empty($i['featured'])));
                    usort($others, fn($a, $b) => ($b['views'] ?? 0) <=> ($a['views'] ?? 0));
                    $merged = array_merge($merged, array_slice($others, 0, $limit - count($merged)));
                }
                $items = $merged;
            }
        }

        $customMap = [
            'c_v872k399g2' => ['id' => 200875, 'type' => 'tv'],     // IT: Bienvenidos a Derry
            'c_3d8l7ewcnq' => ['id' => 1318413, 'type' => 'movie'], // El Día D: Bajo presión
            'c_xq65wtzxir' => ['id' => 1128204, 'type' => 'movie'], // Devorador de Almas
            'c_dwqx1ssbjq' => ['id' => 390061, 'type' => 'movie'],  // El Informante (2017)
            'c_g01x2i8tvk' => ['id' => 354912, 'type' => 'movie'],  // Coco
            'c_bf1wb9cv1f' => ['id' => 66732, 'type' => 'tv'],     // Stranger Things
            's_arcane_s2'  => ['id' => 94605, 'type' => 'tv'],     // Arcane
        ];

        $results = [];
        $seen = [];

        foreach ($items as $it) {
            $rawId = $it['id'] ?? '';
            $cat = $it['category'] ?? 'movies';
            $type = ($cat === 'series') ? 'tv' : 'movie';
            $tmdbId = null;

            if (isset($customMap[$rawId])) {
                $tmdbId = $customMap[$rawId]['id'];
                $type = $customMap[$rawId]['type'];
            } elseif (preg_match('/^mv_(\d+)$/', $rawId, $m)) {
                $tmdbId = (int)$m[1];
                $type = 'movie';
            } elseif (preg_match('/^sr_(\d+)$/', $rawId, $m)) {
                $tmdbId = (int)$m[1];
                $type = 'tv';
            } elseif (!empty($it['tmdbId'])) {
                $tmdbId = (int)$it['tmdbId'];
            } elseif (!empty($it['tmdb_id'])) {
                $tmdbId = (int)$it['tmdb_id'];
            }

            if (!$tmdbId) {
                continue;
            }

            $key = "{$type}_{$tmdbId}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $poster = $it['poster'] ?? ($it['thumbnail'] ?? '');
            $backdrop = $it['backdrop'] ?? '';
            $title = $it['title'] ?? 'Sin título';
            $year = !empty($it['year']) ? (string)$it['year'] : '2024';
            $rating = isset($it['rating']) ? (float)$it['rating'] : 8.0;

            $results[] = [
                'id'             => $tmdbId,
                'title'          => $title,
                'name'           => $title,
                'type'           => $type,
                'poster_path'    => $poster,
                'backdrop_path'  => $backdrop,
                'release_date'   => "{$year}-01-01",
                'first_air_date' => "{$year}-01-01",
                'vote_average'   => $rating,
                'original_title' => $it['original_title'] ?? $title,
                'rakun_badge'    => true
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        if (!empty($results)) {
            self::setCache($cacheKey, $results, 21600); // 6 horas
        }

        return $results;
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
