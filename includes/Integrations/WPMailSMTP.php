<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Mail SMTP: sending status, a rate-limited test email, and the email log
 * where the installed edition has one.
 *
 * Reads go through WP Mail SMTP's own classes (Options, the connections
 * manager, the provider loader and the per-connection sending-error record).
 * The test email uses WP Mail SMTP's own test-email class when present.
 * Passwords, API keys, OAuth tokens and SMTP conversation logs are never
 * returned. Administrators only.
 */
class WPMailSMTP {

	/** Test emails Royal MCP sends per site per hour. */
	const TEST_SENDS_PER_HOUR = 5;

	/** Option holding the timestamps of recent test sends. */
	const TEST_SENDS_OPTION = 'royal_mcp_wpmailsmtp_test_sends';

	/** Option used as a short lock while one test send takes its slot. */
	const TEST_LOCK_OPTION = 'royal_mcp_wpmailsmtp_test_lock';

	/** Age after which a lock left behind by an ended request is taken over. */
	const TEST_LOCK_STALE_SECONDS = 30;

	/** Longest error text returned. */
	const MAX_ERROR_CHARS = 1000;

	/** Largest page size for the email log. */
	const MAX_PER_PAGE = 100;

	/** Highest page number accepted for the email log. */
	const MAX_PAGE = 100000;

	/** Arguments each tool accepts. */
	const ALLOWED_ARGS = [
		'wpmailsmtp_get_status'      => [],
		'wpmailsmtp_send_test_email' => [ 'to', 'plain_text' ],
		'wpmailsmtp_get_email_log'   => [ 'page', 'per_page' ],
	];

	public static function is_available() {
		return function_exists( 'wp_mail_smtp' ) && class_exists( '\WPMailSMTP\Core' ) && class_exists( '\WPMailSMTP\Options' );
	}

	public static function get_tools() {
		$admins = ' Administrators only.';
		return [
			[
				'name'        => 'wpmailsmtp_get_status',
				'description' => 'Read WP Mail SMTP\'s sending setup: the active mailer (slug and name) and whether its settings are complete, the From email and From name and whether WP Mail SMTP forces them on every email, whether settings come from wp-config.php constants, whether "Do Not Send" is blocking all email, the plugin version and edition (free or paid), and the last sending error WP Mail SMTP recorded (time, mailer, error code and message, with email addresses hidden). Passwords, API keys, OAuth tokens and SMTP conversation logs are never returned. Makes no request to the mail provider. Read-only.' . $admins,
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'wpmailsmtp_send_test_email',
				'description' => 'Send one WP Mail SMTP test email to the single address given in "to", through the mailer WP Mail SMTP is set up with, using WP Mail SMTP\'s own test email (fixed content; no subject or body can be supplied). This sends a real email through the site\'s mail provider and cannot be undone. Refused when the mailer\'s settings are incomplete and in read-only mode. Limited to ' . self::TEST_SENDS_PER_HOUR . ' test emails per site per hour; every attempt counts, sent or not. Returns sent (true or false), the mailer used, and the error text when sending failed.' . $admins,
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
			[
				'name'        => 'wpmailsmtp_get_email_log',
				'description' => 'Read WP Mail SMTP\'s email log. The free version of WP Mail SMTP has no email log and keeps no record of sent emails, so there this returns available: false with the reason and no entries. Where WP Mail SMTP does keep an email log, this tool does not read it either and says so; use the Email Log screen in WP Mail SMTP. For the last sending error, use wpmailsmtp_get_status. Accepts page and per_page (up to ' . self::MAX_PER_PAGE . ') and always returns total and has_more. Read-only.' . $admins,
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE, 'description' => 'Page number, from 1. Default 1.' ],
						'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'description' => 'Entries per page, 1 to ' . self::MAX_PER_PAGE . '. Default 20.' ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use WP Mail SMTP tools.' );
		}
		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			throw new \Exception( 'You do not have permission to use WP Mail SMTP tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'WP Mail SMTP is not active' );
		}
		// WP Mail SMTP's own management capability, which a site can change through its filter.
		$plugin_cap = 'manage_options';
		if ( method_exists( wp_mail_smtp(), 'get_capability_manage_options' ) ) {
			$plugin_cap = (string) wp_mail_smtp()->get_capability_manage_options();
		}
		if ( '' === $plugin_cap || ! current_user_can( $plugin_cap ) ) {
			throw new \Exception( 'You do not have permission to use WP Mail SMTP tools.' );
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
			case 'wpmailsmtp_get_status':
				return self::get_status();
			case 'wpmailsmtp_send_test_email':
				return self::send_test_email( $args );
			case 'wpmailsmtp_get_email_log':
				return self::get_email_log( $args );
		}
		throw new \Exception( 'Unknown WP Mail SMTP tool: ' . esc_html( $name ) );
	}

	// ============ Status ============

