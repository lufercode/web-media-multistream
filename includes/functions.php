<?php
declare(strict_types=1);

/**
 * Este archivo ahora contiene únicamente funciones auxiliares para la interfaz de usuario (frontend),
 * como renderizar tarjetas de contenido o obtener datos para las páginas principales.
 *
 * Carga las librerías base para que las funciones de UI tengan acceso a las utilidades
 * y a la lógica de TMDB sin duplicar código.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/tmdb.php';


/**
 * Obtiene el contenido destacado para el Hero Banner (el contenido #1 en tendencia con imagen panorámica).
 */
function get_hero_featured_content(): ?array
{
    $cache_file = CACHE_DIR . '/hero_featured.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) {
        $cached = json_decode((string)@file_get_contents($cache_file), true);
        if (is_array($cached) && !empty($cached['backdrop'])) {
            return $cached;
        }
    }

    $trending = get_tmdb_data('trending/all/day');
    $items = $trending['results'] ?? [];

    if (empty($items)) {
        $popular = get_popular_content('movie', 10);
        $items = $popular;
    }

    $picked = null;
    foreach ($items as $candidate) {
        if (!empty($candidate['backdrop_path']) && !empty($candidate['overview'])) {
            $picked = $candidate;
            break;
        }
    }

    if (!$picked && !empty($items)) {
        $picked = $items[0];
    }

    if (!$picked) {
        return null;
    }

    $type = $picked['media_type'] ?? (isset($picked['title']) ? 'movie' : 'tv');
    $title = $type === 'movie' ? ($picked['title'] ?? '') : ($picked['name'] ?? '');
    $release_date = $picked['release_date'] ?? $picked['first_air_date'] ?? '';
    $year = !empty($release_date) ? substr($release_date, 0, 4) : '';
    $rating = isset($picked['vote_average']) && $picked['vote_average'] > 0 ? round((float)$picked['vote_average'], 1) : null;
    $backdrop = !empty($picked['backdrop_path']) ? "https://image.tmdb.org/t/p/original{$picked['backdrop_path']}" : '';
    $poster = !empty($picked['poster_path']) ? "https://image.tmdb.org/t/p/w500{$picked['poster_path']}" : '';

    $result = [
        'id' => (int)$picked['id'],
        'type' => $type,
        'title' => $title,
        'overview' => $picked['overview'] ?? 'Sin descripción disponible.',
        'backdrop' => $backdrop,
        'poster' => $poster,
        'rating' => $rating,
        'year' => $year
    ];

    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    @file_put_contents($cache_file, json_encode($result));

    return $result;
}

/**
 * Genera el HTML para una tarjeta de contenido (película o serie) con diseño moderno.
 *
 * @param array<string, mixed> $item Array con los datos de la película/serie de TMDB.
 * @param 'movie'|'tv' $type El tipo de contenido.
 * @param bool $is_carousel Define si se renderiza para carrusel horizontal o grilla estándar.
 * @return string El HTML de la tarjeta.
 */
