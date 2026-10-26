document.addEventListener('DOMContentLoaded', () => {
    const contentCards = Array.from(document.querySelectorAll('[data-query]'));

    if (contentCards.length === 0) {
        return;
    }

    console.log('%c🎬 [Web2FTP] Buscador de disponibilidad iniciado. Monitoreando ' + contentCards.length + ' tarjetas.', 'color: #0dcaf0; font-weight: bold;');

    let isStorageOffline = false;
    let isOnlineToastShown = false;
    let isHostingThrottled = false;
    let consecutiveLimitErrors = 0;
    const abortController = new AbortController();
    const availabilityCache = new Map();

    // Cancelar inmediatamente todas las peticiones activas si el usuario recarga o cambia de página
    const cancelAllPendingRequests = () => {
        try {
            console.log('%c🛑 [Web2FTP] Peticiones en segundo plano canceladas por recarga o cambio de página.', 'color: #fd7e14; font-weight: bold;');
            abortController.abort();
        } catch (e) {}
    };

    window.addEventListener('beforeunload', cancelAllPendingRequests);
    window.addEventListener('pagehide', cancelAllPendingRequests);

    const getToastContainer = () => {
        let container = document.getElementById('storage-alert-toast');
        if (!container) {
            container = document.createElement('div');
            container.id = 'storage-alert-toast';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1200';
            document.body.appendChild(container);
        }
        return container;
    };

    const showStorageOnlineToast = () => {
        if (isOnlineToastShown || isStorageOffline) {
            return;
        }
        isOnlineToastShown = true;

        const container = getToastContainer();
        const toastEl = document.createElement('div');
        toastEl.className = 'toast show storage-online-toast align-items-center text-white bg-dark border border-success shadow-lg mb-2';
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML = `
            <div class="d-flex p-2 align-items-center">
                <div class="toast-body d-flex align-items-center">
                    <i class="fas fa-check-circle fa-2x text-success me-3"></i>
                    <div>
                        <strong class="text-success"><i class="fas fa-server me-1"></i> CDN Funcionando</strong><br>
                        <small class="text-light">Servidor de almacenamiento en línea y accesible.</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
            </div>
        `;
        container.appendChild(toastEl);

        setTimeout(() => {
            toastEl.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            toastEl.style.opacity = '0';
            toastEl.style.transform = 'translateY(-10px)';
            setTimeout(() => toastEl.remove(), 600);
        }, 3500);
    };

    const showStorageOfflineToast = (message) => {
        const container = getToastContainer();

        if (container.querySelector('.storage-offline-toast')) {
            return;
        }

        const onlineToast = container.querySelector('.storage-online-toast');
        if (onlineToast) onlineToast.remove();

        const toastEl = document.createElement('div');
        toastEl.className = 'toast show storage-offline-toast align-items-center text-dark bg-warning border-0 shadow-lg mb-2';
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML = `
            <div class="d-flex p-2">
                <div class="toast-body d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x text-danger me-3"></i>
                    <div>
                        <strong>Almacenamiento No Accesible</strong><br>
                        <small>${message || 'El servidor CDN no responde o la URL base cambió. Se pausó la búsqueda de enlaces.'}</small>
                    </div>
                </div>
                <button type="button" class="btn-close me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
            </div>
        `;
        container.appendChild(toastEl);
    };

    const disableRemainingCards = () => {
        contentCards.forEach(card => {
            const badge = card.querySelector('.badge-availability, .search-status-icon');
            if (badge) {
                badge.style.display = 'none';
            }
        });
    };

    const applyBadgeStatus = (badge, status) => {
        if (!badge) return;
        if (status === 'found') {
            badge.className = 'badge-availability badge-available';
            badge.innerHTML = '<i class="fas fa-check-circle me-1"></i>Disponible';
            badge.style.display = 'inline-flex';
        } else {
            badge.className = 'badge-availability badge-unavailable';
            badge.innerHTML = '<i class="fas fa-times-circle me-1"></i>No disponible';
            badge.style.display = 'inline-flex';
        }
    };

    const checkAvailability = async (card) => {
        if (isStorageOffline || abortController.signal.aborted || isHostingThrottled) {
            return;
        }

        const query = card.dataset.query;
        const originalTitle = card.dataset.originalTitle || '';
        const type = card.dataset.type;
        const id = card.dataset.id || '';
        const year = card.dataset.year || '';
        const badge = card.querySelector('.badge-availability, .search-status-icon');

        if (!query || !type || card.dataset.checked === 'true') {
            return;
        }

        // Revisar caché en memoria/sesión del cliente
        const cacheKey = `${type}_${id || query}`;
        if (availabilityCache.has(cacheKey)) {
            card.dataset.checked = 'true';
            applyBadgeStatus(badge, availabilityCache.get(cacheKey));
            return;
        }

        card.dataset.checked = 'true';

        if (badge) {
            badge.className = 'badge-availability badge-searching';
            badge.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Buscando...';
            badge.style.display = 'inline-flex';
        }

        try {
            const bodyParams = new URLSearchParams({
                query: query,
                type: type,
                id: id,
                year: year
            });
            if (originalTitle) {
                bodyParams.append('original_title', originalTitle);
            }

            const response = await fetch('api/check_availability.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: bodyParams.toString(),
                signal: abortController.signal,
            });

            // Circuit Breaker ante límite de recursos de hosting compartido (508 Loop Detected / Resource Limit o 503)
            if (response.status === 508 || response.status === 503) {
                consecutiveLimitErrors++;
                console.warn(`%c⚠️ [Buscador] Servidor ocupado (HTTP ${response.status}). Pausando verificador para no saturar procesos.`, 'color: #fd7e14;');
                if (badge) {
                    badge.style.display = 'none';
                    card.dataset.checked = 'false'; // Permitir reintento posterior si el usuario vuelve
                }
                if (consecutiveLimitErrors >= 2) {
                    isHostingThrottled = true;
                    // Pausar verificaciones durante 8 segundos
                    setTimeout(() => {
                        isHostingThrottled = false;
                        consecutiveLimitErrors = 0;
                        processQueue();
                    }, 8000);
                }
                return;
            }

            consecutiveLimitErrors = 0;

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.status === 'storage_offline') {
                isStorageOffline = true;
                showStorageOfflineToast(data.message);
                disableRemainingCards();
                if (badge) {
                    badge.className = 'badge-availability badge-unavailable';
                    badge.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>No disponible';
                    badge.style.display = 'inline-flex';
                }
                return;
            }

            showStorageOnlineToast();
            const resultStatus = data.status === 'found' ? 'found' : 'not_found';
            availabilityCache.set(cacheKey, resultStatus);
            applyBadgeStatus(badge, resultStatus);

        } catch (error) {
            if (error.name !== 'AbortError') {
                if (badge) {
                    badge.style.display = 'none';
                }
            }
        }
    };

    // Cola controlada con 1 worker (cero concurrencia, cero saturación de Entry Processes en InfinityFree)
    const checkQueue = [];
    let isQueueRunning = false;

    const processQueue = async () => {
        if (isQueueRunning) return;
        isQueueRunning = true;

        while (checkQueue.length > 0) {
            if (abortController.signal.aborted || isStorageOffline || isHostingThrottled) {
                break;
            }

            // Si el usuario está viendo o cargando detalles/reproductor, pausar para ceder el 100% de recursos al streaming
            if (window.isDetailsLoading) {
                await new Promise(r => setTimeout(r, 600));
                continue;
            }

            const card = checkQueue.shift();
            await checkAvailability(card);

            // Espaciado ligero de 150ms entre tarjetas para mantener el consumo de CPU y procesos en niveles mínimos
            await new Promise(r => setTimeout(r, 150));
        }

        isQueueRunning = false;
    };

    const enqueueCard = (card) => {
        if (!card || card.dataset.enqueued === 'true') return;
        card.dataset.enqueued = 'true';
        checkQueue.push(card);
        processQueue();
    };

    // Usar IntersectionObserver para encolar solo las tarjetas que entren en pantalla
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    obs.unobserve(entry.target);
                    enqueueCard(entry.target);
                }
            });
        }, { rootMargin: '80px' });

        contentCards.forEach(card => observer.observe(card));
    } else {
        // Fallback progresivo en orden
        contentCards.forEach(enqueueCard);
    }
});