/**
 * ZandersTheme Storefront JavaScript Entry Point
 * Includes Shopware Native JS Plugin: AgeVerificationModalPlugin
 */

// Base plugin class resolution (Shopware PluginBaseClass or fallback)
const PluginBase = (typeof window !== 'undefined' && window.PluginBaseClass)
    ? window.PluginBaseClass
    : class Plugin {
        constructor(el, options = {}, pluginName = false) {
            this.el = el;
            this._pluginName = pluginName || this.constructor.name;
            this.options = Object.assign({}, this.constructor.options || {}, options);
            this._initialized = false;
            this._init();
        }

        _init() {
            if (this._initialized) return;
            this.init();
            this._initialized = true;
        }

        init() {}
        destroy() {}
    };

class AgeVerificationModalPlugin extends PluginBase {
    static options = {
        storageKey: 'zanders_age_verified',
        cookieKey: 'zanders_age_verified',
        cookieExpiryDays: 30,
        modalVisibleClass: 'is-visible',
        bodyScrollLockClass: 'zanders-age-modal-open',
        confirmBtnSelector: '[data-age-verification-confirm]',
        rejectBtnSelector: '[data-age-verification-reject]',
        actionsSelector: '.zanders-age-modal-actions',
        deniedSelector: '[data-age-verification-denied]',
        redirectUrl: 'https://www.google.com',
        redirectDelay: 1500,
    };

    constructor(el, options = {}) {
        super(el, options, 'AgeVerificationModal');
    }

    init() {
        // Read options from data attribute if available
        const optionsAttr = this.el.getAttribute('data-age-verification-modal-options');
        if (optionsAttr) {
            try {
                const parsed = JSON.parse(optionsAttr);
                this.options = Object.assign({}, this.options, parsed);
            } catch (e) {}
        }

        this._confirmBtn = this.el.querySelector(this.options.confirmBtnSelector);
        this._rejectBtn = this.el.querySelector(this.options.rejectBtnSelector);
        this._actionsWrap = this.el.querySelector(this.options.actionsSelector);
        this._deniedAlert = this.el.querySelector(this.options.deniedSelector);

        // Check if consent has already been given on a previous visit
        if (this.hasConsent()) {
            this.close(false);
            return;
        }

        // First-time visit: display the age verification modal
        this.open();
        this._registerEvents();
    }

    _registerEvents() {
        if (this._confirmBtn) {
            this._confirmBtn.addEventListener('click', this._onConfirm.bind(this));
        }

        if (this._rejectBtn) {
            this._rejectBtn.addEventListener('click', this._onReject.bind(this));
        }

        // Prevent Esc key from closing mandatory verification & trap focus
        this._onKeyDownHandler = this._onKeyDown.bind(this);
        document.addEventListener('keydown', this._onKeyDownHandler);
    }

    _onConfirm(event) {
        if (event) {
            event.preventDefault();
        }

        // 1. Store user consent in localStorage and cookie
        this.setConsent();

        // 2. Publish Shopware native event via emitter if available
        if (this.$emitter && typeof this.$emitter.publish === 'function') {
            this.$emitter.publish('ageVerificationConfirmed', { verified: true });
        }

        // 3. Dispatch standard custom event
        this.el.dispatchEvent(new CustomEvent('zanders:ageVerified', {
            bubbles: true,
            detail: { verified: true }
        }));

        // 4. Close the modal
        this.close(true);
    }

    _onReject(event) {
        if (event) {
            event.preventDefault();
        }

        if (this.$emitter && typeof this.$emitter.publish === 'function') {
            this.$emitter.publish('ageVerificationRejected', { verified: false });
        }

        this.el.dispatchEvent(new CustomEvent('zanders:ageRejected', {
            bubbles: true,
            detail: { verified: false }
        }));

        window.location.href = this.options.redirectUrl;
    }

