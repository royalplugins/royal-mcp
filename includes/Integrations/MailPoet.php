<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailPoet: lists, subscribers, standard newsletters, test sends, scheduling
 * and sent-newsletter statistics.
 *
 * Lists and subscribers go through MailPoet's public PHP API. Newsletters,
 * test sends, scheduling and statistics use MailPoet's own services, each
 * checked (class, methods and a tested MailPoet version range) before use,
 * so an unknown MailPoet version gets a clear refusal instead of an error.
 *
 * Administrators with MailPoet's own permission only. Subscriber email
 * addresses are returned to them, but never written to Royal MCP's change
 * history or activity log. Royal MCP never marks a subscriber as confirmed
 * and never re-subscribes someone who unsubscribed.
 */
class MailPoet {

	const MAX_TEST_ADDRESSES = 5;
	const MAX_LIST_IDS       = 50;
	const MAX_BODY_BYTES     = 200000;
	const MAX_DAYS_AHEAD     = 30;
	const MIN_SECONDS_AHEAD  = 60;

	/** Test emails Royal MCP sends per site per hour. */
	const TEST_SENDS_PER_HOUR = 20;

	/** MailPoet confirmation emails Royal MCP triggers per address per 24 hours. */
	const CONFIRMATIONS_PER_DAY = 3;

	/** Options holding the scheduled-send records and the two send counters. */
	const GUARD_OPTION         = 'royal_mcp_mailpoet_send_guard';
	const TEST_SENDS_OPTION    = 'royal_mcp_mailpoet_test_sends';
	const CONFIRMATIONS_OPTION = 'royal_mcp_mailpoet_confirmations';

	/** Statuses that mean the person asked not to get email, or MailPoet stopped sending to them. */
	const NO_MAIL_STATUSES = [ 'unsubscribed', 'bounced', 'inactive' ];

	/** Subscriber statuses MailPoet filters by. */
	const STATUSES = [ 'subscribed', 'unconfirmed', 'unsubscribed', 'inactive', 'bounced' ];

	/** Field groups a subscriber read can return. */
	const SUBSCRIBER_FIELDS = [ 'email', 'name', 'status', 'lists', 'custom_fields', 'tags', 'dates' ];

	/** Field groups returned when none are asked for. */
	const DEFAULT_SUBSCRIBER_FIELDS = [ 'email', 'name', 'status', 'lists' ];

	/** Newsletter types the listing accepts. */
	const NEWSLETTER_TYPES = [ 'standard', 'welcome', 'notification', 'notification_history', 'automatic', 'automation', 're_engagement' ];

	/** Newsletter statuses the listing accepts. */
	const NEWSLETTER_STATUSES = [ 'draft', 'scheduled', 'sending', 'sent', 'active' ];

	/** Tools that read or change subscribers and lists. */
	const SUBSCRIBER_TOOLS = [ 'mailpoet_list_lists', 'mailpoet_list_subscribers', 'mailpoet_get_subscriber', 'mailpoet_save_subscriber', 'mailpoet_set_subscriber_lists' ];

	/** Tools whose changes can be undone. */
	const UNDO_OPS = [ 'mailpoet_save_subscriber', 'mailpoet_set_subscriber_lists', 'mailpoet_save_draft' ];

	/** How far the real recipient count of a scheduled send may exceed the confirmed count before it is paused. */
	const TOLERANCE_PERCENT = 10;
	const TOLERANCE_MIN     = 5;

	/** MailPoet versions the newsletter services were tested against: [ lowest, first unsupported ]. */
	private static $tested_range = [ '5.44.0', '6.0.0' ];

	/** MailPoet services used by the newsletter tools, with the methods each must have. */
	private static $services = [
		'save'                => [ 'MailPoet\Newsletter\NewsletterSaveController', [ 'save' ] ],
		'newsletters'         => [ 'MailPoet\Newsletter\NewslettersRepository', [ 'findOneById', 'getTableName', 'refresh' ] ],
		'queue'               => [ 'MailPoet\API\JSON\v1\SendingQueue', [ 'add' ] ],
		'preview'             => [ 'MailPoet\Newsletter\Preview\SendPreviewController', [ 'sendPreview' ] ],
		'stats'               => [ 'MailPoet\Newsletter\Statistics\NewsletterStatisticsRepository', [ 'getStatistics' ] ],
		'segment_subscribers' => [ 'MailPoet\Segments\SegmentSubscribersRepository', [ 'getSubscribersCountBySegmentIds' ] ],
		'segments'            => [ 'MailPoet\Segments\SegmentsRepository', [ 'findOneById' ] ],
		'mailer'              => [ 'MailPoet\Mailer\MailerFactory', [ 'getDefaultMailer' ] ],
		'delete'              => [ 'MailPoet\Newsletter\NewsletterDeleteController', [ 'bulkDelete' ] ],
		'subscribers'         => [ 'MailPoet\Subscribers\SubscribersRepository', [ 'findOneById', 'bulkDelete' ] ],
		'settings'            => [ 'MailPoet\Settings\SettingsController', [ 'get' ] ],
		'em'                  => [ 'MailPoetVendor\Doctrine\ORM\EntityManager', [ 'clear', 'getClassMetadata', 'flush' ] ],
	];

	/** Public API methods the subscriber tools use. */
	const API_METHODS = [ 'getLists', 'getSubscriber', 'getSubscribers', 'getSubscribersCount', 'addSubscriber', 'updateSubscriber', 'subscribeToLists', 'unsubscribeFromLists', 'getSubscriberFields' ];

	public static function is_available() {
		return defined( 'MAILPOET_VERSION' ) && class_exists( '\MailPoet\API\API' ) && class_exists( '\MailPoet\DI\ContainerWrapper' );
	}

	public static function version() {
		return defined( 'MAILPOET_VERSION' ) ? (string) MAILPOET_VERSION : '';
	}

	/** Whether this MailPoet version is in the range the newsletter services were tested on. */
	public static function version_supported() {
		$v = self::version();
		return '' !== $v && version_compare( $v, self::$tested_range[0], '>=' ) && version_compare( $v, self::$tested_range[1], '<' );
	}

	/** MailPoet's own permission for a tool. */
	public static function mailpoet_cap( $name ) {
		if ( 'mailpoet_list_lists' === $name ) {
			return 'mailpoet_manage_segments';
		}
		return in_array( (string) $name, self::SUBSCRIBER_TOOLS, true ) ? 'mailpoet_manage_subscribers' : 'mailpoet_manage_emails';
	}