	private static function get_status() {
		$options    = \WPMailSMTP\Options::init();
		$mailer     = (string) $options->get( 'mail', 'mailer' );
		$setup      = self::mailer_setup( $mailer );
		$last_error = self::last_error();

		$status = [
			'plugin'                  => [
				'name'    => 'WP Mail SMTP',
				'version' => defined( 'WPMS_PLUGIN_VER' ) ? (string) WPMS_PLUGIN_VER : '',
				'edition' => self::is_pro() ? 'paid' : 'free',
			],
			'mailer'                  => [
				'slug'           => $mailer,
				'name'           => $setup['title'],
				'setup_complete' => $setup['complete'],
			],
			'from_email'              => (string) $options->get( 'mail', 'from_email' ),
			'from_email_forced'       => (bool) $options->get( 'mail', 'from_email_force' ),
			'from_name'               => (string) $options->get( 'mail', 'from_name' ),
			'from_name_forced'        => (bool) $options->get( 'mail', 'from_name_force' ),
			'settings_from_constants' => method_exists( $options, 'is_const_enabled' ) ? (bool) $options->is_const_enabled() : false,
			'emails_blocked'          => method_exists( wp_mail_smtp(), 'is_blocked' ) ? (bool) wp_mail_smtp()->is_blocked() : false,
			'last_error'              => $last_error,
		];
		if ( '' !== $setup['note'] ) {
			$status['mailer']['note'] = $setup['note'];
		}
		if ( $status['emails_blocked'] ) {
			$status['emails_blocked_note'] = 'WP Mail SMTP\'s "Do Not Send" setting is on: the site sends no email except WP Mail SMTP test emails.';
		}
		if ( ! $last_error ) {
			$status['last_error_note'] = 'WP Mail SMTP has no sending error on record.';
		}
		return $status;
	}

	private static function is_pro() {
		return method_exists( wp_mail_smtp(), 'is_pro' ) && (bool) wp_mail_smtp()->is_pro();
	}

	/**
	 * The mailer's display name and whether its settings are complete, using
	 * the mailer's own check. Null when the mailer has no sending class in the
	 * installed edition.
	 */
	private static function mailer_setup( $mailer ) {
		$out = [ 'title' => $mailer, 'complete' => null, 'note' => '' ];
		if ( '' === $mailer ) {
			$out['complete'] = false;
			$out['note']     = 'No mailer is chosen.';
			return $out;
		}
		$providers = wp_mail_smtp()->get_providers();
		try {
			$opts = $providers->get_options( $mailer );
			if ( $opts && method_exists( $opts, 'get_title' ) ) {
				$out['title'] = wp_strip_all_tags( (string) $opts->get_title() );
			}
		} catch ( \Throwable $e ) {
			// The slug stays as the name.
			unset( $e );
		}

		// Only ask for the mailer when its class exists, so a missing one does not add a debug event.
		$path = method_exists( $providers, 'get_provider_path' ) ? (string) $providers->get_provider_path( $mailer ) : '';
		if ( '' === $path || ! class_exists( $path . 'Mailer' ) ) {
			$out['note'] = 'This mailer is not available in the installed edition of WP Mail SMTP.';
			return $out;
		}

		// The completeness check reads saved settings only; the Gmail client is not built, so no token refresh request is made.
		add_filter( 'wp_mail_smtp_providers_gmail_auth_is_client_needed', '__return_false', PHP_INT_MAX );
		try {
			$connection = wp_mail_smtp()->get_connections_manager()->get_primary_connection();
			$object     = $connection->get_mailer();
			if ( $object && method_exists( $object, 'is_mailer_complete' ) ) {
				$out['complete'] = (bool) $object->is_mailer_complete();
				if ( ! $out['complete'] ) {
					$out['note'] = 'The mailer\'s settings are incomplete; finish them in WP Mail SMTP\'s settings.';
				}
			}
		} catch ( \Throwable $e ) {
			$out['note'] = 'WP Mail SMTP could not check this mailer\'s settings.';
		} finally {
			remove_filter( 'wp_mail_smtp_providers_gmail_auth_is_client_needed', '__return_false', PHP_INT_MAX );
		}
		return $out;
	}

	/**
	 * The most recent failure WP Mail SMTP recorded for any connection, with
	 * only the fields that carry no settings or conversation data.
	 */
	private static function last_error() {
		if ( ! class_exists( '\WPMailSMTP\EmailSendingDebug' ) ) {
			return null;
		}
		$records = \WPMailSMTP\EmailSendingDebug::get();
		if ( ! is_array( $records ) ) {
			return null;
		}
		$latest = null;
		$latest_id = '';
		foreach ( $records as $id => $record ) {
			if ( ! is_array( $record ) || empty( $record['error_message'] ) ) {
				continue;
			}
			if ( null === $latest || (int) ( $record['occurred_at'] ?? 0 ) > (int) ( $latest['occurred_at'] ?? 0 ) ) {
				$latest    = $record;
				$latest_id = (string) $id;
			}
		}
		if ( null === $latest ) {
			return null;
		}
		$when = (int) ( $latest['occurred_at'] ?? 0 );
		return [
			'connection'  => sanitize_key( $latest_id ),
			'occurred_at' => $when > 0 ? gmdate( 'c', $when ) : null,
			'context'     => in_array( $latest['context'] ?? '', [ 'test', 'regular' ], true ) ? $latest['context'] : 'unknown',
			'mailer'      => sanitize_key( (string) ( $latest['mailer'] ?? '' ) ),
			'error_code'  => self::clean_text( (string) ( $latest['error_code'] ?? '' ), 100 ),
			'message'     => self::clean_text( (string) $latest['error_message'], self::MAX_ERROR_CHARS ),
		];
	}

