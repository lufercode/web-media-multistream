<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class PelisPediaProvider implements ProviderInterface
{
    private string $id = 'pelispedia';
    private string $name = 'PelisPedia (HD 1080p)';
    private array $hosts = ['https://pelispedia.is'];

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG[$this->id]['enabled'] ?? true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/?s=' . urlencode($title);
            $html = http_get($search_url, ['timeout' => 6]);
            if (!$html || strlen($html) < 500) continue;

            // Extraer enlaces a películas
            preg_match_all('/<a[^>]+href="(' . preg_quote($host, '/') . '\/pelicula\/[^"]+)"[^>]*>/i', $html, $matches);
            $movie_links = array_unique($matches[1] ?? []);

            foreach (array_slice($movie_links, 0, 4) as $movie_url) {
                if (connection_aborted()) exit;

                $slug = basename(rtrim($movie_url, '/'));
                $cand_title = str_replace('-', ' ', $slug);

                $cand_year = null;
                if (preg_match('/(19\d{2}|20\d{2})/', $slug, $ym)) {
                    $cand_year = $ym[1];
                }

                if (!is_strict_title_match($title, $cand_title, $year, $cand_year)) {
                    continue;
                }

                $detail_html = http_get($movie_url, ['timeout' => 6]);
                if (!$detail_html) continue;

                // Extraer iframes de reproducción tipo ?trembed=X
                preg_match_all('/<iframe[^>]+src="([^"]*trembed=[^"]*)"/i', $detail_html, $iframes);
                if (empty($iframes[1])) {
                    // Si no hay trembed, buscar iframes directos
                    preg_match_all('/<iframe[^>]+src="([^"]*(?:fastream|streamwish|voe|vidhide|filemoon|streamtape)[^"]*)"/i', $detail_html, $iframes);
                }

                foreach (array_unique($iframes[1] ?? []) as $embed_url) {
                    $embed_url = html_entity_decode($embed_url);
                    $final_url = $embed_url;

                    if (strpos($embed_url, 'trembed=') !== false) {
                        $iframe_html = http_get($embed_url, ['timeout' => 4]);
                        if ($iframe_html && preg_match('/<(?:iframe|IFRAME)[^>]+(?:src|SRC)="([^"]+)"/i', $iframe_html, $src_m)) {
                            $final_url = $src_m[1];
                        } else {
                            continue;
                        }
                    }

                    if (strpos($final_url, 'http') !== 0) continue;

                    $server_name = 'Servidor Online';
                    if (stripos($final_url, 'fastream') !== false) $server_name = 'Fastream (HD)';
                    elseif (stripos($final_url, 'streamwish') !== false || stripos($final_url, 'strwish') !== false) $server_name = 'StreamWish';
                    elseif (stripos($final_url, 'voe') !== false) $server_name = 'Voe';
                    elseif (stripos($final_url, 'vidhide') !== false) $server_name = 'VidHide';
                    elseif (stripos($final_url, 'filemoon') !== false) $server_name = 'Filemoon';
                    elseif (stripos($final_url, 'streamtape') !== false) $server_name = 'Streamtape';

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'PelisPedia',
                        'type' => 'streaming',
                        'title' => $title,
                        'server' => $server_name,
                        'quality' => '1080p Full HD',
                        'language' => 'Español Latino / VOSE',
                        'url' => $final_url,
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
        return [];
    }
}
