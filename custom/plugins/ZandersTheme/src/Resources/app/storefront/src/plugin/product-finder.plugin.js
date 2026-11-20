/**
 * Zanders Sporting Goods - Product Finder Storefront Plugin
 * Slide-out Left Drawer matching reference design with live "Matching Items" count.
 */
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

export default class ProductFinderPlugin extends PluginBase {
    static options = {
        backdropSelector: '#zandersFinderBackdrop',
        drawerSelector: '#zandersFinderDrawer',
        formSelector: '#zandersFinderDrawerForm',
        closeBtnSelector: '[data-finder-close]',
        triggerSelector: '[data-finder-drawer-trigger], [data-finder-modal-trigger]',
        selectSelector: '[data-finder-filter-input]',
        resetBtnSelector: '[data-finder-reset]',
        matchingCountSelector: '[data-finder-matching-count]',
        searchUrl: '/finder/search',
        bodyOpenClass: 'zanders-finder-drawer-open',
        visibleClass: 'is-visible',
    };

    constructor(el, options = {}) {
        super(el, options, 'ProductFinder');
    }

    init() {
        this._backdrop = document.querySelector(this.options.backdropSelector);
        this._drawer = document.querySelector(this.options.drawerSelector);
        this._form = document.querySelector(this.options.formSelector);
        this._matchingCountEl = document.querySelector(this.options.matchingCountSelector);

        this._registerEvents();
    }

    _registerEvents() {
        // Trigger drawer open
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest(this.options.triggerSelector);
            if (trigger) {
                e.preventDefault();
                this.open();
                return;
            }

            // Close button (red square with 'X')
            const closeBtn = e.target.closest(this.options.closeBtnSelector);
            if (closeBtn) {
                e.preventDefault();
                this.close();
                return;
            }

            // Click outside drawer on backdrop to close
            if (this._backdrop && e.target === this._backdrop) {
                e.preventDefault();
                this.close();
                return;
            }

            // Reset All button
            const resetBtn = e.target.closest(this.options.resetBtnSelector);
            if (resetBtn && this._form) {
                e.preventDefault();
                this.resetFilters();
                return;
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if ((e.key === 'Escape' || e.keyCode === 27) && this.isOpen()) {
                e.preventDefault();
                this.close();
            }
        });

        // Listen for change and input on all filter inputs for live Matching Items count
        if (this._form) {
            const handleFilterChange = (e) => {
                if (e.target && e.target.matches('select, input')) {
                    this.updateMatchingCount();
                }
            };
            this._form.addEventListener('change', handleFilterChange);
            this._form.addEventListener('input', handleFilterChange);
        }
    }

    open() {
        if (!this._backdrop) return;

        this._backdrop.classList.add(this.options.visibleClass);
        this._backdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add(this.options.bodyOpenClass);

        // Fetch live count on open if not already set
        this.updateMatchingCount();
    }

    close() {
        if (!this._backdrop) return;

        this._backdrop.classList.remove(this.options.visibleClass);
        this._backdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove(this.options.bodyOpenClass);
    }

    isOpen() {
        return this._backdrop && this._backdrop.classList.contains(this.options.visibleClass);
    }

    resetFilters() {
        if (!this._form) return;

        // Reset all selects to first option
        const selects = this._form.querySelectorAll('select');
        selects.forEach((select) => {
            select.selectedIndex = 0;
        });

        // Reset any inputs
        const inputs = this._form.querySelectorAll('input:not([type="hidden"])');
        inputs.forEach((input) => {
            if (input.type === 'checkbox') {
                input.checked = false;
            } else {
                input.value = '';
            }
        });

        this.updateMatchingCount();
    }

    updateMatchingCount() {
        if (!this._form || !this._matchingCountEl) return;

        const formData = new FormData(this._form);
        const params = new URLSearchParams(formData);
        params.set('format', 'json');

        const searchUrl = this._form.dataset.searchUrl || this.options.searchUrl || '/finder/search';

        if (this._abortController) {
            this._abortController.abort();
        }
        this._abortController = new AbortController();

        fetch(`${searchUrl}?${params.toString()}`, {
            signal: this._abortController.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP error ${res.status}`);
                }
                return res.json();
            })
            .then((data) => {
                if (data && typeof data.total !== 'undefined') {
                    this._matchingCountEl.textContent = Number(data.total).toLocaleString('en-US');
                }
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.warn('[ZandersFinder] Could not update matching items count:', err);
                }
            });
    }
}

// Auto-register in Shopware PluginManager if available
if (typeof window !== 'undefined' && window.PluginManager) {
    window.PluginManager.register(
        'ProductFinder',
        ProductFinderPlugin,
        '[data-finder-drawer], [data-finder-modal]'
    );
}
