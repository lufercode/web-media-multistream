<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class PirateBayProvider implements ProviderInterface
{
    private string $apiUrl = 'https://apibay.org/q.php';

    private array $trackers = [
        'udp://tracker.opentrackr.org:1337/announce',
        'udp://open.stealth.si:80/announce',
        'udp://tracker.torrent.eu.org:451/announce',
        'udp://explodie.org:6969/announce',
        'udp://open.demonii.com:1337/announce',
        'udp://tracker.openbittorrent.com:80',
        'udp://exodus.desync.com:6969/announce'
    ];

    public function getId(): string
    {
        return 'thepiratebay';
    }

    public function getName(): string
    {
        return 'The Pirate Bay (Dual Latino / 4K / HD)';
    }

    public function getType(): string
    {
        return 'torrent';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['thepiratebay']['enabled'] ?? true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) return [];

        $clean_title = trim($title);
        if (empty($clean_title)) return [];

        // Generar variantes de búsqueda: Priorizar con año cuando está disponible para evitar contaminaciones
        $queries = [];
        if ($year) {
            $queries[] = "{$clean_title} {$year} Latino";
            $queries[] = "{$clean_title} {$year} Dual";
            $queries[] = "{$clean_title} {$year} Lat";
            $queries[] = "{$clean_title} {$year}";
        }
        $queries[] = $clean_title . ' Latino';
        $queries[] = $clean_title . ' Dual';
        $queries[] = $clean_title . ' Lat';
        $queries[] = $clean_title;

        $results = [];
        $seen_hashes = [];

        foreach (array_unique($queries) as $q) {
            if (connection_aborted()) exit;

            $items = $this->queryApi($q);
            if (empty($items)) continue;

            foreach ($items as $item) {
                $hash = strtoupper(trim($item['info_hash'] ?? ''));
                if (empty($hash) || isset($seen_hashes[$hash])) continue;

                $raw_name = html_entity_decode($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
                if (empty($raw_name)) continue;

                // Descartar episodios de series en búsqueda de películas
                if (preg_match('/(?:s\d{1,2}e\d{1,2}|\bseason\s*\d+|\btemporada\s*\d+|\bcapitulo\s*\d+)/i', $raw_name)) {
                    continue;
                }

                // Parsear título y año del release crudo
                $parsed = parse_torrent_release_name($raw_name);
                $cand_name = $parsed['title'];
                $cand_year = $parsed['year'];

                // Validación estricta: asegurar que coincida con el título y año (descartando secuelas y años distintos)
                if (!is_strict_title_match($clean_title, $cand_name, $year, $cand_year) &&
                    !is_strict_title_match($clean_title, $raw_name, $year, $cand_year)) {
                    continue;
                }

                $seeders = (int)($item['seeders'] ?? 0);
                $leechers = (int)($item['leechers'] ?? 0);
                $bytes = (float)($item['size'] ?? 0);
                $size = $this->formatBytes($bytes);

                $language = $this->detectLanguage($raw_name);
                $quality = $this->detectQuality($raw_name);
                $magnet = $this->buildMagnet($hash, $raw_name);

                $seen_hashes[$hash] = true;

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'The Pirate Bay',
                    'type' => 'torrent',
                    'title' => $raw_name,
                    'server' => 'BitTorrent Magnet',
                    'quality' => $quality,
                    'language' => $language,
                    'url' => $magnet,
                    'size' => $size,
                    'seeders' => $seeders,
                    'leechers' => $leechers,
                    'is_latino' => is_latino_audio($raw_name)
                ];

                if (count($results) >= 12) break 2;
            }
        }

        if (empty($results) && $tmdb_id) {
            $results = $this->fetchTpbMirrorFallback('movie', $tmdb_id, $clean_title, null, null);
        }

        // Ordenar: primero los que tienen audio Latino/Dual, luego por cantidad de seeders
        usort($results, function ($a, $b) {
            if (($a['is_latino'] ?? false) !== ($b['is_latino'] ?? false)) {
                return ($b['is_latino'] ?? false) ? 1 : -1;
            }
            return ($b['seeders'] ?? 0) <=> ($a['seeders'] ?? 0);
        });

        // Limpiar clave temporal
        return array_map(function ($r) {
            unset($r['is_latino']);
            return $r;
        }, array_slice($results, 0, 8));
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) return [];

        $clean_title = trim($title);
        if (empty($clean_title)) return [];

        $s_padded = sprintf('S%02dE%02d', $season, $episode);
        $queries = [
            "{$clean_title} {$s_padded} Latino",
            "{$clean_title} {$s_padded} Multi",
            "{$clean_title} {$s_padded} Dual",
            "{$clean_title} {$s_padded}"
        ];

        if ($season === 1) {
            $ep_padded = sprintf('%02d', $episode);
            $queries[] = "{$clean_title} {$ep_padded} Latino";
            $queries[] = "{$clean_title} {$ep_padded}";
        }

        $results = [];
        $seen_hashes = [];

        foreach (array_unique($queries) as $q) {
            if (connection_aborted()) exit;

            $items = $this->queryApi($q);
            if (empty($items)) continue;

            foreach ($items as $item) {
                $hash = strtoupper(trim($item['info_hash'] ?? ''));
                if (empty($hash) || isset($seen_hashes[$hash])) continue;

                $raw_name = html_entity_decode($item['name'] ?? '', ENT_QUOTES, 'UTF-8');
                if (empty($raw_name)) continue;

                // Validar que el título pertenezca a la serie buscada (permitiendo subtítulos de temporada como Science Future)
                $parsed = parse_torrent_release_name($raw_name);
                $title_part = preg_replace('/s\d{1,2}e\d{1,2}.*/i', '', $raw_name);
                if (!is_strict_title_match($clean_title, $parsed['title']) &&
                    !is_strict_title_match($clean_title, $title_part) &&
                    !is_anime_title_match($clean_title, $title_part)) {
                    continue;
                }

                // Validar que coincida con la temporada y episodio
                $cand_lower = strtolower($raw_name);
                $wrong_season = false;
                for ($s = 1; $s <= 15; $s++) {
                    if ($s === $season) continue;
                    $pat = sprintf('/(?:\b|[^a-z0-9])s0?%d(?:\b|[^a-z0-9]|e)|season\s*0?%d\b/i', $s, $s);
                    if (preg_match($pat, $cand_lower)) {
                        $wrong_season = true;
                        break;
                    }
                }
                if ($wrong_season) continue;

                $ep_match = false;
                if (stripos($cand_lower, strtolower($s_padded)) !== false) {
                    $ep_match = true;
                } elseif (preg_match('/(?:[\s_\-\.\[]|\b)(?:e|ep|episode|\#)?' . sprintf('%02d', $episode) . '(?:v\d+)?(?:[\s_\-\.\]]|\b)/i', $raw_name)) {
                    $ep_match = true;
                }

                if (!$ep_match) continue;

                $seeders = (int)($item['seeders'] ?? 0);
                $leechers = (int)($item['leechers'] ?? 0);
                $bytes = (float)($item['size'] ?? 0);
                $size = $this->formatBytes($bytes);

                $language = $this->detectLanguage($raw_name);
                $quality = $this->detectQuality($raw_name);
                $magnet = $this->buildMagnet($hash, $raw_name);

                $seen_hashes[$hash] = true;

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'The Pirate Bay',
                    'type' => 'torrent',
                    'title' => $raw_name,
                    'server' => '⚙️ ThePirateBay',
                    'tracker' => 'ThePirateBay',
                    'quality' => $quality,
                    'language' => $language,
                    'url' => $magnet,
                    'size' => $size,
                    'seeds' => $seeders,
                    'seeders' => $seeders,
                    'leechers' => $leechers,
                    'is_latino' => is_latino_audio($raw_name)
                ];

                if (count($results) >= 12) break 2;
            }
        }

        if (empty($results) && $tmdb_id) {
            $results = $this->fetchTpbMirrorFallback('tv', $tmdb_id, $clean_title, $season, $episode);
        }

        // Ordenar: primero Latino/Dual, luego por seeds
        usort($results, function ($a, $b) {
            if (($a['is_latino'] ?? false) !== ($b['is_latino'] ?? false)) {
                return ($b['is_latino'] ?? false) ? 1 : -1;
            }
            return ($b['seeders'] ?? 0) <=> ($a['seeders'] ?? 0);
        });

        return array_map(function ($r) {
            unset($r['is_latino']);
            return $r;
        }, array_slice($results, 0, 10));
    }

    private function fetchTpbMirrorFallback(string $type, int $tmdb_id, string $fallbackTitle, ?int $season, ?int $episode): array
    {
        $imdb_id = get_tmdb_imdb_id($tmdb_id, $type);
        if (!$imdb_id) return [];

        $stremioType = ($type === 'movie') ? 'movie' : 'series';
        $streamId = ($type === 'movie') ? $imdb_id : "{$imdb_id}:{$season}:{$episode}";
        $url = "https://torrentio.strem.fun/providers=thepiratebay/stream/{$stremioType}/" . rawurlencode($streamId) . ".json";

        $raw = http_get($url, ['timeout' => 5, 'headers' => ['Accept' => 'application/json']]);
        if (!$raw) return [];

        $data = json_decode($raw, true);
        if (empty($data['streams']) || !is_array($data['streams'])) return [];

        $results = [];
        foreach ($data['streams'] as $s) {
            $hash = strtoupper(trim($s['infoHash'] ?? ''));
            if (empty($hash)) continue;
            $rawTitle = trim($s['title'] ?? '');
            $lines = array_values(array_filter(array_map('trim', explode("\n", $rawTitle))));
            $releaseName = $lines[0] ?? $fallbackTitle;

            $seeders = 0;
            if (preg_match('/👤\s*(\d+)/u', $rawTitle, $mSeeds)) {
                $seeders = (int)$mSeeds[1];
            }
            $size = '';
            if (preg_match('/💾\s*([0-9.,]+\s*(?:GB|MB|TB|KB))/iu', $rawTitle, $mSize)) {
                $size = trim($mSize[1]);
            }

            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => 'The Pirate Bay',
                'type' => 'torrent',
                'title' => $releaseName,
                'server' => 'BitTorrent Magnet',
                'tracker' => 'ThePirateBay',
                'quality' => $this->detectQuality($releaseName),
                'language' => $this->detectLanguage($rawTitle),
                'url' => $this->buildMagnet($hash, $releaseName),
                'size' => $size,
                'seeds' => $seeders,
                'seeders' => $seeders,
                'leechers' => 0,
                'is_latino' => is_latino_audio($rawTitle)
            ];
        }
        return $results;
    }

    private function queryApi(string $query): array
    {
        $url = $this->apiUrl . '?q=' . urlencode(trim($query));
        $json = http_get($url, [
            'timeout' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]
        ]);

        if (!$json) return [];

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data)) return [];

        // Comprobar si devolvió respuesta de "sin resultados"
        if (isset($data[0]['name']) && $data[0]['name'] === 'No results returned') {
            return [];
        }

        return $data;
    }

    private function buildMagnet(string $info_hash, string $name): string
    {
        $dn = rawurlencode($name);
        $magnet = "magnet:?xt=urn:btih:{$info_hash}&dn={$dn}";
        foreach ($this->trackers as $tr) {
            $magnet .= '&tr=' . rawurlencode($tr);
        }
        return $magnet;
    }

    private function detectQuality(string $title): string
    {
        if (preg_match('/(2160p|4k|uhd)/i', $title)) return '4K UHD';
        if (preg_match('/(1080p|bluray|remux)/i', $title)) return '1080p Full HD';
        if (preg_match('/720p/i', $title)) return '720p HD';
        if (preg_match('/480p/i', $title)) return '480p SD';
        return '1080p Full HD';
    }

    private function detectLanguage(string $title): string
    {
        return detect_release_language($title);
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes <= 0) return '';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, ($i >= 3 ? 2 : 1)) . ' ' . $units[$i];
    }
}

