<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class YTSProvider implements ProviderInterface
{
    private array $hosts = [
        'https://yts.lt',
        'https://yts.mx',
        'https://yts.rs',
        'https://yts.do'
    ];

    private array $trackers = [
        'udp://open.demonii.com:1337/announce',
        'udp://tracker.openbittorrent.com:80',
        'udp://tracker.coppersurfer.tk:6969',
        'udp://glotorrents.pw:6969/announce',
        'udp://tracker.opentrackr.org:1337/announce',
        'udp://p4p.arenabg.com:1337',
        'udp://tracker.leechers-paradise.org:6969'
    ];

    public function getId(): string
    {
        return 'yts';
    }

    public function getName(): string
    {
        return 'YTS (YIFY Torrents HD / 4K)';
    }

    public function getType(): string
    {
        return 'torrent';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['yts']['enabled'] ?? true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) return [];

        $results = [];
        $query = $title;
        if ($year) {
            $query .= " {$year}";
        }

        $movieData = null;
        foreach ($this->hosts as $host) {
            $url = "{$host}/api/v2/list_movies.json?query_term=" . urlencode($query) . "&limit=5";
            $json = http_get($url, [
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            if ($json && strlen($json) > 100) {
                $decoded = json_decode($json, true);
                if (!empty($decoded['data']['movies'])) {
                    $movieData = $decoded['data']['movies'];
                    break;
                }
            }
        }

        // Si no encontró con título + año, intentar solo con título
        if (empty($movieData) && $year) {
            foreach ($this->hosts as $host) {
                $url = "{$host}/api/v2/list_movies.json?query_term=" . urlencode($title) . "&limit=5";
                $json = http_get($url, [
                    'timeout' => 5,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ]
                ]);

                if ($json && strlen($json) > 100) {
                    $decoded = json_decode($json, true);
                    if (!empty($decoded['data']['movies'])) {
                        $movieData = $decoded['data']['movies'];
                        break;
                    }
                }
            }
        }

        if (empty($movieData)) return [];

        $trParams = '';
        foreach ($this->trackers as $tr) {
            $trParams .= '&tr=' . urlencode($tr);
        }

        foreach ($movieData as $movie) {
            $candYear = (string)($movie['year'] ?? '');

            if (!is_strict_title_match($title, $movie['title'], $year, $candYear)) {
                continue;
            }

            if (empty($movie['torrents'])) continue;

            foreach ($movie['torrents'] as $t) {
                $quality = strtoupper($t['quality'] ?? '1080p');
                $type = strtoupper($t['type'] ?? 'BLURAY');
                $hash = $t['hash'] ?? '';
                if (empty($hash)) continue;

                $cleanMovieTitle = $movie['title'] . " ({$candYear}) [{$quality}] [YTS]";
                $magnet = "magnet:?xt=urn:btih:{$hash}&dn=" . urlencode($cleanMovieTitle) . $trParams;

                $size = $t['size'] ?? null;

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'YTS (Torrent HD/4K)',
                    'type' => 'torrent',
                    'title' => $cleanMovieTitle,
                    'server' => "YTS (YIFY {$quality} {$type})",
                    'quality' => "{$quality} {$type}",
                    'language' => 'Inglés (VOSE / Multi-Sub)',
                    'url' => $magnet,
                    'size' => $size
                ];
            }
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        return []; // YTS solo indexa películas
    }
}

