<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class PelisForteProvider implements ProviderInterface
{
    private string $id = 'pelisforte';
    private string $name = 'PelisForte (1080p Full HD)';
    private array $hosts = ['https://www2.pelisforte.se', 'https://pelisforte.se'];

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

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = rtrim($host, '/') . '/?s=' . urlencode($title);
            $html = http_get($search_url, ['timeout' => 3]);
            if (!$html || strlen($html) < 500) continue;

            preg_match_all('/<article[^>]*>(.*?)<\/article>/is', $html, $matches);
            if (empty($matches[0])) continue;

            foreach ($matches[0] as $art) {
                if (connection_aborted()) exit;

                preg_match('/<a[^>]+href="([^"]+)"/i', $art, $um);
                preg_match('/class="entry-title">([^<]+)<\//i', $art, $tm);
                preg_match('/<span class="Year">([^<]+)<\/span>/i', $art, $ym);

                $movie_url = $um[1] ?? '';
                $cand_title = trim(strip_tags($tm[1] ?? ''));
                $cand_year = trim($ym[1] ?? '');

                if (empty($movie_url) || empty($cand_title)) continue;

                if (!is_strict_title_match($title, $cand_title, $year, $cand_year ?: null)) {
                    continue;
                }

                $detail_html = http_get($movie_url, ['timeout' => 3]);
                if (!$detail_html) continue;

                // Extraer opciones de servidores
                preg_match_all('/href="#options-(\d+)">.*?<span class="server">([^<]+)<\/span>/is', $detail_html, $opt_matches, PREG_SET_ORDER);
                foreach ($opt_matches as $om) {
                    $opt_id = $om[1];
                    $raw_server_info = trim($om[2]);

                    // Extraer idioma y nombre de servidor
                    $lang = 'Español Latino';
                    if (stripos($raw_server_info, 'Castellano') !== false) {
                        $lang = 'Castellano';
                    } elseif (stripos($raw_server_info, 'Subtitulado') !== false || stripos($raw_server_info, 'Vose') !== false) {
                        $lang = 'Inglés (Subtitulado)';
                    }

                    $server_name = 'Servidor Online';
                    if (stripos($raw_server_info, 'SWish') !== false || stripos($raw_server_info, 'wish') !== false) $server_name = 'StreamWish';
                    elseif (stripos($raw_server_info, 'Ok') !== false) $server_name = 'Ok.ru';
                    elseif (stripos($raw_server_info, 'Voe') !== false) $server_name = 'Voe';
                    elseif (stripos($raw_server_info, 'VidHide') !== false) $server_name = 'VidHide';
                    elseif (stripos($raw_server_info, 'W1tv') !== false) $server_name = 'W1tv (HD)';
                    elseif (stripos($raw_server_info, 'Byse') !== false) $server_name = 'Byse Player';

                    // Obtener URL asociada a la opción
                    if (preg_match('/<div[^>]+id="options-' . $opt_id . '"[^>]*>.*?src="([^"]+)"/is', $detail_html, $src_m)) {
                        $raw_url = $src_m[1];
                        $stream_url = $this->resolveRedirect($raw_url, $host);

                        if ($stream_url) {
                            $results[] = [
                                'provider' => $this->getId(),
                                'provider_name' => 'PelisForte',
                                'type' => 'streaming',
                                'title' => $title,
                                'server' => $server_name,
                                'quality' => '1080p Full HD',
                                'language' => $lang,
                                'url' => $stream_url,
                                'size' => null
                            ];
                        }
                    }
                }

                if (!empty($results)) break;
            }

            if (!empty($results)) break;
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        // PelisForte se especializa principalmente en películas HD de alta calidad
        return [];
    }

    /**
     * Resuelve wrappers y redirecciones como mp4.nu a la URL directa del reproductor
     */
    private function resolveRedirect(string $url, string $host): ?string
    {
        if (strpos($url, '/mp4.nu/') !== false) {
            $resolve_url = str_replace('/mp4.nu/?h=', '/mp4.nu/r.php?h=', $url);
            if (strpos($resolve_url, 'r.php') === false) {
                $resolve_url = str_replace('/mp4.nu/', '/mp4.nu/r.php?h=', $resolve_url);
            }

            if (extension_loaded('curl')) {
                $ch = curl_init($resolve_url);
                curl_setopt($ch, CURLOPT_REFERER, $host . '/');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                $resp = curl_exec($ch);

                if (preg_match('/location:\s*([^\r\n]+)/i', $resp, $loc)) {
                    return trim($loc[1]);
                }
            }
        }

        return (strpos($url, 'http') === 0) ? $url : null;
    }
}
