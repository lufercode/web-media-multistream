<?php
require_once __DIR__ . '/ResolverInterface.php';

class CinecalidadResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'cinecalidad_cipher';
    }

    public function canResolve(string $url): bool
    {
        // Detecta si es una cadena codificada en Base64/ASCII de Cinecalidad
        return (strlen($url) > 20 && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $url) && strpos($url, 'http') === false);
    }

    /**
     * Decodifica el cifrado de Cinecalidad (soporta Base64 directo y legado ROT-2)
     */
    public function decode(string $encoded): ?string
    {
        $encoded = trim($encoded);
        $b64 = @base64_decode($encoded);
        if (!$b64) return null;

        // 1. Base64 directo (formato moderno Cinecalidad .vg / .rs / .am)
        if (strpos($b64, 'http') === 0) {
            return $b64;
        }

        // 2. Formato legado ROT-2 (cadena de números en ASCII separados por espacio)
        $chars = explode(' ', trim($b64));
        $decoded_url = '';
        foreach ($chars as $c) {
            if (is_numeric($c)) {
                $decoded_url .= chr((int)$c - 2);
            }
        }

        return (strpos($decoded_url, 'http') === 0) ? $decoded_url : null;
    }

    /**
     * Compatibilidad hacia atrás
     */
    public function decodeRot2(string $encoded): ?string
    {
        return $this->decode($encoded);
    }

    public function resolve(string $url): ?array
    {
        $decoded = $this->decode($url);
        if (!$decoded) return null;

        $server = 'Cinecalidad Stream';
        if (stripos($decoded, 'vimeos') !== false) $server = 'Vimeos Player';
        elseif (stripos($decoded, 'wish') !== false) $server = 'StreamWish';
        elseif (stripos($decoded, 'voe') !== false) $server = 'Voe';
        elseif (stripos($decoded, 'filemoon') !== false) $server = 'Filemoon';
        elseif (stripos($decoded, 'goodstream') !== false) $server = 'Goodstream';

        return [
            'server' => $server,
            'embed_url' => $decoded,
            'stream_url' => null,
            'quality' => '1080p'
        ];
    }
}

