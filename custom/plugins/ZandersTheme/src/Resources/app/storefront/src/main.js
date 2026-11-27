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

        init() { }
        destroy() { }
    };

class AgeVerificationModalPlugin extends PluginBase {
    static options = {
        storageKey: 'zanders_age_verified',
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
            } catch (e) { }
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

        // 1. Store user consent in localStorage
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
        // Check localStorage
        try {
            return window.localStorage && window.localStorage.getItem(this.options.storageKey) === 'true';
        } catch (e) {
            // LocalStorage might be disabled or restricted in private browsing
            return false;
        }
    }

    setConsent() {
        // Save to localStorage
        try {
            if (window.localStorage) {
                window.localStorage.setItem(this.options.storageKey, 'true');
            }
        } catch (e) {
            console.warn('[ZandersAgeVerification] LocalStorage write error:', e);
        }

        // Mark html tag with verified class to avoid any future flash
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
        if (!this._drawer) {
            const countElements = document.querySelectorAll(this.options.countBadgeSelector);
            countElements.forEach(el => {
                el.classList.add('is-hidden');
            });
            return;
        }

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
                el.classList.toggle('is-hidden', count === 0);
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
            this._empty.classList.remove('is-hidden');
            this._content.classList.add('is-hidden');
            return;
        }

        this._empty.classList.add('is-hidden');
        this._content.classList.remove('is-hidden');

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
                ? `<a href="/account/login" class="btn zanders-btn-login-price zanders-compare-login-price">Login to View</a>`
                : `<span class="zanders-compare-price-val zanders-product-price${isPricesHidden ? ' is-hidden' : ''}">
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

class OrderPrintPlugin extends PluginBase {
    init() {
        setTimeout(() => {
            window.print();
        }, 350);
    }
}

function printInvoiceUrl(url, buttonEl) {
    if (!url) return;

    let originalContent = '';
    if (buttonEl) {
        originalContent = buttonEl.innerHTML;
        buttonEl.classList.add('disabled', 'is-printing');
        buttonEl.style.pointerEvents = 'none';
    }

    const restoreButton = () => {
        if (buttonEl) {
            buttonEl.classList.remove('disabled', 'is-printing');
            buttonEl.style.pointerEvents = '';
            buttonEl.innerHTML = originalContent;
        }
    };

    // Remove any previously created print iframe
    let iframe = document.getElementById('zanders-invoice-print-frame');
    if (iframe) {
        iframe.remove();
    }

    iframe = document.createElement('iframe');
    iframe.id = 'zanders-invoice-print-frame';
    iframe.classList.add('zanders-invoice-print-frame');
    document.body.appendChild(iframe);

    let printed = false;
    const executePrint = (targetFrame, blobUrl) => {
        if (printed) return;
        printed = true;

        try {
            targetFrame.focus();
            if (targetFrame.contentWindow) {
                targetFrame.contentWindow.focus();
                targetFrame.contentWindow.print();
            } else {
                throw new Error('No contentWindow available');
            }
        } catch (err) {
            console.warn('[ZandersTheme] iframe.print() direct invocation failed, using print window fallback:', err);
            const printWin = window.open(blobUrl || url, '_blank');
            if (printWin) {
                printWin.addEventListener('load', () => {
                    setTimeout(() => printWin.print(), 350);
                }, true);
            }
        } finally {
            setTimeout(() => {
                restoreButton();
                if (blobUrl) {
                    URL.revokeObjectURL(blobUrl);
                }
            }, 1000);
        }
    };

    // Fetch the PDF binary via same-origin credentials, then mount to blob URL for fast memory loading
    fetch(url, { credentials: 'same-origin' })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.blob();
        })
        .then(blob => {
            const blobUrl = URL.createObjectURL(blob);
            iframe.onload = () => {
                setTimeout(() => executePrint(iframe, blobUrl), 400);
            };
            iframe.src = blobUrl;

            // Safety timeout in case onload event is delayed by native plugin
            setTimeout(() => {
                if (!printed) {
                    executePrint(iframe, blobUrl);
                }
            }, 3000);
        })
        .catch(err => {
            console.warn('[ZandersTheme] Fetch blob failed, falling back to direct iframe src:', err);
            iframe.onload = () => {
                setTimeout(() => executePrint(iframe, null), 500);
            };
            iframe.src = url;

            setTimeout(() => {
                if (!printed) {
                    executePrint(iframe, null);
                }
            }, 3500);
        });
}

class OrderInvoicePrintPlugin extends PluginBase {
    init() {
        this.el.addEventListener('click', this.onPrintInvoice.bind(this));
    }

    onPrintInvoice(e) {
        e.preventDefault();
        e.stopPropagation();
        const url = this.el.getAttribute('href') || this.el.dataset.printInvoiceUrl;
        printInvoiceUrl(url, this.el);
    }
}

class ProductFinderPlugin extends PluginBase {
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
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest(this.options.triggerSelector);
            if (trigger) {
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

            if (this._backdrop && e.target === this._backdrop) {
                e.preventDefault();
                this.close();
                return;
            }

            const resetBtn = e.target.closest(this.options.resetBtnSelector);
            if (resetBtn && this._form) {
                e.preventDefault();
                this.resetFilters();
                return;
            }
        });

        document.addEventListener('keydown', (e) => {
            if ((e.key === 'Escape' || e.keyCode === 27) && this.isOpen()) {
                e.preventDefault();
                this.close();
            }
        });

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
        const selects = this._form.querySelectorAll('select');
        selects.forEach((select) => {
            select.selectedIndex = 0;
        });

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
                    console.warn('[ZandersFinder] Count update failed:', err);
                }
            });
    }
}

class ProductWarningModalPlugin extends PluginBase {
    static options = {
        openBtnSelector: '[data-warning-modal-open="true"]',
        closeBtnSelector: '[data-warning-modal-close="true"]',
        openClass: 'is-open',
    };

    init() {
        this._modal = this.el;
        this._openBtns = document.querySelectorAll(this.options.openBtnSelector);
        this._closeBtns = this._modal.querySelectorAll(this.options.closeBtnSelector);
        this._registerEvents();
    }

    _registerEvents() {
        this._openHandler = (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.open();
        };

        this._closeHandler = (e) => {
            e.preventDefault();
            this.close();
        };

        this._backdropHandler = (e) => {
            if (e.target === this._modal) {
                this.close();
            }
        };

        this._keyHandler = (e) => {
            if (e.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        };

        this._openBtns.forEach((btn) => btn.addEventListener('click', this._openHandler));
        this._closeBtns.forEach((btn) => btn.addEventListener('click', this._closeHandler));
        this._modal.addEventListener('click', this._backdropHandler);
        document.addEventListener('keydown', this._keyHandler);
    }

    open() {
        this._modal.classList.add(this.options.openClass);
        this._modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    close() {
        this._modal.classList.remove(this.options.openClass);
        this._modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    isOpen() {
        return this._modal.classList.contains(this.options.openClass);
    }

    destroy() {
        this._openBtns.forEach((btn) => btn.removeEventListener('click', this._openHandler));
        this._closeBtns.forEach((btn) => btn.removeEventListener('click', this._closeHandler));
        this._modal.removeEventListener('click', this._backdropHandler);
        document.removeEventListener('keydown', this._keyHandler);
    }
}

class CrossSellingSliderPlugin extends PluginBase {
    static options = {
        trackSelector: '[data-cross-track="true"]',
        prevBtnSelector: '[data-cross-prev="true"]',
        nextBtnSelector: '[data-cross-next="true"]',
        itemSelector: '.zanders-cross-slide-item',
        wishlistBtnSelector: '.zanders-cross-wishlist-btn',
        stockToggleSelector: '[data-cross-stock-toggle="true"]',
        stockToggleTextSelector: '.zanders-cross-stock-toggle-text',
        hideOutOfStockByDefault: true,
        fallbackStep: 260,
        stepGap: 16,
    };

    init() {
        this._track = this.el.querySelector(this.options.trackSelector);
        this._prevBtn = this.el.querySelector(this.options.prevBtnSelector);
        this._nextBtn = this.el.querySelector(this.options.nextBtnSelector);
        this._wishlistBtns = this.el.querySelectorAll(this.options.wishlistBtnSelector);
        this._stockToggleBtn = this.el.querySelector(this.options.stockToggleSelector);
        this._stockToggleText = this._stockToggleBtn ? this._stockToggleBtn.querySelector(this.options.stockToggleTextSelector) : null;
        this._isHidingOutOfStock = this.options.hideOutOfStockByDefault;

        if (!this._track) {
            return;
        }

        // Apply initial out-of-stock visibility state
        this._applyOutOfStockVisibility();

        this._registerEvents();
    }

    _applyOutOfStockVisibility() {
        const items = this._track.querySelectorAll(this.options.itemSelector);
        let hasOutOfStock = false;

        items.forEach((item) => {
            const isOutOfStock = item.querySelector('.is-out-of-stock') || item.querySelector('[data-in-stock="0"]');
            if (isOutOfStock) {
                hasOutOfStock = true;
                item.style.display = this._isHidingOutOfStock ? 'none' : '';
            }
        });

        if (this._stockToggleBtn) {
            // If there are no out-of-stock items in this slider, hide the toggle button entirely
            if (!hasOutOfStock) {
                this._stockToggleBtn.style.display = 'none';
                return;
            }

            this._stockToggleBtn.style.display = '';
            this._stockToggleBtn.classList.toggle('is-active', this._isHidingOutOfStock);
            this._stockToggleBtn.setAttribute('aria-pressed', (!this._isHidingOutOfStock).toString());

            if (this._stockToggleText) {
                const showText = this._stockToggleText.dataset.textShow;
                const hideText = this._stockToggleText.dataset.textHide;
                this._stockToggleText.textContent = this._isHidingOutOfStock ? showText : hideText;
            }
        }
    }

    _registerEvents() {
        if (this._prevBtn) {
            this._prevBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this._track.scrollBy({ left: -this._getScrollStep(), behavior: 'smooth' });
            });
        }

        if (this._nextBtn) {
            this._nextBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this._track.scrollBy({ left: this._getScrollStep(), behavior: 'smooth' });
            });
        }

        this._wishlistBtns.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                btn.classList.toggle('is-active');
            });
        });

        if (this._stockToggleBtn) {
            this._stockToggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this._toggleOutOfStock();
            });
        }
    }

    _toggleOutOfStock() {
        this._isHidingOutOfStock = !this._isHidingOutOfStock;
        this._applyOutOfStockVisibility();
    }

    _getScrollStep() {
        const firstVisibleCard = this._track.querySelector(`${this.options.itemSelector}:not([style*="display: none"])`);
        if (firstVisibleCard) {
            return firstVisibleCard.offsetWidth + this.options.stepGap;
        }
        return this.options.fallbackStep;
    }
}

class ClientStatePlugin extends PluginBase {
    static options = {
        priceStorageKey: 'zanders_hide_prices',
        ageStorageKey: 'zanders_age_verified',
        pricesHiddenClass: 'prices-hidden',
        zandersPricesHiddenClass: 'zanders-prices-hidden',
        ageVerifiedClass: 'zanders-age-verified',
        isLoggedIn: false,
    };

    init() {
        try {
            if (this.options.isLoggedIn || this.el.dataset.isLoggedIn === 'true') {
                if (localStorage.getItem(this.options.priceStorageKey) === 'true') {
                    document.documentElement.classList.add(this.options.pricesHiddenClass, this.options.zandersPricesHiddenClass);
                }
            }
            if (localStorage.getItem(this.options.ageStorageKey) === 'true') {
                document.documentElement.classList.add(this.options.ageVerifiedClass);
            }
        } catch (e) {
            console.warn('[ZandersTheme] ClientStatePlugin error reading storage:', e);
        }
    }
}

class ListingViewPlugin extends PluginBase {
    static options = {
        storageKey: 'zanders_listing_view',
        gridSelector: '[data-listing-grid], .zanders-category-product-grid',
        btnSelector: '[data-view-mode]',
        defaultView: 'grid',
    };

    constructor(el, options = {}) {
        super(el, options, 'ListingView');
    }

    init() {
        this._grid = document.querySelector(this.options.gridSelector);
        this._buttons = document.querySelectorAll(this.options.btnSelector);

        if (!this._grid || this._buttons.length === 0) return;

        let initialView = null;
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const viewParam = urlParams.get('view');
            if (viewParam && ['grid', 'list-image', 'list-text'].includes(viewParam)) {
                initialView = viewParam;
            }
        } catch (e) {}

        if (!initialView) {
            try {
                const stored = localStorage.getItem(this.options.storageKey);
                if (stored && ['grid', 'list-image', 'list-text'].includes(stored)) {
                    initialView = stored;
                }
            } catch (e) {}
        }

        const activeView = initialView || this.options.defaultView;
        this.setView(activeView, false);

        this._registerEvents();
    }

    _registerEvents() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest(this.options.btnSelector);
            if (btn) {
                e.preventDefault();
                const view = btn.getAttribute('data-view-mode');
                if (view && ['grid', 'list-image', 'list-text'].includes(view)) {
                    this.setView(view, true);
                }
            }
        });
    }

    setView(viewMode, save = true) {
        if (!this._grid) {
            this._grid = document.querySelector(this.options.gridSelector);
        }
        if (!this._grid) return;

        this._grid.classList.remove('is-view-grid', 'is-view-list-image', 'is-view-list-text');
        this._grid.classList.add(`is-view-${viewMode}`);

        const buttons = document.querySelectorAll(this.options.btnSelector);
        buttons.forEach(btn => {
            const mode = btn.getAttribute('data-view-mode');
            const isActive = mode === viewMode;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        if (save) {
            try {
                localStorage.setItem(this.options.storageKey, viewMode);
            } catch (e) {}
        }
    }
}

class ListingStockFilterPlugin extends PluginBase {
    static options = {
        gridSelector: '[data-listing-grid], .zanders-category-product-grid',
        cardSelector: '.zanders-product-card, .zanders-cat-product-card',
        stockLinkSelector: '.zanders-stock-link',
        countSelector: '.zanders-toolbar-items-count',
        paginationSelector: '.zanders-toolbar-pagination',
        perPageSelector: '#zandersShowPerPage',
        dynamicEmptySelector: '.zanders-category-empty-alert.is-dynamic-empty',
        storageKey: 'zanders_hide_out_of_stock',
    };

    constructor(el, options = {}) {
        super(el, options, 'ListingStockFilter');
    }

    init() {
        this._grid = document.querySelector(this.options.gridSelector);
        this._stockLinks = document.querySelectorAll(this.options.stockLinkSelector);
        this._countEls = document.querySelectorAll(this.options.countSelector);
        this._paginationEls = document.querySelectorAll(this.options.paginationSelector);
        this._perPageSelect = document.querySelector(this.options.perPageSelector);
        this._dynamicEmptyEl = document.querySelector(this.options.dynamicEmptySelector);

        if (!this._grid || this._stockLinks.length === 0) {
            return;
        }

        this._allCards = Array.from(this._grid.querySelectorAll(this.options.cardSelector));
        if (this._allCards.length === 0) {
            return;
        }

        // Determine initial stock filter state from URL or localStorage
        let isHiding = false;
        try {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('hideOutOfStock')) {
                const param = urlParams.get('hideOutOfStock');
                isHiding = param === '1' || param === 'true';
            } else {
                const stored = localStorage.getItem(this.options.storageKey);
                if (stored !== null) {
                    isHiding = stored === 'true';
                }
            }
        } catch (e) {}

        this._isHidingOutOfStock = isHiding;

        // Current page resolution
        let initialPage = 1;
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const p = parseInt(urlParams.get('p'), 10);
            if (!isNaN(p) && p > 0) {
                initialPage = p;
            }
        } catch (e) {}
        this._currentPage = initialPage;

        this._registerEvents();

        if (this._isHidingOutOfStock) {
            this.updateListing(this._currentPage, false);
        } else {
            this._syncPaginationUrls();
        }
    }

    isCardOutOfStock(card) {
        return card.classList.contains('is-out-of-stock') ||
               card.getAttribute('data-in-stock') === '0' ||
               card.querySelector('.is-out-of-stock') !== null;
    }

    getPageLimit() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('limit')) {
                const limitParam = parseInt(urlParams.get('limit'), 10);
                if (!isNaN(limitParam) && limitParam > 0) return limitParam;
            }
        } catch (e) {}

        const firstPagination = this._paginationEls[0];
        if (firstPagination && firstPagination.dataset.pageLimit) {
            const val = parseInt(firstPagination.dataset.pageLimit, 10);
            if (!isNaN(val) && val > 0) {
                return val;
            }
        }

        if (this._perPageSelect) {
            const val = parseInt(this._perPageSelect.value, 10);
            if (!isNaN(val) && val > 0) {
                return val;
            }
        }

        return 15;
    }

    updateListing(page = 1, save = true) {
        const matchingCards = [];
        this._allCards.forEach(card => {
            const isOos = this.isCardOutOfStock(card);
            if (this._isHidingOutOfStock && isOos) {
                // Out of stock product is filtered out
            } else {
                matchingCards.push(card);
            }
        });

        const totalMatching = matchingCards.length;
        const pageLimit = this.getPageLimit();
        const totalPages = Math.max(1, Math.ceil(totalMatching / pageLimit));

        if (page > totalPages) {
            page = 1;
        }
        if (page < 1) {
            page = 1;
        }
        this._currentPage = page;

        const startIndex = (this._currentPage - 1) * pageLimit;
        const endIndex = Math.min(startIndex + pageLimit, totalMatching);

        // Update product card visibility in DOM
        this._allCards.forEach(card => {
            card.style.display = 'none';
        });
        for (let i = startIndex; i < endIndex; i++) {
            matchingCards[i].style.display = '';
        }

        // Toggle dynamic empty alert message if all products hidden
        if (this._dynamicEmptyEl) {
            this._dynamicEmptyEl.style.display = totalMatching === 0 ? '' : 'none';
        }

        // Update stock toggle link texts
        this._stockLinks.forEach(link => {
            const showText = link.dataset.textShow || 'SHOW OUT OF STOCK ITEMS';
            const hideText = link.dataset.textHide || 'HIDE OUT OF STOCK ITEMS';
            link.textContent = this._isHidingOutOfStock ? showText : hideText;
            link.classList.toggle('is-active', this._isHidingOutOfStock);
        });

        // Update item count element
        this._countEls.forEach(countEl => {
            const labelItems = countEl.dataset.labelItems || 'Items';
            const labelOf = countEl.dataset.labelOf || 'Of';
            if (totalMatching === 0) {
                countEl.textContent = `${labelItems} 0-0 ${labelOf} 0`;
            } else {
                const startNum = startIndex + 1;
                const endNum = endIndex;
                countEl.textContent = `${labelItems} ${startNum}-${endNum} ${labelOf} ${totalMatching}`;
            }
        });

        // Update search results heading count if present (e.g. "4 products found for 'mag'")
        const searchHeadingEl = document.querySelector('.zanders-category-heading[data-search-results="true"]');
        if (searchHeadingEl) {
            const currentText = searchHeadingEl.textContent.trim();
            if (currentText) {
                if (totalMatching === 1) {
                    searchHeadingEl.textContent = currentText.replace(/^\d+/, '1').replace(/\bproducts\b/i, 'product');
                } else {
                    searchHeadingEl.textContent = currentText.replace(/^\d+/, totalMatching.toString()).replace(/\bproduct\b/i, 'products');
                }
            }
        }

        // Update pagination element
        this._paginationEls.forEach(paginationEl => {
            this.renderPagination(paginationEl, this._currentPage, totalPages);
        });

        // Save preference and synchronize URL search params
        if (save) {
            try {
                localStorage.setItem(this.options.storageKey, this._isHidingOutOfStock ? 'true' : 'false');
                const newUrl = new URL(window.location.href);
                if (this._isHidingOutOfStock) {
                    newUrl.searchParams.set('hideOutOfStock', '1');
                } else {
                    newUrl.searchParams.delete('hideOutOfStock');
                }
                if (this._currentPage > 1) {
                    newUrl.searchParams.set('p', this._currentPage.toString());
                } else {
                    newUrl.searchParams.delete('p');
                }
                window.history.replaceState({}, '', newUrl.toString());
            } catch (e) {}
        }
    }

    renderPagination(paginationEl, currentPage, totalPages) {
        const labelPage = paginationEl.dataset.labelPage || 'Page:';
        const labelPrev = paginationEl.dataset.labelPrev || 'Previous page';
        const labelNext = paginationEl.dataset.labelNext || 'Next page';

        const maxPages = 5;
        const startP = Math.max(1, Math.min(currentPage - 2, totalPages - maxPages + 1));
        const endP = Math.min(totalPages, startP + maxPages - 1);

        let html = `<span class="zanders-page-label">${labelPage}</span>\n`;

        // Previous arrow
        if (currentPage > 1) {
            const prevUrl = this._buildPageUrl(currentPage - 1);
            html += `<a href="${prevUrl}" class="zanders-page-arrow" data-page="${currentPage - 1}" aria-label="${labelPrev}">&lt;</a>\n`;
        } else {
            html += `<span class="zanders-page-arrow is-disabled" aria-label="${labelPrev}">&lt;</span>\n`;
        }

        // Page numbers
        for (let pNum = startP; pNum <= endP; pNum++) {
            if (pNum === currentPage) {
                html += `<span class="zanders-page-num is-active" data-page="${pNum}">${pNum}</span>\n`;
            } else {
                const pageUrl = this._buildPageUrl(pNum);
                html += `<a href="${pageUrl}" class="zanders-page-num" data-page="${pNum}">${pNum}</a>\n`;
            }
        }

        // Next arrow
        if (currentPage < totalPages) {
            const nextUrl = this._buildPageUrl(currentPage + 1);
            html += `<a href="${nextUrl}" class="zanders-page-arrow" data-page="${currentPage + 1}" aria-label="${labelNext}">&gt;</a>\n`;
        } else {
            html += `<span class="zanders-page-arrow is-disabled" aria-label="${labelNext}">&gt;</span>\n`;
        }

        paginationEl.innerHTML = html;
    }

    _buildPageUrl(page) {
        try {
            const url = new URL(window.location.href);
            if (page > 1) {
                url.searchParams.set('p', page.toString());
            } else {
                url.searchParams.delete('p');
            }
            if (this._isHidingOutOfStock) {
                url.searchParams.set('hideOutOfStock', '1');
            } else {
                url.searchParams.delete('hideOutOfStock');
            }
            return url.pathname + url.search;
        } catch (e) {
            let q = `?p=${page}`;
            if (this._isHidingOutOfStock) q += '&hideOutOfStock=1';
            return q;
        }
    }

    _syncPaginationUrls() {
        this._paginationEls.forEach(paginationEl => {
            const links = paginationEl.querySelectorAll('a[href]');
            links.forEach(link => {
                const href = link.getAttribute('href');
                if (href && !href.startsWith('#')) {
                    try {
                        const url = new URL(href, window.location.origin);
                        if (this._isHidingOutOfStock) {
                            url.searchParams.set('hideOutOfStock', '1');
                        } else {
                            url.searchParams.delete('hideOutOfStock');
                        }
                        link.setAttribute('href', url.pathname + url.search);
                    } catch (e) {}
                }
            });
        });
    }

    _registerEvents() {
        // Toggle button click
        this._stockLinks.forEach(link => {
            if (link._hasStockFilterListener) return;
            link._hasStockFilterListener = true;
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this._isHidingOutOfStock = !this._isHidingOutOfStock;
                this.updateListing(1, true);
            });
        });

        // Pagination click (delegated on pagination elements)
        this._paginationEls.forEach(paginationEl => {
            paginationEl.addEventListener('click', (e) => {
                const target = e.target.closest('[data-page]');
                if (!target) return;
                const pageNum = parseInt(target.getAttribute('data-page'), 10);
                if (isNaN(pageNum) || pageNum === this._currentPage) return;

                const pageLimit = this.getPageLimit();
                const totalPages = Math.max(1, Math.ceil(this._allCards.length / pageLimit));
                if (pageNum <= totalPages) {
                    e.preventDefault();
                    this.updateListing(pageNum, true);
                    if (this._grid) {
                        const top = this._grid.getBoundingClientRect().top + window.pageYOffset - 120;
                        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
                    }
                }
            });
        });

        // Handle popstate for browser back/forward buttons
        window.addEventListener('popstate', () => {
            let isHiding = false;
            let page = 1;
            try {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('hideOutOfStock')) {
                    isHiding = urlParams.get('hideOutOfStock') === '1' || urlParams.get('hideOutOfStock') === 'true';
                }
                const p = parseInt(urlParams.get('p'), 10);
                if (!isNaN(p) && p > 0) {
                    page = p;
                }
            } catch (e) {}
            this._isHidingOutOfStock = isHiding;
            this.updateListing(page, false);
        });
    }
}

class ProductGalleryThumbnailsPlugin extends PluginBase {
    static options = {
        mainImageSelector: '#zanders-main-product-image',
        thumbSelector: '.zanders-pd-thumb-box, [data-thumbnail-src]',
        activeClass: 'is-active',
    };

    constructor(el, options = {}) {
        super(el, options, 'ProductGalleryThumbnails');
    }

    init() {
        this._mainImg = document.querySelector(this.options.mainImageSelector);
        this._thumbs = this.el.querySelectorAll(this.options.thumbSelector);

        if (!this._mainImg || this._thumbs.length === 0) return;

        this._registerEvents();
    }

    _registerEvents() {
        this._thumbs.forEach(thumb => {
            const activate = (e) => {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                    return;
                }
                e.preventDefault();
                const src = thumb.dataset.thumbnailSrc || (thumb.querySelector('img') ? thumb.querySelector('img').src : null);
                if (src && this._mainImg) {
                    this._mainImg.src = src;
                }
                this._thumbs.forEach(t => t.classList.remove(this.options.activeClass));
                thumb.classList.add(this.options.activeClass);
            };

            thumb.addEventListener('click', activate);
            thumb.addEventListener('keydown', activate);
        });
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
    window.PluginManager.register(
        'OrderPrint',
        OrderPrintPlugin,
        '[data-order-print-page]'
    );
    window.PluginManager.register(
        'OrderInvoicePrint',
        OrderInvoicePrintPlugin,
        '[data-print-invoice]'
    );
    window.PluginManager.register(
        'ProductFinder',
        ProductFinderPlugin,
        '[data-finder-drawer], [data-finder-modal]'
    );
    window.PluginManager.register(
        'ProductWarningModal',
        ProductWarningModalPlugin,
        '[data-warning-modal]'
    );
    window.PluginManager.register(
        'CrossSellingSlider',
        CrossSellingSliderPlugin,
        '[data-zanders-cross-selling]'
    );
    window.PluginManager.register(
        'ClientState',
        ClientStatePlugin,
        '[data-client-state]'
    );
    window.PluginManager.register(
        'ListingView',
        ListingViewPlugin,
        '[data-listing-view-switch]'
    );
    window.PluginManager.register(
        'ListingStockFilter',
        ListingStockFilterPlugin,
        '[data-listing-stock-filter]'
    );
    window.PluginManager.register(
        'ProductGalleryThumbnails',
        ProductGalleryThumbnailsPlugin,
        '[data-product-gallery-thumbnails]'
    );
}

// Export for module systems or global access
if (typeof window !== 'undefined') {
    window.AgeVerificationModalPlugin = AgeVerificationModalPlugin;
    window.ProductComparePlugin = ProductComparePlugin;
    window.OrderPrintPlugin = OrderPrintPlugin;
    window.OrderInvoicePrintPlugin = OrderInvoicePrintPlugin;
    window.ProductFinderPlugin = ProductFinderPlugin;
    window.ProductWarningModalPlugin = ProductWarningModalPlugin;
    window.CrossSellingSliderPlugin = CrossSellingSliderPlugin;
    window.ClientStatePlugin = ClientStatePlugin;
    window.ListingViewPlugin = ListingViewPlugin;
    window.ListingStockFilterPlugin = ListingStockFilterPlugin;
    window.ProductGalleryThumbnailsPlugin = ProductGalleryThumbnailsPlugin;
    window.printInvoiceUrl = printInvoiceUrl;
}

const initStorefront = () => {
    console.info('[ZandersTheme] Storefront JavaScript initialized.');

    // Product Gallery Thumbnails Direct Initialization
    const galleryThumbsEl = document.querySelector('[data-product-gallery-thumbnails]');
    if (galleryThumbsEl && !window.zandersGalleryThumbsInstance) {
        window.zandersGalleryThumbsInstance = new ProductGalleryThumbnailsPlugin(galleryThumbsEl);
    }

    // Global delegated listener for product gallery thumbnails
    document.addEventListener('click', (e) => {
        const thumb = e.target.closest('.zanders-pd-thumb-box, [data-thumbnail-src]');
        if (thumb) {
            const mainImg = document.getElementById('zanders-main-product-image');
            const src = thumb.dataset.thumbnailSrc || (thumb.querySelector('img') ? thumb.querySelector('img').src : null);
            if (src && mainImg) {
                mainImg.src = src;
            }
            document.querySelectorAll('.zanders-pd-thumb-box').forEach(el => el.classList.remove('is-active'));
            thumb.classList.add('is-active');
        }
    });

    // Product Finder Direct Initialization
    if (!window.zandersFinderInstance) {
        window.zandersFinderInstance = new ProductFinderPlugin(document.body);
    }

    // Global delegated listener for Print Invoice buttons / links
    document.addEventListener('click', (e) => {
        const invoiceBtn = e.target.closest('[data-print-invoice], .order-print-invoice-btn, .order-print-invoice-action-link');
        if (invoiceBtn) {
            e.preventDefault();
            e.stopPropagation();
            const url = invoiceBtn.getAttribute('href') || invoiceBtn.dataset.printInvoiceUrl;
            printInvoiceUrl(url, invoiceBtn);
        }
    });

    // Auto-trigger print on order print page
    const printPageEl = document.querySelector('[data-order-print-page]');
    if (printPageEl) {
        new OrderPrintPlugin(printPageEl);
    }

    // Product Compare Direct Initialization
    const compareDrawerEl = document.querySelector('[data-compare-drawer]');
    if (compareDrawerEl) {
        window.zandersCompareInstance = new ProductComparePlugin(compareDrawerEl);
    } else {
        const countElements = document.querySelectorAll('[data-compare-badge], [data-compare-count]');
        countElements.forEach(el => {
            el.style.display = 'none';
        });
    }

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

    // Listing View Switcher Direct Initialization
    const viewSwitchEl = document.querySelector('[data-listing-view-switch]');
    if (viewSwitchEl && !window.zandersListingViewInstance) {
        window.zandersListingViewInstance = new ListingViewPlugin(viewSwitchEl);
    }

    // Stock filter toggle Direct Initialization
    const stockFilterEl = document.querySelector('[data-listing-stock-filter], .zanders-toolbar-stock-toggle, .zanders-stock-link');
    if (stockFilterEl && !window.zandersListingStockFilterInstance) {
        window.zandersListingStockFilterInstance = new ListingStockFilterPlugin(stockFilterEl);
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

        const priceElements = document.querySelectorAll('.zanders-product-price, .zanders-cat-price, .product-price, .product-detail-price, .product-detail-price-container, .zanders-compare-price-val, .zanders-cross-price-row');
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
        } catch (e) { }
    }

    if (priceToggleBtns.length > 0) {
        let isPricesHidden = false;
        try {
            isPricesHidden = localStorage.getItem(PRICE_STORAGE_KEY) === 'true';
        } catch (e) { }

        setPriceVisibility(isPricesHidden);

        priceToggleBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const currentlyHidden = document.body.classList.contains('prices-hidden') || document.documentElement.classList.contains('prices-hidden');
                setPriceVisibility(!currentlyHidden);
            });
        });
    } else {
        document.body.classList.remove('prices-hidden', 'zanders-prices-hidden');
        document.documentElement.classList.remove('prices-hidden', 'zanders-prices-hidden');
    }

    // Cross-Selling Slider & Out-of-Stock Filter
    function initCrossSellingSliders() {
        const sliders = document.querySelectorAll('[data-zanders-cross-selling="true"]');
        sliders.forEach(slider => {
            if (slider.dataset.jsInitialized === 'true') return;
            slider.dataset.jsInitialized = 'true';

            const track = slider.querySelector('[data-cross-track="true"]');
            const prevBtn = slider.querySelector('[data-cross-prev="true"]');
            const nextBtn = slider.querySelector('[data-cross-next="true"]');

            if (!track) return;

            function getScrollStep() {
                const firstCard = track.querySelector('.zanders-cross-slide-item');
                if (firstCard) {
                    return firstCard.offsetWidth + 16;
                }
                return 260;
            }

            if (prevBtn) {
                prevBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    track.scrollBy({ left: -getScrollStep(), behavior: 'smooth' });
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    track.scrollBy({ left: getScrollStep(), behavior: 'smooth' });
                });
            }

            // Wishlist toggle
            const wishlistBtns = slider.querySelectorAll('.zanders-cross-wishlist-btn');
            wishlistBtns.forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    this.classList.toggle('is-active');
                });
            });

            // Fallback Stock toggle for vanilla setup (if PluginManager is not active)
            const stockToggleBtn = slider.querySelector('[data-cross-stock-toggle="true"]');
            if (stockToggleBtn && !window.PluginManager) {
                let isHiding = true;
                const toggleText = stockToggleBtn.querySelector('.zanders-cross-stock-toggle-text');
                const items = track.querySelectorAll('.zanders-cross-slide-item');

                const applyStockVisibility = () => {
                    let hasOutOfStock = false;
                    items.forEach(item => {
                        const isOutOfStock = item.querySelector('.is-out-of-stock') || item.querySelector('[data-in-stock="0"]');
                        if (isOutOfStock) {
                            hasOutOfStock = true;
                            item.style.display = isHiding ? 'none' : '';
                        }
                    });

                    if (!hasOutOfStock) {
                        stockToggleBtn.style.display = 'none';
                        return;
                    }
                    stockToggleBtn.style.display = '';
                    stockToggleBtn.classList.toggle('is-active', isHiding);
                    stockToggleBtn.setAttribute('aria-pressed', (!isHiding).toString());

                    if (toggleText) {
                        toggleText.textContent = isHiding
                            ? (toggleText.dataset.textShow || 'SHOW OUT OF STOCK ITEMS')
                            : (toggleText.dataset.textHide || 'HIDE OUT OF STOCK ITEMS');
                    }
                };

                applyStockVisibility();

                stockToggleBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    isHiding = !isHiding;
                    applyStockVisibility();
                });
            }
        });
    }

    initCrossSellingSliders();

    // Product Warning Modal
    function initProductWarningModal() {
        const modal = document.getElementById('zandersWarningModal');
        if (!modal) return;

        const openBtns = document.querySelectorAll('[data-warning-modal-open="true"]');
        const closeBtns = modal.querySelectorAll('[data-warning-modal-close="true"]');

        function openModal() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        openBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                openModal();
            });
        });

        closeBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                closeModal();
            });
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });
    }

    initProductWarningModal();

    /**
     * Back-in-Stock ("Notify Me") Modal Handler
     */
    function initStockNotificationModal() {
        const modal = document.querySelector('[data-stock-notification-modal]');
        if (!modal) return;

        const inputProductId = modal.querySelector('[data-stock-modal-input-product-id]');
        const nameEl = modal.querySelector('[data-stock-modal-name]');
        const skuEl = modal.querySelector('[data-stock-modal-sku]');
        const imgEl = modal.querySelector('[data-stock-modal-img]');
        const emailInput = modal.querySelector('#zandersStockNotifyEmailInput');
        const closeBtns = modal.querySelectorAll('[data-stock-modal-close="true"]');

        function openModal(btn) {
            const productId = btn.getAttribute('data-product-id');
            const productName = btn.getAttribute('data-product-name') || '';
            const productSku = btn.getAttribute('data-product-sku') || '';
            const productImage = btn.getAttribute('data-product-image') || '';

            if (inputProductId) inputProductId.value = productId || '';
            if (nameEl) nameEl.textContent = productName;
            if (skuEl) skuEl.textContent = productSku;
            if (imgEl) {
                if (productImage) {
                    imgEl.src = productImage;
                    imgEl.alt = productName;
                    imgEl.parentElement.style.display = '';
                } else {
                    imgEl.parentElement.style.display = 'none';
                }
            }

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            if (emailInput && !emailInput.value) {
                setTimeout(() => emailInput.focus(), 150);
            }
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        // Delegate click for trigger buttons (works for dynamically loaded sliders / listings too)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-stock-notify-open="true"]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                openModal(btn);
            }
        });

        closeBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                closeModal();
            });
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });
    }

    initStockNotificationModal();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStorefront);
} else {
    initStorefront();
}
