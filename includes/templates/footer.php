</div>

    <!-- Pie de página (Footer) -->
    <footer class="site-footer mt-5 py-4 border-top border-secondary-subtle">
        <div class="container-fluid px-lg-5 text-center text-md-start">
            <div class="row align-items-center justify-content-between gy-3">
                <div class="col-md-auto d-flex align-items-center justify-content-center justify-content-md-start">
                    <i class="bi bi-play-circle-fill text-danger fs-4 me-2"></i>
                    <span class="brand-logo-text fs-5">StreamMedia</span>
                </div>
                <div class="col-md-auto text-center">
                    <p class="mb-0 text-secondary" style="font-size: 0.95rem;">
                        Desarrollado con <i class="bi bi-heart-fill text-danger mx-1"></i> por 
                        <a href="https://github.com/lufercode" target="_blank" rel="noopener noreferrer" class="text-white fw-bold text-decoration-none author-badge px-2 py-1 rounded bg-dark border border-secondary">
                            <i class="bi bi-github me-1"></i>lufercode
                        </a>
                    </p>
                    <small class="text-secondary" style="font-size: 0.78rem;">
                        &copy; <?= date('Y') ?> • Todos los derechos reservados.
                    </small>
                </div>
                <div class="col-md-auto text-center text-md-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" title="Volver arriba">
                        <i class="bi bi-arrow-up-short"></i> Subir
                    </button>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts de la plataforma de streaming -->
    <script src="assets/js/availability_checker.js" defer></script>
    <script src="assets/js/carousel.js" defer></script>
    <script src="assets/js/live_search.js" defer></script>
    <script src="assets/js/watchlist.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>