<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class MixDropResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'mixdrop';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/mixdrop\.(?:co|to|ch|bz|ms|sx)/i', $url);
    }

    private function unpackPacker(string $script): string
    {
        if (!preg_match('/\}\s*\(\s*[\'"](.*)[\'"]\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*[\'"]([^\'"]+)[\'"]\.split\(\s*[\'"]\|[\'"]\s*\)/s', $script, $m)) {
            return $script;
        }

        $payload = $m[1];
        $base = (int)$m[2];
        $words = explode('|', $m[4]);

        return preg_replace_callback('/\b\w+\b/', function ($matches) use ($words, $base) {
            $word = $matches[0];
            $val = 0;
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $len = strlen($word);
            for ($i = 0; $i < $len; $i++) {
                $pos = strpos($chars, $word[$i]);
                if ($pos === false || $pos >= $base) return $word;
                $val = $val * $base + $pos;
            }
            return isset($words[$val]) && $words[$val] !== '' ? $words[$val] : $word;
        }, $payload);
    }

    public function resolve(string $url): ?array
    {
        $embed_url = str_ireplace('/f/', '/e/', $url);
        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Referer: https://mixdrop.co/'
            ]
        ]);

        if (!$html) {
            return [
                'server' => 'MixDrop',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        if (stripos($html, 'File not found') !== false || stripos($html, 'deleted') !== false) {
            return [
                'server' => 'MixDrop',
                'embed_url' => $embed_url,
                'stream_url' => null,
                'quality' => 'HD',
                'is_available' => false,
                'error' => 'Video eliminado de MixDrop'
            ];
        }

        $stream_url = null;
        if (preg_match('/eval\(function\(p,a,c,k,e,[rd]\).*?\.split\([\'"]\|[\'"]\)\)\)/s', $html, $m)) {
            $unpacked = $this->unpackPacker($m[0]);
            if (preg_match('/wurl\s*=\s*["\']([^"\']+)["\']/i', $unpacked, $wm)) {
                $stream_url = $wm[1];
                if (strpos($stream_url, '//') === 0) {
                    $stream_url = 'https:' . $stream_url;
                }
            }
        }

        return [
            'server' => 'MixDrop',
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => 'HD',
            'is_available' => true
        ];
    }
}
