<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentSMTP: connection status and a rate-limited test email.
 *
 * Status is read from FluentSMTP's saved settings, its connection health report
 * and its email log table; nothing is sent to a mail provider. Passwords, API
 * keys, tokens and email contents are never returned. Administrators only.
 */
class FluentSMTP {

	/** Test emails Royal MCP sends per site per hour. */
	const TEST_SENDS_PER_HOUR = 5;

	/** Option holding the timestamps of recent test sends. */
	const TEST_SENDS_OPTION = 'royal_mcp_fluentsmtp_test_sends';

	/** Option used as a short lock while one test send takes its slot. */
	const TEST_LOCK_OPTION = 'royal_mcp_fluentsmtp_test_lock';

	/** Age after which a lock left behind by an ended request is taken over. */
	const TEST_LOCK_STALE_SECONDS = 30;

	/** Longest error text returned. */
	const MAX_ERROR_CHARS = 1000;

	/** Arguments each tool accepts. */
	const ALLOWED_ARGS = [
		'fluentsmtp_get_status'      => [],
		'fluentsmtp_send_test_email' => [ 'to', 'plain_text' ],
	];

	/** Connection settings returned; every other key (host, credentials, tokens) stays out. */
	const SAFE_CONNECTION_KEYS = [ 'sender_email', 'sender_name', 'force_from_name', 'force_from_email' ];

	public static function is_available() {
		return defined( 'FLUENTMAIL_PLUGIN_FILE' ) && function_exists( 'fluentMailGetSettings' );
	}

