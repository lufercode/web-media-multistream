<?php
// Iniciar sesión persistente de usuario si no está activa
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start([
        'cookie_lifetime' => 86400 * 30, // 30 días de persistencia
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
    // Liberar inmediatamente el cerrojo de archivo en disco para permitir
    // que todas las peticiones AJAX concurrentes (proveedores, disponibilidad) se ejecuten en paralelo.
    session_write_close();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/functions.php';
