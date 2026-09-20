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

    <header class="py-2 px-3 mb-4 sticky-top navbar-glass">
        <div class="container-fluid px-lg-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <!-- Marca / Logo -->
                <a href="index.php" class="navbar-brand d-flex align-items-center text-decoration-none me-2">
                    <i class="bi bi-play-circle-fill text-danger fs-3 me-2"></i>
                    <span class="brand-logo-text">StreamMedia</span>
                </a>

                <!-- Navegación de categorías -->
                <nav class="d-none d-md-flex align-items-center gap-1">
                    <a href="index.php" class="nav-pill-link"><i class="bi bi-house-door me-1"></i> Inicio</a>
                    <a href="index.php#section-popular-movies" class="nav-pill-link"><i class="bi bi-film me-1"></i> Películas</a>
                    <a href="index.php#section-popular-series" class="nav-pill-link"><i class="bi bi-tv me-1"></i> Series</a>
                    <a href="index.php#section-animation-movies" class="nav-pill-link"><i class="bi bi-stars me-1"></i> Animación</a>
                    <a href="index.php#watchlist-container" class="nav-pill-link">
                        <i class="bi bi-bookmark-fill me-1"></i> Mi Lista
                        <span class="badge bg-danger rounded-pill ms-1 nav-watchlist-count" style="display:none; font-size: 0.68rem;">0</span>
                    </a>
                </nav>

                <!-- Buscador en tiempo real y acciones -->
                <div class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0 justify-content-end">
                    <form action="search.php" method="get" class="header-search-wrap position-relative">
                        <input id="search-rakun" 
                               type="text" 
                               name="q" 
                               class="form-control search-input-modern" 
                               placeholder="Buscar películas, series..." 
                               required 
                               autocomplete="off">
                        <kbd class="kbd-shortcut" title="Presiona '/' para buscar">/</kbd>
                    </form>

                    <form action="clear_cache.php" method="post" class="d-inline">
                        <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1" title="Limpiar Caché de la aplicación">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>
    <div id="storage-alert-toast" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1200;"></div>