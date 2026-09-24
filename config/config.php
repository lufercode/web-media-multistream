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

// Autenticación con Google (Google Identity Services - OAuth 2.0 Web)
// Client ID de Google Cloud Console (web-media-multistream)
define('GOOGLE_CLIENT_ID', '474518961918-0r1n29n2gegd6clc3etvidmp21t4c4kh.apps.googleusercontent.com');

// Interruptores de Proveedores (Habilitar / Deshabilitar fuentes individuales)
$PROVIDERS_CONFIG = [
    'local_cdn' => [
        'enabled' => true,
        'name' => 'CDN Propio (Rakun)'
    ],
    'cuevana' => [
        'enabled' => true,
        'name' => 'Cuevana (Streaming Películas y Series)'
    ],
    'pelisplus' => [
        'enabled' => false,
        'name' => 'PelisPlus HD (Dominio extinto / Deshabilitado)'
    ],
    'cinecalidad' => [
        'enabled' => true,
        'name' => 'Cinecalidad (Películas / 4K / Torrents)'
    ],
    'dontorrent' => [
        'enabled' => true,
        'name' => 'DonTorrent (Torrents / 4K / MicroHD)'
    ],
    'anime' => [
        'enabled' => true,
        'name' => 'JKAnime (Anime en Streaming)'
    ],
    'tioanime' => [
        'enabled' => true,
        'name' => 'TioAnime (Anime en Streaming / Mega / Waaw)'
    ],
    'pelisforte' => [
        'enabled' => false,
        'name' => 'PelisForte (Dominio extinto / Deshabilitado)'
    ],
    'pelispedia' => [
        'enabled' => true,
        'name' => 'PelisPedia (Streaming HD / Fastream)'
    ],
    'lamovie' => [
        'enabled' => true,
        'name' => 'LaMovie (Películas, Series y Torrents)'
    ],
    'serieskao' => [
        'enabled' => true,
        'name' => 'SeriesKao (Películas y Series HD)'
    ],
    'retrotve' => [
        'enabled' => true,
        'name' => 'RetroTVE (Clásicos y Series Retro)'
    ],
    'gnula' => [
        'enabled' => true,
        'name' => 'Gnula (Películas HD)'
    ],
    'allpeliculas' => [
        'enabled' => true,
        'name' => 'AllPeliculas (Películas y Series HD)'
    ],
    'hacktorrent' => [
        'enabled' => true,
        'name' => 'HackTorrent (Torrents y Streaming HD)'
    ],
    'elitetorrent' => [
        'enabled' => true,
        'name' => 'EliteTorrent (Torrents HD / MicroHD / 4K)'
    ],
    'yts' => [
        'enabled' => true,
        'name' => 'YTS (YIFY Torrents HD / 4K / VOSE)'
    ],
    'poseidonhd' => [
        'enabled' => true,
        'name' => 'PoseidonHD (Películas y Series HD / Alfa)'
    ],
    'nyaa' => [
        'enabled' => true,
        'name' => 'Nyaa (Anime Torrents HD / Multi-Sub)'
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

