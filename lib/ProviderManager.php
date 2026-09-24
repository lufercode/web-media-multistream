<?php
require_once __DIR__ . '/providers/ProviderInterface.php';
require_once __DIR__ . '/providers/LocalCDNProvider.php';
require_once __DIR__ . '/providers/CuevanaProvider.php';
require_once __DIR__ . '/providers/PelisPlusProvider.php';
require_once __DIR__ . '/providers/CinecalidadProvider.php';
require_once __DIR__ . '/providers/DonTorrentProvider.php';
require_once __DIR__ . '/providers/AnimeProvider.php';
require_once __DIR__ . '/providers/TioAnimeProvider.php';
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
            new CuevanaProvider(),
            new PelisPlusProvider(),
            new PelisForteProvider(),
            new PelisPediaProvider(),
            new LaMovieProvider(),
            new SeriesKaoProvider(),
            new RetroTVEProvider(),
            new GnulaProvider(),
            new AllPeliculasProvider(),
            new PoseidonHDProvider(),
            new HackTorrentProvider(),
            new EliteTorrentProvider(),
            new YTSProvider(),
            new CinecalidadProvider(),
            new DonTorrentProvider(),
            new PirateBayProvider(),
            new AnimeProvider(),
            new TioAnimeProvider(),
            new NyaaProvider()
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
        return null;
    }

    /**
     * Comprueba de forma rápida si un contenido está disponible en al menos un proveedor
     */
    public function checkAvailability(string $title, string $type, ?string $year = null, ?int $tmdb_id = null, ?string $original_title = null, bool $is_anime = true): bool
    {
        if (connection_aborted()) exit;
        $anime_providers = ['anime', 'tioanime', 'nyaa'];

        foreach ($this->providers as $provider) {
            if (!$provider->isEnabled()) continue;
            if (!$is_anime && in_array($provider->getId(), $anime_providers, true)) continue;

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
                error_log("Error comprobando proveedor {$provider->getId()}: " . $e->getMessage());
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
        $anime_providers = ['anime', 'tioanime', 'nyaa'];

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
        $anime_providers = ['anime', 'tioanime', 'nyaa'];

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

        return $consolidated;
    }

    /**
     * Retorna la lista de proveedores activos con sus metadatos
     */
    public function getEnabledProvidersList(bool $is_anime = true): array
    {
        $list = [];
        $anime_providers = ['anime', 'tioanime', 'nyaa'];
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

        $anime_providers = ['anime', 'tioanime', 'nyaa'];
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

        $anime_providers = ['anime', 'tioanime', 'nyaa'];
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

        return $consolidated;
    }
}
