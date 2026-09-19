<?php
require_once 'includes/bootstrap.php';
require_once 'includes/templates/header.php';

// Obtener datos de TMDB.
$popular_series = get_popular_content('tv');
$popular_movies = get_popular_content('movie');
$popular_animation = get_popular_animation();

// Obtener series recientemente vistas
$recently_watched_series_ids = get_recently_watched_series();
$recently_watched_details = [];
foreach ($recently_watched_series_ids as $item_info) {
    $details = get_content_details($item_info['id'], $item_info['type']);
    if (!empty($details)) {
        $recently_watched_details[] = [
            'details' => $details,
            'type' => $item_info['type']
        ];
    }
}
?>

<div class="container mt-4">
    <div class="card mb-4 bg-dark text-white border-0">
        <div class="card-body">
            <form action="search.php" method="get" class="d-flex">
                <input id="search-rakun" type="text" name="q" class="form-control mb-2" placeholder="Buscar..." required>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i>
                </button>

            </form>
        </div>
    </div>

    <?php if (!empty($recently_watched_details)): ?>
        <h2 class="text-white mt-5">Visto Recientemente</h2>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-4">
            <?php foreach ($recently_watched_details as $recent_item): ?>
                <?php echo render_content_card($recent_item['details'], $recent_item['type']); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="text-white mt-5">Series Populares</h2>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-4">
        <?php if (!empty($popular_series)): ?>
            <?php foreach ($popular_series as $serie): ?>
                <?php echo render_content_card($serie, 'tv'); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-white">No se pudieron cargar las series populares.</p>
        <?php endif; ?>
    </div>

    <h2 class="text-white mt-5">Películas Populares</h2>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-4">
        <?php if (!empty($popular_movies)): ?>
            <?php foreach ($popular_movies as $movie): ?>
                <?php echo render_content_card($movie, 'movie'); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-white">No se pudieron cargar las películas populares.</p>
        <?php endif; ?>
    </div>

    <h2 class="text-white mt-5">Series de Animación Populares</h2>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-4">
        <?php if (!empty($popular_animation['series'])): ?>
            <?php foreach ($popular_animation['series'] as $serie): ?>
                <?php echo render_content_card($serie, 'tv'); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-white">No se pudieron cargar las series de animación populares.</p>
        <?php endif; ?>
    </div>

    <h2 class="text-white mt-5">Películas de Animación Populares</h2>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-4">
        <?php if (!empty($popular_animation['movies'])): ?>
            <?php foreach ($popular_animation['movies'] as $movie): ?>
                <?php echo render_content_card($movie, 'movie'); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-white">No se pudieron cargar las películas de animación populares.</p>
        <?php endif; ?>
    </div>
</div>

<?php

require_once 'includes/templates/footer.php';
?>