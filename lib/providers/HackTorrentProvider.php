<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class HackTorrentProvider implements ProviderInterface
{
    private string $host = 'https://hackstore.mx';
    private string $apiBase = 'https://tmdb.allcalidad.re/v1';

    public function getId(): string
    {
        return 'hacktorrent';
    }

    public function getName(): string
    {
        return 'HackStore / HackTorrent (Películas, Series y Anime HD)';
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['hacktorrent']['enabled'] ?? true;
    }

    private function apiGet(string $path): ?array
    {
        $url = $this->apiBase . $path;
        $res = http_get($url, [
            'timeout' => 4,
            'headers' => [
                'Referer' => $this->host . '/',
                'Origin' => $this->host,
                'Accept' => 'application/json'
            ]
        ]);
        if (!$res) return null;
        $decoded = json_decode($res, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $sources = [];

        if ($tmdb_id !== null && $tmdb_id > 0) {
            if (connection_aborted()) exit;
            $data = $this->apiGet("/items/movie/{$tmdb_id}");
            if (!empty($data['item']) && is_array($data['item'])) {
                $item = $data['item'];
                $item_title = $item['title'] ?? $item['original_title'] ?? $title;
                $this->extractFromApiItem($item, $item_title, $sources);
                if (!empty($sources)) {
                    return $sources;
                }
            }
        }

        $clean_query = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)));
        $queries = array_values(array_unique(array_filter([trim($title), $clean_query])));

        foreach ($queries as $q) {
            if (connection_aborted()) exit;
            $searchData = $this->apiGet('/search?q=' . rawurlencode($q) . '&page=1&limit=18');
            $items = $searchData['items'] ?? [];
            if (empty($items)) continue;

            foreach ($items as $cand) {
                if (connection_aborted()) exit;
                if (($cand['kind'] ?? '') !== 'movie') continue;

                $cand_tmdb = isset($cand['tmdb_id']) ? (int)$cand['tmdb_id'] : null;
                $cand_title = $cand['title'] ?? '';
                $cand_orig = $cand['original_title'] ?? '';
                $cand_year = !empty($cand['release_date']) ? substr($cand['release_date'], 0, 4) : null;

                $matched = false;
                if ($tmdb_id !== null && $cand_tmdb === $tmdb_id) {
                    $matched = true;
                } elseif (is_strict_title_match($title, $cand_title, $year, $cand_year) ||
                          (!empty($cand_orig) && is_strict_title_match($title, $cand_orig, $year, $cand_year))) {
                    $matched = true;
                }

                if ($matched) {
                    $matched_title = $cand_title ?: ($cand_orig ?: $title);
                    if (!empty($cand['code'])) {
                        $this->extractFromApiItem($cand, $matched_title, $sources);
                    } elseif ($cand_tmdb) {
                        $detail = $this->apiGet("/items/movie/{$cand_tmdb}");
                        if (!empty($detail['item'])) {
                            $this->extractFromApiItem($detail['item'], $matched_title, $sources);
                        }
                    }
                    if (!empty($sources)) break 2;
                }
            }
        }

        return $sources;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $sources = [];

        if ($tmdb_id !== null && $tmdb_id > 0) {
            foreach (['anime', 'tvshow'] as $kind) {
                if (connection_aborted()) exit;
                $this->fetchEpisodeLinks($kind, $tmdb_id, $title, $season, $episode, $absolute_episode, $sources);
                if (!empty($sources)) {
                    return $sources;
                }
            }
        }

        $clean_query = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)));
        $queries = array_values(array_unique(array_filter([trim($title), $clean_query])));

        foreach ($queries as $q) {
            if (connection_aborted()) exit;
            $searchData = $this->apiGet('/search?q=' . rawurlencode($q) . '&page=1&limit=18');
            $items = $searchData['items'] ?? [];
            if (empty($items)) continue;

            foreach ($items as $cand) {
                if (connection_aborted()) exit;
                $kind = $cand['kind'] ?? '';
                if ($kind !== 'tvshow' && $kind !== 'anime') continue;

                $cand_tmdb = isset($cand['tmdb_id']) ? (int)$cand['tmdb_id'] : 0;
                if ($cand_tmdb <= 0) continue;

                $cand_title = $cand['title'] ?? '';
                $cand_orig = $cand['original_title'] ?? '';

                $matched = false;
                if ($tmdb_id !== null && $cand_tmdb === $tmdb_id) {
                    $matched = true;
                } elseif (is_strict_title_match($title, $cand_title) ||
                          (!empty($cand_orig) && is_strict_title_match($title, $cand_orig)) ||
                          ($kind === 'anime' && (is_anime_title_match($title, $cand_title) || (!empty($cand_orig) && is_anime_title_match($title, $cand_orig))))) {
                    $matched = true;
                }

                if ($matched) {
                    $matched_title = $cand_title ?: ($cand_orig ?: $title);
                    $this->fetchEpisodeLinks($kind, $cand_tmdb, $matched_title, $season, $episode, $absolute_episode, $sources);
                    if (!empty($sources)) break 2;
                }
            }
        }

        return $sources;
    }

    private function fetchEpisodeLinks(string $kind, int $tmdb_id, string $series_title, int $season, int $episode, ?int $absolute_episode, array &$sources): void
    {
        $epData = $this->apiGet("/items/{$kind}/{$tmdb_id}/seasons/{$season}/episodes/{$episode}");
        if (empty($epData['episode']) && $season >= 2 && $absolute_episode !== null && $absolute_episode > $episode) {
            $epData = $this->apiGet("/items/{$kind}/{$tmdb_id}/seasons/1/episodes/{$absolute_episode}");
        }

        if (!empty($epData['episode']) && is_array($epData['episode'])) {
            $ep = $epData['episode'];
            $formatted_title = sprintf('%s S%02dE%02d', $series_title, $season, $episode);
            $this->extractFromApiItem($ep, $formatted_title, $sources);
        }
    }

    private function extractFromApiItem(array $item, string $item_title, array &$sources): void
    {
        $code = trim($item['code'] ?? '');
        if (empty($code)) return;

        $sources[] = [
            'provider' => $this->getId(),
            'provider_name' => 'HackStore',
            'type' => 'streaming',
            'title' => $item_title,
            'server' => 'Vimeos',
            'quality' => '1080p Full HD',
            'language' => 'Español Latino',
            'lang' => 'lat',
            'url' => "https://vimeos.net/embed-{$code}.html",
            'size' => null
        ];
    }
}
