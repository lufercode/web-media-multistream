<?php
require_once __DIR__ . '/ProviderInterface.php';

class TioAnimeProvider implements ProviderInterface
{
    private string $host = 'https://tioanime.com/';

    public function getId(): string
    {
        return 'tioanime';
    }

    public function getName(): string
    {
        return 'TioAnime (Anime HD / Mega / Waaw)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['tioanime']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['tioanime']['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        return $this->searchSeries($title, 1, 1, $tmdb_id);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $query_clean = preg_replace('/[^\w\s]/u', ' ', $title);
        $query_clean = trim(preg_replace('/\s+/', ' ', $query_clean));
        $src_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

        $search_url = $this->host . 'directorio?q=' . rawurlencode($query_clean);
        $html = http_get($search_url, ['timeout' => 6]);
        if (!$html || strlen($html) < 500) return [];

        preg_match_all('/<a\s+href="(\/anime\/[^"]+)"[^>]*>.*?<h3[^>]*>([^<]+)<\/h3>/is', $html, $matches, PREG_SET_ORDER);
        if (empty($matches)) return [];

        foreach ($matches as $m) {
            if (connection_aborted()) exit;
            $rel_slug = basename($m[1]);
            $anime_title = trim(strip_tags($m[2]));

            if (!is_strict_title_match($title, $anime_title)) {
                continue;
            }

            // URL del episodio: /ver/{slug}-{episode}
            $ep_url = $this->host . "ver/{$rel_slug}-{$episode}";
            $ep_html = http_get($ep_url, ['timeout' => 6]);
            if (!$ep_html) continue;

            preg_match('/var\s+videos\s*=\s*(\[[^;]+\]);/is', $ep_html, $vm);
            if (!empty($vm[1])) {
                $videos = json_decode($vm[1], true);
                if (is_array($videos)) {
                    foreach ($videos as $v) {
                        $server_name = $v[0] ?? 'Stream';
                        $stream_url = $v[1] ?? '';
                        if (empty($stream_url) || strpos($stream_url, 'http') === false) continue;

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => 'TioAnime',
                            'type' => 'streaming',
                            'title' => "{$anime_title} Ep. {$episode}",
                            'server' => ucfirst($server_name),
                            'quality' => '1080p Full HD',
                            'language' => 'Japonés (Subtitulado)',
                            'url' => $stream_url,
                            'size' => null
                        ];
                    }
                }
            }

            if (!empty($results)) break;
        }

        return $results;
    }
}

