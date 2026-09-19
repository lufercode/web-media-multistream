<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class FastreamResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'fastream';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/fastream\.[a-z]+/i', $url);
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
        // Normalizar a formato embed canónico
        $embed_url = $url;
        if (preg_match('/fastream\.[a-z]+\/([a-zA-Z0-9]+)(?:\.html)?$/i', $url, $m)) {
            if (strpos($m[1], 'embed-') !== 0) {
                $parsed = parse_url($url);
                $host = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'fastream.to');
                $embed_url = "{$host}/embed-{$m[1]}.html";
            }
        }

        $stream_url = null;
        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'Referer' => 'https://pelispedia.is/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]
        ]);

        if ($html) {
            // 1. Detección directa de m3u8
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
            'server' => 'Fastream',
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => '1080p Full HD'
        ];
    }
}

