<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../tmdb.php';

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
                $langMeta = $this->analyzeLanguage($cand_title);

                // Omitir releases exclusivamente en francés sin semillas o con VOSTFR si buscamos en español
                if ($langMeta['is_french'] && $seeders < 2) continue;

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'Nyaa (Anime Torrents)',
                    'type' => 'torrent',
                    'title' => $cand_title,
                    'server' => '⚙️ NyaaSi',
                    'tracker' => 'NyaaSi',
                    'quality' => $quality,
                    'language' => $langMeta['language'],
                    'is_latino' => $langMeta['is_latino'],
                    'url' => $magnet,
                    'size' => $size ?: null,
                    'seeds' => $seeders,
                    'seeders' => $seeders,
                    'leechers' => $leechers,
                    '_score' => $langMeta['score'] + min($seeders, 1000)
                ];
            }

            if (count($results) >= 15) break;
        }

        usort($results, fn($a, $b) => $b['_score'] <=> $a['_score']);
        $final = array_slice($results, 0, 12);
        foreach ($final as &$r) {
            unset($r['_score']);
        }
        return $final;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) return [];

        $clean_title = trim($title);
        if (empty($clean_title)) return [];

        $padded_ep = sprintf('%02d', $episode);
        $s_padded = sprintf('S%02dE%02d', $season, $episode);

        $season_name = ($tmdb_id !== null && $season >= 2 && function_exists('get_tmdb_anime_season_name'))
            ? get_tmdb_anime_season_name($tmdb_id, $season)
            : null;

        $queries = [];
        // Priorizar versiones con SxxExx (captura VARYG MULTi, ToonsHub, Z-A, DKB, sam, EMBER) y búsqueda con Latino
        $queries[] = "{$clean_title} {$s_padded}";
        $queries[] = "{$clean_title} Latino {$padded_ep}";
        if (!empty($season_name)) {
            $queries[] = "{$clean_title} {$season_name} {$padded_ep}";
        }

        if ($season === 1) {
            $queries[] = "{$clean_title} {$padded_ep}";
        } else {
            $queries[] = "{$clean_title} S{$season} {$padded_ep}";
            if ($absolute_episode && $absolute_episode > $episode) {
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
                $cand_title = trim(html_entity_decode($mT[1] ?? '', ENT_QUOTES, 'UTF-8'));
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
                $langMeta = $this->analyzeLanguage($cand_title);

                // Descartar releases exclusivamente franceses (VOSTFR / VF de Tsundere-Raws, TenmaLand, T3KASHi, KAF)
                if ($langMeta['is_french']) {
                    continue;
                }

                $ep_code = sprintf('S%02dE%02d', $season, $episode);
                if (strpos($magnet, '&ep=') === false) {
                    $magnet .= '&ep=' . rawurlencode($ep_code);
                }

                $results[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'Nyaa (Anime Torrents)',
                    'type' => 'torrent',
                    'title' => $cand_title,
                    'server' => '⚙️ NyaaSi',
                    'tracker' => 'NyaaSi',
                    'quality' => $quality,
                    'language' => $langMeta['language'],
                    'is_latino' => $langMeta['is_latino'],
                    'url' => $magnet,
                    'size' => $size ?: null,
                    'seeds' => $seeders,
                    'seeders' => $seeders,
                    'leechers' => $leechers,
                    '_score' => $langMeta['score'] + min($seeders, 1000)
                ];

                if (count($results) >= 30) break 2;
            }

            if (count($results) >= 12) break;
        }

        // Ordenar priorizando Audio Español Latino / Multi-Audio real -> Sub Español -> Dual Audio + Multi-Subs -> Seeds
        usort($results, fn($a, $b) => $b['_score'] <=> $a['_score']);
        $final = array_slice($results, 0, 12);
        foreach ($final as &$r) {
            unset($r['_score']);
        }

        return $final;
    }

    private function detectQuality(string $title): string
    {
        if (preg_match('/(2160p|4k)/i', $title)) return '4K UHD';
        if (preg_match('/1080p/i', $title)) return '1080p Full HD';
        if (preg_match('/720p/i', $title)) return '720p HD';
        if (preg_match('/480p/i', $title)) return '480p SD';
        return '1080p Full HD';
    }

    private function analyzeLanguage(string $title): array
    {
        // 1. Detectar grupos y etiquetas francesas donde "MULTi" = Francés + Japonés (NO incluye Español)
        if (function_exists('is_french_release') && is_french_release($title) && !preg_match('/\b(latino|latam|es[\s\.\-_]*mx)\b/i', $title)) {
            $is_vf = (bool)preg_match('/\b(multi|vf|vff|vfi|french|truefrench)\b/i', $title);
            return [
                'language' => $is_vf ? '🇫🇷 Audio Francés / Japonés (VF)' : '🇫🇷 Sub Francés (VOSTFR)',
                'is_latino' => false,
                'is_french' => true,
                'score' => -5000
            ];
        }

        $is_multi_audio = (bool)preg_match('/\b(multi[\s\.\-_]*audio|multi[\s\.\-_]*dub|cr[\s\.\-_]*web-?dl[\s\.\-_]*multi)\b/i', $title);
        $is_dual_audio = (bool)preg_match('/\b(dual[\s\.\-_]*audio|cr[\s\.\-_]*web-?dl[\s\.\-_]*dual|\bdual\b)\b/i', $title);
        $is_multi_subs = (bool)preg_match('/\b(multi[\s\.\-_]*subs?|multisub)\b/i', $title);
        $is_sub_esp = (bool)preg_match('/\b(sub[\s\.\-_]*(?:esp|español|espanol|lat|latino)|español[\s\.\-_]*sub)\b/i', $title);
        $explicit_latino = (bool)preg_match('/\b(latino|audio[\s\.\-_]*latino|doblaje[\s\.\-_]*latino|latam|dual[\s\.\-_]*lat(?:ino)?|es[\s\.\-_]*mx|es[\s\.\-_]*419)\b/i', $title);

        // CASO 1: Audio Español Latino confirmado (ej: VARYG CR WEB-DL MULTi Multi-Audio, o release explícito Latino)
        if ($explicit_latino || $is_multi_audio) {
            return [
                'language' => $is_multi_audio ? '🇲🇽 Audio Español Latino (Multi Audio)' : '🇲🇽 Audio Español Latino',
                'is_latino' => true,
                'is_french' => false,
                'score' => 60000
            ];
        }

        // CASO 2: Subtitulado explícitamente al Español (ej: [Z-A] [Sub. Español])
        if ($is_sub_esp) {
            return [
                'language' => '🇯🇵 Audio Japonés + 🇲🇽 Sub Español',
                'is_latino' => false,
                'is_french' => false,
                'score' => 35000
            ];
        }

        // CASO 3: Dual Audio (Japonés + Inglés) con Multi-Subs de Crunchyroll (incluye Sub Español Latino, ej: VARYG DUAL, ToonsHub CR)
        if ($is_dual_audio && $is_multi_subs) {
            return [
                'language' => '🇬🇧/🇯🇵 Dual Audio (Ing/Jap) + 🇲🇽 Sub Latino',
                'is_latino' => false,
                'is_french' => false,
                'score' => 26000
            ];
        }

        // CASO 4: Multi-Subs (si es NF/BILI WEB-DL solo trae subs asiáticos/inglés; si es CR/semanal como DKB/Judas trae Sub Español Latino)
        if ($is_multi_subs) {
            if (preg_match('/\b(nf|bili)\s*web-?dl\b/i', $title)) {
                return [
                    'language' => '🇯🇵 Subtitulado (Multi-Subs Ing/Asia)',
                    'is_latino' => false,
                    'is_french' => false,
                    'score' => 4000
                ];
            }
            return [
                'language' => '🇯🇵 Audio Japonés + 🇲🇽 Sub Latino (Multi-Subs)',
                'is_latino' => false,
                'is_french' => false,
                'score' => 20000
            ];
        }

        // CASO 5: Dual Audio solo Japonés + Inglés (ej: [sam], [EMBER], [Sokudo])
        if ($is_dual_audio) {
            return [
                'language' => '🇬🇧/🇯🇵 Dual Audio (Inglés / Japonés)',
                'is_latino' => false,
                'is_french' => false,
                'score' => 10000
            ];
        }

        if (preg_match('/\b(english[\s\.\-_]*dub|eng[\s\.\-_]*dub)\b/i', $title)) {
            return [
                'language' => '🇬🇧 Audio Inglés (Dubbed)',
                'is_latino' => false,
                'is_french' => false,
                'score' => 3000
            ];
        }

        return [
            'language' => '🇯🇵 Japonés (Sub Inglés)',
            'is_latino' => false,
            'is_french' => false,
            'score' => 2000
        ];
    }
}

