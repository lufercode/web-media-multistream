<?php
declare(strict_types=1);

/**
 * StreamMedia Stremio Addon Protocol Engine (PHP Nativo para InfinityFree)
 * Permite ejecutar el Addon de Stremio al 100% en InfinityFree o Apache sin depender de Node.js ni Render.
 * 
 * Soporta:
 * - GET /manifest.json (o api/stremio.php?action=manifest)
 * - GET /catalog/:type/:id.json (o api/stremio.php?action=catalog&type=...&id=...)
 * - GET /stream/:type/:id.json (o api/stremio.php?action=stream&type=...&id=...)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/ProviderManager.php';
require_once __DIR__ . '/../lib/resolvers/ResolverManager.php';

$action = $_GET['action'] ?? 'manifest';
$type = strtolower($_GET['type'] ?? 'movie');
if ($type === 'tv') $type = 'series';
$id = $_GET['id'] ?? '';
$config = $_GET['config'] ?? 'default';

// =========================================================================
// 1. MANIFEST ENDPOINT
// =========================================================================
if ($action === 'manifest') {
    header('Cache-Control: public, max-age=3600');
    echo json_encode([
        'id' => 'org.streammedia.multistream.latino',
        'version' => '2.8.0',
        'name' => 'StreamMedia Latino & Multi-Scraper (InfinityFree)',
        'description' => 'Addon Multi-Fuente Autónomo (PelisPlus, Cinecalidad, Cuevana, AnimeFLV, JKAnime, LatAnime, LaMovie, SeriesKao, Movie+). Streaming HLS Directo y Torrents P2P para Películas, Series y Anime.',
        'logo' => 'https://cdn-icons-png.flaticon.com/512/2503/2503508.png',
        'background' => 'https://images.unsplash.com/photo-1574375927938-d5a98e8ffe85?auto=format&fit=crop&w=1400&q=80',
        'resources' => ['catalog', 'stream'],
        'types' => ['movie', 'series', 'anime'],
        'idPrefixes' => ['tt', 'tmdb:'],
        'catalogs' => [
            [
                'id' => 'streammedia_trending_movies',
                'type' => 'movie',
                'name' => '🇲🇽 Películas en Español (Trending)',
                'extra' => [['name' => 'skip', 'isRequired' => false]]
            ],
            [
                'id' => 'streammedia_trending_series',
                'type' => 'series',
                'name' => '🇲🇽 Series en Español (Trending)',
                'extra' => [['name' => 'skip', 'isRequired' => false]]
            ],
            [
                'id' => 'streammedia_anime_latino',
                'type' => 'anime',
                'name' => '🐉 Anime en Español Latino (Trending)',
                'extra' => [['name' => 'skip', 'isRequired' => false]]
            ]
        ],
        'behaviorHints' => [
            'configurable' => false,
            'configurationRequired' => false
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// 2. CATALOG ENDPOINT
// =========================================================================
if ($action === 'catalog') {
    header('Cache-Control: public, max-age=1800');
    $skip = isset($_GET['skip']) ? max(0, (int)$_GET['skip']) : 0;
    $page = (int)floor($skip / 20) + 1;

    $endpoint = 'discover/movie';
    $params = [
        'sort_by' => 'popularity.desc',
        'language' => 'es-MX',
        'page' => $page,
        'with_original_language' => 'es',
        'region' => 'MX'
    ];

    if ($id === 'streammedia_trending_series') {
        $endpoint = 'discover/tv';
    } elseif ($id === 'streammedia_anime_latino') {
        $endpoint = 'discover/tv';
        $params['with_genres'] = 16;
        $params['with_origin_country'] = 'JP';
        unset($params['with_original_language'], $params['region']);
    }

    $data = get_tmdb_data($endpoint, $params);
    $results = $data['results'] ?? [];
    $metas = [];

    foreach (array_slice($results, 0, 20) as $item) {
        $tmdbId = $item['id'] ?? null;
        if (!$tmdbId) continue;

        $title = $item['title'] ?? $item['name'] ?? '';
        $poster = !empty($item['poster_path']) ? "https://image.tmdb.org/t/p/w500{$item['poster_path']}" : null;
        $background = !empty($item['backdrop_path']) ? "https://image.tmdb.org/t/p/w1280{$item['backdrop_path']}" : null;
        $releaseDate = $item['release_date'] ?? $item['first_air_date'] ?? '';
        $year = !empty($releaseDate) ? substr($releaseDate, 0, 4) : '';

        $metas[] = [
            'id' => "tmdb:{$tmdbId}",
            'type' => $type,
            'name' => $title,
            'poster' => $poster,
            'background' => $background,
            'description' => $item['overview'] ?? '',
            'releaseInfo' => $year,
            'imdbRating' => isset($item['vote_average']) ? (string)round((float)$item['vote_average'], 1) : null
        ];
    }

    echo json_encode(['metas' => $metas], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// 3. STREAM RESOLUTION ENDPOINT
// =========================================================================
if ($action === 'stream') {
    header('Cache-Control: public, max-age=300');
    $rawId = $id;
    if (empty($rawId)) {
        echo json_encode(['streams' => []]);
        exit;
    }

    $parts = explode(':', str_ireplace('.json', '', $rawId));
    $imdbId = null;
    $tmdbId = null;
    $season = 1;
    $episode = 1;

    if ($parts[0] === 'tmdb') {
        $tmdbId = isset($parts[1]) ? (int)$parts[1] : null;
        $season = isset($parts[2]) ? (int)$parts[2] : 1;
        $episode = isset($parts[3]) ? (int)$parts[3] : 1;
    } else {
        $imdbId = $parts[0];
        $season = isset($parts[1]) ? (int)$parts[1] : 1;
        $episode = isset($parts[2]) ? (int)$parts[2] : 1;
    }

    $title = '';
    $year = null;
    $mediaType = ($type === 'movie') ? 'movie' : 'tv';

    if ($imdbId) {
        $findData = get_tmdb_data("find/{$imdbId}", ['external_source' => 'imdb_id']);
        $candidates = array_merge(
            $findData['movie_results'] ?? [],
            $findData['tv_results'] ?? [],
            $findData['tv_episode_results'] ?? []
        );
        if (!empty($candidates)) {
            $c = $candidates[0];
            $title = $c['title'] ?? $c['name'] ?? '';
            $tmdbId = (int)$c['id'];
            $rDate = $c['release_date'] ?? $c['first_air_date'] ?? '';
            if (!empty($rDate)) $year = substr($rDate, 0, 4);
            if (!empty($c['media_type'])) $mediaType = $c['media_type'];
        }
    } elseif ($tmdbId) {
        $tEndpoint = ($mediaType === 'movie') ? "movie/{$tmdbId}" : "tv/{$tmdbId}";
        $tData = get_tmdb_data($tEndpoint);
        if (!empty($tData)) {
            $title = $tData['title'] ?? $tData['name'] ?? '';
            $rDate = $tData['release_date'] ?? $tData['first_air_date'] ?? '';
            if (!empty($rDate)) $year = substr($rDate, 0, 4);
        }
    }

    if (empty($title)) {
        echo json_encode(['streams' => []]);
        exit;
    }

    $manager = new ProviderManager();
    $rawStreams = $manager->getStreamsForMedia(
        $title,
        $year,
        $mediaType === 'tv' ? $season : null,
        $mediaType === 'tv' ? $episode : null,
        $tmdbId,
        $mediaType
    );

    $resolverManager = new ResolverManager();
    $stremioStreams = [];
    $seenHashes = [];

    foreach ($rawStreams as $s) {
        $url = $s['url'] ?? '';
        if (empty($url)) continue;

        $provName = $s['provider_name'] ?? 'Proveedor';
        $quality = $s['quality'] ?? 'HD';
        $lang = $s['language'] ?? 'Latino';
        $titleLabel = $s['title'] ?? $title;

        // Caso A: Torrent / Magnet (P2P Nativo de Stremio)
        if (str_starts_with($url, 'magnet:?')) {
            $infoHash = null;
            if (preg_match('/xt=urn(?::|%3a)btih(?::|%3a)([a-zA-Z0-9]+)/i', $url, $m)) {
                $infoHash = strtolower($m[1]);
            }
            if (!$infoHash) continue;

            $fileIdx = null;
            if (preg_match('/[?&](?:fileIdx|so)=(\d+)/i', $url, $fm)) {
                $fileIdx = (int)$fm[1];
            }

            $dedupKey = ($mediaType === 'movie' || $fileIdx === null) ? $infoHash : "{$infoHash}:{$fileIdx}";
            if (isset($seenHashes[$dedupKey])) continue;
            $seenHashes[$dedupKey] = true;

            $sizeStr = !empty($s['size']) ? " • 💾 {$s['size']}" : '';
            $stremioStreams[] = [
                'name' => "[P2P] {$provName}",
                'title' => "{$titleLabel}\n💾 {$quality} • 🇲🇽 {$lang}{$sizeStr}",
                'infoHash' => $infoHash,
                'fileIdx' => $fileIdx,
                'behaviorHints' => [
                    'bingeGroup' => "streammedia-p2p-{$infoHash}"
                ]
            ];
            continue;
        }

        // Caso B: Direct Stream o Embed Web
        $resolved = $resolverManager->resolve($url);
        if (!empty($resolved['is_available']) === false && isset($resolved['is_available'])) {
            continue;
        }

        $directUrl = $resolved['stream_url'] ?? $resolved['embed_url'] ?? $url;
        if (empty($directUrl)) continue;

        $stremioStreams[] = [
            'name' => "[HLS/MP4] {$provName}",
            'title' => "{$titleLabel}\n⚡ {$quality} • 🇲🇽 {$lang}",
            'url' => $directUrl,
            'behaviorHints' => [
                'notWebReady' => false
            ]
        ];
    }

    echo json_encode(['streams' => $stremioStreams], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