	/** Whether the current user may use the tool: an administrator who also holds MailPoet's permission. */
	private static function check_access( $name ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new Tool_Refusal( 'insufficient_caps', 'You do not have permission to use this MailPoet tool: it needs an administrator who can manage MailPoet.' );
		}
		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			throw new Tool_Refusal( 'insufficient_caps', 'You are not a member of this site.' );
		}
		// MailPoet's own permissions exist only while MailPoet is active.
		if ( ! self::is_available() ) {
			throw new \Exception( 'MailPoet is not active' );
		}
		if ( ! current_user_can( self::mailpoet_cap( $name ) ) ) {
			throw new Tool_Refusal( 'insufficient_caps', 'You do not have permission to use this MailPoet tool: it needs an administrator who can manage MailPoet.' );
		}
	}

	public static function get_tools() {
		$window  = Undo_Store::window_label();
		$fields  = [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => self::SUBSCRIBER_FIELDS ], 'description' => 'Field groups to return: email, name (first_name, last_name), status, lists, custom_fields, tags, dates (created, updated, confirmed, last subscribed, last engagement). Default: email, name, status, lists. IP addresses and subscription tokens are never returned.' ];
		$admins  = ' Administrators who can manage MailPoet only.';
		return [
			[
				'name'        => 'mailpoet_list_lists',
				'description' => 'List MailPoet lists with how many subscribers each has: subscribed, unconfirmed, unsubscribed, inactive and bounced. Returns id, name, description, type and counts. Read-only.' . $admins,
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'mailpoet_list_subscribers',
				'description' => 'Page through MailPoet subscribers, optionally of one list and one status, oldest first. Up to 100 per call. Returns total, page, per_page and subscribers with the field groups asked for. Read-only.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'list_id'  => [ 'type' => 'integer', 'description' => 'Only subscribers of this list (see mailpoet_list_lists).' ],
						'status'   => [ 'type' => 'string', 'enum' => self::STATUSES, 'description' => 'Only subscribers with this status. Default: every status.' ],
						'per_page' => [ 'type' => 'integer', 'description' => '1-100, default 20.' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, default 1.' ],
						'fields'   => $fields,
					],
				],
			],
			[
				'name'        => 'mailpoet_get_subscriber',
				'description' => 'One MailPoet subscriber by subscriber_id or email: status, lists (with the status on each list), custom fields, tags and dates, as asked for with fields. Read-only.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'subscriber_id' => [ 'type' => 'integer', 'description' => 'MailPoet subscriber ID. Use this or email.' ],
						'email'         => [ 'type' => 'string', 'description' => 'Subscriber email address. Use this or subscriber_id.' ],
						'fields'        => $fields,
					],
				],
			],
			[
				'name'        => 'mailpoet_save_subscriber',
				'description' => 'Add a MailPoet subscriber, or update one (found by subscriber_id, or by email when it already exists): email, first_name, last_name, custom_fields, and with list_ids the exact set of ordinary lists they belong to. MailPoet\'s own rules apply: when the site requires sign-up confirmation (double opt-in), new list members get MailPoet\'s confirmation email and stay unconfirmed until they click it. Royal MCP never marks anyone as confirmed: skip_confirmation is accepted only when MailPoet does not require confirmation. Someone who unsubscribed (from MailPoet or from that list), bounced or went inactive is never put back on a list, and a subscriber in MailPoet\'s trash is not changed. Royal MCP triggers at most ' . self::CONFIRMATIONS_PER_DAY . ' confirmation emails per address per 24 hours. Undo never sets anyone back to subscribed who has since unsubscribed, bounced or gone inactive. Returns the subscriber and an undo token (valid ' . $window . ') that restores the earlier details and lists, or deletes a subscriber this call created; undo refuses with edited_since_write if the subscriber changed again since, force: true restores anyway. Emails already sent (confirmation, welcome) cannot be undone.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'subscriber_id'     => [ 'type' => 'integer', 'description' => 'Update this subscriber. Leave out to add one or to find one by email.' ],
						'email'             => [ 'type' => 'string', 'description' => 'Email address. Required to add a subscriber; with subscriber_id it changes the address.' ],
						'first_name'        => [ 'type' => 'string' ],
						'last_name'         => [ 'type' => 'string' ],
						'custom_fields'     => [ 'type' => 'object', 'description' => 'Custom field values keyed by field id (cf_1) or field name, up to 50. Text values up to 1000 characters.' ],
						'list_ids'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'The ordinary lists the subscriber should be on, up to ' . self::MAX_LIST_IDS . '. Lists left out are unsubscribed. Leave out to keep the lists as they are.' ],
						'skip_confirmation' => [ 'type' => 'boolean', 'description' => 'Add without a confirmation email. Refused when MailPoet requires sign-up confirmation. Default false.' ],
					],
				],
			],
			[
				'name'        => 'mailpoet_set_subscriber_lists',
				'description' => 'Subscribe one MailPoet subscriber to lists and/or unsubscribe them from lists (ordinary lists only, up to ' . self::MAX_LIST_IDS . ' each). New list memberships follow MailPoet\'s sign-up confirmation rule; someone who unsubscribed (from MailPoet or from that list), bounced or went inactive is never put back on a list, and a subscriber in MailPoet\'s trash is not changed. At most ' . self::CONFIRMATIONS_PER_DAY . ' confirmation emails per address per 24 hours. Returns the lists and an undo token (valid ' . $window . ') that puts the earlier list memberships back; undo refuses with edited_since_write if they changed again since, force: true restores anyway.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'subscriber_id' => [ 'type' => 'integer', 'description' => 'Subscriber ID. Use this or email.' ],
						'email'         => [ 'type' => 'string', 'description' => 'Subscriber email address. Use this or subscriber_id.' ],
						'subscribe'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'List IDs to subscribe to.' ],
						'unsubscribe'   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'List IDs to unsubscribe from.' ],
					],
				],
			],
			[
				'name'        => 'mailpoet_list_newsletters',
				'description' => 'List MailPoet emails, newest change first: id, subject, type, status, editor (classic or block), dates and, for scheduled ones, the scheduled time (UTC). Filter by type (default standard newsletters) and status (draft, scheduled, sending, sent, active). Up to 100 per call. Read-only.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'type'     => [ 'type' => 'string', 'enum' => array_merge( self::NEWSLETTER_TYPES, [ 'any' ] ), 'description' => 'Default standard.' ],
						'status'   => [ 'type' => 'string', 'enum' => self::NEWSLETTER_STATUSES, 'description' => 'Default: every status.' ],
						'search'   => [ 'type' => 'string', 'description' => 'Words in the subject.' ],
						'per_page' => [ 'type' => 'integer', 'description' => '1-100, default 20.' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, default 1.' ],
					],
				],
			],
			[
				'name'        => 'mailpoet_get_newsletter',
				'description' => 'One MailPoet email: subject, preview text, sender, reply-to, target lists, status, type, editor, scheduled time, a short text summary of the body, and recipient_count: how many subscribers its lists would send to right now (pass it as expected_recipients to mailpoet_schedule_newsletter). Read-only.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'newsletter_id' => [ 'type' => 'integer', 'description' => 'MailPoet email ID.' ] ],
					'required'   => [ 'newsletter_id' ],
				],
			],
			[
				'name'        => 'mailpoet_save_draft',
				'description' => 'Create a standard MailPoet newsletter draft, or edit one that is still a draft: subject, preview_text, sender, reply-to, target lists and body. body_html becomes the content of an email built from MailPoet\'s blank one-column template (with its unsubscribe footer); scripts and unsafe markup are removed. Replacing the body of a draft that already has content needs replace_body: true. The body of an email made in MailPoet\'s new block editor cannot be changed here (edit it in MailPoet); its other settings can. Nothing is sent. Returns the draft and an undo token (valid ' . $window . ') that restores the earlier draft, or deletes a draft this call created; undo refuses with edited_since_write if the draft changed again since, force: true restores anyway.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'newsletter_id'    => [ 'type' => 'integer', 'description' => 'Edit this draft. Leave out to create one.' ],
						'subject'          => [ 'type' => 'string', 'description' => 'Subject line, up to 250 characters.' ],
						'preview_text'     => [ 'type' => 'string', 'description' => 'Preview text shown after the subject in inboxes, up to 250 characters.' ],
						'sender_name'      => [ 'type' => 'string' ],
						'sender_address'   => [ 'type' => 'string', 'description' => 'From address. Default: MailPoet\'s default sender.' ],
						'reply_to_name'    => [ 'type' => 'string' ],
						'reply_to_address' => [ 'type' => 'string' ],
						'list_ids'         => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Lists to send to, up to ' . self::MAX_LIST_IDS . '. Replaces the current lists.' ],
						'body_html'        => [ 'type' => 'string', 'description' => 'Email content as HTML (paragraphs, headings, links, lists, images), up to ' . self::MAX_BODY_BYTES . ' bytes. MailPoet shortcodes such as [subscriber:firstname | default:there] work.' ],
						'replace_body'     => [ 'type' => 'boolean', 'description' => 'Needed to replace the body of a draft that already has content. Default false.' ],
					],
				],
			],
			[
				'name'        => 'mailpoet_send_test',
				'description' => 'Send a test of a standard MailPoet newsletter draft, the way MailPoet\'s own "Send preview" does, to up to ' . self::MAX_TEST_ADDRESSES . ' of the site\'s own team: the site admin email (the default) or the email addresses of users of this site who can edit posts. Other addresses are refused. Up to ' . self::TEST_SENDS_PER_HOUR . ' test emails per site per hour. Personalisation in the test (such as the first name) is filled with the requesting administrator\'s own data. A sent email cannot be undone.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'newsletter_id'   => [ 'type' => 'integer', 'description' => 'MailPoet email ID.' ],
						'email_addresses' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Up to ' . self::MAX_TEST_ADDRESSES . ' addresses: the site admin email or users of this site who can edit posts. Default: the site admin email.' ],
					],
					'required'   => [ 'newsletter_id' ],
				],
			],
			[
				'name'        => 'mailpoet_get_newsletter_stats',
				'description' => 'Statistics of a MailPoet email that was sent or is sending: sent count, unique opens (and machine opens), unique clicks, unsubscribes, bounces, rates in percent, the 10 most clicked links, and WooCommerce revenue when MailPoet tracks it. Totals only: no subscriber is named. Read-only.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'newsletter_id' => [ 'type' => 'integer', 'description' => 'MailPoet email ID.' ] ],
					'required'   => [ 'newsletter_id' ],
				],
			],
			[
				'name'        => 'mailpoet_schedule_newsletter',
				'description' => 'Send a standard MailPoet newsletter draft to its lists, now or at send_at. Real email goes to real subscribers, so this is guarded: pass confirm: true, expected_recipients equal to the recipient_count mailpoet_get_newsletter returns and expected_subject equal to its subject (show both to the user first); the call counts the recipients again and refuses on any difference. For a scheduled send MailPoet counts the recipients again when sending starts, and Royal MCP pauses it if there are more than ' . self::TOLERANCE_PERCENT . '% (at least ' . self::TOLERANCE_MIN . ') above the confirmed count; mailpoet_get_newsletter then shows why, and resuming it in MailPoet sends to everyone. This guard protects against mistakes; to keep an untrusted agent from sending at all, switch this tool off in Royal MCP or require approval for it (for example in Royally). Refused when the email is not a draft, has no subject, no lists or no content, when MailPoet\'s sending is paused or not set up, and in read-only mode. send_at is an ISO 8601 time with a timezone (for example 2026-12-01T09:00:00Z), at least a minute and at most ' . self::MAX_DAYS_AHEAD . ' days ahead; leave it out to send now. Returns the status, the scheduled time, the recipient count and how to cancel. There is no undo: a scheduled send can be cancelled with mailpoet_cancel_scheduled before it starts; a send that started can only be paused in MailPoet.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'newsletter_id'       => [ 'type' => 'integer', 'description' => 'MailPoet email ID (a standard newsletter draft).' ],
						'confirm'             => [ 'type' => 'boolean', 'description' => 'MUST be true; refused otherwise.' ],
						'expected_recipients' => [ 'type' => 'integer', 'description' => 'The recipient_count from mailpoet_get_newsletter. Refused unless it equals the live count.' ],
						'expected_subject'    => [ 'type' => 'string', 'description' => 'The subject from mailpoet_get_newsletter. Refused unless it equals the current subject exactly.' ],
						'send_at'             => [ 'type' => 'string', 'description' => 'When to send, ISO 8601 with a timezone. Leave out to send now.' ],
					],
					'required'   => [ 'newsletter_id', 'confirm', 'expected_recipients', 'expected_subject' ],
				],
			],
			[
				'name'        => 'mailpoet_cancel_scheduled',
				'description' => 'Cancel a scheduled MailPoet newsletter before it starts sending: it goes back to being a draft and nothing is sent. Schedule it again with mailpoet_schedule_newsletter.' . $admins,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'newsletter_id' => [ 'type' => 'integer', 'description' => 'MailPoet email ID.' ] ],
					'required'   => [ 'newsletter_id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		$args = is_array( $args ) ? $args : [];
		self::check_access( $name );
		try {
			switch ( $name ) {
				case 'mailpoet_list_lists':
					return self::list_lists();
				case 'mailpoet_list_subscribers':
					return self::list_subscribers( $args );
				case 'mailpoet_get_subscriber':
					return self::get_subscriber( $args );
				case 'mailpoet_save_subscriber':
					return self::save_subscriber( $args );
				case 'mailpoet_set_subscriber_lists':
					return self::set_subscriber_lists( $args );
				case 'mailpoet_list_newsletters':
					return self::list_newsletters( $args );
				case 'mailpoet_get_newsletter':
					return self::get_newsletter( $args );
				case 'mailpoet_save_draft':
					return self::save_draft( $args );
				case 'mailpoet_send_test':
					return self::send_test( $args );
				case 'mailpoet_get_newsletter_stats':
					return self::newsletter_stats( $args );
				case 'mailpoet_schedule_newsletter':
					return self::schedule_newsletter( $args );
				case 'mailpoet_cancel_scheduled':
					return self::cancel_scheduled( $args );
			}
		} catch ( Tool_Refusal $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// MailPoet's own messages can name an address; keep them out of the reply and the activity log.
			throw new \Exception( esc_html( Form_Entry_Privacy::hide_emails( $e->getMessage() ) ) );
		}
		throw new \Exception( 'Unknown MailPoet tool: ' . esc_html( $name ) );
	}

	// ============ MailPoet access ============

	/** MailPoet's public API, or a refusal when it lacks a method these tools use. */
	private static function api() {
		$api = \MailPoet\API\API::MP( 'v1' );
		foreach ( self::API_METHODS as $m ) {
			if ( ! method_exists( $api, $m ) ) {
				throw new Tool_Refusal( 'mailpoet_unsupported', sprintf( 'This is not supported on MailPoet %s: its API has no %s.', esc_html( self::version() ), esc_html( $m ) ) );
			}
		}
		return $api;
	}

	/** One of MailPoet's internal services, checked against the tested version range, its class and methods. */
	private static function service( $key ) {
		$spec = self::$services[ $key ] ?? null;
		$unsupported = static function ( $why ) {
			return new Tool_Refusal( 'mailpoet_unsupported', sprintf( 'This is not supported on MailPoet %s (%s). Royal MCP\'s MailPoet email tools are tested with MailPoet %s up to before %s; update Royal MCP or MailPoet.', esc_html( self::version() ), $why, esc_html( self::$tested_range[0] ), esc_html( self::$tested_range[1] ) ) );
		};
		if ( ! self::version_supported() ) {
			throw $unsupported( 'version outside the tested range' );
		}
		if ( ! is_array( $spec ) || ! class_exists( '\\' . $spec[0] ) ) {
			throw $unsupported( 'a MailPoet component these tools use is missing' );
		}
		try {
			$obj = \MailPoet\DI\ContainerWrapper::getInstance()->get( $spec[0] );
		} catch ( \Throwable $e ) {
			throw $unsupported( 'a MailPoet component could not be loaded' );
		}
		foreach ( $spec[1] as $m ) {
			if ( ! is_object( $obj ) || ! method_exists( $obj, $m ) ) {
				throw $unsupported( 'a MailPoet component has changed' );
			}
		}
		return $obj;
	}

	/** A MailPoet database table's name from its entity, or the default name. */
	private static function table( $entity, $fallback ) {
		$class = 'MailPoet\Entities\\' . $entity;
		try {
			if ( class_exists( '\\' . $class ) ) {
				$name = self::service( 'em' )->getClassMetadata( $class )->getTableName();
				if ( is_string( $name ) && '' !== $name ) {
					return $name;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		global $wpdb;
		return $wpdb->prefix . 'mailpoet_' . $fallback;
	}

	/** Drop MailPoet's cached objects after a direct database change. */
	private static function forget_cached() {
		try {
			self::service( 'em' )->clear();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	private static function setting( $path, $default = null ) {
		try {
			return self::service( 'settings' )->get( $path, $default );
		} catch ( \Throwable $e ) {
			return $default;
		}
	}

	/** Whether MailPoet asks new subscribers to confirm (double opt-in). */
	private static function confirmation_required() {
		return (bool) self::setting( 'signup_confirmation.enabled', true );
	}

	// ============ Argument helpers ============

	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) && ! ( is_string( $v ) && preg_match( '/^-?\d{1,9}$/', $v ) ) ) {
			throw new \Exception( esc_html( $key ) . ' must be a whole number.' );
		}
		return max( $min, min( $max, (int) $v ) );
	}

	private static function required_id( array $args, $key ) {
		$id = Form_Entry_Privacy::positive_int( $args[ $key ] ?? null );
		if ( $id <= 0 ) {
			throw new \Exception( esc_html( $key ) . ' must be a positive whole number.' );
		}
		return $id;
	}

	/** A text argument, trimmed and sanitised, or null when absent. */
	private static function text_arg( array $args, $key, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return null;
		}
		if ( ! is_string( $args[ $key ] ) ) {
			throw new \Exception( esc_html( $key ) . ' must be text.' );
		}
		$v = trim( sanitize_text_field( $args[ $key ] ) );
		if ( mb_strlen( $v ) > $max ) {
			throw new \Exception( sprintf( '%s is limited to %d characters.', esc_html( $key ), (int) $max ) );
		}
		return $v;
	}

	/** An email argument, validated, or null when absent. */
	private static function email_arg( array $args, $key ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return null;
		}
		return self::valid_email( $args[ $key ], $key );
	}

	private static function valid_email( $value, $key ) {
		if ( ! is_string( $value ) ) {
			throw new \Exception( esc_html( $key ) . ' must be an email address.' );
		}
		$e = trim( $value );
		if ( '' === $e || strlen( $e ) > 150 || ! is_email( $e ) || sanitize_email( $e ) !== $e ) {
			throw new \Exception( esc_html( $key ) . ' is not a valid email address.' );
		}
		return $e;
	}

	/** A list of positive list IDs, or null when absent. */
	private static function id_list( array $args, $key ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return null;
		}
		if ( ! is_array( $args[ $key ] ) ) {
			throw new \Exception( esc_html( $key ) . ' must be a list of list IDs.' );
		}
		if ( count( $args[ $key ] ) > self::MAX_LIST_IDS ) {
			throw new \Exception( sprintf( '%s takes up to %d list IDs.', esc_html( $key ), self::MAX_LIST_IDS ) );
		}
		$ids = [];
		foreach ( $args[ $key ] as $v ) {
			$id = Form_Entry_Privacy::positive_int( $v );
			if ( $id <= 0 ) {
				throw new \Exception( esc_html( $key ) . ' must hold positive whole numbers.' );
			}
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}

	/** Every MailPoet list (segment) by ID: name and type. */
	private static function segment_map() {
		global $wpdb;
		$table = self::table( 'SegmentEntity', 'segments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, name, type, deleted_at FROM {$table}", ARRAY_A );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = [ 'name' => (string) $r['name'], 'type' => (string) $r['type'], 'trashed' => ! empty( $r['deleted_at'] ) ];
		}
		return $out;
	}

	/** Refuse list IDs that are unknown, trashed, or (with $ordinary) not ordinary lists. */
	private static function check_lists( array $ids, $ordinary ) {
		$map = self::segment_map();
		foreach ( $ids as $id ) {
			if ( ! isset( $map[ $id ] ) || $map[ $id ]['trashed'] ) {
				throw new Tool_Refusal( 'list_not_found', sprintf( 'List %d does not exist. Use mailpoet_list_lists to see the lists.', (int) $id ) );
			}
			if ( $ordinary && 'default' !== $map[ $id ]['type'] ) {
				throw new Tool_Refusal( 'list_not_editable', sprintf( 'List %d is not an ordinary list: MailPoet keeps WordPress Users, WooCommerce Customers and segment membership itself.', (int) $id ) );
			}
		}
		return $map;
	}

	// ============ Subscriber reads ============

	private static function list_lists() {
		$api   = self::api();
		$lists = [];
		foreach ( (array) $api->getLists() as $l ) {
			$id     = (int) ( $l['id'] ?? 0 );
			$counts = [];
			foreach ( self::STATUSES as $st ) {
				$counts[ $st ] = (int) $api->getSubscribersCount( [ 'listId' => $id, 'status' => $st ] );
			}
			$lists[] = [
				'id'          => $id,
				'name'        => (string) ( $l['name'] ?? '' ),
				'description' => (string) ( $l['description'] ?? '' ),
				'type'        => (string) ( $l['type'] ?? '' ),
				'counts'      => $counts,
				'created_at'  => Form_Entry_Privacy::iso( $l['created_at'] ?? '' ),
			];
		}
		return [ 'lists' => $lists, 'total' => count( $lists ) ];
	}

	private static function fields_arg( array $args ) {
		if ( ! isset( $args['fields'] ) ) {
			return self::DEFAULT_SUBSCRIBER_FIELDS;
		}
		if ( ! is_array( $args['fields'] ) || count( $args['fields'] ) > count( self::SUBSCRIBER_FIELDS ) ) {
			throw new \Exception( 'fields must be a list of: ' . implode( ', ', self::SUBSCRIBER_FIELDS ) . '.' );
		}
		$out = [];
		foreach ( $args['fields'] as $f ) {
			if ( ! is_string( $f ) || ! in_array( $f, self::SUBSCRIBER_FIELDS, true ) ) {
				throw new \Exception( 'fields must be a list of: ' . implode( ', ', self::SUBSCRIBER_FIELDS ) . '.' );
			}
			$out[ $f ] = $f;
		}
		return $out ? array_values( $out ) : self::DEFAULT_SUBSCRIBER_FIELDS;
	}

	/** Custom field definitions by id (cf_N): name and type. */
	private static function custom_field_defs() {
		$out = [];
		foreach ( (array) self::api()->getSubscriberFields() as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( preg_match( '/^cf_\d+$/', $id ) ) {
				$out[ $id ] = [ 'name' => (string) ( $f['name'] ?? '' ), 'type' => (string) ( $f['type'] ?? '' ) ];
			}
		}
		return $out;
	}

	/** A subscriber from MailPoet's API reply, with only the field groups asked for. */
	private static function format_subscriber( array $s, array $fields, $segments = null, $defs = null ) {
		$out = [ 'id' => (int) ( $s['id'] ?? 0 ) ];
		if ( in_array( 'email', $fields, true ) ) {
			$out['email'] = (string) ( $s['email'] ?? '' );
		}
		if ( in_array( 'name', $fields, true ) ) {
			$out['first_name'] = (string) ( $s['first_name'] ?? '' );
			$out['last_name']  = (string) ( $s['last_name'] ?? '' );
		}
		if ( in_array( 'status', $fields, true ) ) {
			$out['status']           = (string) ( $s['status'] ?? '' );
			$out['wordpress_user']   = ! empty( $s['wp_user_id'] );
			$out['woocommerce_user'] = ! empty( $s['is_woocommerce_user'] );
		}
		if ( in_array( 'lists', $fields, true ) ) {
			$segments    = null === $segments ? self::segment_map() : $segments;
			$out['lists'] = [];
			foreach ( (array) ( $s['subscriptions'] ?? [] ) as $sub ) {
				$sid            = (int) ( $sub['segment_id'] ?? 0 );
				$out['lists'][] = [ 'id' => $sid, 'name' => $segments[ $sid ]['name'] ?? null, 'status' => (string) ( $sub['status'] ?? '' ) ];
			}
		}
		if ( in_array( 'custom_fields', $fields, true ) ) {
			$defs                 = null === $defs ? self::custom_field_defs() : $defs;
			$out['custom_fields'] = [];
			foreach ( $defs as $id => $d ) {
				if ( array_key_exists( $id, $s ) ) {
					$out['custom_fields'][] = [ 'id' => $id, 'name' => $d['name'], 'value' => is_scalar( $s[ $id ] ) ? (string) $s[ $id ] : null ];
				}
			}
		}
		if ( in_array( 'tags', $fields, true ) ) {
			$out['tags'] = [];
			foreach ( (array) ( $s['tags'] ?? [] ) as $tg ) {
				$out['tags'][] = (string) ( is_array( $tg ) ? ( $tg['name'] ?? '' ) : $tg );
			}
		}
		if ( in_array( 'dates', $fields, true ) ) {
			$out['created_at']         = Form_Entry_Privacy::iso( $s['created_at'] ?? '' );
			$out['updated_at']         = Form_Entry_Privacy::iso( $s['updated_at'] ?? '' );
			$out['confirmed_at']       = Form_Entry_Privacy::iso( $s['confirmed_at'] ?? '' );
			$out['last_subscribed_at'] = Form_Entry_Privacy::iso( $s['last_subscribed_at'] ?? '' );
			$out['last_engagement_at'] = self::last_engagement( (int) $out['id'] );
		}
		return $out;
	}

	private static function last_engagement( $id ) {
		try {
			$e = self::service( 'subscribers' )->findOneById( $id );
			if ( is_object( $e ) && method_exists( $e, 'getLastEngagementAt' ) ) {
				$d = $e->getLastEngagementAt();
				return $d instanceof \DateTimeInterface ? gmdate( 'c', $d->getTimestamp() ) : null;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return null;
	}

	private static function list_subscribers( array $args ) {
		$fields   = self::fields_arg( $args );
		$per_page = self::int_arg( $args, 'per_page', 20, 1, 100 );
		$page     = self::int_arg( $args, 'page', 1, 1, 100000 );
		$filter   = [];
		if ( isset( $args['list_id'] ) ) {
			$lid = self::required_id( $args, 'list_id' );
			self::check_lists( [ $lid ], false );
			$filter['listId'] = $lid;
		}
		if ( isset( $args['status'] ) ) {
			if ( ! is_string( $args['status'] ) || ! in_array( $args['status'], self::STATUSES, true ) ) {
				throw new \Exception( 'status must be one of: ' . implode( ', ', self::STATUSES ) . '.' );
			}
			$filter['status'] = $args['status'];
		}
		$api   = self::api();
		$total = (int) $api->getSubscribersCount( $filter );
		$rows  = (array) $api->getSubscribers( $filter, $per_page, ( $page - 1 ) * $per_page );
		$segs  = in_array( 'lists', $fields, true ) ? self::segment_map() : [];
		$defs  = in_array( 'custom_fields', $fields, true ) ? self::custom_field_defs() : [];
		$out   = [];
		foreach ( $rows as $s ) {
			$out[] = self::format_subscriber( (array) $s, $fields, $segs, $defs );
		}
		return [
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
			'subscribers' => $out,
		];
	}

	/** A subscriber found by subscriber_id or email (MailPoet's API reply). */
	private static function find_subscriber( array $args ) {
		$has_id    = isset( $args['subscriber_id'] );
		$has_email = isset( $args['email'] );
		if ( $has_id === $has_email ) {
			throw new \Exception( 'Pass either subscriber_id or email.' );
		}
		$key = $has_id ? self::required_id( $args, 'subscriber_id' ) : self::valid_email( $args['email'], 'email' );
		try {
			return (array) self::api()->getSubscriber( $key );
		} catch ( \Throwable $e ) {
			throw new Tool_Refusal( 'subscriber_not_found', 'Subscriber not found.' );
		}
	}

	private static function get_subscriber( array $args ) {
		$fields = self::fields_arg( $args );
		return [ 'subscriber' => self::format_subscriber( self::find_subscriber( $args ), $fields ) ];
	}

	// ============ Subscriber writes ============

	/**
	 * The state undo restores and compares: names, email, global status, the
	 * given custom fields and the status on the given lists (null = not on it).
	 */
	private static function subscriber_state( $id, array $cf_ids, array $list_ids ) {
		global $wpdb;
		$subs = self::table( 'SubscriberEntity', 'subscribers' );
		$ss   = self::table( 'SubscriberSegmentEntity', 'subscriber_segment' );
		$scf  = self::table( 'SubscriberCustomFieldEntity', 'subscriber_custom_field' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT email, first_name, last_name, status FROM {$subs} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$lists = [];
		foreach ( $list_ids as $lid ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$ss} WHERE subscriber_id = %d AND segment_id = %d", (int) $id, (int) $lid ) );
			$lists[ (int) $lid ] = null === $v ? null : (string) $v;
		}
		$cfs = [];
		foreach ( $cf_ids as $cid ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$scf} WHERE subscriber_id = %d AND custom_field_id = %d", (int) $id, (int) substr( $cid, 3 ) ) );
			$cfs[ $cid ] = null === $v ? null : (string) $v;
		}
		// phpcs:enable
		return [
			'email'         => (string) $row['email'],
			'first_name'    => (string) $row['first_name'],
			'last_name'     => (string) $row['last_name'],
			'status'        => (string) $row['status'],
			'lists'         => $lists,
			'custom_fields' => $cfs,
		];
	}

	private static function state_fingerprint( array $state ) {
		return md5( (string) wp_json_encode( $state ) );
	}

	/** Lists a subscriber is on now, by list ID with its status there. */
	private static function current_list_statuses( array $s ) {
		$out = [];
		foreach ( (array) ( $s['subscriptions'] ?? [] ) as $sub ) {
			$out[ (int) ( $sub['segment_id'] ?? 0 ) ] = (string) ( $sub['status'] ?? '' );
		}
		return $out;
	}

	/** Custom field input mapped to MailPoet field ids, sanitised. */
	private static function custom_fields_arg( array $args ) {
		if ( ! isset( $args['custom_fields'] ) ) {
			return [];
		}
		if ( ! is_array( $args['custom_fields'] ) || ( $args['custom_fields'] && array_keys( $args['custom_fields'] ) === range( 0, count( $args['custom_fields'] ) - 1 ) ) ) {
			throw new \Exception( 'custom_fields must be an object of field id or name => value.' );
		}
		if ( count( $args['custom_fields'] ) > 50 ) {
			throw new \Exception( 'custom_fields takes up to 50 fields.' );
		}
		$defs   = self::custom_field_defs();
		$byname = [];
		foreach ( $defs as $id => $d ) {
			$byname[ strtolower( $d['name'] ) ] = $id;
		}
		$out = [];
		foreach ( $args['custom_fields'] as $k => $v ) {
			$k  = (string) $k;
			$id = isset( $defs[ $k ] ) ? $k : ( $byname[ strtolower( trim( $k ) ) ] ?? null );
			if ( null === $id ) {
				throw new Tool_Refusal( 'unknown_custom_field', sprintf( 'Unknown custom field: %s. Use mailpoet_get_subscriber with fields custom_fields to see them.', esc_html( Form_Entry_Privacy::hide_emails( mb_substr( $k, 0, 60 ) ) ) ) );
			}
			if ( ! is_scalar( $v ) || is_bool( $v ) ) {
				throw new \Exception( 'Custom field values must be text or numbers.' );
			}
			$v = sanitize_text_field( (string) $v );
			if ( mb_strlen( $v ) > 1000 ) {
				throw new \Exception( 'Custom field values are limited to 1000 characters.' );
			}
			$out[ $id ] = $v;
		}
		return $out;
	}

	/** Refuse to put someone who unsubscribed, bounced or went inactive back on a list. */
	private static function refuse_resubscribe( $status ) {
		if ( in_array( (string) $status, self::NO_MAIL_STATUSES, true ) ) {
			throw new Tool_Refusal( 'subscriber_unsubscribed', sprintf( 'This subscriber is %s, so Royal MCP will not add them to lists. They can sign up again through a MailPoet form.', esc_html( $status ) ) );
		}
	}

	/** Refuse list additions for someone who opted out globally or from one of those lists. */
	private static function check_can_add( array $s, array $current, array $add ) {
		if ( ! $add ) {
			return;
		}
		self::refuse_resubscribe( (string) ( $s['status'] ?? '' ) );
		foreach ( $add as $lid ) {
			if ( 'unsubscribed' === ( $current[ $lid ] ?? '' ) ) {
				throw new Tool_Refusal( 'list_unsubscribed', sprintf( 'This subscriber unsubscribed from list %d, so Royal MCP will not add them back to it. They can sign up again through a MailPoet form.', (int) $lid ) );
			}
		}
	}

	/** Refuse changes to a subscriber in MailPoet's trash (MailPoet would restore them). */
	private static function refuse_trashed( array $s ) {
		if ( ! empty( $s['deleted_at'] ) ) {
			throw new Tool_Refusal( 'subscriber_trashed', 'This subscriber is in MailPoet\'s trash. Restore or delete them in MailPoet first.' );
		}
	}

	/** Whether adding these lists makes MailPoet send its confirmation email. */
	private static function confirmation_will_send( $status, array $add ) {
		return $add && self::confirmation_required() && 'subscribed' !== (string) $status;
	}

	/** Confirmation emails Royal MCP triggered in the last 24 hours, by address hash. */
	private static function recent_confirmations() {
		$map = get_option( self::CONFIRMATIONS_OPTION, [] );
		$out = [];
		foreach ( is_array( $map ) ? $map : [] as $key => $times ) {
			$times = array_values( array_filter( array_map( 'intval', (array) $times ), static function ( $t ) {
				return $t > time() - DAY_IN_SECONDS;
			} ) );
			if ( $times ) {
				$out[ (string) $key ] = $times;
			}
		}
		return $out;
	}

	private static function confirmation_key( $address ) {
		return wp_hash( 'mailpoet-confirmation|' . strtolower( trim( (string) $address ) ) );
	}

	/** Refuse when this address already got the most confirmation emails Royal MCP may trigger. */
	private static function check_confirmation_quota( $address ) {
		$times = self::recent_confirmations()[ self::confirmation_key( $address ) ] ?? [];
		if ( count( $times ) >= self::CONFIRMATIONS_PER_DAY ) {
			$retry = max( 60, (int) min( $times ) + DAY_IN_SECONDS - time() );
			throw new Tool_Refusal( 'confirmation_limit', sprintf( 'Royal MCP already triggered %d confirmation emails to this address in the last 24 hours. Try again in about %d minutes.', self::CONFIRMATIONS_PER_DAY, (int) ceil( $retry / 60 ) ), [ 'retry_after_seconds' => $retry ] );
		}
	}

	private static function count_confirmation( $address ) {
		$map   = self::recent_confirmations();
		$key   = self::confirmation_key( $address );
		$map[ $key ]   = isset( $map[ $key ] ) ? $map[ $key ] : [];
		$map[ $key ][] = time();
		update_option( self::CONFIRMATIONS_OPTION, $map, false );
	}

	/** Subscribe to and unsubscribe from lists through MailPoet's API; returns warnings. */
	private static function apply_lists( $id, array $add, array $remove ) {
		$api      = self::api();
		$warnings = [];
		if ( $add ) {
			try {
				$api->subscribeToLists( $id, $add, [ 'send_confirmation_email' => true ] );
			} catch ( \Throwable $e ) {
				$warnings[] = self::soft_failure( $e );
			}
		}
		if ( $remove ) {
			$api->unsubscribeFromLists( $id, $remove );
		}
		return $warnings;
	}

	/** A MailPoet error raised after the change was saved (an email that failed to go out) as a warning; anything else is rethrown. */
	private static function soft_failure( \Throwable $e ) {
		$codes = [];
		if ( class_exists( '\MailPoet\API\MP\v1\APIException' ) ) {
			foreach ( [ 'WELCOME_FAILED_TO_SEND', 'CONFIRMATION_FAILED_TO_SEND' ] as $c ) {
				if ( defined( '\MailPoet\API\MP\v1\APIException::' . $c ) ) {
					$codes[] = constant( '\MailPoet\API\MP\v1\APIException::' . $c );
				}
			}
		}
		if ( $codes && in_array( $e->getCode(), $codes, true ) ) {
			return Form_Entry_Privacy::hide_emails( $e->getMessage() );
		}
		throw $e;
	}

	private static function save_subscriber( array $args ) {
		$email      = self::email_arg( $args, 'email' );
		$first      = self::text_arg( $args, 'first_name', 255 );
		$last       = self::text_arg( $args, 'last_name', 255 );
		$cfs        = self::custom_fields_arg( $args );
		$list_ids   = self::id_list( $args, 'list_ids' );
		$skip       = ! empty( $args['skip_confirmation'] ) && true === filter_var( $args['skip_confirmation'], FILTER_VALIDATE_BOOLEAN );
		if ( $skip && self::confirmation_required() ) {
			throw new Tool_Refusal( 'confirmation_required', 'MailPoet requires sign-up confirmation on this site, so Royal MCP will not add anyone without it. Leave skip_confirmation out: MailPoet sends its confirmation email and the subscriber stays unconfirmed until they click it.' );
		}
		if ( null !== $list_ids ) {
			self::check_lists( $list_ids, true );
		}
		$api = self::api();

		$existing = null;
		if ( isset( $args['subscriber_id'] ) ) {
			$existing = self::find_subscriber( [ 'subscriber_id' => $args['subscriber_id'] ] );
		} elseif ( null !== $email ) {
			try {
				$existing = (array) $api->getSubscriber( $email );
			} catch ( \Throwable $e ) {
				$existing = null;
			}
		} else {
			throw new \Exception( 'Pass email to add a subscriber, or subscriber_id to update one.' );
		}

		$data = [];
		foreach ( [ 'email' => $email, 'first_name' => $first, 'last_name' => $last ] as $k => $v ) {
			if ( null !== $v ) {
				$data[ $k ] = $v;
			}
		}
		$data     = array_merge( $data, $cfs );
		$warnings = [];
		$cf_ids   = array_keys( $cfs );

		if ( null === $existing ) {
			$list_ids = null === $list_ids ? [] : $list_ids;
			$confirm  = ! $skip && self::confirmation_will_send( '', $list_ids );
			if ( $confirm ) {
				self::check_confirmation_quota( $email );
			}
			try {
				$created = (array) $api->addSubscriber( $data, $list_ids, [ 'send_confirmation_email' => ! $skip ] );
			} catch ( \Throwable $e ) {
				$warnings[] = self::soft_failure( $e );
				$created    = (array) $api->getSubscriber( $email );
			}
			$id    = (int) ( $created['id'] ?? 0 );
			if ( $confirm ) {
				self::count_confirmation( $email );
			}
			$after = self::subscriber_state( $id, $cf_ids, $list_ids );
			$undo  = Undo_Store::store( [
				'op'           => 'mailpoet_save_subscriber',
				'summary'      => sprintf( 'Delete MailPoet subscriber %d, added by this change', $id ),
				'target'       => [ 'subscriber_id' => $id ],
				'pre_op_state' => [ 'created' => true, 'cf_ids' => $cf_ids, 'list_ids' => $list_ids, 'written_fingerprint' => self::state_fingerprint( (array) $after ) ],
			] );
			$struct = [ 'created' => true, 'subscriber' => self::format_subscriber( (array) $api->getSubscriber( $id ), [ 'email', 'name', 'status', 'lists' ] ) ];
			self::verify_saved( $struct['subscriber'], $data, $warnings );
			$struct['confirmation'] = self::confirmation_note( $struct['subscriber'], $list_ids );
			if ( $warnings ) {
				$struct['warnings'] = $warnings;
			}
			return Envelope::success( sprintf( 'Added MailPoet subscriber %d.', $id ), $struct, $undo );
		}

		self::refuse_trashed( $existing );
		$id      = (int) ( $existing['id'] ?? 0 );
		$current = self::current_list_statuses( $existing );
		$add     = [];
		$remove  = [];
		if ( null !== $list_ids ) {
			$map = self::segment_map();
			foreach ( $list_ids as $lid ) {
				if ( 'subscribed' !== ( $current[ $lid ] ?? '' ) && 'unconfirmed' !== ( $current[ $lid ] ?? '' ) ) {
					$add[] = $lid;
				}
			}
			foreach ( $current as $lid => $st ) {
				if ( 'unsubscribed' !== $st && ! in_array( $lid, $list_ids, true ) && 'default' === ( $map[ $lid ]['type'] ?? '' ) ) {
					$remove[] = $lid;
				}
			}
			self::check_can_add( $existing, $current, $add );
		}
		$confirm_to = isset( $data['email'] ) ? $data['email'] : (string) ( $existing['email'] ?? '' );
		$confirm    = self::confirmation_will_send( (string) ( $existing['status'] ?? '' ), $add );
		if ( $confirm ) {
			self::check_confirmation_quota( $confirm_to );
		}
		$touched = array_values( array_unique( array_merge( $add, $remove ) ) );
		$before  = self::subscriber_state( $id, $cf_ids, $touched );
		if ( $data ) {
			$api->updateSubscriber( $id, $data );
		}
		$warnings = array_merge( $warnings, self::apply_lists( $id, $add, $remove ) );
		if ( $confirm ) {
			self::count_confirmation( $confirm_to );
		}
		$after    = self::subscriber_state( $id, $cf_ids, $touched );
		$struct   = [ 'created' => false, 'subscriber' => self::format_subscriber( (array) $api->getSubscriber( $id ), [ 'email', 'name', 'status', 'lists' ] ) ];
		self::verify_saved( $struct['subscriber'], $data, $warnings );
		if ( $add ) {
			$struct['confirmation'] = self::confirmation_note( $struct['subscriber'], $add );
		}
		if ( $warnings ) {
			$struct['warnings'] = $warnings;
		}
		if ( $before === $after ) {
			return Envelope::success( sprintf( 'MailPoet subscriber %d was already set this way; nothing changed.', $id ), $struct + [ 'changed' => false ] );
		}
		$undo = Undo_Store::store( self::subscriber_snapshot( 'mailpoet_save_subscriber', $id, (array) $before, (array) $after, $cf_ids, $touched ) );
		return Envelope::success( sprintf( 'Updated MailPoet subscriber %d.', $id ), $struct + [ 'changed' => true ], $undo );
	}

	/**
	 * Undo snapshot of a subscriber change. The earlier email address is kept
	 * only when this change replaced it, since undo needs it back.
	 */
	private static function subscriber_snapshot( $op, $id, array $before, array $after, array $cf_ids, array $list_ids ) {
		$prior = $before;
		if ( strtolower( $before['email'] ) === strtolower( $after['email'] ) ) {
			unset( $prior['email'] );
		}
		return [
			'op'           => $op,
			'summary'      => sprintf( 'Restore MailPoet subscriber %d as it was', (int) $id ),
			'target'       => [ 'subscriber_id' => (int) $id ],
			'pre_op_state' => [
				'created'             => false,
				'prior'               => $prior,
				'written'             => [ 'status' => $after['status'], 'lists' => $after['lists'] ],
				'cf_ids'              => $cf_ids,
				'list_ids'            => $list_ids,
				'written_fingerprint' => self::state_fingerprint( $after ),
			],
		];
	}

	/** Note fields MailPoet did not keep (a WordPress user's name and email come from their account). */
	private static function verify_saved( array $saved, array $data, array &$warnings ) {
		$dropped = [];
		if ( isset( $data['email'] ) && strtolower( $data['email'] ) !== strtolower( (string) ( $saved['email'] ?? '' ) ) ) {
			$dropped[] = 'email';
		}
		foreach ( [ 'first_name', 'last_name' ] as $k ) {
			if ( isset( $data[ $k ] ) && $data[ $k ] !== (string) ( $saved[ $k ] ?? '' ) ) {
				$dropped[] = $k;
			}
		}
		if ( $dropped ) {
			$warnings[] = 'MailPoet did not change ' . implode( ', ', $dropped ) . ': for a WordPress user, MailPoet takes these from the user account.';
		}
	}

	private static function confirmation_note( array $saved, array $list_ids ) {
		if ( ! $list_ids ) {
			return 'Not on any list.';
		}
		if ( 'unconfirmed' === ( $saved['status'] ?? '' ) ) {
			return 'MailPoet requires sign-up confirmation: it sent its confirmation email, and the subscriber stays unconfirmed until they click it.';
		}
		return 'Subscribed.';
	}

	private static function set_subscriber_lists( array $args ) {
		$sub   = self::id_list( $args, 'subscribe' );
		$unsub = self::id_list( $args, 'unsubscribe' );
		$sub   = null === $sub ? [] : $sub;
		$unsub = null === $unsub ? [] : $unsub;
		if ( ! $sub && ! $unsub ) {
			throw new \Exception( 'Pass subscribe and/or unsubscribe list IDs.' );
		}
		if ( array_intersect( $sub, $unsub ) ) {
			throw new \Exception( 'A list cannot be in both subscribe and unsubscribe.' );
		}
		self::check_lists( array_merge( $sub, $unsub ), true );
		$s       = self::find_subscriber( $args );
		self::refuse_trashed( $s );
		$id      = (int) ( $s['id'] ?? 0 );
		$current = self::current_list_statuses( $s );
		$add     = array_values( array_filter( $sub, static function ( $l ) use ( $current ) {
			return ! in_array( $current[ $l ] ?? '', [ 'subscribed', 'unconfirmed' ], true );
		} ) );
		$remove  = array_values( array_filter( $unsub, static function ( $l ) use ( $current ) {
			return isset( $current[ $l ] ) && 'unsubscribed' !== $current[ $l ];
		} ) );
		self::check_can_add( $s, $current, $add );
		$confirm = self::confirmation_will_send( (string) ( $s['status'] ?? '' ), $add );
		if ( $confirm ) {
			self::check_confirmation_quota( (string) ( $s['email'] ?? '' ) );
		}
		$touched = array_values( array_unique( array_merge( $add, $remove ) ) );
		$before  = self::subscriber_state( $id, [], $touched );
		$warn    = self::apply_lists( $id, $add, $remove );
		if ( $confirm ) {
			self::count_confirmation( (string) ( $s['email'] ?? '' ) );
		}
		$after   = self::subscriber_state( $id, [], $touched );
		$saved   = self::format_subscriber( (array) self::api()->getSubscriber( $id ), [ 'status', 'lists' ] );
		$struct  = [ 'subscriber' => $saved, 'subscribed_to' => $add, 'unsubscribed_from' => $remove ];
		if ( $add ) {
			$struct['confirmation'] = self::confirmation_note( $saved, $add );
		}
		if ( $warn ) {
			$struct['warnings'] = $warn;
		}
		if ( $before === $after ) {
			return Envelope::success( sprintf( 'Subscriber %d\'s lists were already set this way; nothing changed.', $id ), $struct + [ 'changed' => false ] );
		}
		$undo = Undo_Store::store( self::subscriber_snapshot( 'mailpoet_set_subscriber_lists', $id, (array) $before, (array) $after, [], $touched ) );
		return Envelope::success( sprintf( 'Changed subscriber %d\'s lists: %d subscribed, %d unsubscribed.', $id, count( $add ), count( $remove ) ), $struct + [ 'changed' => true ], $undo );
	}

	// ============ Newsletter reads ============

	/** A newsletter entity, or a refusal. */
	private static function newsletter( $id ) {
		$repo = self::service( 'newsletters' );
		$n    = $repo->findOneById( (int) $id );
		if ( ! is_object( $n ) || ( method_exists( $n, 'getDeletedAt' ) && $n->getDeletedAt() ) ) {
			throw new Tool_Refusal( 'newsletter_not_found', 'MailPoet email not found.' );
		}
		return $n;
	}

	private static function date_iso( $d ) {
		return $d instanceof \DateTimeInterface ? gmdate( 'c', $d->getTimestamp() ) : null;
	}

	private static function editor_of( $n ) {
		return method_exists( $n, 'getWpPostId' ) && $n->getWpPostId() ? 'block' : 'classic';
	}

	private static function scheduled_at( $n ) {
		$v = method_exists( $n, 'getOptionValue' ) ? $n->getOptionValue( 'scheduledAt' ) : null;
		if ( ! is_string( $v ) || '' === $v ) {
			return null;
		}
		$ts = strtotime( $v . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	private static function list_newsletters( array $args ) {
		global $wpdb;
		$per_page = self::int_arg( $args, 'per_page', 20, 1, 100 );
		$page     = self::int_arg( $args, 'page', 1, 1, 100000 );
		$type     = $args['type'] ?? 'standard';
		if ( ! is_string( $type ) || ! in_array( $type, array_merge( self::NEWSLETTER_TYPES, [ 'any' ] ), true ) ) {
			throw new \Exception( 'type must be one of: ' . implode( ', ', self::NEWSLETTER_TYPES ) . ', any.' );
		}
		$table  = self::service( 'newsletters' )->getTableName();
		$where  = [ 'deleted_at IS NULL' ];
		$params = [];
		if ( 'any' !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type;
		}
		if ( isset( $args['status'] ) ) {
			if ( ! is_string( $args['status'] ) || ! in_array( $args['status'], self::NEWSLETTER_STATUSES, true ) ) {
				throw new \Exception( 'status must be one of: ' . implode( ', ', self::NEWSLETTER_STATUSES ) . '.' );
			}
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		$search = self::text_arg( $args, 'search', 200 );
		if ( null !== $search && '' !== $search ) {
			$where[]  = 'subject LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$w}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, subject, type, status, wp_post_id, created_at, updated_at, sent_at FROM {$table} WHERE {$w} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $r ) {
			$row = [
				'id'         => (int) $r['id'],
				'subject'    => (string) $r['subject'],
				'type'       => (string) $r['type'],
				'status'     => (string) $r['status'],
				'editor'     => empty( $r['wp_post_id'] ) ? 'classic' : 'block',
				'created_at' => Form_Entry_Privacy::iso( $r['created_at'] ),
				'updated_at' => Form_Entry_Privacy::iso( $r['updated_at'] ),
				'sent_at'    => Form_Entry_Privacy::iso( $r['sent_at'] ),
			];
			if ( 'scheduled' === $r['status'] ) {
				$row['scheduled_at'] = self::scheduled_at( self::newsletter( (int) $r['id'] ) );
			}
			$out[] = $row;
		}
		return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'total_pages' => (int) ceil( $total / $per_page ), 'newsletters' => $out ];
	}

	/** Text of a classic body's text, header, footer and button blocks. */
	private static function body_text( $blocks, array &$texts, &$count ) {
		foreach ( (array) $blocks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$type = (string) ( $b['type'] ?? '' );
			if ( 'container' !== $type ) {
				$count++;
			}
			if ( in_array( $type, [ 'text', 'header', 'footer', 'button' ], true ) && isset( $b['text'] ) && is_string( $b['text'] ) ) {
				$texts[] = wp_strip_all_tags( str_replace( [ '</p>', '<br />', '<br>' ], ' ', $b['text'] ) );
			}
			if ( isset( $b['blocks'] ) ) {
				self::body_text( $b['blocks'], $texts, $count );
			}
		}
	}

	/** Content of a block-editor email (its WordPress post). */
	private static function block_post_content( $n ) {
		$pid  = (int) $n->getWpPostId();
		$post = $pid > 0 ? get_post( $pid ) : null;
		return $post ? (string) $post->post_content : '';
	}

	private static function body_summary( $n ) {
		if ( 'block' === self::editor_of( $n ) ) {
			$raw  = self::block_post_content( $n );
			$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
			return [ 'text' => mb_substr( $text, 0, 500 ), 'empty' => '' === $text, 'blocks' => count( parse_blocks( $raw ) ), 'has_unsubscribe_link' => false !== strpos( $raw, 'unsubscribe' ) ];
		}
		$body   = $n->getBody();
		$texts  = [];
		$count  = 0;
		self::body_text( is_array( $body ) ? ( $body['content']['blocks'] ?? [] ) : [], $texts, $count );
		$text    = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', $texts ) ) );
		$content = method_exists( $n, 'getContent' ) ? (string) $n->getContent() : '';
		return [ 'text' => mb_substr( $text, 0, 500 ), 'empty' => 0 === $count, 'blocks' => $count, 'has_unsubscribe_link' => false !== strpos( $content, '[link:subscription_unsubscribe' ) ];
	}

	/** How many subscribers the newsletter's lists would send to now. */
	private static function recipient_count( $n ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $n->getSegmentIds() ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$filter = method_exists( $n, 'getFilterSegmentId' ) ? $n->getFilterSegmentId() : null;
		return (int) self::service( 'segment_subscribers' )->getSubscribersCountBySegmentIds( $ids, 'subscribed', is_int( $filter ) ? $filter : null );
	}

	private static function newsletter_summary( $n ) {
		$segments = self::segment_map();
		$lists    = [];
		foreach ( (array) $n->getSegmentIds() as $sid ) {
			$lists[] = [ 'id' => (int) $sid, 'name' => $segments[ (int) $sid ]['name'] ?? null ];
		}
		$out = [
			'id'           => (int) $n->getId(),
			'subject'      => (string) $n->getSubject(),
			'preview_text' => (string) $n->getPreheader(),
			'type'         => (string) $n->getType(),
			'status'       => (string) $n->getStatus(),
			'editor'       => self::editor_of( $n ),
			'sender'       => [ 'name' => (string) $n->getSenderName(), 'address' => (string) $n->getSenderAddress() ],
			'reply_to'     => [ 'name' => (string) $n->getReplyToName(), 'address' => (string) $n->getReplyToAddress() ],
			'lists'        => $lists,
			'scheduled_at' => self::scheduled_at( $n ),
			'sent_at'      => self::date_iso( $n->getSentAt() ),
			'body'         => self::body_summary( $n ),
		];
		$queue = method_exists( $n, 'getLatestQueue' ) ? $n->getLatestQueue() : null;
		if ( is_object( $queue ) && method_exists( $queue, 'getCountTotal' ) ) {
			$out['sending'] = [ 'total' => (int) $queue->getCountTotal(), 'processed' => (int) $queue->getCountProcessed() ];
		}
		return $out;
	}

	private static function get_newsletter( array $args ) {
		$n   = self::newsletter( self::required_id( $args, 'newsletter_id' ) );
		$out = self::newsletter_summary( $n );
		if ( 'standard' === $out['type'] ) {
			$out['recipient_count'] = self::recipient_count( $n );
		}
		$rec = self::guard_record( (int) $n->getId() );
		if ( is_array( $rec ) && in_array( $out['status'], [ 'scheduled', 'sending' ], true ) ) {
			$out['send_guard'] = [
				'confirmed_recipients' => (int) ( $rec['confirmed'] ?? 0 ),
				'pauses_above'         => (int) ( $rec['confirmed'] ?? 0 ) + self::tolerance( (int) ( $rec['confirmed'] ?? 0 ) ),
				'note'                 => 'MailPoet counts the recipients again when sending starts; Royal MCP pauses the send if there are more than pauses_above.',
			];
			if ( ! empty( $rec['held'] ) ) {
				$out['send_guard']['paused'] = $rec['held'];
			}
		}
		return [ 'newsletter' => $out ];
	}

	private static function newsletter_stats( array $args ) {
		global $wpdb;
		$n = self::newsletter( self::required_id( $args, 'newsletter_id' ) );
		if ( ! in_array( (string) $n->getStatus(), [ 'sent', 'sending' ], true ) && 'notification_history' !== $n->getType() ) {
			throw new Tool_Refusal( 'not_sent', 'This email has not been sent, so it has no statistics yet.' );
		}
		$st    = self::service( 'stats' )->getStatistics( $n );
		$get   = static function ( $m ) use ( $st ) {
			return method_exists( $st, $m ) ? (int) $st->$m() : null;
		};
		$sent  = (int) $get( 'getTotalSentCount' );
		$rate  = static function ( $v ) use ( $sent ) {
			return $sent > 0 && null !== $v ? round( $v * 100 / $sent, 1 ) : null;
		};
		$out = [
			'newsletter_id' => (int) $n->getId(),
			'subject'       => (string) $n->getSubject(),
			'status'        => (string) $n->getStatus(),
			'sent_at'       => self::date_iso( $n->getSentAt() ),
			'total_sent'    => $sent,
			'opens'         => $get( 'getOpenCount' ),
			'machine_opens' => $get( 'getMachineOpenCount' ),
			'clicks'        => $get( 'getClickCount' ),
			'unsubscribes'  => $get( 'getUnsubscribeCount' ),
			'bounces'       => $get( 'getBounceCount' ),
		];
		$out['rates'] = [ 'open' => $rate( $out['opens'] ), 'click' => $rate( $out['clicks'] ), 'unsubscribe' => $rate( $out['unsubscribes'] ), 'bounce' => $rate( $out['bounces'] ) ];
		$clicks = self::table( 'StatisticsClickEntity', 'statistics_clicks' );
		$links  = self::table( 'NewsletterLinkEntity', 'newsletter_links' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.url AS url, COUNT(DISTINCT c.subscriber_id) AS clicks FROM {$clicks} c JOIN {$links} l ON l.id = c.link_id WHERE c.newsletter_id = %d GROUP BY l.url ORDER BY clicks DESC, l.url ASC LIMIT 10", (int) $n->getId() ), ARRAY_A );
		$out['top_links'] = [];
		foreach ( (array) $rows as $r ) {
			$out['top_links'][] = [ 'url' => Form_Entry_Privacy::hide_emails( (string) $r['url'] ), 'unique_clicks' => (int) $r['clicks'] ];
		}
		$rev = method_exists( $st, 'getWooCommerceRevenue' ) ? $st->getWooCommerceRevenue() : null;
		if ( is_object( $rev ) && method_exists( $rev, 'asArray' ) ) {
			$r = (array) $rev->asArray();
			$out['woocommerce_revenue'] = array_intersect_key( $r, array_flip( [ 'currency', 'value', 'count', 'formatted' ] ) );
		}
		return $out;
	}

	// ============ Newsletter writes ============

	/** The blank one-column template's body as an array, or null. */
	private static function template_body() {
		$class = '\MailPoet\Config\PopulatorData\Templates\NewsletterBlank1Column';
		if ( ! class_exists( $class ) ) {
			return null;
		}
		try {
			$t    = new $class( defined( 'MAILPOET_URL' ) ? MAILPOET_URL . '/assets' : '' );
			$body = json_decode( (string) ( $t->get()['body'] ?? '' ), true );
			return is_array( $body ) ? $body : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** The first block of a type anywhere in a body. */
	private static function find_block( $blocks, $type ) {
		foreach ( (array) $blocks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			if ( ( $b['type'] ?? '' ) === $type ) {
				return $b;
			}
			$found = isset( $b['blocks'] ) ? self::find_block( $b['blocks'], $type ) : null;
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/** A classic MailPoet body: the template's styles, header and footer around one text block. */
	private static function build_body( $html ) {
		$tpl    = self::template_body();
		$styles = is_array( $tpl['globalStyles'] ?? null ) ? $tpl['globalStyles'] : [
			'text'    => [ 'fontColor' => '#000000', 'fontFamily' => 'Arial', 'fontSize' => '15px' ],
			'link'    => [ 'fontColor' => '#21759B', 'textDecoration' => 'underline' ],
			'wrapper' => [ 'backgroundColor' => '#ffffff' ],
			'body'    => [ 'backgroundColor' => '#eeeeee' ],
		];
		$header = self::find_block( $tpl['content']['blocks'] ?? [], 'header' );
		$footer = self::find_block( $tpl['content']['blocks'] ?? [], 'footer' );
		if ( ! $footer || false === strpos( (string) ( $footer['text'] ?? '' ), '[link:subscription_unsubscribe' ) ) {
			$footer = [ 'type' => 'footer', 'text' => '<p><a href="[link:subscription_unsubscribe_url]">Unsubscribe</a> | <a href="[link:subscription_manage_url]">Manage your subscription</a></p>', 'styles' => [ 'block' => [ 'backgroundColor' => 'transparent' ], 'text' => [ 'fontColor' => '#222222', 'fontFamily' => 'Arial', 'fontSize' => '12px', 'textAlign' => 'center' ], 'link' => [ 'fontColor' => '#6cb7d4', 'textDecoration' => 'none' ] ] ];
		}
		$row = static function ( array $inner ) {
			return [
				'type'        => 'container',
				'orientation' => 'horizontal',
				'styles'      => [ 'block' => [ 'backgroundColor' => 'transparent' ] ],
				'blocks'      => [ [ 'type' => 'container', 'orientation' => 'vertical', 'styles' => [ 'block' => [ 'backgroundColor' => 'transparent' ] ], 'blocks' => $inner ] ],
			];
		};
		$rows = [];
		if ( $header ) {
			$rows[] = $row( [ $header ] );
		}
		$rows[] = $row( [ [ 'type' => 'text', 'text' => $html ] ] );
		$rows[] = $row( [ $footer ] );
		return [
			'content'      => [ 'type' => 'container', 'orientation' => 'vertical', 'styles' => [ 'block' => [ 'backgroundColor' => 'transparent' ] ], 'blocks' => $rows ],
			'globalStyles' => $styles,
		];
	}

	/** The parts of a draft undo restores and compares. */
	private static function draft_state( $n ) {
		$segs = array_values( array_map( 'intval', (array) $n->getSegmentIds() ) );
		sort( $segs );
		$body = $n->getBody();
		return [
			'subject'          => (string) $n->getSubject(),
			'preheader'        => (string) $n->getPreheader(),
			'sender_name'      => (string) $n->getSenderName(),
			'sender_address'   => (string) $n->getSenderAddress(),
			'reply_to_name'    => (string) $n->getReplyToName(),
			'reply_to_address' => (string) $n->getReplyToAddress(),
			'segments'         => $segs,
			'body'             => is_array( $body ) ? (string) wp_json_encode( $body ) : '',
		];
	}

	/** A fresh copy of a newsletter, read again from the database. */
	private static function reload( $id ) {
		self::forget_cached();
		return self::newsletter( $id );
	}

	private static function save_draft( array $args ) {
		$subject  = self::text_arg( $args, 'subject', 250 );
		$preview  = self::text_arg( $args, 'preview_text', 250 );
		$s_name   = self::text_arg( $args, 'sender_name', 150 );
		$s_addr   = self::email_arg( $args, 'sender_address' );
		$r_name   = self::text_arg( $args, 'reply_to_name', 150 );
		$r_addr   = self::email_arg( $args, 'reply_to_address' );
		$list_ids = self::id_list( $args, 'list_ids' );
		$replace  = ! empty( $args['replace_body'] ) && true === filter_var( $args['replace_body'], FILTER_VALIDATE_BOOLEAN );
		$html     = null;
		if ( array_key_exists( 'body_html', $args ) && null !== $args['body_html'] ) {
			if ( ! is_string( $args['body_html'] ) ) {
				throw new \Exception( 'body_html must be text.' );
			}
			if ( strlen( $args['body_html'] ) > self::MAX_BODY_BYTES ) {
				throw new \Exception( sprintf( 'body_html is limited to %d bytes.', self::MAX_BODY_BYTES ) );
			}
			$html = trim( wp_kses_post( $args['body_html'] ) );
			if ( '' === trim( wp_strip_all_tags( $html ) ) && false === stripos( $html, '<img' ) ) {
				throw new \Exception( 'body_html has no content left after unsafe markup is removed.' );
			}
		}
		if ( null !== $list_ids ) {
			if ( ! $list_ids ) {
				throw new \Exception( 'list_ids must name at least one list.' );
			}
			self::check_lists( $list_ids, false );
		}
		$saver = self::service( 'save' );
		$id    = isset( $args['newsletter_id'] ) ? self::required_id( $args, 'newsletter_id' ) : 0;

		$data = [];
		foreach ( [ 'subject' => $subject, 'preheader' => $preview, 'sender_name' => $s_name, 'sender_address' => $s_addr, 'reply_to_name' => $r_name, 'reply_to_address' => $r_addr ] as $k => $v ) {
			if ( null !== $v ) {
				$data[ $k ] = $v;
			}
		}
		if ( null !== $list_ids ) {
			$data['segments'] = array_map( static function ( $l ) {
				return [ 'id' => $l ];
			}, $list_ids );
		}

		$before = null;
		if ( $id ) {
			$n = self::reload( $id );
			if ( 'standard' !== $n->getType() || 'draft' !== $n->getStatus() ) {
				throw new Tool_Refusal( 'not_draft', sprintf( 'Email %d is a %s %s, not a standard newsletter draft. A scheduled newsletter can be edited after mailpoet_cancel_scheduled.', $id, esc_html( (string) $n->getStatus() ), esc_html( (string) $n->getType() ) ) );
			}
			if ( null !== $html ) {
				if ( 'block' === self::editor_of( $n ) ) {
					throw new Tool_Refusal( 'block_editor_body', 'This email was made in MailPoet\'s new block editor; its body can only be changed there. Subject, preview text, sender and lists can still be changed here.' );
				}
				if ( ! self::body_summary( $n )['empty'] && ! $replace ) {
					throw new Tool_Refusal( 'body_exists', 'This draft already has content. Pass replace_body: true to replace it (anything built in MailPoet\'s editor is lost; undo puts it back).' );
				}
			}
			if ( ! $data && null === $html ) {
				throw new \Exception( 'Nothing to change: pass at least one field.' );
			}
			$before     = self::draft_state( $n );
			$data['id'] = $id;
		} else {
			if ( null === $subject || '' === $subject ) {
				throw new \Exception( 'subject is required to create a draft.' );
			}
			$data['type'] = 'standard';
		}
		if ( null !== $html ) {
			$data['body'] = (string) wp_json_encode( self::build_body( $html ) );
		}

		$saved = $saver->save( $data );
		$nid   = (int) $saved->getId();
		$n     = self::reload( $nid );
		$after = self::draft_state( $n );

		$requested = array_intersect_key( $data, array_flip( [ 'subject', 'preheader', 'sender_name', 'sender_address', 'reply_to_name', 'reply_to_address' ] ) );
		$dropped   = [];
		foreach ( $requested as $k => $v ) {
			if ( (string) $v !== $after[ $k ] ) {
				$dropped[] = $k;
			}
		}
		if ( null !== $list_ids ) {
			$want = $list_ids;
			sort( $want );
			if ( $want !== $after['segments'] ) {
				$dropped[] = 'list_ids';
			}
		}
		if ( null !== $html && '' === $after['body'] ) {
			$dropped[] = 'body_html';
		}
		$struct = [ 'created' => ! $id, 'newsletter' => self::newsletter_summary( $n ) ];
		$struct['newsletter']['recipient_count'] = self::recipient_count( $n );
		if ( $dropped ) {
			$struct['not_saved'] = $dropped;
		}
		if ( $id && $before === $after ) {
			return Envelope::success( sprintf( 'Draft %d was already set this way; nothing changed.', $nid ), $struct + [ 'changed' => false ] );
		}
		$undo = Undo_Store::store( [
			'op'           => 'mailpoet_save_draft',
			'summary'      => $id ? sprintf( 'Restore MailPoet draft %d as it was', $nid ) : sprintf( 'Delete MailPoet draft %d, created by this change', $nid ),
			'target'       => [ 'newsletter_id' => $nid ],
			'pre_op_state' => [ 'created' => ! $id, 'prior' => $before, 'written_fingerprint' => self::state_fingerprint( $after ) ],
		] );
		$msg = $id ? sprintf( 'Updated MailPoet draft %d.', $nid ) : sprintf( 'Created MailPoet draft %d.', $nid );
		if ( $dropped ) {
			$msg .= ' Not saved: ' . implode( ', ', $dropped ) . '.';
		}
		return Envelope::success( $msg . ' Nothing was sent.', $struct + [ 'changed' => true ], $undo );
	}

	/** Whether an address belongs to the site's own team: the admin email, or a user of this site who can edit posts. */
	private static function team_address( $address ) {
		if ( strtolower( $address ) === strtolower( (string) get_option( 'admin_email' ) ) ) {
			return true;
		}
		$user = get_user_by( 'email', $address );
		if ( ! $user ) {
			return false;
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			return false;
		}
		return user_can( $user, 'edit_posts' );
	}

	/** Reserve test-send slots for this hour, or refuse with when to retry. */
	private static function take_test_quota( $count ) {
		$times = array_values( array_filter( array_map( 'intval', (array) get_option( self::TEST_SENDS_OPTION, [] ) ), static function ( $t ) {
			return $t > time() - HOUR_IN_SECONDS;
		} ) );
		sort( $times );
		$over = count( $times ) + $count - self::TEST_SENDS_PER_HOUR;
		if ( $over > 0 ) {
			$retry = max( 60, $times[ min( $over, count( $times ) ) - 1 ] + HOUR_IN_SECONDS - time() );
			throw new Tool_Refusal( 'rate_limited', sprintf( 'Royal MCP sends up to %d test emails per site per hour, and %d were sent in the last hour. Try again in about %d minutes.', self::TEST_SENDS_PER_HOUR, count( $times ), (int) ceil( $retry / 60 ) ), [ 'retry_after_seconds' => $retry ] );
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$times[] = time();
		}
		update_option( self::TEST_SENDS_OPTION, $times, false );
	}

	private static function send_test( array $args ) {
		$n = self::newsletter( self::required_id( $args, 'newsletter_id' ) );
		if ( 'standard' !== $n->getType() || 'draft' !== $n->getStatus() ) {
			throw new Tool_Refusal( 'not_draft', 'Test sends are for standard newsletter drafts only.' );
		}
		if ( isset( $args['email_addresses'] ) ) {
			if ( ! is_array( $args['email_addresses'] ) || ! $args['email_addresses'] || count( $args['email_addresses'] ) > self::MAX_TEST_ADDRESSES ) {
				throw new \Exception( sprintf( 'email_addresses must be a list of 1 to %d addresses.', self::MAX_TEST_ADDRESSES ) );
			}
			$to = [];
			foreach ( $args['email_addresses'] as $e ) {
				$addr = self::valid_email( $e, 'email_addresses' );
				if ( ! self::team_address( $addr ) ) {
					throw new Tool_Refusal( 'recipient_not_allowed', 'Test sends go only to the site\'s own team: the site admin email or users of this site who can edit posts. One of the addresses is neither.' );
				}
				$to[ strtolower( $addr ) ] = $addr;
			}
			$to = array_values( $to );
		} else {
			$to = [ (string) get_option( 'admin_email' ) ];
		}
		if ( 'block' !== self::editor_of( $n ) && self::body_summary( $n )['empty'] ) {
			throw new Tool_Refusal( 'empty_body', 'This email has no content yet: add a body with mailpoet_save_draft first.' );
		}
		$preview = self::service( 'preview' );
		self::take_test_quota( count( $to ) );
		$sent   = [];
		$failed = [];
		foreach ( $to as $addr ) {
			try {
				$preview->sendPreview( $n, $addr );
				$sent[] = $addr;
			} catch ( \Throwable $e ) {
				$failed[] = [ 'email' => $addr, 'error' => Form_Entry_Privacy::hide_emails( $e->getMessage() ) ];
			}
		}
		if ( ! $sent ) {
			throw new Tool_Refusal( 'send_failed', 'MailPoet could not send the test: ' . ( $failed[0]['error'] ?? 'unknown error' ) );
		}
		$struct = [
			'newsletter_id'   => (int) $n->getId(),
			'sent_to'         => $sent,
			'personalisation' => 'Filled with your own data as the requesting administrator, not a subscriber\'s.',
			'undo'            => 'A sent email cannot be undone.',
		];
		if ( $failed ) {
			$struct['failed'] = $failed;
		}
		return Envelope::success( sprintf( 'Sent a test of email %d to %d address(es). Personalisation is filled with your own data.', (int) $n->getId(), count( $sent ) ), $struct );
	}

	// ============ Scheduled-send guard ============

	/** Recipients MailPoet may add beyond the confirmed count before a send is paused. */
	private static function tolerance( $confirmed ) {
		return max( self::TOLERANCE_MIN, (int) ceil( $confirmed * self::TOLERANCE_PERCENT / 100 ) );
	}

	/** Every scheduled-send record, by newsletter ID; records older than the scheduling window are dropped. */
	private static function guard_records() {
		$all = get_option( self::GUARD_OPTION, [] );
		$out = [];
		foreach ( is_array( $all ) ? $all : [] as $id => $rec ) {
			if ( is_array( $rec ) && (int) ( $rec['at'] ?? 0 ) > time() - ( self::MAX_DAYS_AHEAD + 30 ) * DAY_IN_SECONDS ) {
				$out[ (int) $id ] = $rec;
			}
		}
		return $out;
	}

	private static function guard_record( $id ) {
		return self::guard_records()[ (int) $id ] ?? null;
	}

	private static function save_guard_record( $id, $rec ) {
		$all = self::guard_records();
		if ( null === $rec ) {
			unset( $all[ (int) $id ] );
		} else {
			$all[ (int) $id ] = $rec;
		}
		update_option( self::GUARD_OPTION, $all, false );
	}

	/** Subscribers queued for a MailPoet sending task. */
	private static function task_recipients( $task ) {
		global $wpdb;
		$tid = is_object( $task ) && method_exists( $task, 'getId' ) ? (int) $task->getId() : 0;
		if ( $tid > 0 ) {
			$table = self::table( 'ScheduledTaskSubscriberEntity', 'scheduled_task_subscribers' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_id = %d", $tid ) );
		}
		return 0;
	}

	/**
	 * Runs as MailPoet starts sending a batch. A newsletter scheduled through
	 * Royal MCP is paused when MailPoet's own recipient count is clearly above
	 * the count the user confirmed; the reason is kept for mailpoet_get_newsletter.
	 * A send an administrator resumes in MailPoet afterwards goes ahead.
	 *
	 * @param mixed $subscribers Subscribers of the batch (returned unchanged).
	 * @param mixed $newsletter  MailPoet newsletter.
	 * @param mixed $task        MailPoet sending task.
	 * @return mixed
	 */
	public static function guard_scheduled_send( $subscribers, $newsletter = null, $task = null ) {
		try {
			if ( ! is_object( $newsletter ) || ! method_exists( $newsletter, 'getId' ) || ! is_object( $task ) || ! method_exists( $task, 'setStatus' ) ) {
				return $subscribers;
			}
			$id  = (int) $newsletter->getId();
			$rec = self::guard_record( $id );
			if ( ! is_array( $rec ) || ! empty( $rec['held'] ) ) {
				return $subscribers;
			}
			$confirmed = (int) ( $rec['confirmed'] ?? 0 );
			$real      = self::task_recipients( $task );
			if ( $real <= $confirmed + self::tolerance( $confirmed ) ) {
				return $subscribers;
			}
			// MailPoet checks the task status right after this filter, so the batch is not sent.
			$task->setStatus( 'paused' );
			try {
				self::service( 'em' )->flush();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$rec['held'] = [
				'recipients' => $real,
				'at'         => gmdate( 'c' ),
				'reason'     => sprintf( 'Paused by Royal MCP: MailPoet counted %d recipients when sending started, more than the %d confirmed (allowed up to %d). Resume it in MailPoet > Emails to send to all of them, or cancel it there.', $real, $confirmed, $confirmed + self::tolerance( $confirmed ) ),
			];
			self::save_guard_record( $id, $rec );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $subscribers;
	}

	/** A send time from send_at: UTC 'Y-m-d H:i:s', or a refusal. */
	private static function send_time( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})$/i', trim( $value ) ) ) {
			throw new Tool_Refusal( 'send_at_invalid', 'send_at must be an ISO 8601 time with a timezone, for example 2026-12-01T09:00:00Z.' );
		}
		try {
			$d = new \DateTimeImmutable( trim( $value ) );
		} catch ( \Throwable $e ) {
			throw new Tool_Refusal( 'send_at_invalid', 'send_at is not a valid time.' );
		}
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			throw new Tool_Refusal( 'send_at_invalid', 'send_at is not a valid date.' );
		}
		$ts = $d->getTimestamp();
		if ( $ts < time() + self::MIN_SECONDS_AHEAD ) {
			throw new Tool_Refusal( 'send_at_past', 'send_at must be at least a minute from now. Leave it out to send now.' );
		}
		if ( $ts > time() + self::MAX_DAYS_AHEAD * DAY_IN_SECONDS ) {
			throw new Tool_Refusal( 'send_at_too_far', sprintf( 'send_at must be within %d days.', self::MAX_DAYS_AHEAD ) );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** First error message of a MailPoet JSON response, or null when it succeeded. */
	private static function response_error( $response ) {
		if ( is_object( $response ) && property_exists( $response, 'errors' ) && ! empty( $response->errors ) ) {
			$first = (array) reset( $response->errors );
			return Form_Entry_Privacy::hide_emails( (string) ( $first['message'] ?? 'MailPoet refused the request.' ) );
		}
		if ( ! is_object( $response ) ) {
			return 'MailPoet returned no answer.';
		}
		return null;
	}

	private static function schedule_newsletter( array $args ) {
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		if ( ! array_key_exists( 'confirm', $args ) || true !== $args['confirm'] ) {
			throw new Tool_Refusal( 'not_confirmed', 'confirm must be true. Show the user the recipient count and subject from mailpoet_get_newsletter and send only when they agree.' );
		}
		if ( ! array_key_exists( 'expected_subject', $args ) || ! is_string( $args['expected_subject'] ) ) {
			throw new Tool_Refusal( 'invalid_expected_subject', 'expected_subject must be the subject from mailpoet_get_newsletter.' );
		}
		$id = self::required_id( $args, 'newsletter_id' );
		if ( ! array_key_exists( 'expected_recipients', $args ) || ! is_int( $args['expected_recipients'] ) || $args['expected_recipients'] < 0 ) {
			throw new Tool_Refusal( 'invalid_expected_recipients', 'expected_recipients must be the whole number recipient_count from mailpoet_get_newsletter.' );
		}
		$expected = (int) $args['expected_recipients'];
		$send_at  = isset( $args['send_at'] ) ? self::send_time( $args['send_at'] ) : null;

		$n = self::reload( $id );
		if ( 'standard' !== $n->getType() ) {
			throw new Tool_Refusal( 'not_draft', 'Only standard newsletters can be sent with this tool.' );
		}
		if ( 'draft' !== $n->getStatus() ) {
			throw new Tool_Refusal( 'not_draft', sprintf( 'Email %d is %s, not a draft. Cancel a scheduled one first with mailpoet_cancel_scheduled to reschedule it.', $id, esc_html( (string) $n->getStatus() ) ) );
		}
		if ( '' === trim( (string) $n->getSubject() ) ) {
			throw new Tool_Refusal( 'no_subject', 'This newsletter has no subject.' );
		}
		if ( $args['expected_subject'] !== (string) $n->getSubject() ) {
			throw new Tool_Refusal( 'subject_mismatch', 'Nothing was sent: the newsletter\'s subject is not the one confirmed. Read it again with mailpoet_get_newsletter and show the user what will be sent.', [ 'subject' => (string) $n->getSubject() ] );
		}
		if ( ! array_filter( (array) $n->getSegmentIds() ) ) {
			throw new Tool_Refusal( 'no_lists', 'This newsletter has no lists to send to. Set list_ids with mailpoet_save_draft.' );
		}
		$empty = 'block' === self::editor_of( $n ) ? '' === trim( wp_strip_all_tags( self::block_post_content( $n ) ) ) : self::body_summary( $n )['empty'];
		if ( $empty ) {
			throw new Tool_Refusal( 'empty_body', 'This newsletter has no content.' );
		}
		if ( class_exists( '\MailPoet\Mailer\MailerLog' ) && method_exists( '\MailPoet\Mailer\MailerLog', 'isSendingPaused' ) && \MailPoet\Mailer\MailerLog::isSendingPaused() ) {
			throw new Tool_Refusal( 'sending_paused', 'MailPoet\'s sending is paused (see MailPoet > Emails). Resume it there first.' );
		}
		try {
			self::service( 'mailer' )->getDefaultMailer();
		} catch ( Tool_Refusal $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new Tool_Refusal( 'sending_not_configured', 'MailPoet\'s sending method is not set up: ' . Form_Entry_Privacy::hide_emails( $e->getMessage() ) );
		}
		$count = self::recipient_count( $n );
		if ( 0 === $count ) {
			throw new Tool_Refusal( 'no_recipients', 'The newsletter\'s lists have no subscribed members.', [ 'recipient_count' => 0 ] );
		}
		if ( $count !== $expected ) {
			throw new Tool_Refusal( 'recipients_mismatch', sprintf( 'Nothing was sent: the newsletter would go to %d subscriber(s), not %d. Show the user the new number and call again with expected_recipients: %d if they agree.', $count, $expected, $count ), [ 'recipient_count' => $count ] );
		}
		$queue   = self::service( 'queue' );
		$saver   = self::service( 'save' );
		$options = [ 'isScheduled' => null === $send_at ? '0' : '1', 'scheduledAt' => null === $send_at ? '' : $send_at ];
		if ( class_exists( '\MailPoet\Newsletter\Sending\TimeZoneCampaignScheduler' ) && defined( '\MailPoet\Newsletter\Sending\TimeZoneCampaignScheduler::SCHEDULE_MODE_WEBSITE_TIME' ) ) {
			$options['scheduleMode'] = \MailPoet\Newsletter\Sending\TimeZoneCampaignScheduler::SCHEDULE_MODE_WEBSITE_TIME;
		}
		$saver->save( [ 'id' => $id, 'options' => $options ] );
		$error = self::response_error( $queue->add( [ 'newsletter_id' => $id ] ) );
		$n     = self::reload( $id );
		if ( null !== $error ) {
			if ( 'draft' === $n->getStatus() && null !== $send_at ) {
				$saver->save( [ 'id' => $id, 'options' => [ 'isScheduled' => '0', 'scheduledAt' => '' ] ] );
			}
			throw new Tool_Refusal( 'mailpoet_refused', 'MailPoet did not queue the newsletter: ' . $error );
		}
		$status = (string) $n->getStatus();
		$struct = [ 'newsletter_id' => $id, 'status' => $status, 'recipient_count' => $count, 'undo' => 'Sending cannot be undone.' ];
		if ( null !== $send_at ) {
			if ( 'scheduled' !== $status ) {
				throw new \Exception( sprintf( 'MailPoet did not schedule the newsletter (status is %s).', esc_html( $status ) ) );
			}
			$struct['scheduled_at']  = self::scheduled_at( $n );
			$struct['how_to_cancel'] = sprintf( 'Call mailpoet_cancel_scheduled with newsletter_id %d before it starts.', $id );
			$struct['recount']       = sprintf( 'MailPoet counts the recipients again when sending starts. If there are more than %d then, Royal MCP pauses the send and mailpoet_get_newsletter shows why.', $count + self::tolerance( $count ) );
			self::save_guard_record( $id, [ 'confirmed' => $count, 'subject' => (string) $n->getSubject(), 'scheduled_at' => $struct['scheduled_at'], 'at' => time() ] );
			return Envelope::success( sprintf( 'Scheduled newsletter %d for %s to %d subscriber(s).', $id, $struct['scheduled_at'], $count ), $struct );
		}
		if ( 'sending' !== $status ) {
			throw new \Exception( sprintf( 'MailPoet did not start sending the newsletter (status is %s).', esc_html( $status ) ) );
		}
		$queue_entity = method_exists( $n, 'getLatestQueue' ) ? $n->getLatestQueue() : null;
		$queued       = is_object( $queue_entity ) && method_exists( $queue_entity, 'getCountTotal' ) ? (int) $queue_entity->getCountTotal() : $count;
		$struct['recipient_count']     = $queued;
		$struct['queued']              = $queued;
		$struct['counted_before_send'] = $count;
		if ( $queued > $count + self::tolerance( $count ) ) {
			$queue->pause( [ 'newsletter_id' => $id ] );
			$held = sprintf( 'Paused by Royal MCP: MailPoet queued %d recipients, more than the %d confirmed. Nothing was sent. Resume it in MailPoet > Emails to send to all of them, or cancel it there.', $queued, $count );
			self::save_guard_record( $id, [ 'confirmed' => $count, 'subject' => (string) $n->getSubject(), 'at' => time(), 'held' => [ 'recipients' => $queued, 'at' => gmdate( 'c' ), 'reason' => $held ] ] );
			throw new Tool_Refusal( 'held_recipients', $held, [ 'recipient_count' => $queued ] );
		}
		$struct['how_to_cancel'] = 'It is sending now; pause it in MailPoet > Emails.';
		if ( class_exists( '\MailPoet\Mailer\SendingEnvironment' ) ) {
			try {
				$env = \MailPoet\DI\ContainerWrapper::getInstance()->get( 'MailPoet\Mailer\SendingEnvironment' );
				if ( is_object( $env ) && method_exists( $env, 'isSendingAllowed' ) && ! $env->isSendingAllowed() ) {
					$struct['note'] = 'This is not a production site, so MailPoet holds the queue until sending is allowed in its settings.';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return Envelope::success( sprintf( 'Newsletter %d is sending to %d subscriber(s), as MailPoet queued it.', $id, $queued ), $struct );
	}

	private static function cancel_scheduled( array $args ) {
		$id = self::required_id( $args, 'newsletter_id' );
		$n  = self::reload( $id );
		if ( 'standard' !== $n->getType() || 'scheduled' !== $n->getStatus() ) {
			throw new Tool_Refusal( 'not_scheduled', sprintf( 'Email %d is not a scheduled newsletter (status %s).', $id, esc_html( (string) $n->getStatus() ) ) );
		}
		$was = self::scheduled_at( $n );
		self::service( 'save' )->save( [ 'id' => $id, 'options' => [ 'isScheduled' => '0', 'scheduledAt' => '' ] ] );
		$n = self::reload( $id );
		if ( 'draft' !== $n->getStatus() ) {
			throw new \Exception( sprintf( 'MailPoet did not cancel the schedule (status is %s).', esc_html( (string) $n->getStatus() ) ) );
		}
		self::save_guard_record( $id, null );
		return Envelope::success(
			sprintf( 'Cancelled the schedule of newsletter %d; it is a draft again and nothing was sent.', $id ),
			[ 'newsletter_id' => $id, 'status' => 'draft', 'was_scheduled_at' => $was, 'undo' => 'No undo token: schedule it again with mailpoet_schedule_newsletter.' ]
		);
	}

	// ============ Undo ============

	public static function undo( array $snapshot, $force = false ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'MailPoet is no longer active, so this change cannot be undone.' );
		}
		$op = (string) ( $snapshot['op'] ?? '' );
		self::check_access( $op );
		$pre = (array) ( $snapshot['pre_op_state'] ?? [] );
		if ( 'mailpoet_save_draft' === $op ) {
			return self::undo_draft( $snapshot, $pre, $force );
		}
		if ( in_array( $op, [ 'mailpoet_save_subscriber', 'mailpoet_set_subscriber_lists' ], true ) ) {
			return self::undo_subscriber( $snapshot, $pre, $force );
		}
		throw new \Exception( 'Unsupported MailPoet undo operation.' );
	}

	private static function undo_subscriber( array $snapshot, array $pre, $force ) {
		global $wpdb;
		$op     = (string) $snapshot['op'];
		$id     = (int) ( $snapshot['target']['subscriber_id'] ?? 0 );
		$cf_ids = array_values( array_filter( (array) ( $pre['cf_ids'] ?? [] ), static function ( $c ) {
			return is_string( $c ) && preg_match( '/^cf_\d+$/', $c );
		} ) );
		$lists  = array_values( array_filter( array_map( 'intval', (array) ( $pre['list_ids'] ?? [] ) ) ) );
		$now    = $id > 0 ? self::subscriber_state( $id, $cf_ids, $lists ) : null;
		if ( null === $now ) {
			throw new Tool_Refusal( 'already_removed', 'The subscriber no longer exists.' );
		}
		if ( ! $force && self::state_fingerprint( $now ) !== (string) ( $pre['written_fingerprint'] ?? '' ) ) {
			throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: subscriber %d changed again after this change. The undo token stays valid: undo again with force: true to restore anyway.', $id ) );
		}
		if ( ! empty( $pre['created'] ) ) {
			$deleted = (int) self::service( 'subscribers' )->bulkDelete( [ $id ] );
			self::forget_cached();
			if ( 1 !== $deleted ) {
				throw new \Exception( 'MailPoet did not delete the subscriber (WordPress users and WooCommerce customers are kept by MailPoet).' );
			}
			return Envelope::success( sprintf( 'Deleted MailPoet subscriber %d, added by the undone change.', $id ), [ 'undone' => true, 'op' => $op, 'target' => [ 'subscriber_id' => $id ], 'restored' => true ] );
		}
		$prior = (array) ( $pre['prior'] ?? [] );
		$subs  = self::table( 'SubscriberEntity', 'subscribers' );
		$ss    = self::table( 'SubscriberSegmentEntity', 'subscriber_segment' );
		$scf   = self::table( 'SubscriberCustomFieldEntity', 'subscriber_custom_field' );
		$set   = [];
		foreach ( [ 'first_name', 'last_name', 'status', 'email' ] as $k ) {
			if ( array_key_exists( $k, $prior ) && is_string( $prior[ $k ] ) ) {
				$set[ $k ] = $prior[ $k ];
			}
		}
		// Someone who unsubscribed, bounced or went inactive after the change is never set back to
		// subscribed, even with force: those statuses are kept and listed in the reply.
		$written = (array) ( $pre['written'] ?? [] );
		$skipped = [];
		$now_off = in_array( (string) $now['status'], self::NO_MAIL_STATUSES, true );
		if ( isset( $set['status'] ) && $now_off && $now['status'] !== ( $written['status'] ?? null ) && ! in_array( $set['status'], self::NO_MAIL_STATUSES, true ) ) {
			unset( $set['status'] );
			$skipped[] = 'status';
		}
		$stamp = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $set ) {
			$wpdb->update( $subs, $set + [ 'updated_at' => $stamp ], [ 'id' => $id ] );
		}
		foreach ( (array) ( $prior['lists'] ?? [] ) as $lid => $status ) {
			$lid      = (int) $lid;
			$list_now = $now['lists'][ $lid ] ?? null;
			$changed  = $list_now !== ( $written['lists'][ $lid ] ?? null );
			if ( ( 'unsubscribed' === $list_now && 'unsubscribed' !== $status && $changed )
				|| ( 'subscribed' === $status && $now_off && in_array( 'status', $skipped, true ) ) ) {
				$skipped[] = 'list ' . $lid;
				continue;
			}
			if ( null === $status ) {
				$wpdb->delete( $ss, [ 'subscriber_id' => $id, 'segment_id' => $lid ] );
			} elseif ( null !== $now['lists'][ $lid ] ) {
				$wpdb->update( $ss, [ 'status' => (string) $status, 'updated_at' => $stamp ], [ 'subscriber_id' => $id, 'segment_id' => $lid ] );
			} else {
				$wpdb->insert( $ss, [ 'subscriber_id' => $id, 'segment_id' => $lid, 'status' => (string) $status, 'created_at' => $stamp, 'updated_at' => $stamp ] );
			}
		}
		foreach ( (array) ( $prior['custom_fields'] ?? [] ) as $cid => $value ) {
			if ( ! preg_match( '/^cf_(\d+)$/', (string) $cid, $m ) ) {
				continue;
			}
			$where = [ 'subscriber_id' => $id, 'custom_field_id' => (int) $m[1] ];
			if ( null === $value ) {
				$wpdb->delete( $scf, $where );
			} elseif ( null !== $now['custom_fields'][ $cid ] ) {
				$wpdb->update( $scf, [ 'value' => (string) $value, 'updated_at' => $stamp ], $where );
			} else {
				$wpdb->insert( $scf, $where + [ 'value' => (string) $value, 'created_at' => $stamp, 'updated_at' => $stamp ] );
			}
		}
		// phpcs:enable
		self::forget_cached();
		$struct = [ 'undone' => true, 'op' => $op, 'target' => [ 'subscriber_id' => $id ], 'restored' => true ];
		if ( $skipped ) {
			$struct['kept_unsubscribed'] = $skipped;
			return Envelope::success( sprintf( 'Restored MailPoet subscriber %d, except %s: they unsubscribed, bounced or went inactive since, and Royal MCP never sets them back to subscribed.', $id, implode( ', ', $skipped ) ), $struct );
		}
		return Envelope::success( sprintf( 'Restored MailPoet subscriber %d as it was.', $id ), $struct );
	}

	private static function undo_draft( array $snapshot, array $pre, $force ) {
		global $wpdb;
		$id = (int) ( $snapshot['target']['newsletter_id'] ?? 0 );
		try {
			$n = $id > 0 ? self::reload( $id ) : null;
		} catch ( Tool_Refusal $e ) {
			$n = null;
		}
		if ( ! $n ) {
			throw new Tool_Refusal( 'already_removed', 'The draft no longer exists.' );
		}
		if ( 'draft' !== $n->getStatus() ) {
			throw new Tool_Refusal( 'not_draft', sprintf( 'Cannot undo: email %d is %s now, not a draft.', $id, esc_html( (string) $n->getStatus() ) ) );
		}
		if ( ! $force && self::state_fingerprint( self::draft_state( $n ) ) !== (string) ( $pre['written_fingerprint'] ?? '' ) ) {
			throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: draft %d changed again after this change. The undo token stays valid: undo again with force: true to restore anyway.', $id ) );
		}
		if ( ! empty( $pre['created'] ) ) {
			self::service( 'delete' )->bulkDelete( [ $id ] );
			self::forget_cached();
			return Envelope::success( sprintf( 'Deleted MailPoet draft %d, created by the undone change.', $id ), [ 'undone' => true, 'op' => 'mailpoet_save_draft', 'target' => [ 'newsletter_id' => $id ], 'restored' => true ] );
		}
		$prior = (array) ( $pre['prior'] ?? [] );
		$data  = [ 'id' => $id ];
		foreach ( [ 'subject', 'preheader', 'sender_name', 'sender_address', 'reply_to_name', 'reply_to_address' ] as $k ) {
			if ( isset( $prior[ $k ] ) && is_string( $prior[ $k ] ) ) {
				$data[ $k ] = $prior[ $k ];
			}
		}
		$data['body'] = isset( $prior['body'] ) && is_string( $prior['body'] ) ? $prior['body'] : '';
		$segs         = array_values( array_filter( array_map( 'intval', (array) ( $prior['segments'] ?? [] ) ) ) );
		if ( $segs ) {
			$data['segments'] = array_map( static function ( $l ) {
				return [ 'id' => $l ];
			}, $segs );
		}
		self::service( 'save' )->save( $data );
		if ( ! $segs ) {
			$ns = self::table( 'NewsletterSegmentEntity', 'newsletter_segment' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $ns, [ 'newsletter_id' => $id ] );
		}
		self::forget_cached();
		return Envelope::success( sprintf( 'Restored MailPoet draft %d as it was.', $id ), [ 'undone' => true, 'op' => 'mailpoet_save_draft', 'target' => [ 'newsletter_id' => $id ], 'restored' => true ] );
	}

	/**
	 * Before / after rows for the change history: email addresses are never
	 * shown there, only whether the address changed.
	 */
	public static function change_rows( $op, array $t, array $pre ) {
		$rows = [];
		if ( ! empty( $pre['created'] ) ) {
			$rows[] = [ 'field' => 'mailpoet_save_draft' === $op ? 'draft' : 'subscriber', 'before' => null, 'after' => 'created' ];
			return $rows;
		}
		$prior = (array) ( $pre['prior'] ?? [] );
		if ( 'mailpoet_save_draft' === $op ) {
			foreach ( [ 'subject', 'preheader', 'sender_name', 'reply_to_name' ] as $k ) {
				if ( array_key_exists( $k, $prior ) ) {
					$rows[] = [ 'field' => $k, 'before' => Form_Entry_Privacy::hide_emails( (string) $prior[ $k ] ) ];
				}
			}
			$rows[] = [ 'field' => 'lists', 'before' => implode( ', ', array_map( 'intval', (array) ( $prior['segments'] ?? [] ) ) ) ];
			$rows[] = [ 'field' => 'body', 'before' => '' === (string) ( $prior['body'] ?? '' ) ? 'empty' : 'kept for undo' ];
			return $rows;
		}
		foreach ( [ 'first_name', 'last_name', 'status' ] as $k ) {
			if ( array_key_exists( $k, $prior ) ) {
				$rows[] = [ 'field' => $k, 'before' => Form_Entry_Privacy::hide_emails( (string) $prior[ $k ] ) ];
			}
		}
		if ( array_key_exists( 'email', $prior ) ) {
			$rows[] = [ 'field' => 'email', 'before' => '[email hidden]' ];
		}
		foreach ( (array) ( $prior['lists'] ?? [] ) as $lid => $st ) {
			$rows[] = [ 'field' => 'list ' . (int) $lid, 'before' => null === $st ? 'not on the list' : (string) $st ];
		}
		foreach ( (array) ( $prior['custom_fields'] ?? [] ) as $cid => $v ) {
			$rows[] = [ 'field' => (string) $cid, 'before' => null === $v ? null : Form_Entry_Privacy::hide_emails( (string) $v ) ];
		}
		return $rows;
	}
}

/**
 * Manifest declaration.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'MAILPOET_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'mailpoet',
		'plugin_display_name'        => 'MailPoet',
		'plugin_version'             => (string) MAILPOET_VERSION,
		'vendor_name'                => 'MailPoet',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read', 'write' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => false,
			'supports_undo'                   => true,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => true,
		],
	];
	return $manifests;
} );
