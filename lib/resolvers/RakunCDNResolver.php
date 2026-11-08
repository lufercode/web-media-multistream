<?php
declare(strict_types=1);

require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../RakunService.php';

class RakunCDNResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'rakun_cdn';
    }

    public function canResolve(string $url): bool
    {
        if (str_contains($url, 'api/rakun.php?action=stream')) {
            return false;
        }

        return (
            str_contains($url, 'rakun.b-cdn.net') ||
            str_contains($url, 'cdn.rakun.lat') ||
            str_contains($url, 'rakun.lat') ||
            str_contains($url, 'rakun.site') ||
            str_contains($url, 'rakun-cloud')
        );
    }

    public function resolve(string $url): ?array
    {
        $signedUrl = RakunService::signUrl($url);
        $cleanPath = parse_url($url, PHP_URL_PATH) ?? '';
        $filename = basename($cleanPath);
        $isMkv = str_ends_with(strtolower($filename), '.mkv');

        $isVip = str_contains($url, 'b-cdn.net');
        $serverName = $isVip ? 'Rakun VIP (Bunny CDN)' : 'Rakun Cloud CDN';

        // Usar proxy local con streaming parcial HTTP 206 y forzado de cabeceras video/mp4
        $proxyUrl = 'api/rakun.php?action=stream&url=' . rawurlencode($signedUrl);

        return [
            'server'       => $serverName,
            'embed_url'    => $proxyUrl,
            'stream_url'   => $proxyUrl,
            'download_url' => $signedUrl,
            'filename'     => $filename,
            'is_mkv'       => $isMkv,
            'quality'      => '1080p Full HD',
            'is_available' => true
        ];
    }
}
