<?php
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../RakunService.php';

class LocalCDNProvider implements ProviderInterface
{
    public function getId(): string
    {
        return 'local_cdn';
    }

    public function getName(): string
    {
        return 'Rakun (Cloud CDN)';
    }

    public function getType(): string
    {
        return 'streaming';
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

        try {
            $streams = RakunService::searchMovie($title, $year, $tmdb_id);
            if (empty($streams)) {
                return [];
            }

            $results = [];
            $itemTitle = $title . ($year ? " ({$year})" : '');

            foreach ($streams as $s) {
                // 1. Streaming para reproductor online
                $results[] = [
                    'provider'      => $this->getId(),
                    'provider_name' => 'Rakun',
                    'type'          => 'streaming',
                    'title'         => $itemTitle,
                    'server'        => $s['server'] ?? 'Rakun Cloud CDN',
                    'quality'       => $s['quality'] ?? '1080p FHD',
                    'language'      => $s['language'] ?? 'Latino',
                    'url'           => $s['url'],
                    'size'          => null
                ];

                // 2. Descarga directa en "Servidor Propio (Descarga / Dispositivo)"
                $isVip = $s['is_vip'] ?? false;
                $serverLabel = $isVip ? 'Bunny VIP' : 'Cloud CDN';
                $results[] = [
                    'provider'      => $this->getId(),
                    'provider_name' => 'Rakun',
                    'type'          => 'direct',
                    'title'         => $itemTitle,
                    'server'        => $serverLabel,
                    'quality'       => $s['quality'] ?? '1080p FHD',
                    'language'      => $s['language'] ?? 'Latino',
                    'url'           => $s['url'],
                    'size'          => null
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            error_log("[LocalCDNProvider] Error searching movie: " . $e->getMessage());
            return [];
        }
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        try {
            $streams = RakunService::searchSeries($title, $season, $episode, $tmdb_id);
            if (empty($streams)) {
                return [];
            }

            $s_padded = str_pad((string)$season, 2, '0', STR_PAD_LEFT);
            $e_padded = str_pad((string)$episode, 2, '0', STR_PAD_LEFT);
            $itemTitle = "{$title} S{$s_padded}E{$e_padded}";

            $results = [];
            foreach ($streams as $s) {
                // 1. Streaming para reproductor online
                $results[] = [
                    'provider'      => $this->getId(),
                    'provider_name' => 'Rakun',
                    'type'          => 'streaming',
                    'title'         => $itemTitle,
                    'server'        => $s['server'] ?? 'Rakun Cloud CDN',
                    'quality'       => $s['quality'] ?? '1080p FHD',
                    'language'      => $s['language'] ?? 'Latino',
                    'url'           => $s['url'],
                    'size'          => null
                ];

                // 2. Descarga directa en "Servidor Propio (Descarga / Dispositivo)"
                $isVip = $s['is_vip'] ?? false;
                $serverLabel = $isVip ? 'Bunny VIP' : 'Cloud CDN';
                $results[] = [
                    'provider'      => $this->getId(),
                    'provider_name' => 'Rakun',
                    'type'          => 'direct',
                    'title'         => $itemTitle,
                    'server'        => $serverLabel,
                    'quality'       => $s['quality'] ?? '1080p FHD',
                    'language'      => $s['language'] ?? 'Latino',
                    'url'           => $s['url'],
                    'size'          => null
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            error_log("[LocalCDNProvider] Error searching series: " . $e->getMessage());
            return [];
        }
    }
}
