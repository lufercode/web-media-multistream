/**
 * live_search.js - Búsqueda instantánea en vivo con autocompletado y atajos de teclado
 */
document.addEventListener('DOMContentLoaded', () => {
    const searchInputs = document.querySelectorAll('.search-input-modern, #search-rakun');
    if (searchInputs.length === 0) return;

    // Atajo global: tecla '/' para enfocar el buscador instantáneamente
    window.addEventListener('keydown', (e) => {
        if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            const firstInput = searchInputs[0];
            if (firstInput) {
                firstInput.focus();
                firstInput.select();
            }
        }
    });

    searchInputs.forEach(input => {
        const wrap = input.closest('.header-search-wrap') || input.parentElement;
        if (!wrap) return;

        // Asegurar que el contenedor sea position-relative
        if (!wrap.classList.contains('position-relative')) {
            wrap.classList.add('position-relative');
        }

        // Crear contenedor desplegable de resultados si no existe
        let dropdown = wrap.querySelector('.live-search-dropdown');
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.className = 'live-search-dropdown shadow-lg';
            wrap.appendChild(dropdown);
        }

        let debounceTimer = null;
        let abortCtrl = null;

        const closeDropdown = () => {
            dropdown.classList.remove('show');
            dropdown.innerHTML = '';
        };

        input.addEventListener('input', () => {
            const query = input.value.trim();
            clearTimeout(debounceTimer);

            if (query.length < 2) {
                closeDropdown();
                return;
            }

            debounceTimer = setTimeout(async () => {
                if (abortCtrl) {
                    abortCtrl.abort();
                }
                abortCtrl = new AbortController();

                try {
                    dropdown.innerHTML = `
                        <div class="p-3 text-center text-secondary">
                            <i class="fas fa-spinner fa-spin me-2 text-danger"></i> Buscando "${escapeHtml(query)}"...
                        </div>
                    `;
                    dropdown.classList.add('show');

                    const res = await fetch(`api/live_search.php?q=${encodeURIComponent(query)}`, {
                        signal: abortCtrl.signal
                    });
                    if (!res.ok) throw new Error('Error al buscar');
                    const data = await res.json();
                    const results = data.results || [];

                    if (results.length === 0) {
                        dropdown.innerHTML = `
                            <div class="p-3 text-center text-secondary">
                                <i class="bi bi-search me-1"></i> No se encontraron resultados para "${escapeHtml(query)}".
                            </div>
                        `;
                        return;
                    }

                    let html = '';
                    results.forEach(item => {
                        const typeBadge = item.type === 'movie' 
                            ? '<span class="badge bg-secondary" style="font-size: 0.65rem;">Película</span>' 
                            : '<span class="badge bg-primary" style="font-size: 0.65rem;">Serie</span>';
                        
                        const ratingBadge = item.rating 
                            ? `<small class="text-warning ms-2"><i class="bi bi-star-fill"></i> ${item.rating}</small>` 
                            : '';

                        html += `
                            <a href="${item.url}" class="live-search-item">
                                <img src="${item.poster}" alt="${escapeHtml(item.title)}" class="live-search-thumb" loading="lazy">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-bold text-truncate text-white" style="font-size: 0.95rem;">${escapeHtml(item.title)}</div>
                                    <div class="d-flex align-items-center mt-1">
                                        ${typeBadge}
                                        <small class="text-secondary ms-2">${item.year || ''}</small>
                                        ${ratingBadge}
                                    </div>
                                </div>
                                <i class="bi bi-chevron-right text-secondary ms-auto"></i>
                            </a>
                        `;
                    });

                    // Añadir pie para ir a búsqueda completa
                    html += `
                        <div class="p-2 text-center bg-dark border-top border-secondary-subtle">
                            <a href="search.php?q=${encodeURIComponent(query)}" class="small text-danger text-decoration-none fw-bold">
                                Ver todos los resultados <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    `;

                    dropdown.innerHTML = html;
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        dropdown.innerHTML = `
                            <div class="p-2 text-center text-danger small">
                                <i class="bi bi-exclamation-triangle me-1"></i> Error al cargar resultados.
                            </div>
                        `;
                    }
                }
            }, 250);
        });

        // Cerrar con Escape o clic afuera
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) {
                closeDropdown();
            }
        });
    });

    function escapeHtml(str) {
        return (str || '').replace(/[&<>'"]/g, 
            tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
        );
    }
});
