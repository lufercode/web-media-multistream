<?php
// Configuración básica
define('TMDB_API_KEY', 'aaccf8f89c76c5a7ac753e3a8402de56');
define('TMDB_LANG', 'es-MX'); // Idioma español Latinoamérica
define('MAX_TIMEOUT', 3);      // Timeout en segundos para peticiones de red

// Configuración de almacenamiento local y caché
define('ROOT_DIR', dirname(__DIR__));
define('CACHE_DIR', ROOT_DIR . '/data/cache');
define('CACHE_TIME', 604800);                         // 7 días de caché para TMDB
define('AVAILABILITY_CACHE_TIME', 86400);              // Fallback compatibilidad (24h)
define('AVAILABILITY_CACHE_FOUND', 86400);            // 24 horas si fue encontrado disponible
define('AVAILABILITY_CACHE_NOT_FOUND', 14400);        // 4 horas si no fue encontrado (reintentar estrenos)
define('LINKS_CACHE_TIME', 1800);                     // 30 minutos de caché para enlaces por proveedor
define('CACHE_MAX_RETENTION_DAYS', 15);               // 15 días retención máxima para limpieza automática
define('WATCHED_PROGRESS_FILE', ROOT_DIR . '/data/watched_progress.json'); // Compatibilidad hacia atrás
define('PROGRESS_DIR', ROOT_DIR . '/data/progress');
define('WATCHLIST_DIR', ROOT_DIR . '/data/watchlist');

// Auto-creación de carpetas de datos y caché si no existen en el servidor
$__init_dirs = [
    ROOT_DIR . '/data',
    CACHE_DIR,
    PROGRESS_DIR,
    WATCHLIST_DIR,
    ROOT_DIR . '/data/torrent_cache',
    ROOT_DIR . '/data/movieplus',
    ROOT_DIR . '/data/movieplus/cache',
];
foreach ($__init_dirs as $__d) {
    if (!is_dir($__d)) {
        @mkdir($__d, 0777, true);
    }
}
unset($__init_dirs, $__d);

// Autenticación con Google (Google Identity Services - OAuth 2.0 Web)
// Client ID de Google Cloud Console (web-media-multistream)
define('GOOGLE_CLIENT_ID', '474518961918-0r1n29n2gegd6clc3etvidmp21t4c4kh.apps.googleusercontent.com');

// Modo de reproducción para enlaces BitTorrent / Magnet
// 'streamer': Servidor Node.js WebTorrent propio con ArtPlayer nativo (en localhost o en la nube gratuita ej: Render.com)
// 'webtor': Reproductor en la nube Webtor.io (Legacy / Fallback)
define('TORRENT_PLAYER_MODE', 'streamer');

// Cluster de Servidores Remotos (Render / Cloud) con Conmutación Inteligente y Failover
define('TORRENT_STREAMER_POOL', [
    'https://web-media-multistream-f4qa.onrender.com', // Nodo activo / backup actual
    'https://web-media-multistream.onrender.com'       // Nodo principal (se reactiva el 1 de cada mes)
]);

if (!function_exists('get_active_torrent_streamer_url')) {
    function get_active_torrent_streamer_url(): string {
        static $resolved_url = null;
        if ($resolved_url !== null) {
            return $resolved_url;
        }

        $cache_file = sys_get_temp_dir() . '/streamer_active_node.txt';
        if (file_exists($cache_file) && (time() - filemtime($cache_file) < 180)) {
            $cached = trim((string)@file_get_contents($cache_file));
            if (!empty($cached)) {
                $resolved_url = $cached;
                return $resolved_url;
            }
        }

        $nodes = defined('TORRENT_STREAMER_POOL') ? TORRENT_STREAMER_POOL : [];
        foreach ($nodes as $node) {
            $node = rtrim($node, '/');
            if (empty($node)) continue;

            $ch = curl_init("{$node}/health");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && $res && stripos($res, '"status":"ok"') !== false) {
                $resolved_url = $node;
                @file_put_contents($cache_file, $node);
                return $resolved_url;
            }
        }

        $resolved_url = !empty($nodes[0]) ? $nodes[0] : '';
        @file_put_contents($cache_file, $resolved_url);
        return $resolved_url;
    }
}

// URL activa resuelta dinámicamente con conmutación inteligente
define('TORRENT_STREAMER_REMOTE_URL', get_active_torrent_streamer_url());

