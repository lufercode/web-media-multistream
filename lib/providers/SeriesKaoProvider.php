<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class SeriesKaoProvider implements ProviderInterface
{
    private string $id = 'serieskao';
    private string $name = 'SeriesKao (Películas y Series HD)';
    private array $hosts = ['https://serieskao.top', 'https://serieskao.org'];

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
        $clean_query = trim($title);
        if (empty($clean_query)) return [];

        $queries = [$clean_query];
        $simplified = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)));
        if (!empty($simplified) && strcasecmp($simplified, $clean_query) !== 0) {
            $queries[] = $simplified;
        }

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            foreach ($queries as $q) {
                $search_url = rtrim($host, '/') . '/search?s=' . urlencode($q);
                $headers = ['Referer' => rtrim($host, '/') . '/'];

                $html = http_get($search_url, ['headers' => $headers, 'timeout' => 6]);
                if (!$html || strlen($html) < 500) continue;

                preg_match_all('/<article class="card".*?<\/article>/s', $html, $cards);
                if (empty($cards[0])) continue;

                $matched_url = null;
                $matched_title = null;

                foreach ($cards[0] as $card) {
                    if (connection_aborted()) exit;
                    if (!preg_match('/href="([^"]+)"/', $card, $mUrl)) continue;
                    if (strpos($mUrl[1], '/pelicula/') === false && strpos($mUrl[1], '/peliculas/') === false && strpos($mUrl[1], '/anime/') === false && strpos($mUrl[1], '/animes/') === false) continue;

                    preg_match('/<h2 class="card__title">([^<]+)<\/h2>/', $card, $mTitle);
                    $card_title = $mTitle[1] ?? '';
                    $card_year = preg_match('/<span class="card__badge card__badge--year">([^<]+)<\/span>/', $card, $mY) ? $mY[1] : null;

                    $clean_cand = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $card_title);
                    $clean_cand = trim(preg_replace('/\[.*?\]/', '', $clean_cand));

                    if (is_strict_title_match($title, $clean_cand, $year, $card_year)) {
                        $matched_url = $mUrl[1];
                        $matched_title = $card_title ?: $title;
                        break;
                    }
                }

                if ($matched_url) {
                    if (strpos($matched_url, 'http') !== 0) {
                        $matched_url = rtrim($host, '/') . $matched_url;
                    }

                    $detail_html = http_get($matched_url, ['headers' => $headers, 'timeout' => 6]);
                    if ($detail_html) {
                        $extracted = $this->extractPlayers($detail_html, $matched_url, $matched_title, $host);
                        $results = array_merge($results, $extracted);
                    }
                    break 2;
                }
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
        $clean_query = trim($title);
        if (empty($clean_query)) return [];

        $queries = [$clean_query];
        $simplified = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', ' ', $title)));
        if (!empty($simplified) && strcasecmp($simplified, $clean_query) !== 0) {
            $queries[] = $simplified;
        }

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            foreach ($queries as $q) {
                $search_url = rtrim($host, '/') . '/search?s=' . urlencode($q);
                $headers = ['Referer' => rtrim($host, '/') . '/'];

                $html = http_get($search_url, ['headers' => $headers, 'timeout' => 6]);
                if (!$html || strlen($html) < 500) continue;

                preg_match_all('/<article class="card".*?<\/article>/s', $html, $cards);
                if (empty($cards[0])) continue;

                $matched_url = null;
                $matched_title = null;

                foreach ($cards[0] as $card) {
                    if (connection_aborted()) exit;
                    if (!preg_match('/href="([^"]+)"/', $card, $mUrl)) continue;
                    if (strpos($mUrl[1], '/serie/') === false && strpos($mUrl[1], '/series/') === false && strpos($mUrl[1], '/anime/') === false && strpos($mUrl[1], '/animes/') === false) continue;

                    preg_match('/<h2 class="card__title">([^<]+)<\/h2>/', $card, $mTitle);
                    $card_title = $mTitle[1] ?? '';

                    $clean_cand = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $card_title);
                    $clean_cand = trim(preg_replace('/\[.*?\]/', '', $clean_cand));

                    if (is_strict_title_match($title, $clean_cand)) {
                        $matched_url = $mUrl[1];
                        $matched_title = $clean_cand ?: $title;
                        break;
                    }
                }

                if ($matched_url) {
                    if (strpos($matched_url, 'http') !== 0) {
                        $matched_url = rtrim($host, '/') . $matched_url;
                    }

                    $series_html = http_get($matched_url, ['headers' => $headers, 'timeout' => 6]);
                    if (!$series_html) continue;

                    // Localizar el bloque de la temporada: id="season-{season}"
                    $matched_ep_url = null;
                    if (preg_match('/id="season-' . $season . '"(.*?)(?:id="season-\d+"|(?:\s*<\/div>\s*){2,}|$)/is', $series_html, $mSeasonBlock)) {
                        preg_match_all('/<a\s+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $mSeasonBlock[1], $epItems, PREG_SET_ORDER);
                        foreach ($epItems as $item) {
                            if (preg_match('/<span class="episode-item__number">\s*' . $episode . '\s*<\/span>/i', $item[2])) {
                                $matched_ep_url = $item[1];
                                break;
                            }
                            if (preg_match('/\/capitulo\/' . $episode . '\/?$/i', $item[1])) {
                                $matched_ep_url = $item[1];
                                break;
                            }
                        }
                    }

                    // Respaldo de búsqueda por URL de capítulo en toda la página
                    if (!$matched_ep_url) {
                        $fallback_pat = sprintf('/<a[^>]+href="([^"]*\/temporada\/%d\/capitulo\/%d\/?)"/i', $season, $episode);
                        if (preg_match($fallback_pat, $series_html, $mFallback)) {
                            $matched_ep_url = $mFallback[1];
                        }
                    }

                    if ($matched_ep_url) {
                        if (strpos($matched_ep_url, 'http') !== 0) {
                            $matched_ep_url = rtrim($host, '/') . $matched_ep_url;
                        }

                        $ep_html = http_get($matched_ep_url, ['headers' => ['Referer' => $matched_url], 'timeout' => 6]);
                        if ($ep_html) {
                            $formatted_title = sprintf('%s S%02dE%02d', $matched_title, $season, $episode);
                            $extracted = $this->extractPlayers($ep_html, $matched_ep_url, $formatted_title, $host);
                            $results = array_merge($results, $extracted);
                        }
                    }
                    break 2;
                }
            }
        }

        return $this->deduplicate($results);
    }

    private function extractPlayers(string $html, string $page_url, string $item_title, string $host): array
    {
        $links = [];

        // 1. Extraer iframe principal (Vidurl / Embed69)
        if (preg_match('/<iframe[^>]*id="player-iframe"[^>]*src="([^"]+)"/i', $html, $mIf) ||
            preg_match('/<iframe[^>]*src="([^"]+)"/i', $html, $mIf)) {
            
            $iframe_url = $mIf[1];
            if (strpos($iframe_url, '//') === 0) {
                $iframe_url = 'https:' . $iframe_url;
            } elseif (strpos($iframe_url, 'http') !== 0) {
                $iframe_url = rtrim($host, '/') . $iframe_url;
            }

            if (stripos($iframe_url, '/vidurl/') !== false || stripos($iframe_url, '/embed69.') !== false) {
                $vid_html = http_get($iframe_url, ['headers' => ['Referer' => $page_url], 'timeout' => 6]);
                if ($vid_html) {
                    $this->solveVidUrlPoWAndExtract($vid_html, $item_title, $links);
                }
            } elseif (stripos($iframe_url, '/waaw.') !== false || stripos($iframe_url, '/netu.') !== false) {
                $links[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'SeriesKao',
                    'type' => 'streaming',
                    'title' => $item_title,
                    'server' => 'Waaw',
                    'quality' => '1080p Full HD',
                    'language' => 'Español Latino',
                    'url' => $iframe_url,
                    'size' => null
                ];
            }
        }

        // 2. Extraer opciones secundarias de go_to_player en base64
        preg_match_all('/<li[^>]*onclick="go_to_player[^"]*"[^>]*>(.*?)<\/li>/is', $html, $mGo);
        foreach ($mGo[0] as $idx => $rawLi) {
            if (!preg_match('/go_to_player\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $rawLi, $mB64)) continue;

            $b64 = $mB64[1];
            $decoded_url = @base64_decode($b64);
            if (!$decoded_url || strpos($decoded_url, 'http') !== 0) continue;

            // Extraer idioma
            $lang_code = preg_match('/data-lang="([^"]+)"/i', $rawLi, $mLang) ? $mLang[1] : '';
            $language = $this->formatLanguage($lang_code);

            // Extraer nombre del servidor
            $srv = '';
            if (preg_match('/<span>(.*?)<\/span>/i', $rawLi, $mSrv)) {
                $srv = trim(strip_tags($mSrv[1]));
            }
            $server_name = $this->detectServerName($decoded_url, $srv);

            $is_dl = (stripos($decoded_url, '/download') !== false || stripos($rawLi, 'download') !== false || stripos($srv, 'descarga') !== false);

            $links[] = [
                'provider' => $this->getId(),
                'provider_name' => 'SeriesKao',
                'type' => $is_dl ? 'direct' : 'streaming',
                'title' => $item_title,
                'server' => $server_name,
                'quality' => '1080p Full HD',
                'language' => $language,
                'url' => $decoded_url,
                'size' => null
            ];
        }

        return $links;
    }

    private function solveVidUrlPoWAndExtract(string $html, string $item_title, array &$links): void
    {
        // 1. Extraer parámetros del desafío Proof-of-Work
        if (!preg_match('/POW_CHALLENGE\s*=\s*\'([^\']+)\'/', $html, $mC) ||
            !preg_match('/POW_DIFFICULTY\s*=\s*(\d+)/', $html, $mD) ||
            !preg_match('/POW_SALT\s*=\s*\'([^\']+)\'/', $html, $mS)) {
            return;
        }

        $challenge = $mC[1];
        $difficulty = (int)$mD[1];
        $salt = $mS[1];

        // Resolver PoW (busca hash sha256 con prefijo '000')
        $prefix = str_repeat('0', $difficulty);
        $nonce = 0;
        $aesKey = null;

        while ($nonce <= 1000000) {
            $candidate = $challenge . $nonce;
            if (strpos(hash('sha256', $candidate), $prefix) === 0) {
                $aesKey = hash('sha256', $challenge . $nonce . $salt, true);
                break;
            }
            $nonce++;
        }

        if (!$aesKey) return;

        // 2. Extraer dataLink
        if (!preg_match('/dataLink\s*=\s*(.*?);/s', $html, $mDl)) return;

        $dataLink = json_decode($mDl[1], true);
        if (!is_array($dataLink)) return;

        foreach ($dataLink as $group) {
            $raw_lang = $group['video_language'] ?? 'LAT';
            $language = $this->formatLanguage($raw_lang);

            // Enlaces de streaming
            foreach ($group['sortedEmbeds'] ?? [] as $emb) {
                $crypto = $emb['link'] ?? '';
                if (empty($crypto)) continue;

                $decrypted_url = $this->decryptAesCbc($crypto, $aesKey);
                if (!$decrypted_url || strpos($decrypted_url, 'http') !== 0) continue;

                $server_name = $this->detectServerName($decrypted_url, $emb['servername'] ?? '');

                $links[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'SeriesKao',
                    'type' => 'streaming',
                    'title' => $item_title,
                    'server' => $server_name,
                    'quality' => '1080p Full HD',
                    'language' => $language,
                    'url' => $decrypted_url,
                    'size' => null
                ];
            }

            // Enlaces de descarga directa
            foreach ($group['downloadEmbeds'] ?? [] as $emb) {
                $crypto = $emb['link'] ?? '';
                if (empty($crypto)) continue;

                $decrypted_url = $this->decryptAesCbc($crypto, $aesKey);
                if (!$decrypted_url || strpos($decrypted_url, 'http') !== 0) continue;

                $server_name = $this->detectServerName($decrypted_url, $emb['servername'] ?? '');

                $links[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'SeriesKao',
                    'type' => 'direct',
                    'title' => $item_title,
                    'server' => $server_name,
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
        if (strpos($u, 'voe.sx') !== false || strpos($u, 'voe') !== false) return 'Voe';
        if (strpos($u, 'wish') !== false || strpos($u, 'hglink') !== false) return 'StreamWish';
        if (strpos($u, 'vidhide') !== false || strpos($u, 'morencius') !== false) return 'VidHide';
        if (strpos($u, 'rapidvideo') !== false) return 'RapidVideo';
        if (strpos($u, 'streamtape') !== false) return 'Streamtape';
        if (strpos($u, 'filemoon') !== false) return 'Filemoon';
        if (strpos($u, 'goodstream') !== false) return 'Goodstream';
        if (strpos($u, 'waaw') !== false || strpos($u, 'netu') !== false) return 'Waaw';

        $clean_def = trim($default);
        if (!empty($clean_def)) {
            $cd = strtolower($clean_def);
            if ($cd === 'stp') return 'Streamtape';
            if ($cd === 'vox') return 'Voe';
            return ucfirst($clean_def);
        }
        return 'Online Player';
    }

    private function formatLanguage(string $raw): string
    {
        $r = strtoupper(trim($raw));
        if ($r === 'LAT' || $r === '0') return 'Español Latino';
        if ($r === 'ESP' || $r === '1') return 'Español Castellano';
        if ($r === 'SUB' || $r === '2' || $r === 'VOSE') return 'Subtitulado';
        if ($r === 'JAP' || $r === '3') return 'Japonés (Sub)';
        return 'Español Latino';
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


