<?php
require_once 'includes/bootstrap.php';
require_once 'includes/templates/header.php';

$id = $_GET['id'] ?? null;
$type = $_GET['type'] ?? null;

if (!$id || !$type) {
    echo "<div class='container mt-5 text-white'><div class='alert alert-danger'>Contenido no válido o no especificado.</div></div>";
    require_once 'includes/templates/footer.php';
    exit;
}

$details = get_content_details($id, $type);

if (empty($details)) {
    echo "<div class='container mt-5 text-white'><div class='alert alert-warning'>No se encontraron detalles para este contenido en TMDB.</div></div>";
    require_once 'includes/templates/footer.php';
    exit;
}

$title = $details['title'] ?? $details['name'] ?? 'Sin título';
$overview = $details['overview'] ?? 'Sin descripción disponible.';
$poster = !empty($details['poster_path']) ? "https://image.tmdb.org/t/p/w500{$details['poster_path']}" : 'assets/img/no-poster.jpg';
$backdrop = !empty($details['backdrop_path']) ? "https://image.tmdb.org/t/p/original{$details['backdrop_path']}" : null;
$rating = isset($details['vote_average']) ? round($details['vote_average'], 1) : null;
$release_year = !empty($details['release_date']) ? substr($details['release_date'], 0, 4) : (!empty($details['first_air_date']) ? substr($details['first_air_date'], 0, 4) : null);
$genres = array_map(fn($g) => $g['name'], $details['genres'] ?? []);

$seasons_count = $details['number_of_seasons'] ?? null;
if ($type === 'tv' && $id) {
    $canonical_eg = get_tmdb_episode_groups_seasons($id);
    if ($canonical_eg && !empty($canonical_eg['groups'])) {
        $canonical_seasons = array_filter($canonical_eg['groups'], function($g) {
            $name = strtolower($g['name'] ?? '');
            return ($g['order'] ?? 0) > 0 && strpos($name, 'special') === false && strpos($name, 'especial') === false;
        });
        if (count($canonical_seasons) > ($seasons_count ?? 0)) {
            $seasons_count = count($canonical_seasons);
        }
    }
}
$runtime = isset($details['runtime']) ? $details['runtime'] . ' min' : ($seasons_count ? $seasons_count . ' temporadas' : null);

$is_anime = false;
foreach ($details['genres'] ?? [] as $g) {
    if (($g['id'] ?? 0) === 16 || stripos($g['name'] ?? '', 'animaci') !== false || stripos($g['name'] ?? '', 'animation') !== false) {
        $is_anime = true;
        break;
    }
}
?>

