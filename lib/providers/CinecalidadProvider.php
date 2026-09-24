<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../resolvers/CinecalidadResolver.php';

class CinecalidadProvider implements ProviderInterface
{
    /**
     * Lista unificada de espejos oficiales y verificados de Cinecalidad.
     * Cualquier dominio puede contener tanto servidores de streaming como torrents (4K/1080p) y descargas directas.
     */
    private array $hosts = [
        'https://www.cinecalidad.ro/',
        'https://cinecalidad.re/',
        'https://cinecalidad.fun/',
        'https://www.cinecalidad.my/'
    ];

    private CinecalidadResolver $resolver;

    public function __construct()
    {
        $this->resolver = new CinecalidadResolver();
    }

    public function getId(): string
    {
        return 'cinecalidad';
    }

    public function getName(): string
    {
        return 'Cinecalidad (Latino / 4K / Torrents)';
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['cinecalidad']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['cinecalidad']['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $all_results = [];
        $search_titles = array_unique(array_filter([
            trim($title),
            trim(preg_replace('/^(the|los|las|el|la)\s+/i', '', $title))
        ]));

        $has_streaming = false;
        $has_4k_torrent = false;

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            if ($has_streaming && $has_4k_torrent) {
                break;
            }

            foreach ($search_titles as $search_term) {
                $query_clean = preg_replace('/[^\w\s]/u', ' ', $search_term);
                $query_clean = trim(preg_replace('/\s+/', ' ', $query_clean));

                $search_url = rtrim($host, '/') . '/?s=' . urlencode($query_clean);
                $html = http_get($search_url, ['timeout' => 4]);
                if (!$html || strlen($html) < 300) {
                    continue;
                }

                $matched_url = null;
                $matched_title = null;

                preg_match_all('/<a[^>]+href=([\'"]?)(https?:\/\/[^\'"\s>]*(?:pelicula|movies)\/[^\'"\s>]+)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    if (connection_aborted()) exit;

                    $movie_url = $m[2];
                    $inner = $m[3];
                    $movie_title = '';
                    if (preg_match('/alt=([\'"]?)([^\'"\>]+)\1/i', $inner, $alt)) {
                        $movie_title = html_entity_decode($alt[2], ENT_QUOTES, 'UTF-8');
                    } elseif (preg_match('/<span[^>]*class=[\'"]sr-only[\'"][^>]*>(.*?)<\/span>/is', $inner, $sm)) {
                        $movie_title = trim(strip_tags($sm[1]));
                    } else {
                        $movie_title = trim(strip_tags($inner));
                    }

                    if (empty($movie_title)) continue;

                    $cand_year = null;
                    if (preg_match('/\b(19\d{2}|20\d{2})\b/', $movie_title . ' ' . $movie_url, $ym)) {
                        $cand_year = $ym[1];
                    }

                    $clean_t = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $movie_title);
                    $clean_t = preg_replace('/\[.*?\]/', '', $clean_t);
                    $clean_t = preg_replace('/&lt;!--.*?--&gt;/i', '', $clean_t);
                    $clean_t = trim($clean_t);

                    $is_match = false;
                    foreach ($search_titles as $st) {
                        if (is_strict_title_match($st, $clean_t, $year, $cand_year) || stripos($clean_t, $st) !== false) {
                            $is_match = true;
                            break;
                        }
                    }

                    if ($is_match) {
                        $matched_url = $movie_url;
                        $matched_title = $movie_title ?: $title;
                        break;
                    }
                }

                if ($matched_url) {
                    $detail_html = http_get($matched_url, ['timeout' => 5]);
                    if ($detail_html) {
                        $extracted = $this->extractAllSourcesFromDetail($detail_html, $matched_title, $host);
                        foreach ($extracted as $src) {
                            if ($src['type'] === 'streaming') $has_streaming = true;
                            if (stripos($src['quality'], '4K') !== false) $has_4k_torrent = true;
                            $all_results[] = $src;
                        }
                    }
                    break 2;
                }
            }
        }

