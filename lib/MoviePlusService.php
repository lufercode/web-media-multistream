<?php
declare(strict_types=1);

/**
 * Movie+ Integration & Fast Index Engine
 * Provides AES-256 decryption, SQLite fast lookups, remote Firestore seasons, and Yandex CDN resolution.
 */
class MoviePlusService
{
    private static ?PDO $db = null;

    public const AES_ECB_KEY = "\x61\x6e\x64\x72\x6f\x69\x64\x5f\x69\x64\x66\x6b\x76\x6e\x38\x20\x77\x34\x79\x2a\x28\x4e\x43\x24\x47\x2a\x28\x47\x28\x24\x2a\x47";
    public const AES_GCM_KEY = "\x47\x40\x68\x66\x4d\x74\x5e\x65\x73\x65\x5e\x79\x6d\x43\x6e\x78\x5c\x59\x69\x5b\x5d\x6d\x62\x74\x59\x74\x7f\x69\x51\x2e\x2b\x08";

    public const GENRES = [
        1  => 'Acción',
        2  => 'Aventura',
        3  => 'Animación',
        4  => 'Comedia',
        5  => 'Crimen',
        6  => 'Documental',
        7  => 'Drama',
        8  => 'Familia',
        9  => 'Fantasía',
        10 => 'Historia',
        11 => 'Terror',
        12 => 'Música',
        13 => 'Misterio',
        14 => 'Romance',
        15 => 'Ciencia ficción',
        16 => 'Película de TV',
        17 => 'Suspense',
        18 => 'Bélica',
        19 => 'Western',
        20 => 'Conciertos',
        21 => 'Castellano',
        22 => 'UNRATED',
        23 => 'EXTENDED',
        24 => 'IMAX',
        25 => 'Talk show',
        26 => 'Bélico y político',
        27 => 'Recientes',
    ];

    public static function getDataDir(): string
    {
        $dir = dirname(__DIR__) . '/data/movieplus';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function getCacheDir(): string
    {
        $dir = self::getDataDir() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function getGzCatalogPath(): string
    {
        return self::getDataDir() . '/catalog_cache.json.gz';
    }

    public static function getDbPath(): string
    {
        return self::getDataDir() . '/catalog.db';
    }

    /**
     * Obtiene la conexión PDO SQLite optimizada
     */
    public static function getDb(): PDO
    {
        if (self::$db !== null) {
            return self::$db;
        }

        $dbPath = self::getDbPath();
        $needsIndex = !file_exists($dbPath) || filesize($dbPath) < 1000;

        self::$db = new PDO('sqlite:' . $dbPath);
        self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$db->exec('PRAGMA journal_mode = WAL;');
        self::$db->exec('PRAGMA synchronous = NORMAL;');
        self::$db->exec('PRAGMA cache_size = -8000;');

        if ($needsIndex) {
            self::buildIndexFromGz();
        }

        return self::$db;
    }

    /**
     * Normaliza un título para búsquedas rápidas insensibles a acentos y puntuación
     */
    public static function normalizeTitle(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        // Transliteración de tildes
        $str = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $str
        );
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);
        return trim((string)preg_replace('/\s+/', ' ', $str));
    }

