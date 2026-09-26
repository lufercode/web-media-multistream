<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscador de Contenido</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= file_exists(__DIR__ . '/../../assets/css/style.css') ? filemtime(__DIR__ . '/../../assets/css/style.css') : time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Google Identity Services (Sign In with Google / OAuth 2.0 Web) -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>

<body>
<?php
$current_identity = get_current_identity();
$is_authenticated = ($current_identity['type'] === 'user');
$has_google_client_id = defined('GOOGLE_CLIENT_ID') && strpos(GOOGLE_CLIENT_ID, 'TU_GOOGLE_CLIENT_ID') === false && !empty(GOOGLE_CLIENT_ID);
?>

    <header class="py-2 px-3 mb-4 sticky-top navbar-glass">
        <div class="container-fluid px-lg-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <!-- Marca / Logo -->
                <a href="index.php" class="navbar-brand d-flex align-items-center text-decoration-none me-2">
                    <i class="bi bi-play-circle-fill text-danger fs-3 me-2"></i>
                    <span class="brand-logo-text">StreamMedia</span>
                </a>

                <!-- Navegación de categorías -->
                <nav class="d-none d-md-flex align-items-center gap-1">
                    <a href="index.php" class="nav-pill-link"><i class="bi bi-house-door me-1"></i> Inicio</a>
                    <a href="index.php#section-popular-movies" class="nav-pill-link"><i class="bi bi-film me-1"></i> Películas</a>
                    <a href="index.php#section-popular-series" class="nav-pill-link"><i class="bi bi-tv me-1"></i> Series</a>
                    <a href="index.php#section-animation-movies" class="nav-pill-link"><i class="bi bi-stars me-1"></i> Animación</a>
                    <a href="index.php#watchlist-container" class="nav-pill-link">
                        <i class="bi bi-bookmark-fill me-1"></i> Mi Lista
                        <span class="badge bg-danger rounded-pill ms-1 nav-watchlist-count" style="display:none; font-size: 0.68rem;">0</span>
                    </a>
                </nav>

                <!-- Buscador en tiempo real y acciones -->
                <div class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0 justify-content-end">
                    <form action="search.php" method="get" class="header-search-wrap position-relative">
                        <input id="search-rakun" 
                               type="text" 
                               name="q" 
                               class="form-control search-input-modern" 
                               placeholder="Buscar películas, series..." 
                               required 
                               autocomplete="off">
                        <kbd class="kbd-shortcut" title="Presiona '/' para buscar">/</kbd>
                    </form>

<?php
$stremio_addon_base_url = defined('TORRENT_STREAMER_REMOTE_URL') && !empty(TORRENT_STREAMER_REMOTE_URL)
    ? rtrim(TORRENT_STREAMER_REMOTE_URL, '/')
    : 'https://web-media-multistream.onrender.com';
