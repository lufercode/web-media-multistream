/**
 * carousel.js - Controlador de carruseles horizontales con CSS Scroll Snap
 * Optimizado para navegación táctil, mouse wheel, flechas y control remoto / teclado de TV.
 */
function initCarousels() {
    const arrows = document.querySelectorAll('.carousel-arrow');

    arrows.forEach(arrow => {
        if (arrow.dataset.initialized) return;
        arrow.dataset.initialized = 'true';

        arrow.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = arrow.dataset.target;
            const track = document.getElementById(targetId);
            if (!track) return;

            // Desplazar aproximadamente un 75% del ancho visible para fluidez cinematográfica
            const scrollAmount = Math.max(track.clientWidth * 0.75, 240);
            const isPrev = arrow.classList.contains('prev');

            track.scrollBy({
                left: isPrev ? -scrollAmount : scrollAmount,
                behavior: 'smooth'
            });
        });
    });

    const tracks = document.querySelectorAll('.carousel-track');
    tracks.forEach(track => {
        if (track.dataset.initialized) return;
        track.dataset.initialized = 'true';

        const wrapper = track.closest('.carousel-track-wrapper');

        const updateArrowsAndMasks = () => {
            const trackId = track.id;
            const prevBtn = document.querySelector(`.carousel-arrow.prev[data-target="${trackId}"]`);
            const nextBtn = document.querySelector(`.carousel-arrow.next[data-target="${trackId}"]`);

            const scrollLeft = track.scrollLeft;
            const maxScrollLeft = Math.max(0, track.scrollWidth - track.clientWidth - 5);

            if (prevBtn) {
                prevBtn.disabled = scrollLeft <= 5;
            }
            if (nextBtn) {
                nextBtn.disabled = scrollLeft >= maxScrollLeft;
            }

            // Indicadores visuales de gradiente en bordes para sugerir más contenido
            if (wrapper) {
                if (scrollLeft > 10) {
                    wrapper.classList.add('has-scroll-left');
                } else {
                    wrapper.classList.remove('has-scroll-left');
                }

                if (scrollLeft < maxScrollLeft - 10) {
                    wrapper.classList.add('has-scroll-right');
                } else {
                    wrapper.classList.remove('has-scroll-right');
                }
            }
        };

        track.addEventListener('scroll', updateArrowsAndMasks, { passive: true });

        // Navegación accesible con teclado (D-pad en Smart TVs y flechas de teclado)
        track.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                const activeCard = document.activeElement ? document.activeElement.closest('.carousel-card-item') : null;
                if (!activeCard) return;

                const targetSibling = e.key === 'ArrowRight' ? activeCard.nextElementSibling : activeCard.previousElementSibling;
                if (targetSibling && targetSibling.classList.contains('carousel-card-item')) {
                    e.preventDefault();
                    const focusable = targetSibling.querySelector('a.poster-main-link, a, button');
                    if (focusable) {
                        focusable.focus({ preventScroll: false });
                    }
                }
            }
        });

        // Actualizar estado tras render inicial y tras carga de imágenes
        updateArrowsAndMasks();
        setTimeout(updateArrowsAndMasks, 150);
        setTimeout(updateArrowsAndMasks, 600);
        window.addEventListener('resize', updateArrowsAndMasks, { passive: true });
        window.addEventListener('orientationchange', updateArrowsAndMasks, { passive: true });
    });
}

document.addEventListener('DOMContentLoaded', initCarousels);
window.initCarousels = initCarousels;
