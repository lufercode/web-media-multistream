<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class CuevanaResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'cuevana_player';
    }

    public function canResolve(string $url): bool
    {
        return (stripos($url, 'cuevana-3.mx/player.php') !== false)
            || (stripos($url, 'player.cuevana') !== false)
            || (stripos($url, 'player.poseidonhd') !== false)
            || (stripos($url, 'poseidonhd2.co/player.php') !== false)
            || (stripos($url, 'links.cuevana') !== false)
            || (stripos($url, 'waaw.to') !== false);
    }

    public function resolve(string $url): ?array
    {
        // 1. Manejar player.cuevana.ac/f/ o waaw.to/f/ -> convertir directamente al endpoint embebible /e/
        if (preg_match('#https?://(?:player\.cuevana\.[a-z]+|waaw\.to)/(?:f/|watch_video\.php\?v=)([a-zA-Z0-9_\-\+/=]+)#i', $url, $mPlayer)) {
            $embed = "https://player.cuevana.ac/e/{$mPlayer[1]}";
            return [
                'server' => 'Waaw',
                'embed_url' => $embed,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        // 2. Manejar links.cuevana.ac/play/...
        if (stripos($url, 'links.cuevana') !== false) {
            $html = http_get($url, [
                'timeout' => 5,
                'headers' => [
                    'Referer: https://www2.gnula.one/',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            if ($html) {
                if (preg_match("/go_to_player\(['\"]([^'\"]+)['\"]\)/i", $html, $mGtp)) {
                    $playerUrl = $mGtp[1];
                    if (preg_match('#/(?:f|watch_video\.php\?v=)/([a-zA-Z0-9_\-\+/=]+)#i', $playerUrl, $mCode)) {
                        $playerUrl = "https://player.cuevana.ac/e/{$mCode[1]}";
                    }
                    return [
                        'server' => 'Waaw',
                        'embed_url' => $playerUrl,
                        'stream_url' => null,
                        'quality' => 'HD'
                    ];
                }
            }
        }

        // 3. Manejar player.cuevana-3.mx y player.poseidonhd2.co
        $referer = 'https://www.cuevana-3.mx/';
        if (stripos($url, 'poseidon') !== false) {
            $referer = 'https://poseidonhd2.co/';
        } elseif (stripos($url, 'cuevana.ac') !== false) {
            $referer = 'https://www2.gnula.one/';
        }

        $html = http_get($url, [
            'timeout' => 4,
            'headers' => [
                "Referer: $referer",
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]
        ]);

        if (!$html) {
            $serverName = stripos($url, 'poseidon') !== false ? 'Poseidon Player' : 'Cuevana Player';
            return [
                'server' => $serverName,
                'embed_url' => $url,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        // Buscar iframe o URL de servidor real en el HTML
        if (preg_match('/https?:\/\/(?:[a-zA-Z0-9_\-\.]*(?:voe|katherine|streamwish|hlswish|wishfast|strwish|swhoi|wishembed|embedwish|hglink|dwish|vidhide|vidguard|filelions|streamhide|morencius|kinotv|dramiyos|filemoon|bysejikuar|fastream|dood|streamtape|mixdrop|uqload|netu|wolfstream)[a-zA-Z0-9_\-\.]*\.[a-z]+)\/[^\s"\'<>]+/i', $html, $match)) {
            $real_embed = $match[0];
            $server = 'Online Stream';
            if (preg_match('/(?:voe|katherine)/i', $real_embed)) $server = 'Voe';
            elseif (preg_match('/(?:wish|hglink|dwish)/i', $real_embed)) $server = 'StreamWish';
            elseif (preg_match('/(?:vidhide|vidguard|filelions|streamhide|morencius|kinotv|dramiyos)/i', $real_embed)) $server = 'VidHide';
            elseif (preg_match('/(?:fastream)/i', $real_embed)) $server = 'Fastream';
            elseif (preg_match('/(?:filemoon|bysejikuar)/i', $real_embed)) $server = 'Filemoon';
            elseif (preg_match('/(?:dood)/i', $real_embed)) $server = 'Doodstream';
            elseif (preg_match('/(?:streamtape)/i', $real_embed)) $server = 'Streamtape';

            return [
                'server' => $server,
                'embed_url' => $real_embed,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        $serverName = stripos($url, 'poseidon') !== false ? 'Poseidon Player' : 'Cuevana Player';
        return [
            'server' => $serverName,
            'embed_url' => $url,
            'stream_url' => null,
            'quality' => 'HD'
        ];
    }
}
