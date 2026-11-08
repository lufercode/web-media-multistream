<?php
declare(strict_types=1);

require_once 'includes/bootstrap.php';
require_once 'includes/templates/header.php';
?>

<div class="container my-3 my-md-4 px-2 px-sm-3">
    <!-- Barra Superior de Navegación -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm py-1 px-3 rounded-pill d-inline-flex align-items-center">
            <i class="fas fa-arrow-left me-1.5"></i> Volver al Inicio
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-dark border border-secondary text-secondary px-3 py-1.5 rounded-pill d-flex align-items-center gap-1">
                <i class="bi bi-bookmark-fill text-danger"></i>
                <span id="watchlist-count-badge">0 títulos</span>
            </span>
        </div>
    </div>

    <!-- Cabecera de Página -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 border-bottom border-secondary border-opacity-25 pb-3">
        <div>
            <h1 class="text-white fs-3 fw-bold mb-1 d-flex align-items-center">
                <i class="bi bi-bookmark-heart-fill text-danger me-2"></i> Mi Lista
            </h1>
            <p class="text-secondary small mb-0">Tus películas y series guardadas para disfrutar en cualquier momento.</p>
        </div>

        <!-- Controles de Filtros y Ordenación -->
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Filtros de categoría -->
            <div class="btn-group btn-group-sm" role="group" aria-label="Filtro de tipo">
                <button type="button" class="btn btn-outline-secondary active watchlist-filter-btn" data-filter="all">Todos</button>
                <button type="button" class="btn btn-outline-secondary watchlist-filter-btn" data-filter="movie">
                    <i class="bi bi-film me-1 text-danger"></i>Películas
                </button>
                <button type="button" class="btn btn-outline-secondary watchlist-filter-btn" data-filter="tv">
                    <i class="bi bi-tv me-1 text-info"></i>Series
                </button>
            </div>

            <!-- Selector de Ordenación -->
            <select id="watchlist-sort-select" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;">
                <option value="recent">Añadidos recientemente</option>
                <option value="title-asc">Título (A - Z)</option>
                <option value="title-desc">Título (Z - A)</option>
                <option value="rating-desc">Mayor calificación</option>
                <option value="year-desc">Año (Más reciente)</option>
            </select>

            <!-- Botón Vaciar Lista -->
            <button type="button" id="btn-clear-watchlist" class="btn btn-outline-danger btn-sm d-inline-flex align-items-center gap-1" title="Vaciar toda la lista">
                <i class="bi bi-trash3"></i>
                <span class="d-none d-sm-inline">Vaciar</span>
            </button>
        </div>
    </div>

    <!-- Buscador en tiempo real dentro de Mi Lista -->
    <div class="row mb-4">
        <div class="col-12 col-md-5 col-lg-4">
            <div class="position-relative">
                <input type="text" id="watchlist-search-input" class="form-control form-control-sm bg-dark text-white border-secondary ps-4 py-1.5" placeholder="Filtrar por título..." autocomplete="off">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2 text-secondary" style="font-size: 0.82rem;"></i>
                <button type="button" id="watchlist-search-clear" class="btn btn-link btn-sm position-absolute top-50 end-0 translate-middle-y me-1 text-secondary text-decoration-none p-0 d-none" style="font-size: 0.85rem;" title="Limpiar búsqueda">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Grid de Tarjetas de Contenido -->
    <div id="watchlist-grid" class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-sm-4">
        <!-- Se puebla reactivamente con JavaScript -->
    </div>

    <!-- Estado Vacío (Lista completamente vacía) -->
    <div id="watchlist-empty-state" class="col-12 py-5 text-center d-none">
        <div class="card bg-dark border-secondary p-4 p-md-5 mx-auto" style="max-width: 500px; border-radius: 14px;">
            <div class="mb-3">
                <i class="bi bi-bookmark-plus fs-1 text-secondary"></i>
            </div>
            <h5 class="text-white fw-bold mb-2">Tu lista está vacía</h5>
            <p class="text-secondary small mb-4">
                No tienes películas ni series guardadas. Añade títulos tocando el icono de marcador en cualquier tarjeta o banner para encontrarlos rápidamente aquí.
            </p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="index.php" class="btn btn-outline-danger btn-sm rounded-pill px-4">
                    <i class="bi bi-compass me-1"></i> Explorar Catálogo
                </a>
                <a href="index.php#section-popular-rakun" class="btn btn-outline-success btn-sm rounded-pill px-3">
                    <i class="fas fa-cloud me-1"></i> Popular en Rakun
                </a>
            </div>
        </div>
    </div>

    <!-- Estado Sin Coincidencias en Filtro -->
    <div id="watchlist-no-matches" class="col-12 py-5 text-center d-none">
        <div class="card bg-dark border-secondary p-4 mx-auto" style="max-width: 440px; border-radius: 14px;">
            <i class="bi bi-funnel text-secondary fs-2 mb-2"></i>
            <h6 class="text-white fw-bold mb-1">Sin coincidencias</h6>
            <p class="text-secondary small mb-3">No hay elementos en tu lista que coincidan con los filtros aplicados.</p>
            <button type="button" id="watchlist-reset-filter" class="btn btn-outline-light btn-sm rounded-pill px-3 align-self-center">
                Restablecer Filtros
            </button>
        </div>
    </div>
</div>

<?php
require_once 'includes/templates/footer.php';
?>
