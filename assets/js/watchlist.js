/**
 * watchlist.js - Gestión local de "Mi Lista" (Favoritos) con persistencia en localStorage
 * y soporte para vista en carrusel (Home) y vista completa en cuadrícula (watchlist.php).
 */
const WatchlistManager = {
    STORAGE_KEY: 'webmedia_my_watchlist',

    activeFilter: 'all',
    searchQuery: '',
    sortBy: 'recent',
    pageEventsBound: false,

    getWatchlist() {
        try {
            const data = localStorage.getItem(this.STORAGE_KEY);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('Error al leer watchlist:', e);
            return [];
        }
    },

    saveWatchlist(items) {
        try {
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(items));
            this.updateBadgeCount();
            this.renderWatchlistRow();
            this.renderWatchlistPage();
            this.syncButtonStates();

            // Sincronización en segundo plano con la identidad activa
            fetch('api/sync_watchlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: items })
            }).catch(() => {});
        } catch (e) {
            console.error('Error al guardar watchlist:', e);
        }
    },

    isItemInList(id, type) {
        const items = this.getWatchlist();
        return items.some(item => String(item.id) === String(id) && item.type === type);
    },

    toggleItem(itemData) {
        let items = this.getWatchlist();
        const existingIndex = items.findIndex(item => String(item.id) === String(itemData.id) && item.type === itemData.type);

        if (existingIndex > -1) {
            items.splice(existingIndex, 1);
        } else {
            items.unshift(itemData);
        }

        this.saveWatchlist(items);
        return existingIndex === -1; // true si se añadió, false si se quitó
    },

    updateBadgeCount() {
        const count = this.getWatchlist().length;
        const badges = document.querySelectorAll('.nav-watchlist-count');
        badges.forEach(b => {
            b.textContent = count;
            b.style.display = count > 0 ? 'inline-block' : 'none';
        });
    },

    syncButtonStates() {
        const buttons = document.querySelectorAll('.btn-fav-toggle');
        buttons.forEach(btn => {
            const id = btn.dataset.favId;
            const type = btn.dataset.favType;
            const isFav = this.isItemInList(id, type);
            const hasLabel = btn.querySelector('.fav-btn-text') || btn.classList.contains('details-fav-btn');

            if (isFav) {
                btn.classList.add('is-favorited');
                btn.title = 'Quitar de Mi Lista';
                if (hasLabel) {
                    btn.innerHTML = '<i class="bi bi-bookmark-check-fill text-warning"></i> <span class="fav-btn-text">En Mi Lista</span>';
                } else {
                    btn.innerHTML = '<i class="bi bi-bookmark-check-fill"></i>';
                }
            } else {
                btn.classList.remove('is-favorited');
                btn.title = 'Añadir a Mi Lista';
                if (hasLabel) {
                    btn.innerHTML = '<i class="bi bi-bookmark-plus"></i> <span class="fav-btn-text">Añadir a Mi Lista</span>';
                } else {
                    btn.innerHTML = '<i class="bi bi-bookmark-plus"></i>';
                }
            }
        });
    },

    renderWatchlistRow() {
        const container = document.getElementById('watchlist-container');
        const track = document.getElementById('track-watchlist');
        if (!container || !track) return;

        const items = this.getWatchlist();

        if (items.length === 0) {
            container.style.display = 'none';
            track.innerHTML = '';
            return;
        }

        container.style.display = 'block';

        let html = '';
        items.forEach(item => {
            const typeLabel = item.type === 'movie' ? 'Película' : 'Serie';
            const ratingBadge = item.rating 
                ? `<span class="media-badge rating-badge"><i class="bi bi-star-fill text-warning me-1"></i>${item.rating}</span>` 
                : '';
            const yearBadge = item.year 
                ? `<span class="media-badge year-badge">${item.year}</span>` 
                : '';

            html += `
                <div class="carousel-card-item" data-query="${escapeHtml(item.title)}" data-type="${item.type}" data-id="${item.id}" data-year="${item.year || ''}">
                    <div class="media-card card h-100 bg-transparent border-0 position-relative">
                        <div class="media-poster-wrap position-relative overflow-hidden rounded">
                            <a href="details.php?id=${item.id}&type=${item.type}" class="poster-main-link" aria-label="Ver ${escapeHtml(item.title)}">
                                <img src="${item.poster}" class="card-img-top media-poster" alt="${escapeHtml(item.title)}" loading="lazy">
                            </a>
                            ${ratingBadge}
                            ${yearBadge}
                            <div class="card-overlay-actions">
                                <a href="details.php?id=${item.id}&type=${item.type}" class="poster-overlay-backdrop" aria-label="Ver ${escapeHtml(item.title)}"></a>
                                <a href="details.php?id=${item.id}&type=${item.type}" class="btn-action-play" title="Reproducir">
                                    <i class="bi bi-play-circle-fill"></i>
                                </a>
                                <button type="button" 
                                        class="btn-fav-toggle is-favorited" 
                                        data-fav-id="${item.id}" 
                                        data-fav-type="${item.type}" 
                                        data-fav-title="${escapeHtml(item.title)}" 
                                        data-fav-poster="${item.poster}"
                                        data-fav-year="${item.year || ''}"
                                        data-fav-rating="${item.rating || ''}"
                                        title="Quitar de Mi Lista">
                                    <i class="bi bi-bookmark-check-fill"></i>
                                </button>
                            </div>
                        </div>
                        <a href="details.php?id=${item.id}&type=${item.type}" class="text-decoration-none">
                            <div class="card-body p-2">
                                <h6 class="card-title text-truncate mb-1 text-white" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</h6>
                                <div class="d-flex align-items-center justify-content-between">
                                    <small class="text-secondary">${typeLabel}</small>
                                    <small class="text-secondary">${item.year || ''}</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            `;
        });

        track.innerHTML = html;
        this.bindEvents(track);
        if (typeof window.initCarousels === 'function') {
            window.initCarousels();
        }
    },

    renderWatchlistPage() {
        const grid = document.getElementById('watchlist-grid');
        const emptyState = document.getElementById('watchlist-empty-state');
        const noMatches = document.getElementById('watchlist-no-matches');
        const countBadge = document.getElementById('watchlist-count-badge');
        if (!grid) return;

        const allItems = this.getWatchlist();
        if (countBadge) {
            countBadge.textContent = `${allItems.length} ${allItems.length === 1 ? 'título' : 'títulos'}`;
        }

        this.bindPageControlsOnce();

        if (allItems.length === 0) {
            grid.innerHTML = '';
            grid.classList.add('d-none');
            if (emptyState) emptyState.classList.remove('d-none');
            if (noMatches) noMatches.classList.add('d-none');
            return;
        }

        if (emptyState) emptyState.classList.add('d-none');

        // Filtrar por categoría
        let filtered = allItems;
        if (this.activeFilter && this.activeFilter !== 'all') {
            filtered = filtered.filter(item => item.type === this.activeFilter);
        }

        // Filtrar por búsqueda
        if (this.searchQuery) {
            const q = this.searchQuery.toLowerCase().trim();
            filtered = filtered.filter(item => (item.title || '').toLowerCase().includes(q));
        }

        // Si no hay coincidencias con los filtros
        if (filtered.length === 0) {
            grid.innerHTML = '';
            grid.classList.add('d-none');
            if (noMatches) noMatches.classList.remove('d-none');
            return;
        }

        if (noMatches) noMatches.classList.add('d-none');
        grid.classList.remove('d-none');

        // Ordenar
        const sorted = [...filtered];
        switch (this.sortBy) {
            case 'title-asc':
                sorted.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
                break;
            case 'title-desc':
                sorted.sort((a, b) => (b.title || '').localeCompare(a.title || ''));
                break;
            case 'rating-desc':
                sorted.sort((a, b) => parseFloat(b.rating || 0) - parseFloat(a.rating || 0));
                break;
            case 'year-desc':
                sorted.sort((a, b) => parseInt(b.year || 0) - parseInt(a.year || 0));
                break;
            case 'recent':
            default:
                // Orden por inserción (más reciente primero)
                break;
        }

        let html = '';
        sorted.forEach(item => {
            const typeLabel = item.type === 'movie' ? 'Película' : 'Serie';
            const ratingBadge = item.rating 
                ? `<span class="media-badge rating-badge"><i class="bi bi-star-fill text-warning me-1"></i>${item.rating}</span>` 
                : '';
            const yearBadge = item.year 
                ? `<span class="media-badge year-badge">${item.year}</span>` 
                : '';

            html += `
                <div class="col" data-query="${escapeHtml(item.title)}" data-type="${item.type}" data-id="${item.id}" data-year="${item.year || ''}">
                    <div class="media-card card h-100 bg-transparent border-0 position-relative">
                        <div class="media-poster-wrap position-relative overflow-hidden rounded">
                            <a href="details.php?id=${item.id}&type=${item.type}" class="poster-main-link" aria-label="Ver ${escapeHtml(item.title)}">
                                <img src="${item.poster}" class="card-img-top media-poster" alt="${escapeHtml(item.title)}" loading="lazy">
                            </a>
                            ${ratingBadge}
                            ${yearBadge}
                            <div class="card-overlay-actions">
                                <a href="details.php?id=${item.id}&type=${item.type}" class="poster-overlay-backdrop" aria-label="Ver ${escapeHtml(item.title)}"></a>
                                <a href="details.php?id=${item.id}&type=${item.type}" class="btn-action-play" title="Reproducir">
                                    <i class="bi bi-play-circle-fill"></i>
                                </a>
                                <button type="button" 
                                        class="btn-fav-toggle is-favorited" 
                                        data-fav-id="${item.id}" 
                                        data-fav-type="${item.type}" 
                                        data-fav-title="${escapeHtml(item.title)}" 
                                        data-fav-poster="${item.poster}"
                                        data-fav-year="${item.year || ''}"
                                        data-fav-rating="${item.rating || ''}"
                                        title="Quitar de Mi Lista">
                                    <i class="bi bi-bookmark-check-fill"></i>
                                </button>
                            </div>
                        </div>
                        <a href="details.php?id=${item.id}&type=${item.type}" class="text-decoration-none">
                            <div class="card-body p-2">
                                <h6 class="card-title text-truncate mb-1 text-white" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</h6>
                                <div class="d-flex align-items-center justify-content-between">
                                    <small class="text-secondary">${typeLabel}</small>
                                    <small class="text-secondary">${item.year || ''}</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            `;
        });

        grid.innerHTML = html;
        this.bindEvents(grid);
    },

    bindPageControlsOnce() {
        if (this.pageEventsBound) return;
        this.pageEventsBound = true;

        // Botones de filtro de categoría
        const filterBtns = document.querySelectorAll('.watchlist-filter-btn');
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.activeFilter = btn.dataset.filter || 'all';
                this.renderWatchlistPage();
            });
        });

        // Selector de ordenación
        const sortSelect = document.getElementById('watchlist-sort-select');
        if (sortSelect) {
            sortSelect.addEventListener('change', (e) => {
                this.sortBy = e.target.value || 'recent';
                this.renderWatchlistPage();
            });
        }

        // Buscador en tiempo real dentro de Mi Lista
        const searchInput = document.getElementById('watchlist-search-input');
        const clearSearchBtn = document.getElementById('watchlist-search-clear');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchQuery = e.target.value || '';
                if (clearSearchBtn) {
                    clearSearchBtn.classList.toggle('d-none', !this.searchQuery);
                }
                this.renderWatchlistPage();
            });
        }

        if (clearSearchBtn && searchInput) {
            clearSearchBtn.addEventListener('click', () => {
                searchInput.value = '';
                this.searchQuery = '';
                clearSearchBtn.classList.add('d-none');
                this.renderWatchlistPage();
                searchInput.focus();
            });
        }

        // Botón Restablecer filtros
        const resetBtn = document.getElementById('watchlist-reset-filter');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                this.activeFilter = 'all';
                this.searchQuery = '';
                if (searchInput) searchInput.value = '';
                if (clearSearchBtn) clearSearchBtn.classList.add('d-none');
                filterBtns.forEach(b => b.classList.toggle('active', b.dataset.filter === 'all'));
                this.renderWatchlistPage();
            });
        }

        // Botón Vaciar Lista completa
        const clearListBtn = document.getElementById('btn-clear-watchlist');
        if (clearListBtn) {
            clearListBtn.addEventListener('click', () => {
                const total = this.getWatchlist().length;
                if (total === 0) return;
                if (confirm(`¿Estás seguro de que deseas eliminar los ${total} títulos de tu lista?`)) {
                    this.saveWatchlist([]);
                    this.renderWatchlistPage();
                }
            });
        }
    },

    bindEvents(rootElement = document) {
        const buttons = rootElement.querySelectorAll('.btn-fav-toggle');
        buttons.forEach(btn => {
            if (btn.dataset.favBound) return;
            btn.dataset.favBound = 'true';

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const itemData = {
                    id: btn.dataset.favId,
                    type: btn.dataset.favType,
                    title: btn.dataset.favTitle,
                    poster: btn.dataset.favPoster,
                    year: btn.dataset.favYear,
                    rating: btn.dataset.favRating
                };

                WatchlistManager.toggleItem(itemData);
            });
        });
    }
};

function escapeHtml(str) {
    return (str || '').replace(/[&<>'"]/g, 
        tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
    );
}

document.addEventListener('DOMContentLoaded', () => {
    WatchlistManager.bindEvents();
    WatchlistManager.syncButtonStates();
    WatchlistManager.updateBadgeCount();
    WatchlistManager.renderWatchlistRow();
    WatchlistManager.renderWatchlistPage();

    // Sincronización inicial en segundo plano con la identidad activa
    fetch('api/sync_watchlist.php')
        .then(res => res.json())
        .then(data => {
            if (data && data.items && Array.isArray(data.items)) {
                const localItems = WatchlistManager.getWatchlist();
                if (data.items.length > 0 && localItems.length === 0) {
                    localStorage.setItem(WatchlistManager.STORAGE_KEY, JSON.stringify(data.items));
                    WatchlistManager.updateBadgeCount();
                    WatchlistManager.renderWatchlistRow();
                    WatchlistManager.renderWatchlistPage();
                    WatchlistManager.syncButtonStates();
                } else if (localItems.length > 0 && data.items.length === 0) {
                    WatchlistManager.saveWatchlist(localItems);
                }
            }
        })
        .catch(() => {});
});
