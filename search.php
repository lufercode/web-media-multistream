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

<div class="container mt-4">
    <h1 class="text-white">Resultados de la búsqueda para: "<?= htmlspecialchars($query) ?>"</h1>
    <?php include 'includes/templates/_search_results.php'; ?>
</div>

<?php
require_once 'includes/templates/footer.php';
?>