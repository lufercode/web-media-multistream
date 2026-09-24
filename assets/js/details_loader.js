document.addEventListener('DOMContentLoaded', () => {
    const linksContainer = document.getElementById('enlaces-container');
    if (!linksContainer) return;

    const contentId = linksContainer.dataset.id;
    const contentType = linksContainer.dataset.type;

    if (!contentId || !contentType) {
        linksContainer.innerHTML = '<p class="text-danger">Faltan datos para buscar enlaces (ID o tipo).</p>';
        return;
    }

    linksContainer.innerHTML = `
        <div class="p-4 text-center my-3 bg-dark border border-secondary rounded">
            <div class="spinner-border text-info mb-2" role="status"></div>
            <p class="mb-0 text-light">Consultando fuentes disponibles...</p>
            <small class="text-muted">Buscando en Servidores Online, Torrents y CDN</small>
        </div>
    `;

    const updateEpisodeUI = (episodeElement, status) => {
        episodeElement.classList.remove('border-secondary', 'border-success', 'border-info');
        const badge = episodeElement.querySelector('.badge-status');
        if (status === 'watched') {
            episodeElement.classList.add('border-success');
            if (badge) {
                badge.className = 'badge bg-success badge-status';
                badge.innerHTML = '<i class="fas fa-check-circle me-1"></i> Visto';
            }
        } else if (status === 'partially_watched') {
            episodeElement.classList.add('border-info');
            if (badge) {
                badge.className = 'badge bg-info badge-status';
                badge.innerHTML = '<i class="fas fa-eye me-1"></i> Viendo';
            }
        } else {
            episodeElement.classList.add('border-secondary');
            if (badge) {
                badge.className = 'badge bg-secondary badge-status';
                badge.innerText = 'No visto';
            }
        }
    };

    const updateMovieUI = (status) => {
        const badge = document.getElementById('movieStatusBadge');
        if (!badge) return;
        if (status === 'watched') {
            badge.className = 'badge bg-success badge-status';
            badge.innerHTML = '<i class="fas fa-check-circle me-1"></i> Visto';
        } else if (status === 'partially_watched') {
            badge.className = 'badge bg-info badge-status';
            badge.innerHTML = '<i class="fas fa-eye me-1"></i> Viendo';
        } else {
            badge.className = 'badge bg-secondary badge-status';
            badge.innerText = 'No visto';
        }
    };

    const sendProgressUpdate = async (seasonNum, episodeNum, status) => {
        try {
            console.log(`%c📌 [Progreso] Actualizando: ${contentType === 'tv' ? 'S' + seasonNum + 'E' + episodeNum : 'Película'} -> ${status}`, 'color: #0d6efd;');
            
            // Actualizar caché en memoria y localStorage de último visto
            if (contentType === 'tv' && seasonNum && episodeNum) {
                if (!window._watchedProgressCache) window._watchedProgressCache = {};
                if (!window._watchedProgressCache[seasonNum]) window._watchedProgressCache[seasonNum] = {};
                window._watchedProgressCache[seasonNum][episodeNum] = status;
                window._watchedProgressCache._last_watched = {
                    season: parseInt(seasonNum, 10),
                    episode: parseInt(episodeNum, 10),
                    status: status,
                    updated_at: Date.now()
                };
                try {
                    localStorage.setItem(`last_watched_${contentType}_${contentId}`, JSON.stringify(window._watchedProgressCache._last_watched));
                } catch (e) {}
            }

            const response = await fetch('api/update_progress.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content_id: contentId,
                    content_type: contentType,
                    season_num: seasonNum,
                    episode_num: episodeNum,
                    status: status,
                }),
            });
            const data = await response.json();
            if (data.status === 'success') {
                console.log('%c✅ [Progreso] Guardado en servidor.', 'color: #198754;');
            }
        } catch (error) {
            console.error('❌ [Progreso] Error al actualizar:', error);
        }
    };

    const abortController = new AbortController();
    window.addEventListener('beforeunload', () => {
        console.log('%c🛑 [Detalles] Petición cancelada por salida de página.', 'color: #fd7e14;');
        abortController.abort();
    });
    window.addEventListener('pagehide', () => abortController.abort());

    // Estado y variables del reproductor
    let playerTargetCard = null;
    let playerPreModalScrollY = 0;
    let wasPlayerEverMinimized = false;

    // Crear modal de reproductor integrado
    let playerModal = document.getElementById('videoPlayerModal');
    if (!playerModal) {
        playerModal = document.createElement('div');
        playerModal.id = 'videoPlayerModal';
        playerModal.className = 'modal fade';
        playerModal.tabIndex = -1;
        playerModal.setAttribute('data-bs-backdrop', 'static');
        playerModal.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content bg-dark text-white border-secondary shadow-lg">
                    <div class="modal-header border-secondary py-2 px-3 d-flex justify-content-between align-items-center bg-dark" id="playerModalHeader">
                        <div class="flex-grow-1 me-2" style="min-width: 0;" id="playerModalHeaderTitleArea" title="Clic para expandir a pantalla completa">
                            <div class="d-flex align-items-center" style="min-width: 0;">
                                <span class="mini-player-indicator d-none" id="playerMiniIndicator" title="Reproduciendo en segundo plano"></span>
                                <i class="fas fa-play-circle text-success me-2 flex-shrink-0" id="playerModalIcon"></i>
                                <strong class="text-white text-truncate d-block" id="playerModalContentTitle" style="font-size: 0.95rem;" title="Reproductor">Reproductor</strong>
                            </div>
                            <div class="d-flex align-items-center gap-1 mt-1 flex-wrap" id="playerModalBadges" style="font-size: 0.75rem;">
                                <span class="badge bg-secondary">Cargando...</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1 flex-shrink-0">
                            <button type="button" id="btnNextEpisodeModal" class="btn btn-sm btn-outline-success py-1 px-2 d-none align-items-center" title="Reproducir siguiente episodio">
                                <i class="fas fa-step-forward me-1"></i> <span id="btnNextEpisodeText" class="d-none d-sm-inline">Siguiente</span>
                            </button>
                            <a id="btnExternalLink" href="#" target="_blank" class="btn btn-sm btn-outline-secondary text-light py-1 px-2 d-inline-flex align-items-center" title="Abrir en pestaña externa">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                            <button type="button" id="btnRotateModal" class="btn btn-sm btn-outline-warning py-1 px-2 d-inline-flex align-items-center" title="Girar a horizontal (sin desactivar bloqueo en móvil)">
                                <i class="fas fa-sync-alt me-1"></i> <span id="btnRotateText" class="d-none d-sm-inline">Girar</span>
                            </button>
                            <button type="button" id="btnMinimizeModal" class="btn btn-sm btn-outline-info py-1 px-2 d-inline-flex align-items-center" title="Minimizar para seguir navegando">
                                <i class="fas fa-compress-alt me-1"></i> <span id="btnMinimizeText" class="d-none d-sm-inline">Minimizar</span>
                            </button>
                            <button type="button" id="btnMaximizeModal" class="btn btn-sm btn-outline-success py-1 px-2 align-items-center" style="display: none;" title="Maximizar reproductor">
                                <i class="fas fa-expand-alt me-1"></i> <span id="btnMaximizeText" class="d-none d-sm-inline">Maximizar</span>
                            </button>
                            <button type="button" class="btn-close btn-close-white ms-1" id="btnClosePlayerModal" data-bs-dismiss="modal" aria-label="Cerrar" title="Cerrar reproductor"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0 bg-black" style="min-height: 480px; position: relative;">
                        <iframe id="playerIframe" src="" style="width: 100%; height: 520px; border: 0;" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture"></iframe>
                        <div id="artplayerContainer" style="width: 100%; height: 520px; display: none;"></div>
                    </div>
                    <div id="playerAdTipBar" class="py-1 px-3 bg-dark bg-opacity-75 border-top border-secondary d-flex align-items-center justify-content-between text-secondary" style="font-size: 0.76rem;">
                        <span class="d-flex align-items-center text-truncate me-2">
                            <i class="fas fa-lightbulb text-warning me-2 flex-shrink-0"></i>
                            <span class="text-truncate"><strong>Tip:</strong> Si un servidor externo abre pestañas de publicidad, recomendamos usar <strong class="text-info">Brave Browser</strong> o <strong class="text-info">uBlock Origin</strong>.</span>
                        </span>
                        <button type="button" class="btn-close btn-close-white flex-shrink-0" style="font-size: 0.65rem;" onclick="document.getElementById('playerAdTipBar').style.display='none'" title="Ocultar aviso"></button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(playerModal);

        // Controladores de Minimizar / Maximizar (PiP estilo YouTube)
        const minimizePlayer = () => {
            const modalEl = document.getElementById('videoPlayerModal');
            if (!modalEl || !modalEl.classList.contains('show') || modalEl.classList.contains('player-minimized')) return;

            wasPlayerEverMinimized = true;

            // Desactivar rotación virtual si estaba activa
            modalEl.classList.remove('modal-force-landscape');
            if (screen.orientation && typeof screen.orientation.unlock === 'function') {
                try { screen.orientation.unlock(); } catch (e) {}
            }
            const btnRotate = document.getElementById('btnRotateModal');
            if (btnRotate) {
                btnRotate.classList.remove('btn-warning');
                btnRotate.classList.add('btn-outline-warning');
                const btnRotateText = document.getElementById('btnRotateText');
                if (btnRotateText) btnRotateText.textContent = 'Girar';
            }

            modalEl.classList.add('player-minimized');
            document.body.classList.add('has-minimized-player');

            const miniIndicator = document.getElementById('playerMiniIndicator');
            if (miniIndicator) miniIndicator.classList.remove('d-none');

            const icon = document.getElementById('playerModalIcon');
            if (icon) icon.classList.add('d-none');

            const btnMin = document.getElementById('btnMinimizeModal');
            if (btnMin) btnMin.style.setProperty('display', 'none', 'important');

            const btnMax = document.getElementById('btnMaximizeModal');
            if (btnMax) btnMax.style.setProperty('display', 'inline-flex', 'important');

            if (window._currentArtplayer && typeof window._currentArtplayer.resize === 'function') {
                setTimeout(() => window._currentArtplayer.resize(), 100);
            }
        };

        const maximizePlayer = () => {
            const modalEl = document.getElementById('videoPlayerModal');
            if (!modalEl || !modalEl.classList.contains('player-minimized')) return;

            modalEl.classList.remove('player-minimized');
            document.body.classList.remove('has-minimized-player');

            const miniIndicator = document.getElementById('playerMiniIndicator');
            if (miniIndicator) miniIndicator.classList.add('d-none');

            const icon = document.getElementById('playerModalIcon');
            if (icon) icon.classList.remove('d-none');

            const btnMin = document.getElementById('btnMinimizeModal');
            if (btnMin) btnMin.style.removeProperty('display');

            const btnMax = document.getElementById('btnMaximizeModal');
            if (btnMax) btnMax.style.setProperty('display', 'none', 'important');

            if (window._currentArtplayer && typeof window._currentArtplayer.resize === 'function') {
                setTimeout(() => window._currentArtplayer.resize(), 100);
            }
        };

        const btnMin = document.getElementById('btnMinimizeModal');
        if (btnMin) {
            btnMin.addEventListener('click', (e) => {
                e.stopPropagation();
                minimizePlayer();
            });
        }

        const btnMax = document.getElementById('btnMaximizeModal');
        if (btnMax) {
            btnMax.addEventListener('click', (e) => {
                e.stopPropagation();
                maximizePlayer();
            });
        }

        const btnNextModal = document.getElementById('btnNextEpisodeModal');
        if (btnNextModal) {
            btnNextModal.addEventListener('click', (e) => {
                e.stopPropagation();
                playNextEpisode();
            });
        }

        const headerTitleArea = document.getElementById('playerModalHeaderTitleArea');
        if (headerTitleArea) {
            headerTitleArea.addEventListener('click', (e) => {
                const modalEl = document.getElementById('videoPlayerModal');
                if (modalEl && modalEl.classList.contains('player-minimized')) {
                    maximizePlayer();
                }
            });
        }

        // Cerrar modal completamente al presionar botón X
        const btnClose = document.getElementById('btnClosePlayerModal');
        if (btnClose) {
            btnClose.addEventListener('click', (e) => {
                e.stopPropagation();
                const modal = bootstrap.Modal.getInstance(playerModal);
                if (modal) modal.hide();
            });
        }

        // Minimizar automáticamente al hacer clic fuera del reproductor (en el backdrop/fondo oscuro)
        let isMouseDownOutsideModal = false;

        playerModal.addEventListener('mousedown', (e) => {
            isMouseDownOutsideModal = !e.target.closest('.modal-content');
        });

        playerModal.addEventListener('click', (e) => {
            if (isMouseDownOutsideModal && !e.target.closest('.modal-content')) {
                const modalEl = document.getElementById('videoPlayerModal');
                if (modalEl && modalEl.classList.contains('show') && !modalEl.classList.contains('player-minimized')) {
                    minimizePlayer();
                }
            }
            isMouseDownOutsideModal = false;
        });

        playerModal.addEventListener('hidePrevented.bs.modal', (e) => {
            if (e.preventDefault) e.preventDefault();
            const modalEl = document.getElementById('videoPlayerModal');
            if (modalEl && modalEl.classList.contains('show') && !modalEl.classList.contains('player-minimized')) {
                minimizePlayer();
            }
        });

        // Controlador único para girar la pantalla en móvil (rotación virtual CSS)
        const togglePlayerOrientation = () => {
            const modalEl = document.getElementById('videoPlayerModal');
            if (!modalEl) return;

            modalEl.classList.toggle('modal-force-landscape');
            const isRotated = modalEl.classList.contains('modal-force-landscape');

            const btnText = document.getElementById('btnRotateText');
            const label = isRotated ? 'Vertical' : 'Girar';
            if (btnText) btnText.textContent = label;

            const btnModal = document.getElementById('btnRotateModal');
            if (btnModal) {
                if (isRotated) {
                    btnModal.classList.remove('btn-outline-warning');
                    btnModal.classList.add('btn-warning');
                } else {
                    btnModal.classList.remove('btn-warning');
                    btnModal.classList.add('btn-outline-warning');
                }
            }

            // Intentar bloqueo de orientación nativo si el navegador lo soporta
            if (screen.orientation && typeof screen.orientation.lock === 'function') {
                try {
                    if (isRotated) {
                        screen.orientation.lock('landscape').catch(() => {});
                    } else {
                        screen.orientation.unlock();
                    }
                } catch (e) {}
            }
        };

        const btnRotate = document.getElementById('btnRotateModal');
        if (btnRotate) btnRotate.addEventListener('click', togglePlayerOrientation);

        // Limpieza integral al cerrar el modal (normal o minimizado)
        playerModal.addEventListener('hidden.bs.modal', () => {
            const modalEl = document.getElementById('videoPlayerModal');
            if (modalEl) {
                modalEl.classList.remove('player-minimized');
                modalEl.classList.remove('modal-force-landscape');
            }
            document.body.classList.remove('has-minimized-player');

            const miniIndicator = document.getElementById('playerMiniIndicator');
            if (miniIndicator) miniIndicator.classList.add('d-none');

            const icon = document.getElementById('playerModalIcon');
            if (icon) icon.classList.remove('d-none');

            const btnMin = document.getElementById('btnMinimizeModal');
            if (btnMin) btnMin.style.removeProperty('display');

            const btnMax = document.getElementById('btnMaximizeModal');
            if (btnMax) btnMax.style.setProperty('display', 'none', 'important');

            if (window._autoProgressTimer) {
                clearTimeout(window._autoProgressTimer);
                window._autoProgressTimer = null;
            }
            currentModalAutoProgressTriggered = false;

            if (nextEpCountdownTimer) {
                clearInterval(nextEpCountdownTimer);
                nextEpCountdownTimer = null;
            }
            autoNextTriggered = false;
            hasDismissedManualIntro = false;
            const btnNextModal = document.getElementById('btnNextEpisodeModal');
            if (btnNextModal) {
                btnNextModal.classList.add('d-none');
                btnNextModal.classList.remove('d-inline-flex');
            }

            if (window._torrentStatusPollInterval) {
                clearInterval(window._torrentStatusPollInterval);
                window._torrentStatusPollInterval = null;
            }
            if (window._activeTorrentStreamHash) {
                const hToStop = window._activeTorrentStreamHash;
                window._activeTorrentStreamHash = null;
                fetch(`api/torrent_stream.php?action=stop&infoHash=${hToStop}`, { method: 'POST' }).catch(() => {});
            }

            if (window._currentArtplayer) {
                try {
                    if (window._currentArtplayer.hls) {
                        window._currentArtplayer.hls.destroy();
                    }
                    window._currentArtplayer.destroy(false);
                } catch (e) {}
                window._currentArtplayer = null;
            }

            const artContainer = document.getElementById('artplayerContainer');
            if (artContainer) {
                artContainer.style.display = 'none';
                artContainer.innerHTML = '';
            }

            const iframe = document.getElementById('playerIframe');
            if (iframe) {
                iframe.src = 'about:blank';
                iframe.style.display = 'block';
            }

            const realBadge = document.getElementById('playerRealResolutionBadge');
            if (realBadge) realBadge.remove();

            const btnRotateText = document.getElementById('btnRotateText');
            if (btnRotateText) btnRotateText.textContent = 'Girar';
            const btnModal = document.getElementById('btnRotateModal');
            if (btnModal) {
                btnModal.classList.remove('btn-warning');
                btnModal.classList.add('btn-outline-warning');
            }
            if (screen.orientation && typeof screen.orientation.unlock === 'function') {
                try { screen.orientation.unlock(); } catch (e) {}
            }

            // Restaurar posición de scroll SOLO si nunca fue minimizado
            // (si navegó por la página mientras estaba minimizado, se respeta la posición actual del usuario)
            if (!wasPlayerEverMinimized) {
                setTimeout(() => {
                    if (playerPreModalScrollY > 0) {
                        window.scrollTo({ top: playerPreModalScrollY, behavior: 'instant' });
                    } else if (playerTargetCard && document.body.contains(playerTargetCard)) {
                        playerTargetCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }, 50);
            }
            wasPlayerEverMinimized = false;
        });
    }

    const loadHlsScript = (onSuccess, onError) => {
        if (window.Hls) {
            if (onSuccess) onSuccess();
            return;
        }
        const s = document.createElement('script');
        s.src = 'assets/js/hls.min.js';
        s.onload = onSuccess;
        s.onerror = () => {
            const s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.8/dist/hls.min.js';
            s2.onload = onSuccess;
            s2.onerror = onError;
            document.head.appendChild(s2);
        };
        document.head.appendChild(s);
    };

    const loadArtplayerScript = (onSuccess, onError) => {
        if (window.Artplayer) {
            if (onSuccess) onSuccess();
            return;
        }
        const s = document.createElement('script');
        s.src = 'assets/js/artplayer.js';
        s.onload = onSuccess;
        s.onerror = () => {
            const s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/artplayer/dist/artplayer.js';
            s2.onload = onSuccess;
            s2.onerror = onError;
            document.head.appendChild(s2);
        };
        document.head.appendChild(s);
    };

    const loadWebtorScript = (onSuccess, onError) => {
        if (window.webtor && typeof window.webtor.push === 'function') {
            if (onSuccess) onSuccess();
            return;
        }
        const s = document.createElement('script');
        s.src = 'assets/js/webtor-embed.js';
        s.charset = 'utf-8';
        s.async = true;
        s.onload = () => {
            if (onSuccess) onSuccess();
        };
        s.onerror = () => {
            const s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/@webtor/embed-sdk-js/dist/index.min.js';
            s2.charset = 'utf-8';
            s2.async = true;
            s2.onload = () => {
                if (onSuccess) onSuccess();
            };
            s2.onerror = onError;
            document.head.appendChild(s2);
        };
        document.head.appendChild(s);
    };

    let _audioCtx = null;
    const _sourceNodeMap = new WeakMap();

    const setAudioGain = (videoElement, gainValue, artInstance) => {
        if (!videoElement) return;
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;

            if (!_audioCtx) {
                _audioCtx = new AudioContextClass();
            }
            if (_audioCtx.state === 'suspended') {
                _audioCtx.resume();
            }

            let nodes = _sourceNodeMap.get(videoElement);
            if (!nodes) {
                const source = _audioCtx.createMediaElementSource(videoElement);
                const gainNode = _audioCtx.createGain();
                source.connect(gainNode);
                gainNode.connect(_audioCtx.destination);
                nodes = { source, gainNode };
                _sourceNodeMap.set(videoElement, nodes);
            }

            nodes.gainNode.gain.value = gainValue;
            console.log(`%c🔊 [Volume Booster] Ganancia de audio ajustada a: ${Math.round(gainValue * 100)}%`, 'color: #0dcaf0; font-weight: bold;');
            if (artInstance && artInstance.notice) {
                artInstance.notice.show = `🔊 Booster de Audio: ${Math.round(gainValue * 100)}%`;
            }
        } catch (err) {
            console.warn('Volume Booster restriction (cross-origin audio):', err);
            if (artInstance && artInstance.notice) {
                artInstance.notice.show = '⚠️ El servidor de video bloquea amplificación externa por CORS';
            }
        }
    };

    let currentModalAutoProgressTriggered = false;

    const triggerAutoProgress = (episodeMeta) => {
        if (currentModalAutoProgressTriggered) return;
        currentModalAutoProgressTriggered = true;

        if (contentType === 'tv' && episodeMeta && episodeMeta.season && episodeMeta.episode) {
            const sNum = episodeMeta.season;
            const eNum = episodeMeta.episode;

            if (window._watchedProgressCache?.[sNum]?.[eNum] === 'watched') {
                return;
            }

            console.log(`%c⏱️ [Auto-Progreso] 10s de reproducción: S${sNum}E${eNum} marcado como "Viendo"`, 'color: #0dcaf0; font-weight: bold;');
            sendProgressUpdate(sNum, eNum, 'partially_watched');

            if (!window._watchedProgressCache) window._watchedProgressCache = {};
            if (!window._watchedProgressCache[sNum]) window._watchedProgressCache[sNum] = {};
            window._watchedProgressCache[sNum][eNum] = 'partially_watched';

            const epCard = episodeMeta.cardElement || document.querySelector(`.episode-card[data-season="${sNum}"][data-episode="${eNum}"]`);
            if (epCard) updateEpisodeUI(epCard, 'partially_watched');

            const badges = document.getElementById('playerModalBadges');
            if (badges && !badges.querySelector('.badge-auto-progress')) {
                const autoBadge = document.createElement('span');
                autoBadge.className = 'badge bg-info text-dark badge-auto-progress ms-1';
                autoBadge.innerHTML = '<i class="fas fa-eye me-1"></i>Marcado como Viendo';
                badges.appendChild(autoBadge);
            }

        } else if (contentType === 'movie') {
            const curSt = typeof window._watchedProgressCache === 'string' ? window._watchedProgressCache : (window._watchedProgressCache?.status || 'unwatched');
            if (curSt !== 'watched') {
                console.log(`%c⏱️ [Auto-Progreso] 10s de reproducción: Película marcada como "Viendo"`, 'color: #0dcaf0; font-weight: bold;');
                sendProgressUpdate(null, null, 'partially_watched');
                window._watchedProgressCache = { status: 'partially_watched' };
                updateMovieUI('partially_watched');

                const badges = document.getElementById('playerModalBadges');
                if (badges && !badges.querySelector('.badge-auto-progress')) {
                    const autoBadge = document.createElement('span');
                    autoBadge.className = 'badge bg-info text-dark badge-auto-progress ms-1';
                    autoBadge.innerHTML = '<i class="fas fa-eye me-1"></i>Marcada como Viendo';
                    badges.appendChild(autoBadge);
                }
            }
        }
    };

    let currentPlayingEpisode = null;
    let activeSkipTimes = null;
    let nextEpCountdownTimer = null;
    let autoNextTriggered = false;
    let hasDismissedManualIntro = false;

    const getNextEpisodeInfo = (seasonNum, episodeNum) => {
        if (!seasonNum || !episodeNum) return null;
        const allCards = Array.from(document.querySelectorAll('.episode-card'));
        const curIdx = allCards.findIndex(c => 
            c.dataset.season === String(seasonNum) && c.dataset.episode === String(episodeNum)
        );
        if (curIdx === -1 || curIdx + 1 >= allCards.length) {
            return null;
        }
        const nextCard = allCards[curIdx + 1];
        const s = parseInt(nextCard.dataset.season, 10);
        const ep = parseInt(nextCard.dataset.episode, 10);
        const abs = nextCard.dataset.absolute || ep;
        const thumbBox = nextCard.querySelector('.episode-thumb-box');
        const epName = thumbBox?.dataset?.epname || `Episodio ${ep}`;
        const thumb = thumbBox?.dataset?.thumb || '';
        const heroTitle = document.querySelector('.details-hero-card h2')?.textContent?.trim() || 'Serie';
        const fullTitle = `${heroTitle} - T${s}:E${ep}${epName ? ' · ' + epName : ''}`;

        return {
            card: nextCard,
            season: s,
            episode: ep,
            absolute: abs,
            epName: epName,
            thumb: thumb,
            fullTitle: fullTitle
        };
    };

    const playNextEpisode = () => {
        if (!currentPlayingEpisode) return false;
        const nextInfo = getNextEpisodeInfo(currentPlayingEpisode.season, currentPlayingEpisode.episode);
        if (!nextInfo) {
            if (window._currentArtplayer && window._currentArtplayer.notice) {
                window._currentArtplayer.notice.show = 'Has llegado al final de los capítulos disponibles';
            }
            return false;
        }

        if (nextEpCountdownTimer) {
            clearInterval(nextEpCountdownTimer);
            nextEpCountdownTimer = null;
        }
        autoNextTriggered = false;

        const nextCard = nextInfo.card;
        const nextSeason = nextInfo.season;
        const nextEp = nextInfo.episode;
        const nextAbs = nextInfo.absolute;
        const nextTitle = nextInfo.fullTitle;
        const nextThumb = nextInfo.thumb;

        console.log(`%c⏭️ [Siguiente Episodio] Avanzando a: T${nextSeason}:E${nextEp} (${nextTitle})`, 'color: #2ecc71; font-weight: bold;');

        // Scroll al card del siguiente capítulo
        nextCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Actualizar título y estado en el modal
        const titleEl = document.getElementById('playerModalContentTitle');
        if (titleEl) {
            titleEl.textContent = nextTitle;
            titleEl.title = nextTitle;
        }
        const badgesEl = document.getElementById('playerModalBadges');
        if (badgesEl) {
            badgesEl.innerHTML = `<span class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i>Cargando siguiente episodio (T${nextSeason}:E${nextEp})...</span>`;
        }

        // Si ya hay un servidor en streaming cargado en el DOM, hacer clic directo
        const existingPlayBtn = nextCard.querySelector('.stream-play-btn');
        if (existingPlayBtn) {
            existingPlayBtn.click();
            return true;
        }

        // Si aún no se han cargado fuentes, solicitarlas y auto-reproducir el primer stream disponible
        const container = document.getElementById(`sources_s${nextSeason}_e${nextEp}`);
        if (container) {
            loadEpisodeSources(nextSeason, nextEp, container, nextTitle, nextThumb, nextAbs, false);

            let checkCount = 0;
            const autoPlayTimer = setInterval(() => {
                checkCount++;
                const streamBtn = nextCard.querySelector('.stream-play-btn');
                if (streamBtn) {
                    clearInterval(autoPlayTimer);
                    streamBtn.click();
                } else if (checkCount >= 50 || (container.dataset.loading === 'false' && container.dataset.loaded === 'true')) {
                    clearInterval(autoPlayTimer);
                    if (!nextCard.querySelector('.stream-play-btn') && badgesEl) {
                        badgesEl.innerHTML = `<span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i>No se encontraron enlaces de streaming para T${nextSeason}:E${nextEp}</span>`;
                    }
                }
            }, 300);
        }

        return true;
    };

    const openStreamModal = async (url, title, server, provider, thumbnail, episodeMeta) => {
        const modalEl = document.getElementById('videoPlayerModal');
        const iframe = document.getElementById('playerIframe');
        const artContainer = document.getElementById('artplayerContainer');
        const titleTextEl = document.getElementById('playerModalContentTitle');
        const badgesEl = document.getElementById('playerModalBadges');
        const extLinkBtn = document.getElementById('btnExternalLink');
        const provLabel = provider || 'Online';
        
        const isCurrentlyMinimized = modalEl && modalEl.classList.contains('player-minimized');

        // Guardar referencia al elemento activo y posición de scroll previa solo si abrimos desde vista normal
        if (!isCurrentlyMinimized) {
            playerTargetCard = episodeMeta?.cardElement || null;
            playerPreModalScrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop;
            wasPlayerEverMinimized = false;
        }

        // Si es episodio de serie, registrar también como último visto de inmediato
        if (contentType === 'tv' && episodeMeta && episodeMeta.season && episodeMeta.episode) {
            currentPlayingEpisode = {
                season: parseInt(episodeMeta.season, 10),
                episode: parseInt(episodeMeta.episode, 10),
                absolute: episodeMeta.absolute || null,
                cardElement: episodeMeta.cardElement || null
            };

            if (!window._watchedProgressCache) window._watchedProgressCache = {};
            window._watchedProgressCache._last_watched = {
                season: currentPlayingEpisode.season,
                episode: currentPlayingEpisode.episode,
                status: 'partially_watched',
                updated_at: Date.now()
            };
            try {
                localStorage.setItem(`last_watched_${contentType}_${contentId}`, JSON.stringify(window._watchedProgressCache._last_watched));
            } catch (e) {}
        } else {
            currentPlayingEpisode = null;
        }

        // Reiniciar variables de omitir y siguiente episodio
        if (nextEpCountdownTimer) {
            clearInterval(nextEpCountdownTimer);
            nextEpCountdownTimer = null;
        }
        autoNextTriggered = false;
        hasDismissedManualIntro = false;
        activeSkipTimes = null;

        // Actualizar botón "Siguiente Episodio" en cabecera del modal
        const nextInfo = currentPlayingEpisode ? getNextEpisodeInfo(currentPlayingEpisode.season, currentPlayingEpisode.episode) : null;
        const btnNextModal = document.getElementById('btnNextEpisodeModal');
        if (btnNextModal) {
            if (nextInfo) {
                btnNextModal.classList.remove('d-none');
                btnNextModal.classList.add('d-inline-flex');
                btnNextModal.title = `Siguiente: T${nextInfo.season}:E${nextInfo.episode} · ${nextInfo.epName}`;
            } else {
                btnNextModal.classList.add('d-none');
                btnNextModal.classList.remove('d-inline-flex');
            }
        }

        // Consultar skip times para series / anime en segundo plano
        if (currentPlayingEpisode) {
            const isAnime = linksContainer?.dataset?.isAnime === '1';
            const heroTitle = document.querySelector('.details-hero-card h2')?.textContent?.trim() || '';
            const origTitle = linksContainer?.dataset?.originalTitle || '';
            const s = currentPlayingEpisode.season;
            const ep = currentPlayingEpisode.episode;
            const abs = currentPlayingEpisode.absolute || ep;
            const tmdbId = contentId || '';

            fetch(`api/get_skip_times.php?title=${encodeURIComponent(heroTitle)}&season=${s}&episode=${ep}&absolute=${abs}&is_anime=${isAnime ? '1' : '0'}&tmdb_id=${tmdbId}&original_title=${encodeURIComponent(origTitle)}`)
                .then(r => r.json())
                .then(data => {
                    if (data && data.status === 'success' && data.found) {
                        activeSkipTimes = data;
                        console.log('%c⏩ [SkipTimes] Tiempos OP/ED detectados:', 'color: #9b59b6; font-weight: bold;', activeSkipTimes);
                    }
                })
                .catch(e => console.warn('Skip times fetch error:', e));
        }

        // Reiniciar estado de rotación al abrir nuevo modal solo si no está minimizado
        if (!isCurrentlyMinimized) {
            modalEl.classList.remove('modal-force-landscape');
            const btnText = document.getElementById('btnRotateText');
            if (btnText) btnText.textContent = 'Girar';

            const btnModal = document.getElementById('btnRotateModal');
            if (btnModal) {
                btnModal.classList.remove('btn-warning');
                btnModal.classList.add('btn-outline-warning');
            }
        }

        // Reiniciar y configurar temporizador de auto-progreso
        currentModalAutoProgressTriggered = false;
        if (window._autoProgressTimer) {
            clearTimeout(window._autoProgressTimer);
            window._autoProgressTimer = null;
        }

        if (episodeMeta) {
            window._autoProgressTimer = setTimeout(() => {
                const currentModal = document.getElementById('videoPlayerModal');
                if (currentModal && currentModal.classList.contains('show')) {
                    triggerAutoProgress(episodeMeta);
                }
            }, 10000);
        }

        // Mostrar título del episodio o película inmediatamente
        if (titleTextEl) {
            titleTextEl.textContent = title;
            titleTextEl.title = title;
        }

        if (badgesEl) {
            badgesEl.innerHTML = `
                <span class="badge bg-primary text-white"><i class="fas fa-globe me-1"></i>${provLabel}</span>
                <span class="badge bg-secondary"><i class="fas fa-server me-1"></i>${server}</span>
                <span class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i>Conectando...</span>
            `;
        }

        if (extLinkBtn) {
            extLinkBtn.href = url;
        }

        // Destruir cualquier instancia previa de Artplayer
        if (window._currentArtplayer) {
            try {
                if (window._currentArtplayer.hls) {
                    window._currentArtplayer.hls.destroy();
                }
                window._currentArtplayer.destroy(false);
            } catch (e) {}
            window._currentArtplayer = null;
        }

        if (artContainer) {
            artContainer.style.display = 'none';
            artContainer.innerHTML = '';
        }

        if (iframe) {
            iframe.src = 'about:blank';
            iframe.style.display = 'block';
        }

        // Abrir modal solo si no está ya minimizado
        if (!isCurrentlyMinimized) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', focus: false });
            modal.show();
        }

        try {
            console.log(`%c⚡ [Resolver] Resolviendo enlace limpio para: ${url}`, 'color: #9b59b6;');
            const res = await fetch(`api/resolve_stream.php?url=${encodeURIComponent(url)}`);
            const resData = await res.json();
            const cleanUrl = resData?.data?.embed_url || url;
            const streamUrl = resData?.data?.stream_url;
            const resolvedServer = resData?.data?.server || server;

            if (badgesEl) {
                const streamBadge = streamUrl ? '<span class="badge bg-info text-dark ms-1"><i class="fas fa-bolt me-1"></i>Directo Pro (Sin Publicidad)</span>' : '';
                badgesEl.innerHTML = `
                    <span class="badge bg-primary text-white"><i class="fas fa-globe me-1"></i>${provLabel}</span>
                    <span class="badge bg-success text-white"><i class="fas fa-server me-1"></i>${resolvedServer}</span>
                    ${streamBadge}
                `;
            }

            if (extLinkBtn) {
                extLinkBtn.href = streamUrl || cleanUrl;
            }

            const updateRealResolutionBadge = (w, h) => {
                if (!badgesEl || !w || !h) return;
                let badge = document.getElementById('playerRealResolutionBadge');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.id = 'playerRealResolutionBadge';
                    badge.className = 'badge bg-warning text-dark ms-1';
                    badgesEl.appendChild(badge);
                }
                let label = `${h}p`;
                if (h >= 1080) label = '1080p Full HD';
                else if (h >= 720) label = '720p HD';
                else if (h >= 480) label = '480p SD';
                badge.innerHTML = `<i class="fas fa-tv me-1"></i>Real: ${label} (${w}x${h})`;
            };

            if (streamUrl && artContainer) {
                console.log(`%c✨ [Stream Directo] Reproduciendo con Artplayer Pro (Estilo FastStream): ${streamUrl}`, 'color: #2ecc71; font-weight: bold;');
                const isM3u8 = streamUrl.includes('.m3u8');

                loadArtplayerScript(() => {
                    loadHlsScript(() => {
                        if (iframe) {
                            iframe.style.display = 'none';
                            iframe.src = 'about:blank';
                        }
                        artContainer.style.display = 'block';
                        const tipBar = document.getElementById('playerAdTipBar');
                        if (tipBar) tipBar.style.display = 'none';

                        const hasNextEpInfo = currentPlayingEpisode ? getNextEpisodeInfo(currentPlayingEpisode.season, currentPlayingEpisode.episode) : null;

                        const art = new Artplayer({
                            container: '#artplayerContainer',
                            url: streamUrl,
                            type: isM3u8 ? 'm3u8' : 'auto',
                            customType: {
                                m3u8: function (video, m3u8Url, artInstance) {
                                    if (window.Hls && Hls.isSupported()) {
                                        if (artInstance.hls) artInstance.hls.destroy();
                                        const hls = new Hls({
                                            enableWorker: true,
                                            lowLatencyMode: false,
                                            backBufferLength: 90,              // 90s hacia atrás en memoria (rebobinado instantáneo)
                                            maxBufferLength: 120,              // 2 minutos de video precargados hacia adelante
                                            maxMaxBufferLength: 300,           // Hasta 5 minutos de video en memoria
                                            maxBufferSize: 120 * 1024 * 1024,  // 120 MB de búfer en memoria RAM
                                            startFragPrefetch: true,           // Descarga anticipada del siguiente segmento
                                            progressive: true,                 // Reproducción progresiva sin esperar al fragmento completo
                                            testBandwidth: true,
                                            fragLoadingMaxRetry: 4,
                                            fragLoadingRetryDelay: 500
                                        });
                                        hls.loadSource(m3u8Url);
                                        hls.attachMedia(video);
                                        artInstance.hls = hls;

                                        hls.on(Hls.Events.MANIFEST_PARSED, (event, data) => {
                                            if (data && data.levels && data.levels.length > 0) {
                                                const qualityList = data.levels.map((lvl, idx) => ({
                                                    html: `${lvl.height || 'HD'}p (${Math.round((lvl.bitrate || 0) / 1000)} kbps)`,
                                                    level: idx,
                                                    default: idx === hls.currentLevel || (hls.autoLevelEnabled && idx === 0)
                                                }));
                                                qualityList.unshift({
                                                    html: 'Auto (Adaptable)',
                                                    level: -1,
                                                    default: hls.autoLevelEnabled
                                                });

                                                artInstance.setting.update({
                                                    name: 'quality',
                                                    html: '<i class="fas fa-sliders-h text-info me-2"></i>Calidad',
                                                    width: 220,
                                                    tooltip: 'Auto',
                                                    selector: qualityList,
                                                    onSelect: function (item) {
                                                        hls.currentLevel = item.level;
                                                        return item.html;
                                                    }
                                                });

                                                const initialLvl = data.levels[0];
                                                if (initialLvl.width && initialLvl.height) {
                                                    updateRealResolutionBadge(initialLvl.width, initialLvl.height);
                                                }
                                            }
                                        });

                                        hls.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
                                            const lvl = hls.levels ? hls.levels[data.level] : null;
                                            if (lvl && lvl.width && lvl.height) {
                                                updateRealResolutionBadge(lvl.width, lvl.height);
                                            }
                                        });

                                        hls.on(Hls.Events.ERROR, (event, data) => {
                                            if (data && data.fatal) {
                                                console.warn('HLS Fatal Error, fallback to iframe:', data);
                                                artInstance.destroy(false);
                                                artContainer.style.display = 'none';
                                                if (iframe) {
                                                    iframe.style.display = 'block';
                                                    iframe.src = cleanUrl;
                                                }
                                            }
                                        });
                                    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                                        video.src = m3u8Url;
                                    }
                                }
                            },
                            poster: thumbnail || '',
                            theme: '#198754',
                            volume: 0.8,
                            isLive: false,
                            muted: false,
                            autoplay: true,
                            pip: true,
                            autoSize: false,
                            autoMini: false,
                            screenshot: true,
                            setting: true,
                            loop: false,
                            flip: true,
                            playbackRate: true,
                            aspectRatio: true,
                            fullscreen: true,
                            fullscreenWeb: true,
                            subtitleOffset: true,
                            miniProgressBar: false,
                            mutex: true,
                            backdrop: true,
                            playsInline: true,
                            autoPlayback: true,
                            airplay: true,
                            hotkey: true,
                            controls: [
                                ...(hasNextEpInfo ? [{
                                    name: 'next-ep-control',
                                    position: 'left',
                                    index: 10,
                                    html: '<button type="button" class="art-icon" style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; border: none; background: transparent; color: #fff; cursor: pointer;" title="Siguiente Episodio"><i class="fas fa-step-forward" style="font-size: 15px;"></i></button>',
                                    tooltip: `Siguiente: T${hasNextEpInfo.season}:E${hasNextEpInfo.episode}`,
                                    click: function () {
                                        playNextEpisode();
                                    }
                                }] : [])
                            ],
                            layers: [
                                {
                                    name: 'skip-intro-layer',
                                    html: `
                                        <button type="button" class="art-skip-btn" id="artSkipIntroBtn" style="display: none;">
                                            <i class="fas fa-forward"></i>
                                            <span class="art-skip-text">Omitir Intro</span>
                                        </button>
                                    `,
                                    style: {
                                        position: 'absolute',
                                        bottom: '75px',
                                        right: '25px',
                                        zIndex: 50,
                                        pointerEvents: 'auto'
                                    }
                                },
                                {
                                    name: 'skip-outro-layer',
                                    html: `
                                        <button type="button" class="art-skip-btn" id="artSkipOutroBtn" style="display: none;">
                                            <i class="fas fa-step-forward"></i>
                                            <span class="art-skip-text">Omitir Outro</span>
                                        </button>
                                    `,
                                    style: {
                                        position: 'absolute',
                                        bottom: '75px',
                                        right: '25px',
                                        zIndex: 50,
                                        pointerEvents: 'auto'
                                    }
                                },
                                {
                                    name: 'auto-next-overlay',
                                    html: `
                                        <div class="art-auto-next-card" id="artAutoNextCard" style="display: none;">
                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                <span class="badge bg-primary text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                                    <i class="fas fa-play me-1"></i>Siguiente Episodio
                                                </span>
                                                <button type="button" class="btn-close btn-close-white cancel-next-btn" style="font-size: 0.65rem;" title="Cancelar"></button>
                                            </div>
                                            <div class="fw-bold text-white small mb-1 next-ep-title-display text-truncate" style="max-width: 250px;"></div>
                                            <div class="text-secondary small mb-3">Reproduciendo en <span class="fw-bold text-warning countdown-num">5</span>s...</div>
                                            <div class="d-flex align-items-center gap-2">
                                                <button type="button" class="btn btn-sm btn-primary flex-grow-1 fw-bold play-next-now-btn">
                                                    <i class="fas fa-play me-1"></i>Reproducir ya
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary text-light cancel-next-btn">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </div>
                                    `,
                                    style: {
                                        position: 'absolute',
                                        bottom: '75px',
                                        right: '25px',
                                        zIndex: 60,
                                        pointerEvents: 'auto'
                                    }
                                }
                            ],
                            settings: [
                                {
                                    html: '<i class="fas fa-forward text-warning me-2"></i>Auto-Omitir Intro',
                                    width: 230,
                                    tooltip: localStorage.getItem('auto_skip_intro') === 'true' ? 'Activado' : 'Desactivado',
                                    switch: localStorage.getItem('auto_skip_intro') === 'true',
                                    onSwitch: function (item) {
                                        item.switch = !item.switch;
                                        localStorage.setItem('auto_skip_intro', item.switch ? 'true' : 'false');
                                        if (art.notice) art.notice.show = `Auto-Omitir Intro: ${item.switch ? 'Activado' : 'Desactivado'}`;
                                        return item.switch;
                                    }
                                },
                                {
                                    html: '<i class="fas fa-volume-up text-info me-2"></i>Booster de Audio',
                                    width: 230,
                                    tooltip: '100% (Normal)',
                                    selector: [
                                        { html: '100% (Normal)', value: 1.0, default: true },
                                        { html: '150% (+50%)', value: 1.5 },
                                        { html: '200% (Doble)', value: 2.0 },
                                        { html: '250% (+150%)', value: 2.5 },
                                        { html: '300% (Máximo 3x)', value: 3.0 }
                                    ],
                                    onSelect: function (item) {
                                        setAudioGain(art.template.$video, item.value, art);
                                        return item.html;
                                    }
                                },
                                {
                                    html: '<i class="fas fa-adjust text-warning me-2"></i>Filtro de Brillo',
                                    width: 220,
                                    tooltip: 'Normal',
                                    selector: [
                                        { html: 'Normal (100%)', value: 'none', default: true },
                                        { html: 'Claro (+15%)', value: 'brightness(1.15)' },
                                        { html: 'Oscuro/Noche (+30%)', value: 'brightness(1.30)' },
                                        { html: 'Ultra Brillo (+50%)', value: 'brightness(1.50)' },
                                        { html: 'Alto Contraste', value: 'brightness(1.1) contrast(1.25)' }
                                    ],
                                    onSelect: function (item) {
                                        art.template.$video.style.filter = item.value;
                                        if (art.notice) art.notice.show = `Filtro: ${item.html}`;
                                        return item.html;
                                    }
                                }
                            ]
                        });

                        window._currentArtplayer = art;

                        // Configurar botones de Omitir Intro/Outro y Auto Siguiente
                        const skipIntroBtn = art.template.$container.querySelector('#artSkipIntroBtn');
                        const skipOutroBtn = art.template.$container.querySelector('#artSkipOutroBtn');
                        const autoNextCard = art.template.$container.querySelector('#artAutoNextCard');

                        if (skipIntroBtn) {
                            skipIntroBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                if (activeSkipTimes && activeSkipTimes.op) {
                                    art.currentTime = activeSkipTimes.op.end;
                                    if (art.notice) art.notice.show = 'Intro omitida';
                                } else {
                                    art.currentTime = Math.min((art.duration || 9999) - 10, art.currentTime + 85);
                                    if (art.notice) art.notice.show = '+85s (Intro omitida)';
                                }
                                hasDismissedManualIntro = true;
                                skipIntroBtn.style.display = 'none';
                            });
                        }

                        if (skipOutroBtn) {
                            skipOutroBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                if (activeSkipTimes && activeSkipTimes.ed) {
                                    art.currentTime = activeSkipTimes.ed.end;
                                    if (art.notice) art.notice.show = 'Outro omitido';
                                }
                                skipOutroBtn.style.display = 'none';
                                const hasNext = currentPlayingEpisode ? getNextEpisodeInfo(currentPlayingEpisode.season, currentPlayingEpisode.episode) : null;
                                if (hasNext) {
                                    triggerAutoNextCountdown(hasNext);
                                }
                            });
                        }

                        const triggerAutoNextCountdown = (nextEpInfo) => {
                            if (autoNextTriggered || !nextEpInfo || !autoNextCard) return;
                            autoNextTriggered = true;

                            const titleEl = autoNextCard.querySelector('.next-ep-title-display');
                            const countEl = autoNextCard.querySelector('.countdown-num');
                            if (titleEl) titleEl.textContent = `T${nextEpInfo.season}:E${nextEpInfo.episode} · ${nextEpInfo.epName}`;

                            autoNextCard.style.display = 'block';

                            let secondsLeft = 5;
                            if (countEl) countEl.textContent = secondsLeft;

                            if (nextEpCountdownTimer) clearInterval(nextEpCountdownTimer);
                            nextEpCountdownTimer = setInterval(() => {
                                secondsLeft--;
                                if (countEl) countEl.textContent = secondsLeft;
                                if (secondsLeft <= 0) {
                                    clearInterval(nextEpCountdownTimer);
                                    nextEpCountdownTimer = null;
                                    autoNextCard.style.display = 'none';
                                    playNextEpisode();
                                }
                            }, 1000);
                        };

                        if (autoNextCard) {
                            autoNextCard.querySelectorAll('.cancel-next-btn').forEach(btn => {
                                btn.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    if (nextEpCountdownTimer) {
                                        clearInterval(nextEpCountdownTimer);
                                        nextEpCountdownTimer = null;
                                    }
                                    autoNextCard.style.display = 'none';
                                });
                            });

                            const playNowBtn = autoNextCard.querySelector('.play-next-now-btn');
                            if (playNowBtn) {
                                playNowBtn.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    if (nextEpCountdownTimer) {
                                        clearInterval(nextEpCountdownTimer);
                                        nextEpCountdownTimer = null;
                                    }
                                    autoNextCard.style.display = 'none';
                                    playNextEpisode();
                                });
                            }
                        }

                        // Auto-ocultamiento limpio de barra de carga, controles y cursor tras inactividad
                        let fsIdleTimer = null;
                        const triggerControlsAutoHide = () => {
                            if (fsIdleTimer) clearTimeout(fsIdleTimer);
                            if (!art || !art.playing) return;

                            fsIdleTimer = setTimeout(() => {
                                if (art && art.playing && !art.setting.show && !art.isInput) {
                                    art.controls.show = false;
                                    if (art.controls && art.controls.isHover !== undefined) {
                                        art.controls.isHover = false;
                                    }
                                }
                            }, 2500);
                        };

                        art.on('mousemove', () => {
                            art.controls.show = true;
                            triggerControlsAutoHide();
                        });

                        art.on('control', (show) => {
                            if (show) {
                                triggerControlsAutoHide();
                            }
                        });

                        art.on('video:play', () => {
                            triggerControlsAutoHide();
                        });

                        art.on('video:playing', () => {
                            if (art.loading) art.loading.show = false;
                            triggerControlsAutoHide();
                        });

                        art.on('fullscreen', (isFs) => {
                            triggerControlsAutoHide();
                        });

                        art.on('fullscreenWeb', (isFs) => {
                            triggerControlsAutoHide();
                        });

                        art.on('video:timeupdate', () => {
                            if (art.playing && art.loading && art.loading.show) {
                                art.loading.show = false;
                            }
                            if (art.currentTime >= 10 && episodeMeta) {
                                triggerAutoProgress(episodeMeta);
                            }

                            const curTime = art.currentTime;
                            const dur = art.duration || 0;

                            if (currentPlayingEpisode) {
                                const isAutoSkip = localStorage.getItem('auto_skip_intro') === 'true';

                                // 1. Omitir Intro
                                if (activeSkipTimes && activeSkipTimes.op) {
                                    const opStart = activeSkipTimes.op.start;
                                    const opEnd = activeSkipTimes.op.end;

                                    if (curTime >= opStart && curTime < opEnd) {
                                        if (isAutoSkip) {
                                            art.currentTime = opEnd;
                                            if (art.notice) art.notice.show = 'Intro omitida automáticamente';
                                            if (skipIntroBtn) skipIntroBtn.style.display = 'none';
                                        } else if (!hasDismissedManualIntro && skipIntroBtn) {
                                            const textSpan = skipIntroBtn.querySelector('.art-skip-text');
                                            if (textSpan) textSpan.textContent = 'Omitir Intro';
                                            skipIntroBtn.style.display = 'inline-flex';
                                        }
                                    } else if (skipIntroBtn && skipIntroBtn.style.display !== 'none') {
                                        skipIntroBtn.style.display = 'none';
                                    }
                                } else if (!hasDismissedManualIntro && skipIntroBtn && contentType === 'tv') {
                                    // Fallback para series (+85s entre el segundo 5 y 90)
                                    if (curTime >= 5 && curTime <= 90) {
                                        const textSpan = skipIntroBtn.querySelector('.art-skip-text');
                                        if (textSpan) textSpan.textContent = 'Omitir Intro (+85s)';
                                        skipIntroBtn.style.display = 'inline-flex';
                                    } else if (skipIntroBtn && skipIntroBtn.style.display !== 'none') {
                                        skipIntroBtn.style.display = 'none';
                                    }
                                }

                                // 2. Omitir Outro
                                if (activeSkipTimes && activeSkipTimes.ed) {
                                    const edStart = activeSkipTimes.ed.start;
                                    const edEnd = activeSkipTimes.ed.end;

                                    if (curTime >= edStart && curTime <= edEnd) {
                                        if (skipOutroBtn) skipOutroBtn.style.display = 'inline-flex';
                                    } else if (skipOutroBtn && skipOutroBtn.style.display !== 'none') {
                                        skipOutroBtn.style.display = 'none';
                                    }
                                }

                                // 3. Auto Siguiente Episodio
                                const nextInfo = getNextEpisodeInfo(currentPlayingEpisode.season, currentPlayingEpisode.episode);
                                if (nextInfo && !autoNextTriggered) {
                                    if (activeSkipTimes && activeSkipTimes.ed && curTime >= activeSkipTimes.ed.start) {
                                        triggerAutoNextCountdown(nextInfo);
                                    } else if (dur > 60 && (dur - curTime) <= 15) {
                                        triggerAutoNextCountdown(nextInfo);
                                    }
                                }
                            }
                        });

                        art.on('video:ended', () => {
                            if (currentPlayingEpisode) {
                                const nextInfo = getNextEpisodeInfo(currentPlayingEpisode.season, currentPlayingEpisode.episode);
                                if (nextInfo) {
                                    triggerAutoNextCountdown(nextInfo);
                                }
                            }
                        });

                        art.on('video:loadedmetadata', () => {
                            const v = art.template.$video;
                            if (v && v.videoWidth && v.videoHeight) {
                                updateRealResolutionBadge(v.videoWidth, v.videoHeight);
                            }
                        });

                        art.on('video:resize', () => {
                            const v = art.template.$video;
                            if (v && v.videoWidth && v.videoHeight) {
                                updateRealResolutionBadge(v.videoWidth, v.videoHeight);
                            }
                        });

                        art.on('ready', () => {
                            const m = document.getElementById('videoPlayerModal');
                            if (m && m.classList.contains('player-minimized')) {
                                art.resize();
                            }
                        });

                    }, () => {
                        artContainer.style.display = 'none';
                        if (iframe) {
                            iframe.style.display = 'block';
                            iframe.src = cleanUrl;
                        }
                    });
                }, () => {
                    artContainer.style.display = 'none';
                    if (iframe) {
                        iframe.style.display = 'block';
                        iframe.src = cleanUrl;
                    }
                });

            } else if (iframe) {
                if (artContainer) {
                    artContainer.style.display = 'none';
                    artContainer.innerHTML = '';
                }
                iframe.style.display = 'block';
                iframe.src = cleanUrl;
                const tipBar = document.getElementById('playerAdTipBar');
                if (tipBar) tipBar.style.display = 'flex';
            }
        } catch (e) {
            if (artContainer) {
                artContainer.style.display = 'none';
                artContainer.innerHTML = '';
            }
            if (iframe) {
                iframe.style.display = 'block';
                iframe.src = url;
                const tipBar = document.getElementById('playerAdTipBar');
                if (tipBar) tipBar.style.display = 'flex';
            }
            if (badgesEl) {
                badgesEl.innerHTML = `
                    <span class="badge bg-primary text-white"><i class="fas fa-globe me-1"></i>${provLabel}</span>
                    <span class="badge bg-secondary"><i class="fas fa-server me-1"></i>${server}</span>
                `;
            }
        }
    };

    const playWithWebtor = (magnetUrl, title, server, provLabel, thumbnail, episodeMeta) => {
        const artContainer = document.getElementById('artplayerContainer');
        const badgesEl = document.getElementById('playerModalBadges');

        if (badgesEl) {
            badgesEl.innerHTML = `
                <span class="badge bg-warning text-dark"><i class="fas fa-magnet me-1"></i>${provLabel}</span>
                <span class="badge bg-primary text-white"><i class="fas fa-cloud me-1"></i>Webtor Online</span>
                <span class="badge bg-info text-dark" id="torrentSeedsBadge"><i class="fas fa-spinner fa-spin me-1"></i>Conectando a BitTorrent...</span>
                <button type="button" class="btn btn-xs btn-outline-secondary text-light py-0 px-2 ms-1 border-secondary" id="btnSwitchToLocalStreamer" style="font-size: 0.72rem;" title="Cambiar a reproductor local (Node.js)">
                    <i class="fas fa-exchange-alt me-1"></i>Modo Local
                </button>
            `;
            const btnSwitch = document.getElementById('btnSwitchToLocalStreamer');
            if (btnSwitch) {
                btnSwitch.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    localStorage.setItem('torrent_player_mode', 'streamer');
                    openTorrentStreamModal(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
                });
            }
        }

        artContainer.style.display = 'block';
        artContainer.innerHTML = `
            <div id="torrentBufferHUD" class="d-flex flex-column align-items-center justify-content-center text-center p-4" style="min-height: 500px; height: 100%; background: radial-gradient(circle, #181926 0%, #0c0d14 100%);">
                <div class="spinner-grow text-warning mb-3" style="width: 3.5rem; height: 3.5rem;" role="status"></div>
                <h4 class="text-white fw-bold mb-1"><i class="fas fa-cloud text-warning me-2"></i>Conectando a Webtor Cloud</h4>
                <p class="text-secondary small mb-3">Estableciendo conexión P2P para reproducción directa en el navegador...</p>
                <div class="progress w-75 bg-dark border border-secondary mb-2" style="height: 12px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" style="width: 100%;"></div>
                </div>
                <small class="text-muted mt-2"><i class="fas fa-check-circle text-success me-1"></i>Sin descargas locales • Transcodificación automática en la nube</small>
            </div>
        `;

        loadWebtorScript(() => {
            artContainer.innerHTML = '<div id="webtorEmbedTarget" style="width: 100%; height: 100%; min-height: 500px;"></div>';
            try {
                window.webtor = window.webtor || [];
                window.webtor.push({
                    id: 'webtorEmbedTarget',
                    magnet: magnetUrl,
                    width: '100%',
                    height: '100%',
                    title: title,
                    poster: thumbnail || '',
                    lang: 'es',
                    header: true,
                    features: {
                        subtitles: true,
                        settings: true,
                        fullscreen: true,
                        playpause: true,
                        currentTime: true,
                        timeline: true,
                        duration: true,
                        volume: true,
                        chromecast: true
                    },
                    on: function(e) {
                        const seedsBadge = document.getElementById('torrentSeedsBadge');
                        if (e.name === (window.webtor.TORRENT_FETCHED || 'torrent fetched')) {
                            if (seedsBadge) {
                                const peers = (e.data && e.data.totalPeers) ? `${e.data.totalPeers} seeds` : 'Enjambre Activo';
                                seedsBadge.className = 'badge bg-success text-white';
                                seedsBadge.innerHTML = `<i class="fas fa-check-circle me-1"></i>${peers}`;
                            }
                        } else if (e.name === (window.webtor.TORRENT_ERROR || 'torrent error')) {
                            if (seedsBadge) {
                                seedsBadge.className = 'badge bg-danger text-white';
                                seedsBadge.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Error en torrent`;
                            }
                        } else if (e.name === (window.webtor.CURRENT_TIME || 'current time')) {
                            if (typeof e.data === 'number' && e.data >= 10 && episodeMeta) {
                                triggerAutoProgress(episodeMeta);
                            }
                        }
                    }
                });
            } catch (err) {
                console.error('[Webtor] Error inicializando SDK:', err);
                artContainer.innerHTML = `
                    <div class="d-flex flex-column align-items-center justify-content-center p-4 text-center text-light" style="min-height: 500px; background: rgba(10, 10, 15, 0.95);">
                        <i class="fas fa-exclamation-triangle text-warning mb-2" style="font-size: 2.5rem;"></i>
                        <h5 class="fw-bold text-white mb-2">No se pudo inicializar Webtor</h5>
                        <p class="text-secondary small mb-3" style="max-width: 480px;">Ocurrió un error al cargar el reproductor en la nube. Puedes abrir el enlace directamente en tu cliente BitTorrent externo.</p>
                        <a href="${magnetUrl}" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm">
                            <i class="fas fa-external-link-alt me-1"></i> Abrir en VLC / qBittorrent
                        </a>
                    </div>
                `;
            }
        }, (err) => {
            console.error('[Webtor] Error cargando SDK:', err);
            artContainer.innerHTML = `
                <div class="d-flex flex-column align-items-center justify-content-center p-4 text-center text-light" style="min-height: 500px; background: rgba(10, 10, 15, 0.95);">
                    <i class="fas fa-wifi text-danger mb-2" style="font-size: 2.5rem;"></i>
                    <h5 class="fw-bold text-white mb-2">Error de conexión con Webtor SDK</h5>
                    <p class="text-secondary small mb-3" style="max-width: 480px;">No se pudo conectar con el componente de reproducción en la nube. Puedes abrir el enlace directamente en tu cliente BitTorrent externo.</p>
                    <a href="${magnetUrl}" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm">
                        <i class="fas fa-external-link-alt me-1"></i> Abrir en VLC / qBittorrent
                    </a>
                </div>
            `;
        });
    };

    const playWithLocalStreamer = async (magnetUrl, title, server, provLabel, thumbnail, episodeMeta) => {
        const artContainer = document.getElementById('artplayerContainer');
        const badgesEl = document.getElementById('playerModalBadges');

        if (badgesEl) {
            badgesEl.innerHTML = `
                <span class="badge bg-warning text-dark"><i class="fas fa-magnet me-1"></i>${provLabel}</span>
                <span class="badge bg-secondary"><i class="fas fa-server me-1"></i>Local Node</span>
                <span class="badge bg-info text-dark" id="torrentSeedsBadge"><i class="fas fa-spinner fa-spin me-1"></i>Conectando a BitTorrent...</span>
                <button type="button" class="btn btn-xs btn-outline-info text-info py-0 px-2 ms-1 border-info" id="btnSwitchToWebtor" style="font-size: 0.72rem;" title="Cambiar a reproductor en la nube Webtor">
                    <i class="fas fa-cloud me-1"></i>Modo Webtor
                </button>
            `;
            const btnSwitchW = document.getElementById('btnSwitchToWebtor');
            if (btnSwitchW) {
                btnSwitchW.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    localStorage.setItem('torrent_player_mode', 'webtor');
                    openTorrentStreamModal(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
                });
            }
        }

        artContainer.style.display = 'block';
        artContainer.innerHTML = `
            <div id="torrentBufferHUD" class="d-flex flex-column align-items-center justify-content-center text-center p-4" style="min-height: 480px; height: 100%; background: radial-gradient(circle, #181926 0%, #0c0d14 100%);">
                <div class="spinner-grow text-warning mb-3" style="width: 3.5rem; height: 3.5rem;" role="status"></div>
                <h4 class="text-white fw-bold mb-1"><i class="fas fa-magnet text-warning me-2"></i>Conectando al enjambre BitTorrent</h4>
                <p class="text-secondary small mb-3" id="torrentStatusMsg">Iniciando descarga secuencial y contactando semillas de alta velocidad...</p>
                <div class="progress w-75 bg-dark border border-secondary mb-2" style="height: 16px;">
                    <div id="torrentBufferBar" class="progress-bar progress-bar-striped progress-bar-animated bg-warning text-dark fw-bold" style="width: 0%; font-size: 0.75rem;">0%</div>
                </div>
                <div class="d-flex flex-wrap justify-content-center gap-2 text-secondary small mt-2">
                    <span class="badge bg-dark border border-secondary py-2 px-3"><i class="fas fa-users text-info me-1"></i>Semillas: <strong class="text-white" id="torrentPeersCount">0</strong></span>
                    <span class="badge bg-dark border border-secondary py-2 px-3"><i class="fas fa-tachometer-alt text-success me-1"></i>Velocidad: <strong class="text-white" id="torrentSpeedVal">0 KB/s</strong></span>
                    <span class="badge bg-dark border border-secondary py-2 px-3"><i class="fas fa-hdd text-warning me-1"></i>Descargado: <strong class="text-white" id="torrentDownloadedVal">0 MB</strong></span>
                </div>
                <small class="text-muted mt-3"><i class="fas fa-info-circle me-1"></i>La reproducción comenzará automáticamente en cuanto el primer fragmento esté listo.</small>
            </div>
        `;

        try {
            const loadRes = await fetch('api/torrent_stream.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'load', magnet: magnetUrl })
            });

            if (!loadRes.ok) {
                throw new Error(`Servidor Node no respondió (HTTP ${loadRes.status})`);
            }

            const loadData = await loadRes.json();

            if (!loadData || loadData.status !== 'success' || !loadData.infoHash) {
                throw new Error(loadData?.error || 'No se pudo inicializar el torrent.');
            }

            const infoHash = loadData.infoHash;
            window._activeTorrentStreamHash = infoHash;

            const barEl = document.getElementById('torrentBufferBar');
            const peersEl = document.getElementById('torrentPeersCount');
            const speedEl = document.getElementById('torrentSpeedVal');
            const downEl = document.getElementById('torrentDownloadedVal');
            const msgEl = document.getElementById('torrentStatusMsg');
            const seedsBadge = document.getElementById('torrentSeedsBadge');

            let playerStarted = false;
            let pollAttempts = 0;

            window._torrentStatusPollInterval = setInterval(async () => {
                pollAttempts++;
                try {
                    const sRes = await fetch(`api/torrent_stream.php?action=status&infoHash=${infoHash}`);
                    if (!sRes.ok) return;
                    const sData = await sRes.json();
                    if (!sData || sData.status !== 'success') return;

                    if (peersEl) peersEl.textContent = sData.peers || 0;
                    if (speedEl) speedEl.textContent = sData.downloadSpeed || '0 KB/s';
                    if (downEl) downEl.textContent = sData.downloadedSize || '0 MB';

                    const pct = sData.initialBufferPct || 0;
                    if (barEl) {
                        barEl.style.width = `${pct}%`;
                        barEl.textContent = `${pct}%`;
                    }

                    if (seedsBadge) {
                        seedsBadge.innerHTML = `<i class="fas fa-arrow-up text-success me-1"></i>${sData.peers} seeds · ${sData.downloadSpeed}`;
                    }

                    if (msgEl && sData.name) {
                        msgEl.textContent = `Descargando: ${sData.name} (${sData.downloadedSize} / ${sData.totalSize})`;
                    }

                    const artStats = document.getElementById('artTorrentStats');
                    if (artStats) {
                        artStats.textContent = `${sData.peers} seeds · ${sData.downloadSpeed}`;
                    }

                    if (sData.ready && !playerStarted) {
                        playerStarted = true;
                        const streamUrl = sData.streamUrl;

                        loadArtplayerScript(() => {
                            artContainer.innerHTML = '';
                            const art = new Artplayer({
                                container: '#artplayerContainer',
                                url: streamUrl,
                                type: 'auto',
                                title: sData.name || title,
                                poster: thumbnail || '',
                                autoplay: true,
                                autoSize: false,
                                autoMini: false,
                                loop: false,
                                flip: true,
                                playbackRate: true,
                                aspectRatio: true,
                                screenshot: true,
                                setting: true,
                                hotkey: true,
                                pip: true,
                                mutex: true,
                                fullscreen: true,
                                fullscreenWeb: true,
                                subtitleOffset: true,
                                miniProgressBar: true,
                                playsInline: true,
                                lock: true,
                                fastForward: true,
                                autoPlayback: true,
                                theme: '#f39c12',
                                controls: [
                                    {
                                        name: 'torrent-stats',
                                        position: 'right',
                                        html: `<span class="badge bg-warning text-dark fw-bold px-2 py-1" style="font-size: 0.75rem;"><i class="fas fa-magnet me-1"></i><span id="artTorrentStats">${sData.peers} seeds · ${sData.downloadSpeed}</span></span>`,
                                        tooltip: `Semillas: ${sData.peers} · Velocidad: ${sData.downloadSpeed}`
                                    }
                                ]
                            });

                            art.on('video:timeupdate', () => {
                                if (art.currentTime >= 10 && episodeMeta) {
                                    triggerAutoProgress(episodeMeta);
                                }
                            });

                            art.on('video:error', () => {
                                console.warn('[ArtPlayer] Error de reproducción nativa del navegador.');
                                const errBox = document.createElement('div');
                                errBox.className = 'd-flex flex-column align-items-center justify-content-center p-4 text-center text-light pop-in-card';
                                errBox.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(10, 10, 15, 0.92); z-index: 99;';
                                errBox.innerHTML = `
                                    <i class="fas fa-exclamation-triangle text-warning mb-2" style="font-size: 2.8rem;"></i>
                                    <h5 class="fw-bold text-white mb-2">Formato o Codec no compatible con este navegador</h5>
                                    <p class="text-secondary small mb-3" style="max-width: 500px;">
                                        Este archivo torrent viene codificado en un formato avanzado (ej. <strong>4K x265 / MKV</strong> o audio multicanal <strong>Dolby 5.1 / DTS</strong>) que tu navegador no decodifica nativamente.<br><br>
                                        💡 <em>Puedes reproducirlo mediante <strong>Webtor (Nube)</strong> que transcodifica automáticamente, o abrirlo en <strong>VLC / qBittorrent</strong>.</em>
                                    </p>
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <button type="button" class="btn btn-primary fw-bold btn-sm shadow-sm" id="btnArtPlayerFallbackWebtor">
                                            <i class="fas fa-cloud me-1"></i> Probar con Webtor Cloud
                                        </button>
                                        <a href="${magnetUrl}" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm">
                                            <i class="fas fa-external-link-alt me-1"></i> Abrir en VLC / qBittorrent
                                        </a>
                                    </div>
                                `;
                                artContainer.appendChild(errBox);
                                const btnFall = document.getElementById('btnArtPlayerFallbackWebtor');
                                if (btnFall) {
                                    btnFall.addEventListener('click', () => {
                                        playWithWebtor(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
                                    });
                                }
                            });

                            window._currentArtplayer = art;
                        });
                    }

                    if (!playerStarted && pollAttempts > 40 && (!sData.peers || sData.peers === 0)) {
                        if (msgEl) {
                            msgEl.className = 'text-warning small mb-3';
                            msgEl.innerHTML = 'El enjambre tiene pocas semillas activas en este momento. Puedes esperar o reproducir mediante Webtor en la nube.';
                        }
                    }
                } catch (e) {
                    console.warn('[TorrentStatus] Error en sondeo:', e);
                }
            }, 800);

        } catch (err) {
            console.error('[TorrentStream] Error local:', err);
            artContainer.innerHTML = `
                <div class="d-flex flex-column align-items-center justify-content-center p-4 text-center text-light" style="min-height: 500px; background: rgba(10, 10, 15, 0.95);">
                    <i class="fas fa-server text-warning mb-2" style="font-size: 2.8rem;"></i>
                    <h5 class="fw-bold text-white mb-2">Servidor Local Node.js No Disponible</h5>
                    <p class="text-secondary small mb-3" style="max-width: 500px;">
                        El servicio local de streaming no está activo (típico en InfinityFree o cuando el daemon está apagado).<br><br>
                        💡 <strong>¡No te preocupes!</strong> Puedes reproducir este torrent directamente en la nube usando <strong>Webtor</strong> sin necesidad de ningún servidor propio.
                    </p>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <button type="button" class="btn btn-primary fw-bold btn-sm shadow-sm px-3" id="btnLocalFailFallbackWebtor">
                            <i class="fas fa-cloud me-1"></i> Reproducir con Webtor (Nube)
                        </button>
                        <a href="${magnetUrl}" class="btn btn-outline-warning btn-sm shadow-sm">
                            <i class="fas fa-external-link-alt me-1"></i> Abrir en VLC / qBittorrent
                        </a>
                    </div>
                </div>
            `;
            const btnFailW = document.getElementById('btnLocalFailFallbackWebtor');
            if (btnFailW) {
                btnFailW.addEventListener('click', () => {
                    localStorage.setItem('torrent_player_mode', 'webtor');
                    playWithWebtor(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
                });
            }
        }
    };

    const openTorrentStreamModal = async (magnetUrl, title, server, provider, thumbnail, episodeMeta) => {
        const modalEl = document.getElementById('videoPlayerModal');
        const iframe = document.getElementById('playerIframe');
        const artContainer = document.getElementById('artplayerContainer');
        const titleTextEl = document.getElementById('playerModalContentTitle');
        const extLinkBtn = document.getElementById('btnExternalLink');
        const provLabel = provider || 'Torrent';

        if (window._torrentStatusPollInterval) {
            clearInterval(window._torrentStatusPollInterval);
            window._torrentStatusPollInterval = null;
        }

        if (window._currentArtplayer) {
            try {
                if (window._currentArtplayer.hls) window._currentArtplayer.hls.destroy();
                window._currentArtplayer.destroy(false);
            } catch (e) {}
            window._currentArtplayer = null;
        }

        if (iframe) {
            iframe.src = 'about:blank';
            iframe.style.display = 'none';
        }

        if (titleTextEl) {
            titleTextEl.textContent = title;
            titleTextEl.title = title;
        }

        if (extLinkBtn) {
            extLinkBtn.href = magnetUrl;
            extLinkBtn.title = 'Abrir magnet en cliente externo (qBittorrent / VLC)';
        }

        const isCurrentlyMinimized = modalEl && modalEl.classList.contains('player-minimized');
        if (!isCurrentlyMinimized) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', focus: false });
            modal.show();
        }

        const mode = localStorage.getItem('torrent_player_mode') || window.TORRENT_PLAYER_MODE || 'webtor';

        if (mode === 'streamer') {
            await playWithLocalStreamer(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
        } else if (mode === 'auto') {
            let daemonOnline = false;
            try {
                const ctrl = new AbortController();
                const toId = setTimeout(() => ctrl.abort(), 1200);
                const hRes = await fetch('api/torrent_stream.php?action=health', { signal: ctrl.signal });
                clearTimeout(toId);
                if (hRes.ok) {
                    const hData = await hRes.json();
                    if (hData && hData.status === 'ok') daemonOnline = true;
                }
            } catch (e) {}

            if (daemonOnline) {
                await playWithLocalStreamer(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
            } else {
                playWithWebtor(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
            }
        } else {
            playWithWebtor(magnetUrl, title, server, provLabel, thumbnail, episodeMeta);
        }
    };


    let blockUidCounter = 0;

    const cleanProviderName = (src) => {
        let name = src.provider_name || src.provider || '';
        name = name.replace(/\s*\(.*?\)/gi, '').trim();
        if (!name || name.toLowerCase() === 'servidores' || name.toLowerCase() === 'servidor') {
            if (src.provider) {
                name = src.provider === 'poseidonhd' ? 'PoseidonHD' : (src.provider.charAt(0).toUpperCase() + src.provider.slice(1));
            }
        }
        return name || 'Servidor';
    };

    const getProviderIcon = (provId, type) => {
        const id = (provId || '').toLowerCase();
        if (type === 'torrent') {
            if (id.includes('hacktorrent')) return 'fas fa-bolt text-danger';
            if (id.includes('cinecalidad')) return 'fas fa-star text-info';
            if (id.includes('dontorrent')) return 'fas fa-arrow-circle-down text-warning';
            if (id.includes('elitetorrent')) return 'fas fa-crown text-warning';
            if (id.includes('yts')) return 'fas fa-film text-info';
            if (id.includes('nyaa')) return 'fas fa-paw text-danger';
            if (id.includes('thepiratebay') || id.includes('piratebay') || id.includes('tpb')) return 'fas fa-ship text-warning';
            return 'fas fa-magnet text-warning';
        }
        if (id.includes('cuevana')) return 'fas fa-play-circle text-primary';
        if (id.includes('poseidon')) return 'fas fa-water text-info';
        if (id.includes('gnula')) return 'fas fa-film text-danger';
        if (id.includes('allpeliculas')) return 'fas fa-video text-info';
        if (id.includes('serieskao')) return 'fas fa-tv text-warning';
        if (id.includes('retrotve')) return 'fas fa-history text-warning';
        if (id.includes('hacktorrent')) return 'fas fa-bolt text-danger';
        if (id.includes('pelisplus') || id.includes('pelispedia') || id.includes('pelisforte')) return 'fas fa-compact-disc text-success';
        if (id.includes('tioanime') || id.includes('anime')) return 'fas fa-dragon text-danger';
        return 'fas fa-play text-success';
    };

    const groupLinksByProvider = (items) => {
        const groups = {};
        items.forEach(item => {
            const pKey = (item.provider || cleanProviderName(item)).toLowerCase().trim();
            if (!groups[pKey]) {
                groups[pKey] = {
                    name: cleanProviderName(item),
                    providerId: item.provider || pKey,
                    rawProviderName: item.provider_name || item.provider || 'Streaming',
                    items: []
                };
            }
            groups[pKey].items.push(item);
        });
        return Object.values(groups);
    };

    const renderSourcesBlock = (sources, itemTitle, thumbnail) => {
        const directLinks = sources.direct || [];
        const streamLinks = sources.streaming || [];
        const torrentLinks = sources.torrent || [];

        if (directLinks.length === 0 && streamLinks.length === 0 && torrentLinks.length === 0) {
            return `<div class="alert alert-dark text-light py-2 px-3 mb-0"><i class="fas fa-info-circle me-1 text-info"></i> No se encontraron enlaces para esta opción.</div>`;
        }

        const blockId = 'src_blk_' + (++blockUidCounter) + '_' + Math.random().toString(36).substring(2, 7);
        const safeTitle = (itemTitle || '').replace(/"/g, '&quot;');
        const safeThumb = (thumbnail || '').replace(/"/g, '&quot;');

        let html = '';

        // 1. Sección CDN Propio / Enlaces Directos
        if (directLinks.length > 0) {
            html += `
                <div class="mb-3">
                    <h6 class="text-info mb-2"><i class="fas fa-hdd me-1"></i> Servidor Propio (Descarga / Dispositivo)</h6>
                    <div class="d-flex flex-wrap gap-2">
            `;
            directLinks.forEach((src) => {
                const url = typeof src === 'string' ? src : src.url;
                const srv = src.server || 'CDN Propio';
                const qlt = src.quality || 'HD';
                const provName = src.provider_name || 'CDN Propio';
                html += `
                    <a href="${url}" target="_blank" class="btn btn-sm btn-primary shadow-sm d-inline-flex align-items-center">
                        <span class="badge bg-dark text-info me-1"><i class="fas fa-cloud me-1"></i>${provName}</span>
                        <i class="fas fa-download me-1"></i> ${srv} <span class="badge bg-black ms-1">${qlt}</span>
                    </a>
                `;
            });
            html += `</div></div>`;
        }

        // 2. Sección Streaming Online (Agrupada por Proveedor en Desplegables)
        if (streamLinks.length > 0) {
            const streamGroups = groupLinksByProvider(streamLinks);
            html += `
                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mb-2">
                        <h6 class="text-success mb-0"><i class="fas fa-play-circle me-1"></i> Reproducción Online (${streamLinks.length} ${streamLinks.length === 1 ? 'servidor' : 'servidores'})</h6>
                        <small class="text-secondary"><i class="fas fa-hand-pointer me-1 text-info"></i>Toca un proveedor para ver servidores</small>
                    </div>
                    <div class="provider-groups-container d-flex flex-column gap-2">
            `;

            streamGroups.forEach((group, gIdx) => {
                const collapseId = `${blockId}_stream_${gIdx}`;
                const icon = getProviderIcon(group.providerId, 'streaming');
                const count = group.items.length;

                // Resumen de idiomas y calidades para las insignias del botón
                const langs = [...new Set(group.items.map(i => i.language || (i.lang === 'lat' ? 'Latino' : (i.lang === 'cast' ? 'Castellano' : (i.lang === 'sub' ? 'Subtitulado' : null)))).filter(Boolean))];
                const langBadges = langs.map(l => `<span class="badge bg-dark border border-secondary text-info fw-normal" style="font-size: 0.72rem;">${l}</span>`).join(' ');

                const qualities = [...new Set(group.items.map(i => i.quality).filter(Boolean))];
                const qltBadges = qualities.slice(0, 2).map(q => `<span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 fw-normal" style="font-size: 0.72rem;">${q}</span>`).join(' ');

                let serversHtml = '';
                group.items.forEach((src) => {
                    const url = src.url;
                    const srv = (src.server || 'Servidor Online').replace(/"/g, '&quot;');
                    const provName = (src.provider_name || group.rawProviderName || 'Streaming').replace(/"/g, '&quot;');
                    const lang = src.language ? `<span class="badge bg-secondary ms-1">${src.language}</span>` : '';
                    const qlt = src.quality ? `<span class="badge bg-success ms-1">${src.quality}</span>` : '';

                    serversHtml += `
                        <button type="button" class="btn btn-sm btn-outline-success stream-play-btn shadow-sm d-inline-flex align-items-center py-1 px-2" data-url="${url}" data-title="${safeTitle}" data-server="${srv}" data-provider="${provName}" data-thumbnail="${safeThumb}">
                            <i class="fas fa-play text-success me-1"></i><strong>${srv}</strong>
                            ${lang}
                            ${qlt}
                        </button>
                    `;
                });

                html += `
                    <div class="provider-collapse-card">
                        <button type="button" 
                                class="btn btn-dark w-100 text-start d-flex align-items-center justify-content-between p-2 provider-toggle-btn collapsed shadow-sm" 
                                data-bs-toggle="collapse" 
                                data-bs-target="#${collapseId}" 
                                aria-expanded="false" 
                                aria-controls="${collapseId}">
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <i class="${icon} fs-5"></i>
                                <span class="fw-bold text-white">${group.name}</span>
                                <span class="badge bg-success bg-opacity-75 text-white fw-bold">${count}</span>
                                <div class="d-none d-sm-flex align-items-center gap-1">
                                    ${langBadges}
                                    ${qltBadges}
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-secondary small d-none d-md-inline">Servidores</span>
                                <i class="fas fa-chevron-down toggle-chevron text-secondary"></i>
                            </div>
                        </button>
                        <div class="collapse" id="${collapseId}">
                            <div class="card card-body bg-black border border-secondary border-opacity-50 p-2 p-md-3 mt-1 rounded-3">
                                <div class="d-flex flex-wrap gap-2">
                                    ${serversHtml}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            html += `</div></div>`;
        }

        // 3. Sección Torrents / Magnets (Agrupada por Proveedor en Desplegables)
        if (torrentLinks.length > 0) {
            const torrentGroups = groupLinksByProvider(torrentLinks);
            html += `
                <div class="mb-2">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mb-2">
                        <h6 class="text-warning mb-0"><i class="fas fa-magnet me-1"></i> Descargas Torrent / Magnet (${torrentLinks.length} ${torrentLinks.length === 1 ? 'opción' : 'opciones'})</h6>
                        <small class="text-secondary"><i class="fas fa-hand-pointer me-1 text-warning"></i>Toca un proveedor para ver enlaces</small>
                    </div>
                    <div class="provider-groups-container d-flex flex-column gap-2">
            `;

            torrentGroups.forEach((group, gIdx) => {
                const collapseId = `${blockId}_torrent_${gIdx}`;
                const icon = getProviderIcon(group.providerId, 'torrent');
                const count = group.items.length;

                const qualities = [...new Set(group.items.map(i => i.quality).filter(Boolean))];
                const qltBadges = qualities.slice(0, 3).map(q => `<span class="badge bg-dark border border-warning text-warning fw-normal" style="font-size: 0.72rem;">${q}</span>`).join(' ');

                const langs = [...new Set(group.items.map(i => i.language || (i.lang === 'lat' ? 'Latino' : (i.lang === 'cast' ? 'Castellano' : (i.lang === 'sub' ? 'Subtitulado' : null)))).filter(Boolean))];
                const langBadges = langs.map(l => `<span class="badge bg-secondary bg-opacity-50 text-light fw-normal" style="font-size: 0.72rem;">${l}</span>`).join(' ');

                let torrentsHtml = '';
                group.items.forEach((src) => {
                    const url = src.url;
                    const srv = (src.server || 'BitTorrent Magnet').replace(/"/g, '&quot;');
                    const qlt = src.quality || 'HD';
                    const lang = src.language ? `<span class="badge bg-warning text-dark ms-1">${src.language}</span>` : '';
                    const size = src.size ? `<span class="badge bg-secondary ms-1">${src.size}</span>` : '';

                    if (url && url.startsWith('magnet:?')) {
                        torrentsHtml += `
                            <div class="d-inline-flex align-items-center gap-1 bg-dark bg-opacity-75 border border-secondary border-opacity-50 rounded p-1 mb-1">
                                <button type="button" class="btn btn-sm btn-warning text-dark fw-bold stream-torrent-btn py-1 px-2 shadow-sm" data-magnet="${url}" data-title="${safeTitle}" data-server="${srv}" data-provider="${group.name}" data-thumbnail="${safeThumb}" title="Reproducir directamente en el navegador online">
                                    <i class="fas fa-play-circle me-1"></i>
                                    <span>Ver en Reproductor</span>
                                    <span class="badge bg-black text-warning ms-1">${qlt}</span>
                                    ${size}
                                    ${lang}
                                </button>
                                <a href="${url}" class="btn btn-sm btn-outline-secondary text-warning py-1 px-2" title="Abrir en cliente torrent externo (qBittorrent / VLC)">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        `;
                    } else {
                        torrentsHtml += `
                            <a href="${url}" class="btn btn-sm btn-outline-warning shadow-sm d-inline-flex align-items-center py-1 px-2" title="Descargar torrent">
                                <i class="fas fa-download me-1"></i>
                                <strong class="me-1">${srv}</strong>
                                <span class="badge bg-dark border border-warning text-warning ms-1">${qlt}</span>
                                ${size}
                                ${lang}
                            </a>
                        `;
                    }
                });

                html += `
                    <div class="provider-collapse-card">
                        <button type="button" 
                                class="btn btn-dark w-100 text-start d-flex align-items-center justify-content-between p-2 provider-toggle-btn collapsed shadow-sm" 
                                data-bs-toggle="collapse" 
                                data-bs-target="#${collapseId}" 
                                aria-expanded="false" 
                                aria-controls="${collapseId}">
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <i class="${icon} fs-5"></i>
                                <span class="fw-bold text-white">${group.name}</span>
                                <span class="badge bg-warning text-dark fw-bold">${count}</span>
                                <div class="d-none d-sm-flex align-items-center gap-1">
                                    ${qltBadges}
                                    ${langBadges}
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-secondary small d-none d-md-inline">Torrents</span>
                                <i class="fas fa-chevron-down toggle-chevron text-secondary"></i>
                            </div>
                        </button>
                        <div class="collapse" id="${collapseId}">
                            <div class="card card-body bg-black border border-secondary border-opacity-50 p-2 p-md-3 mt-1 rounded-3">
                                <div class="d-flex flex-wrap gap-2">
                                    ${torrentsHtml}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            html += `</div></div>`;
        }

        return html;
    };

    const startProgressiveSourcesLoad = (targetContainer, requestParams, itemTitle, thumbnail, episodeMeta = null) => {
        const isAnimeContent = linksContainer?.dataset?.isAnime === '1';
        let rawProviders = (window._cachedProvidersList && window._cachedProvidersList.length > 0) ? window._cachedProvidersList : [
            { id: 'local_cdn', name: 'CDN Propio', type: 'direct' },
            { id: 'cuevana', name: 'Cuevana', type: 'streaming' },
            { id: 'pelispedia', name: 'PelisPedia', type: 'streaming' },
            { id: 'cinecalidad', name: 'Cinecalidad (Dual Latino)', type: 'mixed' },
            { id: 'thepiratebay', name: 'The Pirate Bay (Dual Latino)', type: 'torrent' },
            { id: 'yts', name: 'YTS (YIFY)', type: 'torrent' },
            { id: 'elitetorrent', name: 'EliteTorrent', type: 'torrent' },
            { id: 'dontorrent', name: 'DonTorrent', type: 'torrent' },
            { id: 'hacktorrent', name: 'HackTorrent', type: 'mixed' },
            { id: 'lamovie', name: 'LaMovie', type: 'mixed' },
            { id: 'allpeliculas', name: 'AllPeliculas', type: 'streaming' },
            { id: 'gnula', name: 'Gnula', type: 'streaming' },
            { id: 'serieskao', name: 'SeriesKao', type: 'streaming' },
            { id: 'retrotve', name: 'RetroTVE', type: 'streaming' },
            { id: 'anime', name: 'JKAnime', type: 'streaming' },
            { id: 'tioanime', name: 'TioAnime', type: 'streaming' },
            { id: 'nyaa', name: 'Nyaa (Anime Torrents)', type: 'torrent' }
        ];

        const providers = isAnimeContent ? rawProviders : rawProviders.filter(p => p.id !== 'anime' && p.id !== 'tioanime' && p.id !== 'nyaa');

        const totalProviders = providers.length;
        let completedProviders = 0;
        let totalLinksFound = 0;
        let streamLinksCount = 0;
        let torrentLinksCount = 0;
        let directLinksCount = 0;

        const seenUrls = new Set();
        const blockId = 'live_blk_' + (++blockUidCounter) + '_' + Math.random().toString(36).substring(2, 7);
        const safeTitle = (itemTitle || '').replace(/"/g, '&quot;');
        const safeThumb = (thumbnail || '').replace(/"/g, '&quot;');

        targetContainer.dataset.loading = 'true';
        targetContainer.dataset.loaded = 'false';

        targetContainer.innerHTML = `
            <div class="live-sources-controller">
                <!-- Banner de Progreso en Vivo -->
                <div class="sources-progress-banner p-3 mb-3" id="${blockId}_banner">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="spinner-border spinner-border-sm text-info progress-spinner" role="status"></div>
                            <i class="fas fa-check-circle text-success fs-5 progress-check d-none"></i>
                            <span class="progress-status-text fw-bold text-light small">
                                Buscando en servidores en vivo...
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-dark border border-secondary text-info progress-counter-badge">0 / ${totalProviders} proveedores</span>
                            <span class="badge bg-secondary progress-links-badge">0 enlaces</span>
                        </div>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Sección 1: CDN Propio / Enlaces Directos -->
                <div class="section-direct mb-3" style="display: none;">
                    <h6 class="text-info mb-2"><i class="fas fa-hdd me-1"></i> Servidor Propio (Descarga / Dispositivo)</h6>
                    <div class="direct-links-container d-flex flex-wrap gap-2"></div>
                </div>

                <!-- Sección 2: Streaming Online (Agrupada por Proveedor en Desplegables) -->
                <div class="section-streaming mb-3" style="display: none;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mb-2">
                        <h6 class="text-success mb-0"><i class="fas fa-play-circle me-1"></i> Reproducción Online (<span class="stream-count-text">0 servidores</span>)</h6>
                        <small class="text-secondary"><i class="fas fa-hand-pointer me-1 text-info"></i>Toca un proveedor para ver servidores</small>
                    </div>
                    <div class="streaming-groups-container d-flex flex-column gap-2"></div>
                </div>

                <!-- Sección 3: Descargas Torrent / Magnet -->
                <div class="section-torrent mb-2" style="display: none;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mb-2">
                        <h6 class="text-warning mb-0"><i class="fas fa-magnet me-1"></i> Descargas Torrent / Magnet (<span class="torrent-count-text">0 opciones</span>)</h6>
                        <small class="text-secondary"><i class="fas fa-hand-pointer me-1 text-warning"></i>Toca un proveedor para ver enlaces</small>
                    </div>
                    <div class="torrent-groups-container d-flex flex-column gap-2"></div>
                </div>

                <!-- Mensaje cuando termina sin enlaces -->
                <div class="sources-empty-state alert alert-dark text-light py-2 px-3 mb-0" style="display: none;">
                    <i class="fas fa-info-circle me-1 text-info"></i> No se encontraron enlaces para esta opción en los servidores consultados.
                </div>
            </div>
        `;

        const bannerEl = document.getElementById(`${blockId}_banner`);
        const spinnerEl = bannerEl.querySelector('.progress-spinner');
        const checkEl = bannerEl.querySelector('.progress-check');
        const statusTextEl = bannerEl.querySelector('.progress-status-text');
        const counterBadgeEl = bannerEl.querySelector('.progress-counter-badge');
        const linksBadgeEl = bannerEl.querySelector('.progress-links-badge');
        const progressBarEl = bannerEl.querySelector('.progress-bar');

        const secDirect = targetContainer.querySelector('.section-direct');
        const directBox = targetContainer.querySelector('.direct-links-container');

        const secStream = targetContainer.querySelector('.section-streaming');
        const streamBox = targetContainer.querySelector('.streaming-groups-container');
        const streamCountText = targetContainer.querySelector('.stream-count-text');

        const secTorrent = targetContainer.querySelector('.section-torrent');
        const torrentBox = targetContainer.querySelector('.torrent-groups-container');
        const torrentCountText = targetContainer.querySelector('.torrent-count-text');

        const emptyState = targetContainer.querySelector('.sources-empty-state');

        const updateProgressUI = () => {
            const pct = Math.min(100, Math.round((completedProviders / totalProviders) * 100));
            progressBarEl.style.width = `${pct}%`;
            counterBadgeEl.textContent = `${completedProviders} / ${totalProviders} proveedores`;
            linksBadgeEl.textContent = `${totalLinksFound} ${totalLinksFound === 1 ? 'enlace' : 'enlaces'}`;

            if (totalLinksFound > 0) {
                targetContainer.dataset.loaded = 'true';
            }

            if (completedProviders >= totalProviders) {
                targetContainer.dataset.loading = 'false';
                targetContainer.dataset.loaded = 'true';
                spinnerEl.classList.add('d-none');
                checkEl.classList.remove('d-none');
                bannerEl.classList.add('is-done');
                if (totalLinksFound > 0) {
                    statusTextEl.innerHTML = `<span class="text-success fw-bold"><i class="fas fa-check me-1"></i> Búsqueda finalizada</span> (${totalLinksFound} servidores disponibles)`;
                } else {
                    statusTextEl.innerHTML = `<span class="text-secondary">Búsqueda finalizada (sin resultados)</span>`;
                    emptyState.style.display = 'block';
                }
            }
        };

        const renderDirectItem = (src) => {
            const url = typeof src === 'string' ? src : src.url;
            const srv = src.server || 'CDN Propio';
            const qlt = src.quality || 'HD';
            const provName = src.provider_name || 'CDN Propio';

            const a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.className = 'btn btn-sm btn-primary shadow-sm d-inline-flex align-items-center pop-in-card';
            a.innerHTML = `
                <span class="badge bg-dark text-info me-1"><i class="fas fa-cloud me-1"></i>${provName}</span>
                <i class="fas fa-download me-1"></i> ${srv} <span class="badge bg-black ms-1">${qlt}</span>
            `;
            a.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            directBox.appendChild(a);
            secDirect.style.display = 'block';
        };

        const renderStreamGroup = (group) => {
            const collapseId = `${blockId}_stream_${group.providerId}_${Math.random().toString(36).substring(2, 6)}`;
            const icon = getProviderIcon(group.providerId, 'streaming');
            const count = group.items.length;

            const langs = [...new Set(group.items.map(i => i.language || (i.lang === 'lat' ? 'Latino' : (i.lang === 'cast' ? 'Castellano' : (i.lang === 'sub' ? 'Subtitulado' : null)))).filter(Boolean))];
            const langBadges = langs.map(l => `<span class="badge bg-dark border border-secondary text-info fw-normal" style="font-size: 0.72rem;">${l}</span>`).join(' ');

            const qualities = [...new Set(group.items.map(i => i.quality).filter(Boolean))];
            const qltBadges = qualities.slice(0, 2).map(q => `<span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 fw-normal" style="font-size: 0.72rem;">${q}</span>`).join(' ');

            const cardWrapper = document.createElement('div');
            cardWrapper.className = 'provider-collapse-card pop-in-card';
            cardWrapper.innerHTML = `
                <button type="button" 
                        class="btn btn-dark w-100 text-start d-flex align-items-center justify-content-between p-2 provider-toggle-btn collapsed shadow-sm" 
                        data-bs-toggle="collapse" 
                        data-bs-target="#${collapseId}" 
                        aria-expanded="false" 
                        aria-controls="${collapseId}">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <i class="${icon} fs-5"></i>
                        <span class="fw-bold text-white">${group.name}</span>
                        <span class="badge bg-success bg-opacity-75 text-white fw-bold">${count}</span>
                        <div class="d-none d-sm-flex align-items-center gap-1">
                            ${langBadges}
                            ${qltBadges}
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-secondary small d-none d-md-inline">Servidores</span>
                        <i class="fas fa-chevron-down toggle-chevron text-secondary"></i>
                    </div>
                </button>
                <div class="collapse" id="${collapseId}">
                    <div class="card card-body bg-black border border-secondary border-opacity-50 p-2 p-md-3 mt-1 rounded-3">
                        <div class="d-flex flex-wrap gap-2 servers-slot"></div>
                    </div>
                </div>
            `;

            const slot = cardWrapper.querySelector('.servers-slot');
            group.items.forEach(src => {
                const url = src.url;
                const srv = (src.server || 'Servidor Online').replace(/"/g, '&quot;');
                const provName = (src.provider_name || group.rawProviderName || 'Streaming').replace(/"/g, '&quot;');
                const lang = src.language ? `<span class="badge bg-secondary ms-1">${src.language}</span>` : '';
                const qlt = src.quality ? `<span class="badge bg-success ms-1">${src.quality}</span>` : '';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-success stream-play-btn shadow-sm d-inline-flex align-items-center py-1 px-2';
                btn.innerHTML = `<i class="fas fa-play text-success me-1"></i><strong>${srv}</strong> ${lang} ${qlt}`;
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    openStreamModal(url, safeTitle, srv, provName, safeThumb, episodeMeta || { isMovie: true });
                });
                slot.appendChild(btn);
            });

            streamBox.appendChild(cardWrapper);
            secStream.style.display = 'block';
            streamCountText.textContent = `${streamLinksCount} ${streamLinksCount === 1 ? 'servidor' : 'servidores'}`;
        };

        const renderTorrentGroup = (group) => {
            const collapseId = `${blockId}_torrent_${group.providerId}_${Math.random().toString(36).substring(2, 6)}`;
            const icon = getProviderIcon(group.providerId, 'torrent');
            const count = group.items.length;

            const qualities = [...new Set(group.items.map(i => i.quality).filter(Boolean))];
            const qltBadges = qualities.slice(0, 3).map(q => `<span class="badge bg-dark border border-warning text-warning fw-normal" style="font-size: 0.72rem;">${q}</span>`).join(' ');

            const langs = [...new Set(group.items.map(i => i.language || (i.lang === 'lat' ? 'Latino' : (i.lang === 'cast' ? 'Castellano' : (i.lang === 'sub' ? 'Subtitulado' : null)))).filter(Boolean))];
            const langBadges = langs.map(l => `<span class="badge bg-secondary bg-opacity-50 text-light fw-normal" style="font-size: 0.72rem;">${l}</span>`).join(' ');

            const cardWrapper = document.createElement('div');
            cardWrapper.className = 'provider-collapse-card pop-in-card';
            cardWrapper.innerHTML = `
                <button type="button" 
                        class="btn btn-dark w-100 text-start d-flex align-items-center justify-content-between p-2 provider-toggle-btn collapsed shadow-sm" 
                        data-bs-toggle="collapse" 
                        data-bs-target="#${collapseId}" 
                        aria-expanded="false" 
                        aria-controls="${collapseId}">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <i class="${icon} fs-5"></i>
                        <span class="fw-bold text-white">${group.name}</span>
                        <span class="badge bg-warning text-dark fw-bold">${count}</span>
                        <div class="d-none d-sm-flex align-items-center gap-1">
                            ${qltBadges}
                            ${langBadges}
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-secondary small d-none d-md-inline">Torrents</span>
                        <i class="fas fa-chevron-down toggle-chevron text-secondary"></i>
                    </div>
                </button>
                <div class="collapse" id="${collapseId}">
                    <div class="card card-body bg-black border border-secondary border-opacity-50 p-2 p-md-3 mt-1 rounded-3">
                        <div class="d-flex flex-wrap gap-2 torrents-slot"></div>
                    </div>
                </div>
            `;

            const slot = cardWrapper.querySelector('.torrents-slot');
            group.items.forEach(src => {
                const url = src.url;
                const srv = (src.server || 'BitTorrent Magnet').replace(/"/g, '&quot;');
                const qlt = src.quality || 'HD';
                const lang = src.language ? `<span class="badge bg-warning text-dark ms-1">${src.language}</span>` : '';
                const size = src.size ? `<span class="badge bg-secondary ms-1">${src.size}</span>` : '';
                const seeds = (src.seeders !== undefined && src.seeders !== null && src.seeders > 0) ? `<span class="badge bg-dark border border-success text-success ms-1"><i class="fas fa-arrow-up me-1"></i>${src.seeders}</span>` : '';

                const itemBox = document.createElement('div');
                itemBox.className = 'd-inline-flex align-items-center gap-1 bg-dark bg-opacity-75 border border-secondary border-opacity-50 rounded p-1 pop-in-card mb-1';

                if (url && url.startsWith('magnet:?')) {
                    // Botón principal: Ver en ArtPlayer con streaming secuencial
                    const btnPlay = document.createElement('button');
                    btnPlay.type = 'button';
                    btnPlay.className = 'btn btn-sm btn-warning text-dark fw-bold d-inline-flex align-items-center py-1 px-2 shadow-sm';
                    btnPlay.title = 'Reproducir directamente en el navegador online';
                    btnPlay.innerHTML = `
                        <i class="fas fa-play-circle me-1"></i>
                        <span>Ver en Reproductor</span>
                        <span class="badge bg-black text-warning ms-1">${qlt}</span>
                        ${size}
                        ${lang}
                        ${seeds}
                    `;
                    btnPlay.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openTorrentStreamModal(url, safeTitle, srv, src.provider_name || 'Torrent', safeThumb, episodeMeta);
                    });
                    itemBox.appendChild(btnPlay);

                    // Botón secundario: Enlace externo para qBittorrent o VLC
                    const aExt = document.createElement('a');
                    aExt.href = url;
                    aExt.className = 'btn btn-sm btn-outline-secondary text-warning py-1 px-2';
                    aExt.title = 'Abrir en cliente torrent externo (qBittorrent / VLC)';
                    aExt.innerHTML = '<i class="fas fa-external-link-alt"></i>';
                    aExt.addEventListener('click', (e) => {
                        e.stopPropagation();
                    });
                    itemBox.appendChild(aExt);
                } else {
                    const a = document.createElement('a');
                    a.href = url;
                    a.className = 'btn btn-sm btn-outline-warning shadow-sm d-inline-flex align-items-center py-1 px-2';
                    a.title = 'Descargar torrent';
                    a.innerHTML = `
                        <i class="fas fa-download me-1"></i>
                        <strong class="me-1">${srv}</strong>
                        <span class="badge bg-dark border border-warning text-warning ms-1">${qlt}</span>
                        ${size}
                        ${lang}
                        ${seeds}
                    `;
                    a.addEventListener('click', (e) => {
                        e.stopPropagation();
                    });
                    itemBox.appendChild(a);
                }

                slot.appendChild(itemBox);
            });

            torrentBox.appendChild(cardWrapper);
            secTorrent.style.display = 'block';
            torrentCountText.textContent = `${torrentLinksCount} ${torrentLinksCount === 1 ? 'opción' : 'opciones'}`;
        };

        // 1. Ordenar proveedores por prioridad y velocidad esperada
        const priorityOrder = [
            'local_cdn', 'cinecalidad', 'thepiratebay', 'yts', 'cuevana', 'allpeliculas', 
            'pelispedia', 'hacktorrent', 'elitetorrent', 'dontorrent', 
            'lamovie', 'gnula', 'serieskao', 'retrotve', 'anime', 'tioanime', 'nyaa'
        ];
        
        const sortedProviders = [...providers].sort((a, b) => {
            const idxA = priorityOrder.indexOf(a.id);
            const idxB = priorityOrder.indexOf(b.id);
            const valA = idxA === -1 ? 99 : idxA;
            const valB = idxB === -1 ? 99 : idxB;
            return valA - valB;
        });

        // 2. Pool de concurrencia equilibrada (6 workers en paralelo)
        // Punto óptimo: máxima velocidad de carga sin sobrecargar los procesos del hosting.
        const MAX_CONCURRENT = 6;
        const queue = [...sortedProviders];

        const fetchSingleProvider = async (prov, isRetry = false) => {
            const queryParams = new URLSearchParams({
                ...requestParams,
                provider: prov.id
            });

            try {
                const r = await fetch(`api/get_links.php?${queryParams.toString()}`, { signal: abortController.signal });
                
                // Si el servidor devuelve 503 o 508 (límite temporal de hosting), reintentar una vez tras 400ms
                if (!r.ok && (r.status === 503 || r.status === 508) && !isRetry) {
                    await new Promise(res => setTimeout(res, 400));
                    return fetchSingleProvider(prov, true);
                }

                if (r.ok) {
                    const data = await r.json();
                    if (data && data.links) {
                        const direct = (data.links.direct || []).filter(item => {
                            const u = item.url || item;
                            if (seenUrls.has(u)) return false;
                            seenUrls.add(u);
                            return true;
                        });

                        const streaming = (data.links.streaming || []).filter(item => {
                            const u = item.url;
                            if (seenUrls.has(u)) return false;
                            seenUrls.add(u);
                            return true;
                        });

                        const torrent = (data.links.torrent || []).filter(item => {
                            const u = item.url;
                            if (seenUrls.has(u)) return false;
                            seenUrls.add(u);
                            return true;
                        });

                        if (direct.length > 0) {
                            directLinksCount += direct.length;
                            totalLinksFound += direct.length;
                            direct.forEach(renderDirectItem);
                        }

                        if (streaming.length > 0) {
                            streamLinksCount += streaming.length;
                            totalLinksFound += streaming.length;
                            const groups = groupLinksByProvider(streaming);
                            groups.forEach(renderStreamGroup);
                        }

                        if (torrent.length > 0) {
                            torrentLinksCount += torrent.length;
                            totalLinksFound += torrent.length;
                            const groups = groupLinksByProvider(torrent);
                            groups.forEach(renderTorrentGroup);
                        }
                    }
                }
            } catch (err) {
                // Si fue un fallo transitorio de red no abortado por el usuario, reintentar ágilmente
                if (!isRetry && err.name !== 'AbortError' && !abortController.signal.aborted) {
                    try {
                        await new Promise(res => setTimeout(res, 400));
                        return await fetchSingleProvider(prov, true);
                    } catch (e) {}
                }
            } finally {
                if (!isRetry) {
                    completedProviders++;
                    updateProgressUI();
                }
            }
        };

        const worker = async () => {
            while (queue.length > 0) {
                if (abortController.signal.aborted) break;
                const prov = queue.shift();
                await fetchSingleProvider(prov);
            }
        };

        // Iniciar hasta 6 workers en paralelo con micro-espaciado de 30ms para suavizar el inicio
        const activeWorkers = Math.min(MAX_CONCURRENT, sortedProviders.length);
        for (let i = 0; i < activeWorkers; i++) {
            if (i === 0) {
                worker();
            } else {
                setTimeout(() => {
                    if (!abortController.signal.aborted) worker();
                }, i * 30);
            }
        }
    };

    const loadEpisodeSources = (seasonNum, episodeNum, container, itemTitle, epThumbnail, absoluteNum = null, forceReload = false) => {
        // Evitar reiniciar peticiones si las fuentes ya están cargándose o listas
        if (!forceReload && (container.dataset.loading === 'true' || container.dataset.loaded === 'true')) {
            console.log(`%c⚡ [Fuentes] Fuentes ya solicitadas o disponibles para S${seasonNum}E${episodeNum}`, 'color: #ffc107;');
            return;
        }
        const epCard = container.closest('.episode-card');
        const absNum = absoluteNum || epCard?.dataset?.absolute || episodeNum;
        const requestParams = { id: contentId, type: 'tv', season: seasonNum, episode: episodeNum };
        if (absNum) {
            requestParams.absolute = absNum;
        }
        startProgressiveSourcesLoad(
            container,
            requestParams,
            itemTitle,
            epThumbnail,
            { season: seasonNum, episode: episodeNum, absolute: absNum, cardElement: epCard }
        );
    };

    console.log(`%c🔍 [Detalles] Consultando contenido "${contentId}" (${contentType})...`, 'color: #0dcaf0; font-weight: bold;');

    fetch(`api/get_links.php?id=${contentId}&type=${contentType}&metadata_only=1`, {
        signal: abortController.signal
    })
        .then(response => {
            if (!response.ok) throw new Error(`Error de red: ${response.statusText}`);
            return response.json();
        })
        .then(async data => {
            linksContainer.innerHTML = '';

            if (data.providers && Array.isArray(data.providers)) {
                window._cachedProvidersList = data.providers;
            }

            // Obtener progreso de episodios vistos
            let watchedProgress = {};
            try {
                const progRes = await fetch(`api/get_progress.php?id=${contentId}&type=${contentType}`);
                watchedProgress = await progRes.json();
            } catch (e) {}
            window._watchedProgressCache = watchedProgress;

            if (contentType === 'movie') {
                const movieTitle = `${data.title || 'Película'}${data.year ? ' (' + data.year + ')' : ''}`;
                const movieThumb = data.backdrop || data.poster || linksContainer.dataset.backdrop || linksContainer.dataset.poster || '';
                const isAnime = linksContainer.dataset.isAnime === '1';
                
                const movieStatus = typeof watchedProgress === 'string' ? watchedProgress : (watchedProgress?.status || 'unwatched');
                let movieStatusBadge = '<span id="movieStatusBadge" class="badge bg-secondary badge-status">No visto</span>';
                if (movieStatus === 'watched') movieStatusBadge = '<span id="movieStatusBadge" class="badge bg-success badge-status"><i class="fas fa-check-circle me-1"></i> Visto</span>';
                else if (movieStatus === 'partially_watched') movieStatusBadge = '<span id="movieStatusBadge" class="badge bg-info badge-status"><i class="fas fa-eye me-1"></i> Viendo</span>';

                let movieCardHtml = `
                    <div class="card bg-black border border-secondary shadow-lg overflow-hidden mb-3">
                        <div class="row g-0">
                `;

                if (movieThumb) {
                    movieCardHtml += `
                        <div class="col-12 col-md-5 col-lg-4 p-2 p-md-3">
                            <div class="movie-thumb-box shadow-sm position-relative">
                                <img src="${movieThumb}" alt="${movieTitle.replace(/"/g, '&quot;')}" class="movie-thumb-img" loading="lazy">
                                <div class="movie-thumb-overlay">
                                    <div class="episode-thumb-play-icon"><i class="fas fa-play"></i></div>
                                </div>
                                <span class="badge ${isAnime ? 'bg-danger' : 'bg-primary'} position-absolute top-0 start-0 m-2 shadow-sm">
                                    ${isAnime ? '<i class="fas fa-dragon me-1"></i> Anime' : '<i class="fas fa-film me-1"></i> Película'}
                                </span>
                                ${data.runtime ? `<span class="badge bg-black bg-opacity-75 text-white position-absolute bottom-0 end-0 m-2 small"><i class="far fa-clock me-1"></i>${data.runtime} min</span>` : ''}
                            </div>
                        </div>
                        <div class="col-12 col-md-7 col-lg-8 p-3 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                    <h5 class="fw-bold text-white mb-0">${movieTitle}</h5>
                                    <div class="d-flex align-items-center gap-2">
                                        ${movieStatusBadge}
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-info mark-movie-partially" title="Marcar como viendo"><i class="fas fa-eye"></i></button>
                                            <button type="button" class="btn btn-outline-success mark-movie-watched" title="Marcar como visto"><i class="fas fa-check"></i></button>
                                        </div>
                                    </div>
                                </div>
                                ${data.overview ? `<p class="text-secondary small mb-3 text-truncate-2" style="color: #cbd5e1 !important; line-height: 1.45;" title="${data.overview.replace(/"/g, '&quot;')}">${data.overview}</p>` : ''}
                            </div>
                            <div class="sources-wrapper" id="movie-sources-wrapper"></div>
                        </div>
                    `;
                } else {
                    movieCardHtml += `
                        <div class="col-12 p-3">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                <h5 class="fw-bold text-white mb-0">${movieTitle}</h5>
                                <div class="d-flex align-items-center gap-2">
                                    ${movieStatusBadge}
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-info mark-movie-partially" title="Marcar como viendo"><i class="fas fa-eye"></i></button>
                                        <button type="button" class="btn btn-outline-success mark-movie-watched" title="Marcar como visto"><i class="fas fa-check"></i></button>
                                    </div>
                                </div>
                            </div>
                            <div class="sources-wrapper" id="movie-sources-wrapper"></div>
                        </div>
                    `;
                }

                movieCardHtml += `
                        </div>
                    </div>
                `;
                linksContainer.innerHTML = movieCardHtml;

                // Eventos de marcar visto / viendo en película
                const btnMovieWatched = linksContainer.querySelector('.mark-movie-watched');
                if (btnMovieWatched) {
                    btnMovieWatched.addEventListener('click', () => {
                        sendProgressUpdate(null, null, 'watched');
                        window._watchedProgressCache = { status: 'watched' };
                        updateMovieUI('watched');
                    });
                }
                const btnMoviePartially = linksContainer.querySelector('.mark-movie-partially');
                if (btnMoviePartially) {
                    btnMoviePartially.addEventListener('click', () => {
                        sendProgressUpdate(null, null, 'partially_watched');
                        window._watchedProgressCache = { status: 'partially_watched' };
                        updateMovieUI('partially_watched');
                    });
                }

                // Click en miniatura de película para reproducir primer stream disponible
                const movieThumbBox = linksContainer.querySelector('.movie-thumb-box');
                if (movieThumbBox) {
                    movieThumbBox.addEventListener('click', () => {
                        const firstPlayBtn = linksContainer.querySelector('.stream-play-btn');
                        if (firstPlayBtn) firstPlayBtn.click();
                    });
                }

                // Disparar carga progresiva de fuentes en vivo
                const movieSourcesWrapper = document.getElementById('movie-sources-wrapper');
                if (movieSourcesWrapper) {
                    startProgressiveSourcesLoad(movieSourcesWrapper, { id: contentId, type: 'movie' }, movieTitle, movieThumb, { isMovie: true });
                }

            } else {
                // Series: acordeón de temporadas y episodios con miniaturas
                const seasons = data.links || {};
                const seasonKeys = Object.keys(seasons);

                if (seasonKeys.length === 0) {
                    linksContainer.innerHTML = `<div class="alert alert-dark text-light"><i class="fas fa-info-circle me-2 text-warning"></i>No se encontraron temporadas disponibles.</div>`;
                    return;
                }

                // Determinar el último capítulo visto o en progreso
                let lastWatched = null;

                // 1. Intentar desde _last_watched en respuesta de progreso
                if (watchedProgress && typeof watchedProgress === 'object') {
                    if (watchedProgress._last_watched && watchedProgress._last_watched.season && watchedProgress._last_watched.episode) {
                        const sNum = parseInt(watchedProgress._last_watched.season, 10);
                        const eNum = parseInt(watchedProgress._last_watched.episode, 10);
                        const sPad = 'T' + String(sNum).padStart(2, '0');
                        const ePad = 'E' + String(eNum).padStart(2, '0');
                        if (seasons[sPad] && seasons[sPad].episodes && seasons[sPad].episodes[ePad]) {
                            lastWatched = {
                                season: sNum,
                                episode: eNum,
                                seasonKey: sPad,
                                episodeKey: ePad,
                                status: watchedProgress._last_watched.status || 'partially_watched',
                                epData: seasons[sPad].episodes[ePad]
                            };
                        }
                    }
                }

                // 2. Intentar desde localStorage si aún no se determinó
                if (!lastWatched) {
                    try {
                        const localSaved = localStorage.getItem(`last_watched_${contentType}_${contentId}`);
                        if (localSaved) {
                            const parsed = JSON.parse(localSaved);
                            if (parsed && parsed.season && parsed.episode) {
                                const sNum = parseInt(parsed.season, 10);
                                const eNum = parseInt(parsed.episode, 10);
                                const sPad = 'T' + String(sNum).padStart(2, '0');
                                const ePad = 'E' + String(eNum).padStart(2, '0');
                                if (seasons[sPad] && seasons[sPad].episodes && seasons[sPad].episodes[ePad]) {
                                    lastWatched = {
                                        season: sNum,
                                        episode: eNum,
                                        seasonKey: sPad,
                                        episodeKey: ePad,
                                        status: parsed.status || 'partially_watched',
                                        epData: seasons[sPad].episodes[ePad]
                                    };
                                }
                            }
                        }
                    } catch (e) {}
                }

                // 3. Fallback: Escanear todas las temporadas por el último episodio marcado como watched o partially_watched
                if (!lastWatched && watchedProgress && typeof watchedProgress === 'object') {
                    let highestCandidate = null;
                    seasonKeys.forEach(sKey => {
                        const sData = seasons[sKey];
                        const sNum = parseInt(sData.season_number, 10);
                        const episodes = sData.episodes || {};
                        Object.keys(episodes).forEach(epKey => {
                            const ep = episodes[epKey];
                            const eNum = parseInt(ep.episode_number, 10);
                            const st = watchedProgress?.[sNum]?.[eNum];
                            if (st === 'partially_watched' || st === 'watched') {
                                highestCandidate = {
                                    season: sNum,
                                    episode: eNum,
                                    seasonKey: sKey,
                                    episodeKey: epKey,
                                    status: st,
                                    epData: ep
                                };
                            }
                        });
                    });
                    if (highestCandidate) {
                        lastWatched = highestCandidate;
                    }
                }

                // La temporada a desplegar inicialmente: la del último visto o la primera si no ha visto nada
                const activeSeasonKey = lastWatched ? lastWatched.seasonKey : (seasonKeys[0] || null);

                let accordionHtml = '';

                // Banner destacado "Continuar Viendo" si existe progreso previo
                if (lastWatched && lastWatched.epData) {
                    const fallbackThumb = data.backdrop || data.poster || linksContainer.dataset.backdrop || linksContainer.dataset.poster || '';
                    const resumeThumb = lastWatched.epData.still_path || fallbackThumb;
                    const resumeTitle = lastWatched.epData.name || `Episodio ${lastWatched.episode}`;
                    const resumeStatusBadge = lastWatched.status === 'watched'
                        ? '<span class="badge bg-success badge-status" style="font-size: 0.72rem;"><i class="fas fa-check-circle me-1"></i> Visto</span>'
                        : '<span class="badge bg-info badge-status" style="font-size: 0.72rem;"><i class="fas fa-eye me-1"></i> Viendo</span>';

                    accordionHtml += `
                        <div class="continue-watching-banner card border-primary mb-3 shadow-lg overflow-hidden position-relative" style="background: linear-gradient(135deg, rgba(13, 110, 253, 0.22) 0%, rgba(18, 20, 26, 0.96) 65%); border-left: 4px solid #0d6efd !important;">
                            <div class="card-body p-2 p-sm-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2 gap-sm-3 flex-grow-1" style="min-width: 0;">
                                    <div class="continue-thumb-box position-relative rounded overflow-hidden shadow-sm flex-shrink-0" style="width: 74px; height: 42px; background: #111;">
                                        ${resumeThumb ? `<img src="${resumeThumb}" alt="${resumeTitle.replace(/"/g, '&quot;')}" class="w-100 h-100 object-fit-cover">` : ''}
                                        <div class="position-absolute top-50 start-50 translate-middle text-white" style="font-size: 0.75rem; text-shadow: 0 1px 4px rgba(0,0,0,0.8);">
                                            <i class="fas fa-play"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 text-truncate" style="min-width: 0;">
                                        <div class="d-flex align-items-center gap-1 mb-1 flex-wrap">
                                            <span class="badge bg-primary text-uppercase" style="font-size: 0.68rem;"><i class="fas fa-history me-1"></i>Continuar Viendo</span>
                                            ${resumeStatusBadge}
                                        </div>
                                        <h6 class="text-white mb-0 fw-bold text-truncate" style="font-size: 0.9rem;" title="${resumeTitle.replace(/"/g, '&quot;')}">
                                            T${lastWatched.season}:E${lastWatched.episode} · ${resumeTitle}
                                        </h6>
                                    </div>
                                </div>
                                <div class="flex-shrink-0">
                                    <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 py-1.5 shadow-sm resume-continue-btn d-inline-flex align-items-center gap-1" data-season="${lastWatched.season}" data-episode="${lastWatched.episode}">
                                        <i class="fas fa-play"></i> <span>Reanudar</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                }

                accordionHtml += `<div class="accordion" id="seasonsAccordion">`;

                seasonKeys.forEach((sKey) => {
                    const sData = seasons[sKey];
                    const sNum = sData.season_number;
                    const sName = sData.name || `Temporada ${sNum}`;
                    const episodes = sData.episodes || {};
                    const epKeys = Object.keys(episodes);
                    const isExpanded = sKey === activeSeasonKey;

                    accordionHtml += `
                        <div class="accordion-item bg-dark border-secondary mb-2 rounded overflow-hidden shadow-sm">
                            <h2 class="accordion-header" id="heading${sKey}">
                                <button class="accordion-button text-white ${isExpanded ? '' : 'collapsed'}" type="button" data-bs-toggle="collapse" data-bs-target="#collapse${sKey}" aria-expanded="${isExpanded ? 'true' : 'false'}">
                                    <i class="fas fa-folder-open me-2 text-warning"></i> <strong>${sName}</strong> <span class="badge bg-dark border border-secondary text-light ms-2">${epKeys.length} capítulos</span>
                                </button>
                            </h2>
                            <div id="collapse${sKey}" class="accordion-collapse collapse ${isExpanded ? 'show' : ''}" data-bs-parent="#seasonsAccordion">
                                <div class="accordion-body bg-dark text-light p-2 p-md-3">
                                    <div class="row g-2 g-md-3">
                    `;

                    epKeys.forEach(epKey => {
                        const ep = episodes[epKey];
                        const epNum = ep.episode_number;
                        const absNum = ep.absolute_number || epNum;
                        const epName = ep.name || `Episodio ${epNum}`;
                        const epAir = ep.air_date ? `<small class="text-secondary ms-1">(${ep.air_date})</small>` : '';
                        const epRuntime = ep.runtime ? `<span class="badge bg-black bg-opacity-75 text-white position-absolute bottom-0 end-0 m-1 small" style="font-size: 0.72rem;"><i class="far fa-clock me-1 text-info"></i>${ep.runtime}m</span>` : '';
                        const epOverview = ep.overview ? ep.overview : '';
                        const currentStatus = watchedProgress?.[sNum]?.[epNum] || 'unwatched';

                        // Miniatura del episodio con fallback inteligente (backdrop o póster de serie/anime)
                        const fallbackThumb = data.backdrop || data.poster || linksContainer.dataset.backdrop || linksContainer.dataset.poster || '';
                        const epThumb = ep.still_path || fallbackThumb;

                        let statusBadge = '<span class="badge bg-secondary badge-status text-light" style="font-size: 0.72rem;">No visto</span>';
                        if (currentStatus === 'watched') statusBadge = '<span class="badge bg-success badge-status" style="font-size: 0.72rem;"><i class="fas fa-check-circle me-1"></i> Visto</span>';
                        else if (currentStatus === 'partially_watched') statusBadge = '<span class="badge bg-info badge-status" style="font-size: 0.72rem;"><i class="fas fa-eye me-1"></i> Viendo</span>';

                        accordionHtml += `
                            <div class="col-12">
                                <div class="card episode-card p-2 p-md-3 rounded shadow-sm" data-season="${sNum}" data-episode="${epNum}" data-absolute="${absNum}">
                                    <div class="row g-2 g-md-3 align-items-center">
                                        <!-- Miniatura 16:9 del Episodio -->
                                        <div class="col-5 col-sm-4 col-md-3 col-lg-3">
                                            <div class="episode-thumb-box shadow-sm" data-season="${sNum}" data-episode="${epNum}" data-absolute="${absNum}" data-epname="${(epName || '').replace(/"/g, '&quot;')}" data-thumb="${(epThumb || '').replace(/"/g, '&quot;')}" title="Ver fuentes de este episodio">
                                                ${epThumb ? `
                                                    <img src="${epThumb}" alt="${epName.replace(/"/g, '&quot;')}" class="episode-thumb-img" loading="lazy" onerror="this.onerror=null; this.src='${fallbackThumb}';">
                                                ` : `
                                                    <div class="episode-thumb-placeholder">
                                                        <i class="fas fa-film fs-5 mb-1 text-secondary"></i>
                                                        <span class="small fw-bold text-light">E${epNum}</span>
                                                    </div>
                                                `}
                                                <span class="badge bg-warning text-dark fw-bold position-absolute top-0 start-0 m-1 shadow-sm" style="font-size: 0.72rem;">E${epNum}</span>
                                                ${epRuntime}
                                                <div class="episode-thumb-overlay">
                                                    <div class="episode-thumb-play-icon"><i class="fas fa-play"></i></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Información y Acciones del Episodio -->
                                        <div class="col-7 col-sm-8 col-md-9 col-lg-9">
                                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                                <div class="flex-grow-1" style="min-width: 0;">
                                                    <div class="d-flex align-items-center flex-wrap gap-1 gap-md-2 mb-1">
                                                        <strong class="text-white text-truncate d-inline-block" style="max-width: 100%; font-size: 0.95rem;">${epName}</strong>
                                                        ${epAir}
                                                        ${statusBadge}
                                                    </div>
                                                    ${epOverview ? `<p class="text-secondary small mb-0 text-truncate-2 d-none d-sm-block" style="line-height: 1.35; color: #94a3b8 !important;" title="${epOverview.replace(/"/g, '&quot;')}">${epOverview}</p>` : ''}
                                                </div>
                                                <div class="btn-group btn-group-sm flex-shrink-0 ms-1">
                                                    <button type="button" class="btn btn-outline-info mark-partially-watched py-1 px-2" title="Marcar como viendo"><i class="fas fa-eye"></i></button>
                                                    <button type="button" class="btn btn-outline-success mark-watched py-1 px-2" title="Marcar como visto"><i class="fas fa-check"></i></button>
                                                    <button type="button" class="btn btn-primary load-sources-btn py-1 px-2 px-md-3 d-none d-md-inline-flex align-items-center" data-season="${sNum}" data-episode="${epNum}" data-absolute="${absNum}" data-epname="${(epName || '').replace(/"/g, '&quot;')}" data-thumb="${(epThumb || '').replace(/"/g, '&quot;')}">
                                                        <i class="fas fa-search me-1"></i> Fuentes
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <!-- Botón Fuentes visible en móviles -->
                                            <div class="d-md-none mt-1">
                                                <button type="button" class="btn btn-primary btn-sm w-100 load-sources-btn py-1" data-season="${sNum}" data-episode="${epNum}" data-absolute="${absNum}" data-epname="${(epName || '').replace(/"/g, '&quot;')}" data-thumb="${(epThumb || '').replace(/"/g, '&quot;')}">
                                                    <i class="fas fa-search me-1"></i> Buscar Fuentes
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Contenedor de Fuentes Expandible -->
                                    <div class="sources-container mt-2 pt-2 border-top border-secondary border-opacity-50" id="sources_s${sNum}_e${epNum}">
                                        <div class="text-secondary small py-1" style="color: #94a3b8 !important;"><i class="fas fa-info-circle me-1 text-info"></i> Haz clic en la miniatura o en "Fuentes" para consultar servidores online, torrents y CDN.</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    accordionHtml += `
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });

                accordionHtml += `</div>`;
                linksContainer.innerHTML = accordionHtml;

                // Estabilización de scroll al colapsar/desplegar temporadas
                const seasonsAccordion = document.getElementById('seasonsAccordion');
                if (seasonsAccordion) {
                    const isDirectSeasonCollapse = (target) => {
                        if (!target) return false;
                        // Solo procesar colapsos directos de temporadas (hijos directos de seasonsAccordion)
                        // para NO alterar el scroll al desplegar proveedores, servidores o torrents dentro de un capítulo
                        return target.classList.contains('accordion-collapse') && 
                               target.getAttribute('data-bs-parent') === '#seasonsAccordion' &&
                               target.parentElement && target.parentElement.parentElement === seasonsAccordion;
                    };

                    seasonsAccordion.addEventListener('show.bs.collapse', (e) => {
                        // IGNORAR ABSOLUTAMENTE eventos de proveedores, servidores o torrents internos
                        if (!isDirectSeasonCollapse(e.target)) return;

                        const item = e.target.closest('.accordion-item');
                        if (!item || item.parentElement !== seasonsAccordion) return;

                        // Anclar activamente la vista al encabezado de la temporada que se está abriendo
                        // para evitar que el colapso de la temporada previa desplace bruscamente la pantalla al final
                        const startTime = performance.now();
                        const duration = 380; // ms (cubre la animación de colapso de 350ms de Bootstrap)

                        function lockScrollToHeader(now) {
                            item.scrollIntoView({ behavior: 'auto', block: 'start' });
                            if (now - startTime < duration) {
                                requestAnimationFrame(lockScrollToHeader);
                            }
                        }
                        requestAnimationFrame(lockScrollToHeader);
                    });

                    seasonsAccordion.addEventListener('shown.bs.collapse', (e) => {
                        // IGNORAR ABSOLUTAMENTE eventos de proveedores, servidores o torrents internos
                        if (!isDirectSeasonCollapse(e.target)) return;

                        const item = e.target.closest('.accordion-item');
                        if (item && item.parentElement === seasonsAccordion) {
                            setTimeout(() => {
                                item.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }, 30);
                        }
                    });
                }

                // Eventos de marcar visto / viendo
                linksContainer.querySelectorAll('.mark-watched').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const card = e.currentTarget.closest('.episode-card');
                        const sNum = card.dataset.season;
                        const eNum = card.dataset.episode;
                        sendProgressUpdate(sNum, eNum, 'watched');
                        if (!window._watchedProgressCache) window._watchedProgressCache = {};
                        if (!window._watchedProgressCache[sNum]) window._watchedProgressCache[sNum] = {};
                        window._watchedProgressCache[sNum][eNum] = 'watched';
                        updateEpisodeUI(card, 'watched');
                    });
                });

                linksContainer.querySelectorAll('.mark-partially-watched').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const card = e.currentTarget.closest('.episode-card');
                        const sNum = card.dataset.season;
                        const eNum = card.dataset.episode;
                        sendProgressUpdate(sNum, eNum, 'partially_watched');
                        if (!window._watchedProgressCache) window._watchedProgressCache = {};
                        if (!window._watchedProgressCache[sNum]) window._watchedProgressCache[sNum] = {};
                        window._watchedProgressCache[sNum][eNum] = 'partially_watched';
                        updateEpisodeUI(card, 'partially_watched');
                    });
                });

                // Clic en la caja de miniatura para cargar fuentes del capítulo
                linksContainer.querySelectorAll('.episode-thumb-box').forEach(box => {
                    box.addEventListener('click', () => {
                        const sNum = box.dataset.season;
                        const eNum = box.dataset.episode;
                        const absNum = box.dataset.absolute;
                        const epName = box.dataset.epname;
                        const epThumb = box.dataset.thumb;
                        const epTitle = `${data.title || 'Serie'} - T${sNum}:E${eNum}${epName ? ' · ' + epName : ''}`;
                        const container = document.getElementById(`sources_s${sNum}_e${eNum}`);
                        loadEpisodeSources(sNum, eNum, container, epTitle, epThumb, absNum);
                    });
                });

                // Eventos para cargar fuentes bajo demanda con el botón
                linksContainer.querySelectorAll('.load-sources-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const sNum = btn.dataset.season;
                        const eNum = btn.dataset.episode;
                        const absNum = btn.dataset.absolute;
                        const epName = btn.dataset.epname;
                        const epThumb = btn.dataset.thumb;
                        const epTitle = `${data.title || 'Serie'} - T${sNum}:E${eNum}${epName ? ' · ' + epName : ''}`;
                        const container = document.getElementById(`sources_s${sNum}_e${eNum}`);
                        loadEpisodeSources(sNum, eNum, container, epTitle, epThumb, absNum);
                    });
                });

                // Auto-cargar fuentes: si hay capítulo previo (último visto o en progreso), cargar ese; si no, el primer capítulo
                let targetAutoLoadBtn = null;
                if (lastWatched) {
                    targetAutoLoadBtn = linksContainer.querySelector(`.load-sources-btn[data-season="${lastWatched.season}"][data-episode="${lastWatched.episode}"]`);
                }
                if (!targetAutoLoadBtn) {
                    targetAutoLoadBtn = linksContainer.querySelector('.load-sources-btn');
                }

                if (targetAutoLoadBtn) {
                    targetAutoLoadBtn.click();
                    if (lastWatched) {
                        const targetCard = targetAutoLoadBtn.closest('.episode-card');
                        if (targetCard) {
                            setTimeout(() => {
                                targetCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }, 350);
                        }
                    }
                }

                // Evento en botón de reanudar del banner "Continuar Viendo"
                const resumeBannerBtn = linksContainer.querySelector('.resume-continue-btn');
                if (resumeBannerBtn) {
                    resumeBannerBtn.addEventListener('click', (e) => {
                        const s = e.currentTarget.dataset.season;
                        const ep = e.currentTarget.dataset.episode;
                        const epCard = linksContainer.querySelector(`.episode-card[data-season="${s}"][data-episode="${ep}"]`);
                        if (epCard) {
                            epCard.scrollIntoView({ behavior: 'smooth', block: 'center' });

                            // Si ya hay un reproductor o servidor en línea cargado, iniciar reproducción de inmediato
                            const firstStreamPlayBtn = epCard.querySelector('.stream-play-btn');
                            if (firstStreamPlayBtn) {
                                firstStreamPlayBtn.click();
                                return;
                            }

                            // Si las fuentes ya están consultadas o en proceso de carga, no reiniciar peticiones
                            const sourcesBox = epCard.querySelector('.sources-container');
                            if (sourcesBox && (sourcesBox.dataset.loaded === 'true' || sourcesBox.dataset.loading === 'true')) {
                                return;
                            }

                            const btn = epCard.querySelector('.load-sources-btn');
                            if (btn) btn.click();
                        }
                    });
                }
            }
        })
        .catch(err => {
            if (err.name !== 'AbortError') {
                console.error('Error al obtener contenido:', err);
                linksContainer.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> Ocurrió un error al cargar la información.</div>';
            }
        });

    // Delegación de clic para botones de torrents (compatibilidad con renderSourcesBlock)
    document.addEventListener('click', (e) => {
        const torrentBtn = e.target.closest('.stream-torrent-btn');
        if (torrentBtn) {
            e.preventDefault();
            e.stopPropagation();
            const mag = torrentBtn.dataset.magnet;
            const t = torrentBtn.dataset.title;
            const s = torrentBtn.dataset.server;
            const p = torrentBtn.dataset.provider;
            const th = torrentBtn.dataset.thumbnail;
            openTorrentStreamModal(mag, t, s, p, th);
        }
    });
});
