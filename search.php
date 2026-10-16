<?php
require_once 'includes/bootstrap.php';
require_once 'includes/templates/header.php';

$query = $_GET['q'] ?? null;

if ($query) {
    $search_results = get_tmdb_data('search/multi', ['query' => $query])['results'] ?? [];
} else {
    $search_results = [];
}

?>

<div class="container my-3 my-md-4 px-2 px-sm-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm py-1 px-3 rounded-pill d-inline-flex align-items-center">
            <i class="fas fa-arrow-left me-1.5"></i> Volver al Catálogo
        </a>
        <?php if (!empty($search_results)): ?>
            <span class="badge bg-dark border border-secondary text-secondary px-3 py-1.5 rounded-pill">
                <i class="bi bi-film me-1 text-danger"></i> <?= count($search_results) ?> resultados
            </span>
        <?php endif; ?>
    </div>

    <h2 class="text-white fs-4 fw-bold mb-3 d-flex align-items-center flex-wrap gap-2 border-0 p-0 m-0">
        <span>Resultados para:</span>
        <span class="text-warning">"<?= htmlspecialchars($query ?? '') ?>"</span>
    </h2>

    <div class="mt-3">
        <?php include 'includes/templates/_search_results.php'; ?>
    </div>
</div>

<?php
require_once 'includes/templates/footer.php';
?>