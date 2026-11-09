/**
 * Royal MCP — What's New modal behavior.
 *
 * - Auto-opens on page load when RoyalMcpWhatsNew.autoOpen is true (server
 *   determined based on user_meta royal_mcp_whats_new_seen_version vs.
 *   current plugin version).
 * - Chrome-header "What's New?" trigger button re-opens on demand.
 * - Confetti falls on a slide marked data-royal-mcp-wn-confetti while the modal is open.
 * - Close X + backdrop click both dismiss. Dismissal via AJAX stamps the
 *   current plugin version onto user_meta so auto-open doesn't re-fire
 *   until the next plugin version ships.
 */
(function () {
    'use strict';

    var config = window.RoyalMcpWhatsNew || {};

    var CONFETTI_COLORS = ['#C9A227', '#F5D97C', '#A8871D', '#2C2C2C', '#E8DFC3'];
    var CONFETTI_PIECES = 70;

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }
    function $$(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function clearConfetti(backdrop) {
        $$('.rmcp-wn-confetti-piece', backdrop).forEach(function (piece) {
            piece.parentNode.removeChild(piece);
        });
    }

    function spawnConfetti(backdrop) {
        var slide = $('[data-royal-mcp-wn-confetti]', backdrop);
        if (!slide || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
            return;
        }
        clearConfetti(backdrop);
        for (var i = 0; i < CONFETTI_PIECES; i++) {
            var piece = document.createElement('div');
            piece.className = 'rmcp-wn-confetti-piece';
            piece.setAttribute('aria-hidden', 'true');
            piece.style.background = CONFETTI_COLORS[Math.floor(Math.random() * CONFETTI_COLORS.length)];
            piece.style.left = (Math.random() * 100) + '%';
            piece.style.width = (5 + Math.random() * 5) + 'px';
            piece.style.height = (8 + Math.random() * 6) + 'px';
            piece.style.setProperty('--drift', ((Math.random() - 0.5) * 80) + 'px');
            piece.style.setProperty('--spin', (360 + Math.random() * 720) + 'deg');
            piece.style.setProperty('--fall', (slide.offsetHeight + 40) + 'px');
            piece.style.animationDuration = (2.5 + Math.random() * 2.5) + 's';
            piece.style.animationDelay = (Math.random() * 3) + 's';
            slide.appendChild(piece);
        }
    }

    function openModal() {
        var backdrop = $('[data-royal-mcp-wn-backdrop]');
        if (!backdrop) { return; }
        backdrop.removeAttribute('hidden');
        backdrop.classList.add('is-open');
        spawnConfetti(backdrop);
        // Trap scroll on body while modal is open.
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        var backdrop = $('[data-royal-mcp-wn-backdrop]');
        if (!backdrop) { return; }
        backdrop.setAttribute('hidden', '');
        backdrop.classList.remove('is-open');
        clearConfetti(backdrop);
        document.body.style.overflow = '';
        // Persist dismissal so auto-open doesn't fire again this version.
        persistDismissal();
    }

    function persistDismissal() {
        if (!config.ajaxUrl || !config.action || !config.nonce) { return; }
        var body = 'action=' + encodeURIComponent(config.action) +
                   '&nonce=' + encodeURIComponent(config.nonce);
        try {
            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body,
                keepalive: true
            }).catch(function () { /* silent — dismissal is best-effort */ });
        } catch (e) {
            // Older browsers without fetch — silent fallback via image beacon.
            var img = new Image();
            img.src = config.ajaxUrl + '?' + body + '&_=' + Date.now();
        }
    }

    function bindEvents() {
        // Close via X button.
        $$('[data-royal-mcp-wn-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        // Close via backdrop click (only when clicking the backdrop itself,
        // not the modal or its children).
        var backdrop = $('[data-royal-mcp-wn-backdrop]');
        if (backdrop) {
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) { closeModal(); }
            });
        }
        // Close via Escape key when modal is open.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            if (backdrop && backdrop.classList.contains('is-open')) {
                closeModal();
            }
        });
        // Trigger button in chrome header re-opens modal.
        $$('[data-royal-mcp-wn-trigger]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        });
    }

    function init() {
        bindEvents();
        if (config.autoOpen) {
            // Small delay so admin chrome fully paints before the modal
            // takes over the viewport.
            setTimeout(openModal, 250);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
