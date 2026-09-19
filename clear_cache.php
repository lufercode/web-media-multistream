<?php
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cleared_files = clear_cache();
    // Redirige al inicio después de limpiar el caché
    header('Location: index.php?cache_cleared=true');
    exit;
}
// Si se accede directamente por GET, no hace nada
?>