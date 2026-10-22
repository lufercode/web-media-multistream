<?php
declare(strict_types=1);

require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../MoviePlusService.php';

class YandexDiskResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'yandex_disk';
    }

    public function canResolve(string $url): bool
    {
        if (str_contains($url, 'movieplus.php')) {
            return false;
        }
        return (str_starts_with($url, 'https://yadi.sk') || str_starts_with($url, 'http://yadi.sk') || str_contains($url, 'disk.yandex.'));
    }

    public function resolve(string $url): ?array
    {
        $info = MoviePlusService::resolveYandexDisk($url);
        $proxyStreamUrl = 'api/movieplus.php?action=stream&url=' . rawurlencode($url);

        if (!$info || empty($info['download_url'])) {
            return [
                'server'       => 'Movie+ Cloud FastStream',
                'embed_url'    => null,
                'stream_url'   => null,
                'quality'      => 'HD / 1080p',
                'is_available' => false,
                'error'        => 'El enlace de Movie+ en Yandex no está disponible o fue eliminado'
            ];
        }

        $directUrl = $info['download_url'];
        $filename = $info['filename'] ?? '';
        $isMkv = str_ends_with(strtolower($filename), '.mkv');
        $quality = !empty($info['size_human']) && $info['size_human'] !== 'N/A'
            ? 'HD (' . $info['size_human'] . ')'
            : 'HD / 1080p';

        return [
            'server'       => 'Movie+ Cloud FastStream',
            'embed_url'    => $proxyStreamUrl,
            'stream_url'   => $proxyStreamUrl,
            'download_url' => $directUrl,
            'filename'     => $filename,
            'is_mkv'       => $isMkv,
            'quality'      => $quality,
            'is_available' => true
        ];
    }
}
