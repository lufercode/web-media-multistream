<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../tmdb.php';

class TorrentioProvider implements ProviderInterface
{
    private string $baseUrl = 'https://torrentio.strem.fun';

    public function getId(): string
    {
        return 'torrentio';
    }

    public function getName(): string
    {
        return 'Torrentio (TorrentGalaxy / Nyaa / 1337x / Multi-Audio)';
    }

    public function getType(): string
    {
        return 'torrent';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['torrentio']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['torrentio']['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $imdb_id = $this->resolveImdbId($title, 'movie', $tmdb_id);
        if (!$imdb_id) {
            return [];
        }

        return $this->fetchStreams('movie', $imdb_id, $title, null, null);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?int $absolute_episode = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $imdb_id = $this->resolveImdbId($title, 'tv', $tmdb_id);
        if (!$imdb_id) {
            return [];
        }

        $stream_id = "{$imdb_id}:{$season}:{$episode}";
        $results = $this->fetchStreams('series', $stream_id, $title, $season, $episode);

        // Si es temporada >= 2 y tenemos episodio absoluto mayor, consultar también S1:abs por si algún tracker lo indexó en numeración continua
        if (empty($results) && $season >= 2 && $absolute_episode !== null && $absolute_episode > $episode) {
            $alt_stream_id = "{$imdb_id}:1:{$absolute_episode}";
            $results = $this->fetchStreams('series', $alt_stream_id, $title, $season, $episode);
        }

        return $results;
    }

    private function resolveImdbId(string $title, string $type, ?int $tmdb_id): ?string
    {
        if ($tmdb_id !== null && $tmdb_id > 0 && function_exists('get_tmdb_imdb_id')) {
            $imdb = get_tmdb_imdb_id($tmdb_id, $type);
            if ($imdb) return $imdb;
        }

        if (function_exists('tmdb_fetch') && function_exists('get_tmdb_imdb_id')) {
            $endpoint = ($type === 'movie') ? '/search/movie' : '/search/tv';
            $search = tmdb_fetch($endpoint, ['query' => $title]);
            $first_id = $search['results'][0]['id'] ?? null;
            if ($first_id) {
                return get_tmdb_imdb_id((int)$first_id, $type);
            }
        }

        return null;
    }

    private function fetchStreams(string $streamType, string $streamId, string $fallbackTitle, ?int $season, ?int $episode): array
    {
        $providers_cfg = 'providers=yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl,nyaasi,tokyotosho,anidex,mejortorrent,wolfmax4k,cinecalidad';
        $url = "{$this->baseUrl}/{$providers_cfg}/stream/{$streamType}/" . rawurlencode($streamId) . ".json";

        $raw = http_get($url, [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json']
        ]);

        if (!$raw) {
            $fallback_url = "{$this->baseUrl}/stream/{$streamType}/" . rawurlencode($streamId) . ".json";
            $raw = http_get($fallback_url, [
                'timeout' => 7,
                'headers' => ['Accept' => 'application/json']
            ]);
        }

        if (!$raw) return [];

        $json = json_decode($raw, true);
        $streams = $json['streams'] ?? [];
        if (empty($streams) || !is_array($streams)) {
            return [];
        }

        $parsed = [];
        $seen_hashes = [];

        foreach ($streams as $s) {
            $info_hash = strtolower(trim($s['infoHash'] ?? ''));
            if (empty($info_hash) || strlen($info_hash) !== 40) continue;

            $file_idx = isset($s['fileIdx']) && is_numeric($s['fileIdx']) ? (int)$s['fileIdx'] : null;
            $dedup_key = $info_hash . ':' . ($file_idx ?? 'all');
            if (isset($seen_hashes[$dedup_key])) continue;
            $seen_hashes[$dedup_key] = true;

            $raw_name = trim($s['name'] ?? 'Torrentio');
            $raw_title = trim($s['title'] ?? '');
            if (empty($raw_title)) continue;

            $lines = array_values(array_filter(array_map('trim', explode("\n", $raw_title)), fn($l) => $l !== ''));
            $release_line = $lines[0] ?? $fallbackTitle;
            $episode_file_line = null;

            if (isset($lines[1]) && strpos($lines[1], '👤') === false && strpos($lines[1], '💾') === false && strpos($lines[1], '⚙️') === false) {
                $episode_file_line = $lines[1];
            }

            $display_title = $episode_file_line ? "{$release_line} — {$episode_file_line}" : $release_line;

            // Extraer seeds, tamaño y tracker (TorrentGalaxy, NyaaSi, 1337x, EXT, EZTV, etc.)
            $seeds = 0;
            if (preg_match('/👤\s*(\d+)/u', $raw_title, $m_seeds)) {
                $seeds = (int)$m_seeds[1];
            }

            $size = 'Desconocido';
            if (preg_match('/💾\s*([0-9.,]+\s*(?:GB|MB|TB|KB))/iu', $raw_title, $m_size)) {
                $size = trim($m_size[1]);
            }

            $tracker = 'Torrentio';
            if (preg_match('/⚙️\s*([^\n\r]+)/u', $raw_title, $m_tracker)) {
                $tracker = trim($m_tracker[1]);
            }

            // Detectar calidad desde name o release_line
            $quality = $this->extractQuality($raw_name . ' ' . $display_title);

            // Analizar etiquetas de audio, subtítulos y banderas de idioma (estilo Torrentio)
            $meta = $this->parseAudioAndFlags($raw_title, $display_title, $tracker);

            // Construir enlace Magnet con trackers extra e índice de archivo para Season Packs
            $extra_trackers = [];
            if (!empty($s['sources']) && is_array($s['sources'])) {
                foreach ($s['sources'] as $src) {
                    if (is_string($src) && strpos($src, 'tracker:') === 0) {
                        $tr_url = substr($src, 8);
                        if (!empty($tr_url)) {
                            $extra_trackers[] = $tr_url;
                        }
                    }
                }
            }

            $ep_code = ($season !== null && $episode !== null) ? sprintf('S%02dE%02d', $season, $episode) : null;
            $magnet = $this->buildMagnet($info_hash, $episode_file_line ?: $release_line, $extra_trackers, $file_idx, $ep_code);

            // Priorizar torrents de episodio individual (fileIdx === null) por encima de Season Packs (fileIdx !== null)
            // porque descargan más rápido y contienen únicamente el capítulo solicitado
            $pack_penalty = ($file_idx !== null || $episode_file_line !== null) ? 0 : 3000;

            $parsed[] = [
                'provider' => $this->getId(),
                'provider_name' => "Torrentio ({$tracker})",
                'type' => 'torrent',
                'title' => $display_title,
                'release_title' => $release_line,
                'episode_file' => $episode_file_line,
                'server' => "⚙️ {$tracker}",
                'tracker' => $tracker,
                'quality' => $quality,
                'language' => $meta['language'],
                'audio_tags' => $meta['audio_tags'],
                'flags' => $meta['flags'],
                'is_latino' => $meta['is_latino'],
                'is_multi_audio' => $meta['is_multi_audio'],
                'url' => $magnet,
                'size' => $size,
                'seeds' => $seeds,
                'fileIdx' => $file_idx,
                '_score' => $meta['score'] + $pack_penalty + min($seeds, 2000)
            ];
        }

        // Ordenar priorizando Español Latino / Multi Audio / Dual Audio con bandera 🇲🇽 y luego por seeds
        usort($parsed, function($a, $b) {
            return $b['_score'] <=> $a['_score'];
        });

        // Limpiar campo interno _score y devolver hasta 25 resultados ricos
        $final = array_slice($parsed, 0, 25);
        foreach ($final as &$item) {
            unset($item['_score']);
        }

        return $final;
    }

    private function extractQuality(string $text): string
    {
        if (preg_match('/(2160p|4k|uhd)/i', $text)) return '4K 2160p';
        if (preg_match('/1080p/i', $text)) return '1080p Full HD';
        if (preg_match('/720p/i', $text)) return '720p HD';
        if (preg_match('/480p/i', $text)) return '480p SD';
        if (preg_match('/(bluray|bdrip|brrip)/i', $text)) return 'BluRay HD';
        if (preg_match('/(web-?dl|webrip)/i', $text)) return 'WEB-DL HD';
        return 'HD';
    }

    private function parseAudioAndFlags(string $raw_title, string $display_title, string $tracker): array
    {
        $combined = $raw_title . ' ' . $display_title;

        // 1. Detectar primero releases en Francés (donde MULTi = Francés + Japonés, NO incluye Español)
        if (function_exists('is_french_release') && is_french_release($display_title) && !preg_match('/\b(latino|latam|es[\s\.\-_]*mx)\b/i', $display_title)) {
            $is_fr_dub = (bool)preg_match('/\b(multi|vf|vff|vfi|french|truefrench)\b/i', $display_title);
            $fr_lang = $is_fr_dub ? '🇫🇷 Audio Francés / Japonés (VF)' : '🇫🇷 Sub Francés (VOSTFR)';
            return [
                'language' => $fr_lang,
                'audio_tags' => '🇫🇷 Francés / VO',
                'flags' => ['🇫🇷', '🇯🇵'],
                'is_latino' => false,
                'is_multi_audio' => false,
                'score' => 50
            ];
        }

        $has_mx_flag = (strpos($raw_title, '🇲🇽') !== false);
        $has_es_flag = (strpos($raw_title, '🇪🇸') !== false);
        $has_en_flag = (strpos($raw_title, '🇬🇧') !== false || strpos($raw_title, '🇺🇸') !== false);
        $has_jp_flag = (strpos($raw_title, '🇯🇵') !== false);
        $has_pt_flag = (strpos($raw_title, '🇵🇹') !== false || strpos($raw_title, '🇧🇷') !== false);
        $has_fr_flag = (strpos($raw_title, '🇫🇷') !== false);
        $has_it_flag = (strpos($raw_title, '🇮🇹') !== false);
        $has_de_flag = (strpos($raw_title, '🇩🇪') !== false);

        $is_dubbed = (bool)preg_match('/\b(dubbed|doblado)\b/i', $combined);
        // IMPORTANTE: NUNCA usar \bmulti\b suelto porque coincide con "Multi Subs" / "Multi-Subs"
        $is_multi_audio = (bool)preg_match('/\b(multi[\s\.\-_]*audio|multi[\s\.\-_]*dub|cr[\s\.\-_]*web-?dl[\s\.\-_]*multi)\b/i', $combined);
        $is_dual_audio = (bool)preg_match('/\b(dual[\s\.\-_]*audio|cr[\s\.\-_]*web-?dl[\s\.\-_]*dual|\bdual\b)\b/i', $combined);
        $is_multi_subs = (bool)preg_match('/\b(multi[\s\.\-_]*subs?|multisub)\b/i', $combined);

        $explicit_latino = (bool)preg_match('/\b(latino|latam|audio[\s\.\-_]*latino|dual[\s\.\-_]*lat(?:ino)?|es[\s\.\-_]*mx|es[\s\.\-_]*419|esp[\s\.\-_]*lat|cinecalidad)\b/i', $combined . ' ' . $tracker);
        $explicit_castellano = (bool)preg_match('/\b(castellano|mejortorrent|wolfmax4k)\b/i', $combined . ' ' . $tracker);

        // Construir lista de badges de audio (igual que Torrentio: Dubbed, Multi Audio, Dual Audio, Multi Subs)
        $tags = [];
        if ($is_dubbed) $tags[] = 'Dubbed';
        if ($is_multi_audio) $tags[] = 'Multi Audio';
        elseif ($is_dual_audio) $tags[] = 'Dual Audio';
        if ($is_multi_subs) $tags[] = 'Multi Subs';

        // Construir lista de banderas detectadas
        $flags = [];
        if ($has_mx_flag || $explicit_latino) $flags[] = '🇲🇽';
        if ($has_es_flag || $explicit_castellano) $flags[] = '🇪🇸';
        if ($has_en_flag) $flags[] = '🇬🇧';
        if ($has_jp_flag) $flags[] = '🇯🇵';
        if ($has_pt_flag) $flags[] = '🇵🇹';
        if ($has_fr_flag) $flags[] = '🇫🇷';
        if ($has_it_flag) $flags[] = '🇮🇹';
        if ($has_de_flag) $flags[] = '🇩🇪';

        $is_latino = false;
        $score = 0;

        $tag_summary = !empty($tags) ? implode(' / ', $tags) : '';
        $flag_summary = !empty($flags) ? implode(' ', array_slice($flags, 0, 5)) : '';

        // CASO 1: Audio Español Latino confirmado (Multi Audio con 🇲🇽, CR WEB-DL MULTi de VARYG, o explícitamente Latino)
        if ($explicit_latino || ($is_multi_audio && ($has_mx_flag || preg_match('/\bcr[\s\.\-_]*web-?dl[\s\.\-_]*multi\b/i', $display_title))) || (($is_dual_audio || $is_dubbed) && !$is_multi_subs && $has_mx_flag)) {
            $is_latino = true;
            $score = 60000;
            if (!in_array('🇲🇽', $flags)) array_unshift($flags, '🇲🇽');
            if ($is_multi_audio) {
                $language = "🇲🇽 Audio Español Latino (Multi Audio)";
            } elseif ($is_dual_audio) {
                $language = "🇲🇽 Audio Español Latino (Dual Audio)";
            } else {
                $language = "🇲🇽 Audio Español Latino";
            }
        }
        // CASO 2: Audio Español Castellano confirmado
        elseif ($explicit_castellano || ($is_multi_audio && $has_es_flag && !$has_mx_flag) || (($is_dual_audio || $is_dubbed) && !$is_multi_subs && $has_es_flag)) {
            $score = 35000;
            $language = $is_multi_audio ? "🇪🇸 Audio Español (Multi Audio)" : "🇪🇸 Audio Español Castellano";
        }
        // CASO 3: Dual Audio (Japonés + Inglés) con Multi-Subs que incluyen subtítulos en Español Latino (ej: VARYG DUAL, ToonsHub Dual-Audio Multi-Subs)
        elseif ($is_dual_audio && $is_multi_subs && ($has_mx_flag || $has_es_flag)) {
            $is_latino = false;
            $score = 26000;
            $language = "🇬🇧/🇯🇵 Dual Audio (Ing/Jap) + 🇲🇽 Sub Latino";
        }
        // CASO 4: Audio Japonés / Original con Multi-Subs que incluyen subtítulos en Español Latino (ej: DKB, Judas)
        elseif ($is_multi_subs && ($has_mx_flag || $has_es_flag)) {
            $is_latino = false;
            $score = 20000;
            $language = "🇯🇵 Audio Original + 🇲🇽 Sub Latino";
        }
        // CASO 5: Multi Audio sin bandera explícita pero no francés
        elseif ($is_multi_audio) {
            $score = 15000;
            $language = "🌐 Multi Audio" . ($flag_summary ? " ({$flag_summary})" : "");
        }
        // CASO 6: Dual Audio / Dubbed solo Inglés + Japonés (ej: EMBER, sam, Sokudo, Yameii)
        elseif ($is_dual_audio || $is_dubbed) {
            $score = 10000;
            $language = $is_dual_audio ? "🇬🇧/🇯🇵 Dual Audio (Inglés / Japonés)" : "🇬🇧 Audio Inglés (Dubbed)";
        }
        // CASO 7: Multi-Subs en otros idiomas (sin español)
        elseif ($is_multi_subs) {
            $score = 5000;
            $language = "🇯🇵 Subtitulado (Multi-Subs sin Esp)";
        }
        // CASO 8: Inglés / Sub Inglés (ej: SubsPlease)
        else {
            $score = 2000;
            $language = detect_release_language($display_title);
        }

        $audio_badge_text = trim($tag_summary . ($flag_summary ? ' • ' . $flag_summary : ''), ' •');

        return [
            'language' => $language,
            'audio_tags' => $audio_badge_text ?: $language,
            'flags' => $flags,
            'is_latino' => $is_latino,
            'is_multi_audio' => ($is_multi_audio || $is_dual_audio),
            'score' => $score
        ];
    }

    private function buildMagnet(string $hash, string $dn, array $extra_trackers = [], ?int $file_idx = null, ?string $ep_code = null): string
    {
        $default_trackers = [
            'udp://tracker.opentrackr.org:1337/announce',
            'udp://open.stealth.si:80/announce',
            'udp://tracker.torrent.eu.org:451/announce',
            'udp://exodus.desync.com:6969/announce',
            'udp://open.demonii.com:1337/announce',
            'wss://tracker.openwebtorrent.com',
            'wss://tracker.btorrent.xyz'
        ];

        $all_trackers = array_values(array_unique(array_merge($extra_trackers, $default_trackers)));
        $tr_query = implode('&', array_map(fn($t) => 'tr=' . rawurlencode($t), $all_trackers));

        $magnet = "magnet:?xt=urn:btih:{$hash}&dn=" . rawurlencode($dn) . "&{$tr_query}";
        if ($file_idx !== null) {
            $magnet .= "&fileIdx={$file_idx}";
        }
        if ($ep_code !== null) {
            $magnet .= "&ep=" . rawurlencode($ep_code);
        }
        return $magnet;
    }
}
