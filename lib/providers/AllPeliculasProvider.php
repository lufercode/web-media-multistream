<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class AllPeliculasProvider implements ProviderInterface
{
    private string $host = 'https://allpeliculas.la';

    public function getId(): string
    {
        return 'allpeliculas';
    }

    public function getName(): string
    {
        return 'AllPeliculas (Películas y Series HD)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['allpeliculas']['enabled'] ?? true;
    }

    private function normalizeString(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        $str = preg_replace('/[^a-z0-9]/', '', $str);
        return trim($str);
    }

    private function formatServer(string $server, string $url): string
    {
        $lowerUrl = strtolower($url);
        if (stripos($lowerUrl, 'voe.sx') !== false || stripos($lowerUrl, 'voe-un-block') !== false) {
            return 'Voe';
        }
        if (stripos($lowerUrl, 'hlswish') !== false || stripos($lowerUrl, 'streamwish') !== false || stripos($lowerUrl, 'swish') !== false) {
            return 'StreamWish';
        }
        if (stripos($lowerUrl, 'filemoon') !== false) {
            return 'Filemoon';
        }
        if (stripos($lowerUrl, 'goodstream') !== false) {
            return 'GoodStream';
        }
        if (stripos($lowerUrl, 'vimeos') !== false) {
            return 'Vimeos';
        }
        if (stripos($lowerUrl, 'videoapp') !== false) {
            return 'VideoApp';
        }
        if (stripos($lowerUrl, 'la.movie') !== false) {
            return 'LaMovie';
        }
        if (stripos($lowerUrl, 'dood') !== false) {
            return 'Doodstream';
        }
        if (stripos($lowerUrl, 'streamtape') !== false) {
            return 'Streamtape';
        }
        if (stripos($lowerUrl, 'vidhide') !== false) {
            return 'VidHide';
        }

        $s = trim($server);
        if (empty($s) || strtolower($s) === 'online') {
            return 'Online Stream';
        }
        return ucfirst($s);
    }

    private function formatLang(string $rawLang): array
    {
        $l = mb_strtolower($rawLang, 'UTF-8');
        if (strpos($l, 'castellano') !== false || strpos($l, 'esp') !== false) {
            return ['Castellano', 'cast'];
        }
        if (strpos($l, 'sub') !== false || strpos($l, 'vose') !== false) {
            return ['Subtitulado', 'sub'];
        }
        if (strpos($l, 'latino') !== false || strpos($l, 'lat') !== false) {
            return ['Latino', 'lat'];
        }
        if (strpos($l, 'ingl') !== false || strpos($l, 'en') !== false) {
            return ['Inglés', 'en'];
        }
        return ['Latino', 'lat'];
    }

    private function formatQuality(string $rawQ): string
    {
        $q = strtolower(trim($rawQ));
        if (strpos($q, '1080') !== false || strpos($q, 'full') !== false) {
            return '1080p';
        }
        if (strpos($q, '720') !== false) {
            return '720p';
        }
        if (strpos($q, '4k') !== false) {
            return '4K';
        }
        if (strpos($q, 'cam') !== false) {
            return 'CAM';
        }
        return 'HD';
    }

    private function parseEmbeds(array $embeds, string $title): array
    {
        $sources = [];
        foreach ($embeds as $emb) {
            $url = $emb['url'] ?? '';
            if (empty($url)) continue;

            // Descartar servidores de descarga o torrents rotos de acortalink
            if (preg_match('#/(?:1fichier|turbobit|fembed|fireload|cloudemb|tubesb|sbsonic|acortalink)\.#i', $url)) {
                continue;
            }

            $server = $this->formatServer($emb['server'] ?? '', $url);
            [$language, $langCode] = $this->formatLang($emb['lang'] ?? '');
            $quality = $this->formatQuality($emb['quality'] ?? '');

            $sources[] = [
                'provider' => $this->getId(),
                'provider_name' => $this->getName(),
                'title' => $title,
                'server' => $server,
                'language' => $language,
                'lang' => $langCode,
                'quality' => $quality,
                'type' => 'streaming',
                'url' => $url,
                'size' => null
            ];
        }
        return $sources;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        $searchUrl = $this->host . '/wp-api/v1/search?filter=[]&q=' . urlencode($title) . '&orderBy=latest&order=desc&postType=movies&postsPerPage=15&page=1';

        $res = http_get($searchUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$res) return [];

        $data = json_decode($res, true);
        $posts = $data['data']['posts'] ?? [];
        if (empty($posts)) return [];

        $targetNorm = $this->normalizeString($title);
        $candidateId = null;
        $matchedTitle = $title;

        foreach ($posts as $post) {
            $pTitle = $post['title'] ?? '';
            $pRelease = $post['release_date'] ?? '';
            $pYear = !empty($pRelease) ? substr($pRelease, 0, 4) : null;

            $cleanTitle = preg_replace('/\s*\(\d{4}\).*$/', '', $pTitle);
            $cleanTitle = preg_replace('/\[.*?\]/', '', $cleanTitle);
            $pNorm = $this->normalizeString($cleanTitle);

            $isMatch = false;
            if ($pNorm === $targetNorm || strpos($pNorm, $targetNorm) !== false || strpos($targetNorm, $pNorm) !== false) {
                $isMatch = true;
            } else {
                similar_text($pNorm, $targetNorm, $percent);
                if ($percent >= 70) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                if ($year && $pYear && abs((int)$year - (int)$pYear) > 1) {
                    continue;
                }
                $candidateId = $post['_id'] ?? null;
                $matchedTitle = $cleanTitle;
                break;
            }
        }

        // Si no hubo match estricto pero hay un post principal
        if (!$candidateId && !empty($posts)) {
            $candidateId = $posts[0]['_id'] ?? null;
            $matchedTitle = $posts[0]['title'] ?? $title;
        }

        if (!$candidateId) return [];

        $playerUrl = $this->host . '/wp-api/v1/player?postId=' . $candidateId . '&demo=0';
        $pRes = http_get($playerUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$pRes) return [];

        $pData = json_decode($pRes, true);
        $embeds = $pData['data']['embeds'] ?? [];

        return $this->parseEmbeds($embeds, $matchedTitle);
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        $searchUrl = $this->host . '/wp-api/v1/search?filter=[]&q=' . urlencode($title) . '&orderBy=latest&order=desc&postType=tvshows&postsPerPage=15&page=1';

        $res = http_get($searchUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$res) return [];

        $data = json_decode($res, true);
        $posts = $data['data']['posts'] ?? [];
        if (empty($posts)) return [];

        $targetNorm = $this->normalizeString($title);
        $candidateSeriesId = null;
        $matchedTitle = $title;

        foreach ($posts as $post) {
            $pTitle = $post['title'] ?? '';
            $cleanTitle = preg_replace('/\s*\(\d{4}\).*$/', '', $pTitle);
            $pNorm = $this->normalizeString($cleanTitle);

            if ($pNorm === $targetNorm || strpos($pNorm, $targetNorm) !== false || strpos($targetNorm, $pNorm) !== false) {
                $candidateSeriesId = $post['_id'] ?? null;
                $matchedTitle = $cleanTitle;
                break;
            } else {
                similar_text($pNorm, $targetNorm, $percent);
                if ($percent >= 70) {
                    $candidateSeriesId = $post['_id'] ?? null;
                    $matchedTitle = $cleanTitle;
                    break;
                }
            }
        }

        if (!$candidateSeriesId && !empty($posts)) {
            $candidateSeriesId = $posts[0]['_id'] ?? null;
            $matchedTitle = $posts[0]['title'] ?? $title;
        }

        if (!$candidateSeriesId) return [];

        // Obtener episodios de la temporada requerida
        $episodesUrl = $this->host . '/wp-api/v1/single/episodes/list?_id=' . $candidateSeriesId . '&season=' . $season . '&postsPerPage=50&page=1';
        $epRes = http_get($episodesUrl, [
            'timeout' => 10,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$epRes) return [];

        $epData = json_decode($epRes, true);
        $episodes = $epData['data']['posts'] ?? [];
        if (empty($episodes)) return [];

        $targetEpId = null;
        $epTitle = $matchedTitle;

        foreach ($episodes as $ep) {
            $epNum = (int)($ep['episode_number'] ?? 0);
            $sNum = (int)($ep['season_number'] ?? 0);

            if ($epNum === $episode && ($sNum === $season || $sNum === 0)) {
                $targetEpId = $ep['_id'] ?? null;
                $epTitle = $ep['title'] ?? $matchedTitle;
                break;
            }
        }

        if (!$targetEpId) return [];

        // Obtener reproductores para el episodio
        $playerUrl = $this->host . '/wp-api/v1/player?postId=' . $targetEpId . '&demo=0';
        $pRes = http_get($playerUrl, [
            'timeout' => 8,
            'headers' => [
                'Referer' => $this->host . '/',
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$pRes) return [];

        $pData = json_decode($pRes, true);
        $embeds = $pData['data']['embeds'] ?? [];

        return $this->parseEmbeds($embeds, $epTitle);
    }
}

