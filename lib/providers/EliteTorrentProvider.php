<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class EliteTorrentProvider implements ProviderInterface
{
    private array $hosts = [
        'https://elitetorrent.app',
        'https://www.elitetorrent.com'
    ];

    public function getId(): string
    {
        return 'elitetorrent';
    }

    public function getName(): string
    {
        return 'EliteTorrent (Torrents HD)';
    }

    public function getType(): string
    {
        return 'torrent';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['elitetorrent']['enabled'] ?? true;
    }

    /**
     * Decodifica enlaces protegidos de acortame-esto (Base64 multinivel + ROT13)
     */
    private function decodeAcortameEsto(string $encoded): ?string
    {
        $curr = $encoded;
        for ($i = 0; $i < 8; $i++) {
            $decoded = base64_decode($curr);
            if (!$decoded) break;
            $curr = $decoded;
            $rot = str_rot13($curr);
            if (strpos($rot, 'magnet:?') === 0) {
                return $rot;
            }
            if (strpos($curr, 'magnet:?') === 0) {
                return $curr;
            }
            if (stripos($rot, '.torrent') !== false || strpos($rot, '/wp-content/') === 0) {
                return 'https://www.elitetorrent.com' . $rot;
            }
            if (stripos($curr, '.torrent') !== false || strpos($curr, '/wp-content/') === 0) {
                return 'https://www.elitetorrent.com' . $curr;
            }
        }
        return null;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) return [];

        $results = [];
        $searchUrl = $this->hosts[0] . '/?s=' . urlencode($title);

        $html = http_get($searchUrl, [
            'timeout' => 7,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer' => $this->hosts[0] . '/'
            ]
        ]);

        if (!$html || strlen($html) < 500) return [];

        // Buscar enlaces en resultados de búsqueda
        if (!preg_match_all('/<div class="meta">.*?<a href="([^"]+)".*?title="([^"]+)".*?<\/div>/is', $html, $matches)) {
            return [];
        }

        $candidates = [];
        foreach ($matches[1] as $idx => $movieUrl) {
            $rawTitle = html_entity_decode($matches[2][$idx], ENT_QUOTES, 'UTF-8');
            
            // Extraer año si está en el título o slug
            $candYear = null;
            if (preg_match('/[\(\[]?\b(19\d{2}|20\d{2})\b[\)\]]?/', $rawTitle . ' ' . $movieUrl, $ym)) {
                $candYear = $ym[1];
            }

            // Limpiar calidades y etiquetas para comparación de título
            $cleanCandTitle = preg_replace('/\(.*?\)|\[.*?\]/', ' ', $rawTitle);
            $cleanCandTitle = preg_replace('/[:\-–—·]/', ' ', $cleanCandTitle);
            $cleanCandTitle = trim($cleanCandTitle);

            if (is_strict_title_match($title, $cleanCandTitle, $year, $candYear)) {
                $candidates[] = [
                    'url' => $movieUrl,
                    'title' => $rawTitle,
                    'year' => $candYear
                ];
            }
        }

        foreach (array_slice($candidates, 0, 4) as $cand) {
            if (connection_aborted()) exit;

            $detailHtml = http_get($cand['url'], [
                'timeout' => 7,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Referer' => $searchUrl
                ]
            ]);

            if (!$detailHtml) continue;

            // Extraer magnet link (ya sea directo o mediante acortame-esto)
            $magnet = null;

            // 1. Enlace directo magnet
            if (preg_match('/href=["\'](magnet:\?[^"\']+)["\']/i', $detailHtml, $mm)) {
                $magnet = html_entity_decode($mm[1]);
            }
            // 2. Enlace protegido acortame-esto (buscar magnet prioritariamente)
            elseif (preg_match_all('/href=["\']https?:\/\/acortame-esto\.com\/s\.php\?i=([^"\']+)["\']/i', $detailHtml, $am)) {
                foreach ($am[1] as $enc) {
                    $dec = $this->decodeAcortameEsto($enc);
                    if ($dec) {
                        $magnet = $dec;
                        if (strpos($magnet, 'magnet:?') === 0) {
                            break;
                        }
                    }
                }
            }

            if (!$magnet || (strpos($magnet, 'magnet:?') !== 0 && strpos($magnet, 'http') !== 0)) continue;

            // Extraer calidad
            $quality = '1080p HD';
            if (preg_match('/(4k|2160p|uhd)/i', $cand['title'] . ' ' . $detailHtml)) {
                $quality = '4K UHD';
            } elseif (preg_match('/(microhd|1080p)/i', $cand['title'] . ' ' . $detailHtml)) {
                $quality = '1080p MicroHD';
            } elseif (preg_match('/(720p|hdrip)/i', $cand['title'] . ' ' . $detailHtml)) {
                $quality = '720p HDRip';
            } elseif (preg_match('/(dvdrip)/i', $cand['title'] . ' ' . $detailHtml)) {
                $quality = 'DVDRip';
            }

            // Extraer peso / tamaño
            $size = null;
            if (preg_match('/Tamaño:?\s*<strong>(.*?)<\/strong>/is', $detailHtml, $sz)) {
                $size = trim(strip_tags($sz[1]));
            }

            // Extraer idioma
            $lang = 'Castellano / Latino';
            if (preg_match('/Idioma:?\s*<strong>(.*?)<\/strong>/is', $detailHtml, $lg)) {
                $lang = trim(strip_tags($lg[1]));
            } elseif (stripos($cand['title'], 'latino') !== false) {
                $lang = 'Español Latino';
            } elseif (stripos($cand['title'], 'castellano') !== false) {
                $lang = 'Castellano';
            } elseif (stripos($cand['title'], 'vose') !== false) {
                $lang = 'VOSE (Subtitulado)';
            }

            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => 'EliteTorrent (Torrent)',
                'type' => 'torrent',
                'title' => $cand['title'],
                'server' => 'BitTorrent Magnet',
                'quality' => $quality,
                'language' => $lang,
                'url' => $magnet,
                'size' => $size
            ];
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) return [];

        $results = [];
        $query = "{$title} {$season}x{$episode}";
        $searchUrl = $this->hosts[0] . '/?s=' . urlencode($query);

        $html = http_get($searchUrl, [
            'timeout' => 7,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$html || strlen($html) < 500) return [];

        if (!preg_match_all('/<div class="meta">.*?<a href="([^"]+)".*?title="([^"]+)".*?<\/div>/is', $html, $matches)) {
            return [];
        }

        foreach (array_slice($matches[1], 0, 3) as $idx => $epUrl) {
            $epTitle = html_entity_decode($matches[2][$idx], ENT_QUOTES, 'UTF-8');

            $detailHtml = http_get($epUrl, ['timeout' => 7]);
            if (!$detailHtml) continue;

            $magnet = null;
            if (preg_match('/href=["\'](magnet:\?[^"\']+)["\']/i', $detailHtml, $mm)) {
                $magnet = html_entity_decode($mm[1]);
            } elseif (preg_match('/href=["\']https?:\/\/acortame-esto\.com\/s\.php\?i=([^"\']+)["\']/i', $detailHtml, $am)) {
                $magnet = $this->decodeAcortameEsto($am[1]);
            }

            if (!$magnet || strpos($magnet, 'magnet:?') !== 0) continue;

            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => 'EliteTorrent (Torrent)',
                'type' => 'torrent',
                'title' => $epTitle,
                'server' => 'BitTorrent Magnet',
                'quality' => '720p / 1080p HDTV',
                'language' => 'Castellano / Latino',
                'url' => $magnet,
                'size' => null
            ];
        }

        return $results;
    }
}
