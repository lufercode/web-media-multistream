<?php
require_once __DIR__ . '/ProviderInterface.php';

class LocalCDNProvider implements ProviderInterface
{
    private array $moviePatterns = [
        '{name_formatted}.{year}.mp4',
        '{name_formatted}.{year}.mkv',
        '{name_formatted}.mp4',
        '{name_formatted}.mkv',
        '{name_formatted}_{year}.mp4',
        '{name_formatted}_{year}.mkv'
    ];

    private array $seriesPatterns = [
        '{name_formatted}_S{season_padded}E{episode_padded}.mp4',
        '{name_formatted}_S{season_padded}E{episode_padded}.mkv',
        '{name_formatted}.S{season_padded}E{episode_padded}.mp4',
        '{name_formatted}.S{season_padded}E{episode_padded}.mkv',
        '{name_formatted}-S{season_padded}E{episode_padded}.mp4',
        '{name_formatted}-S{season_padded}E{episode_padded}.mkv'
    ];

    public function getId(): string
    {
        return 'local_cdn';
    }

    public function getName(): string
    {
        return 'CDN Propio (Rakun)';
    }

    public function getType(): string
    {
        return 'direct';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        if (isset($PROVIDERS_CONFIG['local_cdn']['enabled'])) {
            return (bool)$PROVIDERS_CONFIG['local_cdn']['enabled'];
        }
        return true;
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $accessible_domains = check_base_domains_accessible();
        if (empty($accessible_domains)) {
            return [];
        }

        $normalized_titles = array_unique([
            format_query($title),
            strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title)),
            str_replace(' ', '.', strtolower($title)),
            str_replace(' ', '_', strtolower($title))
        ]);

        $results = [];
        $candidate_urls = [];

        foreach ($accessible_domains as $domain) {
            foreach ($normalized_titles as $t) {
                foreach ($this->moviePatterns as $pattern) {
                    $file = str_replace(['{name_formatted}', '{year}'], [$t, $year ?? ''], $pattern);
                    $candidate_urls[] = rtrim($domain, '/') . "/vies/" . rawurlencode($file);
                }
            }
        }

        $valid_urls = urls_exist_multi($candidate_urls, 2);
        foreach ($valid_urls as $url) {
            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => $this->getName(),
                'type' => 'direct',
                'title' => $title . ($year ? " ($year)" : ""),
                'server' => 'CDN Propio',
                'quality' => 'HD / Original',
                'language' => 'Latino / Original',
                'url' => $url,
                'size' => null
            ];
        }

        return $results;
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $accessible_domains = check_base_domains_accessible();
        if (empty($accessible_domains)) {
            return [];
        }

        $s_padded = str_pad((string)$season, 2, '0', STR_PAD_LEFT);
        $e_padded = str_pad((string)$episode, 2, '0', STR_PAD_LEFT);

        $normalized_titles = array_unique([
            format_query($title),
            strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title)),
            str_replace(' ', '.', strtolower($title)),
            str_replace(' ', '_', strtolower($title))
        ]);

        $results = [];
        $candidate_urls = [];

        foreach ($accessible_domains as $domain) {
            foreach ($normalized_titles as $t) {
                $base_folder = rtrim($domain, '/') . "/sho/" . $t . "/s{$season}/";
                foreach ($this->seriesPatterns as $pattern) {
                    $filename = str_replace(['{name_formatted}', '{season_padded}', '{episode_padded}'], [$t, $s_padded, $e_padded], $pattern);
                    $candidate_urls[] = $base_folder . rawurlencode($filename);
                }
            }
        }

        $valid_urls = urls_exist_multi($candidate_urls, 2);
        foreach ($valid_urls as $url) {
            $results[] = [
                'provider' => $this->getId(),
                'provider_name' => $this->getName(),
                'type' => 'direct',
                'title' => "{$title} S{$s_padded}E{$e_padded}",
                'server' => 'CDN Propio',
                'quality' => 'HD / Original',
                'language' => 'Latino / Original',
                'url' => $url,
                'size' => null
            ];
        }

        return $results;
    }
}

