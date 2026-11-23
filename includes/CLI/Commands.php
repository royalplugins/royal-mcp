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

    /** Longest message line wp royal-mcp serve accepts, in bytes. */
    const STDIO_MAX_LINE = 8388608;

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
            $this->row( 'plugin_enabled', empty( $settings['enabled'] ) ? 'critical' : 'good', empty( $settings['enabled'] ) ? 'Royal MCP is switched off (Royal MCP → Connections → Connection settings); no AI client can connect.' : 'AI clients can connect.' ),
            $this->row( 'read_only_mode', 'info', empty( $settings['read_only_mode'] ) ? 'off' : 'on: tools that change the site are refused' ),
            $this->row( 'drafts_only_mode', 'info', empty( $settings['drafts_only_mode'] ) ? 'off' : 'on: AI clients may create and edit drafts only' ),
            $this->row( 'connection_access', 'info', sprintf( 'API key: %s; other MCP servers and browser agents: %s; OAuth clients limited: %d', \Royal_MCP\MCP\Support\Access_Levels::for_api_key(), \Royal_MCP\MCP\Support\Access_Levels::for_other_routes(), count( \Royal_MCP\MCP\Support\Access_Levels::clients() ) ) ),
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
     * token: the same rows as Royal MCP → Connections → Connected Clients.
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
     * * access
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
        $formatter = new \WP_CLI\Formatter( $assoc_args, [ 'client_id', 'client_name', 'user_login', 'user_id', 'live_tokens', 'last_issued', 'registered', 'access' ] );
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
                'registered'  => null === $c['registered_age'] ? '(set in Royal MCP → Connections → Connection settings)' : $this->ago( (int) $c['registered_age'] ),
                'access'      => \Royal_MCP\MCP\Support\Access_Levels::for_client( $c['client_id'] ),
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

        $plaintext = $this->issue_api_key( $user, 'wp-cli' );

        if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
            \WP_CLI::line( $plaintext );
            return;
        }
        \WP_CLI::success( sprintf( 'New API key, acting as %s. Copy it now; it is not shown again.', $user->user_login ) );
        \WP_CLI::line( $plaintext );
    }

    /**
     * Turn drafts-only mode on or off, or show whether it is on.
     *
     * When on, AI clients can create drafts and edit posts and pages that
     * are still drafts or pending, and nothing else.
     *
     * ## OPTIONS
     *
     * <state>
     * : on, off or status.
     * ---
     * options:
     *   - on
     *   - off
     *   - status
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp royal-mcp drafts-only on
     *     $ wp royal-mcp drafts-only status
     *
     * @subcommand drafts-only
     */
    public function drafts_only( $args, $assoc_args ) {
        $state    = isset( $args[0] ) ? strtolower( (string) $args[0] ) : 'status';
        $settings = get_option( 'royal_mcp_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        if ( 'status' === $state ) {
            \WP_CLI::line( empty( $settings['drafts_only_mode'] ) ? 'Drafts-only mode is off.' : 'Drafts-only mode is on.' );
            return;
        }
        if ( ! in_array( $state, [ 'on', 'off' ], true ) ) {
            \WP_CLI::error( 'Say on, off or status.' );
        }
        $prior                        = ! empty( $settings['drafts_only_mode'] );
        $settings['drafts_only_mode'] = 'on' === $state;
        Settings_Page::save_programmatically( $settings );
        if ( $prior !== $settings['drafts_only_mode'] ) {
            Settings_Page::log_setting_change( 'settings:drafts_only_mode', $prior, $settings['drafts_only_mode'] );
        }
        \WP_CLI::success( 'on' === $state ? 'Drafts-only mode is on: AI clients may create and edit drafts only.' : 'Drafts-only mode is off.' );
    }

    /**
     * Set the access level of an OAuth client, of the API key, or of calls
     * through other MCP servers and browser agents.
     *
     * ## OPTIONS
     *
     * <connection>
     * : An OAuth client_id (see list-clients), api-key, or other-routes.
     *
     * <level>
     * : full, read-only or drafts-only.
     *
     * ## EXAMPLES
     *
     *     $ wp royal-mcp set-access api-key read-only
     *     $ wp royal-mcp set-access other-routes drafts-only
     *     $ wp royal-mcp set-access <client_id> full
     *
     * @subcommand set-access
     */
    public function set_access( $args, $assoc_args ) {
        $connection = isset( $args[0] ) ? (string) $args[0] : '';
        $level      = \Royal_MCP\MCP\Support\Access_Levels::normalize( $args[1] ?? '' );
        if ( '' === $level ) {
            \WP_CLI::error( 'Level must be full, read-only or drafts-only.' );
        }
        if ( in_array( $connection, [ 'api-key', 'other-routes' ], true ) ) {
            $settings = get_option( 'royal_mcp_settings', [] );
            $settings = is_array( $settings ) ? $settings : [];
            $key   = 'api-key' === $connection ? 'api_key_access' : 'other_routes_access';
            $prior = 'api-key' === $connection ? \Royal_MCP\MCP\Support\Access_Levels::for_api_key() : \Royal_MCP\MCP\Support\Access_Levels::for_other_routes();
            $settings[ $key ] = $level;
            Settings_Page::save_programmatically( $settings );
            if ( $prior !== $level ) {
                Settings_Page::log_setting_change( 'settings:' . $key, $prior, $level );
            }
            \WP_CLI::success( sprintf( '%s access level: %s.', $connection, $level ) );
            return;
        }
        $prior = \Royal_MCP\MCP\Support\Access_Levels::for_client( $connection );
        try {
            \Royal_MCP\MCP\Support\Access_Levels::set_client( $connection, $level );
        } catch ( \InvalidArgumentException $e ) {
            \WP_CLI::error( $e->getMessage() );
        }
        if ( $prior !== $level ) {
            Settings_Page::log_setting_change( 'settings:client_access:' . substr( sanitize_key( $connection ), 0, 40 ), $prior, $level );
        }
        \WP_CLI::success( sprintf( 'Client %s access level: %s.', $connection, $level ) );
    }

    /**
     * Serves MCP over standard input and output, for an AI client on this machine.
     *
     * Reads one JSON-RPC message per line from standard input and writes each
     * reply as one line to standard output. Messages about the session go to
     * standard error. Nothing listens on the network. Every call goes through
     * the same checks as the HTTP endpoint: tool capabilities, tool groups,
     * read-only and drafts-only modes, and the Activity Log, where the
     * connection is recorded with the auth method cli-stdio. Stops when
     * standard input closes or on Ctrl+C.
     *
     * The AI client acts as the WordPress user named with WP-CLI's own --user
     * flag, which is required. That user must be a Royal MCP administrator
     * unless --allow-non-admin is given; each tool then still needs its own
     * capability.
     *
     * ## OPTIONS
     *
     * [--allow-non-admin]
     * : Serve as a user who is not a Royal MCP administrator.
     *
     * [--access=<level>]
     * : Limit this connection further. The site-wide read-only and drafts-only modes apply either way.
     * ---
     * default: full
     * options:
     *   - full
     *   - read-only
     *   - drafts-only
     * ---
     *
     * ## EXAMPLES
     *
     *     # Command for a local MCP client (Claude Desktop, Claude Code, Cursor).
     *     $ wp royal-mcp serve --user=admin --path=/path/to/wordpress
     *
     *     # A read-only connection.
     *     $ wp royal-mcp serve --user=admin --access=read-only
     *
     * @subcommand serve
     */
    public function serve( $args, $assoc_args ) {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            \WP_CLI::error( 'Say which WordPress user the AI client acts as: wp royal-mcp serve --user=<login, email or ID>.' );
        }
        if ( ! current_user_can( Settings_Page::admin_capability() ) && ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'allow-non-admin', false ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability()
            \WP_CLI::error( sprintf( '%s is not a Royal MCP administrator. To serve as this user anyway, add --allow-non-admin; each tool still needs its own capability.', $user->user_login ) );
        }
        $settings = get_option( 'royal_mcp_settings', [] );
        if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
            \WP_CLI::error( 'Royal MCP is switched off (Royal MCP → Connections → Connection settings).' );
        }
        if ( ! \Royal_MCP\MCP\Support\Route_Roles::connect_allowed( (int) $user->ID ) ) {
            \WP_CLI::error( sprintf( '%s is not allowed to connect AI clients to this site (Royal MCP → Safety).', $user->user_login ) );
        }
        $access = \Royal_MCP\MCP\Support\Access_Levels::normalize( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'access', 'full' ) );
        if ( '' === $access ) {
            \WP_CLI::error( 'Access must be full, read-only or drafts-only.' );
        }

        $server = new \Royal_MCP\MCP\Server();
        $server->start_stdio_session( $access, (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'allow-non-admin', false ) );

        // A stop request while a message is being handled waits for that message to finish.
        $stop      = false;
        $busy      = false;
        $on_signal = static function () use ( &$stop, &$busy ) {
            $stop = true;
            if ( ! $busy ) {
                fwrite( STDERR, "Royal MCP: stopping.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- MCP STDIO transport: session messages go to standard error
                exit( 0 );
            }
            return true;
        };
        if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal' ) ) {
            pcntl_async_signals( true );
            pcntl_signal( SIGINT, $on_signal );
            pcntl_signal( SIGTERM, $on_signal );
        } elseif ( function_exists( 'sapi_windows_set_ctrl_handler' ) ) {
            sapi_windows_set_ctrl_handler( $on_signal );
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- MCP STDIO transport: replies on standard output, session messages on standard error
        fwrite( STDERR, sprintf( "Royal MCP: serving MCP over standard input/output as %s (access: %s). Close standard input to stop.\n", $user->user_login, $access ) );
        $max_line = self::STDIO_MAX_LINE;
        while ( ! $stop ) {
            $line = fgets( STDIN, $max_line + 2 );
            if ( false === $line ) {
                break;
            }
            $too_long = false;
            if ( strlen( $line ) > $max_line && "\n" !== substr( $line, -1 ) ) {
                // Read past the rest of the line without keeping it; the session goes on with the next line.
                $too_long = true;
                while ( ! feof( STDIN ) ) {
                    $rest = fgets( STDIN, 65536 );
                    if ( false === $rest || "\n" === substr( $rest, -1 ) ) {
                        break;
                    }
                }
            }
            $line = $too_long ? '' : trim( $line );
            if ( ! $too_long && '' === $line ) {
                continue;
            }
            $busy = true;
            if ( $too_long || strlen( $line ) > $max_line ) {
                $reply = [ 'jsonrpc' => '2.0', 'id' => null, 'error' => [ 'code' => -32600, 'message' => 'Message too large.' ] ];
            } else {
                $body = json_decode( $line, true );
                if ( JSON_ERROR_NONE !== json_last_error() ) {
                    $reply = [ 'jsonrpc' => '2.0', 'id' => null, 'error' => [ 'code' => -32700, 'message' => 'Parse error' ] ];
                } else {
                    // Anything printed while a message is handled (notices, other plugins' output) must not reach standard output.
                    ob_start();
                    try {
                        $reply = $server->handle_stdio_message( $body );
                    } catch ( \Throwable $e ) {
                        $reply = [ 'jsonrpc' => '2.0', 'id' => is_array( $body ) && isset( $body['id'] ) && ( is_string( $body['id'] ) || is_int( $body['id'] ) ) ? $body['id'] : null, 'error' => [ 'code' => -32603, 'message' => 'Internal error' ] ];
                    }
                    // The next message reads the site afresh, also when other processes changed it meanwhile.
                    if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
                        wp_cache_flush_runtime();
                    }
                    $stray = (string) ob_get_clean();
                    if ( '' !== trim( $stray ) ) {
                        fwrite( STDERR, 'Royal MCP: output while handling a message: ' . substr( wp_strip_all_tags( $stray ), 0, 2000 ) . "\n" );
                    }
                }
            }
            if ( null !== $reply ) {
                $json = wp_json_encode( $reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                if ( false === $json ) {
                    $json = wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => $reply['id'] ?? null, 'error' => [ 'code' => -32603, 'message' => 'The reply could not be encoded.' ] ] );
                }
                fwrite( STDOUT, $json . "\n" );
                fflush( STDOUT );
            }
            $busy = false;
        }
        fwrite( STDERR, $stop ? "Royal MCP: stopping.\n" : "Royal MCP: input closed; stopping.\n" );
        // phpcs:enable
    }

    /**
     * Checks the connection and prints what an AI client needs to connect.
     *
     * Runs the Connection Doctor, prints the MCP address, and prints
     * ready-to-paste settings for Claude Desktop, Claude Code, Cursor and
     * other MCP clients: a local command (wp royal-mcp serve) for a site on
     * this machine, the HTTP address with OAuth sign-in for a site on the
     * internet. Asks nothing.
     *
     * ## OPTIONS
     *
     * [--client=<client>]
     * : Which client to print settings for.
     * ---
     * default: all
     * options:
     *   - all
     *   - claude-desktop
     *   - claude-code
     *   - cursor
     *   - generic
     * ---
     *
     * [--transport=<transport>]
     * : stdio (wp royal-mcp serve on this machine) or http. auto picks stdio for a local site address.
     * ---
     * default: auto
     * options:
     *   - auto
     *   - stdio
     *   - http
     * ---
     *
     * [--create-key]
     * : Create a new API key acting as the --user and print it once. Only its hash is stored. Replaces the current API key.
     *
     * [--yes]
     * : With --create-key, replace an existing API key without asking.
     *
     * [--skip-checks]
     * : Do not run the Connection Doctor.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     # Settings for every client, for the user "admin".
     *     $ wp royal-mcp setup --user=admin
     *
     *     # A new API key and Cursor settings for a remote site, as JSON.
     *     $ wp royal-mcp setup --user=admin --transport=http --client=cursor --create-key --yes --format=json
     *
     * @subcommand setup
     */
    public function setup( $args, $assoc_args ) {
        $format    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'text' );
        $client    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'client', 'all' );
        $transport = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'transport', 'auto' );
        if ( ! in_array( $format, [ 'text', 'json' ], true ) ) {
            \WP_CLI::error( 'Format must be text or json.' );
        }
        if ( ! in_array( $client, [ 'all', 'claude-desktop', 'claude-code', 'cursor', 'generic' ], true ) ) {
            \WP_CLI::error( 'Client must be all, claude-desktop, claude-code, cursor or generic.' );
        }
        if ( ! in_array( $transport, [ 'auto', 'stdio', 'http' ], true ) ) {
            \WP_CLI::error( 'Transport must be auto, stdio or http.' );
        }
        $user     = wp_get_current_user();
        $user     = $user && $user->exists() ? $user : null;
        $settings = get_option( 'royal_mcp_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $mcp_url  = rest_url( 'royal-mcp/v1/mcp' );
        $local    = self::is_local_site( home_url( '/' ) );
        $use      = 'auto' === $transport ? ( $local ? 'stdio' : 'http' ) : $transport;

        $key        = null;
        $create_key = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'create-key', false );
        if ( $create_key ) {
            if ( 'stdio' === $use ) {
                \WP_CLI::error( 'The local command (wp royal-mcp serve) needs no key. For a key, add --transport=http.' );
            }
            if ( ! $user ) {
                \WP_CLI::error( 'Say which user the key acts as: --user=<login, email or ID>.' );
            }
            if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability()
                \WP_CLI::error( sprintf( '%s is not a Royal MCP administrator, so the key cannot act as this user.', $user->user_login ) );
            }
            $has_key = '' !== (string) ( $settings['api_key_hash'] ?? '' ) || '' !== (string) ( $settings['api_key'] ?? '' );
            if ( $has_key && ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
                \WP_CLI::error( 'This site already has an API key. A new key replaces it and the old one stops working. Add --yes to go ahead.' );
            }
        }

        $doctor = null;
        if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-checks', false ) ) {
            $run    = \Royal_MCP\Admin\Connection_Doctor::run();
            $doctor = [
                'ok'            => ! empty( $run['ok'] ),
                'first_problem' => is_array( $run['first_problem'] ?? null ) ? (string) $run['first_problem']['label'] : '',
                'steps'         => array_map( static function ( $s ) {
                    return [ 'key' => (string) $s['key'], 'label' => (string) $s['label'], 'status' => (string) $s['status'], 'detail' => (string) $s['detail'] ];
                }, (array) ( $run['steps'] ?? [] ) ),
            ];
        }

        // The key is made once every check has run, so nothing after this point can stop it being shown.
        if ( $create_key ) {
            $key = [ 'type' => 'api-key', 'value' => $this->issue_api_key( $user, 'wp-cli setup' ), 'acts_as' => $user->user_login ];
        }

        $login   = $user ? $user->user_login : '<login>';
        $clients = $this->client_snippets( $use, $mcp_url, $login, null !== $key );
        if ( 'all' !== $client ) {
            $clients = array_intersect_key( $clients, [ $client => true ] );
        }

        $out = [
            'site'          => home_url( '/' ),
            'mcp_url'       => $mcp_url,
            'enabled'       => ! empty( $settings['enabled'] ),
            'user'          => $user ? $user->user_login : null,
            'transport'     => $use,
            'local_site'    => $local,
            'connection_doctor' => $doctor,
            'key'           => $key,
            'clients'       => $clients,
        ];

        if ( 'json' === $format ) {
            \WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            return;
        }

        \WP_CLI::line( 'MCP address: ' . $mcp_url );
        if ( empty( $settings['enabled'] ) ) {
            \WP_CLI::warning( 'Royal MCP is switched off; no AI client can connect until it is switched on (Royal MCP → Connections → Connection settings).' );
        }
        if ( null !== $doctor ) {
            \WP_CLI::line( '' );
            \WP_CLI::line( 'Connection Doctor:' );
            foreach ( $doctor['steps'] as $s ) {
                \WP_CLI::line( sprintf( '  [%s] %s%s', $s['status'], $s['label'], '' !== $s['detail'] ? ' - ' . $s['detail'] : '' ) );
            }
            if ( ! $doctor['ok'] ) {
                \WP_CLI::warning( 'First problem: ' . $doctor['first_problem'] . ( 'stdio' === $use ? ' (the local wp royal-mcp serve command does not depend on it).' : '' ) );
            }
        }
        if ( null !== $key ) {
            \WP_CLI::line( '' );
            \WP_CLI::line( sprintf( 'New API key, acting as %s. Copy it now; it is not shown again:', $key['acts_as'] ) );
            \WP_CLI::line( $key['value'] );
        }
        foreach ( $clients as $c ) {
            \WP_CLI::line( '' );
            \WP_CLI::line( $c['name'] . ' (' . $c['transport'] . '):' );
            \WP_CLI::line( '  ' . $c['how'] );
            if ( isset( $c['config'] ) ) {
                \WP_CLI::line( (string) wp_json_encode( $c['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            }
            if ( isset( $c['command'] ) ) {
                \WP_CLI::line( '  ' . $c['command'] );
            }
        }
    }

    /** Whether a site address points at this machine or a local network (where a local command can reach WordPress). */
    private static function is_local_site( $url ) {
        if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
            return true;
        }
        $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
        if ( '' === $host || 'localhost' === $host ) {
            return true;
        }
        foreach ( [ '.localhost', '.test', '.local', '.example', '.invalid', '.lan', '.internal' ] as $suffix ) {
            if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
                return true;
            }
        }
        $ip = trim( $host, '[]' );
        return false !== filter_var( $ip, FILTER_VALIDATE_IP ) && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * Settings for each client.
     *
     * @param string $transport stdio or http.
     * @param string $mcp_url   The MCP address.
     * @param string $login     User the local command acts as.
     * @param bool   $with_key  Whether an API key was just created (the HTTP settings then send it).
     * @return array<string, array>
     */
    private function client_snippets( $transport, $mcp_url, $login, $with_key ) {
        $name = 'royal-mcp';
        if ( 'stdio' === $transport ) {
            // Forward slashes work for WP-CLI on every system and need no escaping in any shell or JSON.
            $wp_args = [ 'royal-mcp', 'serve', '--user=' . $login, '--path=' . str_replace( '\\', '/', untrailingslashit( ABSPATH ) ) ];
            $server  = [ 'command' => 'wp', 'args' => $wp_args ];
            $shell   = 'wp ' . implode( ' ', array_map( [ __CLASS__, 'shell_arg' ], $wp_args ) );
            return [
                'claude-desktop' => [ 'name' => 'Claude Desktop', 'transport' => 'stdio', 'how' => 'Settings > Developer > Edit Config, add this to claude_desktop_config.json and restart Claude Desktop. If wp is not found, put the full path to wp (or to php and wp-cli.phar) in "command".', 'config' => [ 'mcpServers' => [ $name => $server ] ] ],
                'claude-code'    => [ 'name' => 'Claude Code', 'transport' => 'stdio', 'how' => 'Run this in a terminal:', 'command' => 'claude mcp add ' . $name . ' -- ' . $shell ],
                'cursor'         => [ 'name' => 'Cursor', 'transport' => 'stdio', 'how' => 'Add this to .cursor/mcp.json in your project (or ~/.cursor/mcp.json for every project):', 'config' => [ 'mcpServers' => [ $name => $server ] ] ],
                'generic'        => [ 'name' => 'Other MCP clients', 'transport' => 'stdio', 'how' => 'Start this command as a local (STDIO) MCP server:', 'command' => $shell ],
            ];
        }
        $auth_header = 'Authorization: Bearer <API key>';
        $http        = [ 'url' => $mcp_url ];
        if ( $with_key ) {
            $http['headers'] = [ 'Authorization' => 'Bearer <API key>' ];
        }
        return [
            'claude-desktop' => [ 'name' => 'Claude Desktop', 'transport' => 'http', 'how' => 'Plugins > Add > Add custom connector (earlier versions of Claude: Settings > Connectors), paste the MCP address ' . $mcp_url . ' and sign in to WordPress when asked (OAuth).' ],
            'claude-code'    => [ 'name' => 'Claude Code', 'transport' => 'http', 'how' => $with_key ? 'Run this in a terminal, with the API key in place of <API key>:' : 'Run this in a terminal, then run /mcp in Claude Code and sign in to WordPress (OAuth):', 'command' => 'claude mcp add --transport http ' . $name . ' ' . $mcp_url . ( $with_key ? ' --header "' . $auth_header . '"' : '' ) ],
            'cursor'         => [ 'name' => 'Cursor', 'transport' => 'http', 'how' => 'Add this to .cursor/mcp.json' . ( $with_key ? ', with the API key in place of <API key>:' : '; Cursor asks you to sign in to WordPress (OAuth):' ), 'config' => [ 'mcpServers' => [ $name => $http ] ] ],
            'generic'        => [ 'name' => 'Other MCP clients', 'transport' => 'http', 'how' => 'Add a remote (Streamable HTTP) MCP server with this address. Clients that support OAuth sign in to WordPress; others send the header ' . $auth_header . '.', 'command' => $mcp_url ],
        ];
    }

    /**
     * One argument for a command line that works in bash, PowerShell and cmd alike: left as is
     * when it holds only safe characters, otherwise in double quotes with backslashes turned
     * into forward slashes and the characters a shell would expand left out.
     */
    private static function shell_arg( $arg ) {
        $arg = (string) $arg;
        if ( preg_match( '/^[A-Za-z0-9_\-=.,\/:@]+$/', $arg ) ) {
            return $arg;
        }
        return '"' . str_replace( [ '\\', '"', '$', '`', '%', '!' ], [ '/', '', '', '', '', '' ], $arg ) . '"';
    }

    /**
     * Replace the API key with a new one acting as the given user. Only the
     * key's hash is stored and only the change is logged; the key itself is
     * returned once for the caller to print.
     *
     * @param \WP_User $user   User the key acts as.
     * @param string   $source What issued it, for the Activity Log.
     * @return string The new key.
     */
    private function issue_api_key( $user, $source ) {
        $settings  = get_option( 'royal_mcp_settings', [] );
        $settings  = is_array( $settings ) ? $settings : [];
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
                'request_data'  => wp_json_encode( [ 'source' => (string) $source, 'bound_to' => (int) $user->ID, 'previously_bound_to' => $previous ] ),
                'response_data' => wp_json_encode( [ 'status' => 'success' ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
        return $plaintext;
    }

    private function row( $check, $status, $detail ) {
        return [ 'check' => $check, 'status' => $status, 'detail' => $detail ];
    }

    /** A point in the past given as seconds ago, as a site-time date. */
    private function ago( $seconds_ago ) {
        return wp_date( 'Y-m-d H:i', time() - max( 0, (int) $seconds_ago ) );
    }
}
