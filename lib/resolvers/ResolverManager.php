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
require_once __DIR__ . '/YandexDiskResolver.php';
require_once __DIR__ . '/StreamtapeResolver.php';
require_once __DIR__ . '/YourUploadResolver.php';
require_once __DIR__ . '/MixDropResolver.php';
require_once __DIR__ . '/Mp4UploadResolver.php';

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
            new YandexDiskResolver(),
            new CinecalidadResolver(),
            new CuevanaResolver(),
            new GnulaResolver(),
            new RetroTVEResolver(),
            new OkRuResolver(),
            new VoeResolver(),
            new VidHideResolver(),
            new StreamWishResolver(),
            new FastreamResolver(),
            new StreamtapeResolver(),
            new YourUploadResolver(),
            new MixDropResolver(),
            new Mp4UploadResolver()
        ];
    }

    /**
     * Resuelve cualquier URL a través de la cadena de resolvers disponibles
     */
    public function resolve(string $url): array
    {
        $current_url = trim($url);
        if (strpos($current_url, 'http://') === 0 && strpos($current_url, 'http://localhost') === false && strpos($current_url, 'http://127.0.0.1') === false) {
            $current_url = 'https://' . substr($current_url, 7);
        }

        $resolved_server = 'Online Stream';
        $resolved_quality = 'HD';
        $stream_url = null;
        $download_url = null;
        $filename = null;
        $is_mkv = false;
        $is_available = true;
        $error = null;

        // Comprobación previa de dominio muerto conocido
        if (function_exists('is_dead_stream_domain') && is_dead_stream_domain($current_url)) {
            return [
                'original_url' => $url,
                'embed_url'    => null,
                'stream_url'   => null,
                'download_url' => null,
                'filename'     => null,
                'is_mkv'       => false,
                'server'       => 'Servidor Inactivo',
                'quality'      => 'N/A',
                'is_available' => false,
                'error'        => 'Servidor dado de baja o inactivo'
            ];
        }

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
                        if (!empty($res['download_url'])) $download_url = $res['download_url'];
                        if (!empty($res['filename'])) $filename = $res['filename'];
                        if (isset($res['is_mkv'])) $is_mkv = (bool)$res['is_mkv'];
                        if (isset($res['is_available'])) $is_available = (bool)$res['is_available'];
                        if (!empty($res['error'])) $error = $res['error'];
                        $matched = true;
                        break;
                    } elseif ($res && isset($res['is_available']) && !$res['is_available']) {
                        $is_available = false;
                        $error = $res['error'] ?? 'El servidor no está disponible';
                        if (!empty($res['server'])) $resolved_server = $res['server'];
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched || !$is_available) break;
        }

        // Si sigue disponible y no hay stream directo, comprobar salud del embed antes de entregarlo
        if ($is_available && empty($stream_url) && !empty($current_url)) {
            $health = $this->checkEmbedHealth($current_url);
            if (!$health['available']) {
                $is_available = false;
                $error = $health['reason'] ?? 'El video fue eliminado del servidor externo';
            }
        }

        return [
            'original_url' => $url,
            'embed_url'    => $current_url,
            'stream_url'   => $stream_url,
            'download_url' => $download_url,
            'filename'     => $filename,
            'is_mkv'       => $is_mkv,
            'server'       => $resolved_server,
            'quality'      => $resolved_quality,
            'is_available' => $is_available,
            'error'        => $error
        ];
    }

    /**
     * Comprueba de forma ágil si un embed está vivo y responde sin errores de archivo eliminado
     */
    public function checkEmbedHealth(string $url): array
    {
        if (function_exists('is_dead_stream_domain') && is_dead_stream_domain($url)) {
            return ['available' => false, 'reason' => 'Servidor dado de baja o inactivo'];
        }

        if (!extension_loaded('curl')) {
            return ['available' => true];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_RANGE, '0-4096');

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
        curl_close($ch);

        if ($err !== 0 || $code === 0) {
            return ['available' => false, 'reason' => 'No se pudo conectar con el servidor'];
        }

        if ($code === 404 || $code === 410 || $code >= 500) {
            return ['available' => false, 'reason' => "Servidor no disponible (HTTP $code)"];
        }

        if (preg_match('/(file (?:was )?deleted|file not found|video (?:was )?removed|not exist|no longer available|el archivo fue eliminado)/i', $body)) {
            return ['available' => false, 'reason' => 'El video fue eliminado por el servidor'];
        }

        return ['available' => true];
    }
}

