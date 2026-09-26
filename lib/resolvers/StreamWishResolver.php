<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class StreamWishResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'streamwish';
    }

    public function canResolve(string $url): bool
    {
        return (bool)preg_match('/(?:streamwish|hlswish|wishfast|strwish|swhoi|wishembed|embedwish|hglink|dwish|awish|mwish|flaswish|obeywish|cdnwish|asnwish|vimeos|luluvdoo|vide0)/i', $url);
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
        $parsed = parse_url($url);
        $host = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'streamwish.to');
        $path = trim($parsed['path'] ?? '', '/');
        $segments = explode('/', $path);
        $id = end($segments);

        $is_vimeos = stripos($host, 'vimeos') !== false;
        if ($is_vimeos) {
            if (preg_match('/(?:embed-|\/d\/|\/e\/|\/)([a-zA-Z0-9]{8,20})(?:_[a-z])?(?:\.html)?/i', $url, $vm)) {
                $id = $vm[1];
            }
            $embed_url = "{$host}/embed-{$id}.html";
            $referer = 'https://lamovie.org/';
            $server_name = 'Vimeos';
        } else {
            if (empty($id) || strlen($id) < 5 || in_array(strtolower($id), ['embed', 'v', 'd', 'e', 'f'])) {
                if (preg_match('/(?:embed|v|d|e|f)\/([a-zA-Z0-9]+)/i', $url, $mId)) {
                    $id = $mId[1];
                }
            }
            $embed_url = "{$host}/e/{$id}";
            $referer = $embed_url;
            $server_name = 'StreamWish';
        }

        $stream_url = null;
        $html = http_get($embed_url, [
            'timeout' => 4,
            'headers' => [
                'Referer' => $referer,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]
        ]);

        if ($html) {
            if (preg_match('/["\'](https?:\/\/[^"\']+\.m3u8[^"\']*)["\']/i', $html, $m)) {
                $stream_url = $m[1];
            } elseif (preg_match_all('/eval\(function\(p,a,c,k,e,[rd]\).*?\.split\([\'"]\|[\'"]\)\)\)/s', $html, $packMatches)) {
                foreach ($packMatches[0] as $packedBlock) {
                    $unpacked = $this->unpackPacker($packedBlock);
                    if (preg_match('/(https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*)/i', $unpacked, $m3)) {
                        $stream_url = $m3[1];
                        break;
                    }
                }
            }
        }

        return [
            'server' => $server_name,
            'embed_url' => $embed_url,
            'stream_url' => $stream_url,
            'quality' => '1080p Full HD'
        ];
    }
}

