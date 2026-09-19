<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class RetroTVEProvider implements ProviderInterface
{
    private string $host = 'https://retrotve.com';

    public function getId(): string
    {
        return 'retrotve';
    }

    public function getName(): string
    {
        return 'RetroTVE (Clásicos y Series Retro)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['retrotve']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['retrotve']['enabled'];
        }
        return true;
    }

    private function mapServerName(string $raw): string
    {
        $clean = trim(strip_tags($raw));
        $lower = strtolower($clean);

        if (strpos($lower, 'filemoon') !== false) return 'Filemoon';
        if (strpos($lower, 'vkvideo') !== false || strpos($lower, 'vk') !== false) return 'VK Video';
        if (strpos($lower, 'ok.ru') !== false || strpos($lower, 'okru') !== false) return 'Ok.ru';
        if (strpos($lower, 'streamwish') !== false || strpos($lower, 'wish') !== false) return 'StreamWish';
        if (strpos($lower, 'vidhide') !== false) return 'VidHide';
        if (strpos($lower, 'voe') !== false) return 'Voe';
        if (strpos($lower, 'mega') !== false) return 'Mega';
        if (strpos($lower, 'dood') !== false) return 'Doodstream';
        if (strpos($lower, 'streamtape') !== false) return 'Streamtape';
        if (strpos($lower, 'vip') !== false) return 'RetroTVE VIP';
        if (preg_match('/opci[oó]n\s*(\d+)/iu', $clean, $m)) return "RetroTVE {$m[1]}";

        return !empty($clean) ? ucwords($clean) : 'RetroTVE Stream';
    }

    /**
     * Extrae las opciones de reproductor (TPlayer) de una página de película o episodio
     */
    private function extractPlayers(string $html, string $itemTitle): array
    {
        $results = [];
        $decoded_html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        // Extraer metadatos de las pestañas en <ul class="TPlayerNv">
        $tabs_info = [];
        if (preg_match_all('/<li[^>]+data-tplayernv=["\']([^"\']+)["\'][^>]*>(.*?)<\/li>/is', $html, $tab_matches)) {
            foreach ($tab_matches[1] as $idx => $optId) {
                $tab_content = $tab_matches[2][$idx];
                preg_match_all('/<span>(.*?)<\/span>/is', $tab_content, $span_matches);

                $srv_text = $span_matches[1][0] ?? 'Opción ' . ($idx + 1);
                $info_text = $span_matches[1][1] ?? '';

                $tabs_info[$optId] = [
                    'server' => $this->mapServerName($srv_text),
                    'info' => $info_text
                ];
            }
        }

        // Extraer los iframes dentro de los contenedores id="OptN" o TPlayerTb
        if (preg_match_all('/<div[^>]+id=["\'](Opt\d+)["\'][^>]*>(.*?)<\/div>/is', $decoded_html, $player_divs)) {
            foreach ($player_divs[1] as $idx => $optId) {
                $div_content = $player_divs[2][$idx];

                if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $div_content, $ifr_match)) {
                    $player_url = trim(html_entity_decode($ifr_match[1], ENT_QUOTES, 'UTF-8'));
                    if (strpos($player_url, '//') === 0) {
                        $player_url = 'https:' . $player_url;
                    }

                    $tab_data = $tabs_info[$optId] ?? [
                        'server' => 'RetroTVE ' . ($idx + 1),
                        'info' => ''
                    ];

                    $info_str = strtolower($tab_data['info']);
                    $quality = 'HD';
                    if (strpos($info_str, '1080p') !== false) $quality = '1080p';
                    elseif (strpos($info_str, '720p') !== false) $quality = '720p';
                    elseif (strpos($info_str, '480p') !== false) $quality = '480p';

                    $language = 'Español Latino';
                    if (strpos($info_str, 'castellano') !== false) $language = 'Castellano';
                    elseif (strpos($info_str, 'sub') !== false) $language = 'Subtitulado';

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => $this->getName(),
                        'type' => 'streaming',
                        'title' => $itemTitle,
                        'server' => $tab_data['server'],
                        'quality' => $quality,
                        'language' => $language,
                        'url' => $player_url,
                        'size' => null
                    ];
                }
            }
        }

        return $results;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $search_url = "{$this->host}/?s=" . urlencode(trim($title));
        $html = http_get($search_url, ['timeout' => 6]);

        if (!$html) {
            return [];
        }

        preg_match_all('/<article[^>]*id="post-(\d+)"[^>]*>(.*?)<\/article>/is', $html, $articles);
        if (empty($articles[0])) {
            return [];
        }

        foreach ($articles[0] as $art) {
            if (connection_aborted()) exit;

            // Comprobar enlace a película
            if (!preg_match('/<a[^>]+href=["\'](https:\/\/retrotve\.com\/pelicula\/[^"\']+)["\']/i', $art, $mUrl)) {
                continue;
            }

            $movie_url = $mUrl[1];
            $cand_title = '';
            if (preg_match('/<h3[^>]*class=["\']Title["\'][^>]*>([^<]+)<\/h3>/i', $art, $mTtl)) {
                $cand_title = trim($mTtl[1]);
            }

            $cand_year = null;
            if (preg_match('/<span[^>]*class=["\']Year["\'][^>]*>(\d{4})<\/span>/i', $art, $mYr)) {
                $cand_year = trim($mYr[1]);
            }

            if (is_strict_title_match($title, $cand_title, $year, $cand_year)) {
                $detail_html = http_get($movie_url, ['timeout' => 6]);
                if ($detail_html) {
                    $players = $this->extractPlayers($detail_html, $cand_title ?: $title);
                    $results = array_merge($results, $players);
                    if (!empty($results)) {
                        break;
                    }
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
        $search_url = "{$this->host}/?s=" . urlencode(trim($title));
        $html = http_get($search_url, ['timeout' => 6]);

        if (!$html) {
            return [];
        }

        preg_match_all('/<article[^>]*id="post-(\d+)"[^>]*>(.*?)<\/article>/is', $html, $articles);
        if (empty($articles[0])) {
            return [];
        }

        foreach ($articles[0] as $art) {
            if (connection_aborted()) exit;

            // Debe ser serie
            if (!preg_match('/<a[^>]+href=["\'](https:\/\/retrotve\.com\/serie\/[^"\']+)["\']/i', $art, $mUrl)) {
                continue;
            }

            $series_url = $mUrl[1];
            $cand_title = '';
            if (preg_match('/<h3[^>]*class=["\']Title["\'][^>]*>([^<]+)<\/h3>/i', $art, $mTtl)) {
                $cand_title = trim($mTtl[1]);
            }

            if (is_strict_title_match($title, $cand_title, null, null)) {
                $series_html = http_get($series_url, ['timeout' => 8]);
                if (!$series_html) continue;

                // Buscar el contenedor de la temporada solicitada
                $season_pattern = '/<div class="Title AA-Season[^"]*"[^>]*data-tab=["\']' . $season . '["\'][^>]*>.*?<\/div>\s*<div class="TPTblCn[^"]*">(.*?)<\/table>\s*<\/div>/is';
                if (!preg_match($season_pattern, $series_html, $season_match)) {
                    // Fallback alternativo para coincidencia por texto de temporada si data-tab difiere
                    $season_alt = '/<div class="Title AA-Season[^"]*"[^>]*>\s*Temporada\s*<span>\s*' . $season . '\s*<\/span>.*?<\/div>\s*<div class="TPTblCn[^"]*">(.*?)<\/table>\s*<\/div>/is';
                    preg_match($season_alt, $series_html, $season_match);
                }

                if (!empty($season_match[1])) {
                    $table_html = $season_match[1];
                    // Buscar la fila del episodio solicitado
                    preg_match_all('/<tr[^>]*data-tr-episode-id=["\'](\d+)["\'][^>]*>.*?<span class="Num">(\d+)<\/span>.*?<td class="MvTbTtl"><a href=["\']([^"\']+)["\']>(.*?)<\/a>/is', $table_html, $ep_matches);

                    $found_ep_url = null;
                    $found_ep_name = null;
                    if (!empty($ep_matches[2])) {
                        foreach ($ep_matches[2] as $e_idx => $ep_num) {
                            if ((int)$ep_num === $episode) {
                                $found_ep_url = $ep_matches[3][$e_idx];
                                $found_ep_name = trim(strip_tags($ep_matches[4][$e_idx] ?? ''));
                                break;
                            }
                        }
                    }

                    if ($found_ep_url) {
                        $ep_html = http_get($found_ep_url, ['timeout' => 6]);
                        if ($ep_html) {
                            $item_label = !empty($found_ep_name) ? "{$cand_title} - {$found_ep_name} (T{$season}E{$episode})" : "{$cand_title} - T{$season}E{$episode}";
                            $players = $this->extractPlayers($ep_html, $item_label);
                            $results = array_merge($results, $players);
                            if (!empty($results)) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $this->deduplicate($results);
    }

    private function deduplicate(array $items): array
    {
        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $url = $item['url'] ?? '';
            if ($url && !isset($seen[$url])) {
                $seen[$url] = true;
                $unique[] = $item;
            }
        }
        return $unique;
    }
}

