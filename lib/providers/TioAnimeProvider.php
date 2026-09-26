<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../tmdb.php';

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

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $query_clean = preg_replace('/[^\w\s]/u', ' ', $title);
        $query_clean = trim(preg_replace('/\s+/', ' ', $query_clean));

        $search_url = $this->host . 'directorio?q=' . rawurlencode($query_clean);
        $html = http_get($search_url, ['timeout' => 6]);
        if (!$html || strlen($html) < 500) return [];

        preg_match_all('/<a\s+href="(\/anime\/[^"]+)"[^>]*>.*?<h3[^>]*>([^<]+)<\/h3>/is', $html, $matches, PREG_SET_ORDER);
        if (empty($matches)) return [];

        $candidates = [];
        $seen_slugs = [];
        foreach ($matches as $m) {
            $rel_slug = basename($m[1]);
            if (isset($seen_slugs[$rel_slug])) continue;
            $anime_title = trim(strip_tags($m[2]));

            if (is_anime_title_match($title, $anime_title)) {
                $seen_slugs[$rel_slug] = true;
                $candidates[] = [
                    'slug' => $rel_slug,
                    'title' => $anime_title
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
            $rel_slug = $att['slug'];
            $target_ep = $att['episode'];

            $ep_url = $this->host . "ver/{$rel_slug}-{$target_ep}";
            $ep_html = http_get($ep_url, ['timeout' => 6]);
            if (!$ep_html) continue;

            preg_match('/var\s+videos\s*=\s*(\[[^;]+\]);/is', $ep_html, $vm);
            if (!empty($vm[1])) {
                $videos = json_decode($vm[1], true);
                if (is_array($videos) && !empty($videos)) {
                    foreach ($videos as $v) {
                        $server_name = $v[0] ?? 'Stream';
                        $stream_url = $v[1] ?? '';
                        if (empty($stream_url) || strpos($stream_url, 'http') === false) continue;

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => 'TioAnime',
                            'type' => 'streaming',
                            'title' => "{$att['title']} Ep. {$target_ep} (S{$season}E{$episode})",
                            'server' => ucfirst($server_name),
                            'quality' => '1080p Full HD',
                            'language' => 'Japonés (Subtitulado)',
                            'url' => $stream_url,
                            'size' => null
                        ];
                    }
                }
            }

            // Extraer enlaces de descarga directa de table-downloads
            if (preg_match('/<table[^>]*table-downloads[^>]*>(.*?)<\/table>/is', $ep_html, $mTable)) {
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $mTable[1], $rows);
                foreach ($rows[1] as $row) {
                    if (!preg_match('/<a[^>]+href="([^"]+)"/i', $row, $mLink)) continue;
                    $dl_url = trim($mLink[1]);
                    if (empty($dl_url) || strpos($dl_url, 'http') === false) continue;
                    if (stripos($dl_url, 'zippyshare.com') !== false) continue;

                    preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cols);
                    $srv = !empty($cols[1][0]) ? trim(strip_tags($cols[1][0])) : 'Direct';
                    $raw_lang = !empty($cols[1][1]) ? trim(strip_tags($cols[1][1])) : '';
                    $lang = 'Japonés (Subtitulado)';
                    if (stripos($raw_lang, 'lat') !== false) {
                        $lang = 'Español Latino';
                    } elseif (stripos($raw_lang, 'esp') !== false || stripos($raw_lang, 'castellano') !== false) {
                        $lang = 'Español Castellano';
                    }

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'TioAnime',
                        'type' => 'direct',
                        'title' => "{$att['title']} Ep. {$target_ep} (S{$season}E{$episode})",
                        'server' => ucfirst($srv),
                        'quality' => '1080p Full HD',
                        'language' => $lang,
                        'url' => $dl_url,
                        'size' => null
                    ];
                }
            }

            if (!empty($results)) break;
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

                    // Probar primero el candidato base del arco (TioAnime suele unificar todos los episodios del arco en una sola entrada)
                    foreach ($part1_cands as $c) {
                        $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode];
                    }
                    if ($episode > 24 && !empty($part3_cands)) {
                        foreach ($part3_cands as $c) {
                            $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 24];
                        }
                    }
                    if ($episode > 11 && !empty($part2_cands)) {
                        foreach ($part2_cands as $c) {
                            if ($episode > 12) {
                                $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 12];
                            }
                            $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 11];
                        }
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

        // Caso 2: Solicitud explícita de temporada >= 2 por número
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

        // Caso 3: Solicitud de Temporada 1 pero con número de episodio continuo (> 24)
        if ($season === 1 && $episode > 24) {
            foreach ($tv_candidates as $c) {
                if (preg_match($season_patterns[2], $c['slug']) || preg_match($season_patterns[2], $c['title'])) {
                    $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode - 24];
                }
            }
        }

        // Caso 4: Temporada 1 base
        foreach ($tv_candidates as $c) {
            $slug = strtolower($c['slug']);
            if (preg_match('/(?:2nd|3rd|4th|5th|6th|7th|season[-_ ][2-9]|temporada[-_ ][2-9]|final[-_ ]season|part[-_ ][2-9])/i', $slug)) {
                continue;
            }
            if ($season === 1) {
                $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $episode];
            } elseif ($absolute_episode !== null && $absolute_episode > $episode) {
                // En temporadas >= 2 NUNCA usar el episodio relativo sobre la temporada 1
                $attempts[] = ['slug' => $c['slug'], 'title' => $c['title'], 'episode' => $absolute_episode];
            }
            break;
        }

        return $attempts;
    }
}
