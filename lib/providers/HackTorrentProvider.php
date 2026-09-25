<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class HackTorrentProvider implements ProviderInterface
{
    private string $host = 'https://hacktorrent.cc';

    public function getId(): string
    {
        return 'hacktorrent';
    }

    public function getName(): string
    {
        return 'HackTorrent (Torrents y Streaming HD)';
    }

    public function getType(): string
    {
        return 'mixed';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['hacktorrent']['enabled'] ?? true;
    }

    private function normalizeString(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        $str = preg_replace('/[^a-z0-9]/', '', $str);
        return trim($str);
    }

    private function mapServerName(string $url): string
    {
        $lower = strtolower($url);
        if (strpos($lower, 'voe') !== false) return 'Voe';
        if (strpos($lower, 'streamwish') !== false || strpos($lower, 'hlswish') !== false || strpos($lower, 'wish') !== false) return 'StreamWish';
        if (strpos($lower, 'filemoon') !== false) return 'Filemoon';
        if (strpos($lower, 'goodstream') !== false) return 'GoodStream';
        if (strpos($lower, 'vimeos') !== false) return 'Vimeos';
        if (strpos($lower, 'videoapp') !== false) return 'VideoApp';
        if (strpos($lower, 'dood') !== false) return 'Doodstream';
        if (strpos($lower, 'streamtape') !== false) return 'Streamtape';
        if (strpos($lower, 'vidhide') !== false) return 'VidHide';
        return 'Online Stream';
    }

    private function formatQuality(string $rawQ): string
    {
        $q = strtolower(trim($rawQ));
        if (strpos($q, '4k') !== false || strpos($q, '2160') !== false) return '4K UHD';
        if (strpos($q, '1080') !== false || strpos($q, 'full') !== false) return '1080p Full HD';
        if (strpos($q, '720') !== false) return 'HD 720p';
        if (strpos($q, 'dvd') !== false) return 'DVDRip';
        if (strpos($q, 'cam') !== false) return 'CAM';
        return !empty($rawQ) ? $rawQ : 'HD';
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        $sources = [];
        $searchUrl = $this->host . '/wp-json/wpreact/v1/search?query=' . urlencode($title) . '&posts_per_page=20&page=1';

        $searchJson = http_get($searchUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$searchJson) return [];

        $data = json_decode($searchJson, true);
        $results = $data['results'] ?? [];
        if (empty($results)) return [];

        $targetSlug = null;
        $targetTitle = $title;
        $targetNorm = $this->normalizeString($title);

        foreach ($results as $item) {
            $mType = $item['type'] ?? '';
            if ($mType !== 'pelicula' && $mType !== 'movie') continue;

            $mTmdb = isset($item['tmdb_id']) ? (int)$item['tmdb_id'] : null;
            if ($tmdb_id !== null && $mTmdb !== null && $tmdb_id === $mTmdb) {
                $targetSlug = $item['slug'] ?? null;
                $targetTitle = $item['title'] ?? $title;
                break;
            }

            $mTitle = $item['title'] ?? '';
            $itemYear = isset($item['years']) ? substr($item['years'], 0, 4) : (isset($item['year']) ? substr($item['year'], 0, 4) : null);

            if (is_strict_title_match($title, $mTitle, $year, $itemYear)) {
                $targetSlug = $item['slug'] ?? null;
                $targetTitle = $mTitle;
                break;
            }
        }

        if (!$targetSlug) return [];

        $detailUrl = $this->host . '/wp-json/wpreact/v1/movie/' . $targetSlug;
        $detailJson = http_get($detailUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/pelicula/' . $targetSlug . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$detailJson) return [];

        $movieData = json_decode($detailJson, true);
        $downloads = $movieData['downloads'] ?? [];

        foreach ($downloads as $dl) {
            $link = $dl['download_link'] ?? '';
            if (!$link || strpos($link, 'magnet:') !== 0) continue;

            $rawQ = $dl['quality'] ?? 'HD';
            $quality = $this->formatQuality($rawQ);
            $server = (stripos($rawQ, '4k') !== false || stripos($rawQ, '2160') !== false) 
                ? 'BitTorrent (4K UHD)' 
                : 'BitTorrent Magnet';

            $lang = $dl['language'] ?? 'Latino';
            $langCode = 'lat';
            if (is_latino_audio($lang)) {
                $langCode = 'lat';
            } elseif (stripos($lang, 'castellano') !== false || stripos($lang, 'esp') !== false || stripos($lang, 'spa') !== false) {
                $langCode = 'cast';
            } elseif (stripos($lang, 'sub') !== false || stripos($lang, 'vose') !== false) {
                $langCode = 'sub';
            }

            $sources[] = [
                'provider' => $this->getId(),
                'provider_name' => 'HackTorrent (Torrent)',
                'type' => 'torrent',
                'title' => $movieData['title'] ?? $targetTitle,
                'server' => $server,
                'quality' => $quality,
                'language' => $lang,
                'lang' => $langCode,
                'url' => $link,
                'size' => $dl['size'] ?? null
            ];
        }

        return $sources;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        $sources = [];
        $searchUrl = $this->host . '/wp-json/wpreact/v1/search?query=' . urlencode($title) . '&posts_per_page=20&page=1';

        $searchJson = http_get($searchUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$searchJson) return [];

        $data = json_decode($searchJson, true);
        $results = $data['results'] ?? [];
        if (empty($results)) return [];

        $targetSlug = null;
        $targetTitle = $title;
        $targetNorm = $this->normalizeString($title);

        foreach ($results as $item) {
            $mType = $item['type'] ?? '';
            if ($mType !== 'serie' && $mType !== 'tv') continue;

            $mTmdb = isset($item['tmdb_id']) ? (int)$item['tmdb_id'] : null;
            if ($tmdb_id !== null && $mTmdb !== null && $tmdb_id === $mTmdb) {
                $targetSlug = $item['slug'] ?? null;
                $targetTitle = $item['title'] ?? $title;
                break;
            }

            $mTitle = $item['title'] ?? '';
            $mNorm = $this->normalizeString($mTitle);

            if ($mNorm === $targetNorm || strpos($mNorm, $targetNorm) !== false || strpos($targetNorm, $mNorm) !== false) {
                $targetSlug = $item['slug'] ?? null;
                $targetTitle = $mTitle;
                break;
            }
        }

        if (!$targetSlug) return [];

        $detailUrl = $this->host . '/wp-json/wpreact/v1/serie/' . $targetSlug . '/related/';
        $detailJson = http_get($detailUrl, [
            'timeout' => 10,
            'headers' => [
                'Referer' => $this->host . '/serie/' . $targetSlug . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$detailJson) return [];

        $seriesData = json_decode($detailJson, true);

        // 1. Fuentes de Streaming
        $embeds = $seriesData['embeds'] ?? [];
        foreach ($embeds as $emb) {
            $sNum = (int)($emb['season'] ?? 0);
            $eNum = (int)($emb['episode'] ?? 0);

            if ($sNum === $season && $eNum === $episode) {
                $url = $emb['url'] ?? '';
                if (!$url) continue;

                $server = $this->mapServerName($url);
                $quality = $this->formatQuality($emb['quality'] ?? 'Full HD');
                $lang = $emb['lang'] ?? 'Latino';
                $langCode = 'lat';
                if (is_latino_audio($lang)) {
                    $langCode = 'lat';
                } elseif (stripos($lang, 'castellano') !== false || stripos($lang, 'esp') !== false || stripos($lang, 'spa') !== false) {
                    $langCode = 'cast';
                } elseif (stripos($lang, 'sub') !== false || stripos($lang, 'vose') !== false) {
                    $langCode = 'sub';
                }

                $sources[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'HackTorrent (Streaming)',
                    'type' => 'streaming',
                    'title' => sprintf("%s S%02dE%02d", $targetTitle, $season, $episode),
                    'server' => $server,
                    'quality' => $quality,
                    'language' => $lang,
                    'lang' => $langCode,
                    'url' => $url,
                    'size' => null
                ];
            }
        }

        // 2. Fuentes de Torrents/Descargas
        $downloads = $seriesData['downloads'] ?? [];
        foreach ($downloads as $dl) {
            $sNum = (int)($dl['season'] ?? 0);
            $eNum = (int)($dl['episode'] ?? 0);

            if ($sNum === $season && $eNum === $episode) {
                $link = $dl['download_link'] ?? '';
                if (!$link || strpos($link, 'magnet:') !== 0) continue;

                $quality = $this->formatQuality($dl['quality'] ?? '1080p Full HD');
                $lang = $dl['language'] ?? 'Latino';
                $langCode = 'lat';
                if (is_latino_audio($lang)) {
                    $langCode = 'lat';
                } elseif (stripos($lang, 'castellano') !== false || stripos($lang, 'esp') !== false || stripos($lang, 'spa') !== false) {
                    $langCode = 'cast';
                } elseif (stripos($lang, 'sub') !== false || stripos($lang, 'vose') !== false) {
                    $langCode = 'sub';
                }

                $sources[] = [
                    'provider' => $this->getId(),
                    'provider_name' => 'HackTorrent (Torrent)',
                    'type' => 'torrent',
                    'title' => sprintf("%s S%02dE%02d", $targetTitle, $season, $episode),
                    'server' => 'BitTorrent Magnet',
                    'quality' => $quality,
                    'language' => $lang,
                    'lang' => $langCode,
                    'url' => $link,
                    'size' => $dl['size'] ?? null
                ];
            }
        }

        return $sources;
    }
}

