<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class VidHideResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'vidhide';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/(?:vidhide|vidguard|filelions|streamhide|morencius|kinotv|dramiyos)/i', $url);
    }

    private function unpackPacker(string $script): string
    {
        if (!preg_match('/}\s*\(\s*[\'"](.*)[\'"]\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*[\'"](.*)[\'"]\.split\(\s*[\'"]\|[\'"]\s*\)/s', $script, $m)) {
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
        $parsed = parse_url($url);
        $host = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'vidhidepro.com');
        $path = trim($parsed['path'] ?? '', '/');
        $segments = explode('/', $path);
        $id = end($segments);

        if (empty($id) || strlen($id) < 5 || in_array(strtolower($id), ['embed', 'v', 'd', 'e', 'watch'])) {
            if (preg_match('/(?:embed|v|d|e)\/([a-zA-Z0-9]+)/i', $url, $mId)) {
                $id = $mId[1];
            }
        }

        $embed_url = "{$host}/v/{$id}";

        $stream_url = null;
        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'Referer' => $embed_url,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]
        ]);

        if ($html) {
            // 1. Búsqueda directa de stream m3u8 en el HTML
            if (preg_match('/["\'](https?:\/\/[^"\']+\.m3u8[^"\']*)["\']/i', $html, $m)) {
                $stream_url = $m[1];
            } elseif (preg_match('/eval\(function\(p,a,c,k,e,[rd]\).*?\.split\([\'"]\|[\'"]\)\)\)/s', $html, $m)) {
                // 2. Desempaquetar código Dean Edwards Packer
                $unpacked = $this->unpackPacker($m[0]);
                if (preg_match('/(https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*)/i', $unpacked, $m3)) {
                    $stream_url = $m3[1];
                }
            }
        }

        return [
            'server' => 'VidHide',
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => '1080p Full HD'
        ];
    }
}