function render_content_card(array $item, string $type, bool $is_carousel = false): string
{
    if (empty($item['poster_path'])) {
        return '';
    }

    $id = htmlspecialchars((string)$item['id']);
    $title = htmlspecialchars($type === 'movie' ? ($item['title'] ?? 'Sin título') : ($item['name'] ?? 'Sin título'));
    $posterPath = htmlspecialchars($item['poster_path']);
    $year = htmlspecialchars(substr($item['release_date'] ?? $item['first_air_date'] ?? '', 0, 4));
    $rating = isset($item['vote_average']) && $item['vote_average'] > 0 ? number_format((float)$item['vote_average'], 1) : null;

    $watched_indicator = '';
    $progress = read_watched_progress();
    if ($type === 'tv') {
        if (isset($progress[$type]) && isset($progress[$type][$id])) {
            $total_episodes_watched = 0;
            $total_episodes_partially_watched = 0;
            foreach ($progress[$type][$id] as $season_data) {
                foreach ($season_data as $episode_status) {
                    if ($episode_status === 'watched') {
                        $total_episodes_watched++;
                    } elseif ($episode_status === 'partially_watched') {
                        $total_episodes_partially_watched++;
                    }
                }
            }
            if ($total_episodes_watched > 0 || $total_episodes_partially_watched > 0) {
                $watched_indicator = '<span class="badge-watched badge rounded-pill bg-primary">';
                if ($total_episodes_watched > 0 && $total_episodes_partially_watched === 0) {
                    $watched_indicator .= '<i class="fas fa-check-circle me-1"></i>Visto';
                } elseif ($total_episodes_partially_watched > 0) {
                    $watched_indicator .= '<i class="fas fa-eye me-1"></i>Parcial';
                }
                $watched_indicator .= '</span>';
            }
        }
    } elseif ($type === 'movie') {
        $mov_st = $progress['movie'][$id] ?? null;
        if (is_array($mov_st)) $mov_st = $mov_st['status'] ?? null;
        if ($mov_st === 'watched') {
            $watched_indicator = '<span class="badge-watched badge rounded-pill bg-success"><i class="fas fa-check-circle me-1"></i>Visto</span>';
        } elseif ($mov_st === 'partially_watched') {
            $watched_indicator = '<span class="badge-watched badge rounded-pill bg-info"><i class="fas fa-eye me-1"></i>Parcial</span>';
        }
    }

    $rating_badge = $rating !== null
        ? "<span class=\"media-badge rating-badge\"><i class=\"bi bi-star-fill text-warning me-1\"></i>{$rating}</span>"
        : '';

    $year_badge = !empty($year)
        ? "<span class=\"media-badge year-badge\">{$year}</span>"
        : '';

    $type_label = $type === 'movie' ? 'Película' : 'Serie';
    $wrapper_class = $is_carousel ? 'carousel-card-item' : 'col';

    return <<<HTML
    <div class="{$wrapper_class}" data-query="{$title}" data-type="{$type}" data-id="{$id}" data-year="{$year}">
        <div class="media-card card h-100 bg-transparent border-0 position-relative">
            <div class="media-poster-wrap position-relative overflow-hidden rounded">
                <a href="details.php?id={$id}&type={$type}" class="poster-main-link" aria-label="Ver {$title}">
                    <img src="https://image.tmdb.org/t/p/w500{$posterPath}" 
                         class="card-img-top media-poster" 
                         alt="{$title}" 
                         loading="lazy">
                </a>
                
                {$rating_badge}
                {$year_badge}
                {$watched_indicator}

                <span class="badge-availability" style="display: none;">
                    <i class="fas fa-spinner fa-spin me-1"></i>Buscando...
                </span>

                <div class="card-overlay-actions">
                    <a href="details.php?id={$id}&type={$type}" class="poster-overlay-backdrop" aria-label="Ver {$title}"></a>
                    <a href="details.php?id={$id}&type={$type}" class="btn-action-play" title="Reproducir">
                        <i class="bi bi-play-circle-fill"></i>
                    </a>
                    <button type="button" 
                            class="btn-fav-toggle" 
                            data-fav-id="{$id}" 
                            data-fav-type="{$type}" 
                            data-fav-title="{$title}" 
                            data-fav-poster="https://image.tmdb.org/t/p/w500{$posterPath}"
                            data-fav-year="{$year}"
                            data-fav-rating="{$rating}"
                            title="Añadir a Mi Lista">
                        <i class="bi bi-bookmark-plus"></i>
                    </button>
                </div>
            </div>
            <a href="details.php?id={$id}&type={$type}" class="text-decoration-none">
                <div class="card-body p-2">
                    <h6 class="card-title text-truncate mb-1 text-white" title="{$title}">{$title}</h6>
                    <div class="d-flex align-items-center justify-content-between">
                        <small class="text-secondary">{$type_label}</small>
                        <small class="text-secondary">{$year}</small>
                    </div>
                </div>
            </a>
        </div>
    </div>
    HTML;
}

