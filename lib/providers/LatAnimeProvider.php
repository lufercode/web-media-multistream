<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../tmdb.php';

class LatAnimeProvider implements ProviderInterface
{
    private string $id = 'latanime';
    private string $name = 'LatAnime (Anime en Español Latino y Castellano HD)';
    private string $host = 'https://latanime.org';

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
        return 'mixed';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG[$this->id]['enabled'] ?? true;
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
        $queries = array_values(array_unique(array_filter([
            trim($title),
            trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s.]/u', ' ', $title))),
            trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)))
        ])));

        $candidates = [];
        $seen_urls = [];

        foreach ($queries as $q) {
            if (connection_aborted()) exit;
            $search_url = $this->host . '/buscar?q=' . urlencode($q);
            $html = http_get($search_url, [
                'timeout' => 5,
                'headers' => ['Referer' => $this->host . '/']
            ]);
            if (!$html || strlen($html) < 500) continue;

            preg_match_all('/<a[^>]+href="(https:\/\/latanime\.org\/anime\/([^"\/]+))"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $url = $m[1];
                $slug = $m[2];
                if (isset($seen_urls[$slug])) continue;

                $raw_text = trim(preg_replace('/\s+/', ' ', strip_tags($m[3])));
                if (preg_match('/<h3[^>]*>([^<]+)<\/h3>/i', $m[3], $hm)) {
                    $raw_text = trim(html_entity_decode($hm[1], ENT_QUOTES, 'UTF-8'));
                }

                // Limpiar sufijos de idioma/temporada para comparar el título base
                $base_cand = preg_replace('/\b(?:latino|castellano|subtitulado|s\d+|temporada\s*\d+|ova|pelicula)\b.*/iu', '', $raw_text);
                $base_cand = trim($base_cand, " \t\n\r\0\x0B:-");
                if (empty($base_cand)) $base_cand = $raw_text;

                if (is_anime_title_match($title, $base_cand) || is_strict_title_match($title, $base_cand)) {
                    $seen_urls[$slug] = true;
                    $lang = 'Español Latino';
                    if (stripos($slug, 'castellano') !== false || stripos($raw_text, 'castellano') !== false) {
                        $lang = 'Español Castellano';
                    } elseif (stripos($slug, 'sub') !== false) {
                        $lang = 'Japonés (Subtitulado)';
                    }

                    $candidates[] = [
                        'slug' => $slug,
                        'url' => $url,
                        'title' => $raw_text,
                        'language' => $lang
                    ];
                }
            }

            if (!empty($candidates)) break;
        }

        if (empty($candidates)) return [];

        $season_name = ($tmdb_id !== null && $season >= 2 && function_exists('get_tmdb_anime_season_name'))
            ? get_tmdb_anime_season_name($tmdb_id, $season)
            : null;

        // Filtrar candidatos que correspondan a la temporada solicitada
        $matched_candidates = $this->filterCandidatesBySeason($candidates, $season, $season_name);

        // Priorizar Español Latino primero, luego Castellano
        usort($matched_candidates, function ($a, $b) {
            $a_lat = (stripos($a['language'], 'Latino') !== false) ? 0 : 1;
            $b_lat = (stripos($b['language'], 'Latino') !== false) ? 0 : 1;
            return $a_lat <=> $b_lat;
        });

        foreach ($matched_candidates as $cand) {
            if (connection_aborted()) exit;

            $ep_nums_to_try = [$episode];
            if ($season >= 2 && $absolute_episode !== null && $absolute_episode > $episode && !preg_match('/(?:s[2-9]|temporada[-_ ][2-9])/i', $cand['slug'])) {
                $ep_nums_to_try = [$absolute_episode, $episode];
            }

            foreach ($ep_nums_to_try as $ep_num) {
                $ep_url = "{$this->host}/ver/{$cand['slug']}-episodio-{$ep_num}";
                $ep_html = http_get($ep_url, [
                    'timeout' => 5,
                    'headers' => ['Referer' => $cand['url']]
                ]);

                if (!$ep_html || strlen($ep_html) < 2000) continue;

                $formatted_title = sprintf('%s S%02dE%02d', $title, $season, $episode);

                // 1. Extraer reproductores data-player (Base64)
                if (preg_match_all('/data-player="([^"]+)"[^>]*>(.*?)<\/a>/is', $ep_html, $pm, PREG_SET_ORDER)) {
                    foreach ($pm as $p) {
                        $decoded_url = trim(@base64_decode($p[1]) ?: '');
                        if (empty($decoded_url) || strpos($decoded_url, 'http') !== 0) continue;

                        $raw_srv = trim(strip_tags($p[2]));
                        $server_name = $this->detectServerName($decoded_url, $raw_srv);

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => 'LatAnime',
                            'type' => 'streaming',
                            'title' => $formatted_title,
                            'server' => $server_name,
                            'quality' => '1080p Full HD',
                            'language' => $cand['language'],
                            'url' => $decoded_url,
                            'size' => null
                        ];
                    }
                }

                // 2. Extraer enlaces de descarga directa (Pixeldrain, Mega, MediaFire, Gofile, etc.)
                if (preg_match_all('/<a[^>]+href="(https?:\/\/(?:pixeldrain\.com|mega\.nz|gofile\.io|www\.mediafire\.com|mediafire\.com|1cloudfile\.com|www\.fireload\.com)[^"]+)"[^>]*>(.*?)<\/a>/is', $ep_html, $dm, PREG_SET_ORDER)) {
                    foreach ($dm as $d) {
                        $dl_url = trim($d[1]);
                        $dl_srv = trim(strip_tags($d[2])) ?: $this->detectServerName($dl_url, 'Directo');
                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => 'LatAnime',
                            'type' => 'direct',
                            'title' => $formatted_title,
                            'server' => ucfirst($dl_srv),
                            'quality' => '1080p Full HD',
                            'language' => $cand['language'],
                            'url' => $dl_url,
                            'size' => null
                        ];
                    }
                }

                break;
            }
        }

        return $this->deduplicate($results);
    }

    private function filterCandidatesBySeason(array $candidates, int $season, ?string $season_name): array
    {
        $non_ova = array_values(array_filter($candidates, function ($c) {
            return !preg_match('/\b(?:ova|especial|pelicula|movie)\b/i', $c['slug'] . ' ' . $c['title']);
        }));
        if (empty($non_ova)) $non_ova = $candidates;

        // 1. Buscar coincidencia explícita de temporada: s{season} o temporada-{season}
        $explicit = [];
        $s_pat = '/(?:^|[-_ ])(?:s0*' . $season . '|temporada[-_ ]*0*' . $season . '|season[-_ ]*0*' . $season . ')(?:[-_ ]|$)/i';
        foreach ($non_ova as $c) {
            if (preg_match($s_pat, $c['slug']) || preg_match($s_pat, $c['title'])) {
                $explicit[] = $c;
            }
        }
        if (!empty($explicit)) return $explicit;

        // 2. Buscar por nombre del arco de TMDB si temporada >= 2
        if ($season >= 2 && !empty($season_name)) {
            $arc_words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($season_name, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($arc_words)) {
                $arc_regex = '/' . implode('[-_ :\s]+', array_map(fn($w) => preg_quote($w, '/'), $arc_words)) . '/iu';
                $arc_matches = array_values(array_filter($non_ova, function ($c) use ($arc_regex) {
                    return preg_match($arc_regex, $c['slug']) || preg_match($arc_regex, $c['title']);
                }));
                if (!empty($arc_matches)) return $arc_matches;
            }
        }

        // 3. Si es temporada 1, tomar los que NO tengan s2, s3, s4...
        if ($season === 1) {
            $s1 = array_values(array_filter($non_ova, function ($c) {
                return !preg_match('/(?:^|[-_ ])(?:s[2-9]|temporada[-_ ]*[2-9]|season[-_ ]*[2-9])(?:[-_ ]|$)/i', $c['slug'] . ' ' . $c['title']);
            }));
            if (!empty($s1)) return $s1;
        }

        return $non_ova;
    }

    private function detectServerName(string $url, string $default = ''): string
    {
        $u = strtolower($url);
        if (strpos($u, 'voe') !== false) return 'Voe';
        if (strpos($u, 'filemoon') !== false) return 'Filemoon';
        if (strpos($u, 'wish') !== false || strpos($u, 'hglink') !== false) return 'StreamWish';
        if (strpos($u, 'vidhide') !== false || strpos($u, 'morencius') !== false) return 'VidHide';
        if (strpos($u, 'mega.nz') !== false) return 'Mega';
        if (strpos($u, 'mp4upload') !== false) return 'Mp4Upload';
        if (strpos($u, 'lulu') !== false) return 'LuluStream';
        if (strpos($u, 'mxdrop') !== false || strpos($u, 'mixdrop') !== false) return 'Mixdrop';
        if (strpos($u, 'listeamed') !== false || strpos($u, 'vidguard') !== false) return 'VidGuard';
        if (strpos($u, 'vide0') !== false) return 'Vide0';
        if (!empty($default)) return ucfirst(trim($default));
        return 'Online Player';
    }

    private function deduplicate(array $results): array
    {
        $seen = [];
        $unique = [];
        foreach ($results as $item) {
            $key = ($item['type'] ?? '') . '|' . ($item['url'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $item;
            }
        }
        return $unique;
    }
}
