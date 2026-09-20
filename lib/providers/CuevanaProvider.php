<?php
require_once __DIR__ . '/ProviderInterface.php';

class CuevanaProvider implements ProviderInterface
{
    private string $host = 'https://www.cuevana-3.mx';

    public function getId(): string
    {
        return 'cuevana';
    }

    public function getName(): string
    {
        return 'Cuevana (Streaming)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['cuevana']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['cuevana']['enabled'];
        }
        return true;
    }

    private function mapServerName(string $locker): string
    {
        $l = strtolower($locker);
        if (strpos($l, 'voe') !== false) return 'Voe';
        if (strpos($l, 'streamwish') !== false || strpos($l, 'wish') !== false) return 'StreamWish';
        if (strpos($l, 'vidhide') !== false) return 'VidHide';
        if (strpos($l, 'dood') !== false) return 'Doodstream';
        if (strpos($l, 'streamtape') !== false) return 'Streamtape';
        if (strpos($l, 'mixdrop') !== false) return 'Mixdrop';
        if (strpos($l, 'uqload') !== false) return 'Uqload';
        if (strpos($l, 'filemoon') !== false) return 'Filemoon';
        if (strpos($l, 'netu') !== false) return 'Netu';
        if (strpos($l, 'wolfstream') !== false) return 'Wolfstream';
        return ucfirst($locker);
    }

    private function mapLanguageName(string $langKey): string
    {
        switch ($langKey) {
            case 'latino': return 'Español Latino';
            case 'spanish': return 'Castellano';
            case 'english': return 'Inglés (Subtitulado)';
            case 'japanese': return 'Japonés';
            default: return ucfirst($langKey);
        }
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $query = urlencode(trim($title));
        $src_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

        $search_url = "{$this->host}/search?q={$query}";
        $html = http_get($search_url, ['timeout' => 6]);

        if (!$html || !preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $matches)) {
            return [];
        }

        $next_data = json_decode($matches[1], true);
        $movies = $next_data['props']['pageProps']['movies'] ?? [];

        // Si tenemos tmdb_id, ordenar para colocar la coincidencia exacta de ID primero
        if ($tmdb_id !== null) {
            usort($movies, function($a, $b) use ($tmdb_id) {
                $a_match = (isset($a['TMDbId']) && (int)$a['TMDbId'] === (int)$tmdb_id) ? 1 : 0;
                $b_match = (isset($b['TMDbId']) && (int)$b['TMDbId'] === (int)$tmdb_id) ? 1 : 0;
                return $b_match - $a_match;
            });
        }

        foreach ($movies as $m) {
            if (connection_aborted()) exit;

            $m_tmdb = $m['TMDbId'] ?? null;
            $m_title = $m['titles']['name'] ?? '';
            $raw_slug = $m['url']['slug'] ?? '';
            if (!$raw_slug || strpos($raw_slug, 'movies/') !== 0) continue;

            $m_year = !empty($m['releaseDate']) ? substr($m['releaseDate'], 0, 4) : null;

            // 1. Verificación por TMDB ID exacto
            $is_match = false;
            if ($tmdb_id !== null && $m_tmdb !== null && (int)$tmdb_id === (int)$m_tmdb) {
                $is_match = true;
            }

            // 2. Verificación estricta por título y año si no coincide ID
            if (!$is_match) {
                if (!is_strict_title_match($title, $m_title, $year, $m_year)) {
                    continue;
                }
            }

            // Transformar movies/ID/slug a pelicula/ID/slug
            $pelicula_slug = preg_replace('/^movies\//', 'pelicula/', $raw_slug);
            $detail_url = "{$this->host}/{$pelicula_slug}";
            $detail_html = http_get($detail_url, ['timeout' => 6]);

            if ($detail_html && preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $detail_html, $m_det)) {
                $det_json = json_decode($m_det[1], true);
                $thisMovie = $det_json['props']['pageProps']['thisMovie'] ?? [];
                $videos = $thisMovie['videos'] ?? [];

                foreach ($videos as $langKey => $serverList) {
                    if (!is_array($serverList)) continue;
                    $language = $this->mapLanguageName($langKey);

                    foreach ($serverList as $srv) {
                        $url = $srv['result'] ?? ($srv['url'] ?? null);
                        if (!$url) continue;

                        $server_name = $this->mapServerName($srv['cyberlocker'] ?? 'Online');
                        $quality = !empty($srv['quality']) ? strtoupper($srv['quality']) : 'HD';

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => $this->getName(),
                            'type' => 'streaming',
                            'title' => $m_title ?: $title,
                            'server' => $server_name,
                            'quality' => $quality,
                            'language' => $language,
                            'url' => $url,
                            'size' => null
                        ];
                    }
                }
            }

            if (!empty($results)) break;
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $query = urlencode(trim($title));
        $src_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

        $search_url = "{$this->host}/search?q={$query}";
        $html = http_get($search_url, ['timeout' => 6]);

        if (!$html || !preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $matches)) {
            return [];
        }

        $next_data = json_decode($matches[1], true);
        $movies_or_series = $next_data['props']['pageProps']['movies'] ?? [];
        if (!is_array($movies_or_series)) {
            return [];
        }

        // Si tenemos tmdb_id, ordenar para colocar la coincidencia exacta de ID primero
        if ($tmdb_id !== null) {
            usort($movies_or_series, function($a, $b) use ($tmdb_id) {
                $a_match = (isset($a['TMDbId']) && (int)$a['TMDbId'] === (int)$tmdb_id) ? 1 : 0;
                $b_match = (isset($b['TMDbId']) && (int)$b['TMDbId'] === (int)$tmdb_id) ? 1 : 0;
                return $b_match - $a_match;
            });
        }

        foreach ($movies_or_series as $item) {
            if (connection_aborted()) exit;

            $raw_slug = $item['url']['slug'] ?? '';
            if (!$raw_slug || strpos($raw_slug, 'series/') !== 0) continue;

            $s_tmdb = $item['TMDbId'] ?? null;
            $s_title = $item['titles']['name'] ?? '';

            $is_match = false;
            if ($tmdb_id !== null && $s_tmdb !== null && (int)$tmdb_id === (int)$s_tmdb) {
                $is_match = true;
            }

            if (!$is_match) {
                if (!is_strict_title_match($title, $s_title)) {
                    continue;
                }
            }

            // Transformar series/ID/slug a serie/ID/slug/temporada/X/episodio/Y
            $serie_slug = preg_replace('/^series\//', 'serie/', $raw_slug);
            $ep_url = "{$this->host}/{$serie_slug}/temporada/{$season}/episodio/{$episode}";
            $ep_html = http_get($ep_url, ['timeout' => 6]);

            // Si falla y la temporada es >= 2 (animes donde Cuevana unifica todo en temporada 1),
            // probar con la numeración continua de temporada 1
            if (!$ep_html && $season >= 2) {
                $alt_ep = ($absolute_episode !== null && $absolute_episode > 0) ? $absolute_episode : (($season - 1) * 24 + $episode);
                $alt_url = "{$this->host}/{$serie_slug}/temporada/1/episodio/{$alt_ep}";
                $ep_html = http_get($alt_url, ['timeout' => 6]);
            }

            if ($ep_html && preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $ep_html, $m_det)) {
                $det_json = json_decode($m_det[1], true);
                $ep_data = $det_json['props']['pageProps']['episode'] ?? [];
                $videos = $ep_data['videos'] ?? [];

                foreach ($videos as $langKey => $serverList) {
                    if (!is_array($serverList)) continue;
                    $language = $this->mapLanguageName($langKey);

                    foreach ($serverList as $srv) {
                        $url = $srv['result'] ?? ($srv['url'] ?? null);
                        if (!$url) continue;

                        $server_name = $this->mapServerName($srv['cyberlocker'] ?? 'Online');
                        $quality = !empty($srv['quality']) ? strtoupper($srv['quality']) : 'HD';

                        $results[] = [
                            'provider' => $this->getId(),
                            'provider_name' => $this->getName(),
                            'type' => 'streaming',
                            'title' => "{$s_title} S{$season}E{$episode}",
                            'server' => $server_name,
                            'quality' => $quality,
                            'language' => $language,
                            'url' => $url,
                            'size' => null
                        ];
                    }
                }
            }

            if (!empty($results)) break;
        }

        return $results;
    }
}
