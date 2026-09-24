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

        // Generar variantes de búsqueda: Priorizar Latino y Dual
        $queries = [
            $clean_title . ' Latino',
            $clean_title . ' Dual'
        ];
        if ($year) {
            $queries[] = "{$clean_title} {$year}";
        }
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
                    'is_latino' => (stripos($language, 'Latino') !== false)
                ];

                if (count($results) >= 12) break 2;
            }
        }

        // Ordenar: primero los que tienen audio Latino/Dual, luego por cantidad de seeders
        usort($results, function ($a, $b) {
            if ($a['is_latino'] !== $b['is_latino']) {
                return $b['is_latino'] ? 1 : -1;
            }
            return $b['seeders'] <=> $a['seeders'];
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
                    'server' => 'BitTorrent Magnet',
                    'quality' => $quality,
                    'language' => $language,
                    'url' => $magnet,
                    'size' => $size,
                    'seeders' => $seeders,
                    'leechers' => $leechers,
                    'is_latino' => (stripos($language, 'Latino') !== false)
                ];

                if (count($results) >= 12) break 2;
            }
        }

        // Ordenar: primero Latino/Dual, luego por seeds
        usort($results, function ($a, $b) {
            if ($a['is_latino'] !== $b['is_latino']) {
                return $b['is_latino'] ? 1 : -1;
            }
            return $b['seeders'] <=> $a['seeders'];
        });

        return array_map(function ($r) {
            unset($r['is_latino']);
            return $r;
        }, array_slice($results, 0, 8));
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
        if (stripos($title, 'Latino') !== false || stripos($title, 'Español Latino') !== false) {
            if (stripos($title, 'Dual') !== false || stripos($title, 'ENG') !== false || stripos($title, 'English') !== false) {
                return 'Español Latino (Dual)';
            }
            return 'Español Latino';
        }
        if (stripos($title, 'Castellano') !== false || stripos($title, 'Spanish') !== false) {
            return 'Español Castellano';
        }
        if (stripos($title, 'Dual') !== false || stripos($title, 'Multi') !== false) {
            return 'Dual Audio / Multi';
        }
        return 'Inglés / VO';
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