    _onKeyDown(event) {
        if (!this.isOpen()) return;

        // Block Escape key from closing the verification modal
        if (event.key === 'Escape' || event.keyCode === 27) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    hasConsent() {
        // 1. Check localStorage
        try {
            if (window.localStorage && window.localStorage.getItem(this.options.storageKey) === 'true') {
                return true;
            }
        } catch (e) {
            // LocalStorage might be disabled or restricted in private browsing
        }

        // 2. Check Cookie fallback
        const cookieValue = this.getCookie(this.options.cookieKey);
        return cookieValue === 'true';
    }

    setConsent() {
        // 1. Save to localStorage
        try {
            if (window.localStorage) {
                window.localStorage.setItem(this.options.storageKey, 'true');
            }
        } catch (e) {
            console.warn('[ZandersAgeVerification] LocalStorage write error:', e);
        }

        // 2. Save to cookie
        this.setCookie(this.options.cookieKey, 'true', this.options.cookieExpiryDays);

        // 3. Mark html tag with verified class to avoid any future flash
        document.documentElement.classList.add('zanders-age-verified');
    }

    open() {
        this.el.style.display = 'flex';
        // Force reflow for CSS transition
        void this.el.offsetHeight;

        this.el.classList.add(this.options.modalVisibleClass);
        this.el.setAttribute('aria-hidden', 'false');

        document.body.classList.add(this.options.bodyScrollLockClass);
        document.documentElement.classList.add(this.options.bodyScrollLockClass);

        // Autofocus YES button
        setTimeout(() => {
            if (this._confirmBtn) {
                this._confirmBtn.focus();
            }
        }, 80);
    }

    close(animated = true) {
        if (this._onKeyDownHandler) {
            document.removeEventListener('keydown', this._onKeyDownHandler);
        }

        document.body.classList.remove(this.options.bodyScrollLockClass);
        document.documentElement.classList.remove(this.options.bodyScrollLockClass);

        if (animated) {
            this.el.classList.remove(this.options.modalVisibleClass);
            this.el.setAttribute('aria-hidden', 'true');
            setTimeout(() => {
                this.el.style.display = 'none';
            }, 300);
        } else {
            this.el.classList.remove(this.options.modalVisibleClass);
            this.el.setAttribute('aria-hidden', 'true');
            this.el.style.display = 'none';
        }
    }

    isOpen() {
        return this.el.classList.contains(this.options.modalVisibleClass);
    }

    getCookie(name) {
        const nameEQ = encodeURIComponent(name) + '=';
        const cookies = document.cookie.split(';');
        for (let i = 0; i < cookies.length; i++) {
            let c = cookies[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length, c.length));
            }
        }
        return null;
    }

    setCookie(name, value, days) {
        let expires = '';
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    destroy() {
        if (this._onKeyDownHandler) {
            document.removeEventListener('keydown', this._onKeyDownHandler);
        }
    }
}

class ProductComparePlugin extends PluginBase {
    static options = {
        storageKey: 'zanders_compare_products',
        maxItems: 5,
        drawerSelector: '[data-compare-drawer]',
        backdropSelector: '[data-compare-backdrop]',
        openBtnSelector: '[data-compare-open-btn], [data-compare-drawer-toggle]',
        closeBtnSelector: '[data-compare-close-btn]',
        clearBtnSelector: '[data-compare-clear-btn]',
        compareBtnSelector: '[data-compare-btn]',
        countBadgeSelector: '[data-compare-badge], [data-compare-count]',
        badgeTextSelector: '[data-compare-badge-text]',
        contentSelector: '[data-compare-content]',
        emptySelector: '[data-compare-empty]',
        statusTextSelector: '[data-compare-status-text]',
        toastSelector: '[data-compare-toast]',
        toastMsgSelector: '[data-compare-toast-msg]',
        toastTitleSelector: '[data-compare-toast-title]',
        toastIconSelector: '[data-compare-toast-icon]',
        toastCloseSelector: '[data-compare-toast-close]',
        toastActionSelector: '.zanders-toast-action-btn',
        modalOpenClass: 'is-open',
        backdropVisibleClass: 'is-visible',
        bodyOpenClass: 'zanders-compare-open',
    };

    constructor(el, options = {}) {
        super(el, options, 'ProductCompare');
    }

    init() {
        this._drawer = document.querySelector(this.options.drawerSelector);
        this._backdrop = document.querySelector(this.options.backdropSelector);
        this._content = document.querySelector(this.options.contentSelector);
        this._empty = document.querySelector(this.options.emptySelector);
        this._statusText = document.querySelector(this.options.statusTextSelector);
        this._toast = document.querySelector(this.options.toastSelector);
        this._toastMsg = document.querySelector(this.options.toastMsgSelector);
        this._toastTimer = null;

        this._registerEvents();
        this.syncUI();
    }

