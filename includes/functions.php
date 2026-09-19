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
 * Genera el HTML para una tarjeta de contenido (película o serie).
 *
 * @param array<string, mixed> $item Array con los datos de la película/serie de TMDB.
 * @param 'movie'|'tv' $type El tipo de contenido.
 * @return string El HTML de la tarjeta.
 */
function render_content_card(array $item, string $type): string
{
    if (empty($item['poster_path'])) {
        return '';
    }

    $id = htmlspecialchars((string)$item['id']);
    $title = htmlspecialchars($type === 'movie' ? $item['title'] : $item['name']);
    $posterPath = htmlspecialchars($item['poster_path']);
    $year = htmlspecialchars(substr($item['release_date'] ?? $item['first_air_date'] ?? '', 0, 4));

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
                $watched_indicator = '<span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-primary" style="font-size: 0.8em;">';
                if ($total_episodes_watched > 0 && $total_episodes_partially_watched === 0) {
                    $watched_indicator .= '<i class="fas fa-check-circle"></i> Visto';
                } elseif ($total_episodes_partially_watched > 0) {
                    $watched_indicator .= '<i class="fas fa-eye"></i> Parcial';
                }
                $watched_indicator .= '</span>';
            }
        }
    } elseif ($type === 'movie') {
        $mov_st = $progress['movie'][$id] ?? null;
        if (is_array($mov_st)) $mov_st = $mov_st['status'] ?? null;
        if ($mov_st === 'watched') {
            $watched_indicator = '<span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-success" style="font-size: 0.8em;"><i class="fas fa-check-circle"></i> Visto</span>';
        } elseif ($mov_st === 'partially_watched') {
            $watched_indicator = '<span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-info" style="font-size: 0.8em;"><i class="fas fa-eye"></i> Parcial</span>';
        }
    }

    return <<<HTML
    <div class="col" data-query="{$title}" data-type="{$type}" data-id="{$id}" data-year="{$year}">
        <a href="details.php?id={$id}&type={$type}" class="text-decoration-none">
            <div class="card h-100 bg-dark text-white border-0 position-relative">
                <img src="https://image.tmdb.org/t/p/w500{$posterPath}" class="card-img-top rounded" alt="{$title}">
                <div class="card-body p-2">
                    <h5 class="card-title text-truncate mb-0">{$title}</h5>
                </div>
                {$watched_indicator}
                <span class="search-status-icon position-absolute top-0 end-0 p-2 text-warning" style="font-size: 1.5rem;">
                    <i class="fas fa-spinner fa-spin"></i>
                </span>
            </div>
        </a>
    </div>
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
