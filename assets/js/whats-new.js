/**
 * Royal MCP — What's New modal behavior.
 *
 * - Auto-opens on page load when RoyalMcpWhatsNew.autoOpen is true (server
 *   determined based on user_meta royal_mcp_whats_new_seen_version vs.
 *   current plugin version).
 * - Chrome-header "What's New?" trigger button re-opens on demand.
 * - Close X + backdrop click both dismiss. Dismissal via AJAX stamps the
 *   current plugin version onto user_meta so auto-open doesn't re-fire
 *   until the next plugin version ships.
 * - Confetti rains on Slide 1 whenever the modal opens.
 */
(function () {
    'use strict';

    var config = window.RoyalMcpWhatsNew || {};

    var CONFETTI_COLORS = ['#C9A227', '#E8DFC3', '#FAF8F5', '#F5D97C', '#A8871D'];

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }
    function $$(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function spawnConfetti(circle) {
        if (!circle) { return; }
        $$('.rmcp-wn-confetti-piece', circle).forEach(function (p) { p.remove(); });
        for (var i = 0; i < 30; i++) {
            var piece = document.createElement('div');
            piece.className = 'rmcp-wn-confetti-piece';
            var color = CONFETTI_COLORS[Math.floor(Math.random() * CONFETTI_COLORS.length)];
            var startX = Math.random() * 220;
            var drift = (Math.random() - 0.5) * 60;
            var spin = 360 + Math.random() * 720;
            var duration = 2 + Math.random() * 2;
            var delay = Math.random() * 2;
            var width = 5 + Math.random() * 5;
            var height = 8 + Math.random() * 6;
            piece.style.background = color;
            piece.style.left = startX + 'px';
            piece.style.width = width + 'px';
            piece.style.height = height + 'px';
            piece.style.setProperty('--drift', drift + 'px');
            piece.style.setProperty('--spin', spin + 'deg');
            piece.style.animationDuration = duration + 's';
            piece.style.animationDelay = delay + 's';
            circle.appendChild(piece);
        }
    }

    function openModal() {
        var backdrop = $('[data-royal-mcp-wn-backdrop]');
        if (!backdrop) { return; }
        backdrop.removeAttribute('hidden');
        backdrop.classList.add('is-open');
        // Trigger confetti when the modal opens.
        var confettiCircle = $('[data-royal-mcp-wn-confetti]', backdrop);
        if (confettiCircle) { spawnConfetti(confettiCircle); }
        // Trap scroll on body while modal is open.
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        var backdrop = $('[data-royal-mcp-wn-backdrop]');
        if (!backdrop) { return; }
        backdrop.setAttribute('hidden', '');
        backdrop.classList.remove('is-open');
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
