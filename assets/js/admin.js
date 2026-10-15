jQuery(document).ready(function($) {
    'use strict';

    // ==========================================
    // Copy Buttons
    // ==========================================

    // Regenerate API key confirmation
    $('#rmcp-regenerate-key').on('click', function(e) {
        if (!confirm(royalMcp.strings.confirmRegenerate)) {
            e.preventDefault();
        }
    });

    $('#copy-api-key').on('click', function(e) {
        e.preventDefault();
        const apiKey = $('#api_key').val();
        copyToClipboard(apiKey);
        showNotice('API key copied to clipboard!');
    });

    // Generic copy button handler
    $(document).on('click', '.copy-btn', function(e) {
        e.preventDefault();
        const targetId = $(this).data('target');
        const $input = $('#' + targetId);
        if ($input.length) {
            copyToClipboard($input.val());
            const $btn = $(this);
            $btn.addClass('copied');
            setTimeout(function() {
                $btn.removeClass('copied');
            }, 1500);
            showNotice('Copied to clipboard!');
        }
    });

    // ==========================================
    // Setup Guides accordion + Advanced collapsibles (1.4.25)
    // ==========================================

    // MCP Client Setup Guides — accordion. Clicking a header toggles its
    // own body open/closed; others remain in whatever state the user left them.
    $(document).on('click', '.setup-guide-header', function(e) {
        e.preventDefault();
        const $item = $(this).closest('.setup-guide-item');
        const isOpen = $item.hasClass('open');
        $item.toggleClass('open');
        $(this).attr('aria-expanded', isOpen ? 'false' : 'true');
    });

    // Advanced (Legacy REST API + manual OAuth credentials)
    $(document).on('click', '.advanced-toggle', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const targetId = $btn.attr('aria-controls') || $btn.attr('id') + '-content';
        const $content = $btn.next('.advanced-content').length
            ? $btn.next('.advanced-content')
            : $('#' + targetId);

        if (!$content.length) return;

        const willOpen = $content.is('[hidden]') || $content.is(':hidden');
        $btn.toggleClass('open', willOpen);
        $btn.attr('aria-expanded', willOpen ? 'true' : 'false');

        if (willOpen) {
            $content.removeAttr('hidden').hide().slideDown(200);
        } else {
            $content.slideUp(200, function() { $(this).attr('hidden', true); });
        }
    });

    // The option allowlist only applies while "Allow AI to write WordPress
    // options" is on, so its row is shown only then. A hidden row still
    // submits its value, so the saved list is kept either way.
    function syncOptionAllowlist() {
        $('#rmcp-row-writable-options').prop('hidden', !$('#allow_option_writes').is(':checked'));
    }
    $('#allow_option_writes').on('change', syncOptionAllowlist);
    syncOptionAllowlist();

    // Generate OAuth credentials
    $(document).on('click', '.generate-oauth', function(e) {
        e.preventDefault();
        const field = $(this).data('field');
        const $input = $('#' + field);

        // Generate a random string
        let value;
        if (field === 'oauth_client_id') {
            value = 'wp_' + generateRandomString(24);
        } else {
            value = generateRandomString(48);
        }

        $input.val(value);
        $(this).hide();
        showNotice('OAuth ' + (field === 'oauth_client_id' ? 'Client ID' : 'Client Secret') + ' generated. Remember to save your settings!');
    });

    // Clear OAuth credentials — wipes the stored value via AJAX so the connector
    // can fall back to Dynamic Client Registration. Without this button an admin
    // in manual-creds mode has no UI path to clear these fields once generated.
    $(document).on('click', '.clear-oauth', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const field = $btn.data('field');
        const fieldLabel = field === 'oauth_client_id' ? 'Client ID' : 'Client Secret';

        if (!confirm('Clear the stored OAuth ' + fieldLabel + '? Any MCP client using these credentials will need to re-authorize.')) {
            return;
        }

        $btn.prop('disabled', true);

        $.ajax({
            url: royalMcp.ajaxUrl,
            type: 'POST',
            data: {
                action: 'royal_mcp_clear_oauth_field',
                nonce: royalMcp.nonce,
                field: field
            },
            success: function(response) {
                if (response.success) {
                    $('#' + field).val('');
                    showNotice('OAuth ' + fieldLabel + ' cleared. The connector will use Dynamic Client Registration on the next handshake.');
                    // Reload so the Generate button reappears in place of Clear.
                    setTimeout(function() { window.location.reload(); }, 800);
                } else {
                    $btn.prop('disabled', false);
                    showNotice((response.data && response.data.message) || 'Failed to clear field.', 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                showNotice('Network error while clearing field.', 'error');
            }
        });
    });

    function generateRandomString(length) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }

    // Toggle password visibility
    $(document).on('click', '.toggle-password', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $input = $btn.parent().find('input[type="password"], input[type="text"]').first();
        const $icon = $btn.find('.dashicons');

        if ($input.length === 0) return;

        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // ==========================================
    // Reset OAuth State button (Troubleshooting section)
    // ==========================================
    $(document).on('click', '#royal-mcp-reset-oauth-state', function(e) {
        e.preventDefault();

        const confirmMsg = 'This will delete all registered OAuth clients, issued access/refresh tokens, and pending authorization codes.\n\nAll currently-connected MCP clients (Claude.ai, Claude Desktop, ChatGPT, etc.) will need to re-authorize after this runs.\n\nYour settings, API key, and Activity Log are NOT affected.\n\nContinue?';
        if (!window.confirm(confirmMsg)) {
            return;
        }

        const $btn = $(this);
        const $status = $('#royal-mcp-reset-oauth-state-status');

        $btn.prop('disabled', true);
        $status.css('color', '').text('Resetting... (can take several minutes)');

        $.ajax({
            url: royalMcp.ajaxUrl,
            type: 'POST',
            data: {
                action: 'royal_mcp_reset_oauth_state',
                nonce: royalMcp.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.css('color', '#00a32a').text(response.data.message);
                } else {
                    $status.css('color', '#d63638').text((response.data && response.data.message) || 'Reset failed');
                }
            },
            error: function() {
                $status.css('color', '#d63638').text('Reset request failed (network or server error)');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ==========================================
    // Helper Functions
    // ==========================================

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = 0;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
    }

    function showNotice(message, type = 'success') {
        const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');

        $('.wrap h1').after(notice);

        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 4000);
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================
    // Logs Page - View Details Modal
    // ==========================================

    $('.view-log-details').on('click', function() {
        // Use .attr() rather than .data() — jQuery's .data() auto-parses
        // JSON-looking attribute values into JavaScript objects, which then
        // crash JSON.parse() and render as "[object Object]" in the modal.
        // .attr() returns the raw string value of the HTML attribute.
        const requestData = $(this).attr('data-request') || '';
        const responseData = $(this).attr('data-response') || '';

        try {
            const formattedRequest = JSON.stringify(JSON.parse(requestData), null, 2);
            $('#log-request-data').text(formattedRequest);
        } catch (e) {
            $('#log-request-data').text(requestData);
        }

        try {
            const formattedResponse = JSON.stringify(JSON.parse(responseData), null, 2);
            $('#log-response-data').text(formattedResponse);
        } catch (e) {
            $('#log-response-data').text(responseData);
        }

        $('#log-details-modal').fadeIn();
    });

    // Close modal
    $('.log-modal-close').on('click', function() {
        $('#log-details-modal').fadeOut();
    });

    // Close modal on outside click
    $(window).on('click', function(e) {
        if ($(e.target).is('#log-details-modal')) {
            $('#log-details-modal').fadeOut();
        }
    });

    // Close modal on escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#log-details-modal').is(':visible')) {
            $('#log-details-modal').fadeOut();
        }
    });

});
