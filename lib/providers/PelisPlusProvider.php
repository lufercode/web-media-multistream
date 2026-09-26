<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class PelisPlusProvider implements ProviderInterface
{
    private array $hosts = [
        'https://pelisplushd.bz'
    ];

    public function getId(): string
    {
        return 'pelisplus';
    }

    public function getName(): string
    {
        return 'PelisPlus HD (Películas, Series y Anime Latino)';
    }

    public function getType(): string
    {
        return 'mixed';
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
        $queries = array_values(array_unique(array_filter([
            trim($title),
            trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)))
        ])));

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            foreach ($queries as $q) {
                $search_url = rtrim($host, '/') . '/search?s=' . urlencode($q);
                $html = http_get($search_url, ['timeout' => 5]);
                if (!$html || strlen($html) < 500) continue;

                preg_match_all('/<a\s+href="([^"]*\/(?:pelicula|anime)\/[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                if (empty($matches)) continue;

                foreach ($matches as $m) {
                    if (connection_aborted()) exit;
                    $rel_url = $m[1];
                    $inner_text = trim(strip_tags($m[2]));
                    $slug = basename(rtrim($rel_url, '/'));
                    $cand_title = str_replace('-', ' ', $slug);

                    $cand_year = null;
                    if (preg_match('/\((19\d{2}|20\d{2})\)/', $inner_text, $ym)) {
                        $cand_year = $ym[1];
                    }

                    $clean_inner = preg_replace('/^(?:Pel[ií]cula|Anime|Serie)\s+/iu', '', $inner_text);
                    $clean_inner = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $clean_inner);

                    if (!is_strict_title_match($title, $cand_title, $year, $cand_year) &&
                        !is_strict_title_match($title, $clean_inner, $year, $cand_year)) {
                        continue;
                    }

                    $movie_url = (strpos($rel_url, 'http') === 0) ? $rel_url : rtrim($host, '/') . '/' . ltrim($rel_url, '/');
                    $detail_html = http_get($movie_url, ['timeout' => 5]);
                    if (!$detail_html) continue;

                    $this->extractFromDetailHtml($detail_html, $movie_url, $title, $results);
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
        $queries = array_values(array_unique(array_filter([
            trim($title),
            trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)))
        ])));

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            foreach ($queries as $q) {
                $search_url = rtrim($host, '/') . '/search?s=' . urlencode($q);
                $html = http_get($search_url, ['timeout' => 5]);
                if (!$html || strlen($html) < 500) continue;

                // Soportar tanto /serie/ como /anime/
                preg_match_all('/<a\s+href="([^"]*\/(serie|series|anime|animes)\/[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                if (empty($matches)) continue;

                foreach ($matches as $m) {
                    if (connection_aborted()) exit;
                    $rel_url = $m[1];
                    $section = strtolower($m[2]);
                    $inner_text = trim(strip_tags($m[3]));
                    $slug = basename(rtrim($rel_url, '/'));
                    $cand_title = str_replace('-', ' ', $slug);

                    $clean_inner = preg_replace('/^(?:Pel[ií]cula|Anime|Serie)\s+/iu', '', $inner_text);
                    $clean_inner = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $clean_inner);
                    $clean_inner = trim($clean_inner);

                    if (!is_strict_title_match($title, $cand_title) &&
                        !is_strict_title_match($title, $clean_inner) &&
                        !is_anime_title_match($title, $clean_inner)) {
                        continue;
                    }

                    $base_series_url = (strpos($rel_url, 'http') === 0) ? rtrim($rel_url, '/') : rtrim($host, '/') . '/' . trim($rel_url, '/');
                    $ep_url = "{$base_series_url}/temporada/{$season}/capitulo/{$episode}";
                    $ep_html = http_get($ep_url, ['timeout' => 5]);

                    if ((!$ep_html || strlen($ep_html) < 1000) && $season >= 2 && $absolute_episode !== null && $absolute_episode > $episode) {
                        $ep_url = "{$base_series_url}/temporada/1/capitulo/{$absolute_episode}";
                        $ep_html = http_get($ep_url, ['timeout' => 5]);
                    }

                    if (!$ep_html) continue;

                    $formatted_title = sprintf('%s S%02dE%02d', $clean_inner ?: $title, $season, $episode);
                    $this->extractFromDetailHtml($ep_html, $ep_url, $formatted_title, $results);

                    if (!empty($results)) break 2;
                }
            }
        }

        return $this->deduplicate($results);
    }

    private function extractFromDetailHtml(string $html, string $page_url, string $item_title, array &$results): void
    {
        // 1. Extraer enlaces tipo video[N] = 'https://embed69.org/f/...' o reproductores embebidos
        if (preg_match_all('/video\[\d+\]\s*=\s*[\'"](https?:\/\/[^\'"]+)[\'"]/i', $html, $vMatches)) {
            foreach (array_unique($vMatches[1]) as $vUrl) {
                if (stripos($vUrl, 'embed69.') !== false || stripos($vUrl, '/vidurl/') !== false) {
                    $vid_html = http_get($vUrl, ['headers' => ['Referer' => $page_url], 'timeout' => 5]);
                    if ($vid_html) {
                        $this->solveEmbed69PoWAndExtract($vid_html, $item_title, $results);
                    }
                } else {
                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'PelisPlus',
                        'type' => 'streaming',
                        'title' => $item_title,
                        'server' => $this->detectServerName($vUrl),
                        'quality' => '1080p Full HD',
                        'language' => 'Español Latino',
                        'url' => $vUrl,
                        'size' => null
                    ];
                }
            }
        }

        // 2. Extraer servidores data-url y data-name tradicionales
        if (preg_match_all('/data-url="([^"]+)"[^>]*data-name="([^"]*)"/i', $html, $servers, PREG_SET_ORDER)) {
            foreach ($servers as $srv) {
                $player_url = $srv[1];
                if (strpos($player_url, '//') === 0) $player_url = 'https:' . $player_url;
                if (strpos($player_url, 'http') !== 0) continue;

                $lang = $srv[2] ?: 'Español Latino';
                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'PelisPlus',
                    'type' => 'streaming',
                    'title' => $item_title,
                    'server' => $this->detectServerName($player_url),
                    'quality' => '1080p Full HD',
                    'language' => $lang,
                    'url' => $player_url,
                    'size' => null
                ];
            }
        }
    }

    private function solveEmbed69PoWAndExtract(string $html, string $item_title, array &$links): void
    {
        if (!preg_match('/POW_CHALLENGE\s*=\s*\'([^\']+)\'/', $html, $mC) ||
            !preg_match('/POW_DIFFICULTY\s*=\s*(\d+)/', $html, $mD) ||
            !preg_match('/POW_SALT\s*=\s*\'([^\']+)\'/', $html, $mS)) {
            return;
        }

        $challenge = $mC[1];
        $difficulty = (int)$mD[1];
        $salt = $mS[1];

        $prefix = str_repeat('0', $difficulty);
        $nonce = 0;
        $aesKey = null;

        while ($nonce <= 500000) {
            if (strpos(hash('sha256', $challenge . $nonce), $prefix) === 0) {
                $aesKey = hash('sha256', $challenge . $nonce . $salt, true);
                break;
            }
            $nonce++;
        }

        if (!$aesKey || !preg_match('/dataLink\s*=\s*(.*?);/s', $html, $mDl)) return;

        $dataLink = json_decode($mDl[1], true);
        if (!is_array($dataLink)) return;

        foreach ($dataLink as $group) {
            $raw_lang = strtoupper(trim($group['video_language'] ?? 'LAT'));
            $language = 'Español Latino';
            if ($raw_lang === 'ESP' || $raw_lang === '1') $language = 'Español Castellano';
            elseif ($raw_lang === 'SUB' || $raw_lang === '2' || $raw_lang === 'VOSE') $language = 'Subtitulado';
            elseif ($raw_lang === 'JAP' || $raw_lang === '3') $language = 'Japonés (Sub)';

            foreach ($group['sortedEmbeds'] ?? [] as $emb) {
                $decrypted_url = $this->decryptAesCbc($emb['link'] ?? '', $aesKey);
                if (!$decrypted_url || strpos($decrypted_url, 'http') !== 0) continue;

                $links[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'PelisPlus',
                    'type' => 'streaming',
                    'title' => $item_title,
                    'server' => $this->detectServerName($decrypted_url, $emb['servername'] ?? ''),
                    'quality' => '1080p Full HD',
                    'language' => $language,
                    'url' => $decrypted_url,
                    'size' => null
                ];
            }

            foreach ($group['downloadEmbeds'] ?? [] as $emb) {
                $decrypted_url = $this->decryptAesCbc($emb['link'] ?? '', $aesKey);
                if (!$decrypted_url || strpos($decrypted_url, 'http') !== 0) continue;

                $links[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'PelisPlus',
                    'type' => 'direct',
                    'title' => $item_title,
                    'server' => $this->detectServerName($decrypted_url, $emb['servername'] ?? ''),
                    'quality' => '1080p Full HD',
                    'language' => $language,
                    'url' => $decrypted_url,
                    'size' => null
                ];
            }
        }
    }

    private function decryptAesCbc(string $cryptoB64, string $rawKey): string
    {
        $data = @base64_decode($cryptoB64);
        if (!$data || strlen($data) < 17) return '';
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
        return $decrypted ? trim($decrypted) : '';
    }

    private function detectServerName(string $url, string $default = ''): string
    {
        $u = strtolower($url);
        if (strpos($u, 'voe') !== false) return 'Voe';
        if (strpos($u, 'wish') !== false || strpos($u, 'hglink') !== false) return 'StreamWish';
        if (strpos($u, 'vidhide') !== false || strpos($u, 'morencius') !== false) return 'VidHide';
        if (strpos($u, 'tape') !== false) return 'Streamtape';
        if (strpos($u, 'filemoon') !== false) return 'Filemoon';
        if (strpos($u, 'vimeos') !== false) return 'Vimeos';
        if (strpos($u, 'waaw') !== false || strpos($u, 'netu') !== false) return 'Netu';
        if (!empty($default)) return ucfirst(trim($default));
        return 'Servidor Online';
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
