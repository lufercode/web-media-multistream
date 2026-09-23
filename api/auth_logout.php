<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

unset($_SESSION['user']);
session_destroy();

// Si es petición AJAX, devolver JSON; de lo contrario redirigir al index
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Sesión cerrada.']);
    exit;
}

$referer = $_SERVER['HTTP_REFERER'] ?? '../index.php';
header("Location: {$referer}");
exit;
