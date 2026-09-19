document.addEventListener('DOMContentLoaded', () => {
    const contentCards = Array.from(document.querySelectorAll('.col[data-query]'));

    if (contentCards.length === 0) {
        return;
    }

    console.log('%c🎬 [Web2FTP] Buscador de disponibilidad iniciado. Monitoreando ' + contentCards.length + ' tarjetas.', 'color: #0dcaf0; font-weight: bold;');

    let isStorageOffline = false;
    let isOnlineToastShown = false;
    const abortController = new AbortController();

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

        // Auto-desaparecer tras 3.5 segundos con animación suave
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

        // Si había un toast de online, removerlo
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
            const icon = card.querySelector('.search-status-icon');
            if (icon) {
                const iconElement = icon.querySelector('i');
                if (iconElement && iconElement.classList.contains('fa-spinner')) {
                    icon.style.display = 'none';
                }
            }
        });
    };

    const checkAvailability = async (card) => {
        if (isStorageOffline || abortController.signal.aborted) {
            return;
        }

        const query = card.dataset.query;
        const type = card.dataset.type;
        const id = card.dataset.id || '';
        const year = card.dataset.year || '';
        const icon = card.querySelector('.search-status-icon');

        if (!query || !type || !icon || card.dataset.checked === 'true') {
            return;
        }

        card.dataset.checked = 'true';
        icon.style.display = 'block';

        console.log(`%c🔍 [Buscador] Verificando en fuentes: "${query}" (${type})`, 'color: #ffc107;');

        try {
            const response = await fetch('api/check_availability.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `query=${encodeURIComponent(query)}&type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}&year=${encodeURIComponent(year)}`,
                signal: abortController.signal,
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const data = await response.json();

            if (data.status === 'storage_offline') {
                isStorageOffline = true;
                console.warn(`%c⚠️ [Almacenamiento] Servidor CDN fuera de línea o inaccesible para "${query}": ${data.message}`, 'color: #dc3545; font-weight: bold;');
                showStorageOfflineToast(data.message);
                disableRemainingCards();
                icon.style.display = 'none';
                return;
            }

            // CDN responde con éxito: mostrar toast de confirmación (una vez por página)
            showStorageOnlineToast();

            if (data.status === 'found') {
                console.log(`%c✅ [Buscador] DISPONIBLE: "${query}"`, 'color: #198754; font-weight: bold;');
            } else {
                console.log(`%c❌ [Buscador] No encontrado: "${query}"`, 'color: #adb5bd;');
            }

            const iconElement = icon.querySelector('i');
            if (iconElement) {
                iconElement.classList.remove('fa-spinner', 'fa-spin');
                iconElement.classList.add(data.status === 'found' ? 'fa-check-circle' : 'fa-times-circle');
                icon.classList.remove('text-warning');
                icon.classList.add(data.status === 'found' ? 'text-success' : 'text-danger');
            }

        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(`❌ [Buscador] Error al verificar "${query}":`, error);
                icon.style.display = 'none';
            }
        }
    };

    // Usar IntersectionObserver para verificar solo las tarjetas visibles en pantalla
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    obs.unobserve(entry.target);
                    checkAvailability(entry.target);
                }
            });
        }, { rootMargin: '100px' });

        contentCards.forEach(card => observer.observe(card));
    } else {
        // Fallback: procesar en lotes de 2
        const runQueue = async () => {
            const batchSize = 2;
            for (let i = 0; i < contentCards.length; i += batchSize) {
                if (isStorageOffline || abortController.signal.aborted) break;
                const batch = contentCards.slice(i, i + batchSize);
                await Promise.all(batch.map(checkAvailability));
            }
        };
        runQueue();
    }
});