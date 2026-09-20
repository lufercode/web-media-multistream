<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class AnimeProvider implements ProviderInterface
{
    private string $host = 'https://jkanime.net/';

    public function getId(): string
    {
        return 'anime';
    }

    public function getName(): string
    {
        return 'JKAnime (Anime en Streaming)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['anime']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['anime']['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        return $this->searchSeries($title, 1, 1, $tmdb_id);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $query_clean = preg_replace('/[^\w\s]/u', ' ', $title);
        $query_clean = trim(preg_replace('/\s+/', ' ', $query_clean));

        $search_url = $this->host . 'buscar/' . rawurlencode($query_clean) . '/';
        $html = http_get($search_url, ['timeout' => 6]);
        if (!$html) return [];

        preg_match_all('/<a\s+href="(https:\/\/jkanime\.net\/[^\/"\s]+\/)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
        $candidates = [];

        foreach ($matches as $m) {
            $link = $m[1];
            $text = trim(strip_tags($m[2]));
            if (empty($text) || stripos($link, 'buscar') !== false || stripos($link, 'genero') !== false) continue;

            if (is_anime_title_match($title, $text)) {
                $candidates[] = [
                    'slug' => trim(parse_url($link, PHP_URL_PATH), '/'),
                    'title' => $text,
                    'url' => $link
                ];
            }
        }

        if (empty($candidates)) return [];

        $attempts = $this->resolveSeasonAttempts($candidates, $season, $episode, $absolute_episode);

        foreach ($attempts as $att) {
            if (connection_aborted()) exit;
            $ep_url = $this->host . "{$att['slug']}/{$att['episode']}/";
            $ep_html = http_get($ep_url, ['timeout' => 6]);

            if ($ep_html) {
                preg_match_all('/<iframe[^>]+src="([^"]+)"/i', $ep_html, $iframes);
                $links = array_unique($iframes[1] ?? []);

                if (!empty($links)) {
                    $idx = 1;
                    foreach ($links as $link) {
                        if (stripos($link, 'jkanimenet.png') !== false) continue;
                        if (strpos($link, '+val.') !== false) continue;

                        $server_name = 'Anime Player ' . $idx++;
                        if (stripos($link, '/um?') !== false) $server_name = 'Mega / Mediafire Stream';
                        elseif (stripos($link, '/umv?') !== false) $server_name = 'Voe Stream';
                        elseif (stripos($link, '/jk?') !== false) $server_name = 'JK HD Server';

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => $this->getName(),
                            'type' => 'streaming',
                            'title' => "{$att['title']} Ep. {$att['episode']}",
                            'server' => $server_name,
                            'quality' => 'HD 1080p',
                            'language' => 'Japonés (Subtitulado)',
                            'url' => $link,
                            'size' => null
                        ];
                    }
                    if (!empty($results)) break;
                }
            }
        }

        return $results;
    }

    private function resolveSeasonAttempts(array $candidates, int $season, int $episode, ?int $absolute_episode = null): array
    {
        $tv_candidates = array_values(array_filter($candidates, function($c) {
            $slug = strtolower($c['slug']);
            return strpos($slug, 'movie') === false && strpos($slug, 'pelicula') === false;
        }));

        if (empty($tv_candidates)) {
            $tv_candidates = $candidates;
        }

        $season_patterns = [
            2 => '/(?:2nd[-_ ]season|second[-_ ]season|season[-_ ]2|2da[-_ ]temporada|part[-_ ]2|[-_ ]2(?=[-_\/]|$))/i',
            3 => '/(?:3rd[-_ ]season|third[-_ ]season|season[-_ ]3|3ra[-_ ]temporada|[-_ ]3(?=[-_\/]|$))/i',
            4 => '/(?:4th[-_ ]season|fourth[-_ ]season|season[-_ ]4|4ta[-_ ]temporada|the[-_ ]final[-_ ]season|final[-_ ]season|[-_ ]4(?=[-_\/]|$))/i',
            5 => '/(?:5th[-_ ]season|season[-_ ]5|[-_ ]5(?=[-_\/]|$))/i',
            6 => '/(?:6th[-_ ]season|season[-_ ]6|[-_ ]6(?=[-_\/]|$))/i',
            7 => '/(?:7th[-_ ]season|season[-_ ]7|[-_ ]7(?=[-_\/]|$))/i',
        ];

        $attempts = [];

        if ($season >= 2 && isset($season_patterns[$season])) {
            foreach ($tv_candidates as $c) {
                if (preg_match($season_patterns[$season], $c['slug']) || preg_match($season_patterns[$season], $c['title'])) {
                    $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode];
                    if ($episode > 24) {
                        $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 24];
                    }
                }
            }
        }

        if ($season === 1 && $episode > 24) {
            foreach ($tv_candidates as $c) {
                if (preg_match($season_patterns[2], $c['slug']) || preg_match($season_patterns[2], $c['title'])) {
                    $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 24];
                }
            }
        }

        foreach ($tv_candidates as $c) {
            $slug = strtolower($c['slug']);
            if (preg_match('/(?:2nd|3rd|4th|5th|6th|7th|season[-_ ][2-9]|temporada[-_ ][2-9]|final[-_ ]season)/i', $slug)) {
                continue;
            }
            $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode];
            break;
        }

        if (empty($attempts) && !empty($tv_candidates)) {
            $attempts[] = ['slug' => $tv_candidates[0]['slug'], 'title' => $tv_candidates[0]['title'], 'episode' => $episode];
        }

        return $attempts;
    }
}
