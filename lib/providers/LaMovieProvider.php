<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class LaMovieProvider implements ProviderInterface
{
    private string $id = 'lamovie';
    private string $name = 'LaMovie (Películas, Series y Torrents)';
    private array $hosts = ['https://lamovie.org'];

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
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $clean_query = preg_replace('/[^\w\s]/u', ' ', $title);
        $clean_query = trim(preg_replace('/\s+/', ' ', $clean_query));
        if (empty($clean_query)) return [];

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/wp-api/v1/search?filter=%7B%7D&postType=any&q=' . urlencode($clean_query) . '&postsPerPage=25&page=1';
            $headers = [
                'Referer' => rtrim($host, '/') . '/'
            ];

            $res = http_get($search_url, ['headers' => $headers, 'timeout' => 4]);
            if (!$res) continue;

            $data = json_decode($res, true);
            $posts = $data['data']['posts'] ?? [];
            if (empty($posts)) continue;

            $matched_post = null;
            $matched_title = null;

            foreach ($posts as $post) {
                if (connection_aborted()) exit;
                if (($post['type'] ?? '') !== 'movies') continue;

                $post_title = $post['title'] ?? '';
                $cand_year = !empty($post['release_date']) ? substr($post['release_date'], 0, 4) : null;

                $clean_cand = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $post_title);
                $clean_cand = trim(preg_replace('/\[.*?\]/', '', $clean_cand));

                if (is_strict_title_match($title, $clean_cand, $year, $cand_year)) {
                    $matched_post = $post;
                    $matched_title = $post_title ?: $title;
                    break;
                }
            }

            if ($matched_post && !empty($matched_post['_id'])) {
                $player_url = rtrim($host, '/') . "/wp-api/v1/player?postId={$matched_post['_id']}&demo=0";
                $p_res = http_get($player_url, ['headers' => $headers, 'timeout' => 4]);
                if ($p_res) {
                    $p_data = json_decode($p_res, true);
                    $this->parseMediaLinks($p_data['data'] ?? [], $matched_title, $results);
                }
                break;
            }
        }

        return $this->deduplicate($results);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $clean_query = preg_replace('/[^\w\s]/u', ' ', $title);
        $clean_query = trim(preg_replace('/\s+/', ' ', $clean_query));
        if (empty($clean_query)) return [];

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/wp-api/v1/search?filter=%7B%7D&postType=any&q=' . urlencode($clean_query) . '&postsPerPage=25&page=1';
            $headers = [
                'Referer' => rtrim($host, '/') . '/'
            ];

            $res = http_get($search_url, ['headers' => $headers, 'timeout' => 4]);
            if (!$res) continue;

            $data = json_decode($res, true);
            $posts = $data['data']['posts'] ?? [];
            if (empty($posts)) continue;

            $matched_series = null;
            $matched_title = null;

            foreach ($posts as $post) {
                if (connection_aborted()) exit;
                $type = $post['type'] ?? '';
                if ($type !== 'tvshows' && $type !== 'animes') continue;

                $post_title = $post['title'] ?? '';
                $clean_cand = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $post_title);
                $clean_cand = trim(preg_replace('/\[.*?\]/', '', $clean_cand));

                if (is_strict_title_match($title, $clean_cand)) {
                    $matched_series = $post;
                    $matched_title = $clean_cand ?: $title;
                    break;
                }
            }

            if ($matched_series && !empty($matched_series['_id'])) {
                $ep_list_url = rtrim($host, '/') . "/wp-api/v1/single/episodes/list?_id={$matched_series['_id']}&season={$season}&postsPerPage=50&page=1";
                $ep_res = http_get($ep_list_url, ['headers' => $headers, 'timeout' => 4]);
                if ($ep_res) {
                    $ep_data = json_decode($ep_res, true);
                    $ep_posts = $ep_data['data']['posts'] ?? [];

                    $matched_ep = null;
                    foreach ($ep_posts as $ep) {
                        $ep_num = $ep['episode_number'] ?? null;
                        if ($ep_num !== null && (int)$ep_num === $episode) {
                            $matched_ep = $ep;
                            break;
                        }

                        if (preg_match('/Episodio\s*' . $episode . '\b/i', $ep['title'] ?? '')) {
                            $matched_ep = $ep;
                            break;
                        }
                    }

                    if ($matched_ep && !empty($matched_ep['_id'])) {
                        $player_url = rtrim($host, '/') . "/wp-api/v1/player?postId={$matched_ep['_id']}&demo=0";
                        $p_res = http_get($player_url, ['headers' => $headers, 'timeout' => 4]);
                        if ($p_res) {
                            $p_data = json_decode($p_res, true);
                            $formatted_title = sprintf('%s S%02dE%02d', $matched_title, $season, $episode);
                            $this->parseMediaLinks($p_data['data'] ?? [], $formatted_title, $results);
                        }
                    }
                }
                break;
            }
        }

        return $this->deduplicate($results);
    }

    private function parseMediaLinks(array $data, string $item_title, array &$results): void
    {
        // 1. Procesar Servidores Streaming
        $embeds = $data['embeds'] ?? [];
        foreach ($embeds as $emb) {
            $url = trim($emb['url'] ?? '');
            if (empty($url) || !preg_match('#^https?://#i', $url)) continue;
            if (stripos($url, 'lamovie.org/embed.html') !== false) continue;

            $server_name = $this->detectServerName($url, $emb['server'] ?? '');
            $quality = !empty($emb['quality']) ? trim($emb['quality']) : '1080p Full HD';
            if (stripos($quality, 'hd') === false && stripos($quality, 'p') === false) {
                $quality .= ' HD';
            }
            $lang = $this->formatLanguage($emb['lang'] ?? 'Español Latino');

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

        // 2. Procesar Descargas y Torrents
        $downloads = $data['downloads'] ?? [];
        foreach ($downloads as $dl) {
            $url = trim($dl['url'] ?? '');
            if (empty($url)) continue;

            $quality = !empty($dl['quality']) ? trim($dl['quality']) : '1080p Full HD';
            $lang = $this->formatLanguage($dl['lang'] ?? 'Español Latino');

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
        $r = strtolower($raw);
        if (strpos($r, 'lat') !== false) return 'Español Latino';
        if (strpos($r, 'esp') !== false || strpos($r, 'castellano') !== false) return 'Español Castellano';
        if (strpos($r, 'sub') !== false || strpos($r, 'vose') !== false) return 'Subtitulado';
        if (strpos($r, 'jap') !== false) return 'Japonés (Sub)';
        return ucfirst(trim($raw));
    }

    private function deduplicate(array $results): array
    {
        $seen = [];
        $unique = [];
        foreach ($results as $item) {
            $key = ($item['server'] ?? '') . '|' . ($item['url'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $item;
            }
        }
        return $unique;
    }
}

