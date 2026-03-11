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

    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get">
                <input type="hidden" name="page" value="royal-mcp-logs">
                <button type="submit" class="button"><?php esc_html_e('Refresh', 'royal-mcp'); ?></button>
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
                <td colspan="5" class="no-items">
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
                    <td class="column-status">
                        <?php
                        $royal_mcp_status_class = $royal_mcp_log->status === 'success' ? 'success' : 'error';
                        $royal_mcp_status_label = $royal_mcp_log->status === 'success' ? esc_html__('Success', 'royal-mcp') : esc_html__('Error', 'royal-mcp');
                        ?>
                        <span class="status-badge status-<?php echo esc_attr($royal_mcp_status_class); ?>">
                            <?php echo esc_html($royal_mcp_status_label); ?>
                        </span>
                    </td>
                    <td class="column-details">
                        <button type="button"
                                class="button button-small view-log-details"
                                data-request="<?php echo esc_attr($royal_mcp_log->request_data); ?>"
                                data-response="<?php echo esc_attr($royal_mcp_log->response_data); ?>">
                            <?php esc_html_e('View Details', 'royal-mcp'); ?>
                        </button>
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
<div id="log-details-modal" class="log-modal" style="display: none;">
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
