<?php
/**
 * Rakun Cloud CDN API & Streaming Proxy
 * Supports Partial Content / Range requests (HTTP 206), stream signing,
 * and content lookups.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/RakunService.php';

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
        case 'stream':
            $targetUrl = $_GET['url'] ?? '';
            if (empty($targetUrl)) {
                http_response_code(400);
                echo 'URL requerida';
                exit;
            }

            // Validar que sea un dominio de Rakun
            $parsedHost = parse_url($targetUrl, PHP_URL_HOST) ?? '';
            $allowedHosts = ['cdn.rakun.lat', 'rakun.b-cdn.net', 'b-cdn.net', 'cdn.rakun.site', 'rakun.lat'];
            $isAllowed = false;
            foreach ($allowedHosts as $h) {
                if ($parsedHost === $h || str_ends_with($parsedHost, '.' . $h)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                http_response_code(403);
                echo 'Host no permitido';
                exit;
            }

            // Asegurar token activo
            $signedUrl = RakunService::signUrl($targetUrl);

            // Iniciar streaming proxy con soporte de Range (HTTP 206)
            $ch = curl_init($signedUrl);
            $clientHeaders = [
                'User-Agent: ' . RakunService::USER_AGENT,
                'Accept: */*'
            ];

            if (isset($_SERVER['HTTP_RANGE'])) {
                $clientHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
            }

            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => $clientHeaders,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_BUFFERSIZE     => 131072
            ]);

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

                if ($isRedirect) return $len;

                // Forzar inline para reproducción en navegador
                if (stripos($trimmed, 'Content-Disposition:') === 0) {
                    header('Content-Disposition: inline');
                    return $len;
                }

                if (stripos($trimmed, 'Content-Type:') === 0) {
                    // Forzar video/mp4 si viene como octet-stream o matroska para compatibilidad total con HTML5
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

        case 'search':
            $q = trim((string)($_GET['q'] ?? ($_GET['title'] ?? '')));
            $tmdbId = isset($_GET['tmdb']) ? (int)$_GET['tmdb'] : null;
            $type = (string)($_GET['type'] ?? 'movie');
            $year = isset($_GET['year']) ? (string)$_GET['year'] : null;

            if ($type === 'tv' || $type === 'series') {
                $season = isset($_GET['season']) ? (int)$_GET['season'] : 1;
                $episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;
                $streams = RakunService::searchSeries($q, $season, $episode, $tmdbId);
            } else {
                $streams = RakunService::searchMovie($q, $year, $tmdbId);
            }

            echo json_encode([
                'status'  => 'success',
                'query'   => $q,
                'tmdb_id' => $tmdbId,
                'type'    => $type,
                'streams' => $streams
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'sign':
            $targetUrl = (string)($_GET['url'] ?? '');
            if (empty($targetUrl)) {
                echo json_encode(['status' => 'error', 'message' => 'url requerida']);
                exit;
            }
            $signed = RakunService::signUrl($targetUrl);
            echo json_encode([
                'status'     => 'success',
                'signed_url' => $signed
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'stats':
            $catalog = RakunService::getLocalCatalog();
            echo json_encode([
                'status'         => 'success',
                'total_cached'   => count($catalog),
                'upstream_api'   => RakunService::UPSTREAM_API,
                'cache_path'     => file_exists(__DIR__ . '/../data/rakun/rakun_core_catalog.json') ? 'data/rakun/rakun_core_catalog.json' : 'c:/laragon/www/rakun/assets/rakun_core_catalog.json'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error'  => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
