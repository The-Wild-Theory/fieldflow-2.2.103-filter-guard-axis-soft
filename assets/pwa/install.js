(function () {
    'use strict';

    var config = window.FieldFlowPWA || null;
    if (!config || !config.enabled) return;

    var version = config.version || '1';
    var storageKey = 'fieldflow_mobile_install_notice_dismissed_' + version;
    var installEvent = null;
    var promptBox = null;
    var helpBox = null;

    function isStandaloneMode() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    }

    function cleanOldPwaRuntime() {
        try {
            if ('caches' in window) {
                caches.keys().then(function (keys) {
                    keys.forEach(function (key) {
                        if (key.indexOf('fieldflow-pwa-') === 0) caches.delete(key);
                    });
                }).catch(function () {});
            }
            /* Mantemos o service worker atual, porque o Chrome precisa dele para mostrar o instalador nativo. */
        } catch (e) {}
    }

    function registerInstallWorker() {
        if (!('serviceWorker' in navigator) || !config.serviceWorkerUrl) return;
        try {
            navigator.serviceWorker.register(config.serviceWorkerUrl, { scope: '/' }).catch(function () {});
        } catch (e) {}
    }

    function isMobileLike() {
        return window.matchMedia('(max-width: 900px)').matches || /android|iphone|ipad|ipod/i.test(navigator.userAgent || '');
    }

    function dismissWindowMs() {
        var frequency = (promptBox && promptBox.getAttribute('data-frequency')) || config.frequency || 'weekly';
        if (frequency === 'always') return 0;
        if (frequency === 'daily') return 24 * 60 * 60 * 1000;
        if (frequency === 'weekly') return 7 * 24 * 60 * 60 * 1000;
        return 3650 * 24 * 60 * 60 * 1000;
    }

    function wasDismissedRecently() {
        try {
            var value = window.localStorage.getItem(storageKey);
            var then = value ? parseInt(value, 10) : 0;
            var windowMs = dismissWindowMs();
            return windowMs > 0 && then && Date.now() - then < windowMs;
        } catch (e) { return false; }
    }

    function isAppContext() {
        if (config.serverApp) return true;
        if (promptBox && promptBox.getAttribute('data-server-app') === '1') return true;
        return !!document.querySelector('#routespro-app, #routespro-client-portal, .rp-premium-shell, .rp-client-portal, .rp-client-premium, [data-fieldflow-app], [data-routespro-app], .routespro-app');
    }

    function moveToBody() {
        document.querySelectorAll('[data-ff-overlay]').forEach(function (el) {
            if (el.parentNode !== document.body) document.body.appendChild(el);
        });
    }

    function showPrompt(force) {
        if (!promptBox) return;
        if (isStandaloneMode()) return;
        if (!isMobileLike()) return;
        if (!isAppContext()) return;
        if (!force && wasDismissedRecently()) return;
        promptBox.hidden = false;
        promptBox.setAttribute('aria-hidden', 'false');
        document.body.classList.add('fieldflow-has-app-mobile');
    }

    function hidePrompt(store) {
        if (promptBox) {
            promptBox.hidden = true;
            promptBox.setAttribute('aria-hidden', 'true');
        }
        if (store) {
            try { window.localStorage.setItem(storageKey, String(Date.now())); } catch (e) {}
        }
    }

    function showManualHelp() {
        if (!helpBox) return;
        var ua = navigator.userAgent || '';
        helpBox.querySelectorAll('[data-ff-ios]').forEach(function (el) { el.hidden = !(/iphone|ipad|ipod/i.test(ua)); });
        helpBox.querySelectorAll('[data-ff-android]').forEach(function (el) { el.hidden = !(/android/i.test(ua)); });
        helpBox.querySelectorAll('[data-ff-desktop], [data-ff-chrome-pending]').forEach(function (el) { el.hidden = true; });
        helpBox.hidden = false;
        helpBox.setAttribute('aria-hidden', 'false');
    }

    function applyAppConfig() {
        var app = document.getElementById('routespro-app');
        if (!app || !config.menu) return;
        Object.keys(config.menu).forEach(function(panel){
            if (config.menu[panel]) return;
            app.querySelectorAll('[data-panel="' + panel + '"], [data-target="' + panel + '"]').forEach(function(el){
                var card = el.closest('.rp-card');
                if (card) card.style.display = 'none';
                else el.style.display = 'none';
            });
        });
        var qsPanel = '';
        try { qsPanel = new URLSearchParams(window.location.search).get('ffp_panel') || ''; } catch (e) {}
        var hashPanel = (window.location.hash || '').replace('#rp-app-', '');
        var storedPanel = '';
        try { storedPanel = sessionStorage.getItem('fieldflow_active_panel') || ''; } catch (e) {}
        if (qsPanel || hashPanel || storedPanel) return;
        var start = config.startPanel;
        if (start && config.menu[start] !== false) {
            var btn = app.querySelector('.rp-tabbar button[data-panel="' + start + '"]');
            if (btn) setTimeout(function(){ btn.click(); }, 80);
        }
    }

    function bind() {
        cleanOldPwaRuntime();
        registerInstallWorker();
        applyAppConfig();
        moveToBody();
        promptBox = document.querySelector('[data-ff-pwa-prompt]');
        helpBox = document.querySelector('[data-ff-pwa-help]');
        if (isAppContext()) document.body.classList.add('fieldflow-has-app-mobile');
        if (!promptBox) return;

        promptBox.querySelectorAll('[data-ff-pwa-dismiss]').forEach(function (btn) {
            btn.addEventListener('click', function () { hidePrompt(true); });
        });
        promptBox.querySelectorAll('[data-ff-pwa-install]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (installEvent) {
                    hidePrompt(false);
                    installEvent.prompt();
                    installEvent.userChoice.finally(function () { installEvent = null; });
                } else {
                    registerInstallWorker();
                    window.setTimeout(function () {
                        if (installEvent) {
                            hidePrompt(false);
                            installEvent.prompt();
                            installEvent.userChoice.finally(function () { installEvent = null; });
                        } else {
                            showManualHelp();
                        }
                    }, 400);
                }
            });
        });
        document.querySelectorAll('[data-ff-pwa-help-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (helpBox) {
                    helpBox.hidden = true;
                    helpBox.setAttribute('aria-hidden', 'true');
                }
            });
        });

        var delay = typeof config.delay === 'number' ? config.delay : 900;
        window.setTimeout(function () { showPrompt(false); }, Math.max(700, delay));
        window.setTimeout(function () { showPrompt(false); }, Math.max(2400, delay + 1200));
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installEvent = event;
        showPrompt(true);
    });

    window.addEventListener('appinstalled', function () {
        hidePrompt(true);
        if (helpBox) helpBox.hidden = true;
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
    window.addEventListener('load', cleanOldPwaRuntime);
}());
