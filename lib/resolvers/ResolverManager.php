<?php
require_once __DIR__ . '/ResolverInterface.php';
require_once __DIR__ . '/CuevanaResolver.php';
require_once __DIR__ . '/VoeResolver.php';
require_once __DIR__ . '/CinecalidadResolver.php';
require_once __DIR__ . '/VidHideResolver.php';
require_once __DIR__ . '/StreamWishResolver.php';
require_once __DIR__ . '/FastreamResolver.php';
require_once __DIR__ . '/RetroTVEResolver.php';
require_once __DIR__ . '/OkRuResolver.php';
require_once __DIR__ . '/GnulaResolver.php';

class ResolverManager
{
    /** @var ResolverInterface[] */
    private array $resolvers = [];

    public function __construct()
    {
        $this->registerDefaultResolvers();
    }

    private function registerDefaultResolvers(): void
    {
        $this->resolvers = [
            new CinecalidadResolver(),
            new CuevanaResolver(),
            new GnulaResolver(),
            new RetroTVEResolver(),
            new OkRuResolver(),
            new VoeResolver(),
            new VidHideResolver(),
            new StreamWishResolver(),
            new FastreamResolver()
        ];
    }

    /**
     * Resuelve cualquier URL a través de la cadena de resolvers disponibles
     */
    public function resolve(string $url): array
    {
        $current_url = $url;
        $resolved_server = 'Online Stream';
        $resolved_quality = 'HD';
        $stream_url = null;

        // Intentar resolver hasta 2 niveles de anidación (ej. Wrapper -> Embed -> Direct Stream)
        for ($i = 0; $i < 2; $i++) {
            $matched = false;
            foreach ($this->resolvers as $resolver) {
                if ($resolver->canResolve($current_url)) {
                    $res = $resolver->resolve($current_url);
                    if ($res && !empty($res['embed_url'])) {
                        $current_url = $res['embed_url'];
                        if (!empty($res['server'])) $resolved_server = $res['server'];
                        if (!empty($res['quality'])) $resolved_quality = $res['quality'];
                        if (!empty($res['stream_url'])) $stream_url = $res['stream_url'];
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) break;
        }

        return [
            'original_url' => $url,
            'embed_url' => $current_url,
            'stream_url' => $stream_url,
            'server' => $resolved_server,
            'quality' => $resolved_quality
        ];
    }
}

