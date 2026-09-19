<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class PoseidonHDProvider implements ProviderInterface
{
    private string $host = 'https://poseidonhd2.co';

    public function getId(): string
    {
        return 'poseidonhd';
    }

    public function getName(): string
    {
        return 'PoseidonHD (Películas y Series HD / Alfa)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['poseidonhd']['enabled'] ?? true;
    }

    private function normalizeString(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        $str = preg_replace('/[^a-z0-9]/', '', $str);
        return trim($str);
    }

    private function formatServer(string $cyberlocker): string
    {
        $cl = strtolower(trim($cyberlocker));
        if (strpos($cl, 'vidhide') !== false) return 'VidHide';
        if (strpos($cl, 'streamwish') !== false) return 'StreamWish';
        if (strpos($cl, 'voe') !== false) return 'Voe';
        if (strpos($cl, 'filemoon') !== false) return 'Filemoon';
        if (strpos($cl, 'fastream') !== false) return 'Fastream';
        if (strpos($cl, 'dood') !== false) return 'Doodstream';
        if (strpos($cl, 'streamtape') !== false) return 'Streamtape';
        if (strpos($cl, 'netu') !== false) return 'Netu';
        if (strpos($cl, '1fichier') !== false) return '1Fichier';
        return ucfirst($cl);
    }

    private function extractNextData(string $html): ?array
    {
        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $m)) {
            $data = json_decode($m[1], true);
            return $data['props']['pageProps'] ?? null;
        }
        return null;
    }

    private function getSearchResults(string $query): array
    {
        $url = "{$this->host}/search?q=" . urlencode($query);
        $html = http_get($url, [
            'timeout' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Referer' => "{$this->host}/"
            ]
        ]);

        if (!$html) return [];

        $pageProps = $this->extractNextData($html);
        if ($pageProps && !empty($pageProps['movies']) && is_array($pageProps['movies'])) {
            return $pageProps['movies'];
        }

        return [];
    }

    private function extractVideosFromProps(?array $pageProps, string $pageUrl): array
    {
        if (!$pageProps) return [];

        $container = $pageProps['thisMovie'] ?? $pageProps['episode'] ?? $pageProps['thisEpisode'] ?? [];
        $videos = $container['videos'] ?? [];

        if (empty($videos) || !is_array($videos)) return [];

        $sources = [];
        $langMap = [
            'latino' => ['Latino', 'lat'],
            'spanish' => ['Castellano', 'cast'],
            'english' => ['Subtitulado', 'sub']
        ];

        foreach ($videos as $langKey => $serverList) {
            if (!is_array($serverList)) continue;
            $langInfo = $langMap[strtolower($langKey)] ?? ['Latino', 'lat'];

            foreach ($serverList as $item) {
                $rawServer = $item['cyberlocker'] ?? 'Online Stream';
                $playerUrl = $item['result'] ?? '';
                if (empty($playerUrl)) continue;

                $serverName = $this->formatServer($rawServer);
                $quality = !empty($item['quality']) ? strtoupper($item['quality']) : '1080p Full HD';

                $sources[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'PoseidonHD',
                    'server' => $serverName,
                    'url' => $playerUrl,
                    'lang' => $langInfo[0],
                    'lang_id' => $langInfo[1],
                    'quality' => $quality,
                    'type' => 'streaming'
                ];
            }
        }

        return $sources;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (connection_aborted()) exit;

        $results = $this->getSearchResults($title);
        if (empty($results)) return [];

        $normTitle = $this->normalizeString($title);
        $bestMatchSlug = null;

        foreach ($results as $item) {
            $slug = $item['url']['slug'] ?? '';
            // Ignorar series en búsqueda de película
            if (strpos($slug, 'series/') === 0) continue;

            $itemTmdb = isset($item['TMDbId']) ? (int)$item['TMDbId'] : null;
            if ($tmdb_id && $itemTmdb) {
                if ($itemTmdb === (int)$tmdb_id) {
                    $bestMatchSlug = $slug;
                    break;
                }
                continue;
            }

            $itemName = $item['titles']['name'] ?? '';
            if (is_strict_title_match($itemName, $title)) {
                $bestMatchSlug = $slug;
                break;
            }
        }

        if (!$bestMatchSlug) return [];

        // Convertir 'movies/19995/avatar' a URL 'https://poseidonhd2.co/pelicula/19995/avatar'
        $detailPath = preg_replace('/^movies\//', 'pelicula/', $bestMatchSlug);
        $detailUrl = "{$this->host}/{$detailPath}";

        $detailHtml = http_get($detailUrl, [
            'timeout' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Referer' => "{$this->host}/search?q=" . urlencode($title)
            ]
        ]);

        if (!$detailHtml) return [];

        $pageProps = $this->extractNextData($detailHtml);
        return $this->extractVideosFromProps($pageProps, $detailUrl);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (connection_aborted()) exit;

        $results = $this->getSearchResults($title);
        if (empty($results)) return [];

        $bestMatchSlug = null;

        foreach ($results as $item) {
            $slug = $item['url']['slug'] ?? '';
            // Solo considerar series
            if (strpos($slug, 'series/') !== 0) continue;

            $itemTmdb = isset($item['TMDbId']) ? (int)$item['TMDbId'] : null;
            if ($tmdb_id && $itemTmdb) {
                if ($itemTmdb === (int)$tmdb_id) {
                    $bestMatchSlug = $slug;
                    break;
                }
                continue;
            }

            $itemName = $item['titles']['name'] ?? '';
            if (is_strict_title_match($itemName, $title)) {
                $bestMatchSlug = $slug;
                break;
            }
        }

        if (!$bestMatchSlug) return [];

        // Convertir 'series/1396/breaking-bad' a URL de episodio 'https://poseidonhd2.co/serie/1396/breaking-bad/temporada/{s}/episodio/{e}'
        $seriesPath = preg_replace('/^series\//', 'serie/', $bestMatchSlug);
        $episodeUrl = "{$this->host}/{$seriesPath}/temporada/{$season}/episodio/{$episode}";

        $epHtml = http_get($episodeUrl, [
            'timeout' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Referer' => "{$this->host}/{$seriesPath}"
            ]
        ]);

        if (!$epHtml) return [];

        $pageProps = $this->extractNextData($epHtml);
        return $this->extractVideosFromProps($pageProps, $episodeUrl);
    }
}

