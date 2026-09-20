<?php
require_once 'includes/bootstrap.php';
require_once 'includes/templates/header.php';

// Obtener datos destacados y populares de TMDB
$hero = get_hero_featured_content();
$popular_movies = get_popular_content('movie', 15);
$popular_series = get_popular_content('tv', 15);
$popular_animation = get_popular_animation(15);

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

<div class="container-fluid px-lg-4 px-3">

    <!-- Hero Banner Cinematográfico Principal -->
    <?php if (!empty($hero) && !empty($hero['backdrop'])): ?>
        <section class="hero-banner">
            <img src="<?= htmlspecialchars($hero['backdrop']) ?>" 
                 alt="<?= htmlspecialchars($hero['title']) ?>" 
                 class="hero-backdrop" 
                 loading="eager"
                 fetchpriority="high">
            <div class="hero-gradient-overlay"></div>
            <div class="hero-content">
                <span class="hero-tag">
                    <i class="bi bi-fire"></i> Tendencia de Hoy
                </span>
                <h1 class="hero-title"><?= htmlspecialchars($hero['title']) ?></h1>
                <div class="hero-meta text-light">
                    <?php if ($hero['rating']): ?>
                        <span class="badge bg-warning text-dark fw-bold px-2 py-1">
                            <i class="bi bi-star-fill me-1"></i><?= $hero['rating'] ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($hero['year']): ?>
                        <span class="badge bg-dark border border-secondary px-2 py-1"><?= $hero['year'] ?></span>
                    <?php endif; ?>
                    <span class="badge bg-secondary px-2 py-1"><?= $hero['type'] === 'movie' ? 'Película' : 'Serie' ?></span>
                </div>
                <p class="hero-desc"><?= htmlspecialchars($hero['overview']) ?></p>
                <div class="hero-actions">
                    <a href="details.php?id=<?= $hero['id'] ?>&type=<?= $hero['type'] ?>" class="btn-hero-play">
                        <i class="bi bi-play-fill fs-5"></i> Ver Ahora
                    </a>
                    <a href="details.php?id=<?= $hero['id'] ?>&type=<?= $hero['type'] ?>" class="btn-hero-info">
                        <i class="bi bi-info-circle fs-5"></i> Más Información
                    </a>
                    <button type="button" 
                            class="btn-fav-toggle" 
                            data-fav-id="<?= $hero['id'] ?>" 
                            data-fav-type="<?= $hero['type'] ?>" 
                            data-fav-title="<?= htmlspecialchars($hero['title']) ?>" 
                            data-fav-poster="<?= htmlspecialchars($hero['poster']) ?>"
                            data-fav-year="<?= htmlspecialchars($hero['year']) ?>"
                            data-fav-rating="<?= htmlspecialchars((string)$hero['rating']) ?>"
                            title="Añadir a Mi Lista">
                        <i class="bi bi-bookmark-plus"></i>
                    </button>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Fila de Mi Lista (Se puebla reactivamente vía JavaScript desde localStorage) -->
    <div id="watchlist-container" style="display: none;">
        <?= render_carousel_row('Mi Lista', 'watchlist', '', 'Guardados') ?>
    </div>

    <!-- Carrusel: Continuar Viendo / Visto Recientemente -->
    <?php if (!empty($recently_watched_details)): ?>
        <?php
        $recent_cards_html = '';
        foreach ($recently_watched_details as $recent_item) {
            $recent_cards_html .= render_content_card($recent_item['details'], $recent_item['type'], true);
        }
        echo render_carousel_row('Continuar Viendo', 'recent-watched', $recent_cards_html, 'Historial');
        ?>
    <?php endif; ?>

    <!-- Carrusel: Películas Populares -->
    <?php
    $movies_cards_html = '';
    if (!empty($popular_movies)) {
        foreach ($popular_movies as $movie) {
            $movies_cards_html .= render_content_card($movie, 'movie', true);
        }
    }
    echo render_carousel_row('Películas Populares', 'popular-movies', $movies_cards_html, 'Tendencias');
    ?>

    <!-- Carrusel: Series Populares -->
    <?php
    $series_cards_html = '';
    if (!empty($popular_series)) {
        foreach ($popular_series as $serie) {
            $series_cards_html .= render_content_card($serie, 'tv', true);
        }
    }
    echo render_carousel_row('Series Populares', 'popular-series', $series_cards_html, 'Tendencias');
    ?>

    <!-- Carrusel: Películas de Animación -->
    <?php
    $anim_movies_html = '';
    if (!empty($popular_animation['movies'])) {
        foreach ($popular_animation['movies'] as $movie) {
            $anim_movies_html .= render_content_card($movie, 'movie', true);
        }
    }
    echo render_carousel_row('Películas de Animación', 'animation-movies', $anim_movies_html, 'Anime & Más');
    ?>

    <!-- Carrusel: Series de Animación -->
    <?php
    $anim_series_html = '';
    if (!empty($popular_animation['series'])) {
        foreach ($popular_animation['series'] as $serie) {
            $anim_series_html .= render_content_card($serie, 'tv', true);
        }
    }
    echo render_carousel_row('Series de Animación', 'animation-series', $anim_series_html, 'Anime & Más');
    ?>

<?php
require_once 'includes/templates/footer.php';
?>