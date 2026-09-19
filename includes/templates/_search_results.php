<?php
// This file is responsible for displaying the search results.
// The `$search_results` variable must be defined in the including file.
?>
<div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-4">
    <?php if (!empty($search_results)): ?>
        <?php foreach ($search_results as $item):
            if (empty($item['id']) || empty($item['media_type']) || empty($item['poster_path'])) {
                continue;
            }
            $item_year = substr($item['release_date'] ?? $item['first_air_date'] ?? '', 0, 4);
        ?>
            <div class="col" data-query="<?= htmlspecialchars($item['title'] ?? $item['name']) ?>" data-type="<?= htmlspecialchars($item['media_type']) ?>" data-id="<?= htmlspecialchars($item['id']) ?>" data-year="<?= htmlspecialchars($item_year) ?>">
                <a href="details.php?id=<?= htmlspecialchars($item['id']) ?>&type=<?= htmlspecialchars($item['media_type']) ?>" class="text-decoration-none">
                    <div class="card h-100 bg-dark text-white border-0">
                        <img src="https://image.tmdb.org/t/p/w500<?= htmlspecialchars($item['poster_path']) ?>" class="card-img-top rounded" alt="<?= htmlspecialchars($item['title'] ?? $item['name']) ?>">
                        <div class="card-body p-2">
                            <h5 class="card-title text-truncate mb-0"><?= htmlspecialchars($item['title'] ?? $item['name']) ?></h5>
                            <span class="search-status-icon position-absolute top-0 end-0 p-2 text-warning" style="font-size: 1.5rem;">
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <p class="text-white">No se encontraron resultados.</p>
        </div>
    <?php endif; ?>
</div>