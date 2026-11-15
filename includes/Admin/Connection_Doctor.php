<?php
/**
 * Connection Doctor: walks the path an AI client takes to connect (switched on,
 * permalinks, the 401 challenge, the discovery documents, client registration,
 * the Authorization header, the sign-in addresses) from inside the site, names
 * the first step that blocks, and says how to fix it. Shown as a dashboard card.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Royal_MCP\Integrations\Coexistence_Check;
use Royal_MCP\OAuth\Server as OAuth_Server;

class Connection_Doctor {

	const RESULT_OPTION = 'royal_mcp_connection_doctor';
	const RUN_ACTION    = 'royal_mcp_run_connection_doctor';
	const PROBE_HEADER  = 'X-Royal-MCP-Doctor';
	const PROBE_KEY     = 'royal_mcp_doctor_probe';

	/** Whether this request is the Doctor's own registration probe (it is not written to the Activity Log). */
	public static function is_probe_request() {
		return self::is_probe_request_value( isset( $_SERVER['HTTP_X_ROYAL_MCP_DOCTOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ROYAL_MCP_DOCTOR'] ) ) : '' );
	}

	/** Whether a header value is the live one-time probe value. */
	public static function is_probe_request_value( $sent ) {
		$sent = (string) $sent;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $sent ) ) {
			return false;
		}
		$want = (string) get_transient( self::PROBE_KEY . '_' . md5( $sent ) );
		return '' !== $want && hash_equals( $want, $sent );
	}

	public static function register() {
		add_action( 'admin_post_' . self::RUN_ACTION, [ __CLASS__, 'handle_run' ] );
	}

	/** The last run, or null. */
	public static function last() {
		$r = get_option( self::RESULT_OPTION, null );
		if ( ! is_array( $r ) || ! isset( $r['steps'] ) || ! is_array( $r['steps'] ) ) {
			return null;
		}
		$r['ok']            = ! empty( $r['ok'] );
		$r['checked_at']    = (int) ( $r['checked_at'] ?? 0 );
		$r['first_problem'] = is_array( $r['first_problem'] ?? null ) ? $r['first_problem'] + [ 'label' => '' ] : [ 'label' => '' ];
		$r['steps']         = array_values( array_filter( array_map( static function ( $s ) {
			return is_array( $s ) ? $s + [ 'key' => '', 'label' => '', 'status' => 'info', 'detail' => '', 'link' => '', 'link_text' => '' ] : null;
		}, $r['steps'] ) ) );
		return $r;
	}

	public static function handle_run() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability(); per-site option only
			wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::RUN_ACTION );
		update_option( self::RESULT_OPTION, self::run(), false );
		wp_safe_redirect( admin_url( 'admin.php?page=royal-mcp#royal-mcp-doctor' ) );
		exit;
	}

	/**
	 * Run every step.
	 *
	 * @return array{ok: bool, checked_at: int, first_problem: ?array, steps: array}
	 */
	public static function run() {
		$steps    = [];
		$settings = get_option( 'royal_mcp_settings', [] );
		$on       = is_array( $settings ) && ! empty( $settings['enabled'] );
		$steps[]  = $on
			? self::step( 'switched_on', __( 'Royal MCP is switched on', 'royal-mcp' ), 'ok' )
			: self::step( 'switched_on', __( 'Royal MCP is switched off', 'royal-mcp' ), 'problem', __( 'AI clients are refused while Royal MCP is off.', 'royal-mcp' ), admin_url( 'admin.php?page=royal-mcp-settings' ), __( 'Switch it on in Settings', 'royal-mcp' ) );

		$health  = ( new \ReflectionClass( Site_Health_Tests::class ) )->newInstanceWithoutConstructor();
		$steps[] = self::from_health( 'permalinks', $health->test_permalinks() );
		$pretty  = '' !== (string) get_option( 'permalink_structure' );

		if ( $on && $pretty ) {
			$chain = Coexistence_Check::discovery_chain( true );
			$names = [
				'challenge'            => __( 'The MCP address asks clients to sign in (401 with the discovery link)', 'royal-mcp' ),
				'protected_resource'   => __( 'Discovery document (protected resource)', 'royal-mcp' ),
				'authorization_server' => __( 'Discovery document (authorization server)', 'royal-mcp' ),
			];
			foreach ( (array) ( $chain['steps'] ?? [] ) as $s ) {
				$key = (string) ( $s['step'] ?? '' );
				if ( ! isset( $names[ $key ] ) ) {
					continue;
				}
				$by = (string) ( $s['answered_by'] ?? '' );
				if ( in_array( $by, [ 'royal_mcp', 'redirects' ], true ) ) {
					$steps[] = self::step( $key, $names[ $key ], 'ok', (string) ( $s['url'] ?? '' ) );
				} elseif ( 'unknown' === $by ) {
					$steps[] = self::step( $key, $names[ $key ], 'warn', __( 'The site could not reach its own address to check this (a loopback request was blocked or timed out). Clients outside may still connect; if they cannot, ask the host whether the server can call itself.', 'royal-mcp' ) );
				} elseif ( 'other' === $by ) {
					$steps[] = self::step( $key, $names[ $key ], 'problem', ! empty( $s['plugin'] )
						/* translators: 1: plugin name, 2: how to fix it */
						? sprintf( __( 'Another plugin answers this address: %1$s. %2$s', 'royal-mcp' ), (string) $s['plugin'], (string) $s['fix'] )
						: __( 'Something other than Royal MCP answers this address (another plugin, a cache or the host), so clients that read it are sent elsewhere. Clearing the site and host caches is the first thing to try.', 'royal-mcp' ), Well_Known_Notice::STALE_SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) );
				} else {
					/* translators: %d: HTTP status code */
					$steps[] = self::step( $key, $names[ $key ], 'problem', sprintf( __( 'Nothing usable answers this address (HTTP %d). A security plugin, a firewall or the host may block it.', 'royal-mcp' ), (int) ( $s['http_status'] ?? 0 ) ), Well_Known_Notice::SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) );
				}
			}
			$steps[] = self::registration_step( OAuth_Server::build_authorization_server_metadata( '' !== OAuth_Server::namespace_issuer() ) );
		} else {
			$steps[] = self::step( 'discovery', __( 'Discovery and sign-in', 'royal-mcp' ), 'skipped', __( 'Not checked until the steps above are fixed.', 'royal-mcp' ) );
		}

		$steps[] = self::from_health( 'authorization_header', $health->test_authorization_header() );
		$steps[] = self::from_health( 'sign_in_addresses', $health->test_sign_in_addresses() );
		$steps[] = self::activity_step();

		$first = null;
		foreach ( $steps as $s ) {
			if ( 'problem' === $s['status'] ) {
				$first = $s;
				break;
			}
		}
		return [ 'ok' => null === $first, 'checked_at' => time(), 'first_problem' => $first, 'steps' => $steps ];
	}

	/** Client registration answers as an OAuth server should (an empty request is refused with a JSON error; nothing is created). */
	private static function registration_step( $as_doc ) {
		$label = __( 'Client registration answers', 'royal-mcp' );
		$url   = is_array( $as_doc ) && ! empty( $as_doc['registration_endpoint'] ) && is_string( $as_doc['registration_endpoint'] ) ? $as_doc['registration_endpoint'] : '';
		$host  = static function ( $u ) { return strtolower( (string) wp_parse_url( (string) $u, PHP_URL_HOST ) ); };
		if ( '' === $url || $host( $url ) !== $host( home_url( '/' ) ) ) {
			return self::step( 'registration', $label, 'skipped', __( 'The discovery document did not name a registration address on this site.', 'royal-mcp' ) );
		}
		$probe = wp_generate_password( 32, false );
		$probe_key = self::PROBE_KEY . '_' . md5( $probe );
		set_transient( $probe_key, $probe, MINUTE_IN_SECONDS );
		$res = wp_remote_post( $url, [ 'timeout' => 5, 'redirection' => 0, 'sslverify' => false, 'headers' => [ 'Content-Type' => 'application/json', self::PROBE_HEADER => $probe ], 'body' => '' ] );
		delete_transient( $probe_key );
		if ( is_wp_error( $res ) ) {
			return self::step( 'registration', $label, 'warn', __( 'The site could not reach its own registration address to check it (loopback blocked or timed out).', 'royal-mcp' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 && $code < 500 && is_array( $json ) && isset( $json['error'] ) ) {
			return self::step( 'registration', $label, 'ok', $url );
		}
		/* translators: %d: HTTP status code */
		return self::step( 'registration', $label, 'problem', sprintf( __( 'The registration address answered HTTP %d without an OAuth error. A security plugin, a firewall rule or a cache may be answering it instead of Royal MCP; AI apps cannot register there.', 'royal-mcp' ), $code ) );
	}

	/** What the Activity Log shows of real clients: the last sign-in and the last tool call. */
	private static function activity_step() {
		global $wpdb;
		$table = $wpdb->prefix . 'royal_mcp_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$call = $wpdb->get_var( $wpdb->prepare( "SELECT timestamp FROM `{$table}` WHERE timestamp >= %s AND action LIKE 'tools/call:%%' AND status = 'success' ORDER BY id DESC LIMIT 1", $since ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$sign = $wpdb->get_var( $wpdb->prepare( "SELECT timestamp FROM `{$table}` WHERE timestamp >= %s AND action = 'oauth:token' AND status = 'success' ORDER BY id DESC LIMIT 1", $since ) );
		$fmt  = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$when = static function ( $ts ) use ( $fmt ) {
			// Shown as the Activity Log shows the same column.
			return $ts ? mysql2date( $fmt, $ts ) : __( 'none in the last 30 days', 'royal-mcp' );
		};
		/* translators: 1: date of the last sign-in, 2: date of the last tool call */
		$detail = sprintf( __( 'Last AI sign-in: %1$s. Last tool call: %2$s.', 'royal-mcp' ), $when( $sign ), $when( $call ) );
		// The last refused sign-in or MCP request, with the reason the log recorded.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$refused = $wpdb->get_row( $wpdb->prepare( "SELECT action, response_data, timestamp FROM `{$table}` WHERE timestamp >= %s AND status = 'error' AND ( action LIKE 'oauth:%%' OR action LIKE 'mcp:%%' ) ORDER BY id DESC LIMIT 1", $since ), ARRAY_A );
		if ( $refused ) {
			$data   = json_decode( (string) $refused['response_data'], true );
			$reason = is_array( $data ) ? (string) ( $data['description'] ?? ( $data['error_message'] ?? ( $data['error']['message'] ?? ( $data['message'] ?? ( $data['error_code'] ?? ( $data['code'] ?? '' ) ) ) ) ) ) : '';
			$reason = '' !== $reason ? $reason : (string) $refused['action'];
			/* translators: 1: date, 2: what was refused (e.g. oauth:token), 3: the reason recorded */
			$detail .= ' ' . sprintf( __( 'Last refused request: %1$s, %2$s: %3$s', 'royal-mcp' ), $when( $refused['timestamp'] ), (string) $refused['action'], wp_html_excerpt( wp_strip_all_tags( $reason ), 200, '...' ) );
		}
		return self::step( 'activity', __( 'Real clients seen', 'royal-mcp' ), $call ? 'ok' : 'info', $detail, admin_url( 'admin.php?page=royal-mcp-logs' ), __( 'Activity Log', 'royal-mcp' ) );
	}

	private static function from_health( $key, array $r ) {
		$map    = [ 'good' => 'ok', 'recommended' => 'warn', 'critical' => 'problem' ];
		$link   = '';
		$text   = '';
		if ( preg_match( '/<a href="([^"]+)"[^>]*>([^<]+)<\/a>/', (string) ( $r['actions'] ?? '' ), $m ) ) {
			$link = html_entity_decode( $m[1] );
			$text = html_entity_decode( $m[2] );
		}
		return self::step( $key, wp_strip_all_tags( (string) $r['label'] ), $map[ $r['status'] ?? 'good' ] ?? 'warn', trim( wp_strip_all_tags( (string) ( $r['description'] ?? '' ) ) ), $link, $text );
	}

	private static function step( $key, $label, $status, $detail = '', $link = '', $link_text = '' ) {
		return [ 'key' => (string) $key, 'label' => (string) $label, 'status' => (string) $status, 'detail' => (string) $detail, 'link' => (string) $link, 'link_text' => (string) $link_text ];
	}

	/** Dashboard card. */
	public static function render_card() {
		$last = self::last();
		$fmt  = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		echo '<div class="royal-mcp-dash-card royal-mcp-dash-doctor" id="royal-mcp-doctor">';
		echo '<h2>' . esc_html__( 'Connection Doctor', 'royal-mcp' ) . '</h2>';
		echo '<p class="royal-mcp-dash-sub">' . esc_html__( 'Walks the path an AI app takes to connect, from inside this site, and names the first step that blocks it.', 'royal-mcp' ) . '</p>';
		if ( null === $last ) {
			echo '<p>' . esc_html__( 'Not run yet.', 'royal-mcp' ) . '</p>';
		} else {
			if ( $last['ok'] ) {
				echo '<p class="royal-mcp-dash-ok">' . esc_html__( 'Every step passed. If an AI app still cannot connect, its own network or a rule in front of this site (a CDN or the host) may block it; the steps below are what this site sees.', 'royal-mcp' ) . '</p>';
			} else {
				/* translators: %s: name of the step that blocks */
				echo '<p class="royal-mcp-doctor-first"><strong>' . esc_html( sprintf( __( 'Blocked at: %s', 'royal-mcp' ), $last['first_problem']['label'] ) ) . '</strong></p>';
			}
			$icons = [ 'ok' => '&#10003;', 'problem' => '&#10007;', 'warn' => '!', 'skipped' => '&ndash;', 'info' => 'i' ];
			echo '<ol class="royal-mcp-doctor-steps">';
			foreach ( $last['steps'] as $s ) {
				echo '<li class="is-' . esc_attr( $s['status'] ) . '"><span class="royal-mcp-doctor-mark" aria-hidden="true">' . wp_kses( $icons[ $s['status'] ] ?? '?', [] ) . '</span><span><strong>' . esc_html( $s['label'] ) . '</strong>';
				if ( '' !== $s['detail'] && 'ok' !== $s['status'] || ( 'activity' === $s['key'] ) ) {
					echo '<span class="royal-mcp-doctor-detail">' . esc_html( $s['detail'] ) . '</span>';
				}
				if ( '' !== $s['link'] && 'ok' !== $s['status'] ) {
					echo ' <a href="' . esc_url( $s['link'] ) . '"' . ( 0 === strpos( $s['link'], 'https://royalplugins.com/' ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( '' !== $s['link_text'] ? $s['link_text'] : __( 'How to fix', 'royal-mcp' ) ) . '</a>';
				}
				echo '</span></li>';
			}
			echo '</ol>';
			/* translators: %s: date and time */
			echo '<p class="royal-mcp-dash-sub">' . esc_html( sprintf( __( 'Checked %s.', 'royal-mcp' ), wp_date( $fmt, (int) $last['checked_at'] ) ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '">';
		wp_nonce_field( self::RUN_ACTION );
		echo '<button type="submit" class="button">' . esc_html( null === $last ? __( 'Run the check', 'royal-mcp' ) : __( 'Run it again', 'royal-mcp' ) ) . '</button>';
		echo '</form>';
		echo '</div>';
	}
}
