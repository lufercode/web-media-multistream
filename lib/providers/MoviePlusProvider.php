<?php
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../MoviePlusService.php';

class MoviePlusProvider implements ProviderInterface
{
    private string $id = 'movieplus';
    private string $name = 'Movie+ (Cloud CDN)';

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG[$this->id]['enabled'])) {
            return (bool)$PROVIDERS_CONFIG[$this->id]['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $movie = null;

        // 1. Búsqueda instantánea y exacta por TMDB ID (< 0.5 ms)
        if ($tmdb_id !== null && $tmdb_id > 0) {
            $movie = MoviePlusService::getMovieByTmdbId($tmdb_id);
        }

        // 2. Respaldo por título y año si no se encontró por ID
        if (!$movie) {
            $movie = MoviePlusService::searchMovieByTitle($title, $year);
        }

        if (!$movie || empty($movie['decrypted_urls'])) {
            return [];
        }

        $results = [];
        $itemTitle = $title . ($year ? " ($year)" : "");

        foreach ($movie['decrypted_urls'] as $u) {
            $quality = $u['quality'] ?? 'HD';
            $langInfo = $u['language'] ?? ['name' => 'Latino'];
            $langName = $langInfo['name'] ?? 'Latino';
            $url = $u['url'] ?? '';

            if (empty($url)) continue;

            $serverName = 'Yandex CDN ' . $quality;
            if (str_contains($url, 'mega.nz')) {
                $serverName = 'Mega.nz ' . $quality;
            } elseif (str_contains($url, 'google')) {
                $serverName = 'Google Drive ' . $quality;
            }

            $size = !empty($u['size']) && $u['size'] !== 'N/A' ? $u['size'] : null;

            $results[] = [
                'provider'      => $this->getId(),
                'provider_name' => $this->getName(),
                'type'          => 'streaming',
                'title'         => $itemTitle,
                'server'        => $serverName,
                'quality'       => $quality,
                'language'      => $langName,
                'url'           => $url,
                'size'          => $size
            ];
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $serie = null;

        // 1. Búsqueda por TMDB ID
        if ($tmdb_id !== null && $tmdb_id > 0) {
            $serie = MoviePlusService::getSeriesByTmdbId($tmdb_id);
        }

        // 2. Respaldo por título
        if (!$serie) {
            $serie = MoviePlusService::searchSeriesByTitle($title);
        }

        if (!$serie || empty($serie['id'])) {
            return [];
        }

        $seriesId = (int)$serie['id'];
        $remoteDoc = MoviePlusService::fetchSeriesFromRemote($seriesId);
        if (!$remoteDoc) {
            return [];
        }

        $seasons = MoviePlusService::parseSeriesSeasons($remoteDoc);
        if (empty($seasons)) {
            return [];
        }

        // Buscar temporada solicitada (ej. "Temporada 01", "Temporada 1")
        $targetSeasonKey = null;
        foreach (array_keys($seasons) as $sName) {
            if (preg_match('/\b(?:Temporada|Season)\s*0?' . $season . '\b/i', $sName)) {
                $targetSeasonKey = $sName;
                break;
            }
        }

        if (!$targetSeasonKey || empty($seasons[$targetSeasonKey])) {
            return [];
        }

        $seasonQualities = $seasons[$targetSeasonKey];
        $results = [];
        $epTitle = sprintf('%s S%02dE%02d', $title, $season, $episode);

        // Patrón para identificar el episodio en el nombre del archivo
        $epPattern = '/(?:S\d{1,2}E0?' . $episode . '|E0?' . $episode . '|Cap[ií]tulo\s*0?' . $episode . ')\b/i';

        foreach ($seasonQualities as $quality => $episodes) {
            foreach ($episodes as $ep) {
                $epName = $ep['name'] ?? '';
                if (preg_match($epPattern, $epName)) {
                    $url = $ep['url'] ?? '';
                    if (empty($url)) continue;

                    $langInfo = $ep['language'] ?? ['name' => 'Latino'];
                    $langName = $langInfo['name'] ?? 'Latino';

                    $serverName = 'Yandex CDN ' . $quality;
                    if (str_contains($url, 'mega.nz')) {
                        $serverName = 'Mega.nz ' . $quality;
                    }

                    $results[] = [
                        'provider'      => $this->getId(),
                        'provider_name' => $this->getName(),
                        'type'          => 'streaming',
                        'title'         => $epTitle,
                        'server'        => $serverName,
                        'quality'       => $quality,
                        'language'      => $langName,
                        'url'           => $url,
                        'size'          => !empty($ep['size']) && $ep['size'] !== 'N/A' ? $ep['size'] : null
                    ];
                }
            }
        }

        return $results;
    }
}

