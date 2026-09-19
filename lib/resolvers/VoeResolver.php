<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class VoeResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'voe';
    }

    public function canResolve(string $url): bool
    {
        return (stripos($url, 'voe.sx') !== false) || (stripos($url, 'voesx.com') !== false);
    }

    public function resolve(string $url): ?array
    {
        $html = http_get($url, ['timeout' => 3]);
        $stream_url = null;

        if ($html) {
            // 1. Extraer hls direct link
            if (preg_match('/["\']hls["\']\s*:\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
                $stream_url = $m[1];
            } elseif (preg_match('/https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i', $html, $m)) {
                $stream_url = $m[0];
            } elseif (preg_match('/["\']mp4["\']\s*:\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
                $stream_url = $m[1];
            }
        }

        return [
            'server' => 'Voe',
            'embed_url' => $url,
            'stream_url' => $stream_url,
            'quality' => '1080p'
        ];
    }
}
