<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../tmdb.php';

class AnimeFLVProvider implements ProviderInterface
{
    private string $id   = 'animeflv';
    private string $name = 'AnimeFLV (Anime Latino y Subtitulado HD)';
    private string $apiHost = 'https://animeav1.ahmedrangel.com/api';
    private string $webHost = 'https://www3.animeflv.net';

    public function getId(): string   { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getType(): string { return 'mixed'; }

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
        if (!$this->isEnabled()) return [];

        $results = [];
        $clean_title = trim($title);

        // 1. Intentar primero con la API REST directa (ultrarrápida y sin Cloudflare blocks)
        try {
            $api_results = $this->searchViaApi($clean_title, $season, $episode, $tmdb_id, $absolute_episode);
            if (!empty($api_results)) {
                return $this->deduplicate($api_results);
            }
        } catch (\Throwable $e) {
            // Continuar al fallback
        }

        // 2. Fallback a scraping web directo
        try {
            $web_results = $this->searchViaWeb($clean_title, $season, $episode, $tmdb_id, $absolute_episode);
            if (!empty($web_results)) {
                return $this->deduplicate($web_results);
            }
        } catch (\Throwable $e) {
            // Error silencioso
        }

        return $this->deduplicate($results);
    }

    private function searchViaApi(string $title, int $season, int $episode, ?int $tmdb_id, ?int $absolute_episode): array
    {
        $queries = [$title, preg_replace('/[^\w\s]/u', ' ', $title)];

        // Enriquecer con nombres alternativos / Romaji desde TMDB si está disponible
        if ($tmdb_id !== null && function_exists('get_tmdb_data')) {
            $alt_data = get_tmdb_data("tv/{$tmdb_id}/alternative_titles");
            foreach ($alt_data['results'] ?? [] as $alt) {
                $alt_title = trim($alt['title'] ?? '');
                if (!empty($alt_title)) {
                    $queries[] = $alt_title;
                }
            }
            $details = get_tmdb_data("tv/{$tmdb_id}");
            $orig_name = trim($details['original_name'] ?? '');
            if (!empty($orig_name)) {
                $queries[] = $orig_name;
            }
        }
        $queries = array_values(array_unique(array_filter($queries)));

        $search_media = [];
        foreach ($queries as $q) {
            $search_url = $this->apiHost . '/search?query=' . urlencode(trim($q));
            $json = http_get($search_url, ['timeout' => 5]);
            if (!$json) continue;

            $data = @json_decode($json, true);
            $media = $data['data']['media'] ?? [];
            if (!empty($media)) {
                $search_media = $media;
                break;
            }
        }

        if (empty($search_media)) return [];

        // Obtener nombre del arco en TMDB para animes con arcos divididos
        $season_name = ($tmdb_id !== null && $season >= 2 && function_exists('get_tmdb_anime_season_name'))
            ? get_tmdb_anime_season_name($tmdb_id, $season)
            : null;

        // Determinar candidatos con slug y episodio correspondiente
        $attempts = $this->resolveMediaAttempts($search_media, $queries, $season, $episode, $season_name, $absolute_episode);

        $results = [];
        $formatted_title = sprintf('%s S%02dE%02d', $title, $season, $episode);

        foreach ($attempts as $att) {
            if (connection_aborted()) exit;

            $ep_url = $this->apiHost . "/anime/{$att['slug']}/{$att['episode']}";
            $ep_json = http_get($ep_url, ['timeout' => 5]);
            if (!$ep_json) continue;

            $ep_data = @json_decode($ep_json, true);
            $embeds = $ep_data['data']['embeds'] ?? [];
            $downloads = $ep_data['data']['downloads'] ?? [];

            if (empty($embeds) && empty($downloads)) continue;

            // Procesar embeds de streaming
            foreach ($embeds as $emb) {
                $embed_url = $emb['url'] ?? '';
                if (empty($embed_url) || strpos($embed_url, 'http') !== 0) continue;

                $type_code = strtoupper($emb['type'] ?? 'SUB');
                $lang = ($type_code === 'DUB' || $type_code === 'LAT' || $type_code === 'LATINO') ? 'Español Latino' : 'Japonés (Sub)';
                $server = $this->detectServerName($embed_url, $emb['name'] ?? '');

                $results[] = [
                    'provider'      => $this->id,
                    'provider_name' => 'AnimeFLV',
                    'type'          => 'streaming',
                    'title'         => $formatted_title,
                    'server'        => $server,
                    'quality'       => '1080p Full HD',
                    'language'      => $lang,
                    'url'           => $embed_url,
                    'size'          => null,
                ];
            }

            // Procesar descargas directas
            foreach ($downloads as $dl) {
                $dl_url = $dl['url'] ?? '';
                if (empty($dl_url) || strpos($dl_url, 'http') !== 0) continue;

                $type_code = strtoupper($dl['type'] ?? 'SUB');
                $lang = ($type_code === 'DUB' || $type_code === 'LAT' || $type_code === 'LATINO') ? 'Español Latino' : 'Japonés (Sub)';
                $server = !empty($dl['name']) ? ucfirst($dl['name']) : 'Directo';

                $results[] = [
                    'provider'      => $this->id,
                    'provider_name' => 'AnimeFLV',
                    'type'          => 'direct',
                    'title'         => $formatted_title,
                    'server'        => $server,
                    'quality'       => '1080p Full HD',
                    'language'      => $lang,
                    'url'           => $dl_url,
                    'size'          => null,
                ];
            }

            if (!empty($results)) break;
        }

        return $results;
    }

