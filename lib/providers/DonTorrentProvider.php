<?php
require_once __DIR__ . '/ProviderInterface.php';

class DonTorrentProvider implements ProviderInterface
{
    private array $hosts = [
        'https://tomadivx.net',
        'https://dontorrent.org'
    ];

    public function getId(): string
    {
        return 'dontorrent';
    }

    public function getName(): string
    {
        return 'DonTorrent (Torrents / 4K / 1080p)';
    }

    public function getType(): string
    {
        return 'torrent';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['dontorrent']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['dontorrent']['enabled'];
        }
        return true;
    }

    private function postSearch(string $url, string $title): ?string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['campo' => 'titulo', 'valor' => $title]));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
            $html = curl_exec($ch);
            return $html ?: null;
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
                'content' => http_build_query(['campo' => 'titulo', 'valor' => $title]),
                'timeout' => 6
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        return @file_get_contents($url, false, stream_context_create($opts)) ?: null;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $src_clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = "{$host}/peliculas/buscar";
            $html = $this->postSearch($search_url, $title);
            if (!$html || strlen($html) < 500) continue;

            preg_match_all('/<a[^>]+href="(\/pelicula\/[^"]+)"[^>]*>/is', $html, $matches);
            $urls = array_unique($matches[1] ?? []);

            foreach (array_slice($urls, 0, 8) as $rel_url) {
                if (connection_aborted()) exit;
                $link = $host . $rel_url;
                $slug = basename($rel_url);
                $clean_title = preg_replace('/^\d+\//', '', $slug);
                $cand_title = trim(str_ireplace(['-4k', '-1080p', '-720p', '-microhd', '-castellano', '-latino'], '', $clean_title));
                $cand_title = str_replace('-', ' ', $cand_title);

                $cand_year = null;
                if (preg_match('/(19\d{2}|20\d{2})/', $slug, $ym)) {
                    $cand_year = $ym[1];
                }

                if (!is_strict_title_match($title, $cand_title, $year, $cand_year)) {
                    continue;
                }

                $torrent_url = $link;

                if ($torrent_url) {
                    if (strpos($torrent_url, 'http') !== 0 && strpos($torrent_url, 'magnet') !== 0) {
                        $torrent_url = $host . '/' . ltrim($torrent_url, '/');
                    }

                    $quality = '1080p MicroHD';
                    if (stripos($slug, '4k') !== false) {
                        $quality = '4K UHD';
                    } elseif (stripos($slug, '720p') !== false) {
                        $quality = '720p HD';
                    }

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'DonTorrent',
                        'type' => 'torrent',
                        'title' => str_replace('-', ' ', preg_replace('/^\d+\//', '', $slug)),
                        'server' => 'Torrent / Magnet',
                        'quality' => $quality,
                        'language' => 'Castellano / Español',
                        'url' => $torrent_url,
                        'size' => null
                    ];
                }
            }

            if (!empty($results)) break;
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach ($this->hosts as $host) {
            if (connection_aborted()) exit;

            $search_url = "{$host}/series/buscar";
            $html = $this->postSearch($search_url, $title);
            if (!$html || strlen($html) < 500) continue;

            preg_match_all('/<a[^>]+href="(\/serie\/[^"]+)"[^>]*>/is', $html, $matches);
            $urls = array_unique($matches[1] ?? []);

            foreach (array_slice($urls, 0, 8) as $rel_url) {
                if (connection_aborted()) exit;
                $link = $host . $rel_url;
                $slug = basename($rel_url);
                $clean_title = preg_replace('/^\d+\//', '', $slug);
                $cand_title = trim(str_ireplace(['-temporada', '-temp', '-1080p', '-720p'], '', $clean_title));
                $cand_title = str_replace('-', ' ', $cand_title);

                if (!is_strict_title_match($title, $cand_title)) {
                    continue;
                }

                $torrent_url = $link;

                if ($torrent_url) {
                    if (strpos($torrent_url, 'http') !== 0 && strpos($torrent_url, 'magnet') !== 0) {
                        $torrent_url = $host . '/' . ltrim($torrent_url, '/');
                    }

                    $quality = '1080p';
                    if (stripos($slug, '720p') !== false) $quality = '720p';

                    $results[] = [
                        'provider' => $this->getId(),
                        'provider_name' => 'DonTorrent',
                        'type' => 'torrent',
                        'title' => "{$title} Temp. {$season}",
                        'server' => 'Torrent / Magnet',
                        'quality' => $quality,
                        'language' => 'Castellano / Español',
                        'url' => $torrent_url,
                        'size' => null
                    ];
                }
            }

            if (!empty($results)) break;
        }

        return $results;
    }
}

