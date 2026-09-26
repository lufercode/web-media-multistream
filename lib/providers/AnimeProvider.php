<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../tmdb.php';

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
        $query_clean = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query_clean)), 'UTF-8');

        $search_url = $this->host . 'buscar/' . rawurlencode($query_clean) . '/';
        $html = http_get($search_url, ['timeout' => 6]);
        if (!$html) return [];

        preg_match_all('/<a\s+href="(https:\/\/jkanime\.net\/[^\/"\s]+\/)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
        $candidates = [];
        $seen_slugs = [];

        foreach ($matches as $m) {
            $link = $m[1];
            $text = trim(strip_tags($m[2]));
            if (empty($text) || stripos($link, 'buscar') !== false || stripos($link, 'genero') !== false) continue;

            $slug = trim(parse_url($link, PHP_URL_PATH), '/');
            if (isset($seen_slugs[$slug])) continue;

            if (is_anime_title_match($title, $text)) {
                $seen_slugs[$slug] = true;
                $candidates[] = [
                    'slug' => $slug,
                    'title' => $text,
                    'url' => $link
                ];
            }
        }

        if (empty($candidates)) return [];

        $season_name = ($tmdb_id !== null && $season >= 2 && function_exists('get_tmdb_anime_season_name'))
            ? get_tmdb_anime_season_name($tmdb_id, $season)
            : null;

        $attempts = $this->resolveSeasonAttempts($candidates, $season, $episode, $absolute_episode, $season_name);

        foreach ($attempts as $att) {
            if (connection_aborted()) exit;
            $ep_url = $this->host . "{$att['slug']}/{$att['episode']}/";
            $ep_html = http_get($ep_url, ['timeout' => 6]);

            if ($ep_html) {
                preg_match_all('/<iframe[^>]+src="([^"]+)"/i', $ep_html, $iframes);
                $links = array_unique($iframes[1] ?? []);
                $valid_links = [];
                foreach ($links as $link) {
                    if (stripos($link, 'jkanimenet.png') !== false) continue;
                    if (strpos($link, '+val.') !== false) continue;
                    $valid_links[] = $link;
                }

                if (!empty($valid_links)) {
                    $idx = 1;
                    foreach ($valid_links as $link) {
                        $server_name = 'Anime Player ' . $idx++;
                        if (stripos($link, '/um?') !== false) $server_name = 'Mega / Mediafire Stream';
                        elseif (stripos($link, '/umv?') !== false) $server_name = 'Voe Stream';
                        elseif (stripos($link, '/jk?') !== false) $server_name = 'JK HD Server';

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => $this->getName(),
                            'type' => 'streaming',
                            'title' => "{$att['title']} Ep. {$att['episode']} (S{$season}E{$episode})",
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

    private function resolveSeasonAttempts(array $candidates, int $season, int $episode, ?int $absolute_episode = null, ?string $season_name = null): array
    {
        $tv_candidates = array_values(array_filter($candidates, function($c) {
            $slug = strtolower($c['slug']);
            return strpos($slug, 'movie') === false && strpos($slug, 'pelicula') === false && strpos($slug, 'especial') === false && strpos($slug, 'ova') === false;
        }));

        if (empty($tv_candidates)) {
            $tv_candidates = $candidates;
        }

        $attempts = [];

        // 1. Si tenemos el nombre oficial del arco/temporada desde TMDB (ej: "Science Future", "New World", "Stone Wars")
        if ($season >= 2 && !empty($season_name)) {
            $arc_words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($season_name, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($arc_words)) {
                $arc_regex = '/' . implode('[-_ :\s]+', array_map(fn($w) => preg_quote($w, '/'), $arc_words)) . '/iu';
                $arc_matches = array_values(array_filter($tv_candidates, function($c) use ($arc_regex) {
                    return preg_match($arc_regex, $c['slug']) || preg_match($arc_regex, $c['title']);
                }));

                if (!empty($arc_matches)) {
                    $part1_cands = [];
                    $part2_cands = [];
                    $part3_cands = [];

                    foreach ($arc_matches as $c) {
                        if (preg_match('/(?:part[-_ ]*3|parte[-_ ]*3|cour[-_ ]*3)/i', $c['slug'] . ' ' . $c['title'])) {
                            $part3_cands[] = $c;
                        } elseif (preg_match('/(?:part[-_ ]*2|parte[-_ ]*2|cour[-_ ]*2|2nd[-_ ]*cour)/i', $c['slug'] . ' ' . $c['title'])) {
                            $part2_cands[] = $c;
                        } else {
                            $part1_cands[] = $c;
                        }
                    }

                    // Si el episodio es > 24 y existe Part 3, probar Part 3 primero
                    if ($episode > 24 && !empty($part3_cands)) {
                        foreach ($part3_cands as $c) {
                            $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 24];
                        }
                    }
                    // Si el episodio es > 12 y existe Part 2 (ej: JKAnime divide Science Future en Part 1 eps 1-12 y Part 2 eps 1-12)
                    if ($episode > 11 && !empty($part2_cands)) {
                        foreach ($part2_cands as $c) {
                            if ($episode > 12) {
                                $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 12];
                            }
                            $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 11];
                        }
                    }
                    // Probar también el candidato base del arco (por si aloja todos los episodios seguidos)
                    foreach ($part1_cands as $c) {
                        $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode];
                    }
                }
            }
        }

        $season_patterns = [
            2 => '/(?:2nd[-_ ]season|second[-_ ]season|season[-_ ]2|2da[-_ ]temporada|[-_ ]2(?=[-_\/]|$))/i',
            3 => '/(?:3rd[-_ ]season|third[-_ ]season|season[-_ ]3|3ra[-_ ]temporada|[-_ ]3(?=[-_\/]|$))/i',
            4 => '/(?:4th[-_ ]season|fourth[-_ ]season|season[-_ ]4|4ta[-_ ]temporada|the[-_ ]final[-_ ]season|final[-_ ]season|[-_ ]4(?=[-_\/]|$))/i',
            5 => '/(?:5th[-_ ]season|season[-_ ]5|[-_ ]5(?=[-_\/]|$))/i',
            6 => '/(?:6th[-_ ]season|season[-_ ]6|[-_ ]6(?=[-_\/]|$))/i',
            7 => '/(?:7th[-_ ]season|season[-_ ]7|[-_ ]7(?=[-_\/]|$))/i',
        ];

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

        // Candidato Temporada 1 base
        foreach ($tv_candidates as $c) {
            $slug = strtolower($c['slug']);
            if (preg_match('/(?:2nd|3rd|4th|5th|6th|7th|season[-_ ][2-9]|temporada[-_ ][2-9]|final[-_ ]season|part[-_ ][2-9])/i', $slug)) {
                continue;
            }
            if ($season === 1) {
                $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode];
            } elseif ($absolute_episode !== null && $absolute_episode > $episode) {
                // En temporadas >= 2 NUNCA usar el episodio relativo sobre la temporada 1 (evita devolver S1E15 al pedir S4E15)
                $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $absolute_episode];
            }
            break;
        }

        return $attempts;
    }
}
