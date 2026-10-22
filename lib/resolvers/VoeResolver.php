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
        return (stripos($url, 'voe.sx') !== false) ||
               (stripos($url, 'voesx.com') !== false) ||
               (stripos($url, 'zilla-networks.com/play/') !== false);
    }

    public function resolve(string $url): ?array
    {
        // Resolución directa e instantánea para Zilla Networks (AnimeFLV / LatAnime)
        if (preg_match('/zilla-networks\.com\/play\/([a-f0-9]{16,64})/i', $url, $mZilla)) {
            return [
                'server' => 'Zilla HLS',
                'embed_url' => $url,
                'stream_url' => 'https://player.zilla-networks.com/m3u8/' . $mZilla[1],
                'quality' => '1080p'
            ];
        }

        $html = http_get($url, ['timeout' => 4]);
        $stream_url = null;

        $is_dead = (!$html || stripos($html, '404 not found') !== false || stripos($html, 'file was deleted') !== false || stripos($html, 'file not found') !== false || stripos($html, 'page you are looking for') !== false);
        if ($is_dead) {
            return [
                'server' => 'Voe',
                'embed_url' => $url,
                'stream_url' => null,
                'quality' => '1080p',
                'is_available' => false,
                'error' => 'El video fue eliminado de Voe (Error 404 / Eliminado)'
            ];
        }

        if ($html) {
            // Seguir redirección por JS de Voe (ej: window.location.href = 'https://megankiddifference.com/e/...')
            if (preg_match('/window\.location\.href\s*=\s*[\'"](https?:\/\/[^\'"]+)[\'"]/i', $html, $mRedir)) {
                $redir_html = http_get($mRedir[1], [
                    'timeout' => 4,
                    'headers' => ['Referer' => $url]
                ]);
                if ($redir_html) {
                    $html = $redir_html;
                }
            }

            // 1. Decodificar el payload JSON ofuscado de Voe (<script type="application/json">["..."]</script>)
            $stream_url = $this->decodeVoeJsonPayload($html);

            // 2. Fallback a extracción clásica (ignorando el video dummy Big Buck Bunny de test-videos.co.uk)
            if (!$stream_url) {
                if (preg_match('/["\']hls["\']\s*:\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m) && stripos($m[1], 'test-videos.co.uk') === false) {
                    $stream_url = $m[1];
                } elseif (preg_match('/https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i', $html, $m) && stripos($m[0], 'test-videos.co.uk') === false) {
                    $stream_url = $m[0];
                } elseif (preg_match('/["\']mp4["\']\s*:\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m) && stripos($m[1], 'test-videos.co.uk') === false) {
                    $stream_url = $m[1];
                }
            }
        }

        return [
            'server' => 'Voe',
            'embed_url' => $url,
            'stream_url' => $stream_url,
            'quality' => '1080p',
            'is_available' => true
        ];
    }

    private function decodeVoeJsonPayload(string $html): ?string
    {
        if (!preg_match_all('/<script[^>]+type=["\']application\/json["\'][^>]*>\s*(\[\s*"[^"]+"\s*\])\s*<\/script>/is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $rawJson) {
            $arr = json_decode($rawJson, true);
            if (!is_array($arr) || empty($arr[0]) || !is_string($arr[0])) continue;

            // Paso 1: ROT13 sobre letras ASCII
            $s = str_rot13($arr[0]);

            // Paso 2: Eliminar tokens señuelo de Voe (@$, ^^, ~@, %?, *~, !!, #&)
            $s = str_replace(['@$', '^^', '~@', '%?', '*~', '!!', '#&'], '', $s);

            // Paso 3: Base64 decode
            $s = base64_decode($s, true);
            if ($s === false || $s === '') continue;

            // Paso 4: Desplazamiento César (-3) en cada byte
            $shifted = '';
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $shifted .= chr((ord($s[$i]) - 3) & 0xFF);
            }

            // Paso 5: Invertir cadena y segundo Base64 decode
            $reversed = strrev($shifted);
            $finalJson = base64_decode($reversed, true);
            if ($finalJson === false || $finalJson === '') continue;

            $parsed = json_decode($finalJson, true);
            if (!is_array($parsed)) continue;

            if (!empty($parsed['source']) && stripos($parsed['source'], 'http') === 0) {
                return $parsed['source'];
            }
            if (!empty($parsed['direct_access_url']) && stripos($parsed['direct_access_url'], 'http') === 0) {
                return $parsed['direct_access_url'];
            }
        }

        return null;
    }
}
