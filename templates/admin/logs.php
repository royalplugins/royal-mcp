<?php
if (!defined('ABSPATH')) {
    exit;
}

$royal_mcp_logs = isset($logs) ? $logs : [];
$royal_mcp_total_items = isset($total_items) ? $total_items : 0;
$royal_mcp_per_page = isset($per_page) ? $per_page : 20;
$royal_mcp_page = isset($page) ? $page : 1;
$royal_mcp_total_pages = ceil($royal_mcp_total_items / $royal_mcp_per_page);
?>

<div class="wrap royal-mcp-logs">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect ?>
    <?php if ( isset( $_GET['royal_mcp_log_cleared'] ) ) : ?>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect ?>
        <?php $royal_mcp_cleared_flag = sanitize_key( wp_unslash( $_GET['royal_mcp_log_cleared'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag ?>
        <?php if ( '1' === $royal_mcp_cleared_flag ) : ?>
            <?php $royal_mcp_cleared_n = isset( $_GET['royal_mcp_log_count'] ) ? absint( wp_unslash( $_GET['royal_mcp_log_count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only count ?>
            <?php /* translators: %s: number of entries */ ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( 'The Activity Log was cleared (%s entry). You can restore it below for 24 hours.', 'The Activity Log was cleared (%s entries). You can restore them below for 24 hours.', $royal_mcp_cleared_n, 'royal-mcp' ), number_format_i18n( $royal_mcp_cleared_n ) ) ); ?></p></div>
        <?php elseif ( 'error' === $royal_mcp_cleared_flag ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The Activity Log could not be cleared in full. Entries that were not cleared are still in the log; any that were cleared can be restored below.', 'royal-mcp' ); ?></p></div>
        <?php elseif ( 'stale' === $royal_mcp_cleared_flag ) : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was cleared: reload this page and use the Clear button on it.', 'royal-mcp' ); ?></p></div>
        <?php else : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was cleared: tick the box next to the Clear button first.', 'royal-mcp' ); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect ?>
    <?php if ( isset( $_GET['royal_mcp_log_restored'] ) ) : ?>
        <?php
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirect
        $royal_mcp_restored_flag = sanitize_key( wp_unslash( $_GET['royal_mcp_log_restored'] ) );
        $royal_mcp_restored_n    = isset( $_GET['royal_mcp_log_count'] ) ? absint( wp_unslash( $_GET['royal_mcp_log_count'] ) ) : 0;
        $royal_mcp_restored_left = isset( $_GET['royal_mcp_log_left'] ) ? absint( wp_unslash( $_GET['royal_mcp_log_left'] ) ) : 0;
        // phpcs:enable
        ?>
        <?php if ( 'done' === $royal_mcp_restored_flag ) : ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php
                /* translators: %s: number of entries */
                echo esc_html( sprintf( _n( '%s entry was restored.', '%s entries were restored.', $royal_mcp_restored_n, 'royal-mcp' ), number_format_i18n( $royal_mcp_restored_n ) ) );
                $royal_mcp_restored_coll = isset( $_GET['royal_mcp_log_collision'] ) ? absint( wp_unslash( $_GET['royal_mcp_log_collision'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only count
                if ( $royal_mcp_restored_coll > 0 ) {
                    /* translators: %s: number of entries */
                    echo ' ' . esc_html( sprintf( _n( '%s entry was not restored because a newer entry uses its number; nothing was written over.', '%s entries were not restored because newer entries use their numbers; nothing was written over.', $royal_mcp_restored_coll, 'royal-mcp' ), number_format_i18n( $royal_mcp_restored_coll ) ) );
                } elseif ( $royal_mcp_restored_left > 0 ) {
                    /* translators: %s: number of entries */
                    echo ' ' . esc_html( sprintf( _n( '%s older entry did not fit under the entry limit and can still be restored later.', '%s older entries did not fit under the entry limit and can still be restored later.', $royal_mcp_restored_left, 'royal-mcp' ), number_format_i18n( $royal_mcp_restored_left ) ) );
                }
                echo ' ' . esc_html__( 'Restored entries older than the retention period are deleted at the next daily clean-up.', 'royal-mcp' );
                ?>
            </p></div>
        <?php elseif ( 'full' === $royal_mcp_restored_flag ) : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was restored: the Activity Log is at its entry limit. Clear or export entries first, then restore.', 'royal-mcp' ); ?></p></div>
        <?php elseif ( 'collision' === $royal_mcp_restored_flag ) : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was restored: newer entries now use the same entry numbers. Nothing was written over; the cleared entries stay restorable until they expire.', 'royal-mcp' ); ?></p></div>
        <?php elseif ( 'copy_failed' === $royal_mcp_restored_flag ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The entries could not be written back to the Activity Log. They are still kept for restoring; try again.', 'royal-mcp' ); ?></p></div>
        <?php else : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was restored: those entries can no longer be restored.', 'royal-mcp' ); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect ?>
    <?php if ( isset( $_GET['royal_mcp_retention'] ) ) : ?>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect ?>
        <?php $royal_mcp_retention_done = sanitize_key( wp_unslash( $_GET['royal_mcp_retention'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag ?>
        <?php if ( 'saved' === $royal_mcp_retention_done ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'How long entries are kept was saved.', 'royal-mcp' ); ?></p></div>
        <?php elseif ( 'network' === $royal_mcp_retention_done ) : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was saved: on a network, only a network administrator can keep entries until they are cleared.', 'royal-mcp' ); ?></p></div>
        <?php elseif ( 'network_kept' === $royal_mcp_retention_done ) : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was saved: a network administrator chose to keep entries until they are cleared, and only a network administrator can change that.', 'royal-mcp' ); ?></p></div>
        <?php elseif ( 'failed' === $royal_mcp_retention_done ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The setting could not be saved. Try again.', 'royal-mcp' ); ?></p></div>
        <?php else : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Nothing was saved: pick one of the periods in the list.', 'royal-mcp' ); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Royal AI Firewall cross-link — deep-link if active, wp.org install pointer otherwise.
    $royal_mcp_raif_active = defined('ROYAL_AI_FIREWALL_VERSION');
    ?>
    <div class="royal-mcp-raif-cta" style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #C9A227;border-radius:6px;padding:1rem 1.25rem;margin:1rem 0 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;box-shadow:0 1px 2px rgba(0,0,0,0.04)">
        <div style="flex:1;min-width:280px">
            <?php if ($royal_mcp_raif_active) : ?>
                <strong style="display:block;font-size:14px;color:#1d2327;margin-bottom:4px"><?php esc_html_e('Also visible in Royal AI Firewall', 'royal-mcp'); ?></strong>
                <span style="font-size:13px;color:#50575e;line-height:1.5"><?php esc_html_e('MCP tool calls flow into your Royal AI Firewall dashboard alongside HTTP-layer AI bot traffic.', 'royal-mcp'); ?></span>
            <?php else : ?>
                <strong style="display:block;font-size:14px;color:#1d2327;margin-bottom:4px"><?php esc_html_e('See non-MCP AI bots hitting your site too', 'royal-mcp'); ?></strong>
                <span style="font-size:13px;color:#50575e;line-height:1.5"><?php esc_html_e('Royal AI Firewall (free) surfaces the GPTBot, ClaudeBot, PerplexityBot, ByteSpider and 50+ others hitting your WordPress site outside of MCP tool calls.', 'royal-mcp'); ?></span>
            <?php endif; ?>
        </div>
        <?php if ($royal_mcp_raif_active) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=royal-ai-firewall')); ?>" class="button button-primary" style="background:#C9A227;border-color:#A88B1F;color:#2C2C2C;text-shadow:none;box-shadow:none"><?php esc_html_e('Open AI Firewall dashboard →', 'royal-mcp'); ?></a>
        <?php else : ?>
            <a href="https://wordpress.org/plugins/royal-ai-firewall/" target="_blank" rel="noopener" class="button button-primary" style="background:#C9A227;border-color:#A88B1F;color:#2C2C2C;text-shadow:none;box-shadow:none"><?php esc_html_e('Install Royal AI Firewall →', 'royal-mcp'); ?></a>
        <?php endif; ?>
    </div>

    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get">
                <input type="hidden" name="page" value="royal-mcp-logs">
                <button type="submit" class="button"><?php esc_html_e('Refresh', 'royal-mcp'); ?></button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . \Royal_MCP\Admin\Changes_Page::MENU_SLUG)); ?>"><?php /* translators: %s: the undo window, e.g. "72 hours" or "7 days" */ echo esc_html( sprintf( __( 'Recent changes (%s)', 'royal-mcp' ), \Royal_MCP\MCP\Undo_Store::window_label() ) ); ?></a>
            </form>
        </div>
        <?php if ($royal_mcp_total_pages > 1) : ?>
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                /* translators: %s: number of items */
                printf(esc_html(_n('%s item', '%s items', $royal_mcp_total_items, 'royal-mcp')), esc_html(number_format_i18n($royal_mcp_total_items)));
                ?>
            </span>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns safe HTML
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $royal_mcp_total_pages,
                'current' => $royal_mcp_page,
            ]));
            ?>
        </div>
        <?php endif; ?>
    </div>

    <?php
    $royal_mcp_keep_days   = \Royal_MCP\Admin\Settings_Page::log_retention_days();
    $royal_mcp_keep_choice = \Royal_MCP\Admin\Settings_Page::log_retention_choice();
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
        <input type="hidden" name="action" value="<?php echo esc_attr( \Royal_MCP\Admin\Settings_Page::LOG_RETENTION_ACTION ); ?>">
        <?php wp_nonce_field( \Royal_MCP\Admin\Settings_Page::LOG_RETENTION_ACTION ); ?>
        <label for="royal-mcp-log-retention" style="margin-right:8px;"><?php esc_html_e( 'Keep entries for', 'royal-mcp' ); ?></label>
        <select id="royal-mcp-log-retention" name="royal_mcp_log_retention">
            <?php foreach ( \Royal_MCP\Admin\Settings_Page::LOG_RETENTION_CHOICES as $royal_mcp_choice ) : ?>
                <option value="<?php echo esc_attr( (string) $royal_mcp_choice ); ?>" <?php selected( $royal_mcp_keep_choice, $royal_mcp_choice ); ?>>
                    <?php
                    if ( 0 === $royal_mcp_choice ) {
                        esc_html_e( 'until I clear them', 'royal-mcp' );
                    } else {
                        /* translators: %s: number of days */
                        echo esc_html( sprintf( _n( '%s day', '%s days', $royal_mcp_choice, 'royal-mcp' ), number_format_i18n( $royal_mcp_choice ) ) );
                    }
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e( 'Save', 'royal-mcp' ); ?></button>
        <?php if ( \Royal_MCP\Admin\Settings_Page::log_retention_locked() ) : ?>
            <span class="description" style="margin-left:12px;"><?php esc_html_e( 'A network administrator chose to keep entries until they are cleared; only a network administrator can change this.', 'royal-mcp' ); ?></span>
        <?php endif; ?>
        <span class="description" style="margin-left:12px;">
            <?php
            if ( $royal_mcp_keep_days !== $royal_mcp_keep_choice ) {
                if ( $royal_mcp_keep_days > 0 ) {
                    /* translators: %s: number of days */
                    echo esc_html( sprintf( _n( 'Code on this site overrides this setting: entries are kept for %s day.', 'Code on this site overrides this setting: entries are kept for %s days.', $royal_mcp_keep_days, 'royal-mcp' ), number_format_i18n( $royal_mcp_keep_days ) ) );
                } else {
                    esc_html_e( 'Code on this site overrides this setting: entries are kept until you clear them.', 'royal-mcp' );
                }
            } elseif ( $royal_mcp_keep_days > 0 ) {
                esc_html_e( 'Older entries are deleted once a day.', 'royal-mcp' );
            }
            $royal_mcp_max_rows = \Royal_MCP\Admin\Settings_Page::log_max_rows();
            if ( $royal_mcp_max_rows > 0 && $royal_mcp_keep_days > 0 ) {
                /* translators: %s: number of entries */
                echo ' ' . esc_html( sprintf( __( 'At most %s entries are kept: sign-in request entries go first, then the oldest.', 'royal-mcp' ), number_format_i18n( $royal_mcp_max_rows ) ) );
            } elseif ( $royal_mcp_max_rows > 0 ) {
                /* translators: %s: number of entries */
                echo ' ' . esc_html( sprintf( __( 'Above %s entries, the oldest sign-in request entries are removed; tool calls and settings changes stay until you clear them.', 'royal-mcp' ), number_format_i18n( $royal_mcp_max_rows ) ) );
            }
            ?>
        </span>
    </form>

    <?php
    $royal_mcp_restorable = \Royal_MCP\Admin\Settings_Page::restorable_log_batches();
    $royal_mcp_clear_upto = \Royal_MCP\Admin\Settings_Page::activity_log_max_id();
    $royal_mcp_when_fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    ?>
    <?php foreach ( $royal_mcp_restorable as $royal_mcp_batch ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="royal-mcp-log-restore" style="margin:0 0 12px;padding:8px 12px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #C9A227;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="<?php echo esc_attr( \Royal_MCP\Admin\Settings_Page::RESTORE_LOG_ACTION ); ?>">
            <input type="hidden" name="batch_id" value="<?php echo esc_attr( $royal_mcp_batch['batch_id'] ); ?>">
            <?php wp_nonce_field( \Royal_MCP\Admin\Settings_Page::RESTORE_LOG_ACTION, '_wpnonce', true ); ?>
            <span>
                <?php
                /* translators: %s: number of entries */
                echo esc_html( sprintf( _n( 'Cleared %s entry.', 'Cleared %s entries.', $royal_mcp_batch['entries'], 'royal-mcp' ), number_format_i18n( $royal_mcp_batch['entries'] ) ) );
                ?>
            </span>
            <button type="submit" class="button"><?php esc_html_e( 'Restore', 'royal-mcp' ); ?></button>
            <span class="description">
                <?php
                /* translators: %s: date and time */
                echo esc_html( sprintf( __( 'until %s', 'royal-mcp' ), wp_date( $royal_mcp_when_fmt, (int) strtotime( $royal_mcp_batch['expires_at'] . ' UTC' ) ) ) );
                ?>
            </span>
        </form>
    <?php endforeach; ?>

    <div class="royal-mcp-log-clear" style="margin:0 0 12px;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 6px;">
            <input type="hidden" name="action" value="<?php echo esc_attr( \Royal_MCP\Admin\Settings_Page::EXPORT_LOG_ACTION ); ?>">
            <input type="hidden" name="upto" value="<?php echo esc_attr( (string) $royal_mcp_clear_upto ); ?>">
            <?php wp_nonce_field( \Royal_MCP\Admin\Settings_Page::EXPORT_LOG_ACTION, '_wpnonce', true ); ?>
            <strong style="margin-right:8px;"><?php esc_html_e( 'Step 1 (optional): download what will be cleared', 'royal-mcp' ); ?></strong>
            <button type="submit" name="format" value="csv" class="button"><?php esc_html_e( 'CSV', 'royal-mcp' ); ?></button>
            <button type="submit" name="format" value="json" class="button"><?php esc_html_e( 'JSON', 'royal-mcp' ); ?></button>
            <span class="description" style="margin-left:12px;"><?php esc_html_e( 'Before you clear, check the download is complete: the CSV ends with a #END line giving the number of entries, the JSON ends with "rows" and "complete": true.', 'royal-mcp' ); ?></span>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Clear the Activity Log? You can restore the entries for 24 hours.', 'royal-mcp' ) ); ?>');">
            <input type="hidden" name="action" value="<?php echo esc_attr( \Royal_MCP\Admin\Settings_Page::CLEAR_LOG_ACTION ); ?>">
            <input type="hidden" name="upto" value="<?php echo esc_attr( (string) $royal_mcp_clear_upto ); ?>">
            <?php wp_nonce_field( \Royal_MCP\Admin\Settings_Page::CLEAR_LOG_ACTION, '_wpnonce', true ); ?>
            <strong style="margin-right:8px;"><?php esc_html_e( 'Step 2:', 'royal-mcp' ); ?></strong>
            <label style="margin-right:8px;">
                <input type="checkbox" name="royal_mcp_clear_confirm" value="1" required>
                <?php esc_html_e( 'Delete every entry shown up to now', 'royal-mcp' ); ?>
            </label>
            <button type="submit" class="button"><?php esc_html_e( 'Clear log', 'royal-mcp' ); ?></button>
            <span class="description" style="margin-left:12px;"><?php esc_html_e( 'Cleared entries can be restored for 24 hours.', 'royal-mcp' ); ?></span>
        </form>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
        <input type="hidden" name="action" value="<?php echo esc_attr( \Royal_MCP\Admin\Settings_Page::EXPORT_LOG_ACTION ); ?>">
        <?php wp_nonce_field( \Royal_MCP\Admin\Settings_Page::EXPORT_LOG_ACTION, '_wpnonce', true ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'royal-mcp' ); ?></button>
        <span class="description" style="margin-left:12px;">
            <?php
            /* translators: %s: number of entries */
            echo esc_html( sprintf( __( 'Downloads the newest entries, up to %s. An entry holds the tool name, the names of its arguments and any error text, never argument values.', 'royal-mcp' ), number_format_i18n( \Royal_MCP\Admin\Settings_Page::LOG_EXPORT_MAX ) ) );
            ?>
        </span>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-timestamp">
                    <?php esc_html_e('Timestamp', 'royal-mcp'); ?>
                </th>
                <th scope="col" class="manage-column column-server">
                    <?php esc_html_e('MCP Server', 'royal-mcp'); ?>
                </th>
                <th scope="col" class="manage-column column-action">
                    <?php esc_html_e('Action', 'royal-mcp'); ?>
                </th>
                <th scope="col" class="manage-column column-who">
                    <?php esc_html_e('User / client', 'royal-mcp'); ?>
                </th>
                <th scope="col" class="manage-column column-status">
                    <?php esc_html_e('Status', 'royal-mcp'); ?>
                </th>
                <th scope="col" class="manage-column column-details">
                    <?php esc_html_e('Details', 'royal-mcp'); ?>
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($royal_mcp_logs)) : ?>
            <tr>
                <td colspan="6" class="no-items">
                    <?php esc_html_e('No activity logs found.', 'royal-mcp'); ?>
                </td>
            </tr>
            <?php else : ?>
                <?php foreach ($royal_mcp_logs as $royal_mcp_log) : ?>
                <tr>
                    <td class="column-timestamp">
                        <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $royal_mcp_log->timestamp)); ?>
                    </td>
                    <td class="column-server">
                        <strong><?php echo esc_html($royal_mcp_log->mcp_server); ?></strong>
                    </td>
                    <td class="column-action">
                        <code><?php echo esc_html($royal_mcp_log->action); ?></code>
                    </td>
                    <td class="column-who">
                        <?php
                        $royal_mcp_req   = json_decode((string) $royal_mcp_log->request_data, true);
                        $royal_mcp_uid   = is_array($royal_mcp_req) && isset($royal_mcp_req['user_id']) && is_numeric($royal_mcp_req['user_id']) ? (int) $royal_mcp_req['user_id'] : 0;
                        $royal_mcp_user  = $royal_mcp_uid > 0 ? get_userdata($royal_mcp_uid) : false;
                        $royal_mcp_cid   = is_array($royal_mcp_req) && isset($royal_mcp_req['client_id']) && is_string($royal_mcp_req['client_id']) ? $royal_mcp_req['client_id'] : '';
                        $royal_mcp_auth  = is_array($royal_mcp_req) && isset($royal_mcp_req['auth']) && is_string($royal_mcp_req['auth']) ? $royal_mcp_req['auth'] : '';
                        $royal_mcp_route = is_array($royal_mcp_req) && isset($royal_mcp_req['route']) && is_string($royal_mcp_req['route']) ? $royal_mcp_req['route'] : '';
                        $royal_mcp_via   = '';
                        if ('' !== $royal_mcp_cid) {
                            $royal_mcp_client = \Royal_MCP\OAuth\Token_Store::get_client($royal_mcp_cid);
                            $royal_mcp_via    = is_array($royal_mcp_client) && !empty($royal_mcp_client['client_name']) ? (string) $royal_mcp_client['client_name'] : $royal_mcp_cid;
                        } elseif ('api-key' === $royal_mcp_auth) {
                            $royal_mcp_via = __('API key', 'royal-mcp');
                        } elseif ('cookie-session' === $royal_mcp_auth) {
                            $royal_mcp_via = __('Browser agent', 'royal-mcp');
                        } elseif ('abilities' === $royal_mcp_route) {
                            $royal_mcp_via = __('Another MCP server or the Abilities API', 'royal-mcp');
                        }
                        if ($royal_mcp_user) {
                            echo esc_html($royal_mcp_user->user_login);
                        } elseif ($royal_mcp_uid > 0) {
                            /* translators: %d: user ID */
                            echo esc_html(sprintf(__('User %d', 'royal-mcp'), $royal_mcp_uid));
                        } else {
                            echo '&mdash;';
                        }
                        if ('' !== $royal_mcp_via) {
                            echo '<br><span class="description">' . esc_html($royal_mcp_via) . '</span>';
                        }
                        ?>
                    </td>
                    <td class="column-status">
                        <?php
                        $royal_mcp_status_class = $royal_mcp_log->status === 'success' ? 'success' : 'error';
                        $royal_mcp_status_label = $royal_mcp_log->status === 'success' ? esc_html__('Success', 'royal-mcp') : esc_html__('Error', 'royal-mcp');
                        ?>
                        <span class="status-badge status-<?php echo esc_attr($royal_mcp_status_class); ?>">
                            <?php echo esc_html($royal_mcp_status_label); ?>
                        </span>
                        <?php
                        $royal_mcp_vresp = json_decode((string) $royal_mcp_log->response_data, true);
                        $royal_mcp_ver   = is_array($royal_mcp_vresp) ? (string) ($royal_mcp_vresp['verified'] ?? '') : '';
                        if ('verified' === $royal_mcp_ver) {
                            $royal_mcp_landed = is_array($royal_mcp_vresp['landed'] ?? null) ? implode(', ', array_map('strval', $royal_mcp_vresp['landed'])) : '';
                            /* translators: %s: what changed, e.g. "title, content" */
                            echo '<br><span class="description">' . esc_html('' !== $royal_mcp_landed ? sprintf(__('Read back: %s changed', 'royal-mcp'), $royal_mcp_landed) : __('Read back: saved', 'royal-mcp')) . '</span>';
                        } elseif ('unchanged' === $royal_mcp_ver) {
                            echo '<br><span class="description">' . esc_html__('Read back: nothing changed', 'royal-mcp') . '</span>';
                        }
                        ?>
                    </td>
                    <td class="column-details">
                        <button type="button"
                                class="button button-small view-log-details"
                                data-request="<?php echo esc_attr($royal_mcp_log->request_data); ?>"
                                data-response="<?php echo esc_attr($royal_mcp_log->response_data); ?>">
                            <?php esc_html_e('View Details', 'royal-mcp'); ?>
                        </button>
                        <?php
                        $royal_mcp_response = json_decode((string) $royal_mcp_log->response_data, true);
                        $royal_mcp_change   = is_array($royal_mcp_response) ? (string) ($royal_mcp_response['change_id'] ?? '') : '';
                        if (preg_match('/^[a-f0-9]{20}$/', $royal_mcp_change)) :
                        ?>
                        <a class="button button-small" href="<?php echo esc_url(\Royal_MCP\Admin\Changes_Page::change_url($royal_mcp_change)); ?>"><?php esc_html_e('View change', 'royal-mcp'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($royal_mcp_total_pages > 1) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                /* translators: %s: number of items */
                printf(esc_html(_n('%s item', '%s items', $royal_mcp_total_items, 'royal-mcp')), esc_html(number_format_i18n($royal_mcp_total_items)));
                ?>
            </span>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns safe HTML
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $royal_mcp_total_pages,
                'current' => $royal_mcp_page,
            ]));
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal for log details -->
<div id="log-details-modal" class="log-modal">
    <div class="log-modal-content">
        <span class="log-modal-close">&times;</span>
        <h2><?php esc_html_e('Log Details', 'royal-mcp'); ?></h2>
        <div class="log-details-container">
            <h3><?php esc_html_e('Request Data', 'royal-mcp'); ?></h3>
            <pre id="log-request-data"></pre>
            <h3><?php esc_html_e('Response Data', 'royal-mcp'); ?></h3>
            <pre id="log-response-data"></pre>
        </div>
    </div>
</div>
