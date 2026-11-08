<?php

/**
 * Manejador centralizado de errores y excepciones para la aplicación.
 * 
 * - Registra advertencias y notices no fatales (warnings/notices) en el log sin abortar la ejecución.
 * - Solo convierte errores irrecuperables en excepciones.
 * - Si ocurre una excepción no capturada:
 *   - En peticiones API o AJAX: responde con JSON estructurado coherente.
 *   - En páginas web tradicionales (HTML): muestra una vista amigable al usuario en lugar de interrumpir con JSON.
 */

function app_error_handler(int $severity, string $message, string $file, int $line): bool {
    // Si el error fue suprimido con @ (en PHP 8 error_reporting() devuelve 0 durante la llamada)
    if (!(error_reporting() & $severity)) {
        return false;
    }

    // Errores no fatales (Warnings, Notices, Deprecations) no deben detener la ejecución
    $non_fatal = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED;
    if (defined('E_STRICT')) {
        $non_fatal |= E_STRICT;
    }

    if ($severity & $non_fatal) {
        // Registrar silenciosamente en el log de errores de PHP
        @error_log(sprintf("[StreamMedia %s] %s in %s on line %d", ($severity & (E_WARNING | E_USER_WARNING)) ? 'Warning' : 'Notice', $message, $file, $line));
        return true; // Indicamos que el error fue gestionado para que PHP continúe su curso normal
    }

    // Para errores fatales recuperables (E_RECOVERABLE_ERROR, E_USER_ERROR), lanzar excepción
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function app_exception_handler(Throwable $exception): void {
    @error_log(sprintf("[StreamMedia Exception] %s in %s on line %d", $exception->getMessage(), $exception->getFile(), $exception->getLine()));

    $is_api_request = (
        (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    );

    if (!headers_sent()) {
        http_response_code(500);
        if ($is_api_request) {
            header('Content-Type: application/json; charset=utf-8');
        } else {
            header('Content-Type: text/html; charset=utf-8');
        }
    }

    if ($is_api_request) {
        $response = [
            'status' => 'error',
            'message' => 'Ocurrió un error interno en el servidor.'
        ];

        if (ini_get('display_errors')) {
            $response['details'] = [
                'error_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error - StreamMedia</title>';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<style>body{background:#0b0e14;color:#e6edf3;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;text-align:center}';
        echo '.card{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:32px;max-width:520px;box-shadow:0 8px 24px rgba(0,0,0,0.5)}';
        echo 'h1{color:#f85149;margin:0 0 16px;font-size:22px}p{color:#8b949e;line-height:1.5;margin-bottom:24px;font-size:14px}';
        echo 'a{display:inline-block;background:#e50914;color:#fff;text-decoration:none;padding:10px 22px;border-radius:6px;font-weight:600;font-size:14px}';
        echo 'a:hover{background:#b80710}</style></head><body>';
        echo '<div class="card">';
        echo '<h1>Aviso del Sistema</h1>';
        echo '<p>Ha ocurrido un contratiempo temporal al procesar la solicitud. Por favor recarga la página o regresa al catálogo principal.</p>';
        if (ini_get('display_errors')) {
            echo '<pre style="text-align:left;background:#0d1117;padding:12px;border-radius:6px;overflow:auto;font-size:11px;color:#f85149;margin-bottom:20px;">';
            echo htmlspecialchars($exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine());
            echo '</pre>';
        }
        echo '<a href="index.php">Volver al Inicio</a>';
        echo '</div></body></html>';
    }
    exit;
}

set_error_handler('app_error_handler');
set_exception_handler('app_exception_handler');