    private function resolveMediaAttempts(array $mediaList, array|string $queries, int $season, int $episode, ?string $season_name, ?int $absolute_episode): array
    {
        $query_list = is_array($queries) ? $queries : [$queries];
        $candidates = [];

        foreach ($mediaList as $item) {
            $item_title = $item['title'] ?? '';
            $slug = $item['slug'] ?? '';
            if (empty($slug)) continue;

            $matched = false;
            foreach ($query_list as $q) {
                if (is_anime_title_match($q, $item_title) || is_strict_title_match($q, $item_title)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $candidates[] = [
                    'title' => $item_title,
                    'slug' => $slug,
                    'type' => $item['type'] ?? 'TV'
                ];
            }
        }

        if (empty($candidates)) {
            $candidates = $mediaList;
        }

        $is_special_or_movie = function(array $c): bool {
            $type = mb_strtolower($c['type'] ?? '', 'UTF-8');
            $str = mb_strtolower(($c['slug'] ?? '') . ' ' . ($c['title'] ?? ''), 'UTF-8');
            if (in_array($type, ['película', 'pelicula', 'movie', 'ova', 'ona', 'special', 'especial'])) {
                return true;
            }
            return (bool)preg_match('/(?:[-_\s](?:movie|pelicula|ova|ona|special|specials|gekijouban|daisakusen))\b/iu', $str);
        };

        $detect_season = function(array $c): int {
            $str = mb_strtolower(($c['slug'] ?? '') . ' ' . ($c['title'] ?? ''), 'UTF-8');
            // Detección de números romanos y arábigos de temporada
            if (preg_match('/(?:[-_\s](?:ii|2|2nd|season[-_\s]*0*2|s0*2|second[-_\s]*season))\b/iu', $str)) return 2;
            if (preg_match('/(?:[-_\s](?:iii|3|3rd|season[-_\s]*0*3|s0*3|third[-_\s]*season))\b/iu', $str)) return 3;
            if (preg_match('/(?:[-_\s](?:iv|4|4th|season[-_\s]*0*4|s0*4|fourth[-_\s]*season))\b/iu', $str)) return 4;
            if (preg_match('/(?:[-_\s](?:v|5|5th|season[-_\s]*0*5|s0*5|fifth[-_\s]*season))\b/iu', $str)) return 5;
            if (preg_match('/(?:[-_\s](?:vi|6|6th|season[-_\s]*0*6|s0*6))\b/iu', $str)) return 6;
            if (preg_match('/(?:[-_\s](?:vii|7|7th|season[-_\s]*0*7|s0*7))\b/iu', $str)) return 7;
            if (preg_match('/(?:[-_\s](?:viii|8|8th|season[-_\s]*0*8|s0*8))\b/iu', $str)) return 8;
            if (preg_match('/(?:season[-_\s]*0*([2-9])|s0*([2-9]))/iu', $str, $m)) {
                return (int)($m[1] ?: $m[2]);
            }
            return 1; // Base / Temporada 1
        };

        // Separar candidatos regulares de TV de películas o especiales
        $tv_candidates = array_values(array_filter($candidates, fn($c) => !$is_special_or_movie($c)));
        if (empty($tv_candidates)) {
            $tv_candidates = $candidates;
        }

        $attempts = [];

        // 1. Coincidencia por nombre de arco TMDB (ej. Science Future, New World, etc.)
        if ($season >= 2 && !empty($season_name)) {
            $arc_clean = preg_replace('/[^\w\s]/u', '', mb_strtolower($season_name, 'UTF-8'));
            $arc_words = array_filter(explode(' ', $arc_clean));

            $arc_matches = [];
            foreach ($tv_candidates as $c) {
                $s_lower = mb_strtolower($c['slug'] . ' ' . $c['title'], 'UTF-8');
                $matched_all = true;
                foreach ($arc_words as $w) {
                    if (strlen($w) > 2 && strpos($s_lower, $w) === false) {
                        $matched_all = false;
                        break;
                    }
                }
                if ($matched_all) $arc_matches[] = $c;
            }

            if (!empty($arc_matches)) {
                if ($episode > 12) {
                    foreach ($arc_matches as $c) {
                        if (preg_match('/part[-_ ]*2/i', $c['slug'])) {
                            $attempts[] = ['slug' => $c['slug'], 'episode' => $episode - 12];
                        }
                    }
                }
                foreach ($arc_matches as $c) {
                    if (!preg_match('/part[-_ ]*2/i', $c['slug'])) {
                        $attempts[] = ['slug' => $c['slug'], 'episode' => $episode];
                    }
                }
                if (!empty($attempts)) return $attempts;
            }
        }

        // 2. Coincidencia por temporada exacta (Season >= 2)
        if ($season >= 2) {
            foreach ($tv_candidates as $c) {
                if ($detect_season($c) === $season) {
                    if ($episode > 12 && preg_match('/part[-_ ]*2/i', $c['slug'])) {
                        $attempts[] = ['slug' => $c['slug'], 'episode' => $episode - 12];
                    } else {
                        $attempts[] = ['slug' => $c['slug'], 'episode' => $episode];
                    }
                }
            }
            if (!empty($attempts)) return $attempts;
        }

        // 3. Temporada 1: Candidato base (temporada 1 y sin sufijos de secuela)
        if ($season === 1) {
            foreach ($tv_candidates as $c) {
                if ($detect_season($c) === 1) {
                    $attempts[] = ['slug' => $c['slug'], 'episode' => $episode];
                    break;
                }
            }
            if (!empty($attempts)) return $attempts;
        }

        // 4. Fallback si nada específico coincidió
        if (!empty($tv_candidates[0])) {
            $attempts[] = ['slug' => $tv_candidates[0]['slug'], 'episode' => $episode];
        }

        return $attempts;
    }

    private function searchViaWeb(string $title, int $season, int $episode, ?int $tmdb_id, ?int $absolute_episode): array
    {
        $search_url = $this->webHost . '/api/animes/search?value=' . urlencode($title);
        $raw = http_get($search_url, [
            'timeout' => 4,
            'headers' => ['Referer' => $this->webHost . '/'],
        ]);
        if (!$raw) return [];

        $items = @json_decode($raw, true);
        if (!is_array($items)) return [];

        $attempts = $this->resolveMediaAttempts($items, $title, $season, $episode, null, $absolute_episode);
        if (empty($attempts)) return [];

        $slug = $attempts[0]['slug'];
        $ep_candidates = [$attempts[0]['episode']];
        if ($season >= 2 && $absolute_episode !== null && $absolute_episode !== $episode) {
            array_unshift($ep_candidates, $absolute_episode);
        }

        $results = [];
        $formatted_title = sprintf('%s S%02dE%02d', $title, $season, $episode);

        foreach ($ep_candidates as $ep_num) {
            $ep_url  = $this->webHost . '/ver/' . $slug . '-' . $ep_num;
            $ep_html = http_get($ep_url, [
                'timeout' => 4,
                'headers' => ['Referer' => $this->webHost . '/anime/' . $slug],
            ]);
            if (!$ep_html || strlen($ep_html) < 1000) continue;

            if (!preg_match('/var\s+videos\s*=\s*(\{.*?\});\s*\n/s', $ep_html, $vm)) continue;

            $videos = @json_decode($vm[1], true);
            if (!is_array($videos)) continue;

            foreach ($videos as $lang_group => $list) {
                if (!is_array($list)) continue;
                $is_latino = strtoupper($lang_group) === 'LAT' || strtoupper($lang_group) === 'LATINO';
                $language  = $is_latino ? 'Español Latino' : 'Japonés (Sub)';

                foreach ($list as $v) {
                    $embed_url = $v['url'] ?? ($v['code'] ?? '');
                    if (empty($embed_url) || strpos($embed_url, 'http') !== 0) continue;

                    $server = $this->detectServerName($embed_url, $v['title'] ?? 'AnimeFLV');

                    $results[] = [
                        'provider'      => $this->id,
                        'provider_name' => 'AnimeFLV',
                        'type'          => 'streaming',
                        'title'         => $formatted_title,
                        'server'        => $server,
                        'quality'       => '1080p Full HD',
                        'language'      => $language,
                        'url'           => $embed_url,
                        'size'          => null,
                    ];
                }
            }
            if (!empty($results)) break;
        }

        return $results;
    }

    private function detectServerName(string $url, string $default = ''): string
    {
        $u = strtolower($url);
        if (strpos($u, 'voe')        !== false) return 'Voe';
        if (strpos($u, 'filemoon')   !== false) return 'Filemoon';
        if (strpos($u, 'wish')       !== false || strpos($u, 'hglink') !== false) return 'StreamWish';
        if (strpos($u, 'vidhide')    !== false || strpos($u, 'morencius') !== false) return 'VidHide';
        if (strpos($u, 'fembed')     !== false || strpos($u, 'animefenix') !== false) return 'Fembed';
        if (strpos($u, 'mp4upload')  !== false) return 'Mp4Upload';
        if (strpos($u, 'streamtape') !== false) return 'Streamtape';
        if (strpos($u, 'byse')       !== false) return 'Byse';
        if (strpos($u, 'upnshare')   !== false || strpos($u, 'uns.bio') !== false) return 'UPNShare';
        if (strpos($u, 'mega.nz')    !== false) return 'Mega';
        if (strpos($u, '1fichier')   !== false) return '1Fichier';
        if (!empty($default)) return ucfirst(trim($default));
        return 'Online Player';
    }

    private function deduplicate(array $results): array
    {
        $seen = []; $unique = [];
        foreach ($results as $item) {
            $key = ($item['type'] ?? '') . '|' . ($item['url'] ?? '');
            if (!isset($seen[$key])) { $seen[$key] = true; $unique[] = $item; }
        }
        return $unique;
    }
}
