<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../lib/utils.php';

$DAEMON_HOST = '127.0.0.1';
$DAEMON_PORT = 8889;
$DAEMON_BASE = "http://{$DAEMON_HOST}:{$DAEMON_PORT}";

function findNodeBinary(): string
{
    $candidates = [
        'C:/laragon/bin/nodejs/node-v22/node.exe',
        'C:/laragon/bin/nodejs/node-v20/node.exe',
        'C:/Program Files/nodejs/node.exe',
        'C:/Program Files (x86)/nodejs/node.exe'
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) return $cand;
    }
    $laragon_node = glob('C:/laragon/bin/nodejs/*/node.exe');
    if (!empty($laragon_node) && file_exists($laragon_node[0])) {
        return $laragon_node[0];
    }
    return 'node';
}

/**
 * Comprueba si el daemon Node.js está respondiendo; si no, lo inicia en segundo plano.
 */
function ensureDaemonRunning(string $daemon_base, int $timeout_sec = 4): bool
{
    $health = @http_get("{$daemon_base}/health", ['timeout' => 1]);
    if ($health && stripos($health, '"status":"ok"') !== false) {
        return true;
    }

    $tools_dir = ROOT_DIR . '/tools/torrent-streamer';
    $server_script = $tools_dir . '/server.js';
    if (!file_exists($server_script)) {
        return false;
    }

    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $win_tools = str_replace('/', '\\', $tools_dir);
        $node_bin = findNodeBinary();
        $cmd = 'cmd.exe /c "cd /d ' . $win_tools . ' && start "" /B "' . $node_bin . '" server.js > nul 2>&1"';
        pclose(popen($cmd, 'r'));
    } else {
        // En Linux / macOS
        $cmd = 'cd "' . $tools_dir . '" && node server.js > /dev/null 2>&1 &';
        exec($cmd);
    }

    // Esperar hasta que responda
    $start_time = microtime(true);
    while (microtime(true) - $start_time < $timeout_sec) {
        usleep(250000); // 250ms
        $check = @http_get("{$daemon_base}/health", ['timeout' => 1]);
        if ($check && stripos($check, '"status":"ok"') !== false) {
            return true;
        }
    }

    return false;
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Si se envían datos en JSON por POST
$json_input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_body = file_get_contents('php://input');
    if ($raw_body) {
        $json_input = json_decode($raw_body, true) ?? [];
    }
}

if (!$action) {
    $action = $json_input['action'] ?? 'health';
}

if ($action === 'health') {
    $is_running = ensureDaemonRunning($DAEMON_BASE, 2);
    if ($is_running) {
        $info = @http_get("{$DAEMON_BASE}/health", ['timeout' => 2]);
        echo $info ?: json_encode(['status' => 'ok', 'daemon' => 'running']);
    } else {
        echo json_encode(['status' => 'offline', 'error' => 'No se pudo iniciar el daemon de torrents']);
    }
    exit;
}

if ($action === 'load') {
    $magnet = $_POST['magnet'] ?? $json_input['magnet'] ?? $_GET['magnet'] ?? null;

    if (!$magnet || !str_starts_with($magnet, 'magnet:?')) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Magnet link no proporcionado o inválido']);
        exit;
    }

    if (!ensureDaemonRunning($DAEMON_BASE, 3)) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'error' => 'El servicio de streaming no está disponible']);
        exit;
    }

    // Enviar solicitud POST al daemon
    $payload = json_encode(['magnet' => $magnet]);
    $ch = curl_init("{$DAEMON_BASE}/load");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && ($http_code === 200 || $http_code === 201)) {
        echo $response;
    } else {
        http_response_code($http_code ?: 500);
        echo $response ?: json_encode(['status' => 'error', 'error' => 'Error al comunicar con el motor de torrents']);
    }
    exit;
}

if ($action === 'status') {
    $infoHash = $_GET['infoHash'] ?? $json_input['infoHash'] ?? null;
    if (!$infoHash) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Falta el parámetro infoHash']);
        exit;
    }

    ensureDaemonRunning($DAEMON_BASE, 2);

    $cleanHash = strtolower(trim($infoHash));
    $status_json = @http_get("{$DAEMON_BASE}/status/{$cleanHash}", ['timeout' => 2]);

    if ($status_json) {
        echo $status_json;
    } else {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'error' => 'Daemon no disponible']);
    }
    exit;
}

if ($action === 'stop') {
    $infoHash = $_POST['infoHash'] ?? $json_input['infoHash'] ?? $_GET['infoHash'] ?? null;
    if (!$infoHash) {
        echo json_encode(['status' => 'ignored']);
        exit;
    }

    $cleanHash = strtolower(trim($infoHash));
    $ch = curl_init("{$DAEMON_BASE}/stop/{$cleanHash}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $res = curl_exec($ch);
    curl_close($ch);

    echo $res ?: json_encode(['status' => 'stopped']);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'error' => 'Acción no reconocida']);
