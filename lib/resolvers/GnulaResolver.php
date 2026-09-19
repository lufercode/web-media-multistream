<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/../utils.php';

class GnulaResolver implements ResolverInterface
{
    public function getId(): string
    {
        return 'gnula_resolver';
    }

    public function canResolve(string $url): bool
    {
        return (stripos($url, 'streamz.ws') !== false)
            || (stripos($url, 'gnula.one') !== false)
            || (stripos($url, 'uqload.com') !== false)
            || (stripos($url, 'uqload.to') !== false);
    }

    public function resolve(string $url): ?array
    {
        // 1. Streamz
        if (stripos($url, 'streamz.ws') !== false) {
            return [
                'server' => 'Streamz',
                'embed_url' => $url,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        // 2. Uqload
        if (stripos($url, 'uqload.') !== false) {
            return [
                'server' => 'Uqload',
                'embed_url' => $url,
                'stream_url' => null,
                'quality' => 'HD'
            ];
        }

        // 3. Gnula page directa si se pasa como enlace
        if (stripos($url, 'gnula.one/movie/') !== false) {
            $html = http_get($url, [
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            if ($html && preg_match('/<iframe[^>]+(?:data-lazy-src|src)="([^"]+)"/i', $html, $m)) {
                $iframe = $m[1];
                if ($iframe !== 'about:blank' && stripos($iframe, 'facebook') === false) {
                    return [
                        'server' => 'Gnula Stream',
                        'embed_url' => $iframe,
                        'stream_url' => null,
                        'quality' => 'HD'
                    ];
                }
            }
        }

        return [
            'server' => 'Gnula Player',
            'embed_url' => $url,
            'stream_url' => null,
            'quality' => 'HD'
        ];
    }
}

