<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class JKAnimeProvider implements ProviderInterface
{
    private string $id   = 'jkanime';
    private string $name = 'JKAnime (Anime en Español Latino y Subtitulado HD)';
    private string $host = 'https://jkanime.net';

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

        $queries = array_values(array_unique(array_filter([
            trim($title),
            trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s.]/u', ' ', $title))),
        ])));

        if ($tmdb_id !== null && function_exists('get_tmdb_data')) {
            $alt_data = get_tmdb_data("tv/{$tmdb_id}/alternative_titles");
            foreach ($alt_data['results'] ?? [] as $alt) {
                $alt_title = trim($alt['title'] ?? '');
                if (!empty($alt_title)) $queries[] = $alt_title;
            }
            $details = get_tmdb_data("tv/{$tmdb_id}");
            $orig_name = trim($details['original_name'] ?? '');
            if (!empty($orig_name)) $queries[] = $orig_name;
        }
        $queries = array_values(array_unique(array_filter($queries)));

        $matched_cards = [];

        foreach ($queries as $q) {
            if (connection_aborted()) exit;

            $search_url = $this->host . '/buscar/' . rawurlencode($q) . '/';
            $html = http_get($search_url, [
                'timeout' => 5,
                'headers' => ['Referer' => $this->host . '/'],
            ]);
            if (!$html || strlen($html) < 500) continue;

            /* Tarjetas de resultados de JKAnime: <h5> envuelve <a href="...">Título</a> */
            preg_match_all('/<div[^>]+class="[^"]*anime__item[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $cards);
            foreach ($cards[0] as $card) {
                if (!preg_match('/href="(https?:\/\/jkanime\.net\/([^"\/]+)\/)"/i', $card, $mUrl)) continue;
                if (!preg_match('/<h5[^>]*>(.*?)<\/h5>/is', $card, $mTitle)) continue;

                $cand = trim(html_entity_decode(strip_tags($mTitle[1]), ENT_QUOTES, 'UTF-8'));
                if ($cand === '') continue;

                $is_match = false;
                foreach ($queries as $q) {
                    if (is_anime_title_match($q, $cand) || is_strict_title_match($q, $cand)) {
                        $is_match = true;
                        break;
                    }
                }

                if ($is_match) {
                    $card_default_lang = 'Japonés (Sub Latino)';
                    if (preg_match('/\b(latino|doblado|audio\s*latino)\b/i', $cand)) {
                        $card_default_lang = 'Español Latino';
                    }
                    $matched_cards[] = [
                        'url'   => $mUrl[1],
                        'slug'  => $mUrl[2],
                        'title' => $cand,
                        'lang'  => $card_default_lang,
                    ];
                }
            }
            if (!empty($matched_cards)) break;
        }

        if (empty($matched_cards)) return [];

        /* Ordenar candidatos priorizando el que mejor coincida con la temporada solicitada */
        usort($matched_cards, function ($a, $b) use ($season, $episode) {
            $score = function ($item) use ($season, $episode) {
                $t = strtolower($item['title'] . ' ' . $item['slug']);
                if (preg_match('/\b(movie|pelicula|ova|ona|especial|special)\b/i', $t)) {
                    return -10;
                }
                $roman_map = [2 => 'ii', 3 => 'iii', 4 => 'iv', 5 => 'v', 6 => 'vi', 7 => 'vii', 8 => 'viii'];
                $rom = $roman_map[$season] ?? '';
                if ($season === 1) {
                    if (preg_match('/(?:^|[-_ ])(?:s[2-9]|temporada[-_ ]*[2-9]|season[-_ ]*[2-9]|part[-_ ]*[2-9]|parte[-_ ]*[2-9]|[-_ ](?:ii|iii|iv|v|vi|[2-9])\b|2nd|3rd|4th)(?:[-_ ]|$)/i', $t)) {
                        return 0;
                    }
                    return 10;
                }
                if ($season >= 2 && $episode > 12 && preg_match('/\b(part\s*2|parte\s*2|cour\s*2)\b/i', $t)) {
                    return 25;
                }
                if ($rom && preg_match('/(?:^|[-_ ])' . $rom . '(?:[-_ ]|$)/i', $t)) {
                    return 22;
                }
                if (preg_match('/\b(' . $season . '(?:nd|rd|th)?\s*season|season\s*' . $season . '|temporada\s*' . $season . ')\b/i', $t)) {
                    return 20;
                }
                return 5;
            };
            return $score($b) <=> $score($a);
        });

        $results = [];
        $formatted_title = sprintf('%s S%02dE%02d', $title, $season, $episode);

        foreach (array_slice($matched_cards, 0, 3) as $cand_card) {
            $anime_url  = $cand_card['url'];
            $anime_slug = $cand_card['slug'];
            $default_lang = $cand_card['lang'];

            /* Construir números de episodio candidatos (incluyendo ajuste de Part 2 cuando $episode > 12) */
            $ep_candidates = [$episode];
            if ($season >= 2 && $episode > 12 && preg_match('/(?:part-2|parte-2)/i', $anime_slug)) {
                array_unshift($ep_candidates, $episode - 12);
            }
            if ($season >= 2 && $absolute_episode !== null && $absolute_episode !== $episode) {
                $ep_candidates[] = $absolute_episode;
            }
            $ep_candidates = array_values(array_unique(array_filter($ep_candidates, fn($n) => $n > 0)));

            foreach ($ep_candidates as $ep_num) {
                if (connection_aborted()) exit;

                $ep_url  = $this->host . '/' . $anime_slug . '/' . $ep_num . '/';
                $ep_html = http_get($ep_url, [
                    'timeout' => 5,
                    'headers' => ['Referer' => $anime_url],
                ]);
                if (!$ep_html || strlen($ep_html) < 1500) continue;

                /* 1. Mapear códigos de idioma desde <select id="deflang"> (ej: 1 = Japones Sub. Español) */
                $lang_map = [];
                if (preg_match('/<select[^>]+id=["\']deflang["\'][^>]*>(.*?)<\/select>/is', $ep_html, $mSelect)) {
                    if (preg_match_all('/<option[^>]+value=["\'](\d+)["\'][^>]*>(.*?)<\/option>/is', $mSelect[1], $mOpts, PREG_SET_ORDER)) {
                        foreach ($mOpts as $opt) {
                            $lang_map[(string)$opt[1]] = $this->formatJkLanguage(strip_tags($opt[2]));
                        }
                    }
                }
                if (!empty($lang_map)) {
                    $first_key = array_key_first($lang_map);
                    $default_lang = $lang_map[$first_key];
                }

                /* 2. Extraer var servers = [{"remote":"<base64>","server":"VOE","lang":1}] */
                if (preg_match('/var\s+servers\s*=\s*(\[\s*\{.*?\}\s*\])\s*;/is', $ep_html, $mServers)) {
                    $servers_arr = json_decode($mServers[1], true);
                    if (is_array($servers_arr)) {
                        foreach ($servers_arr as $srv) {
                            $b64 = trim($srv['remote'] ?? '');
                            if ($b64 === '') continue;
                            $decoded_url = trim((string)base64_decode($b64, true));
                            if (!preg_match('/^https?:\/\//i', $decoded_url)) continue;

                            $srv_name = $this->detectServerName($decoded_url, $srv['server'] ?? '');
                            $lang_code = isset($srv['lang']) ? (string)$srv['lang'] : '';
                            $srv_lang = $lang_map[$lang_code] ?? $default_lang;

                            $results[] = [
                                'provider'      => $this->id,
                                'provider_name' => 'JKAnime',
                                'type'          => 'streaming',
                                'title'         => $formatted_title,
                                'server'        => $srv_name,
                                'quality'       => '1080p Full HD',
                                'language'      => $srv_lang,
                                'url'           => $decoded_url,
                                'size'          => null,
                            ];
                        }
                    }
                }

                /* 3. Extraer iframes directos adicionales */
                $pattern = '/(?:src|data-src)=["\']' .
                           '(https?:\/\/(?:[^"\']*' .
                           '(?:voe\.sx|wish|streamwish|hglink|vidhide|morencius|filemoon|fembed|mp4upload|streamtape|luluvdoo|jk\.php)' .
                           '[^"\']*))["\']/i';
                if (preg_match_all($pattern, $ep_html, $mIframes) && !empty($mIframes[1])) {
                    foreach (array_unique($mIframes[1]) as $embed_url) {
                        $server = $this->detectServerName($embed_url);
                        $results[] = [
                            'provider'      => $this->id,
                            'provider_name' => 'JKAnime',
                            'type'          => 'streaming',
                            'title'         => $formatted_title,
                            'server'        => $server,
                            'quality'       => '1080p Full HD',
                            'language'      => $default_lang,
                            'url'           => $embed_url,
                            'size'          => null,
                        ];
                    }
                }

                /* 4. Extraer enlaces de descarga directa (MediaFire, Mega, 1Fichier) */
                if (preg_match_all('/href=["\'](https?:\/\/(?:www\.)?(?:mediafire\.com|mega\.nz|1fichier\.com)\/[^"\'\s<>]+)["\']/i', $ep_html, $mDl) && !empty($mDl[1])) {
                    foreach (array_unique($mDl[1]) as $dl_url) {
                        $dl_server = stripos($dl_url, 'mediafire') !== false ? 'MediaFire' : (stripos($dl_url, 'mega') !== false ? 'Mega' : '1Fichier');
                        $results[] = [
                            'provider'      => $this->id,
                            'provider_name' => 'JKAnime',
                            'type'          => 'direct',
                            'title'         => $formatted_title,
                            'server'        => $dl_server,
                            'quality'       => '1080p Full HD',
                            'language'      => $default_lang,
                            'url'           => $dl_url,
                            'size'          => null,
                        ];
                    }
                }

                if (!empty($results)) break 2;
            }
        }

        return $this->deduplicate($results);
    }

    /* ------------------------------------------------------------------ */

    private function formatJkLanguage(string $raw): string
    {
        $r = mb_strtolower(trim($raw), 'UTF-8');
        if (strpos($r, 'sub') !== false || strpos($r, 'jap') !== false || strpos($r, 'vose') !== false) {
            return 'Japonés (Sub Latino)';
        }
        if (strpos($r, 'castellano') !== false || strpos($r, 'españa') !== false) {
            return 'Español Castellano';
        }
        if (strpos($r, 'lat') !== false) {
            return 'Español Latino';
        }
        if (strpos($r, 'esp') !== false) {
            return 'Español Latino';
        }
        return 'Japonés (Sub Latino)';
    }

    private function detectServerName(string $url, string $fallback = ''): string
    {
        $u = strtolower($url . ' ' . $fallback);
        if (strpos($u, 'voe')        !== false) return 'Voe';
        if (strpos($u, 'filemoon')   !== false) return 'Filemoon';
        if (strpos($u, 'wish')       !== false || strpos($u, 'hglink') !== false) return 'StreamWish';
        if (strpos($u, 'vidhide')    !== false || strpos($u, 'morencius') !== false) return 'VidHide';
        if (strpos($u, 'fembed')     !== false) return 'Fembed';
        if (strpos($u, 'mp4upload')  !== false) return 'Mp4Upload';
        if (strpos($u, 'streamtape') !== false) return 'Streamtape';
        if (strpos($u, 'lulu')       !== false) return 'LuluStream';
        if (strpos($u, 'jk.php')     !== false) return 'JK Player';
        if (trim($fallback) !== '') return ucfirst(strtolower(trim($fallback)));
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

