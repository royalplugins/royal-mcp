<?php
namespace Royal_MCP\CLI;

use Royal_MCP\Admin\Settings_Page;
use Royal_MCP\Admin\Site_Health_Tests;
use Royal_MCP\OAuth\Token_Store;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks and manages the Royal MCP server from the command line.
 */
class Commands {

    /**
     * Checks what AI clients need from this site.
     *
     * Runs the same checks as Tools > Site Health, plus the plugin's own
     * state, and reports each one. Exits with status 1 when any check is
     * critical, so it can gate a deployment.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # Show every check.
     *     $ wp royal-mcp connection-health
     *
     *     # Fail a deployment step when a check is critical.
     *     $ wp royal-mcp connection-health --format=json || exit 1
     *
     * @subcommand connection-health
     */
    public function connection_health( $args, $assoc_args ) {
        $settings = get_option( 'royal_mcp_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $bound    = ! empty( $settings['api_key_user_id'] ) ? get_userdata( (int) $settings['api_key_user_id'] ) : false;

        $rows = [
            $this->row( 'plugin_version', 'info', ROYAL_MCP_VERSION ),
            $this->row( 'endpoint', 'info', rest_url( 'royal-mcp/v1/mcp' ) ),
            $this->row( 'plugin_enabled', empty( $settings['enabled'] ) ? 'critical' : 'good', empty( $settings['enabled'] ) ? 'Royal MCP is switched off in its settings; no AI client can connect.' : 'AI clients can connect.' ),
            $this->row( 'read_only_mode', 'info', empty( $settings['read_only_mode'] ) ? 'off' : 'on: tools that change the site are refused' ),
            $this->row( 'api_key_bound_to', $bound ? 'info' : 'recommended', $bound ? $bound->user_login . ' (ID ' . (int) $bound->ID . ')' : 'no user; run `wp royal-mcp rotate-api-key --user=<login>`' ),
            $this->row( 'oauth_connections', 'info', (string) count( Token_Store::list_connections( 500 ) ) ),
        ];

        $health = new Site_Health_Tests();
        foreach ( [
            'permalinks'           => $health->test_permalinks(),
            'discovery_document'   => $health->test_well_known(),
            'authorization_header' => $health->test_authorization_header(),
            'sign_in_addresses'    => $health->test_sign_in_addresses(),
        ] as $check => $result ) {
            $rows[] = $this->row( $check, $result['status'], wp_strip_all_tags( $result['label'] . ' — ' . $result['description'] ) );
        }

        $rows[] = $this->row( 'builders', 'info', implode( ', ', array_keys( array_filter( [
            'divi'      => defined( 'ET_BUILDER_VERSION' ),
            'elementor' => defined( 'ELEMENTOR_VERSION' ),
            'gutenberg' => defined( 'GUTENBERG_VERSION' ),
        ] ) ) ) ?: 'none detected' );

        \WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, [ 'check', 'status', 'detail' ] );

        $critical = array_filter( $rows, static function ( $r ) { return 'critical' === $r['status']; } );
        if ( $critical ) {
            \WP_CLI::warning( count( $critical ) . ' critical check(s).' );
            \WP_CLI::halt( 1 );
        }
    }