// Interruptores de Proveedores (Habilitar / Deshabilitar fuentes individuales)
$PROVIDERS_CONFIG = [
    'local_cdn' => [
        'enabled' => true,
        'name' => 'CDN Propio (Rakun)'
    ],
    'movieplus' => [
        'enabled' => true,
        'name' => 'Movie+ (Cloud CDN - Latino y Castellano)'
    ],
    'lamovie' => [
        'enabled' => true,
        'name' => 'LaMovie (Películas, Series y Anime HD)'
    ],
    'serieskao' => [
        'enabled' => true,
        'name' => 'SeriesKao (Películas, Series y Anime HD)'
    ],
    'pelisplus' => [
        'enabled' => true,
        'name' => 'PelisPlus HD (Películas, Series y Anime Latino)'
    ],
    'latanime' => [
        'enabled' => true,
        'name' => 'LatAnime (Anime en Español Latino y Castellano HD)'
    ],
    'cuevana' => [
        'enabled' => true,
        'name' => 'Cuevana (Streaming Películas y Series)'
    ],
    'poseidonhd' => [
        'enabled' => true,
        'name' => 'PoseidonHD (Películas y Series HD / Alfa)'
    ],
    'cinecalidad' => [
        'enabled' => true,
        'name' => 'Cinecalidad (Películas / 4K / Torrents)'
    ],
    'pelispedia' => [
        'enabled' => true,
        'name' => 'PelisPedia (Películas, Series y Anime HD)'
    ],
    'pelisforte' => [
        'enabled' => true,
        'name' => 'PelisForte (Películas 1080p Full HD)'
    ],
    'allpeliculas' => [
        'enabled' => true,
        'name' => 'AllPeliculas (Películas, Series y Anime HD)'
    ],
    'hacktorrent' => [
        'enabled' => true,
        'name' => 'HackStore (Películas, Series y Anime HD)'
    ],
    'gnula' => [
        'enabled' => true,
        'name' => 'Gnula (Películas HD)'
    ],
    'retrotve' => [
        'enabled' => true,
        'name' => 'RetroTVE (Clásicos y Series Retro)'
    ],
    'animeflv' => [
        'enabled' => true,
        'name' => 'AnimeFLV (Anime Latino y Subtitulado HD)'
    ],
    'jkanime' => [
        'enabled' => true,
        'name' => 'JKAnime (Anime en Español Latino y Subtitulado HD)'
    ],
    'anime' => [
        'enabled' => true,
        'name' => 'JKAnime (Anime en Streaming)'
    ],
    'tioanime' => [
        'enabled' => true,
        'name' => 'TioAnime (Anime en Streaming / Mega / Waaw)'
    ],
    'torrentio' => [
        'enabled' => true,
        'name' => 'Torrentio (TorrentGalaxy / Nyaa / 1337x / Multi-Audio)'
    ],
    'yts' => [
        'enabled' => true,
        'name' => 'YTS (YIFY Torrents HD / 4K / VOSE)'
    ],
    'thepiratebay' => [
        'enabled' => true,
        'name' => 'The Pirate Bay (Dual Latino / 4K / HD)'
    ],
    'nyaa' => [
        'enabled' => true,
        'name' => 'Nyaa (Anime Torrents HD / Multi-Sub)'
    ],
    'dontorrent' => [
        'enabled' => false,
        'name' => 'DonTorrent (Dominio inactivo)'
    ],
    'elitetorrent' => [
        'enabled' => false,
        'name' => 'EliteTorrent (Dominio inactivo)'
    ]
];

// Tipos de contenido para TMDB
$CONTENT_TYPES = [
    'movie' => 'Películas',
    'tv' => 'Series',
    'anime' => 'Animación'
];

// Dominios de tu CDN propio
$BASE_DOMAINS = [
    'https://cdn.rakun.site/file/rakun-cloud/'
];

// Patrones de archivos para el CDN propio
$URL_PATTERNS = [
    'movie' => [
        'folder' => '/vies/',
        'files' => [
            '{name_formatted}.mkv',
            '{name_formatted}.mp4',
            '{name_formatted} ({year}).mkv',
            '{name_formatted} ({year}).mp4',
        ],
    ],
    'series' => [
        'folder' => '/sho/{name_formatted}/s{season_num}/',
        'files' => [
            'S{season_padded}E{episode_padded}.mkv',
            's{season_padded}e{episode_padded}.mkv',
            '{name_formatted} - S{season_padded}E{episode_padded}.mkv',
            'S{season_padded}E{episode_padded}.mp4',
            's{season_padded}e{episode_padded}.mp4',
        ],
    ],
    'anime' => [
        'folder' => '/nime/{name_formatted}/s{season_num}/',
        'files' => [],
    ]
];

