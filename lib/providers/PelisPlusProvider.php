<?php
require_once __DIR__ . '/ProviderInterface.php';

class PelisPlusProvider implements ProviderInterface
{
    private array $hosts = [
        'https://pelisplushd.bz/',
        'https://www.pelisplushd.la/',
        'https://pelisplushd.cx/',
        'https://ww1.pelisplushd.to/'
    ];

    public function getId(): string
    {
        return 'pelisplus';
    }

    public function getName(): string
    {
        return 'PelisPlus HD (Latino / Castellano)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['pelisplus']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['pelisplus']['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $src_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/search?s=' . urlencode($title);
            $html = http_get($search_url, ['timeout' => 3]);
            if (!$html || strlen($html) < 500) continue;

            preg_match_all('/<a\s+href="([^"]*\/pelicula\/[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
            if (empty($matches)) continue;

            foreach ($matches as $m) {
                if (connection_aborted()) exit;
                $rel_url = $m[1];
                $slug = basename($rel_url);
                $cand_title = str_replace('-', ' ', $slug);

                if (!is_strict_title_match($title, $cand_title, $year, null)) {
                    continue;
                }

                $movie_url = (strpos($rel_url, 'http') === 0) ? $rel_url : rtrim($host, '/') . '/' . ltrim($rel_url, '/');
                $detail_html = http_get($movie_url, ['timeout' => 3]);
                if (!$detail_html) continue;

                // Extraer servidores data-url y data-name
                preg_match_all('/data-url="([^"]+)"[^>]*data-name="([^"]+)"/i', $detail_html, $servers, PREG_SET_ORDER);
                foreach ($servers as $srv) {
                    $player_url = $srv[1];
                    $lang = $srv[2] ?: 'Español Latino';
                    $server_name = 'Servidor Online';

                    if (stripos($player_url, 'voe') !== false) $server_name = 'Voe';
                    elseif (stripos($player_url, 'wish') !== false) $server_name = 'StreamWish';
                    elseif (stripos($player_url, 'vidhide') !== false) $server_name = 'VidHide';
                    elseif (stripos($player_url, 'tape') !== false) $server_name = 'Streamtape';
                    elseif (stripos($player_url, 'filemoon') !== false) $server_name = 'Filemoon';
                    elseif (stripos($player_url, 'waaw') !== false || stripos($player_url, 'netu') !== false) $server_name = 'Netu';

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'PelisPlus',
                        'type' => 'streaming',
                        'title' => $title,
                        'server' => $server_name,
                        'quality' => '1080p Full HD',
                        'language' => $lang,
                        'url' => $player_url,
                        'size' => null
                    ];
                }

                if (!empty($results)) break;
            }

            if (!empty($results)) break;
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/search?s=' . urlencode($title);
            $html = http_get($search_url, ['timeout' => 3]);
            if (!$html || strlen($html) < 500) continue;

            preg_match_all('/<a\s+href="([^"]*\/serie\/[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
            if (empty($matches)) continue;

            foreach ($matches as $m) {
                if (connection_aborted()) exit;
                $rel_url = $m[1];
                $slug = basename($rel_url);
                $cand_title = str_replace('-', ' ', $slug);

                if (!is_strict_title_match($title, $cand_title)) {
                    continue;
                }

                // URL del episodio: /serie/{slug}/temporada/{S}/capitulo/{E}
                $ep_url = rtrim($host, '/') . "/serie/{$slug}/temporada/{$season}/capitulo/{$episode}";
                $ep_html = http_get($ep_url, ['timeout' => 3]);
                if (!$ep_html) continue;

                preg_match_all('/data-url="([^"]+)"[^>]*data-name="([^"]+)"/i', $ep_html, $servers, PREG_SET_ORDER);
                foreach ($servers as $srv) {
                    $player_url = $srv[1];
                    $lang = $srv[2] ?: 'Español Latino';
                    $server_name = 'Servidor Online';

                    if (stripos($player_url, 'voe') !== false) $server_name = 'Voe';
                    elseif (stripos($player_url, 'wish') !== false) $server_name = 'StreamWish';
                    elseif (stripos($player_url, 'vidhide') !== false) $server_name = 'VidHide';
                    elseif (stripos($player_url, 'tape') !== false) $server_name = 'Streamtape';
                    elseif (stripos($player_url, 'filemoon') !== false) $server_name = 'Filemoon';
                    elseif (stripos($player_url, 'waaw') !== false || stripos($player_url, 'netu') !== false) $server_name = 'Netu';

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'PelisPlus',
                        'type' => 'streaming',
                        'title' => "{$title} S{$season}E{$episode}",
                        'server' => $server_name,
                        'quality' => '1080p Full HD',
                        'language' => $lang,
                        'url' => $player_url,
                        'size' => null
                    ];
                }

                if (!empty($results)) break;
            }

            if (!empty($results)) break;
        }

        return $results;
    }
}

