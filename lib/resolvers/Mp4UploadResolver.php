<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class Mp4UploadResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'mp4upload';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/mp4upload\.com/i', $url);
    }

    public function resolve(string $url): ?array
    {
        $embed_url = preg_replace('/\/([a-zA-Z0-9]+)(?:\.html)?$/i', '/embed-$1.html', $url);
        if (strpos($embed_url, 'embed-') === false && preg_match('/\/([a-zA-Z0-9]+)$/', $url, $m)) {
            $embed_url = 'https://www.mp4upload.com/embed-' . $m[1] . '.html';
        }

        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Referer: https://www.mp4upload.com/'
            ]
        ]);

        if (!$html) {
            return [
                'server' => 'Mp4Upload',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => '1080p'
            ];
        }

        if (stripos($html, 'File was deleted') !== false || stripos($html, 'File Not Found') !== false) {
            return [
                'server' => 'Mp4Upload',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => '1080p',
                'is_available' => false,
                'error' => 'Video eliminado de Mp4Upload'
            ];
        }

        $stream_url = null;
        if (preg_match('/src:\s*["\'](https?:\/\/[^"\']+\.mp4[^"\']*)["\']/i', $html, $m)) {
            $stream_url = $m[1];
        } elseif (preg_match('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $stream_url = $m[1];
        }

        return [
            'server' => 'Mp4Upload',
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => '1080p',
            'is_available' => true
        ];
    }
}