?>
                    <button type="button" 
                            class="btn btn-sm d-flex align-items-center gap-1 px-2.5 py-1 fw-semibold text-white shadow-sm"
                            style="background: linear-gradient(135deg, #7b2cbf 0%, #5a189a 100%); border: 1px solid rgba(157, 78, 221, 0.65); border-radius: 8px;"
                            data-bs-toggle="modal" 
                            data-bs-target="#stremioAddonModal"
                            title="Configurar e Instalar Addon en Stremio">
                        <i class="fas fa-puzzle-piece" style="color: #e0aaff;"></i>
                        <span class="d-none d-sm-inline" style="font-size: 0.8rem;">Addon Stremio</span>
                    </button>

                    <form action="clear_cache.php" method="post" class="d-inline">
                        <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1" title="Limpiar Caché de la aplicación">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </form>

                    <!-- Identidad de Usuario / Dispositivo y Google Auth -->
                    <?php if ($is_authenticated): ?>
                        <div class="dropdown">
                            <button class="btn btn-user-profile dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Perfil de <?= htmlspecialchars($current_identity['name']) ?>">
                                <?php if (!empty($current_identity['picture'])): ?>
                                    <img src="<?= htmlspecialchars($current_identity['picture']) ?>" alt="Avatar" class="rounded-circle" style="width: 24px; height: 24px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 0.75rem;">
                                        <?= strtoupper(substr($current_identity['name'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <span class="d-none d-lg-inline text-white" style="font-size: 0.82rem; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= htmlspecialchars($current_identity['name']) ?>
                                </span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark shadow border-secondary py-2" style="font-size: 0.85rem; min-width: 210px;">
                                <li class="px-3 py-1">
                                    <div class="fw-bold text-white text-truncate"><?= htmlspecialchars($current_identity['name']) ?></div>
                                    <div class="text-secondary text-truncate" style="font-size: 0.74rem;"><?= htmlspecialchars($current_identity['email']) ?></div>
                                </li>
                                <li class="px-3 py-1">
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success" style="font-size: 0.72rem;">
                                        <i class="fas fa-cloud me-1"></i>Sincronizado en la nube
                                    </span>
                                </li>
                                <li><hr class="dropdown-divider border-secondary my-1"></li>
                                <li>
                                    <a class="dropdown-item py-1" href="index.php#watchlist-container">
                                        <i class="bi bi-bookmark-fill me-2 text-danger"></i>Mi Lista
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-1 text-danger" href="api/auth_logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>Cerrar sesión
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <button type="button" class="btn btn-auth-signin d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#authModal" title="Acceder con Google para sincronizar tus dispositivos">
                            <i class="fab fa-google text-danger"></i>
                            <span class="d-none d-sm-inline">Acceder</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Modal de Autenticación y Sincronización con Google -->
    <div class="modal fade" id="authModal" tabindex="-1" aria-labelledby="authModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
            <div class="modal-content bg-dark text-white border-secondary shadow-lg">
                <div class="modal-header border-secondary py-2 px-3 bg-black">
                    <h6 class="modal-title d-flex align-items-center gap-2" id="authModalLabel">
                        <i class="fab fa-google text-danger"></i> Sincronización en la Nube
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="mb-3">
                        <div class="d-inline-flex p-3 rounded-circle bg-secondary bg-opacity-25 mb-2">
                            <i class="fas fa-laptop-house text-info fs-1"></i>
                        </div>
                        <h5 class="fw-bold mb-1">Continúa viendo en cualquier pantalla</h5>
                        <p class="text-secondary small mb-0">
                            Inicia sesión con tu cuenta de Google para sincronizar tu historial de episodios, películas vistas y "Mi Lista" en tu PC, móvil y tablet.
                        </p>
                    </div>

                    <div class="card bg-black border-secondary p-2 mb-3 text-start small">
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="fas fa-shield-alt text-success"></i>
                            <span>Estado actual: <strong class="text-white">Modo Dispositivo Local</strong></span>
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem; margin-top: 2px;">
                            Tus reproducciones se guardan de forma aislada en este navegador.
                        </div>
                    </div>

                    <?php if ($has_google_client_id): ?>
                        <!-- Botón Oficial de Google Identity Services -->
                        <div id="g_id_onload"
                             data-client_id="<?= htmlspecialchars(GOOGLE_CLIENT_ID) ?>"
                             data-context="signin"
                             data-ux_mode="popup"
                             data-callback="handleGoogleCredentialResponse"
                             data-auto_prompt="false">
                        </div>
                        <div class="g_id_signin d-flex justify-content-center my-3"
                             data-type="standard"
                             data-shape="pill"
                             data-theme="filled_black"
                             data-text="signin_with"
                             data-size="large"
                             data-logo_alignment="left">
                        </div>
                    <?php else: ?>
                        <!-- Guía de configuración rápida si aún no se coloca el Client ID real -->
                        <div class="alert alert-info text-start small mb-3 border-info">
                            <strong><i class="fas fa-info-circle me-1"></i>Configuración de Google Client ID:</strong>
                            <div class="mt-1" style="font-size: 0.78rem;">
                                Para activar el botón oficial de Google en tu instalación local:
                                <ol class="ps-3 mb-1 mt-1">
                                    <li>Entra a <a href="https://console.cloud.google.com" target="_blank" class="text-info fw-bold">Google Cloud Console</a> (gratis).</li>
                                    <li>Crea una credencial <em>"ID de cliente de OAuth 2.0"</em> para <strong>Aplicación web</strong>.</li>
                                    <li>En <em>Orígenes autorizados</em> agrega <code>http://localhost</code>.</li>
                                    <li>Pega tu Client ID en <code>config/config.php</code> en <code>GOOGLE_CLIENT_ID</code>.</li>
                                </ol>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-secondary py-2 px-3 bg-black d-flex justify-content-between">
                    <span class="text-muted" style="font-size: 0.72rem;"><i class="fas fa-lock me-1"></i>Autenticación oficial y segura por Google</span>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function handleGoogleCredentialResponse(response) {
        if (!response || !response.credential) return;
        
        const modalBody = document.querySelector('#authModal .modal-body');
        if (modalBody) {
            modalBody.innerHTML = '<div class="py-4 text-center"><i class="fas fa-spinner fa-spin fa-2x text-primary mb-2"></i><p class="small text-white">Sincronizando cuenta con Google...</p></div>';
        }

        fetch('api/auth_google.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ credential: response.credential })
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.reload();
            } else {
                alert(data.message || 'Error al autenticar con Google');
                window.location.reload();
            }
        })
        .catch(err => {
            console.error('Error Google Auth:', err);
            alert('Error de conexión al conectar con el servidor.');
            window.location.reload();
        });
    }
    </script>

    <!-- Modal Configurador de Addon para Stremio (Alojado en Render) -->
    <div class="modal fade" id="stremioAddonModal" tabindex="-1" aria-labelledby="stremioAddonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark text-white border-secondary shadow-lg">
                <div class="modal-header border-secondary py-2.5 px-3" style="background: linear-gradient(90deg, #1a0933 0%, #0f172a 100%);">
                    <div>
                        <h6 class="modal-title fw-bold d-flex align-items-center gap-2 mb-0" id="stremioAddonModalLabel">
                            <i class="fas fa-puzzle-piece" style="color: #c77dff;"></i>
                            Configurador de Addon para Stremio
                            <span class="badge rounded-pill" style="background-color: #7b2cbf; font-size: 0.68rem;">v2.4 Cloud</span>
                        </h6>
                        <div class="text-secondary" style="font-size: 0.73rem;">
                            Elige los scrapers e idiomas que deseas agregar a tu Stremio (PC, Android y Android TV)
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-3 p-sm-4">
                    <!-- Explicación de arquitectura P2P / HLS -->
                    <div class="alert py-2 px-3 mb-3 border-0 d-flex align-items-start gap-2" style="background: rgba(123, 44, 191, 0.15); border-left: 3px solid #9d4edd !important; font-size: 0.78rem;">
                        <i class="fas fa-bolt text-warning mt-1"></i>
                        <div>
                            <strong class="text-white">¿Cómo funciona con Stremio?</strong>
                            <span class="text-light d-block">
                                Render realiza únicamente el <em>scraping</em> rápido de enlaces (~2 seg). Cuando eliges un <strong>Torrent</strong>, <strong>el motor BitTorrent interno de Stremio descarga el video directamente en tu dispositivo</strong> (0% consumo de CPU/RAM en Render). Y en los servidores <strong>HLS (.m3u8)</strong>, Stremio reproduce el stream directo.
                            </span>
                        </div>
                    </div>

                    <!-- 1. Idiomas Preferidos -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-info small mb-1.5 d-flex align-items-center gap-1">
                            <i class="fas fa-globe-americas"></i> 1. Idiomas a mostrar en Stremio:
                        </label>
                        <div class="row g-2">
                            <div class="col-6 col-sm-3">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.8rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-lang-chk" value="latino" checked>
                                    <span>🇲🇽 Español Latino</span>
                                </label>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.8rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-lang-chk" value="castellano" checked>
                                    <span>🇪🇸 Castellano</span>
                                </label>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.8rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-lang-chk" value="multi" checked>
                                    <span>🌐 Multi-Audio</span>
                                </label>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.8rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-lang-chk" value="sub" checked>
                                    <span>🇯🇵/🇺🇸 Subtitulado</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Scrapers de Streaming Directo / HLS -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1.5">
                            <label class="form-label fw-bold text-warning small mb-0 d-flex align-items-center gap-1">
                                <i class="fas fa-play-circle"></i> 2. Scrapers de Streaming Directo (.m3u8 / .mp4):
                            </label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.7rem;" onclick="toggleStremioGroup('.stremio-prov-chk', true)">Todos</button>
                                <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.7rem;" onclick="toggleStremioGroup('.stremio-prov-chk', false)">Ninguno</button>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="lamovie" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">⚡ LaMovie / AllCalidad</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Películas, Series y Anime (Vimeos HLS)</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="latanime" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🐉 LatAnime</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Anime en Español Latino y Castellano</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="serieskao" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🎬 SeriesKao</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Películas, Series y Anime (Embed69)</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="pelisplus" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🍿 PelisPlusHD</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Catálogo Latino, Series y Anime</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="cuevana" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">📺 Cuevana 3</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Multi-Servidor HLS Directo</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="poseidonhd" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🔱 PoseidonHD</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Películas y Series en Español</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="local_cdn" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">☁️ CDN Propio</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Rakun Cloud Directo (.mp4/.mkv)</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Scrapers de Torrents P2P (Descargados por Stremio) -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-success small mb-1.5 d-flex align-items-center gap-1">
                            <i class="fas fa-magnet"></i> 3. Scrapers de Torrents P2P (Descarga directa nativa en Stremio):
                        </label>
                        <div class="row g-2">
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="torrentio" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🇲🇽 Torrentio Latino+</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">15 Indexers + Detector Multi-Audio</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="cinecalidad" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🎞️ Cinecalidad</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Películas Dual Latino 1080p / 4K</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="tpb" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🏴‍☠️ ThePirateBay</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Películas y Series P2P</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="yts" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">🎥 YTS Oficial</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Películas 1080p / 4K ligeras</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label class="d-flex align-items-center gap-2 p-2 rounded border border-secondary bg-black bg-opacity-50 w-100" style="cursor:pointer; font-size:0.78rem;">
                                    <input type="checkbox" class="form-check-input mt-0 stremio-prov-chk" value="nyaa" checked>
                                    <div class="text-truncate">
                                        <div class="fw-semibold text-white">⛩️ Nyaa.si</div>
                                        <div class="text-secondary" style="font-size:0.68rem;">Anime P2P 1080p Multi/Sub</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 4. URL del Manifiesto Generado -->
                    <div class="p-2.5 rounded bg-black border border-secondary">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-secondary" style="font-size: 0.72rem;">
                                <i class="fas fa-link me-1 text-info"></i>URL de tu Addon configurado en Render:
                            </span>
                            <span id="stremio-copy-badge" class="badge bg-success" style="display:none; font-size: 0.68rem;">¡Copiado!</span>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" id="stremio-manifest-url" class="form-control bg-dark text-light border-secondary font-monospace" style="font-size: 0.74rem;" readonly value="<?= htmlspecialchars($stremio_addon_base_url) ?>/stremio/default/manifest.json">
                            <button class="btn btn-outline-info" type="button" onclick="copyStremioManifestUrl()" title="Copiar enlace del manifiesto">
                                <i class="fas fa-copy me-1"></i>Copiar URL
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-secondary py-2.5 px-3 bg-black d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="text-secondary" style="font-size: 0.72rem;">
                        <i class="fas fa-tv me-1 text-success"></i>Compatible con Stremio PC, Web, Android y Smart TV
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-light" onclick="copyStremioManifestUrl()">
                            <i class="fas fa-paste me-1"></i>Copiar para pegar en Stremio
                        </button>
                        <a id="stremio-install-btn" href="stremio://<?= htmlspecialchars(preg_replace('#^https?://#i', '', $stremio_addon_base_url)) ?>/stremio/default/manifest.json" class="btn btn-sm fw-bold text-white px-3 shadow" style="background: linear-gradient(135deg, #7b2cbf 0%, #5a189a 100%); border: 1px solid #9d4edd;">
                            <i class="fas fa-rocket me-1"></i> Instalar en Stremio
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const RENDER_BASE = <?= json_encode($stremio_addon_base_url) ?>;
        const ALL_PROV_COUNT = 12;
        const ALL_LANG_COUNT = 4;

        function updateStremioAddonLinks() {
            const provChks = Array.from(document.querySelectorAll('.stremio-prov-chk:checked')).map(c => c.value);
            const langChks = Array.from(document.querySelectorAll('.stremio-lang-chk:checked')).map(c => c.value);

            const selectedProvs = provChks.length > 0 ? provChks : ['lamovie', 'torrentio'];
            const selectedLangs = langChks.length > 0 ? langChks : ['latino', 'castellano', 'multi', 'sub'];

            let configSlug = 'default';
            if (selectedProvs.length < ALL_PROV_COUNT || selectedLangs.length < ALL_LANG_COUNT) {
                configSlug = 'p-' + selectedProvs.join('.') + '_l-' + selectedLangs.join('.');
            }

            const httpsUrl = `${RENDER_BASE.replace(/\/$/, '')}/stremio/${configSlug}/manifest.json`;
            const stremioProtoUrl = httpsUrl.replace(/^https?:\/\//i, 'stremio://');

            const urlInput = document.getElementById('stremio-manifest-url');
            const installBtn = document.getElementById('stremio-install-btn');
            if (urlInput) urlInput.value = httpsUrl;
            if (installBtn) installBtn.href = stremioProtoUrl;
        }

        window.toggleStremioGroup = function(selector, checked) {
            document.querySelectorAll(selector).forEach(el => { el.checked = checked; });
            updateStremioAddonLinks();
        };

        window.copyStremioManifestUrl = function() {
            const urlInput = document.getElementById('stremio-manifest-url');
            if (!urlInput) return;
            navigator.clipboard.writeText(urlInput.value).then(() => {
                const badge = document.getElementById('stremio-copy-badge');
                if (badge) {
                    badge.style.display = 'inline-block';
                    setTimeout(() => { badge.style.display = 'none'; }, 2500);
                }
            }).catch(() => {
                urlInput.select();
                document.execCommand('copy');
            });
        };

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.stremio-prov-chk, .stremio-lang-chk').forEach(chk => {
                chk.addEventListener('change', updateStremioAddonLinks);
            });
            updateStremioAddonLinks();
        });
    })();
    </script>

    <div id="storage-alert-toast" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1200;"></div>