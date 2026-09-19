<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class RetroTVEResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'retrotve_embed';
    }

    public function canResolve(string $url): bool
    {
        return (stripos($url, 'retrotve.com') !== false && (stripos($url, 'trembed=') !== false || stripos($url, 'trid=') !== false));
    }

    public function resolve(string $url): ?array
    {
        $html = http_get($url, [
            'timeout' => 5,
            'headers' => [
                'Referer: https://retrotve.com/'
            ]
        ]);

        if (!$html) {
            return [
                'server' => 'RetroTVE Player',
                'embed_url' => $url,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        // Extraer el iframe real contenido en la vista trembed
        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            $real_embed = html_entity_decode($matches[1]);
            if (strpos($real_embed, '//') === 0) {
                $real_embed = 'https:' . $real_embed;
            }

            $server = 'Online Stream';
            $lower = strtolower($real_embed);
            if (strpos($lower, 'ok.ru') !== false || strpos($lower, 'odnoklassniki') !== false) {
                $server = 'Ok.ru';
            } elseif (strpos($lower, 'filemoon') !== false) {
                $server = 'Filemoon';
            } elseif (strpos($lower, 'vkvideo.ru') !== false || strpos($lower, 'vk.com') !== false) {
                $server = 'VK Video';
            } elseif (strpos($lower, 'streamwish') !== false || strpos($lower, 'wish') !== false) {
                $server = 'StreamWish';
            } elseif (strpos($lower, 'vidhide') !== false) {
                $server = 'VidHide';
            } elseif (strpos($lower, 'voe.sx') !== false) {
                $server = 'Voe';
            } elseif (strpos($lower, 'dood') !== false) {
                $server = 'Doodstream';
            } elseif (strpos($lower, 'streamtape') !== false) {
                $server = 'Streamtape';
            } elseif (strpos($lower, 'mega.nz') !== false || strpos($lower, 'mega.io') !== false) {
                $server = 'Mega';
            }

            return [
                'server' => $server,
                'embed_url' => $real_embed,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        return [
            'server' => 'RetroTVE Player',
            'embed_url' => $url,
            'stream_url' => null,
            'quality' => 'HD'
        ];
    }
}

