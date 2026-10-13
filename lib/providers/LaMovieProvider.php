<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class LaMovieProvider implements ProviderInterface
{
    private string $id = 'lamovie';
    private string $name = 'LaMovie (Películas, Series y Anime HD)';
    private string $wpApiBase = 'https://lamovie.org/wp-api/v1';
    private string $apiBase = 'https://tmdb.allcalidad.re/v1';
    private string $referer = 'https://lamovie.org/';

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

    private function wpApiGet(string $path): ?array
    {
        $url = $this->wpApiBase . $path;
        $res = http_get($url, [
            'timeout' => 5,
            'headers' => [
                'Referer' => $this->referer,
                'Origin' => rtrim($this->referer, '/'),
                'Accept' => 'application/json'
            ]
        ]);
        if (!$res) return null;
        $decoded = json_decode($res, true);
        if (!is_array($decoded)) return null;
        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }

    private function apiGet(string $path): ?array
    {
        $url = $this->apiBase . $path;
        $res = http_get($url, [
            'timeout' => 4,
            'headers' => [
                'Referer' => $this->referer,
                'Origin' => rtrim($this->referer, '/'),
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

        $results = [];
        $clean_query = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)));
        $queries = array_values(array_unique(array_filter([trim($title), $clean_query])));

        // 1. Búsqueda principal en el API oficial actual de LaMovie (wp-api/v1)
        foreach ($queries as $q) {
            if (connection_aborted()) exit;
            $searchData = $this->wpApiGet('/search?filter=%5B%5D&postType=any&q=' . rawurlencode($q) . '&postsPerPage=18&page=1');
            $posts = $searchData['posts'] ?? [];
            if (empty($posts) || !is_array($posts)) continue;

            foreach ($posts as $cand) {
                if (connection_aborted()) exit;
                $type = strtolower($cand['type'] ?? $cand['post_type'] ?? '');
                if ($type !== '' && $type !== 'movies' && $type !== 'movie') continue;

                $post_id = $cand['_id'] ?? $cand['id'] ?? null;
                if (!$post_id) continue;

                $cand_title = $cand['title'] ?? '';
                $cand_orig = $cand['original_title'] ?? '';
                $cand_year = !empty($cand['release_date']) ? substr((string)$cand['release_date'], 0, 4) : (!empty($cand['year']) ? (string)$cand['year'] : null);

                if (is_strict_title_match($title, $cand_title, $year, $cand_year) ||
                    (!empty($cand_orig) && is_strict_title_match($title, $cand_orig, $year, $cand_year))) {
                    $matched_title = $cand_title ?: ($cand_orig ?: $title);
                    $playerData = $this->wpApiGet('/player?postId=' . rawurlencode((string)$post_id) . '&demo=0');
                    if (is_array($playerData)) {
                        $this->parseMediaLinks($playerData, $matched_title, $results);
                    }
                    if (!empty($results)) {
                        return $this->deduplicate($results);
                    }
                }
            }
        }

        // 2. Respaldo en backend legado por TMDB ID
        if ($tmdb_id !== null && $tmdb_id > 0) {
            if (connection_aborted()) exit;
            $data = $this->apiGet("/items/movie/{$tmdb_id}");
            if (!empty($data['item']) && is_array($data['item'])) {
                $item = $data['item'];
                $item_title = $item['title'] ?? $item['original_title'] ?? $title;
                $this->extractFromApiItem($item, $item_title, $results);
                if (!empty($results)) {
                    return $this->deduplicate($results);
                }
            }
        }

        // 3. Respaldo por búsqueda de texto en backend legado
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
                        $this->extractFromApiItem($cand, $matched_title, $results);
                    } elseif ($cand_tmdb) {
                        $detail = $this->apiGet("/items/movie/{$cand_tmdb}");
                        if (!empty($detail['item'])) {
                            $this->extractFromApiItem($detail['item'], $matched_title, $results);
                        }
                    }
                    if (!empty($results)) break 2;
                }
            }
        }

        return $this->deduplicate($results);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $clean_query = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)));
        $queries = array_values(array_unique(array_filter([trim($title), $clean_query])));

        // 1. Búsqueda principal en el API oficial actual de LaMovie (wp-api/v1)
        foreach ($queries as $q) {
            if (connection_aborted()) exit;
            $searchData = $this->wpApiGet('/search?filter=%5B%5D&postType=any&q=' . rawurlencode($q) . '&postsPerPage=18&page=1');
            $posts = $searchData['posts'] ?? [];
            if (empty($posts) || !is_array($posts)) continue;

            foreach ($posts as $cand) {
                if (connection_aborted()) exit;
                $type = strtolower($cand['type'] ?? $cand['post_type'] ?? '');
                if ($type === 'movies' || $type === 'movie') continue;

                $post_id = $cand['_id'] ?? $cand['id'] ?? null;
                if (!$post_id) continue;

                $cand_title = $cand['title'] ?? '';
                $cand_orig = $cand['original_title'] ?? '';

                $matched = is_strict_title_match($title, $cand_title) ||
                           (!empty($cand_orig) && is_strict_title_match($title, $cand_orig)) ||
                           is_anime_title_match($title, $cand_title) ||
                           (!empty($cand_orig) && is_anime_title_match($title, $cand_orig));

                if ($matched) {
                    $matched_title = $cand_title ?: ($cand_orig ?: $title);
                    $this->fetchWpApiEpisodeLinks((string)$post_id, $matched_title, $season, $episode, $absolute_episode, $results);
                    if (!empty($results)) {
                        return $this->deduplicate($results);
                    }
                }
            }
        }

        // 2. Respaldo en backend legado por TMDB ID
        $candidates = [];
        if ($tmdb_id !== null && $tmdb_id > 0) {
            $candidates[] = ['kind' => 'anime', 'tmdb_id' => $tmdb_id, 'title' => $title];
            $candidates[] = ['kind' => 'tvshow', 'tmdb_id' => $tmdb_id, 'title' => $title];
        }

        foreach ($candidates as $cand) {
            if (connection_aborted()) exit;
            $this->fetchEpisodeLinks($cand['kind'], (int)$cand['tmdb_id'], $cand['title'], $season, $episode, $absolute_episode, $results);
            if (!empty($results)) {
                return $this->deduplicate($results);
            }
        }

        // 3. Respaldo por búsqueda de título en backend legado
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
                    $this->fetchEpisodeLinks($kind, $cand_tmdb, $matched_title, $season, $episode, $absolute_episode, $results);
                    if (!empty($results)) break 2;
                }
            }
        }

        return $this->deduplicate($results);
    }

    private function fetchWpApiEpisodeLinks(string $post_id, string $series_title, int $season, int $episode, ?int $absolute_episode, array &$results): void
    {
        $attempts = [
            ['season' => $season, 'episode' => $episode]
        ];
        if ($season >= 2 && $absolute_episode !== null && $absolute_episode > $episode) {
            $attempts[] = ['season' => 1, 'episode' => $absolute_episode];
        }

        foreach ($attempts as $att) {
            $s_num = $att['season'];
            $e_num = $att['episode'];
            $epListData = $this->wpApiGet('/single/episodes/list?_id=' . rawurlencode($post_id) . '&season=' . $s_num . '&page=1&postsPerPage=50');
            $posts = $epListData['posts'] ?? (is_array($epListData) && isset($epListData[0]) ? $epListData : []);
            if (empty($posts) || !is_array($posts)) continue;

            $target_ep = null;
            foreach ($posts as $ep) {
                $ep_number = isset($ep['episode_number']) ? (int)$ep['episode_number'] : (isset($ep['episode']) ? (int)$ep['episode'] : -1);
                if ($ep_number === $e_num) {
                    $target_ep = $ep;
                    break;
                }
            }

            if ($target_ep && !empty($target_ep['_id'])) {
                $formatted_title = sprintf('%s S%02dE%02d', $series_title, $season, $episode);
                $playerData = $this->wpApiGet('/player?postId=' . rawurlencode((string)$target_ep['_id']) . '&demo=0');
                if (is_array($playerData)) {
                    $this->parseMediaLinks($playerData, $formatted_title, $results);
                }
                if (!empty($results)) return;
            }
        }
    }

    private function fetchEpisodeLinks(string $kind, int $tmdb_id, string $series_title, int $season, int $episode, ?int $absolute_episode, array &$results): void
    {
        $epData = $this->apiGet("/items/{$kind}/{$tmdb_id}/seasons/{$season}/episodes/{$episode}");
        if (empty($epData['episode']) && $season >= 2 && $absolute_episode !== null && $absolute_episode > $episode) {
            $epData = $this->apiGet("/items/{$kind}/{$tmdb_id}/seasons/1/episodes/{$absolute_episode}");
        }

        if (!empty($epData['episode']) && is_array($epData['episode'])) {
            $ep = $epData['episode'];
            $formatted_title = sprintf('%s S%02dE%02d', $series_title, $season, $episode);
            $this->extractFromApiItem($ep, $formatted_title, $results);
        }
    }

    private function extractFromApiItem(array $item, string $item_title, array &$results): void
    {
        $code = trim($item['code'] ?? '');
        $raw_q = trim($item['quality'] ?? '1080p Full HD');
        $quality = (stripos($raw_q, '1080') !== false || strtoupper($raw_q) === 'HD') ? '1080p Full HD' : $raw_q;
        $lang = !empty($item['lang']) ? $this->formatLanguage($item['lang']) : 'Español Latino';

        if (!empty($code)) {
            $embed_url = "https://vimeos.net/embed-{$code}.html";
            $dl_url = "https://vimeos.net/d/{$code}_h";

            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => 'LaMovie',
                'type' => 'streaming',
                'title' => $item_title,
                'server' => 'Vimeos',
                'quality' => $quality,
                'language' => $lang,
                'url' => $embed_url,
                'size' => null
            ];

            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => 'LaMovie',
                'type' => 'direct',
                'title' => $item_title,
                'server' => 'Vimeos Direct',
                'quality' => $quality,
                'language' => $lang,
                'url' => $dl_url,
                'size' => null
            ];
        }

        // Soporte adicional si el item contiene embeds o downloads explícitos
        if (!empty($item['embeds']) && is_array($item['embeds'])) {
            $this->parseMediaLinks($item, $item_title, $results);
        }
    }

    private function parseMediaLinks(array $data, string $item_title, array &$results): void
    {
        $embeds = $data['embeds'] ?? [];
        $fallback_raw_lang = 'Español Latino';
        foreach ($embeds as $e_cand) {
            if (!empty(trim((string)($e_cand['lang'] ?? '')))) {
                $fallback_raw_lang = trim((string)$e_cand['lang']);
                break;
            }
        }

        foreach ($embeds as $emb) {
            $url = trim($emb['url'] ?? '');
            if (empty($url) || !preg_match('#^https?://#i', $url)) continue;
            if (stripos($url, 'lamovie.org/embed.html') !== false || stripos($url, 'videoapp.zip') !== false) continue;

            $server_name = $this->detectServerName($url, $emb['server'] ?? '');
            $quality = !empty($emb['quality']) ? trim($emb['quality']) : '1080p Full HD';
            if (stripos($quality, 'hd') === false && stripos($quality, 'p') === false) {
                $quality .= ' HD';
            }
            $raw_emb_lang = !empty(trim((string)($emb['lang'] ?? ''))) ? (string)$emb['lang'] : $fallback_raw_lang;
            $lang = $this->formatLanguage($raw_emb_lang);

            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => 'LaMovie',
                'type' => 'streaming',
                'title' => $item_title,
                'server' => $server_name,
                'quality' => $quality,
                'language' => $lang,
                'url' => $url,
                'size' => null
            ];
        }

        $downloads = $data['downloads'] ?? [];
        foreach ($downloads as $dl) {
            $url = trim($dl['url'] ?? '');
            if (empty($url)) continue;

            $quality = !empty($dl['quality']) ? trim($dl['quality']) : '1080p Full HD';
            $raw_dl_lang = !empty(trim((string)($dl['lang'] ?? ''))) ? (string)$dl['lang'] : $fallback_raw_lang;
            $lang = $this->formatLanguage($raw_dl_lang);

            if (stripos($url, 'magnet:') === 0) {
                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'LaMovie (Torrent)',
                    'type' => 'torrent',
                    'title' => $item_title,
                    'server' => 'BitTorrent Magnet',
                    'quality' => $quality,
                    'language' => $lang,
                    'url' => html_entity_decode($url),
                    'size' => null
                ];
            } elseif (preg_match('#^https?://#i', $url)) {
                $server_name = $this->detectServerName($url, $dl['server'] ?? 'Directo');
                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'LaMovie',
                    'type' => 'direct',
                    'title' => $item_title,
                    'server' => $server_name,
                    'quality' => $quality,
                    'language' => $lang,
                    'url' => $url,
                    'size' => null
                ];
            }
        }
    }

    private function detectServerName(string $url, string $default = ''): string
    {
        $u = strtolower($url);
        if (strpos($u, 'goodstream') !== false) return 'Goodstream';
        if (strpos($u, 'wish') !== false || strpos($u, 'hlswish') !== false) return 'StreamWish';
        if (strpos($u, 'voe.sx') !== false || strpos($u, 'voe') !== false) return 'Voe';
        if (strpos($u, 'filemoon') !== false) return 'Filemoon';
        if (strpos($u, 'vimeos') !== false) return 'Vimeos';
        if (strpos($u, 'vidhide') !== false || strpos($u, 'morencius') !== false) return 'VidHide';
        if (strpos($u, 'streamtape') !== false) return 'Streamtape';
        if (strpos($u, 'waaw') !== false || strpos($u, 'netu') !== false) return 'Waaw';
        if (strpos($u, 'rapidvideo') !== false) return 'RapidVideo';
        if (strpos($u, 'mega.nz') !== false) return 'Mega';
        if (strpos($u, 'mediafire') !== false) return 'MediaFire';
        if (strpos($u, '1fichier') !== false) return '1Fichier';

        $clean_def = trim($default);
        if (!empty($clean_def) && strtolower($clean_def) !== 'online') {
            return ucfirst($clean_def);
        }
        return 'Online Player';
    }

    private function formatLanguage(string $raw): string
    {
        $r = mb_strtolower(trim($raw), 'UTF-8');
        // IMPORTANTE: Verificar primero indicadores de subtítulos o idioma original (ej: "Japonés - Subtítulos Latino")
        // para NUNCA confundir subtítulos en español/latino con audio doblado.
        if (strpos($r, 'sub') !== false || strpos($r, 'vose') !== false || strpos($r, 'vost') !== false) {
            if (strpos($r, 'jap') !== false) {
                return 'Japonés (Sub Latino)';
            }
            if (strpos($r, 'lat') !== false || strpos($r, 'esp') !== false) {
                return 'Subtitulado (Sub Latino)';
            }
            return 'Subtitulado';
        }
        if (strpos($r, 'jap') !== false) {
            return 'Japonés (Sub)';
        }
        if (strpos($r, 'ingl') !== false || strpos($r, 'eng') !== false || strpos($r, 'original') !== false) {
            return 'Inglés (Sub)';
        }
        if (strpos($r, 'castellano') !== false || strpos($r, 'españa') !== false || strpos($r, 'espana') !== false) {
            return 'Español Castellano';
        }
        if (strpos($r, 'lat') !== false || strpos($r, 'mx') !== false) {
            return 'Español Latino';
        }
        if (strpos($r, 'esp') !== false) {
            return 'Español Castellano';
        }
        return ucfirst(trim($raw));
    }

    private function deduplicate(array $results): array
    {
        $seen = [];
        $unique = [];
        foreach ($results as $item) {
            $key = ($item['type'] ?? '') . '|' . ($item['server'] ?? '') . '|' . ($item['url'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $item;
            }
        }
        return $unique;
    }
}
