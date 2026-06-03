jQuery(document).ready(function($) {
    'use strict';

    // Platform index counter
    let platformIndex = $('.platform-item').length;

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

    $('#copy-rest-url').on('click', function(e) {
        e.preventDefault();
        const restUrl = $(this).prev('input').val();
        copyToClipboard(restUrl);
        showNotice('REST API URL copied to clipboard!');
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
    // can fall back to Dynamic Client Registration. Pre-1.4.22 there was no UI
    // path to clear these fields once generated.
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

    // ==========================================
    // Platform Management
    // ==========================================

    // Add new platform
    $('#add-platform-btn').on('click', function(e) {
        e.preventDefault();
        const platformId = $('#add-platform-select').val();

        if (!platformId) {
            showNotice('Please select a platform first.', 'error');
            return;
        }

        const platform = royalMcp.platforms[platformId];
        if (!platform) {
            showNotice('Invalid platform selected.', 'error');
            return;
        }

        // Remove empty state if present
        $('.platform-empty-state').remove();

        // Build the platform item HTML
        const html = buildPlatformItemHtml(platform, platformIndex);
        $('#platforms-list').append(html);

        // Expand the newly added platform
        const $newItem = $('#platforms-list .platform-item').last();
        $newItem.find('.platform-config').slideDown();
        $newItem.find('.platform-toggle .dashicons')
            .removeClass('dashicons-arrow-down-alt2')
            .addClass('dashicons-arrow-up-alt2');

        // Reset the dropdown
        $('#add-platform-select').val('');

        // Increment index
        platformIndex++;

        showNotice('Provider added. Configure it and save your changes.');
    });

    // Toggle platform config visibility
    $(document).on('click', '.platform-toggle', function(e) {
        e.preventDefault();
        const $item = $(this).closest('.platform-item');
        const $config = $item.find('.platform-config');
        const $icon = $(this).find('.dashicons');

        $config.slideToggle(200);
        $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    // Remove platform
    $(document).on('click', '.remove-platform', function(e) {
        e.preventDefault();

        if (!confirm(royalMcp.strings.confirmRemove)) {
            return;
        }

        const $item = $(this).closest('.platform-item');
        $item.slideUp(200, function() {
            $(this).remove();

            // Show empty state if no platforms left
            if ($('#platforms-list .platform-item').length === 0) {
                $('#platforms-list').html(`
                    <div class="platform-empty-state">
                        <div class="empty-icon">
                            <span class="dashicons dashicons-cloud"></span>
                        </div>
                        <h3>No outbound AI providers configured</h3>
                        <p>Add a provider below to give this site outbound API access — purely optional, only needed if you want WordPress to call out to AI services.</p>
                    </div>
                `);
            }
        });
    });

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

    // Test connection
    $(document).on('click', '.test-connection', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $item = $btn.closest('.platform-item');
        const $status = $item.find('.connection-status');
        const platformId = $item.data('platform');

        // Collect config from form fields
        const config = {};
        $item.find('[data-field]').each(function() {
            const field = $(this).data('field');
            config[field] = $(this).val();
        });

        // Show loading state
        $btn.prop('disabled', true);
        $btn.find('.dashicons').addClass('spin');
        $status.removeClass('success error').text(royalMcp.strings.testing);

        // Make AJAX request
        $.ajax({
            url: royalMcp.ajaxUrl,
            type: 'POST',
            data: {
                action: 'royal_mcp_test_connection',
                nonce: royalMcp.nonce,
                platform: platformId,
                config: config
            },
            success: function(response) {
                if (response.success) {
                    $status.addClass('success').text(response.data.message);
                } else {
                    $status.addClass('error').text(response.data.message);
                }
            },
            error: function() {
                $status.addClass('error').text('Connection test failed');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btn.find('.dashicons').removeClass('spin');
            }
        });
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
        $status.css('color', '').text('Resetting...');

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

    function buildPlatformItemHtml(platform, index) {
        const fieldsHtml = buildFieldsHtml(platform.fields, index);
        const iconLetter = platform.label.charAt(0);

        let linksHtml = '';
        if (platform.api_key_url) {
            linksHtml += `
                <a href="${escapeHtml(platform.api_key_url)}" target="_blank" class="button button-link">
                    <span class="dashicons dashicons-external"></span>
                    ${royalMcp.strings.getApiKey}
                </a>
            `;
        }
        if (platform.docs_url) {
            linksHtml += `
                <a href="${escapeHtml(platform.docs_url)}" target="_blank" class="button button-link">
                    <span class="dashicons dashicons-book"></span>
                    ${royalMcp.strings.documentation}
                </a>
            `;
        }

        return `
            <div class="platform-item" data-index="${index}" data-platform="${escapeHtml(platform.id)}">
                <div class="platform-header">
                    <div class="platform-info">
                        <span class="platform-icon" style="background-color: ${escapeHtml(platform.color)}">
                            ${escapeHtml(iconLetter)}
                        </span>
                        <div class="platform-details">
                            <h3 class="platform-name">${escapeHtml(platform.label)}</h3>
                            <span class="platform-description">${escapeHtml(platform.description)}</span>
                        </div>
                    </div>
                    <div class="platform-actions">
                        <label class="switch small">
                            <input type="checkbox"
                                   name="royal_mcp_settings[platforms][${index}][enabled]"
                                   value="1"
                                   checked>
                            <span class="slider"></span>
                        </label>
                        <button type="button" class="button platform-toggle">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                        </button>
                        <button type="button" class="button remove-platform">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </div>
                <div class="platform-config">
                    <input type="hidden"
                           name="royal_mcp_settings[platforms][${index}][platform]"
                           value="${escapeHtml(platform.id)}">

                    <table class="form-table platform-fields">
                        ${fieldsHtml}
                    </table>

                    <div class="platform-footer">
                        <div class="platform-links">
                            ${linksHtml}
                        </div>
                        <div class="platform-test">
                            <button type="button" class="button test-connection">
                                <span class="dashicons dashicons-update"></span>
                                ${royalMcp.strings.testConnection}
                            </button>
                            <span class="connection-status"></span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function buildFieldsHtml(fields, index) {
        let html = '';

        for (const [fieldId, field] of Object.entries(fields)) {
            const fieldName = `royal_mcp_settings[platforms][${index}][${fieldId}]`;
            const defaultValue = field.default || '';
            const placeholder = field.placeholder || '';
            const required = field.required ? '<span class="required">*</span>' : '';
            const helpText = field.help ? `<p class="description">${escapeHtml(field.help)}</p>` : '';

            let inputHtml = '';

            switch (field.type) {
                case 'select':
                    let optionsHtml = '';
                    for (const [value, label] of Object.entries(field.options)) {
                        const selected = value === defaultValue ? 'selected' : '';
                        optionsHtml += `<option value="${escapeHtml(value)}" ${selected}>${escapeHtml(label)}</option>`;
                    }
                    inputHtml = `
                        <select
                            name="${escapeHtml(fieldName)}"
                            id="platform-${index}-${escapeHtml(fieldId)}"
                            class="regular-text"
                            data-field="${escapeHtml(fieldId)}"
                        >${optionsHtml}</select>
                    `;
                    break;

                case 'password':
                    inputHtml = `
                        <input
                            type="password"
                            name="${escapeHtml(fieldName)}"
                            id="platform-${index}-${escapeHtml(fieldId)}"
                            value=""
                            class="regular-text"
                            placeholder="${escapeHtml(placeholder)}"
                            data-field="${escapeHtml(fieldId)}"
                            autocomplete="new-password"
                        >
                        <button type="button" class="button toggle-password" title="Show/Hide">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    `;
                    break;

                case 'url':
                    inputHtml = `
                        <input
                            type="url"
                            name="${escapeHtml(fieldName)}"
                            id="platform-${index}-${escapeHtml(fieldId)}"
                            value="${escapeHtml(defaultValue)}"
                            class="regular-text"
                            placeholder="${escapeHtml(placeholder)}"
                            data-field="${escapeHtml(fieldId)}"
                        >
                    `;
                    break;

                case 'text':
                default:
                    inputHtml = `
                        <input
                            type="text"
                            name="${escapeHtml(fieldName)}"
                            id="platform-${index}-${escapeHtml(fieldId)}"
                            value="${escapeHtml(defaultValue)}"
                            class="regular-text"
                            placeholder="${escapeHtml(placeholder)}"
                            data-field="${escapeHtml(fieldId)}"
                        >
                    `;
                    break;
            }

            html += `
                <tr class="platform-field platform-field-${escapeHtml(fieldId)}">
                    <th scope="row">
                        <label for="platform-${index}-${escapeHtml(fieldId)}">
                            ${escapeHtml(field.label)}
                            ${required}
                        </label>
                    </th>
                    <td>
                        ${inputHtml}
                        ${helpText}
                    </td>
                </tr>
            `;
        }

        return html;
    }

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

    // ==========================================
    // Legacy MCP Server Support (for backward compatibility)
    // ==========================================

    let serverIndex = $('#mcp-servers-list .mcp-server-item').length;

    $('#add-server').on('click', function(e) {
        e.preventDefault();

        const template = $('#mcp-server-template').html();
        if (!template) return;

        const newServer = template.replace(/__INDEX__/g, serverIndex);

        $('#mcp-servers-list').append(newServer);
        updateServerNumbers();
        serverIndex++;
    });

    $(document).on('click', '.remove-server', function(e) {
        e.preventDefault();

        if ($('#mcp-servers-list .mcp-server-item').length === 1) {
            showNotice('You must have at least one server configured.', 'error');
            return;
        }

        if (confirm('Are you sure you want to remove this server?')) {
            $(this).closest('.mcp-server-item').remove();
            updateServerNumbers();
        }
    });

    function updateServerNumbers() {
        $('#mcp-servers-list .mcp-server-item').each(function(index) {
            $(this).find('.server-number').text(index + 1);
        });
    }

    updateServerNumbers();
});
