<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$credential = $input['credential'] ?? $_POST['credential'] ?? null;

if (!$credential) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token de credencial no proporcionado.']);
    exit;
}

// Validar el ID Token directamente con el endpoint de verificación oficial de Google
$verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

$context = stream_context_create([
    'http' => [
        'timeout' => 5,
        'ignore_errors' => true,
        'header' => "User-Agent: StreamMedia-App/1.0\r\n"
    ]
]);

$response_json = @file_get_contents($verify_url, false, $context);
if ($response_json === false) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo conectar con el servicio de autenticación de Google.']);
    exit;
}

$payload = json_decode($response_json, true);

if (empty($payload) || empty($payload['sub']) || empty($payload['email'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => $payload['error_description'] ?? 'Token de Google inválido o caducado.'
    ]);
    exit;
}

// Opcional: Si GOOGLE_CLIENT_ID está configurado y no es el marcador de posición, verificar que coincida con aud
if (defined('GOOGLE_CLIENT_ID') && strpos(GOOGLE_CLIENT_ID, 'TU_GOOGLE_CLIENT_ID') === false && !empty(GOOGLE_CLIENT_ID)) {
    if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'El token no corresponde a esta aplicación.']);
        exit;
    }
}

// Iniciar sesión y guardar datos del usuario
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$google_user_id = (string)$payload['sub'];
$user_data = [
    'id' => $google_user_id,
    'email' => (string)$payload['email'],
    'name' => (string)($payload['name'] ?? $payload['email']),
    'picture' => (string)($payload['picture'] ?? '')
];

$_SESSION['user'] = $user_data;
session_write_close();

// Si había progreso en el dispositivo actual, fusionarlo dentro de la cuenta Google del usuario
$device_cookie_name = 'stream_device_id';
if (!empty($_COOKIE[$device_cookie_name])) {
    $current_device_id = trim((string)$_COOKIE[$device_cookie_name]);
    if (preg_match('/^[a-f0-9\-]{16,64}$/i', $current_device_id)) {
        merge_device_progress_to_user($current_device_id, $google_user_id);
    }
}

echo json_encode([
    'status' => 'success',
    'message' => 'Sesión iniciada con éxito.',
    'user' => $user_data
]);

