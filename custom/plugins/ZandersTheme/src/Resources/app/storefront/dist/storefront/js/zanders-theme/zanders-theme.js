// ZandersTheme Storefront JavaScript Entry Point
document.addEventListener('DOMContentLoaded', () => {
    console.info('[ZandersTheme] Storefront JavaScript initialized.');

    // Catalog Dropdown Toggle (for click / touch & hover)
    const catalogWrapper = document.querySelector('.zanders-catalog-dropdown-wrapper');
    const catalogBtn = document.querySelector('.zanders-catalog-header-btn');
    const isHomePage = document.body.classList.contains('is-active-route-frontend-home-page');

    if (catalogWrapper && catalogBtn) {
        catalogBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (isHomePage) {
                catalogWrapper.classList.toggle('is-closed');
            } else {
                const isOpen = catalogWrapper.classList.toggle('is-open');
                catalogBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
        });

        document.addEventListener('click', (e) => {
            if (!catalogWrapper.contains(e.target)) {
                if (!isHomePage) {
                    catalogWrapper.classList.remove('is-open');
                    catalogBtn.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }
});
