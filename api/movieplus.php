<?php
/**
 * Movie+ Integrated API (Local Catalog, Fast SQLite Index, Remote Series & Streaming Proxy)
 * Fully compatible with original Movie+ endpoints and web2-ftp architecture.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/MoviePlusService.php';

$action = (string)($_GET['action'] ?? 'search');

if ($action !== 'stream') {
    header('Content-Type: application/json; charset=utf-8');
}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');
header('Access-Control-Expose-Headers: Content-Range, Accept-Ranges, Content-Length');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

try {
    switch ($action) {
        case 'stats':
            $db = MoviePlusService::getDb();
            $stmtM = $db->query('SELECT COUNT(*) as cnt FROM movies');
            $moviesCount = (int)($stmtM->fetch()['cnt'] ?? 0);

            $stmtS = $db->query('SELECT COUNT(*) as cnt FROM series');
            $seriesCount = (int)($stmtS->fetch()['cnt'] ?? 0);

            $gzPath = MoviePlusService::getGzCatalogPath();
            $dbPath = MoviePlusService::getDbPath();

            echo json_encode([
                'success'       => true,
                'movies_count'  => $moviesCount,
                'series_count'  => $seriesCount,
                'total_titles'  => $moviesCount + $seriesCount,
                'db_size_mb'    => file_exists($dbPath) ? round(filesize($dbPath) / (1024 * 1024), 2) : 0,
                'gz_size_mb'    => file_exists($gzPath) ? round(filesize($gzPath) / (1024 * 1024), 2) : 0,
                'cache_updated' => file_exists($gzPath) ? date('Y-m-d H:i:s', filemtime($gzPath)) : null,
                'genres'        => MoviePlusService::GENRES,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'search':
            $q = trim((string)($_GET['q'] ?? ''));
            $type = (string)($_GET['type'] ?? 'all');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(60, max(1, (int)($_GET['limit'] ?? 24)));
            $offset = ($page - 1) * $limit;

            $db = MoviePlusService::getDb();
            $qNorm = MoviePlusService::normalizeTitle($q);

            $matches = [];
            $total = 0;

            if ($qNorm === '') {
                // Si no hay búsqueda, ordenar por año descendente
                if ($type === 'all' || $type === 'movies') {
                    $stmt = $db->query('SELECT data_json FROM movies ORDER BY year DESC, id DESC LIMIT 50');
                    while ($row = $stmt->fetch()) {
                        $m = json_decode($row['data_json'], true);
                        $m['item_type'] = 'movie';
                        $matches[] = $m;
                    }
                }
                if ($type === 'all' || $type === 'series') {
                    $stmt = $db->query('SELECT data_json FROM series ORDER BY year DESC, id DESC LIMIT 50');
                    while ($row = $stmt->fetch()) {
                        $s = json_decode($row['data_json'], true);
                        $s['item_type'] = 'series';
                        $matches[] = $s;
                    }
                }
                $total = count($matches);
                $items = array_slice($matches, $offset, $limit);
            } else {
                $resultsM = [];
                $resultsS = [];

                if ($type === 'all' || $type === 'movies') {
                    $stmt = $db->prepare('SELECT data_json FROM movies WHERE title_norm LIKE ? LIMIT 60');
                    $stmt->execute(['%' . $qNorm . '%']);
                    while ($row = $stmt->fetch()) {
                        $m = json_decode($row['data_json'], true);
                        $m['item_type'] = 'movie';
                        $resultsM[] = $m;
                    }
                }

                if ($type === 'all' || $type === 'series') {
                    $stmt = $db->prepare('SELECT data_json FROM series WHERE title_norm LIKE ? LIMIT 60');
                    $stmt->execute(['%' . $qNorm . '%']);
                    while ($row = $stmt->fetch()) {
                        $s = json_decode($row['data_json'], true);
                        $s['item_type'] = 'series';
                        $resultsS[] = $s;
                    }
                }

                $matches = array_merge($resultsM, $resultsS);
                $total = count($matches);
                $items = array_slice($matches, $offset, $limit);
            }

            foreach ($items as &$it) {
                $genreNames = [];
                if (!empty($it['generos']) && is_array($it['generos'])) {
                    foreach ($it['generos'] as $gId) {
                        if (isset(MoviePlusService::GENRES[$gId])) {
                            $genreNames[] = MoviePlusService::GENRES[$gId];
                        }
                    }
                }
                $it['genre_names'] = $genreNames;
                $it['language'] = MoviePlusService::parseLanguageInfo($it['resolutions'] ?? $it['nameFile']);
                unset($it['urls']);
            }
            unset($it);

            echo json_encode([
                'success'     => true,
                'query'       => $q,
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
                'items'       => $items,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'movie':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $movie = MoviePlusService::getMovieByTmdbId($id);
            if (!$movie) {
                echo json_encode(['success' => false, 'error' => 'Película no encontrada en catálogo'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $genreNames = [];
            foreach ($movie['generos'] ?? [] as $gId) {
                if (isset(MoviePlusService::GENRES[$gId])) {
                    $genreNames[] = MoviePlusService::GENRES[$gId];
                }
            }
            $movie['genre_names'] = $genreNames;

            echo json_encode([
                'success' => true,
                'movie'   => $movie
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'series':
        case 'series_episodes':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $serie = MoviePlusService::getSeriesByTmdbId($id);
            if (!$serie) {
                echo json_encode(['success' => false, 'error' => 'Serie no encontrada en catálogo'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $genreNames = [];
            foreach ($serie['generos'] ?? [] as $gId) {
                if (isset(MoviePlusService::GENRES[$gId])) {
                    $genreNames[] = MoviePlusService::GENRES[$gId];
                }
            }
            $serie['genre_names'] = $genreNames;

            $remoteDoc = MoviePlusService::fetchSeriesFromRemote($id);
            $seasons = [];
            if ($remoteDoc !== null) {
                $seasons = MoviePlusService::parseSeriesSeasons($remoteDoc);
            }

            $totalEpisodes = 0;
            foreach ($seasons as $sName => $qualities) {
                foreach ($qualities as $q => $episodes) {
                    $totalEpisodes += count($episodes);
                }
            }

            echo json_encode([
                'success'        => true,
                'series'         => $serie,
                'total_seasons'  => count($seasons),
                'total_episodes' => $totalEpisodes,
                'seasons'        => $seasons,
                'source'         => $remoteDoc !== null ? 'firestore_live' : 'unavailable'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'resolve_yandex':
            $yandexUrl = trim((string)($_GET['url'] ?? ''));
            $res = MoviePlusService::resolveYandexDisk($yandexUrl);
            if ($res) {
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'error'   => 'No se pudo resolver el recurso en Yandex Disk o el enlace expiró'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'stream':
            @set_time_limit(0);
            ignore_user_abort(false);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Streaming de video con soporte de HTTP Range y Content-Disposition inline
            $url = trim((string)($_GET['url'] ?? ''));
            if ($url === '') {
                http_response_code(400);
                header('Content-Type: text/plain');
                die('Error: URL de video requerida');
            }

            $streamUrl = $url;
            if (str_contains($url, 'yadi.sk')) {
                $res = MoviePlusService::resolveYandexDisk($url);
                if ($res && !empty($res['download_url'])) {
                    $streamUrl = $res['download_url'];
                } else {
                    http_response_code(404);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => 'El enlace de Yandex Disk expiró o no se encuentra disponible.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            $reqHeaders = [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: */*'
            ];
            if (isset($_SERVER['HTTP_RANGE'])) {
                $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $streamUrl);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 128 * 1024);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $isRedirect = false;
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$isRedirect) {
                $len = strlen($header);
                $trimmed = trim($header);
                if ($trimmed === '') return $len;

                if (stripos($trimmed, 'HTTP/') === 0) {
                    if (preg_match('#HTTP/\S+\s+(\d{3})#i', $trimmed, $m)) {
                        $code = (int)$m[1];
                        if ($code >= 300 && $code < 400) {
                            $isRedirect = true;
                            return $len;
                        } else {
                            $isRedirect = false;
                            http_response_code($code);
                        }
                    }
                    header($trimmed);
                    return $len;
                }

                // Si estamos en un salto de redirección (301/302), no reenviar sus cabeceras al cliente
                if ($isRedirect) {
                    return $len;
                }

                // Forzar disposition inline para reproducción en navegador
                if (stripos($trimmed, 'Content-Disposition:') === 0) {
                    header('Content-Disposition: inline');
                    return $len;
                }

                if (stripos($trimmed, 'Content-Type:') === 0) {
                    // Si el CDN reporta video/x-matroska u octet-stream, reportar video/mp4 para máxima compatibilidad con navegadores
                    if (stripos($trimmed, 'video/x-matroska') !== false || stripos($trimmed, 'octet-stream') !== false) {
                        header('Content-Type: video/mp4');
                    } else {
                        header($trimmed);
                    }
                    return $len;
                }

                if (stripos($trimmed, 'Content-Length:') === 0 ||
                    stripos($trimmed, 'Content-Range:') === 0 ||
                    stripos($trimmed, 'Accept-Ranges:') === 0 ||
                    stripos($trimmed, 'Access-Control-Allow-Origin:') === 0) {
                    header($trimmed);
                }
                return $len;
            });

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $chunk) {
                echo $chunk;
                flush();
                return strlen($chunk);
            });

            curl_exec($ch);
            curl_close($ch);
            exit;

        case 'refresh':
            $success = MoviePlusService::buildIndexFromGz();
            $db = MoviePlusService::getDb();
            $cntM = (int)($db->query('SELECT COUNT(*) as cnt FROM movies')->fetch()['cnt'] ?? 0);
            $cntS = (int)($db->query('SELECT COUNT(*) as cnt FROM series')->fetch()['cnt'] ?? 0);

            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Catálogo e índice SQLite actualizados correctamente' : 'Fallo al actualizar catálogo',
                'movies'  => $cntM,
                'series'  => $cntS
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no soportada'], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