	public static function get_tools() {
		$admins = ' Administrators only.';
		return [
			[
				'name'        => 'fluentsmtp_get_status',
				'description' => 'Read FluentSMTP\'s sending setup: each connection (provider and its name, sender email and name, whether From is forced, the From addresses mapped to it, and its last health check result), the default and fallback connections, whether emails are only simulated, whether the email log is on, how many days it keeps, how many emails it holds and how many failed in the last 7 days, and the last failed send (time and error, with email addresses hidden). Passwords, API keys, tokens and email contents are never returned. Makes no request to the mail provider. Read-only.' . $admins,
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
			],
			[
				'name'        => 'fluentsmtp_send_test_email',
				'description' => 'Send one FluentSMTP test email to the single address given in "to", through FluentSMTP\'s default connection, using FluentSMTP\'s own test email (fixed content; no subject or body can be supplied). This sends a real email and cannot be undone. Refused when no connection is set up and in read-only mode. Limited to ' . self::TEST_SENDS_PER_HOUR . ' test emails per site per hour; every attempt counts, sent or not. Returns sent (true or false) and the error text when sending failed.' . $admins,
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'to'         => [ 'type' => 'string', 'format' => 'email', 'maxLength' => 150, 'description' => 'The one email address to send the test to.' ],
						'plain_text' => [ 'type' => 'boolean', 'description' => 'Send the plain-text version instead of the HTML one. Default false.' ],
					],
					'required'             => [ 'to' ],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use FluentSMTP tools.' );
		}
		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			throw new \Exception( 'You do not have permission to use FluentSMTP tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'FluentSMTP is not active' );
		}
		// FluentSMTP's own management capability, which a site can change through its filter.
		$plugin_cap = function_exists( 'fluentMailManageCapability' ) ? (string) fluentMailManageCapability() : 'manage_options';
		if ( '' === $plugin_cap || ! current_user_can( $plugin_cap ) ) {
			throw new \Exception( 'You do not have permission to use FluentSMTP tools.' );
		}
		if ( null === $args ) {
			$args = [];
		}
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			throw new \Exception( 'Arguments must be an object.' );
		}
		if ( isset( self::ALLOWED_ARGS[ $name ] ) ) {
			$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), self::ALLOWED_ARGS[ $name ] );
			if ( $unknown ) {
				throw new Tool_Refusal( 'unknown_argument', sprintf( 'Unknown argument: %s.', esc_html( implode( ', ', array_slice( $unknown, 0, 5 ) ) ) ) );
			}
		}
		switch ( $name ) {
			case 'fluentsmtp_get_status':
				return self::get_status();
			case 'fluentsmtp_send_test_email':
				return self::send_test_email( $args );
		}
		throw new \Exception( 'Unknown FluentSMTP tool: ' . esc_html( $name ) );
	}

	/** FluentSMTP's saved settings (decrypted by FluentSMTP itself). */
	private static function settings() {
		$s = fluentMailGetSettings( [], false );
		return is_array( $s ) ? $s : [];
	}

	/** Provider key => display name, from FluentSMTP's provider list. */
	private static function provider_names() {
		$names = [];
		$mgr   = '\FluentMail\App\Services\Mailer\Manager';
		if ( function_exists( 'fluentMail' ) && class_exists( $mgr ) ) {
			try {
				$providers = fluentMail( $mgr )->getConfig( 'providers', [] );
				foreach ( is_array( $providers ) ? $providers : [] as $key => $p ) {
					if ( is_array( $p ) && isset( $p['title'] ) && is_scalar( $p['title'] ) ) {
						$names[ (string) $key ] = wp_strip_all_tags( (string) $p['title'] );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $names;
	}

	private static function yes( $v ) {
		return 'yes' === $v || true === $v || '1' === $v || 1 === $v;
	}

	private static function get_status() {
		$settings    = self::settings();
		$connections = is_array( $settings['connections'] ?? null ) ? $settings['connections'] : [];
		$mappings    = is_array( $settings['mappings'] ?? null ) ? $settings['mappings'] : [];
		$misc        = is_array( $settings['misc'] ?? null ) ? $settings['misc'] : [];
		$default     = (string) ( $misc['default_connection'] ?? '' );
		$fallback    = (string) ( $misc['fallback_connection'] ?? '' );
		$health      = get_option( '_fluentsmtp_connection_health', [] );
		$health      = is_array( $health ) ? $health : [];
		$names       = self::provider_names();

		$rows = [];
		foreach ( $connections as $key => $conn ) {
			$ps       = is_array( $conn['provider_settings'] ?? null ) ? $conn['provider_settings'] : [];
			$provider = sanitize_key( (string) ( $ps['provider'] ?? '' ) );
			$mapped   = 0;
			foreach ( $mappings as $from => $to ) {
				if ( (string) $to === (string) $key ) {
					$mapped++;
				}
			}
			$row = [
				'id'               => sanitize_key( (string) $key ),
				'provider'         => $provider,
				'provider_name'    => $names[ $provider ] ?? $provider,
				'title'            => isset( $conn['title'] ) && is_scalar( $conn['title'] ) ? wp_strip_all_tags( (string) $conn['title'] ) : '',
				'sender_email'     => sanitize_email( (string) ( $ps['sender_email'] ?? '' ) ),
				'sender_name'      => wp_strip_all_tags( (string) ( $ps['sender_name'] ?? '' ) ),
				'force_from_email' => self::yes( $ps['force_from_email'] ?? 'no' ),
				'force_from_name'  => self::yes( $ps['force_from_name'] ?? 'no' ),
				'mapped_from_addresses' => $mapped,
				'is_default'       => (string) $key === $default,
				'is_fallback'      => (string) $key === $fallback,
			];
			if ( isset( $health[ $key ] ) && is_array( $health[ $key ] ) ) {
				$h = $health[ $key ];
				$row['health'] = [
					'status'     => in_array( $h['status'] ?? '', [ 'healthy', 'error' ], true ) ? $h['status'] : 'unknown',
					'message'    => self::clean_text( (string) ( $h['message'] ?? '' ), 300 ),
					'checked_at' => self::utc( $h['checked_at'] ?? '' ),
				];
			}
			$rows[] = $row;
		}

		$log    = self::log_summary();
		$status = [
			'plugin'              => [ 'name' => 'FluentSMTP', 'version' => defined( 'FLUENTMAIL_PLUGIN_VERSION' ) ? (string) FLUENTMAIL_PLUGIN_VERSION : '' ],
			'connections'         => $rows,
			'connection_count'    => count( $rows ),
			'default_connection'  => '' !== $default && isset( $connections[ $default ] ) ? sanitize_key( $default ) : null,
			'fallback_connection' => '' !== $fallback && isset( $connections[ $fallback ] ) ? sanitize_key( $fallback ) : null,
			'simulate_emails'     => self::yes( $misc['simulate_emails'] ?? 'no' ) || ( defined( 'FLUENTMAIL_SIMULATE_EMAILS' ) && FLUENTMAIL_SIMULATE_EMAILS ),
			'email_log'           => $log,
			'last_error'          => self::last_error(),
		];
		if ( ! $rows ) {
			$status['note'] = 'FluentSMTP has no connection set up, so WordPress sends email the default way.';
		}
		if ( $status['simulate_emails'] ) {
			$status['simulate_note'] = 'FluentSMTP is set to simulate emails: nothing is actually sent.';
		}
		if ( null === $status['last_error'] ) {
			$status['last_error_note'] = $log['available'] ? 'The email log has no failed send.' : 'No email log to read a failed send from.';
		}
		return $status;
	}

	private static function utc( $mysql ) {
		$ts = is_string( $mysql ) && '' !== $mysql ? strtotime( $mysql . ' UTC' ) : false;
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	private static function log_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'fsmpt_email_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one schema read
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ? $table : '';
	}

	private static function log_summary() {
		global $wpdb;
		$settings = self::settings();
		$misc     = is_array( $settings['misc'] ?? null ) ? $settings['misc'] : [];
		$on       = self::yes( $misc['log_emails'] ?? 'yes' );
		$table    = self::log_table();
		$out      = [
			'enabled'        => $on,
			'available'      => '' !== $table,
			'retention_days' => isset( $misc['log_saved_interval_days'] ) && is_numeric( $misc['log_saved_interval_days'] ) ? (int) $misc['log_saved_interval_days'] : null,
		];
		if ( '' !== $table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- counts only; table name is fixed
			$out['entries']         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			$out['failed_last_7_days'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND created_at >= %s", 'failed', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
			// phpcs:enable
		}
		return $out;
	}

	/** The latest failed send in FluentSMTP's log: time and error only. */
	private static function last_error() {
		global $wpdb;
		$table = self::log_table();
		if ( '' === $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, response, created_at FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT 1", 'failed' ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$resp = (string) ( $row['response'] ?? '' );
		$msg  = '';
		$code = '';
		if ( is_serialized( $resp ) ) {
			$data = @unserialize( trim( $resp ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes refused
			if ( is_array( $data ) ) {
				$msg  = is_scalar( $data['message'] ?? null ) ? (string) $data['message'] : '';
				$code = is_scalar( $data['code'] ?? null ) ? (string) $data['code'] : '';
			}
		} else {
			$msg = $resp;
		}
		return [
			'occurred_at' => self::utc( $row['created_at'] ?? '' ),
			'error_code'  => self::clean_text( $code, 100 ),
			'message'     => '' !== $msg ? self::clean_text( $msg, self::MAX_ERROR_CHARS ) : 'FluentSMTP recorded the failure without an error message.',
		];
	}

	/** Plain text with email addresses hidden, cut to a length. */
	private static function clean_text( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		$text = Form_Entry_Privacy::hide_emails( $text );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $max ) {
			$text = mb_substr( $text, 0, $max ) . '...';
		} elseif ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max ) . '...';
		}
		return $text;
	}

	// ============ Test email ============

	private static function send_test_email( array $args ) {
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		if ( ! array_key_exists( 'to', $args ) ) {
			throw new \Exception( 'to is required: one email address.' );
		}
		$to = $args['to'];
		if ( ! is_string( $to ) ) {
			throw new \Exception( 'to must be one email address.' );
		}
		$to = trim( $to );
		if ( '' === $to || strlen( $to ) > 150 || ! is_email( $to ) || sanitize_email( $to ) !== $to || false === filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			throw new \Exception( 'to is not a valid email address.' );
		}
		$plain = false;
		if ( array_key_exists( 'plain_text', $args ) && null !== $args['plain_text'] ) {
			if ( ! is_bool( $args['plain_text'] ) ) {
				throw new \Exception( 'plain_text must be true or false.' );
			}
			$plain = $args['plain_text'];
		}
		$settings = self::settings();
		if ( empty( $settings['connections'] ) || ! is_array( $settings['connections'] ) ) {
			throw new Tool_Refusal( 'mailer_not_set_up', 'FluentSMTP has no connection set up, so no test email was sent. Add a connection in FluentSMTP first.' );
		}

		$left = self::take_test_quota();

		$failure = null;
		$catch   = static function ( $error ) use ( &$failure ) {
			$failure = $error;
		};
		add_action( 'wp_mail_failed', $catch );
		try {
			// FluentSMTP's own test flow: no fallback connection for a test send.
			if ( ! defined( 'FLUENTMAIL_EMAIL_TESTING' ) ) {
				define( 'FLUENTMAIL_EMAIL_TESTING', true );
			}
			if ( class_exists( '\FluentMail\App\Models\Settings' ) ) {
				$model = new \FluentMail\App\Models\Settings();
				$sent  = (bool) $model->sendTestEmail( [ 'email' => $to, 'isHtml' => $plain ? 'false' : 'true' ], $settings );
			} else {
				$sent = (bool) wp_mail( $to, 'FluentSMTP: Test email', "This is a test email sent through FluentSMTP.\n" );
			}
		} catch ( \Throwable $e ) {
			$sent    = false;
			$failure = $e->getMessage();
		} finally {
			remove_action( 'wp_mail_failed', $catch );
		}

		$error = null;
		if ( ! $sent ) {
			if ( $failure instanceof \WP_Error ) {
				$error = $failure->get_error_message();
			} elseif ( is_string( $failure ) && '' !== $failure ) {
				$error = $failure;
			} else {
				$error = 'The email was not sent and FluentSMTP gave no further detail.';
			}
			$error = self::clean_text( $error, self::MAX_ERROR_CHARS );
		}
		return [
			'sent'                 => $sent,
			'to'                   => $to,
			'format'               => $plain ? 'plain_text' : 'html',
			'error'                => $error,
			'test_sends_left_hour' => $left,
			'undo'                 => 'A sent email cannot be undone.',
		];
	}

	/**
	 * Counts one send against the hourly limit, or refuses. Returns the sends left this hour.
	 * The read, count and write run under a lock, so calls made at the same moment cannot
	 * all take the last slot.
	 */
	private static function take_test_quota() {
		$lock = self::take_lock( self::TEST_LOCK_OPTION, self::TEST_LOCK_STALE_SECONDS );
		if ( ! $lock ) {
			throw new Tool_Refusal( 'busy', 'Another FluentSMTP test send is being counted right now, so no test email was sent. Try again in a few seconds.', [ 'retry_after_seconds' => 5 ] );
		}
		try {
			self::forget_cached_option( self::TEST_SENDS_OPTION );
			$now   = time();
			$times = array_values( array_filter( array_map( 'intval', (array) get_option( self::TEST_SENDS_OPTION, [] ) ), static function ( $t ) use ( $now ) {
				return $t > $now - HOUR_IN_SECONDS && $t <= $now + 60;
			} ) );
			sort( $times );
			if ( count( $times ) >= self::TEST_SENDS_PER_HOUR ) {
				$retry = max( 60, $times[ count( $times ) - self::TEST_SENDS_PER_HOUR ] + HOUR_IN_SECONDS - $now );
				throw new Tool_Refusal(
					'rate_limited',
					sprintf( 'Royal MCP sends up to %d FluentSMTP test emails per site per hour, and %d were sent in the last hour. Try again in about %d minutes.', self::TEST_SENDS_PER_HOUR, count( $times ), (int) ceil( $retry / 60 ) ),
					[ 'retry_after_seconds' => $retry ]
				);
			}
			$times[] = $now;
			update_option( self::TEST_SENDS_OPTION, $times, false );
			return self::TEST_SENDS_PER_HOUR - count( $times );
		} finally {
			self::release_lock( self::TEST_LOCK_OPTION, $lock );
		}
	}

	/** Takes a lock row in the options table; returns the value to release it with, or 0. */
	private static function take_lock( $name, $stale ) {
		global $wpdb;
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, (string) $now ) );
		if ( ! $got ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null !== $held && ( ! ctype_digit( (string) $held ) || (int) $held < $now - (int) $stale ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
				$got = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, (string) $held ) );
			}
		}
		self::forget_cached_option( $name );
		return $got ? $now : 0;
	}

	/** Removes the lock row, only while it still holds this request's value. */
	private static function release_lock( $name, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $value ) );
		self::forget_cached_option( $name );
	}

	/** Drops any cached copy of an option written directly, so get_option() reads the table. */
	private static function forget_cached_option( $name ) {
		wp_cache_delete( $name, 'options' );
		$not = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $not ) && isset( $not[ $name ] ) ) {
			unset( $not[ $name ] );
			wp_cache_set( 'notoptions', $not, 'options' );
		}
	}
}