    _registerEvents() {
        document.addEventListener('click', (e) => {
            const compareBtn = e.target.closest(this.options.compareBtnSelector);
            if (compareBtn) {
                e.preventDefault();
                e.stopPropagation();
                this._onCompareBtnClick(compareBtn);
                return;
            }

            const openBtn = e.target.closest(this.options.openBtnSelector);
            if (openBtn) {
                e.preventDefault();
                this.open();
                return;
            }

            const closeBtn = e.target.closest(this.options.closeBtnSelector);
            if (closeBtn) {
                e.preventDefault();
                this.close();
                return;
            }

            const clearBtn = e.target.closest(this.options.clearBtnSelector);
            if (clearBtn) {
                e.preventDefault();
                this.clearAll();
                return;
            }

            const itemRemoveBtn = e.target.closest('[data-remove-id]');
            if (itemRemoveBtn) {
                e.preventDefault();
                e.stopPropagation();
                const removeId = itemRemoveBtn.getAttribute('data-remove-id');
                if (removeId) {
                    this.removeProduct(removeId);
                }
                return;
            }

            const toastClose = e.target.closest(this.options.toastCloseSelector);
            if (toastClose && this._toast) {
                e.preventDefault();
                this._toast.classList.remove('is-visible');
                return;
            }

            if (this._backdrop && (e.target === this._backdrop || e.target.closest(this.options.backdropSelector))) {
                e.preventDefault();
                this.close();
                return;
            }
        });

        document.addEventListener('keydown', (e) => {
            if ((e.key === 'Escape' || e.keyCode === 27) && this.isOpen()) {
                e.preventDefault();
                this.close();
            }
        });

        window.addEventListener('storage', (e) => {
            if (e.key === this.options.storageKey) {
                this.syncUI();
            }
        });
    }

    _onCompareBtnClick(btn) {
        const id = btn.getAttribute('data-product-id');
        if (!id) return;

        if (this.hasProduct(id)) {
            this.removeProduct(id);
        } else {
            const product = {
                id: id,
                name: btn.getAttribute('data-product-name') || '',
                sku: btn.getAttribute('data-product-sku') || '',
                upc: btn.getAttribute('data-product-upc') || '',
                brand: btn.getAttribute('data-product-brand') || '',
                price: btn.getAttribute('data-product-price') || '',
                imageUrl: btn.getAttribute('data-product-image') || '',
                url: btn.getAttribute('data-product-url') || '#',
                stock: btn.getAttribute('data-product-stock') || 'In Stock',
                inStock: btn.getAttribute('data-product-in-stock') !== '0',
            };
            this.addProduct(product);
        }
    }

    getProducts() {
        try {
            const data = localStorage.getItem(this.options.storageKey);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('[ZandersCompare] Failed to read from localStorage:', e);
            return [];
        }
    }

    saveProducts(products) {
        try {
            localStorage.setItem(this.options.storageKey, JSON.stringify(products));
        } catch (e) {
            console.error('[ZandersCompare] Failed to save to localStorage:', e);
        }
        this.syncUI();
    }

    hasProduct(id) {
        return this.getProducts().some(p => p.id === id);
    }

    addProduct(product) {
        const products = this.getProducts();
        if (products.some(p => p.id === product.id)) {
            this.showToast('Already in Compare', product.name || 'Product is already in your comparison list.', 'info');
            return false;
        }

        if (products.length >= this.options.maxItems) {
            this.showToast('Comparison Full', `You can compare up to ${this.options.maxItems} items at a time. Please remove one first.`, 'warning');
            return false;
        }

        products.push(product);
        this.saveProducts(products);
        this.showToast('Added to Comparison', product.name || 'Product', 'success');
        return true;
    }

    removeProduct(id) {
        const products = this.getProducts();
        const removedItem = products.find(p => p.id === id);
        const updated = products.filter(p => p.id !== id);
        this.saveProducts(updated);
        this.showToast('Removed from Compare', (removedItem && removedItem.name) ? removedItem.name : 'Product removed.', 'info');
    }

