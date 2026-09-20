/**
 * carousel.js - Controlador de carruseles horizontales con CSS Scroll Snap
 */
document.addEventListener('DOMContentLoaded', () => {
    const arrows = document.querySelectorAll('.carousel-arrow');

    arrows.forEach(arrow => {
        arrow.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = arrow.dataset.target;
            const track = document.getElementById(targetId);
            if (!track) return;

            // Ancho visible del track para desplazar aproximadamente una pantalla de tarjetas
            const scrollAmount = Math.max(track.clientWidth * 0.75, 240);
            const isPrev = arrow.classList.contains('prev');

            track.scrollBy({
                left: isPrev ? -scrollAmount : scrollAmount,
                behavior: 'smooth'
            });
        });
    });

    // Supervisar scroll para deshabilitar o atenuar flechas en los extremos
    const tracks = document.querySelectorAll('.carousel-track');
    tracks.forEach(track => {
        const updateArrows = () => {
            const trackId = track.id;
            const prevBtn = document.querySelector(`.carousel-arrow.prev[data-target="${trackId}"]`);
            const nextBtn = document.querySelector(`.carousel-arrow.next[data-target="${trackId}"]`);

            if (prevBtn) {
                prevBtn.disabled = track.scrollLeft <= 5;
            }
            if (nextBtn) {
                const maxScrollLeft = track.scrollWidth - track.clientWidth - 5;
                nextBtn.disabled = track.scrollLeft >= maxScrollLeft;
            }
        };

        track.addEventListener('scroll', updateArrows, { passive: true });
        // Comprobar estado inicial tras carga
        setTimeout(updateArrows, 100);
        window.addEventListener('resize', updateArrows);
    });
});
