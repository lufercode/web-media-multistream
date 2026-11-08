<?php
require_once __DIR__ . '/providers/ProviderInterface.php';
require_once __DIR__ . '/providers/LocalCDNProvider.php';
require_once __DIR__ . '/providers/MoviePlusProvider.php';
require_once __DIR__ . '/providers/CuevanaProvider.php';
require_once __DIR__ . '/providers/PelisPlusProvider.php';
require_once __DIR__ . '/providers/CinecalidadProvider.php';
require_once __DIR__ . '/providers/DonTorrentProvider.php';
require_once __DIR__ . '/providers/AnimeProvider.php';
require_once __DIR__ . '/providers/TioAnimeProvider.php';
require_once __DIR__ . '/providers/LatAnimeProvider.php';
require_once __DIR__ . '/providers/AnimeFLVProvider.php';
require_once __DIR__ . '/providers/JKAnimeProvider.php';
require_once __DIR__ . '/providers/PelisForteProvider.php';
require_once __DIR__ . '/providers/PelisPediaProvider.php';
require_once __DIR__ . '/providers/LaMovieProvider.php';
require_once __DIR__ . '/providers/SeriesKaoProvider.php';
require_once __DIR__ . '/providers/RetroTVEProvider.php';
require_once __DIR__ . '/providers/GnulaProvider.php';
require_once __DIR__ . '/providers/AllPeliculasProvider.php';
require_once __DIR__ . '/providers/HackTorrentProvider.php';
require_once __DIR__ . '/providers/EliteTorrentProvider.php';
require_once __DIR__ . '/providers/YTSProvider.php';
require_once __DIR__ . '/providers/PoseidonHDProvider.php';
require_once __DIR__ . '/providers/NyaaProvider.php';
require_once __DIR__ . '/providers/PirateBayProvider.php';
require_once __DIR__ . '/providers/TorrentioProvider.php';

class ProviderManager
{
    /** @var ProviderInterface[] */
    private array $providers = [];

    public function __construct()
    {
        $this->registerDefaultProviders();
    }

    private function registerDefaultProviders(): void
    {
        $this->providers = [
            new LocalCDNProvider(),
            new MoviePlusProvider(),
            new LaMovieProvider(),
            new SeriesKaoProvider(),
            new PelisPlusProvider(),
            new LatAnimeProvider(),
            new AnimeFLVProvider(),
            new JKAnimeProvider(),
            new CuevanaProvider(),
            new PoseidonHDProvider(),
            new PelisPediaProvider(),
            new PelisForteProvider(),
            new AllPeliculasProvider(),
            new CinecalidadProvider(),
            new GnulaProvider(),
            new RetroTVEProvider(),
            new TorrentioProvider(),
            new HackTorrentProvider(),
            new YTSProvider(),
            new PirateBayProvider(),
            new TioAnimeProvider(),
            new NyaaProvider(),
            new EliteTorrentProvider(),
            new DonTorrentProvider()
        ];
    }