<div class="container my-4 text-white">
    <!-- Breadcrumb / Volver -->
    <div class="mb-3">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Volver al Catálogo
        </a>
    </div>

    <!-- Ficha de Detalles con Fondo Cinematográfico -->
    <div class="card bg-dark border-secondary shadow-lg overflow-hidden mb-4 details-hero-card" <?= $backdrop ? 'style="background: linear-gradient(to right, rgba(16, 18, 27, 0.96) 25%, rgba(16, 18, 27, 0.88) 60%, rgba(16, 18, 27, 0.94) 100%), url(\'' . htmlspecialchars($backdrop) . '\') center/cover no-repeat;"' : '' ?>>
        <div class="row g-0 align-items-center align-items-md-stretch">
            <div class="col-12 col-md-4 col-lg-3 text-center details-poster-col p-3">
                <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" class="img-fluid rounded shadow details-poster-img" style="max-height: 450px; object-fit: cover;">
            </div>
            <div class="col-12 col-md-8 col-lg-9 p-3 p-md-4 d-flex flex-column justify-content-between details-info-col">
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <h2 class="mb-0 fw-bold text-white"><?= htmlspecialchars($title) ?></h2>
                        <?php if ($release_year): ?>
                            <span class="badge bg-secondary fs-6"><?= htmlspecialchars($release_year) ?></span>
                        <?php endif; ?>
                        <?php if ($is_anime): ?>
                            <span class="badge bg-danger text-uppercase"><i class="fas fa-dragon me-1"></i> Anime <?= $type === 'movie' ? '(Película)' : '(Serie)' ?></span>
                        <?php else: ?>
                            <span class="badge bg-primary text-uppercase"><?= $type === 'movie' ? 'Película' : 'Serie' ?></span>
                        <?php endif; ?>
                        <?php if ($rating): ?>
                            <span class="badge bg-warning text-dark fs-6"><i class="fas fa-star text-dark me-1"></i> <?= $rating ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Botón de Mi Lista (Guardar en Favoritos) -->
                    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                        <button type="button" 
                                class="btn btn-outline-light btn-sm btn-fav-toggle rounded-pill px-3 py-1.5 shadow-sm d-inline-flex align-items-center gap-2 details-fav-btn"
                                data-fav-id="<?= htmlspecialchars($id) ?>" 
                                data-fav-type="<?= htmlspecialchars($type) ?>" 
                                data-fav-title="<?= htmlspecialchars($title) ?>" 
                                data-fav-poster="<?= htmlspecialchars($poster) ?>"
                                data-fav-year="<?= htmlspecialchars($release_year ?? '') ?>"
                                data-fav-rating="<?= htmlspecialchars((string)($rating ?? '')) ?>">
                            <i class="bi bi-bookmark-plus"></i> <span class="fav-btn-text">Añadir a Mi Lista</span>
                        </button>
                    </div>

                    <?php if (!empty($genres) || $runtime): ?>
                        <div class="text-secondary small mb-3" style="color: #cbd5e1 !important;">
                            <?php if ($runtime): ?>
                                <span class="me-3"><i class="far fa-clock me-1 text-info"></i> <?= htmlspecialchars($runtime) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($genres)): ?>
                                <span><i class="fas fa-tags me-1 text-warning"></i> <?= htmlspecialchars(implode(', ', $genres)) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h6 class="text-info fw-bold mb-2">Sinopsis:</h6>
                    <p class="text-light lead fs-6 lh-base mb-3"><?= nl2br(htmlspecialchars($overview)) ?></p>
                </div>

                <div class="mt-2 pt-3 border-top border-secondary text-secondary small">
                    <span><i class="fas fa-satellite-dish text-success me-1"></i> Búsqueda multi-fuente activa (CDN, Streaming y Torrents).</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección de Enlaces y Fuentes -->
    <div class="card bg-dark border-secondary shadow-lg p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0 text-warning"><i class="fas fa-link me-2"></i> Servidores y Enlaces Disponibles</h4>
            <span class="badge bg-secondary">Multi-Servidor</span>
        </div>
        
        <div id="enlaces-container" data-id="<?= htmlspecialchars($id) ?>" data-type="<?= htmlspecialchars($type) ?>" data-backdrop="<?= htmlspecialchars($backdrop ?? '') ?>" data-poster="<?= htmlspecialchars($poster) ?>" data-is-anime="<?= $is_anime ? '1' : '0' ?>">
            <div class="p-4 text-center my-3 bg-dark border border-secondary rounded">
                <div class="spinner-border text-info mb-2" role="status"></div>
                <p class="mb-0 text-light">Consultando fuentes en servidores...</p>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/hls.min.js?v=<?= file_exists(__DIR__ . '/assets/js/hls.min.js') ? filemtime(__DIR__ . '/assets/js/hls.min.js') : '1.5.8' ?>"></script>
<script src="assets/js/artplayer.js?v=<?= file_exists(__DIR__ . '/assets/js/artplayer.js') ? filemtime(__DIR__ . '/assets/js/artplayer.js') : '5.4.0' ?>"></script>
<script src="assets/js/details_loader.js?v=<?= file_exists(__DIR__ . '/assets/js/details_loader.js') ? filemtime(__DIR__ . '/assets/js/details_loader.js') : time() ?>" defer></script>

<?php require_once 'includes/templates/footer.php'; ?>