        // Deduplicar por URL
        $unique = [];
        $deduped = [];
        foreach ($all_results as $r) {
            if (!isset($unique[$r['url']])) {
                $unique[$r['url']] = true;
                $deduped[] = $r;
            }
        }

        return $deduped;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $all_results = [];
        $query_clean = preg_replace('/[^\w\s]/u', ' ', $title);
        $query_clean = trim(preg_replace('/\s+/', ' ', $query_clean));

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/?s=' . urlencode($query_clean);
            $html = http_get($search_url, ['timeout' => 5]);
            if (!$html || strlen($html) < 300) {
                continue;
            }

            preg_match_all('/<article[^>]*>(.*?)<\/article>/is', $html, $articles);
            $matched_series_url = null;
            $matched_series_title = null;

            foreach ($articles[0] as $art) {
                if (connection_aborted()) exit;

                if (!preg_match('/href=(["\']?)(https?:\/\/[^"\'\s>]*\/(?:serie|series|tv)\/[^"\'\s>]+)\1/i', $art, $hm)) {
                    continue;
                }
                $series_url = $hm[2];

                $series_title = '';
                if (preg_match('/<span class="sr-only">(.*?)<\/span>/is', $art, $sm)) {
                    $series_title = trim(strip_tags($sm[1]));
                } elseif (preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $art, $tm)) {
                    $series_title = trim(strip_tags($tm[1]));
                } elseif (preg_match('/alt=(["\']?)([^"\'\>]+)\1/i', $art, $am)) {
                    $series_title = trim($am[2]);
                }

                $clean_t = preg_replace('/\s*\((?:19|20)\d{2}\).*/', '', $series_title);
                $clean_t = preg_replace('/\[.*?\]/', '', $clean_t);
                $clean_t = trim($clean_t);