    clearAll() {
        this.saveProducts([]);
        this.showToast('Comparison Cleared', 'All products have been removed.', 'info');
    }

    open() {
        if (!this._drawer) return;
        this.renderCompareTable();
        this._drawer.classList.add(this.options.modalOpenClass);
        this._drawer.setAttribute('aria-hidden', 'false');
        if (this._backdrop) {
            this._backdrop.classList.add(this.options.backdropVisibleClass);
            this._backdrop.setAttribute('aria-hidden', 'false');
        }
        document.body.classList.add(this.options.bodyOpenClass);
    }

    close() {
        if (!this._drawer) return;
        this._drawer.classList.remove(this.options.modalOpenClass);
        this._drawer.setAttribute('aria-hidden', 'true');
        if (this._backdrop) {
            this._backdrop.classList.remove(this.options.backdropVisibleClass);
            this._backdrop.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove(this.options.bodyOpenClass);
    }

    isOpen() {
        return this._drawer && this._drawer.classList.contains(this.options.modalOpenClass);
    }

    showToast(title, message = '', type = 'success') {
        if (!this._toast) return;

        const titleEl = this._toast.querySelector(this.options.toastTitleSelector);
        const msgEl = this._toast.querySelector(this.options.toastMsgSelector);
        const iconWrap = this._toast.querySelector(this.options.toastIconSelector);
        const actionBtn = this._toast.querySelector(this.options.toastActionSelector);

        if (titleEl) {
            titleEl.textContent = title;
        }
        if (msgEl) {
            msgEl.textContent = message;
            msgEl.style.display = message ? 'block' : 'none';
        }

        this._toast.classList.remove('is-success', 'is-warning', 'is-info');
        this._toast.classList.add(`is-${type}`);

        if (iconWrap) {
            if (type === 'warning') {
                iconWrap.innerHTML = `
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" fill="#EAB308"/>
                        <path d="M12 8v5M12 16h.01" stroke="#000000" stroke-width="2.2" stroke-linecap="round"/>
                    </svg>
                `;
            } else if (type === 'info') {
                iconWrap.innerHTML = `
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" fill="#64748B"/>
                        <path d="M12 16v-4M12 8h.01" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round"/>
                    </svg>
                `;
            } else {
                iconWrap.innerHTML = `
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" fill="#10B981"/>
                        <path d="M8 12.5L10.5 15L16 9.5" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                `;
            }
        }

        if (actionBtn) {
            actionBtn.style.display = this.getProducts().length > 0 ? 'inline-flex' : 'none';
        }

        this._toast.classList.add('is-visible');

        if (this._toastTimer) {
            clearTimeout(this._toastTimer);
        }
        this._toastTimer = setTimeout(() => {
            this._toast.classList.remove('is-visible');
        }, 4500);
    }

    updateButtons() {
        const buttons = document.querySelectorAll(this.options.compareBtnSelector);
        buttons.forEach(btn => {
            const id = btn.getAttribute('data-product-id');
            const inCompare = id && this.hasProduct(id);

            btn.classList.toggle('is-active', !!inCompare);
            btn.classList.toggle('is-in-compare', !!inCompare);
            btn.setAttribute('aria-pressed', inCompare ? 'true' : 'false');

            const textEl = btn.querySelector('.zanders-compare-btn-text');
            if (textEl) {
                textEl.textContent = inCompare ? 'Remove from Compare' : 'Add to Compare';
            }
            btn.setAttribute('title', inCompare ? 'Remove from Compare' : 'Add to Compare');
        });
    }

    updateBadges() {
        const count = this.getProducts().length;

        const countElements = document.querySelectorAll(this.options.countBadgeSelector);
        countElements.forEach(el => {
            el.textContent = count;
            if (el.classList.contains('zanders-compare-count-badge')) {
                el.style.display = count > 0 ? 'inline-flex' : 'none';
            }
        });

        const badgeTextElements = document.querySelectorAll(this.options.badgeTextSelector);
        badgeTextElements.forEach(el => {
            el.textContent = count;
        });

        if (this._statusText) {
            this._statusText.textContent = `${count} of ${this.options.maxItems} products`;
        }
    }

    renderCompareTable() {
        if (!this._content || !this._empty) return;
        const products = this.getProducts();

        if (products.length === 0) {
            this._empty.style.display = 'block';
            this._content.style.display = 'none';
            return;
        }

        this._empty.style.display = 'none';
        this._content.style.display = 'block';

        const isPricesHidden = document.body.classList.contains('prices-hidden') || document.documentElement.classList.contains('prices-hidden');

        let html = `
            <table class="zanders-compare-table">
                <tbody>
                    <tr>
                        <th class="zanders-compare-attr-col">Product</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                <div class="zanders-compare-item-card">
                                    <button type="button" class="zanders-compare-item-remove" data-remove-id="${this._escapeHtml(p.id)}" title="Remove from comparison">&times;</button>
                                    <a href="${this._escapeHtml(p.url)}" class="zanders-compare-item-img-link">
                                        <img src="${this._escapeHtml(p.imageUrl)}" alt="${this._escapeHtml(p.name)}">
                                    </a>
                                    <a href="${this._escapeHtml(p.url)}" class="zanders-compare-item-title-link" title="${this._escapeHtml(p.name)}">
                                        ${this._escapeHtml(p.name)}
                                    </a>
                                </div>
                            </td>
                        `).join('')}
                    </tr>
                    <tr>
                        <th class="zanders-compare-attr-col">Price</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                ${p.price === 'Login to View'
                                    ? `<a href="/account/login" class="btn zanders-btn-login-price" style="font-size:0.75rem; padding: 4px 8px; width: auto; min-height: 28px; white-space: nowrap; text-transform: uppercase;">Login to View</a>`
                                    : `<span class="zanders-compare-price-val zanders-product-price"${isPricesHidden ? ' style="display:none;"' : ''}>
                                        ${this._escapeHtml(p.price)}
                                       </span>`
                                }
                            </td>
                        `).join('')}
                    </tr>
                    <tr>
                        <th class="zanders-compare-attr-col">Availability</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                <span class="zanders-compare-stock-badge ${p.inStock ? 'is-in-stock' : 'is-out-of-stock'}">
                                    ${this._escapeHtml(p.stock)}
                                </span>
                            </td>
                        `).join('')}
                    </tr>
                    <tr>
                        <th class="zanders-compare-attr-col">Item Number</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                <strong>${this._escapeHtml(p.sku || '-')}</strong>
                            </td>
                        `).join('')}
                    </tr>
                    <tr>
                        <th class="zanders-compare-attr-col">UPC</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                ${this._escapeHtml(p.upc || '-')}
                            </td>
                        `).join('')}
                    </tr>
                    <tr>
                        <th class="zanders-compare-attr-col">Brand</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                ${this._escapeHtml(p.brand || '-')}
                            </td>
                        `).join('')}
                    </tr>
                    <tr>
                        <th class="zanders-compare-attr-col">Action</th>
                        ${products.map(p => `
                            <td class="zanders-compare-prod-col">
                                <a href="${this._escapeHtml(p.url)}" class="zanders-compare-btn-view">
                                    View Details
                                </a>
                            </td>
                        `).join('')}
                    </tr>
                </tbody>
            </table>
        `;

        this._content.innerHTML = html;
    }

    _escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    syncUI() {
        this.updateButtons();
        this.updateBadges();
        if (this.isOpen()) {
            this.renderCompareTable();
        }
    }
}