    /**
     * Lists the AI clients signed in through OAuth.
     *
     * One row per client and WordPress user that currently holds a live
     * token: the same rows as Royal MCP > Connected Clients.
     *
     * ## OPTIONS
     *
     * [--field=<field>]
     * : Print the value of a single field for each connection.
     *
     * [--fields=<fields>]
     * : Limit the output to specific fields.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - ids
     *   - count
     * ---
     *
     * ## AVAILABLE FIELDS
     *
     * * client_id
     * * client_name
     * * user_login
     * * user_id
     * * live_tokens
     * * last_issued
     * * registered
     *
     * ## EXAMPLES
     *
     *     # Show every connection.
     *     $ wp royal-mcp list-clients
     *
     *     # Export who is connected as which user.
     *     $ wp royal-mcp list-clients --format=csv --fields=client_name,user_login,last_issued
     *
     *     # Count the connections.
     *     $ wp royal-mcp list-clients --format=count
     *
     * @subcommand list-clients
     */
    public function list_clients( $args, $assoc_args ) {
        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'client_id', 'client_name', 'user_login', 'user_id', 'live_tokens', 'last_issued', 'registered' ] );
        $rows      = [];
        foreach ( Token_Store::list_connections( 500 ) as $c ) {
            $user   = get_userdata( (int) $c['user_id'] );
            $rows[] = [
                'client_id'   => $c['client_id'],
                'client_name' => '' !== $c['client_name'] ? $c['client_name'] : '(unknown client)',
                'user_login'  => $user ? $user->user_login : '(deleted user)',
                'user_id'     => (int) $c['user_id'],
                'live_tokens' => (int) $c['live_tokens'],
                'last_issued' => $this->ago( (int) $c['last_issued_age'] ),
                'registered'  => null === $c['registered_age'] ? '(set in plugin settings)' : $this->ago( (int) $c['registered_age'] ),
            ];
        }
        if ( in_array( $formatter->format, [ 'ids', 'count' ], true ) ) {
            $formatter->display_items( array_column( $rows, 'client_id' ) );
            return;
        }
        $formatter->display_items( $rows );
    }

    /**
     * Replaces the API key and prints the new one once.
     *
     * The old key stops working immediately. The new key is shown once and
     * is not stored anywhere in readable form. The key acts as a WordPress
     * user: pass that user with WP-CLI's own --user flag; without it the key
     * stays bound to the user it is bound to now.
     *
     * ## OPTIONS
     *
     * [--porcelain]
     * : Print only the new key.
     *
     * [--yes]
     * : Skip the confirmation.
     *
     * ## EXAMPLES
     *
     *     # Rotate the key so that it acts as the user "admin".
     *     $ wp royal-mcp rotate-api-key --user=admin
     *
     *     # In a script: no prompt, only the key on standard output.
     *     $ wp royal-mcp rotate-api-key --user=admin --porcelain --yes
     *
     * @subcommand rotate-api-key
     */
    public function rotate_api_key( $args, $assoc_args ) {
        $settings = get_option( 'royal_mcp_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];

        $user_id = (int) get_current_user_id();
        if ( $user_id < 1 ) {
            $user_id = (int) ( $settings['api_key_user_id'] ?? 0 );
        }
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        if ( ! $user ) {
            \WP_CLI::error( 'Say which user the key should act as: --user=<login, email or ID>.' );
        }

        \WP_CLI::confirm( sprintf( 'The current API key will stop working immediately and the new one will act as %s. Continue?', $user->user_login ), $assoc_args );

        $plaintext = bin2hex( random_bytes( 16 ) );
        $previous  = (int) ( $settings['api_key_user_id'] ?? 0 );

        $settings['api_key_hash']    = hash( 'sha256', $plaintext );
        $settings['api_key']         = '';
        $settings['api_key_user_id'] = (int) $user->ID;
        Settings_Page::save_programmatically( $settings );

        $stored = get_option( 'royal_mcp_settings', [] );
        if ( ! is_array( $stored ) || ! hash_equals( (string) ( $stored['api_key_hash'] ?? '' ), hash( 'sha256', $plaintext ) ) ) {
            \WP_CLI::error( 'The new key could not be saved.' );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => 'settings:api_key_rotated',
                'request_data'  => wp_json_encode( [ 'source' => 'wp-cli', 'bound_to' => (int) $user->ID, 'previously_bound_to' => $previous ] ),
                'response_data' => wp_json_encode( [ 'status' => 'success' ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
            \WP_CLI::line( $plaintext );
            return;
        }
        \WP_CLI::success( sprintf( 'New API key, acting as %s. Copy it now; it is not shown again.', $user->user_login ) );
        \WP_CLI::line( $plaintext );
    }

    private function row( $check, $status, $detail ) {
        return [ 'check' => $check, 'status' => $status, 'detail' => $detail ];
    }

    /** A point in the past given as seconds ago, as a site-time date. */
    private function ago( $seconds_ago ) {
        return wp_date( 'Y-m-d H:i', time() - max( 0, (int) $seconds_ago ) );
    }
}
