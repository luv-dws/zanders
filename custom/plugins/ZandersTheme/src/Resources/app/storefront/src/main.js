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

// Register into Shopware's native PluginManager
if (typeof window !== 'undefined' && window.PluginManager) {
    window.PluginManager.register(
        'AgeVerificationModal',
        AgeVerificationModalPlugin,
        '[data-age-verification-modal]'
    );
}

// Export for module systems or global access
if (typeof window !== 'undefined') {
    window.AgeVerificationModalPlugin = AgeVerificationModalPlugin;
}

document.addEventListener('DOMContentLoaded', () => {
    console.info('[ZandersTheme] Storefront JavaScript initialized.');

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

        const priceElements = document.querySelectorAll('.zanders-product-price, .zanders-cat-price, .product-price, .product-detail-price, .product-detail-price-container');
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
