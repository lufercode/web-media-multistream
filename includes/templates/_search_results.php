<?php
// This file is responsible for displaying the search results.
// The `$search_results` variable must be defined in the including file.
?>
<div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-sm-4">
    <?php if (!empty($search_results)): ?>
        <?php foreach ($search_results as $item):
            if (empty($item['id']) || empty($item['media_type']) || empty($item['poster_path'])) {
                continue;
            }
            echo render_content_card($item, $item['media_type'], false);
        endforeach; ?>
    <?php else: ?>
        <div class="col-12 py-5 text-center">
            <div class="card bg-dark border-secondary p-4 p-md-5 mx-auto" style="max-width: 500px; border-radius: 14px;">
                <i class="bi bi-search fs-1 text-secondary mb-3"></i>
                <h5 class="text-white fw-bold mb-2">No se encontraron resultados</h5>
                <p class="text-secondary small mb-3">Intenta buscar con otro término, nombre original o palabra clave.</p>
                <a href="index.php" class="btn btn-outline-danger btn-sm rounded-pill px-4 align-self-center">
                    <i class="bi bi-compass me-1"></i> Explorar Catálogo
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>