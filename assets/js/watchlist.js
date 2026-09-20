/**
 * watchlist.js - Gestión local de "Mi Lista" (Favoritos) con persistencia en localStorage
 */
const WatchlistManager = {
    STORAGE_KEY: 'webmedia_my_watchlist',

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
            this.syncButtonStates();
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

            if (isFav) {
                btn.classList.add('is-favorited');
                btn.title = 'Quitar de Mi Lista';
                btn.innerHTML = '<i class="bi bi-bookmark-check-fill"></i>';
            } else {
                btn.classList.remove('is-favorited');
                btn.title = 'Añadir a Mi Lista';
                btn.innerHTML = '<i class="bi bi-bookmark-plus"></i>';
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
                            <img src="${item.poster}" class="card-img-top media-poster" alt="${escapeHtml(item.title)}" loading="lazy">
                            ${ratingBadge}
                            ${yearBadge}
                            <div class="card-overlay-actions">
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
    },

    bindEvents(rootElement = document) {
        const buttons = rootElement.querySelectorAll('.btn-fav-toggle');
        buttons.forEach(btn => {
            // Evitar doble registro
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
});