    /**
     * Construye o reconstruye la base de datos SQLite a partir del archivo catalog_cache.json.gz
     */
    public static function buildIndexFromGz(): bool
    {
        $gzPath = self::getGzCatalogPath();
        if (!file_exists($gzPath)) {
            // Intentar buscar en ../movie-plus si existe
            $altPath = dirname(__DIR__, 2) . '/movie-plus/catalog_cache.json.gz';
            if (file_exists($altPath)) {
                @copy($altPath, $gzPath);
            } else {
                $urls = [
                    'https://raw.githubusercontent.com/dev-mplus01/mplus01T/main/db/list/items_up_r1791999803134.json.gz',
                    'https://raw.githubusercontent.com/dev-mplus01/mplus01T/main/db/items_up.json.gz',
                    'https://api.appsdevxp.com/db/items_up.json.gz'
                ];
                $downloaded = false;
                foreach ($urls as $u) {
                    $ctx = stream_context_create([
                        'http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 15]
                    ]);
                    $content = @file_get_contents($u, false, $ctx);
                    if ($content !== false && strlen($content) > 1000) {
                        @file_put_contents($gzPath, $content);
                        $downloaded = true;
                        break;
                    }
                }
                if (!$downloaded) {
                    return false;
                }
            }
        }

        $rawGz = @file_get_contents($gzPath);
        if (!$rawGz) return false;

        $uncompressed = @gzdecode($rawGz);
        if (!$uncompressed) return false;

        $catalog = json_decode($uncompressed, true);
        unset($uncompressed);
        if (!is_array($catalog)) return false;

        $dbPath = self::getDbPath();
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA synchronous = OFF;');
        $pdo->exec('PRAGMA journal_mode = MEMORY;');

        $pdo->exec('
            DROP TABLE IF EXISTS movies;
            DROP TABLE IF EXISTS series;

            CREATE TABLE movies (
                id INTEGER PRIMARY KEY,
                title TEXT,
                title_norm TEXT,
                year INTEGER,
                data_json TEXT
            );
            CREATE INDEX idx_movies_title ON movies(title_norm);
            CREATE INDEX idx_movies_year ON movies(year);

            CREATE TABLE series (
                id INTEGER PRIMARY KEY,
                title TEXT,
                title_norm TEXT,
                year INTEGER,
                data_json TEXT
            );
            CREATE INDEX idx_series_title ON series(title_norm);
            CREATE INDEX idx_series_year ON series(year);
        ');

        $stmtM = $pdo->prepare('INSERT OR REPLACE INTO movies (id, title, title_norm, year, data_json) VALUES (?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        foreach ($catalog['movies'] ?? [] as $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id <= 0) continue;
            $title = $m['nameFile'] ?? '';
            $year = !empty($m['date']) ? (int)substr($m['date'], 0, 4) : 0;
            $norm = self::normalizeTitle($title . ' ' . ($m['othersNames'] ?? ''));
            $stmtM->execute([$id, $title, $norm, $year, json_encode($m, JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();

        $stmtS = $pdo->prepare('INSERT OR REPLACE INTO series (id, title, title_norm, year, data_json) VALUES (?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        foreach ($catalog['series'] ?? [] as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id <= 0) continue;
            $title = $s['nameFile'] ?? '';
            $year = !empty($s['date']) ? (int)substr($s['date'], 0, 4) : 0;
            $norm = self::normalizeTitle($title . ' ' . ($s['othersNames'] ?? ''));
            $stmtS->execute([$id, $title, $norm, $year, json_encode($s, JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();

        return true;
    }

    /**
     * Consulta GitHub y descarga la versión más reciente del catálogo si existe una nueva
     */
    public static function checkAndDownloadLatestCatalog(bool $force = false): array
    {
        $versionFile = dirname(self::getDbPath()) . '/version.json';
        $currentInfo = file_exists($versionFile) ? json_decode((string)@file_get_contents($versionFile), true) : [];
        $lastChecked = $currentInfo['last_checked'] ?? 0;
        $currentTimestamp = (int)($currentInfo['snapshot_timestamp'] ?? 0);

        // Si no es forzado y revisamos hace menos de 24 horas, no consultar GitHub
        if (!$force && (time() - $lastChecked < 86400)) {
            return [
                'updated'   => false,
                'message'   => 'El catálogo ya está actualizado (última verificación hace menos de 24h)',
                'current'   => $currentInfo
            ];
        }

        $apiUrl = 'https://api.github.com/repos/dev-mplus01/mplus01T/contents/db/list';
        $ctx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Web2-FTP-MoviePlusUpdater/1.0\r\nAccept: application/json\r\n",
                'timeout' => 12
            ]
        ]);
        $json = @file_get_contents($apiUrl, false, $ctx);
        $latestFile = null;
        $maxTs = 0;

        if ($json !== false) {
            $files = json_decode($json, true);
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (preg_match('/items_up_r(\d+)\.json\.gz/i', $f['name'] ?? '', $m)) {
                        $ts = (int)$m[1];
                        if ($ts > $maxTs) {
                            $maxTs = $ts;
                            $latestFile = $f;
                        }
                    }
                }
            }
        }

        // Si la API de GitHub falló (por rate-limit sin token), usar mirror directo
        if (!$latestFile) {
            $latestFile = [
                'name'         => 'items_up.json.gz',
                'download_url' => 'https://raw.githubusercontent.com/dev-mplus01/mplus01T/main/db/items_up.json.gz'
            ];
            $maxTs = time();
        }

        $currentInfo['last_checked'] = time();

        if ($maxTs <= $currentTimestamp && !$force) {
            @file_put_contents($versionFile, json_encode($currentInfo, JSON_PRETTY_PRINT));
            return [
                'updated' => false,
                'message' => 'El catálogo ya cuenta con la versión más reciente (' . ($currentInfo['file_name'] ?? 'actual') . ')',
                'current' => $currentInfo
            ];
        }

        // Descargar nuevo snapshot
        $gzContent = @file_get_contents($latestFile['download_url'], false, stream_context_create([
            'http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 30]
        ]));

        if (!$gzContent || strlen($gzContent) < 1000) {
            @file_put_contents($versionFile, json_encode($currentInfo, JSON_PRETTY_PRINT));
            return [
                'updated' => false,
                'message' => 'No se pudo descargar la nueva versión desde el repositorio',
                'current' => $currentInfo
            ];
        }

        $gzPath = self::getGzCatalogPath();
        @file_put_contents($gzPath, $gzContent);

        // Reconstruir índice SQLite
        self::buildIndexFromGz();

        $db = self::getDb();
        $cntM = (int)($db->query('SELECT COUNT(*) as cnt FROM movies')->fetch()['cnt'] ?? 0);
        $cntS = (int)($db->query('SELECT COUNT(*) as cnt FROM series')->fetch()['cnt'] ?? 0);

        $newInfo = [
            'file_name'          => $latestFile['name'],
            'snapshot_timestamp' => $maxTs,
            'snapshot_date'      => date('Y-m-d H:i:s', (int)substr((string)$maxTs, 0, 10)),
            'last_checked'       => time(),
            'movies_count'       => $cntM,
            'series_count'       => $cntS,
            'file_size_mb'       => round(strlen($gzContent) / (1024 * 1024), 2)
        ];
        @file_put_contents($versionFile, json_encode($newInfo, JSON_PRETTY_PRINT));

        return [
            'updated' => true,
            'message' => "Catálogo actualizado con éxito a {$latestFile['name']} ({$cntM} películas, {$cntS} series)",
            'version' => $newInfo
        ];
    }

    /**
     * Búsqueda instantánea de película por TMDB ID (< 0.5ms)
     */
    public static function getMovieByTmdbId(int $tmdbId): ?array
    {
        if ($tmdbId <= 0) return null;
        try {
            $db = self::getDb();
            $stmt = $db->prepare('SELECT data_json FROM movies WHERE id = ? LIMIT 1');
            $stmt->execute([$tmdbId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['data_json'])) {
                $movie = json_decode($row['data_json'], true);
                if (is_array($movie)) {
                    $movie['decrypted_urls'] = self::decryptMovieUrls($movie['urls'] ?? []);
                    return $movie;
                }
            }
        } catch (\Throwable $e) {
            error_log('[MoviePlusService] Error getMovieByTmdbId: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Búsqueda instantánea de serie por TMDB ID (< 0.5ms)
     */
    public static function getSeriesByTmdbId(int $tmdbId): ?array
    {
        if ($tmdbId <= 0) return null;
        try {
            $db = self::getDb();
            $stmt = $db->prepare('SELECT data_json FROM series WHERE id = ? LIMIT 1');
            $stmt->execute([$tmdbId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['data_json'])) {
                $series = json_decode($row['data_json'], true);
                if (is_array($series)) {
                    return $series;
                }
            }
        } catch (\Throwable $e) {
            error_log('[MoviePlusService] Error getSeriesByTmdbId: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Búsqueda por título y año si no se tiene el TMDB ID
     */
    public static function searchMovieByTitle(string $title, ?string $year = null): ?array
    {
        $norm = self::normalizeTitle($title);
        if ($norm === '') return null;

        try {
            $db = self::getDb();
            $sql = 'SELECT data_json FROM movies WHERE title_norm LIKE ?';
            $params = ['%' . $norm . '%'];

            if ($year && (int)$year > 1900) {
                $sql .= ' AND year = ?';
                $params[] = (int)$year;
            }
            $sql .= ' LIMIT 1';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            if ($row && !empty($row['data_json'])) {
                $movie = json_decode($row['data_json'], true);
                if (is_array($movie)) {
                    $movie['decrypted_urls'] = self::decryptMovieUrls($movie['urls'] ?? []);
                    return $movie;
                }
            }
        } catch (\Throwable $e) {
            error_log('[MoviePlusService] Error searchMovieByTitle: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Búsqueda de series por título y año
     */
    public static function searchSeriesByTitle(string $title, ?string $year = null): ?array
    {
        $norm = self::normalizeTitle($title);
        if ($norm === '') return null;

        try {
            $db = self::getDb();
            $sql = 'SELECT data_json FROM series WHERE title_norm LIKE ?';
            $params = ['%' . $norm . '%'];

            if ($year && (int)$year > 1900) {
                $sql .= ' AND year = ?';
                $params[] = (int)$year;
            }
            $sql .= ' LIMIT 1';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            if ($row && !empty($row['data_json'])) {
                return json_decode($row['data_json'], true);
            }
        } catch (\Throwable $e) {
            error_log('[MoviePlusService] Error searchSeriesByTitle: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Normaliza información de audio/idioma
     */
    public static function parseLanguageInfo(string $str): array
    {
        $u = mb_strtoupper($str, 'UTF-8');

        if (preg_match('/\b(DUALC)\b/', $u)) {
            return [
                'code'  => 'DUALC',
                'name'  => 'Dual Castellano',
                'label' => 'Audio Dual (Castellano/Inglés)',
                'flag'  => '🇪🇸',
                'badge' => '🇪🇸 DUALC'
            ];
        }
        if (preg_match('/\b(TRIAL)\b/', $u)) {
            return [
                'code'  => 'TRIAL',
                'name'  => 'Multi Audio',
                'label' => 'Triple Audio (Latino + Castellano + Inglés)',
                'flag'  => '🌐',
                'badge' => '🌐 TRIAL'
            ];
        }
        if (preg_match('/\b(DUAL)\b/', $u)) {
            return [
                'code'  => 'DUAL',
                'name'  => 'Audio Dual',
                'label' => 'Audio Dual (Latino + Inglés)',
                'flag'  => '🎧',
                'badge' => '🎧 DUAL'
            ];
        }
        if (preg_match('/\b(CASTELLANO|CAST|ESP)\b/', $u)) {
            return [
                'code'  => 'CAST',
                'name'  => 'Castellano',
                'label' => 'Español de España (Castellano)',
                'flag'  => '🇪🇸',
                'badge' => '🇪🇸 CAST'
            ];
        }
        if (preg_match('/\b(SUBTITULADO|SUB|VOSE)\b/', $u)) {
            return [
                'code'  => 'SUB',
                'name'  => 'Subtitulado',
                'label' => 'Subtitulado al Español (VOSE)',
                'flag'  => '💬',
                'badge' => '💬 SUB'
            ];
        }
        if (preg_match('/\b(LATINO|LAT)\b/', $u)) {
            return [
                'code'  => 'LAT',
                'name'  => 'Latino',
                'label' => 'Español Latino',
                'flag'  => '🇲🇽',
                'badge' => '🇲🇽 LAT'
            ];
        }
        if (preg_match('/\b(INGLES|ENG|ENGLISH)\b/', $u)) {
            return [
                'code'  => 'ENG',
                'name'  => 'Inglés',
                'label' => 'Audio Inglés (Original)',
                'flag'  => '🇬🇧',
                'badge' => '🇬🇧 ENG'
            ];
        }
        if (preg_match('/\b(JAPONES|JAPON|JPN|JAP)\b/', $u)) {
            return [
                'code'  => 'JPN',
                'name'  => 'Japonés',
                'label' => 'Audio Japonés (Original)',
                'flag'  => '🇯🇵',
                'badge' => '🇯🇵 JPN'
            ];
        }

        return [
            'code'  => 'LAT',
            'name'  => 'Latino',
            'label' => 'Español Latino',
            'flag'  => '🇲🇽',
            'badge' => '🇲🇽 LAT'
        ];
    }

    /**
     * Limpia la cadena de calidad retirando etiquetas de idioma
     */
    public static function cleanQualityStr(string $raw): string
    {
        $cleaned = preg_replace('/\b(DUALC|TRIAL|DUAL|CASTELLANO|CAST|ESP|SUBTITULADO|SUB|VOSE|LATINO|LAT|INGLES|ENG|JAPONES|JPN|JAP)\b/i', '', $raw);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $trimmed = trim((string)$cleaned);
        return $trimmed !== '' ? $trimmed : 'HD';
    }

    /**
     * Descifra una cadena con AES-256-ECB
     */
    public static function decryptEcb(string $b64): string
    {
        $raw = base64_decode($b64);
        if ($raw === false || strlen($raw) < 16) {
            return $b64;
        }
        $dec = openssl_decrypt($raw, 'aes-256-ecb', self::AES_ECB_KEY, OPENSSL_RAW_DATA);
        if ($dec === false) {
            return $b64;
        }
        return trim($dec);
    }

    /**
     * Descifra una carga AES-256-GCM nativa (IV 12b + Ciphertext + Tag 16b)
     */
    public static function decryptGcm(string $b64): string
    {
        $raw = base64_decode($b64);
        if ($raw === false || strlen($raw) < 28) {
            return $b64;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ct = substr($raw, 12, -16);
        $dec = openssl_decrypt($ct, 'aes-256-gcm', self::AES_GCM_KEY, OPENSSL_RAW_DATA, $iv, $tag);
        if ($dec === false) {
            return $b64;
        }
        return trim($dec);
    }

    /**
     * Descifra una URL de Movie+ con doble capa (ECB -> GCM)
     */
    public static function decryptUrl(string $ciphertext): string
    {
        if (str_starts_with($ciphertext, 'http://') || str_starts_with($ciphertext, 'https://')) {
            return $ciphertext;
        }

        if (str_starts_with($ciphertext, 'Movp!::')) {
            $gcm = self::decryptGcm(substr($ciphertext, 7));
            if (str_starts_with($gcm, 'http')) {
                return $gcm;
            }
        }

        $dec = self::decryptEcb($ciphertext);
        if (str_starts_with($dec, 'Movp!::')) {
            $gcm = self::decryptGcm(substr($dec, 7));
            if (str_starts_with($gcm, 'http')) {
                return $gcm;
            }
        }

        if (strlen($dec) > 20 && str_ends_with($dec, '=') && !str_starts_with($dec, 'http')) {
            $second = self::decryptEcb($dec);
            if (str_starts_with($second, 'http')) {
                return $second;
            }
        }

        return $dec;
    }

    /**
     * Descifra la lista de URLs de una película
     */
    public static function decryptMovieUrls(array $urls): array
    {
        $result = [];
        foreach ($urls as $entry) {
            if (!is_string($entry)) continue;
            $parts = explode(',_,', $entry);
            if (count($parts) >= 3) {
                $rawQuality = trim($parts[0]);
                $size = trim($parts[1]);
                $cipher = trim($parts[2]);
                $plainUrl = self::decryptUrl($cipher);
                $provider = 'Direct';
                if (str_contains($plainUrl, 'yadi.sk')) $provider = 'Yandex Disk';
                elseif (str_contains($plainUrl, 'mega.nz')) $provider = 'Mega.nz';
                elseif (str_contains($plainUrl, 'google')) $provider = 'Google Drive';

                $lang = self::parseLanguageInfo($rawQuality);
                $cleanQ = self::cleanQualityStr($rawQuality);

                $result[] = [
                    'quality'       => $cleanQ,
                    'raw_quality'   => $rawQuality,
                    'language'      => $lang,
                    'size'          => $size,
                    'url'           => $plainUrl,
                    'provider'      => $provider,
                    'is_yandex'     => str_contains($plainUrl, 'yadi.sk')
                ];
            } else {
                $dec = self::decryptUrl($entry);
                $lang = self::parseLanguageInfo($entry);
                $result[] = [
                    'quality'       => 'HD',
                    'raw_quality'   => 'Standard',
                    'language'      => $lang,
                    'size'          => 'N/A',
                    'url'           => $dec,
                    'provider'      => str_contains($dec, 'yadi.sk') ? 'Yandex Disk' : (str_contains($dec, 'mega.nz') ? 'Mega.nz' : 'Direct'),
                    'is_yandex'     => str_contains($dec, 'yadi.sk')
                ];
            }
        }
        return $result;
    }

    /**
     * Parsea un string de episodio de serie
     */
    public static function parseEpisodeEntry(string $rawEntry): ?array
    {
        $clean = trim($rawEntry, " \t\n\r\0\x0B\"");
        $parts = explode('_SPLIT_', $clean);
        if (count($parts) < 2) {
            return null;
        }

        $title = trim($parts[0]);
        $encryptedUrl = trim($parts[1]);
        $bytes = isset($parts[2]) ? (float)trim($parts[2]) : 0;

        $sizeHuman = 'N/A';
        if ($bytes > 0) {
            if ($bytes >= 1024 * 1024 * 1024) {
                $sizeHuman = round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
            } elseif ($bytes >= 1024 * 1024) {
                $sizeHuman = round($bytes / (1024 * 1024), 1) . ' MB';
            } else {
                $sizeHuman = round($bytes / 1024) . ' KB';
            }
        }

        $decryptedUrl = self::decryptUrl($encryptedUrl);
        $provider = 'Direct';
        if (str_contains($decryptedUrl, 'yadi.sk')) $provider = 'Yandex Disk';
        elseif (str_contains($decryptedUrl, 'mega.nz')) $provider = 'Mega.nz';

        $lang = self::parseLanguageInfo($title);

        return [
            'name'       => $title,
            'url'        => $decryptedUrl,
            'size'       => $sizeHuman,
            'size_bytes' => (int)$bytes,
            'provider'   => $provider,
            'language'   => $lang,
            'is_yandex'  => str_contains($decryptedUrl, 'yadi.sk')
        ];
    }

    /**
     * Consulta Firestore o Cloudflare Worker de fallback para obtener el documento de una serie
     */
    public static function fetchSeriesFromRemote(int $seriesId): ?array
    {
        $cacheDir = self::getCacheDir();
        $cachePath = $cacheDir . "/series_{$seriesId}.json";

        // Caché local de 24 horas para documentos de serie
        if (file_exists($cachePath) && (time() - filemtime($cachePath) < 86400)) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached) && !empty($cached['fields'])) {
                return $cached;
            }
        }

        // 1. Consulta directa a Firebase Firestore REST API
        $apiKey = 'AIzaSyBgn47se6RS2ezDtTf4Jz58W2f8PhYO_4g';
        $firestoreUrl = "https://firestore.googleapis.com/v1/projects/dev-movieplus/databases/(default)/documents/tv/{$seriesId}?key={$apiKey}";
        $ctx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
                'timeout' => 8
            ]
        ]);
        $raw = @file_get_contents($firestoreUrl, false, $ctx);
        if ($raw !== false) {
            $doc = json_decode($raw, true);
            if (!empty($doc['fields'])) {
                @file_put_contents($cachePath, $raw);
                return $doc;
            }
        }

        // 2. Fallback firmado hacia Cloudflare Worker Proxy
        $workerSecret = 'G7!j2M8#qWzo5$uP9v1&bK4p3^Ym0S8';
        $workerBase = 'https://movp-firestore-proxy.developerxploit.workers.dev';

        $pingCtx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: MOVP-Android-FirestoreFallback/16.9\r\n" .
                             "X-MOVP-Signature: probe\r\n" .
                             "X-MOVP-Timestamp: 1\r\n" .
                             "X-MOVP-Nonce: probe_nonce\r\n" .
                             "X-MOVP-App-Version: 16.9\r\n",
                'timeout' => 5
            ]
        ]);
        $pingResp = @file_get_contents($workerBase . '/v1/doc/tv/' . $seriesId, false, $pingCtx);
        $serverTime = time();
        if ($pingResp !== false) {
            $pData = json_decode($pingResp, true);
            if (!empty($pData['serverTimeSeconds'])) {
                $serverTime = (int)$pData['serverTimeSeconds'];
            }
        }

        $endpoint = '/v1/doc/tv/' . $seriesId;
        $nonce = bin2hex(random_bytes(16));
        $toSign = "GET\n{$endpoint}\n{$serverTime}\n{$nonce}";
        $sig = hash_hmac('sha256', $toSign, $workerSecret);

        $authCtx = stream_context_create([
            'http' => [
                'header'  => "Accept: application/json\r\n" .
                             "User-Agent: MOVP-Android-FirestoreFallback/16.9\r\n" .
                             "X-MOVP-Timestamp: {$serverTime}\r\n" .
                             "X-MOVP-Nonce: {$nonce}\r\n" .
                             "X-MOVP-Signature: {$sig}\r\n" .
                             "X-MOVP-App-Version: 16.9\r\n",
                'timeout' => 8
            ]
        ]);
        $workerResp = @file_get_contents($workerBase . $endpoint, false, $authCtx);
        if ($workerResp !== false) {
            $doc = json_decode($workerResp, true);
            if (!empty($doc['fields'])) {
                @file_put_contents($cachePath, $workerResp);
                return $doc;
            }
        }

        return null;
    }

    /**
     * Parsea el documento de Firestore y estructura temporadas, calidades y episodios
     */
    public static function parseSeriesSeasons(array $doc): array
    {
        $fields = $doc['fields'] ?? [];
        $seasons = [];

        foreach ($fields as $key => $fieldData) {
            if (!str_starts_with($key, 'Temporada') && !str_starts_with($key, 'Especial')) {
                continue;
            }

            $parts = explode('/', $key, 2);
            $seasonName = trim($parts[0]);
            $quality = isset($parts[1]) ? trim($parts[1]) : 'Standard';

            $values = $fieldData['arrayValue']['values'] ?? [];
            if (!is_array($values) || empty($values)) {
                continue;
            }

            if (!isset($seasons[$seasonName])) {
                $seasons[$seasonName] = [];
            }
            if (!isset($seasons[$seasonName][$quality])) {
                $seasons[$seasonName][$quality] = [];
            }

            foreach ($values as $val) {
                $str = $val['stringValue'] ?? null;
                if (!$str) continue;
                $parsed = self::parseEpisodeEntry($str);
                if ($parsed !== null) {
                    $seasons[$seasonName][$quality][] = $parsed;
                }
            }
        }

        uksort($seasons, 'strnatcasecmp');

        foreach ($seasons as $sName => &$qualities) {
            uksort($qualities, function($a, $b) {
                $priority = ['4K' => 1, '1080p' => 2, '720p' => 3, 'HD' => 4, 'Standard' => 5];
                $pa = $priority[$a] ?? 99;
                $pb = $priority[$b] ?? 99;
                return $pa <=> $pb;
            });

            foreach ($qualities as $q => &$episodes) {
                usort($episodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            }
        }
        unset($qualities);

        return $seasons;
    }

    /**
     * Resuelve un enlace de Yandex Disk a su URL de descarga directa con caché en memoria/disco
     */
    public static function resolveYandexDisk(string $yandexUrl): ?array
    {
        if (!str_contains($yandexUrl, 'yadi.sk')) {
            return null;
        }

        // Cache de resolución por 30 minutos
        $cacheFile = self::getCacheDir() . '/yandex_' . md5($yandexUrl) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 1800)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['download_url'])) {
                return $cached;
            }
        }

        $apiUrl = 'https://cloud-api.yandex.net/v1/disk/public/resources/download?public_key=' . urlencode($yandexUrl);
        $ctx = stream_context_create([
            'http' => [
                'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: application/json\r\n",
                'timeout'       => 8,
                'ignore_errors' => true
            ]
        ]);

        $resp = @file_get_contents($apiUrl, false, $ctx);
        if ($resp === false) return null;

        $data = json_decode($resp, true);
        if (!empty($data['href'])) {
            $parts = parse_url($data['href']);
            parse_str($parts['query'] ?? '', $queryParams);

            $result = [
                'success'      => true,
                'download_url' => $data['href'],
                'filename'     => $queryParams['filename'] ?? 'video.mp4',
                'size_bytes'   => (int)($queryParams['fsize'] ?? 0),
                'size_human'   => isset($queryParams['fsize']) ? round($queryParams['fsize'] / (1024 * 1024 * 1024), 2) . ' GB' : 'N/A',
                'content_type' => $queryParams['content_type'] ?? 'video/mp4'
            ];
            @file_put_contents($cacheFile, json_encode($result));
            return $result;
        }

        return null;
    }
}
