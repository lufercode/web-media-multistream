<?php
require_once __DIR__ . '/ProviderInterface.php';

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

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $query_clean = preg_replace('/[^\w\s]/u', ' ', $title);
        $query_clean = trim(preg_replace('/\s+/', ' ', $query_clean));

        $search_url = $this->host . 'buscar/' . rawurlencode($query_clean) . '/';
        $html = http_get($search_url, ['timeout' => 6]);

        if ($html) {
            preg_match_all('/<a\s+href="(https:\/\/jkanime\.net\/[^\/"\s]+\/)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

            $target_url = null;
            $anime_title = $title;

            $src_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

            foreach ($matches as $m) {
                $link = $m[1];
                $text = trim(strip_tags($m[2]));
                if (empty($text) || stripos($link, 'buscar') !== false || stripos($link, 'genero') !== false) continue;

                if (is_strict_title_match($title, $text)) {
                    $target_url = $link;
                    $anime_title = $text;
                    break;
                }
            }

            if ($target_url) {
                $ep_url = rtrim($target_url, '/') . "/{$episode}/";
                $ep_html = http_get($ep_url, ['timeout' => 6]);

                if ($ep_html) {
                    preg_match_all('/<iframe[^>]+src="([^"]+)"/i', $ep_html, $iframes);
                    $links = array_unique($iframes[1] ?? []);

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
                            'title' => "{$anime_title} Ep. {$episode}",
                            'server' => $server_name,
                            'quality' => 'HD 1080p',
                            'language' => 'Japonés (Subtitulado)',
                            'url' => $link,
                            'size' => null
                        ];
                    }
                }
            }
        }

        return $results;
    }
}