/**
 * Renderiza una sección completa de carrusel horizontal deslizable.
 */
function render_carousel_row(string $title, string $row_id, string $cards_html, ?string $badge_text = null): string
{
    if (empty(trim($cards_html))) {
        return '';
    }

    $badge_html = $badge_text ? "<span class=\"badge bg-danger-subtle text-danger border border-danger-subtle ms-2 px-2 py-1\" style=\"font-size: 0.75rem;\">{$badge_text}</span>" : '';

    return <<<HTML
    <section class="carousel-section mb-4" id="section-{$row_id}">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h3 class="carousel-title text-white fw-bold mb-0 d-flex align-items-center">
                <span>{$title}</span>
                {$badge_html}
            </h3>
            <div class="carousel-nav-controls d-flex gap-1">
                <button type="button" class="btn btn-dark btn-sm carousel-arrow prev" data-target="track-{$row_id}" aria-label="Desplazar hacia la izquierda">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button type="button" class="btn btn-dark btn-sm carousel-arrow next" data-target="track-{$row_id}" aria-label="Desplazar hacia la derecha">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
        <div class="carousel-track-wrapper position-relative">
            <div class="carousel-track" id="track-{$row_id}">
                {$cards_html}
            </div>
        </div>
    </section>
    HTML;
}


// Función para obtener contenido popular, excluyendo la animación (ID 16) de forma eficiente.
function get_popular_content(string $type, int $limit = 10): array
{
    // Usamos el endpoint 'discover' que permite filtrar por género en la propia API.
    // Esto es más eficiente que obtener todos los resultados y filtrarlos en PHP.
    $params = [
        'sort_by' => 'popularity.desc',
        'without_genres' => 16, // ID del género 'Animación'
        'page' => 1
    ];
    $data = get_tmdb_data("discover/{$type}", $params);

    if (!$data) { // Si get_tmdb_data devolvió null
        return [];
    }

    return array_slice($data['results'] ?? [], 0, $limit); // Si no hay 'results', devuelve []
}

// Función corregida para obtener películas y series de animación
function get_popular_animation(int $limit = 10): array
{
    // Obtener series de animación
    $series_data = get_tmdb_data('discover/tv', [
        'with_genres' => 16, // ID del género 'Animación' para series
        'sort_by' => 'popularity.desc'
    ]);
    $popular_animation_series = $series_data ? array_slice($series_data['results'] ?? [], 0, $limit) : [];

    // Obtener películas de animación
    $movies_data = get_tmdb_data('discover/movie', [
        'with_genres' => 16, // ID del género 'Animación' para películas
        'sort_by' => 'popularity.desc'
    ]);
    $popular_animation_movies = $movies_data ? array_slice($movies_data['results'] ?? [], 0, $limit) : [];

    // Devolver un array con ambos tipos de contenido
    return [
        'series' => $popular_animation_series,
        'movies' => $popular_animation_movies
    ];
}

function clear_cache(): int
{
    $files = glob(CACHE_DIR . '/*.json'); // Obtiene todos los archivos .json del directorio de caché
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file); // Elimina cada archivo
        }
    }
    return count($files);
}

function get_content_details(int|string $id, string $type): array
{
    $data = get_tmdb_data("{$type}/{$id}") ?? [];
    if (!empty($data) && empty(trim($data['overview'] ?? ''))) {
        $fallback = get_tmdb_data("{$type}/{$id}", ['language' => 'es-ES']);
        if (!empty(trim($fallback['overview'] ?? ''))) {
            $data['overview'] = $fallback['overview'];
        } else {
            $fallback_en = get_tmdb_data("{$type}/{$id}", ['language' => 'en-US']);
            if (!empty(trim($fallback_en['overview'] ?? ''))) {
                $data['overview'] = $fallback_en['overview'];
            }
        }
    }
    return $data;
}

?>