    /**
     * Retorna todos los proveedores registrados
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Retorna un proveedor específico por su ID
     */
    public function getProvider(string $id): ?ProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getId() === $id) {
                return $provider;
            }
        }
        if ($id === 'anime') {
            return $this->getProvider('jkanime');
        }
        return null;
    }

    /**
     * Comprueba de forma rápida si un contenido está disponible en al menos un proveedor
     */
    public function checkAvailability(string $title, string $type, ?string $year = null, ?int $tmdb_id = null, ?string $original_title = null, bool $is_anime = true): bool
    {
        if (connection_aborted()) exit;

        // Lista prioritaria de alta velocidad y máxima cobertura para comprobación ligera en tarjetas
        // Evita saturar el hosting compartido (InfinityFree/cPanel) ejecutando 25 scrapers externos por cada tarjeta
        $fast_check_ids = $is_anime
            ? ['movieplus', 'local_cdn', 'latanime', 'animeflv', 'serieskao', 'torrentio']
            : ['movieplus', 'local_cdn', 'lamovie', 'serieskao', 'pelisplus', 'cuevana', 'torrentio'];

        $external_checks = 0;
        $max_external_checks = 3; // Límite estricto de scrapers externos por tarjeta para proteger los Entry Processes

        foreach ($this->providers as $provider) {
            if (connection_aborted()) exit;
            $pid = $provider->getId();

            if (!in_array($pid, $fast_check_ids, true)) continue;
            if (!$provider->isEnabled()) continue;

            // movieplus y local_cdn son instantáneos (SQLite local o caché en memoria)
            $is_instant = ($pid === 'movieplus' || $pid === 'local_cdn');
            if (!$is_instant) {
                if ($external_checks >= $max_external_checks) {
                    break;
                }
                $external_checks++;
            }

            try {
                if ($type === 'movie') {
                    $results = $provider->searchMovie($title, $year, $tmdb_id);
                    if (!empty($results)) return true;
                    if ($original_title && strtolower($original_title) !== strtolower($title)) {
                        $orig_res = $provider->searchMovie($original_title, $year, $tmdb_id);
                        if (!empty($orig_res)) return true;
                    }
                } else {
                    $results = $provider->searchSeries($title, 1, 1, $tmdb_id);
                    if (!empty($results)) return true;
                    if ($original_title && strtolower($original_title) !== strtolower($title)) {
                        $orig_res = $provider->searchSeries($original_title, 1, 1, $tmdb_id);
                        if (!empty($orig_res)) return true;
                    }
                }
            } catch (\Throwable $e) {
                // Silenciar fallos de scrapers en la verificación ligera
            }
        }

        return false;
    }

    /**
     * Busca todas las fuentes para una película y las agrupa por categoría,
     * consultando tanto el título en español como el título original si difiere.
     */
    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null, ?string $original_title = null, bool $is_anime = true): array
    {
        $consolidated = [
            'direct' => [],
            'streaming' => [],
            'torrent' => []
        ];

        $titles = array_filter(array_unique([$title, $original_title]));
        $anime_providers = ['anime', 'tioanime', 'latanime', 'animeflv', 'jkanime', 'nyaa'];

        foreach ($this->providers as $provider) {
            if (connection_aborted()) exit;
            if (!$provider->isEnabled()) continue;
            if (!$is_anime && in_array($provider->getId(), $anime_providers, true)) continue;

            $prov_sources = [];
            foreach ($titles as $t) {
                if (empty($t)) continue;
                try {
                    $sources = $provider->searchMovie($t, $year, $tmdb_id);
                    if (!empty($sources)) {
                        $prov_sources = array_merge($prov_sources, $sources);
                        if (count($sources) >= 5) break;
                    }
                } catch (\Throwable $e) {
                    // Continuar
                }
            }

            foreach ($prov_sources as $source) {
                $type = $source['type'] ?? 'streaming';
                if (isset($consolidated[$type])) {
                    $consolidated[$type][] = $source;
                } else {
                    $consolidated['streaming'][] = $source;
                }
            }
        }

        // Deduplicar fuentes consolidadas por URL
        foreach ($consolidated as $type => $list) {
            $seen = [];
            $unique = [];
            foreach ($list as $item) {
                $url = $item['url'] ?? '';
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $unique[] = $item;
                }
            }
            $consolidated[$type] = $unique;
        }

        // Sanitizar y descartar servidores muertos / caídos / inseguros
        $consolidated['streaming'] = sanitize_and_filter_stream_links($consolidated['streaming']);
        $consolidated['direct'] = sanitize_and_filter_stream_links($consolidated['direct']);

        return $consolidated;
    }

    /**
     * Busca todas las fuentes para un episodio de serie,
     * consultando tanto el título en español como el título original si difiere.
     */
    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null, ?string $original_title = null, ?int $absolute_episode = null, bool $is_anime = true): array
    {
        $consolidated = [
            'direct' => [],
            'streaming' => [],
            'torrent' => []
        ];

        $titles = array_filter(array_unique([$title, $original_title]));
        $anime_providers = ['anime', 'tioanime', 'latanime', 'animeflv', 'jkanime', 'nyaa'];

        foreach ($this->providers as $provider) {
            if (connection_aborted()) exit;
            if (!$provider->isEnabled()) continue;
            if (!$is_anime && in_array($provider->getId(), $anime_providers, true)) continue;

            $prov_sources = [];
            foreach ($titles as $t) {
                if (empty($t)) continue;
                try {
                    $sources = $provider->searchSeries($t, $season, $episode, $tmdb_id, $absolute_episode);
                    if (!empty($sources)) {
                        $prov_sources = array_merge($prov_sources, $sources);
                        if (count($sources) >= 5) break;
                    }
                } catch (\Throwable $e) {
                    // Continuar
                }
            }

            foreach ($prov_sources as $source) {
                $type = $source['type'] ?? 'streaming';
                if (isset($consolidated[$type])) {
                    $consolidated[$type][] = $source;
                } else {
                    $consolidated['streaming'][] = $source;
                }
            }
        }

        // Deduplicar fuentes consolidadas por URL
        foreach ($consolidated as $type => $list) {
            $seen = [];
            $unique = [];
            foreach ($list as $item) {
                $url = $item['url'] ?? '';
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $unique[] = $item;
                }
            }
            $consolidated[$type] = $unique;
        }

        // Sanitizar y descartar servidores muertos / caídos / inseguros
        $consolidated['streaming'] = sanitize_and_filter_stream_links($consolidated['streaming']);
        $consolidated['direct'] = sanitize_and_filter_stream_links($consolidated['direct']);

        return $consolidated;
    }

    /**
     * Retorna todas las fuentes para Stremio agrupadas en 'streaming', 'direct' y 'torrent'
     */
    public function getStreamsForMedia(string $title, ?string $year = null, ?int $season = null, ?int $episode = null, ?int $tmdb_id = null, string $mediaType = 'movie', ?string $original_title = null, bool $is_anime = true): array
    {
        if ($mediaType === 'tv' && $season !== null && $episode !== null) {
            return $this->searchSeries($title, (int)$season, (int)$episode, $tmdb_id, $original_title, null, $is_anime);
        }
        return $this->searchMovie($title, $year, $tmdb_id, $original_title, $is_anime);
    }

    /**
     * Retorna la lista de proveedores activos con sus metadatos
     */
    public function getEnabledProvidersList(bool $is_anime = true): array
    {
        $list = [];
        $anime_providers = ['anime', 'tioanime', 'latanime', 'animeflv', 'jkanime', 'nyaa'];
        foreach ($this->providers as $provider) {
            if (!$is_anime && in_array($provider->getId(), $anime_providers, true)) {
                continue;
            }
            if ($provider->isEnabled()) {
                $list[] = [
                    'id' => $provider->getId(),
                    'name' => $provider->getName(),
                    'type' => $provider->getType()
                ];
            }
        }
        return $list;
    }

    /**
     * Busca fuentes para una película en un único proveedor específico
     */
    public function searchMovieSingleProvider(string $provider_id, string $title, ?string $year = null, ?int $tmdb_id = null, ?string $original_title = null, bool $is_anime = true): array
    {
        $consolidated = [
            'direct' => [],
            'streaming' => [],
            'torrent' => []
        ];

        $anime_providers = ['anime', 'tioanime', 'latanime', 'animeflv', 'jkanime', 'nyaa'];
        if (!$is_anime && in_array($provider_id, $anime_providers, true)) {
            return $consolidated;
        }

        $provider = $this->getProvider($provider_id);
        if (!$provider || !$provider->isEnabled()) {
            return $consolidated;
        }

        $titles = array_filter(array_unique([$title, $original_title]));
        $prov_sources = [];

        foreach ($titles as $t) {
            if (empty($t)) continue;
            try {
                $sources = $provider->searchMovie($t, $year, $tmdb_id);
                if (!empty($sources)) {
                    $prov_sources = array_merge($prov_sources, $sources);
                    if (count($sources) >= 5) break;
                }
            } catch (\Throwable $e) {
                // Continuar
            }
        }

        foreach ($prov_sources as $source) {
            $type = $source['type'] ?? 'streaming';
            if (isset($consolidated[$type])) {
                $consolidated[$type][] = $source;
            } else {
                $consolidated['streaming'][] = $source;
            }
        }

        // Deduplicar por URL
        foreach ($consolidated as $type => $list) {
            $seen = [];
            $unique = [];
            foreach ($list as $item) {
                $url = $item['url'] ?? '';
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $unique[] = $item;
                }
            }
            $consolidated[$type] = $unique;
        }

        // Sanitizar y descartar servidores muertos / caídos / inseguros
        $consolidated['streaming'] = sanitize_and_filter_stream_links($consolidated['streaming']);
        $consolidated['direct'] = sanitize_and_filter_stream_links($consolidated['direct']);

        // Si el scraper local devolvió 0 enlaces (por bloqueo de IP en hosting compartido como Cloudflare en PoseidonHD),
        // intentar respaldo ligero desde Render como último recurso excepcional.
        if (empty($consolidated['streaming']) && empty($consolidated['direct']) && empty($consolidated['torrent'])) {
            $fallback = $this->fetchFromRenderFallback($provider_id, 'movie', $title, $year, $tmdb_id, $original_title);
            if (!empty($fallback['streaming']) || !empty($fallback['direct']) || !empty($fallback['torrent'])) {
                return $fallback;
            }
        }

        return $consolidated;
    }

    /**
     * Busca fuentes para un episodio de serie en un único proveedor específico
     */
    public function searchSeriesSingleProvider(string $provider_id, string $title, int $season, int $episode, ?int $tmdb_id = null, ?string $original_title = null, ?int $absolute_episode = null, bool $is_anime = true): array
    {
        $consolidated = [
            'direct' => [],
            'streaming' => [],
            'torrent' => []
        ];

        $anime_providers = ['anime', 'tioanime', 'latanime', 'animeflv', 'jkanime', 'nyaa'];
        if (!$is_anime && in_array($provider_id, $anime_providers, true)) {
            return $consolidated;
        }

        $provider = $this->getProvider($provider_id);
        if (!$provider || !$provider->isEnabled()) {
            return $consolidated;
        }

        $titles = array_filter(array_unique([$title, $original_title]));
        $prov_sources = [];

        foreach ($titles as $t) {
            if (empty($t)) continue;
            try {
                $sources = $provider->searchSeries($t, $season, $episode, $tmdb_id, $absolute_episode);
                if (!empty($sources)) {
                    $prov_sources = array_merge($prov_sources, $sources);
                    if (count($sources) >= 5) break;
                }
            } catch (\Throwable $e) {
                // Continuar
            }
        }

        foreach ($prov_sources as $source) {
            $type = $source['type'] ?? 'streaming';
            if (isset($consolidated[$type])) {
                $consolidated[$type][] = $source;
            } else {
                $consolidated['streaming'][] = $source;
            }
        }

        // Deduplicar por URL
        foreach ($consolidated as $type => $list) {
            $seen = [];
            $unique = [];
            foreach ($list as $item) {
                $url = $item['url'] ?? '';
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $unique[] = $item;
                }
            }
            $consolidated[$type] = $unique;
        }

        // Sanitizar y descartar servidores muertos / caídos / inseguros
        $consolidated['streaming'] = sanitize_and_filter_stream_links($consolidated['streaming']);
        $consolidated['direct'] = sanitize_and_filter_stream_links($consolidated['direct']);

        // Si el scraper local devolvió 0 enlaces (por bloqueo de IP en hosting compartido como Cloudflare en PoseidonHD),
        // intentar respaldo ligero desde Render como último recurso excepcional.
        if (empty($consolidated['streaming']) && empty($consolidated['direct']) && empty($consolidated['torrent'])) {
            $fallback = $this->fetchFromRenderFallback($provider_id, 'series', $title, null, $tmdb_id, $original_title, $season, $episode);
            if (!empty($fallback['streaming']) || !empty($fallback['direct']) || !empty($fallback['torrent'])) {
                return $fallback;
            }
        }

        return $consolidated;
    }

    /**
     * Respaldo excepcional: consulta a Render únicamente si el proveedor local fue bloqueado
     * por Cloudflare o red en el hosting compartido (ej. PoseidonHD en InfinityFree).
     */
    private function fetchFromRenderFallback(string $provider_id, string $type, string $title, ?string $year = null, ?int $tmdb_id = null, ?string $original_title = null, ?int $season = null, ?int $episode = null): array
    {
        $supported = ['poseidonhd', 'cuevana', 'serieskao', 'pelisplus', 'lamovie', 'latanime', 'animeflv', 'jkanime', 'cinecalidad'];
        if (!in_array($provider_id, $supported, true)) {
            return [];
        }

        $streamerUrl = '';
        if (function_exists('get_active_torrent_streamer_url')) {
            $streamerUrl = get_active_torrent_streamer_url();
        } elseif (defined('TORRENT_STREAMER_REMOTE_URL')) {
            $streamerUrl = TORRENT_STREAMER_REMOTE_URL;
        } else {
            $streamerUrl = 'https://web-media-multistream-f4qa.onrender.com';
        }

        if (empty($streamerUrl)) {
            return [];
        }

        $params = [
            'provider'      => $provider_id,
            'type'          => $type,
            'title'         => $title,
            'originalTitle' => $original_title ?: '',
            'year'          => $year ?: '',
            'tmdbId'        => $tmdb_id ?: '',
            'season'        => $season ?: 1,
            'episode'       => $episode ?: 1
        ];

        $apiUrl = rtrim($streamerUrl, '/') . '/api/fallback-provider?' . http_build_query($params);

        $raw = null;
        if (function_exists('curl_init')) {
            $ch = @curl_init($apiUrl);
            if ($ch) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Web-Media-Fallback',
                        'Accept: application/json'
                    ]
                ]);
                $raw = @curl_exec($ch);
                @curl_close($ch);
            }
        }

        if (!$raw) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout'       => 4,
                    'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Web-Media-Fallback\r\nAccept: application/json\r\n",
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false
                ]
            ]);
            $raw = @file_get_contents($apiUrl, false, $ctx);
        }

        if (!$raw) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!empty($data['links']) && is_array($data['links'])) {
            $links = $data['links'];
            $res = [
                'direct'    => $links['direct'] ?? [],
                'streaming' => $links['streaming'] ?? [],
                'torrent'   => $links['torrent'] ?? []
            ];

            if (function_exists('sanitize_and_filter_stream_links')) {
                $res['streaming'] = sanitize_and_filter_stream_links($res['streaming']);
                $res['direct']    = sanitize_and_filter_stream_links($res['direct']);
            }
            return $res;
        }

        return [];
    }
}