	/** Plain text with email addresses hidden, cut to a length. */
	private static function clean_text( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
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

		$mailer = (string) \WPMailSMTP\Options::init()->get( 'mail', 'mailer' );
		$setup  = self::mailer_setup( $mailer );
		if ( true !== $setup['complete'] ) {
			throw new Tool_Refusal( 'mailer_not_set_up', 'WP Mail SMTP\'s mailer is not set up, so no test email was sent. ' . ( '' !== $setup['note'] ? $setup['note'] : 'Check the mailer settings in WP Mail SMTP.' ) );
		}

		$left = self::take_test_quota();

		$failure = null;
		$catch   = static function ( $error ) use ( &$failure ) {
			$failure = $error;
		};
		add_action( 'wp_mail_failed', $catch );
		try {
			if ( class_exists( '\WPMailSMTP\TestEmail\TestEmail' ) ) {
				$test = ( new \WPMailSMTP\TestEmail\TestEmail() )->as_html( ! $plain )->with_domain_check( false );
				$test->send( $to );
				$sent = $test->is_successful();
			} else {
				// Without WP Mail SMTP's test-email class: its test header marks the email as a test, which "Do Not Send" lets through.
				$sent = (bool) wp_mail(
					$to,
					sprintf( 'WP Mail SMTP: Test email to %s', $to ),
					"This is a test email sent through WP Mail SMTP.\n",
					[ 'X-Mailer-Type:WPMailSMTP/Admin/Test' ]
				);
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
				$error = 'The email was not sent and WP Mail SMTP gave no further detail.';
			}
			$error = self::clean_text( $error, self::MAX_ERROR_CHARS );
		}

		return [
			'sent'                 => (bool) $sent,
			'to'                   => $to,
			'mailer'               => $mailer,
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
			throw new Tool_Refusal(
				'busy',
				'Another WP Mail SMTP test send is being counted right now, so no test email was sent. Try again in a few seconds.',
				[ 'retry_after_seconds' => 5 ]
			);
		}
		try {
			wp_cache_delete( self::TEST_SENDS_OPTION, 'options' );
			$now   = time();
			$times = array_values( array_filter( array_map( 'intval', (array) get_option( self::TEST_SENDS_OPTION, [] ) ), static function ( $t ) use ( $now ) {
				return $t > $now - HOUR_IN_SECONDS && $t <= $now + 60;
			} ) );
			sort( $times );
			if ( count( $times ) >= self::TEST_SENDS_PER_HOUR ) {
				$retry = max( 60, $times[ count( $times ) - self::TEST_SENDS_PER_HOUR ] + HOUR_IN_SECONDS - $now );
				throw new Tool_Refusal(
					'rate_limited',
					sprintf( 'Royal MCP sends up to %d WP Mail SMTP test emails per site per hour, and %d were sent in the last hour. Try again in about %d minutes.', self::TEST_SENDS_PER_HOUR, count( $times ), (int) ceil( $retry / 60 ) ),
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

	/**
	 * Takes a lock row in the options table. The insert only succeeds when no row exists, and
	 * a row older than $stale seconds is taken over only while it still holds the value read,
	 * so two requests never both get the lock. Returns the value to release it with, or 0.
	 */
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

	// ============ Email log ============

	private static function get_email_log( array $args ) {
		$page     = self::int_arg( $args, 'page', 1, 1, self::MAX_PAGE );
		$per_page = self::int_arg( $args, 'per_page', 20, 1, self::MAX_PER_PAGE );
		$paid     = self::is_pro();
		return [
			'available' => false,
			'reason'    => $paid
				? 'This tool does not read WP Mail SMTP\'s email log. Use the Email Log screen in WP Mail SMTP.'
				: 'The free version of WP Mail SMTP has no email log and keeps no record of sent emails.',
			'edition'   => $paid ? 'paid' : 'free',
			'hint'      => 'wpmailsmtp_get_status returns the last sending error WP Mail SMTP recorded.',
			'entries'   => [],
			'total'     => 0,
			'page'      => $page,
			'per_page'  => $per_page,
			'has_more'  => false,
		];
	}

	/** A whole-number argument within a range; anything else is refused, not adjusted. */
	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) ) {
			throw new \Exception( sprintf( '%s must be a whole number from %d to %d.', esc_html( $key ), (int) $min, (int) $max ) );
		}
		if ( $v < $min || $v > $max ) {
			throw new \Exception( sprintf( '%s must be from %d to %d.', esc_html( $key ), (int) $min, (int) $max ) );
		}
		return $v;
	}
}
