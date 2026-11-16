/**
 * Zanders Sporting Goods - Age Verification Modal Storefront Plugin
 * Extends Shopware 6 Native Storefront Plugin Architecture
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

export default class AgeVerificationModalPlugin extends PluginBase {
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

    init() {
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

        // Trap focus & block Escape key dismiss
        this._onKeyDownHandler = this._onKeyDown.bind(this);
        document.addEventListener('keydown', this._onKeyDownHandler);
    }

    _onConfirm(event) {
        if (event) {
            event.preventDefault();
        }

        // Store user consent in localStorage and cookie
        this.setConsent();

        // Publish Shopware native event via emitter if available
        if (this.$emitter && typeof this.$emitter.publish === 'function') {
            this.$emitter.publish('ageVerificationConfirmed', { verified: true });
        }

        // Dispatch DOM event for any other listeners
        this.el.dispatchEvent(new CustomEvent('zanders:ageVerified', {
            bubbles: true,
            detail: { verified: true }
        }));

        // Close the modal
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
            // LocalStorage might be disabled or restricted
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