// Register into Shopware's native PluginManager
if (typeof window !== 'undefined' && window.PluginManager) {
    window.PluginManager.register(
        'AgeVerificationModal',
        AgeVerificationModalPlugin,
        '[data-age-verification-modal]'
    );
    window.PluginManager.register(
        'ProductCompare',
        ProductComparePlugin,
        '[data-compare-drawer]'
    );
}

// Export for module systems or global access
if (typeof window !== 'undefined') {
    window.AgeVerificationModalPlugin = AgeVerificationModalPlugin;
    window.ProductComparePlugin = ProductComparePlugin;
}

document.addEventListener('DOMContentLoaded', () => {
    console.info('[ZandersTheme] Storefront JavaScript initialized.');

    // Product Compare Direct Initialization
    const compareDrawerEl = document.querySelector('[data-compare-drawer]') || document.body;
    window.zandersCompareInstance = new ProductComparePlugin(compareDrawerEl);

    // Age Verification Modal Direct Initialization (if PluginManager hasn't already initialized it)
    const ageModalEl = document.querySelector('[data-age-verification-modal]');
    if (ageModalEl) {
        let instance = null;
        if (window.PluginManager && typeof window.PluginManager.getPluginInstanceFromElement === 'function') {
            instance = window.PluginManager.getPluginInstanceFromElement(ageModalEl, 'AgeVerificationModal');
        }
        if (!instance) {
            new AgeVerificationModalPlugin(ageModalEl);
        }
    }

    // Catalog Dropdown Toggle (for click / touch & hover)
    const catalogWrapper = document.querySelector('.zanders-catalog-dropdown-wrapper');
    const catalogBtn = document.querySelector('.zanders-catalog-header-btn');
    const isHomePage = document.body.classList.contains('is-active-route-frontend-home-page');
    const isDetailPage = document.body.classList.contains('is-active-route-frontend-detail-page') || document.body.classList.contains('zanders-has-open-catalog') || !!document.querySelector('.zanders-product-detail-wrapper');
    const isPermanentlyOpen = isHomePage || isDetailPage;

    if (catalogWrapper && catalogBtn) {
        catalogBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (isPermanentlyOpen) {
                catalogWrapper.classList.toggle('is-closed');
            } else {
                const isOpen = catalogWrapper.classList.toggle('is-open');
                catalogBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
        });

        document.addEventListener('click', (e) => {
            if (!catalogWrapper.contains(e.target)) {
                if (!isPermanentlyOpen) {
                    catalogWrapper.classList.remove('is-open');
                    catalogBtn.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    // Stock filter toggle ("HIDE OUT OF STOCK ITEMS" / "SHOW OUT OF STOCK ITEMS")
    const stockLink = document.querySelector('.zanders-stock-link');
    if (stockLink) {
        let hideOutOfStock = false;
        stockLink.addEventListener('click', (e) => {
            e.preventDefault();
            hideOutOfStock = !hideOutOfStock;
            const outOfStockCards = document.querySelectorAll('.zanders-cat-product-card.is-out-of-stock');
            outOfStockCards.forEach(card => {
                card.style.display = hideOutOfStock ? 'none' : '';
            });
            stockLink.textContent = hideOutOfStock ? 'SHOW OUT OF STOCK ITEMS' : 'HIDE OUT OF STOCK ITEMS';
        });
    }

    // Price visibility toggle ("HIDE PRICES" / "SHOW PRICES")
    const priceToggleBtns = document.querySelectorAll('.zanders-price-toggle-btn');
    const PRICE_STORAGE_KEY = 'zanders_hide_prices';

    function setPriceVisibility(hide) {
        if (hide) {
            document.body.classList.add('prices-hidden', 'zanders-prices-hidden');
            document.documentElement.classList.add('prices-hidden', 'zanders-prices-hidden');
        } else {
            document.body.classList.remove('prices-hidden', 'zanders-prices-hidden');
            document.documentElement.classList.remove('prices-hidden', 'zanders-prices-hidden');
        }

        const priceElements = document.querySelectorAll('.zanders-product-price, .zanders-cat-price, .product-price, .product-detail-price, .product-detail-price-container, .zanders-compare-price-val');
        priceElements.forEach(el => {
            el.style.display = hide ? 'none' : '';
        });

        priceToggleBtns.forEach(btn => {
            btn.textContent = hide ? 'SHOW PRICES' : 'HIDE PRICES';
            btn.setAttribute('aria-pressed', hide ? 'true' : 'false');
            btn.classList.toggle('is-active', hide);
        });

        try {
            localStorage.setItem(PRICE_STORAGE_KEY, hide ? 'true' : 'false');
        } catch (e) {}
    }

    let isPricesHidden = false;
    try {
        isPricesHidden = localStorage.getItem(PRICE_STORAGE_KEY) === 'true';
    } catch (e) {}

    setPriceVisibility(isPricesHidden);

    priceToggleBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const currentlyHidden = document.body.classList.contains('prices-hidden') || document.documentElement.classList.contains('prices-hidden');
            setPriceVisibility(!currentlyHidden);
        });
    });
});
