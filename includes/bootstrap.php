<?php
// Iniciar sesión persistente de usuario si no está activa
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start([
        'cookie_lifetime' => 86400 * 30, // 30 días de persistencia
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/functions.php';
