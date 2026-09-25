<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class NyaaProvider implements ProviderInterface
{
    private string $host = 'https://nyaa.si/';

    public function getId(): string
    {
        return 'nyaa';
    }

    public function getName(): string
    {
        return 'Nyaa (Anime Torrents HD / Multi-Sub)';
    }

    public function getType(): string
    {
        return 'torrent';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['nyaa']['enabled'] ?? true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) return [];

        $clean_title = trim($title);
        if (empty($clean_title)) return [];

        $results = [];
        $seen_hashes = [];

        // Búsqueda priorizando versiones con doblaje Latino y multi-idioma
        $movieQueries = [
            $clean_title . ' Latino',
            $clean_title
        ];

        foreach (array_unique($movieQueries) as $q) {
            if (connection_aborted()) exit;

            $url = $this->host . '?f=0&c=0_0&q=' . urlencode($q);
            $html = http_get($url, [
                'timeout' => 8,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ]
            ]);

            if (!$html || strlen($html) < 500) continue;

            if (!preg_match_all('/<tr class="(?:default|success|danger)">(.*?)<\/tr>/is', $html, $rows)) {
                continue;
            }

            foreach ($rows[1] as $row) {
                preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $tds);
                if (count($tds[1]) < 7) continue;

                $raw_title_td = $tds[1][1];
                preg_match('/<a href="\/view\/\d+"[^>]*title="([^"]+)"/i', $raw_title_td, $mT);
                if (empty($mT)) {
                    preg_match('/<a href="\/view\/\d+"[^>]*>([^<]+)<\/a>/i', $raw_title_td, $mT);
                }
                $cand_title = trim($mT[1] ?? '');
                if (empty($cand_title)) continue;

                // Descartar episodios de series si estamos buscando una película
                if (preg_match('/(?:s\d{1,2}e\d{1,2}|\bep\s*\d+|\bepisode\s*\d+|-\s*\d{2,3}\b)/i', $cand_title)) {
                    continue;
                }

                // Parsear título y año del torrent y validar contra la película
                $parsed = parse_torrent_release_name($cand_title);
                $cand_name = $parsed['title'];
                $cand_year = $parsed['year'];
                if (!is_strict_title_match($clean_title, $cand_name, $year, $cand_year) &&
                    !is_anime_title_match($clean_title, $cand_name)) {
                    continue;
                }

                $raw_links_td = $tds[1][2];
                if (!preg_match('/href="(magnet:\?[^"]+)"/i', $raw_links_td, $mM)) continue;
                $magnet = html_entity_decode($mM[1], ENT_QUOTES, 'UTF-8');

                $hash = '';
                if (preg_match('/xt=urn:btih:([a-zA-Z0-9]+)/i', $magnet, $mh)) {
                    $hash = strtolower($mh[1]);
                    if (isset($seen_hashes[$hash])) continue;
                }

                $size = trim(strip_tags($tds[1][3] ?? ''));
                $seeders = (int)trim(strip_tags($tds[1][5] ?? '0'));
                $leechers = (int)trim(strip_tags($tds[1][6] ?? '0'));

                if ($hash) $seen_hashes[$hash] = true;

                $quality = $this->detectQuality($cand_title);
                $language = $this->detectLanguage($cand_title);

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'Nyaa (Anime Torrents)',
                    'type' => 'torrent',
                    'title' => $cand_title,
                    'server' => 'BitTorrent Magnet',
                    'quality' => $quality,
                    'language' => $language,
                    'url' => $magnet,
                    'size' => $size ?: null,
                    'seeders' => $seeders,
                    'leechers' => $leechers
                ];

                if (count($results) >= 6) break 2;
            }

            if (!empty($results)) break;
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) return [];

        $clean_title = trim($title);
        if (empty($clean_title)) return [];

        $padded_ep = sprintf('%02d', $episode);
        $s_padded = sprintf('S%02dE%02d', $season, $episode);

        $queries = [];
        // Priorizar versiones con audio Latino
        $queries[] = "{$clean_title} Latino {$padded_ep}";
        $queries[] = "{$clean_title} Latino {$s_padded}";
        $queries[] = "{$clean_title} {$s_padded}";

        if ($season === 1) {
            $queries[] = "{$clean_title} {$padded_ep}";
            $queries[] = "{$clean_title} - {$padded_ep}";
        } else {
            $queries[] = "{$clean_title} S{$season} {$padded_ep}";
            $queries[] = "{$clean_title} Season {$season} {$padded_ep}";
            if ($absolute_episode) {
                $queries[] = "{$clean_title} " . sprintf('%02d', $absolute_episode);
            }
        }

        $results = [];
        $seen_hashes = [];

        foreach (array_unique($queries) as $q) {
            if (connection_aborted()) exit;

            // c=0_0 busca en todas las categorías, incluyendo 1_3 (Non-English translated / Latino)
            $url = $this->host . '?f=0&c=0_0&q=' . urlencode($q);
            $html = http_get($url, [
                'timeout' => 8,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ]
            ]);

            if (!$html || strlen($html) < 500) continue;

            if (!preg_match_all('/<tr class="(?:default|success|danger)">(.*?)<\/tr>/is', $html, $rows)) {
                continue;
            }

            foreach ($rows[1] as $row) {
                preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $tds);
                if (count($tds[1]) < 7) continue;

                $raw_title_td = $tds[1][1];
                preg_match('/<a href="\/view\/\d+"[^>]*title="([^"]+)"/i', $raw_title_td, $mT);
                if (empty($mT)) {
                    preg_match('/<a href="\/view\/\d+"[^>]*>([^<]+)<\/a>/i', $raw_title_td, $mT);
                }
                $cand_title = trim($mT[1] ?? '');
                if (empty($cand_title)) continue;

                $raw_links_td = $tds[1][2];
                if (!preg_match('/href="(magnet:\?[^"]+)"/i', $raw_links_td, $mM)) continue;
                $magnet = html_entity_decode($mM[1], ENT_QUOTES, 'UTF-8');

                $hash = '';
                if (preg_match('/xt=urn:btih:([a-zA-Z0-9]+)/i', $magnet, $mh)) {
                    $hash = strtolower($mh[1]);
                    if (isset($seen_hashes[$hash])) continue;
                }

                // Validar temporada y evitar falsos positivos
                $cand_lower = strtolower($cand_title);
                $wrong_season = false;
                for ($s = 1; $s <= 10; $s++) {
                    if ($s === $season) continue;
                    $pat = sprintf('/(?:\b|[^a-z0-9])s0?%d(?:\b|[^a-z0-9]|e)|season\s*0?%d\b/i', $s, $s);
                    if (preg_match($pat, $cand_lower)) {
                        $wrong_season = true;
                        break;
                    }
                }
                if ($wrong_season) continue;

                // Si es temporada 1, descartar "Part 2", "Cour 2", etc.
                if ($season === 1 && preg_match('/(?:part|cour)\s*[2-9]/i', $cand_lower)) {
                    continue;
                }

                // Validar coincidencia de episodio
                $ep_match = false;
                if (stripos($cand_lower, $s_padded) !== false) {
                    $ep_match = true;
                } elseif (preg_match('/(?:[\s_\-\.\[]|\b)(?:e|ep|episode|\#)?' . sprintf('%02d', $episode) . '(?:v\d+)?(?:[\s_\-\.\]]|\b)/i', $cand_title)) {
                    $ep_match = true;
                } elseif ($absolute_episode && preg_match('/(?:[\s_\-\.\[]|\b)(?:e|ep|episode|\#)?' . sprintf('%02d', $absolute_episode) . '(?:v\d+)?(?:[\s_\-\.\]]|\b)/i', $cand_title)) {
                    $ep_match = true;
                }

                if (!$ep_match) continue;

                $size = trim(strip_tags($tds[1][3] ?? ''));
                $seeders = (int)trim(strip_tags($tds[1][5] ?? '0'));
                $leechers = (int)trim(strip_tags($tds[1][6] ?? '0'));

                if ($hash) $seen_hashes[$hash] = true;

                $quality = $this->detectQuality($cand_title);
                $language = $this->detectLanguage($cand_title);

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'Nyaa (Anime Torrents)',
                    'type' => 'torrent',
                    'title' => $cand_title,
                    'server' => 'BitTorrent Magnet',
                    'quality' => $quality,
                    'language' => $language,
                    'url' => $magnet,
                    'size' => $size ?: null,
                    'seeders' => $seeders,
                    'leechers' => $leechers
                ];

                if (count($results) >= 6) break 2;
            }

            if (count($results) >= 3) break;
        }

        return $results;
    }

    private function detectQuality(string $title): string
    {
        if (preg_match('/(2160p|4k)/i', $title)) return '4K UHD';
        if (preg_match('/1080p/i', $title)) return '1080p Full HD';
        if (preg_match('/720p/i', $title)) return '720p HD';
        if (preg_match('/480p/i', $title)) return '480p SD';
        return '1080p Full HD';
    }

    private function detectLanguage(string $title): string
    {
        if (is_latino_audio($title)) {
            return (stripos($title, 'Dual') !== false || stripos($title, 'Multi') !== false) ? 'Español Latino (Dual)' : 'Español Latino';
        }
        if (stripos($title, 'Castellano') !== false || stripos($title, 'Spanish') !== false) {
            return 'Español Castellano';
        }
        if (stripos($title, 'Dual-Audio') !== false || stripos($title, 'Dual Audio') !== false) {
            return 'Dual Audio (Jap/Eng/Sub)';
        }
        if (stripos($title, 'Multi-Sub') !== false || stripos($title, 'Multi Sub') !== false || stripos($title, 'Multi-Audio') !== false) {
            return 'Multi-Sub (Inc. Español)';
        }
        return 'Japonés (Subtitulado)';
    }
}

