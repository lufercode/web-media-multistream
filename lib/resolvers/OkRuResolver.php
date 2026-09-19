<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class OkRuResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'okru';
    }

    public function canResolve(string $url): bool
    {
        return (stripos($url, 'ok.ru/videoembed/') !== false || 
                stripos($url, 'ok.ru/video/') !== false || 
                stripos($url, 'odnoklassniki.ru/videoembed/') !== false);
    }

    public function resolve(string $url): ?array
    {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $html = http_get($url, [
            'timeout' => 6,
            'headers' => [
                'Referer: https://ok.ru/',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]
        ]);

        if (!$html) {
            return [
                'server' => 'Ok.ru',
                'embed_url' => $url,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        if (preg_match('/data-options="([^"]+)"/i', $html, $matches)) {
            $raw_json = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            $data = json_decode($raw_json, true);

            $meta = $data['flashvars']['metadata'] ?? null;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }

            if (is_array($meta)) {
                // 1. Extraer enlace MP4 directo con la mejor calidad disponible
                if (!empty($meta['videos']) && is_array($meta['videos'])) {
                    $quality_order = ['full', 'hd', 'sd', 'low', 'lowest', 'mobile'];
                    $best_video = null;
                    $best_quality_label = 'HD';

                    foreach ($quality_order as $q) {
                        foreach ($meta['videos'] as $vid) {
                            if (strtolower($vid['name'] ?? '') === $q && !empty($vid['url'])) {
                                $best_video = $vid['url'];
                                $best_quality_label = strtoupper($q);
                                break 2;
                            }
                        }
                    }

                    if (!$best_video && !empty($meta['videos'][0]['url'])) {
                        $best_video = $meta['videos'][0]['url'];
                        $best_quality_label = strtoupper($meta['videos'][0]['name'] ?? 'HD');
                    }

                    if ($best_video) {
                        return [
                            'server' => 'Ok.ru',
                            'embed_url' => $url,
                            'stream_url' => $best_video,
                            'quality' => $best_quality_label
                        ];
                    }
                }

                // 2. Si no hay videos MP4 directos, comprobar HLS Master Playlist
                if (!empty($meta['hlsMasterPlaylistUrl'])) {
                    return [
                        'server' => 'Ok.ru',
                        'embed_url' => $url,
                        'stream_url' => $meta['hlsMasterPlaylistUrl'],
                        'quality' => 'HD'
                    ];
                }
            }
        }

        return [
            'server' => 'Ok.ru',
            'embed_url' => $url,
            'stream_url' => null,
            'quality' => 'HD'
        ];
    }
}