                if (is_strict_title_match($title, $clean_t)) {
                    $matched_series_url = $series_url;
                    $matched_series_title = $series_title ?: $title;
                    break;
                }
            }

            if ($matched_series_url) {
                $detail_html = http_get($matched_series_url, ['timeout' => 5]);
                if (!$detail_html) continue;

                // Buscar enlace del episodio: ej. /episodes/loki-1x1/ o /episodio/loki-1x1/
                $ep_pattern = sprintf('/<a[^>]+href=(["\']?)(https?:\/\/[^"\'\s>]*\/(?:episodio|episodes)\/[^\'"]*-%dx%d\/?)\1/i', $season, $episode);
                if (!preg_match($ep_pattern, $detail_html, $ep_match)) {
                    $ep_pattern = sprintf('/<a[^>]+href=(["\']?)(https?:\/\/[^"\'\s>]*\/(?:episodio|episodes)\/[^\'"]*)\1[^>]*>.*?(?:%dx%d|episodio\s*%d)/is', $season, $episode, $episode);
                    preg_match($ep_pattern, $detail_html, $ep_match);
                }

                if (!empty($ep_match[2])) {
                    $ep_url = $ep_match[2];
                    $ep_html = http_get($ep_url, ['timeout' => 5]);
                    if ($ep_html) {
                        $ep_title = sprintf('%s S%02dE%02d', $matched_series_title, $season, $episode);
                        $extracted = $this->extractAllSourcesFromDetail($ep_html, $ep_title, $host);
                        foreach ($extracted as $src) {
                            $all_results[] = $src;
                        }
                    }
                }
                break;
            }
        }

        // Deduplicar por URL
        $unique = [];
        $deduped = [];
        foreach ($all_results as $r) {
            if (!isset($unique[$r['url']])) {
                $unique[$r['url']] = true;
                $deduped[] = $r;
            }
        }

        return $deduped;
    }

    /**
     * Extrae de forma unificada TODOS los enlaces presentes en la página de detalle:
     * 1. Streaming (data-src en Base64)
     * 2. Torrents y Descargas Directas (data-url en Base64)
     * 3. Torrents y Descargas protegidas (/protect/ver.php)
     * 4. Magnets directos (href="magnet:?...")
     */
    private function extractAllSourcesFromDetail(string $detail_html, string $matched_title, string $host): array
    {
        $sources = [];

        // 1. STREAMING: data-src (Base64)
        preg_match_all('/data-src=(["\']?)([^"\'\s>]+)\1/i', $detail_html, $dsrcs);
        foreach (array_unique($dsrcs[2] ?? []) as $enc) {
            $decoded_url = $this->resolver->decode($enc);
            if ($decoded_url) {
                $server_name = 'Vimeos Player';
                if (stripos($decoded_url, 'wish') !== false) $server_name = 'StreamWish';
                elseif (stripos($decoded_url, 'voe') !== false) $server_name = 'Voe';
                elseif (stripos($decoded_url, 'filemoon') !== false) $server_name = 'Filemoon';
                elseif (stripos($decoded_url, 'goodstream') !== false) $server_name = 'Goodstream';
                elseif (stripos($decoded_url, 'videoapp') !== false) $server_name = 'VideoApp';

                $sources[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'Cinecalidad',
                    'type' => 'streaming',
                    'title' => $matched_title,
                    'server' => $server_name,
                    'quality' => '1080p Full HD',
                    'language' => 'Español Latino',
                    'url' => $decoded_url,
                    'size' => null
                ];
            }
        }

        // 2. TORRENTS Y DESCARGAS DIRECTAS: data-url (Base64)
        preg_match_all('/<li[^>]*>.*?<a[^>]+data-url=(["\']?)([^"\'\s>]+)\1[^>]*>(.*?)<\/a>(?:.*?<span[^>]*>(.*?)<\/span>)?.*?<\/li>/is', $detail_html, $items, PREG_SET_ORDER);
        foreach ($items as $item) {
            $enc = $item[2];
            $btn_text = trim(strip_tags($item[3]));
            $badge = isset($item[4]) ? trim(strip_tags($item[4])) : '';

            $dec1 = base64_decode($enc);
            if (!$dec1) continue;

            $final_url = null;
            if (strpos($dec1, 'magnet:?') === 0) {
                $final_url = $dec1;
            } elseif (preg_match('#/links/([A-Za-z0-9+/=]+)#', $dec1, $lm)) {
                $final_url = base64_decode($lm[1]);
            }

            if ($final_url && (strpos($final_url, 'http://') === 0 || strpos($final_url, 'https://') === 0 || strpos($final_url, 'magnet:?') === 0)) {
                $is_torrent = (strpos($final_url, 'magnet:?') === 0);
                $quality = '1080p Full HD';
                if (stripos($badge, '4k') !== false || stripos($final_url, '2160p') !== false) {
                    $quality = '4K UHD';
                } elseif (stripos($badge, '720p') !== false) {
                    $quality = '720p HD';
                }

                if ($is_torrent) {
                    $sources[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'Cinecalidad (Torrent)',
                        'type' => 'torrent',
                        'title' => $matched_title,
                        'server' => (stripos($quality, '4K') !== false) ? 'BitTorrent (4K UHD)' : 'BitTorrent Magnet',
                        'quality' => $quality,
                        'language' => 'Español Latino',
                        'url' => $final_url,
                        'size' => null
                    ];
                } else {
                    $srv = ucfirst(strtolower($btn_text));
                    if (stripos($final_url, 'mega.nz') !== false) $srv = 'Mega';
                    elseif (stripos($final_url, '1fichier') !== false) $srv = '1Fichier';

                    $sources[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'Cinecalidad (Descarga)',
                        'type' => 'direct',
                        'title' => $matched_title,
                        'server' => $srv,
                        'quality' => $quality,
                        'language' => 'Español Latino',
                        'url' => $final_url,
                        'size' => null
                    ];
                }
            }
        }

        // 3. TORRENTS Y DESCARGAS VIA PROTECCIÓN (/protect/ver.php)
        preg_match_all('/<a[^>]+href=(["\']?)([^"\'\s>]+)\1[^>]*>(.*?)<\/a>/is', $detail_html, $links, PREG_SET_ORDER);
        foreach ($links as $l) {
            $href = $l[2];
            $text = trim(strip_tags($l[3]));

            if (stripos($text, 'torrent') !== false || stripos($text, 'bittorrent') !== false || stripos($text, 'mega') !== false || stripos($href, 'protect/ver.php') !== false) {
                $protect_url = null;
                if (preg_match('/[?&]s=(https?:\/\/[^&]+)/i', $href, $sm)) {
                    $protect_url = urldecode($sm[1]);
                } elseif (strpos($href, '/protect/ver.php') !== false) {
                    $protect_url = (strpos($href, 'http') === 0) ? $href : rtrim($host, '/') . $href;
                }

                if ($protect_url) {
                    $prot_html = http_get($protect_url, ['timeout' => 4]);
                    if ($prot_html) {
                        // A) Magnet BitTorrent
                        if (preg_match('/(magnet:\?[^"\'\s>]+)/is', $prot_html, $mm)) {
                            $mag = html_entity_decode($mm[1]);
                            $quality = (stripos($text, '4k') !== false || stripos($mag, '2160p') !== false) ? '4K UHD' : '1080p Full HD';
                            $sources[] = [
                                'provider' => $this->getId(),
                                'provider_name' => 'Cinecalidad (Torrent)',
                                'type' => 'torrent',
                                'title' => $matched_title,
                                'server' => (stripos($quality, '4K') !== false) ? 'BitTorrent (4K UHD)' : 'BitTorrent Magnet',
                                'quality' => $quality,
                                'language' => 'Español Latino',
                                'url' => $mag,
                                'size' => null
                            ];
                        }
                        // B) Enlace Directo (Mega / 1Fichier)
                        elseif (preg_match('/[?&]s=(https?:\/\/(?:mega\.nz|1fichier\.com)[^\s"\'<>]+)/i', $prot_html, $dm)) {
                            $direct_link = urldecode($dm[1]);
                            $quality = (stripos($text, '4k') !== false) ? '4K UHD' : '1080p Full HD';
                            $srv = (stripos($direct_link, 'mega.nz') !== false) ? 'Mega' : '1Fichier';
                            $sources[] = [
                                'provider' => $this->getId(),
                                'provider_name' => 'Cinecalidad (Descarga)',
                                'type' => 'direct',
                                'title' => $matched_title,
                                'server' => (stripos($quality, '4K') !== false) ? "$srv (4K UHD)" : $srv,
                                'quality' => $quality,
                                'language' => 'Español Latino',
                                'url' => $direct_link,
                                'size' => null
                            ];
                        }
                    }
                }
            }
        }

        // 4. MAGNETS DIRECTOS EN EL HTML
        preg_match_all('/href=(["\']?)(magnet:\?[^"\'\s>]+)\1/is', $detail_html, $mags);
        foreach (array_unique($mags[2] ?? []) as $mag) {
            $mag = html_entity_decode($mag);
            $quality = (stripos($mag, '2160p') !== false || stripos($mag, '4k') !== false) ? '4K UHD' : '1080p Full HD';
            $sources[] = [
                'provider' => $this->getId(),
                'provider_name' => 'Cinecalidad (Torrent)',
                'type' => 'torrent',
                'title' => $matched_title,
                'server' => (stripos($quality, '4K') !== false) ? 'BitTorrent (4K UHD)' : 'BitTorrent Magnet',
                'quality' => $quality,
                'language' => 'Español Latino',
                'url' => $mag,
                'size' => null
            ];
        }

        return $sources;
    }
}
