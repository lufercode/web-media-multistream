<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class StreamtapeResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'streamtape';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/(?:streamtape\.(?:com|net|to|site)|streamta\.pe|tapecontent\.net)/i', $url);
    }

    public function resolve(string $url): ?array
    {
        $embed_url = preg_replace('/\/v\//i', '/e/', $url);
        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Referer: ' . $embed_url
            ]
        ]);

        if (!$html) {
            return [
                'server' => 'Streamtape',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => '1080p'
            ];
        }

        if (stripos($html, '404 Not Found') !== false || stripos($html, 'Video not found') !== false) {
            return [
                'server' => 'Streamtape',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => '1080p',
                'is_available' => false,
                'error' => 'Video eliminado de Streamtape'
            ];
        }

        // Encontrar robotlink o token de Streamtape (reconstrucción extractor Kodi Balandro)
        $stream_url = null;
        if (preg_match('/id=["\']robotlink["\'][^>]*>(.*?)<\/div>/is', $html, $m)) {
            $stream_url = trim($m[1]);
        } elseif (preg_match('/document\.getElementById\([\'"]robotlink[\'"]\)\.innerHTML\s*=\s*[\'"]([^\'"]+)[\'"]\s*\+\s*\(?[\'"]([^\'"]+)[\'"]\)?\.substring\((\d+)\)/is', $html, $m)) {
            $sub = substr($m[2], (int)$m[3]);
            $stream_url = $m[1] . $sub;
        } elseif (preg_match('/document\.getElementById\([\'"]robotlink[\'"]\)\.innerHTML\s*=\s*[\'"]([^\'"]+)[\'"]/is', $html, $m)) {
            $stream_url = $m[1];
        }

        if ($stream_url) {
            if (strpos($stream_url, '//') === 0) {
                $stream_url = 'https:' . $stream_url;
            } elseif (strpos($stream_url, 'http') !== 0) {
                $stream_url = 'https://streamtape.com' . $stream_url;
            }
            if (strpos($stream_url, 'stream=1') === false) {
                $stream_url .= (strpos($stream_url, '?') !== false ? '&' : '?') . 'stream=1';
            }
        }

        return [
            'server' => 'Streamtape',
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => '1080p',
            'is_available' => true
        ];
    }
}
