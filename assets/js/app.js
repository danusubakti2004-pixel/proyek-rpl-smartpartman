/**
 * SmartPartMan — JavaScript global (tanpa framework).
 * Fitur: konfirmasi aksi berdampak besar (data-confirm), tutup otomatis pesan,
 * serta ciutkan/bentangkan sidebar dengan state tersimpan.
 */
(function () {
    'use strict';

    // Konfirmasi sebelum submit form/klik dengan atribut data-confirm.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        var anchor = target.closest('a[data-confirm]');
        if (anchor && !window.confirm(anchor.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        // ----- Sidebar collapse (desktop) -----
        var COLLAPSE_KEY = 'smartpartman_sidebar_collapsed';
        var toggle = document.getElementById('sidebarToggleDesktop');
        var isDesktop = function () {
            return window.matchMedia('(min-width: 992px)').matches;
        };

        var applyCollapsed = function (collapsed, persist) {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            document.documentElement.classList.remove('sidebar-collapsed-early');
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                toggle.setAttribute('title', collapsed ? 'Bentangkan sidebar' : 'Ciutkan sidebar');
            }
            if (persist) {
                try { localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0'); } catch (e) {}
            }
        };

        var startCollapsed = false;
        try {
            startCollapsed = localStorage.getItem(COLLAPSE_KEY) === '1';
        } catch (e) {}
        applyCollapsed(startCollapsed && isDesktop(), false);
        // Pastikan state awal selalu dibersihkan dari <html>.
        document.documentElement.classList.remove('sidebar-collapsed-early');

        if (toggle) {
            toggle.addEventListener('click', function () {
                applyCollapsed(!document.body.classList.contains('sidebar-collapsed'), true);
            });
        }

        // Saat pindah ukuran layar, jangan biarkan collapse mengganggu mobile.
        window.addEventListener('resize', function () {
            if (!isDesktop()) {
                document.body.classList.remove('sidebar-collapsed');
            } else {
                var stored = false;
                try { stored = localStorage.getItem(COLLAPSE_KEY) === '1'; } catch (e) {}
                document.body.classList.toggle('sidebar-collapsed', stored);
            }
        });

        // ----- Posisi scroll sidebar (per pengguna, per desktop/mobile) -----
        // Elemen yang benar-benar menggulir adalah .sidebar-body, bukan <nav>.
        var scrollUser = document.body.getAttribute('data-user-id') || 'anon';
        var SCROLL_PREFIX = 'smartpartman_sidebar_scroll:';

        var initSidebarScroll = function (el, scope) {
            if (!el) { return null; }

            var key = SCROLL_PREFIX + scrollUser + ':' + scope;
            var read = function () {
                try {
                    var value = sessionStorage.getItem(key);
                    return value === null ? 0 : (parseInt(value, 10) || 0);
                } catch (e) {
                    return 0;
                }
            };
            var save = function () {
                try { sessionStorage.setItem(key, String(el.scrollTop)); } catch (e) {}
            };
            var restore = function () {
                el.scrollTop = read();
            };

            // Pulihkan setelah layout tersedia, setelah paint, dan setelah load
            // agar tidak tertimpa proses inisialisasi sidebar/collapse.
            restore();
            window.requestAnimationFrame(restore);
            window.addEventListener('load', restore, { once: true });
            window.addEventListener('pageshow', restore);

            var ticking = false;
            el.addEventListener('scroll', function () {
                if (ticking) { return; }
                ticking = true;
                window.requestAnimationFrame(function () {
                    ticking = false;
                    save();
                });
            }, { passive: true });

            el.addEventListener('click', save);
            window.addEventListener('pagehide', save);
            window.addEventListener('beforeunload', save);
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') { save(); }
            });

            return { restore: restore, save: save };
        };

        initSidebarScroll(document.querySelector('#sidebarMenu .sidebar-body'), 'desktop');

        var mobileMenu = document.getElementById('mobileMenu');
        var mobileScroll = initSidebarScroll(document.querySelector('#mobileMenu .sidebar-body'), 'mobile');
        if (mobileMenu && mobileScroll) {
            // Offcanvas tersembunyi saat halaman dimuat; pulihkan saat dibuka.
            mobileMenu.addEventListener('shown.bs.offcanvas', mobileScroll.restore);
            mobileMenu.addEventListener('hide.bs.offcanvas', mobileScroll.save);
        }

        // ----- Tutup otomatis pesan setelah 6 detik -----
        window.setTimeout(function () {
            document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
                try {
                    var instance = window.bootstrap.Alert.getOrCreateInstance(alert);
                    instance.close();
                } catch (e) {
                    alert.remove();
                }
            });
        }, 6000);
    });
})();
