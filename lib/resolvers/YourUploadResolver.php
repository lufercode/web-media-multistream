<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class YourUploadResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'yourupload';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/yourupload\.com/i', $url);
    }

    public function resolve(string $url): ?array
    {
        $embed_url = str_ireplace('/watch/', '/embed/', $url);
        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Referer: https://www.yourupload.com/'
            ]
        ]);

        if (!$html) {
            return [
                'server' => 'YourUpload',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => '720p'
            ];
        }

        if (stripos($html, 'File not found') !== false || stripos($html, 'File has been deleted') !== false) {
            return [
                'server' => 'YourUpload',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => '720p',
                'is_available' => false,
                'error' => 'Video eliminado de YourUpload'
            ];
        }

        $stream_url = null;
        if (preg_match('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $stream_url = $m[1];
        } elseif (preg_match('/file\s*:\s*["\']([^"\']+\.mp4[^"\']*)["\']/i', $html, $m)) {
            $stream_url = $m[1];
        }

        if ($stream_url) {
            if (strpos($stream_url, '//') === 0) {
                $stream_url = 'https:' . $stream_url;
            } elseif (strpos($stream_url, 'http') !== 0) {
                $stream_url = 'https://www.yourupload.com' . $stream_url;
            }
        }

        return [
            'server' => 'YourUpload',
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => '720p',
            'is_available' => true
        ];
    }
}

