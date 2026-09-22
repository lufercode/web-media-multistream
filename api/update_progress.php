<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$content_id = $input['content_id'] ?? null;
$content_type = $input['content_type'] ?? null;
$season_num = $input['season_num'] ?? null;
$episode_num = $input['episode_num'] ?? null;
$status = $input['status'] ?? null;

if (!$content_id || !$content_type || !$status) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Parámetros incompletos.']);
    exit;
}

$progress = read_watched_progress();

if (!isset($progress[$content_type])) {
    $progress[$content_type] = [];
}

if ($content_type === 'movie') {
    $progress[$content_type][$content_id] = $status;
} else {
    if (!$season_num || !$episode_num) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Falta temporada o episodio para contenido de serie.']);
        exit;
    }
    if (!isset($progress[$content_type][$content_id])) {
        $progress[$content_type][$content_id] = [];
    }
    if (!isset($progress[$content_type][$content_id][$season_num])) {
        $progress[$content_type][$content_id][$season_num] = [];
    }
    $progress[$content_type][$content_id][$season_num][$episode_num] = $status;
    $progress[$content_type][$content_id]['_last_watched'] = [
        'season' => (int)$season_num,
        'episode' => (int)$episode_num,
        'status' => $status,
        'updated_at' => time()
    ];
}

if (write_watched_progress($progress)) {
    echo json_encode(['status' => 'success', 'message' => 'Progreso actualizado.']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error al guardar el progreso.']);
}

