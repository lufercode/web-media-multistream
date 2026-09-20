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
            echo render_content_card($item, $item['media_type'], false);
        endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <p class="text-white">No se encontraron resultados.</p>
        </div>
    <?php endif; ?>
</div>