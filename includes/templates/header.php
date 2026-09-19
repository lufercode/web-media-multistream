<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscador de Contenido</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= file_exists(__DIR__ . '/../../assets/css/style.css') ? filemtime(__DIR__ . '/../../assets/css/style.css') : time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>

    <header class="p-3 mb-3 border-bottom bg-dark">
        <div class="container">
            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
                <a href="index.php" class="navbar-brand d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none">
                    <h1 class="h3 text-white my-0">Mi Buscador</h1>
                </a>
                <ul class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
                    <li><a href="index.php" class="nav-link px-2 text-white">Inicio</a></li>
                </ul>
                <div class="text-end">
                    <form action="clear_cache.php" method="post">
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-trash-fill"></i> Limpiar Caché
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>
    <div id="storage-alert-toast" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1200;"></div>