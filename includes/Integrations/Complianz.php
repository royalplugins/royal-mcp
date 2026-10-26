<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complianz MCP integration: read the consent setup (wizard state, regions,
 * cookies, services, banner texts, legal documents) and edit two kinds of
 * visitor-facing text, banner texts and cookie descriptions, with undo.
 *
 * Not offered: Script Center scripts (they run JavaScript on the front end),
 * banner and document custom CSS, settings resets, other wizard answers (they
 * change the legal documents), and data requests or consent records (personal
 * data). Company contact details and stored credentials are never returned.
 *
 * Complianz loads most of its admin code only in wp-admin, so these tools use
 * its always-loaded classes and its own tables directly.
 */
class Complianz {

	/** Banner text fields stored as plain text. */
	const BANNER_TEXT_FIELDS = [ 'accept', 'view_preferences', 'save_preferences', 'revoke', 'category_functional' ];

	/** Banner text fields stored as {text, show}. */
	const BANNER_TOGGLE_FIELDS = [
		'header', 'accept_informational', 'dismiss', 'category_prefs', 'category_stats', 'category_all',
		'functional_text', 'preferences_text', 'statistics_text', 'statistics_text_anonymous', 'marketing_text',
	];

	/** Banner messages, which allow a little formatting. */
	const BANNER_MESSAGE_FIELDS = [ 'message_optin', 'message_optout' ];

	/** Cookie description columns, by tool argument. */
	const COOKIE_FIELDS = [
		'purpose'                 => 'purpose',
		'retention'               => 'retention',
		'cookie_function'         => 'cookieFunction',
		'collected_personal_data' => 'collectedPersonalData',
	];

	const DOCUMENT_TYPES = [ 'cookie-statement', 'privacy-statement', 'impressum', 'disclaimer' ];

	public static function is_available() {
		if ( ! class_exists( '\COMPLIANZ' ) || ! class_exists( '\CMPLZ_COOKIEBANNER' ) ) {
			return false;
		}
		foreach ( [ 'cmplz_get_option', 'cmplz_get_regions', 'cmplz_get_cookiebanner', 'cmplz_get_default_banner_id', 'cmplz_allowed_html', 'cmplz_delete_transient' ] as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The capability Complianz itself requires (manage_privacy unless filtered).
	 * That capability only exists while Complianz is active, so without it
	 * administrators are told Complianz is not active rather than refused.
	 */
	public static function cap() {
		if ( ! self::is_available() ) {
			return 'manage_options';
		}
		$cap = apply_filters( 'cmplz_capability', 'manage_privacy' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_privacy';
	}

	public static function get_tools() {
		$banner_id = [ 'type' => 'integer', 'description' => 'Banner ID from complianz_get_banner (default: the default banner).' ];
		$toggle    = [
			'description' => 'New text, or {text, show} to also show or hide this element.',
			'anyOf'       => [
				[ 'type' => 'string' ],
				[
					'type'       => 'object',
					'properties' => [ 'text' => [ 'type' => 'string' ], 'show' => [ 'type' => 'boolean' ] ],
				],
			],
		];
		$text_props = [];
		foreach ( self::BANNER_TEXT_FIELDS as $f ) {
			$text_props[ $f ] = [ 'type' => 'string' ];
		}
		foreach ( self::BANNER_TOGGLE_FIELDS as $f ) {
			$text_props[ $f ] = $toggle;
		}
		foreach ( self::BANNER_MESSAGE_FIELDS as $f ) {
			$text_props[ $f ] = [ 'type' => 'string', 'description' => 'Banner message. Links, bold, italic, paragraphs and line breaks are kept; other HTML is removed.' ];
		}
		return [
			[
				'name'        => 'complianz_status',
				'description' => 'Complianz consent setup at a glance: whether the wizard was completed, regions and consent type (opt-in or opt-out), whether the cookie banner shows to visitors, cookie-database sync, cookie and service counts (and how many cookies lack a description), and when the documents, cookie scan and sync last changed. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'complianz_list_cookies',
				'description' => 'List the cookies Complianz knows about: ID, name, service, purpose, retention, function, collected personal data, language, whether it is kept in sync with cookiedatabase.org, shown on the cookie policy, ignored or deleted. Paged: {cookies, page, per_page, total, total_pages}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'language'            => [ 'type' => 'string', 'description' => 'Language code such as en or nl, or all. Default: the main (English) rows only.' ],
						'service'             => [ 'type' => 'string', 'description' => 'Only cookies of this service name.' ],
						'missing_description' => [ 'type' => 'boolean', 'description' => 'Only cookies with no purpose or retention.' ],
						'include_ignored'     => [ 'type' => 'boolean', 'description' => 'Include cookies marked ignored (default false).' ],
						'include_deleted'     => [ 'type' => 'boolean', 'description' => 'Include deleted cookies (default false).' ],
						'search'              => [ 'type' => 'string', 'description' => 'Only cookies whose name contains this text.' ],
						'page'                => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1).' ],
						'per_page'            => [ 'type' => 'integer', 'description' => 'Cookies per page (default 50, max 100).' ],
					],
				],
			],
			[
				'name'        => 'complianz_list_services',
				'description' => 'List the services Complianz knows about (for example Google Analytics): ID, name, category, type, whether it shares data with a third party, privacy statement URL, language, sync and how many cookies it has. Paged: {services, page, per_page, total, total_pages}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'language' => [ 'type' => 'string', 'description' => 'Language code, or all. Default: the main (English) rows only.' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1).' ],
						'per_page' => [ 'type' => 'integer', 'description' => 'Services per page (default 50, max 100).' ],
					],
				],
			],
			[
				'name'        => 'complianz_get_banner',
				'description' => 'Read a cookie banner\'s texts as visitors see them: title, message (opt-in and opt-out versions), button texts, category labels and descriptions, plus the list of banners. Use these field names with complianz_update_banner_text. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => [ 'banner_id' => $banner_id ] ],
			],
			[
				'name'        => 'complianz_list_documents',
				'description' => 'List the legal documents Complianz manages per region (cookie policy, privacy statement, imprint, disclaimer): whether each is required, how it is provided (generated, an existing page, an external URL or none), its page, address and whether a generated page still follows Complianz updates. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'complianz_update_banner_text',
				'description' => 'Change the texts on a Complianz cookie banner: title (header), messages (message_optin, message_optout), buttons (accept, accept_informational, dismiss, view_preferences, save_preferences, revoke) and category labels and descriptions. Pass only the fields to change; for title, deny button and category fields pass text, or {text, show} to also show or hide it. Colours, layout and custom CSS are not changed. Returns an undo token.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => array_merge( [ 'banner_id' => $banner_id ], $text_props ),
				],
			],
			[
				'name'        => 'complianz_update_cookie_description',
				'description' => 'Change how one cookie is described on the cookie policy: purpose, retention, function and collected personal data. Edits the row for the cookie ID given (each language has its own row; see complianz_list_cookies with language all). By default the cookie stops syncing with cookiedatabase.org so the next sync does not overwrite the new text (keep_sync true keeps it syncing). Returns an undo token.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'cookie_id'               => [ 'type' => 'integer', 'description' => 'Cookie ID from complianz_list_cookies.' ],
						'purpose'                 => [ 'type' => 'string' ],
						'retention'               => [ 'type' => 'string', 'description' => 'For example: 1 year, session.' ],
						'cookie_function'         => [ 'type' => 'string', 'description' => 'What the cookie does, for example: store the consent choice.' ],
						'collected_personal_data' => [ 'type' => 'string' ],
						'keep_sync'               => [ 'type' => 'boolean', 'description' => 'Keep syncing with cookiedatabase.org (its next sync may overwrite this text). Default false.' ],
					],
					'required'   => [ 'cookie_id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// The capability check comes first, so callers who could never use these
		// tools are not told whether Complianz is installed.
		if ( ! current_user_can( self::cap() ) ) {
			throw new \Exception( 'You do not have permission to use Complianz tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Complianz is not active' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'complianz_status':
				return self::status();
			case 'complianz_list_cookies':
				return self::list_cookies( $args );
			case 'complianz_list_services':
				return self::list_services( $args );
			case 'complianz_get_banner':
				return self::get_banner( $args );
			case 'complianz_list_documents':
				return self::list_documents();
			case 'complianz_update_banner_text':
				return self::update_banner_text( $args );
			case 'complianz_update_cookie_description':
				return self::update_cookie_description( $args );
			default:
				throw new \Exception( 'Unknown Complianz tool: ' . esc_html( (string) $name ) );
		}
	}

	// ============ Reads ============

	private static function status() {
		global $wpdb;
		$regions  = array_values( array_map( 'strval', (array) cmplz_get_regions() ) );
		$consent  = function_exists( 'cmplz_get_used_consenttypes' ) ? array_values( array_map( 'strval', (array) cmplz_get_used_consenttypes() ) ) : [];
		$wizard   = (bool) get_option( 'cmplz_wizard_completed_once' );
		$needs    = isset( \COMPLIANZ::$banner_loader ) && is_object( \COMPLIANZ::$banner_loader ) && method_exists( \COMPLIANZ::$banner_loader, 'site_needs_cookie_warning' )
			? (bool) \COMPLIANZ::$banner_loader->site_needs_cookie_warning()
			: null;
		$banner_id = (int) cmplz_get_default_banner_id();
		$banner    = $banner_id > 0 ? cmplz_get_cookiebanner( $banner_id, false ) : null;
		$disabled  = $banner && ! empty( $banner->disable_cookiebanner );

		$cookies = self::table_exists( 'cmplz_cookies' );
		$counts  = [ 'cookies' => 0, 'cookies_without_description' => 0, 'services' => 0 ];
		if ( $cookies ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts['cookies'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cmplz_cookies WHERE isTranslationFrom = 0 AND deleted = 0 AND ignored = 0" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts['cookies_without_description'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cmplz_cookies WHERE isTranslationFrom = 0 AND deleted = 0 AND ignored = 0 AND ( purpose = '' OR purpose IS NULL OR retention = '' OR retention IS NULL )" );
		}
		if ( self::table_exists( 'cmplz_services' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts['services'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cmplz_services WHERE isTranslationFrom = 0" );
		}

		return [
			'wizard_completed'     => $wizard,
			'regions'              => $regions,
			'consent_types'        => $consent,
			'cookie_banner'        => [
				'needed'            => $needs,
				'default_banner_id' => $banner_id > 0 ? $banner_id : null,
				'disabled'          => (bool) $disabled,
				'shows_to_visitors' => $wizard && true === $needs && $banner_id > 0 && ! $disabled,
			],
			'cookie_database_sync' => 'no' !== (string) cmplz_get_option( 'use_cdb_api' ),
			'counts'               => $counts,
			'dates'                => [
				'documents_updated'  => self::date( get_option( 'cmplz_documents_update_date' ) ),
				'policy_published'   => self::date( get_option( 'cmplz_publish_date' ) ),
				'last_cookie_scan'   => self::date( get_option( 'cmplz_last_cookie_scan' ) ),
				'last_cookie_sync'   => self::date( get_option( 'cmplz_last_cookie_sync' ) ),
				'activated'          => self::date( get_option( 'cmplz_activation_time' ) ),
			],
			'tables_ready'         => $cookies,
		];
	}

	private static function list_cookies( array $args ) {
		global $wpdb;
		self::require_table( 'cmplz_cookies' );
		list( $page, $per_page ) = self::paging( $args );
		$where  = [ '1=1' ];
		$params = [];
		self::language_clause( $args, 'c.', $where, $params );
		if ( empty( $args['include_deleted'] ) ) {
			$where[] = 'c.deleted = 0';
		}
		if ( empty( $args['include_ignored'] ) ) {
			$where[] = 'c.ignored = 0';
		}
		if ( ! empty( $args['missing_description'] ) ) {
			$where[] = "( c.purpose = '' OR c.purpose IS NULL OR c.retention = '' OR c.retention IS NULL )";
		}
		if ( isset( $args['service'] ) && is_string( $args['service'] ) && '' !== trim( $args['service'] ) ) {
			$where[]  = 's.name = %s';
			$params[] = sanitize_text_field( $args['service'] );
		}
		if ( isset( $args['search'] ) && is_string( $args['search'] ) && '' !== trim( $args['search'] ) ) {
			$where[]  = 'c.name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}
		$from = "{$wpdb->prefix}cmplz_cookies c LEFT JOIN {$wpdb->prefix}cmplz_services s ON s.ID = c.serviceID";
		$sql  = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- clauses are fixed strings, values bound below
		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM $from WHERE $sql", $params ) );
		$query = self::prepare( "SELECT c.*, s.name AS service_name FROM $from WHERE $sql ORDER BY c.name ASC, c.ID ASC LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared by self::prepare
		$rows = (array) $wpdb->get_results( $query );

		return [
			'cookies'     => array_map( [ __CLASS__, 'cookie_row' ], $rows ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
		];
	}

	private static function list_services( array $args ) {
		global $wpdb;
		self::require_table( 'cmplz_services' );
		list( $page, $per_page ) = self::paging( $args );
		$where  = [ '1=1' ];
		$params = [];
		self::language_clause( $args, 's.', $where, $params );
		$sql = implode( ' AND ', $where );
		$cookie_count = self::table_exists( 'cmplz_cookies' )
			? "(SELECT COUNT(*) FROM {$wpdb->prefix}cmplz_cookies c WHERE c.serviceID = s.ID AND c.deleted = 0)"
			: '0';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- clauses are fixed strings, values bound below
		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cmplz_services s WHERE $sql", $params ) );
		$query = self::prepare( "SELECT s.*, $cookie_count AS cookie_count FROM {$wpdb->prefix}cmplz_services s WHERE $sql ORDER BY s.name ASC, s.ID ASC LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared by self::prepare
		$rows = (array) $wpdb->get_results( $query );

		$out = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'id'                     => (int) $r->ID,
				'name'                   => (string) $r->name,
				'category'               => (string) $r->category,
				'service_type'           => (string) $r->serviceType,
				'shares_data'            => ! empty( $r->thirdParty ),
				'privacy_statement_url'  => esc_url_raw( (string) $r->privacyStatementURL ),
				'language'               => (string) $r->language,
				'is_translation_of'      => (int) $r->isTranslationFrom > 0 ? (int) $r->isTranslationFrom : null,
				'sync'                   => ! empty( $r->sync ),
				'cookie_count'           => (int) $r->cookie_count,
			];
		}
		return [
			'services'    => $out,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
		];
	}

	private static function get_banner( array $args ) {
		$id     = self::banner_id( $args );
		$banner = cmplz_get_cookiebanner( $id, true );
		$texts  = [];
		foreach ( self::BANNER_TEXT_FIELDS as $f ) {
			$texts[ $f ] = (string) $banner->{$f};
		}
		foreach ( self::BANNER_TOGGLE_FIELDS as $f ) {
			$texts[ $f ] = self::toggle_value( $banner->{$f} );
		}
		foreach ( self::BANNER_MESSAGE_FIELDS as $f ) {
			$texts[ $f ] = (string) $banner->{$f};
		}
		$list = [];
		foreach ( self::banner_rows() as $row ) {
			$list[] = [ 'id' => (int) $row->ID, 'title' => (string) $row->title, 'default' => ! empty( $row->default ) ];
		}
		return [
			'banner_id'      => $id,
			'title'          => (string) $banner->title,
			'default'        => ! empty( $banner->default ),
			'disabled'       => ! empty( $banner->disable_cookiebanner ),
			'banner_version' => (int) $banner->banner_version,
			'texts'          => $texts,
			'banners'        => $list,
		];
	}

	private static function list_documents() {
		$required = isset( \COMPLIANZ::$document ) && is_object( \COMPLIANZ::$document ) && method_exists( \COMPLIANZ::$document, 'get_required_pages' )
			? (array) \COMPLIANZ::$document->get_required_pages()
			: [];
		// pages is a private property read through __get, so isset() on it is always false.
		$pages    = is_object( \COMPLIANZ::$config ?? null ) ? \COMPLIANZ::$config->pages : [];
		$pages    = is_array( $pages ) ? $pages : [];
		$updated  = self::date( get_option( 'cmplz_documents_update_date' ) );
		$active   = array_merge( array_map( 'strval', (array) cmplz_get_regions() ), [ 'all' ] );
		$out      = [];
		foreach ( $pages as $region => $types ) {
			// Only the regions this site targets, plus documents for every region.
			if ( ! is_array( $types ) || ! in_array( (string) $region, $active, true ) ) {
				continue;
			}
			foreach ( $types as $type => $def ) {
				if ( ! in_array( $type, self::DOCUMENT_TYPES, true ) ) {
					continue;
				}
				$mode = (string) cmplz_get_option( $type );
				$mode = in_array( $mode, [ 'generated', 'custom', 'url', 'none' ], true ) ? $mode : 'none';
				$row  = [
					'type'     => (string) $type,
					'region'   => (string) $region,
					'title'    => is_array( $def ) && isset( $def['title'] ) ? (string) $def['title'] : (string) $type,
					'required' => isset( $required[ $region ][ $type ] ),
					'mode'     => $mode,
					'page_id'  => null,
					'url'      => null,
				];
				if ( 'generated' === $mode && method_exists( \COMPLIANZ::$document, 'get_shortcode_page_id' ) ) {
					$page_id = (int) \COMPLIANZ::$document->get_shortcode_page_id( $type, $region, false );
					if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
						$row['page_id']        = $page_id;
						$row['url']            = (string) get_permalink( $page_id );
						$row['follows_updates'] = self::follows_updates( $page_id );
						$row['last_generated'] = $updated;
					}
				} elseif ( 'custom' === $mode ) {
					$page_id = (int) get_option( 'cmplz_' . $type . '_custom_page' );
					if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
						$row['page_id'] = $page_id;
						$row['url']     = (string) get_permalink( $page_id );
					}
				} elseif ( 'url' === $mode ) {
					$url        = esc_url_raw( (string) get_option( 'cmplz_' . $type . '_custom_page_url' ) );
					$row['url'] = '' !== $url ? $url : null;
				}
				$row['missing'] = $row['required'] && null === $row['url'];
				$out[]          = $row;
			}
		}
		return [ 'documents' => $out ];
	}

	// ============ Writes ============

	private static function update_banner_text( array $args ) {
		$id     = self::banner_id( $args );
		$fields = self::banner_fields( $args );
		if ( [] === $fields ) {
			throw new \Exception( 'Pass at least one banner text field, for example accept or message_optin.' );
		}
		$banner = self::banner_for_write( $id );
		$before = self::banner_values( $banner, array_keys( $fields ) );
		foreach ( $fields as $f => $value ) {
			if ( in_array( $f, self::BANNER_TOGGLE_FIELDS, true ) && ! is_array( $value ) ) {
				$current = self::toggle_value( $banner->{$f} );
				$value   = [ 'text' => $value, 'show' => $current['show'] ];
			}
			$banner->{$f} = is_array( $value ) ? [ 'text' => (string) $value['text'], 'show' => (int) (bool) $value['show'] ] : $value;
		}
		$banner->save();
		$after = self::banner_values( self::banner_for_write( $id ), array_keys( $fields ) );
		if ( $after === $before ) {
			return Envelope::success( sprintf( 'Banner %d already had these texts; nothing changed.', $id ), [ 'banner_id' => $id, 'changed' => false, 'texts' => $after ] );
		}
		$undo = Undo_Store::store( [
			'op'           => 'complianz_update_banner_text',
			'summary'      => sprintf( 'Restore the texts of cookie banner %d', $id ),
			'target'       => [ 'banner_id' => $id ],
			'pre_op_state' => [ 'prior_values' => $before, 'applied_values' => $after ],
		] );
		return Envelope::success(
			sprintf( 'Updated %d text field(s) on cookie banner %d. Visitors see the change on their next page load; a page cache may need clearing first.', count( $fields ), $id ),
			[ 'banner_id' => $id, 'changed' => true, 'before' => $before, 'after' => $after ],
			$undo
		);
	}

	private static function update_cookie_description( array $args ) {
		self::require_table( 'cmplz_cookies' );
		$id  = self::positive_int( $args['cookie_id'] ?? null );
		$row = $id > 0 ? self::cookie_columns( $id ) : null;
		if ( ! $row ) {
			throw new \Exception( 'No cookie with that ID.' );
		}
		$columns = [];
		foreach ( self::COOKIE_FIELDS as $arg => $column ) {
			if ( ! array_key_exists( $arg, $args ) ) {
				continue;
			}
			if ( ! is_string( $args[ $arg ] ) || strlen( $args[ $arg ] ) > 2000 ) {
				throw new \Exception( esc_html( $arg ) . ' must be text of at most 2000 characters.' );
			}
			$columns[ $column ] = sanitize_text_field( $args[ $arg ] );
		}
		if ( [] === $columns ) {
			throw new \Exception( 'Pass purpose, retention, cookie_function or collected_personal_data.' );
		}
		if ( empty( $args['keep_sync'] ) ) {
			$columns['sync'] = 0;
		}
		$before = array_intersect_key( $row, $columns );
		self::write_cookie( $id, $columns );
		$after = array_intersect_key( self::cookie_columns( $id ), $columns );
		if ( $after === $before ) {
			return Envelope::success( sprintf( 'Cookie %d already had this description; nothing changed.', $id ), [ 'cookie_id' => $id, 'changed' => false ] );
		}
		$undo = Undo_Store::store( [
			'op'           => 'complianz_update_cookie_description',
			'summary'      => sprintf( 'Restore the description of cookie %d (%s)', $id, $row['name'] ),
			'target'       => [ 'cookie_id' => $id ],
			'pre_op_state' => [ 'prior_values' => $before, 'applied_values' => $after ],
		] );
		$note = isset( $columns['sync'] ) && ! empty( $before['sync'] )
			? ' It no longer syncs with cookiedatabase.org, so the next sync keeps this text.'
			: '';
		return Envelope::success(
			sprintf( 'Updated the description of cookie %d (%s).%s', $id, $row['name'], $note ),
			[ 'cookie_id' => $id, 'changed' => true, 'before' => self::public_cookie_fields( $before ), 'after' => self::public_cookie_fields( $after ) ],
			$undo
		);
	}

	/** Undo of a banner text or cookie description change; refuses when it changed again since. */
	public static function undo( array $snapshot ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Complianz is no longer active, so this change cannot be undone.' );
		}
		if ( ! current_user_can( self::cap() ) ) {
			throw new \Exception( 'You do not have permission to change Complianz settings.' );
		}
		$op    = (string) ( $snapshot['op'] ?? '' );
		$pre   = (array) ( $snapshot['pre_op_state'] ?? [] );
		$prior = (array) ( $pre['prior_values'] ?? [] );
		$applied = (array) ( $pre['applied_values'] ?? [] );

		if ( 'complianz_update_banner_text' === $op ) {
			$id     = (int) ( $snapshot['target']['banner_id'] ?? 0 );
			try {
				$banner = self::banner_for_write( $id );
			} catch ( \Exception $e ) {
				throw new \Exception( 'Cookie banner ' . (int) $id . ' no longer exists, so there is nothing to undo.' );
			}
			if ( self::banner_values( $banner, array_keys( $applied ) ) !== $applied ) {
				throw new \Exception( 'Cannot undo: cookie banner ' . (int) $id . '\'s texts changed after the tracked change.' );
			}
			foreach ( $prior as $f => $value ) {
				if ( self::is_banner_field( $f ) ) {
					$banner->{$f} = $value;
				}
			}
			$banner->save();
			return Envelope::success( sprintf( 'Restored the texts of cookie banner %d.', $id ), [ 'undone' => true, 'op' => $op, 'banner_id' => $id ] );
		}

		if ( 'complianz_update_cookie_description' === $op ) {
			self::require_table( 'cmplz_cookies' );
			$id  = (int) ( $snapshot['target']['cookie_id'] ?? 0 );
			$row = $id > 0 ? self::cookie_columns( $id ) : null;
			if ( ! $row ) {
				throw new \Exception( 'Cookie ' . (int) $id . ' no longer exists, so there is nothing to undo.' );
			}
			if ( array_intersect_key( $row, $applied ) != $applied ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- DB values come back as strings
				throw new \Exception( 'Cannot undo: cookie ' . (int) $id . '\'s description changed after the tracked change.' );
			}
			$restore = array_intersect_key( $prior, array_flip( array_merge( array_values( self::COOKIE_FIELDS ), [ 'sync' ] ) ) );
			self::write_cookie( $id, $restore );
			return Envelope::success( sprintf( 'Restored the description of cookie %d.', $id ), [ 'undone' => true, 'op' => $op, 'cookie_id' => $id ] );
		}

		throw new \Exception( 'Unsupported Complianz undo.' );
	}

	// ============ Helpers ============

	private static function banner_fields( array $args ) {
		$fields = [];
		foreach ( self::BANNER_TEXT_FIELDS as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$fields[ $f ] = self::short_text( $f, $args[ $f ] );
			}
		}
		foreach ( self::BANNER_TOGGLE_FIELDS as $f ) {
			if ( ! array_key_exists( $f, $args ) ) {
				continue;
			}
			$v = $args[ $f ];
			if ( is_array( $v ) ) {
				if ( ! array_key_exists( 'text', $v ) ) {
					throw new \Exception( esc_html( $f ) . ' needs text (and optionally show).' );
				}
				if ( array_key_exists( 'show', $v ) && ! is_bool( $v['show'] ) ) {
					throw new \Exception( esc_html( $f ) . '.show must be true or false.' );
				}
				$fields[ $f ] = [
					'text' => self::short_text( $f, $v['text'] ),
					'show' => array_key_exists( 'show', $v ) ? $v['show'] : true,
				];
			} else {
				$fields[ $f ] = self::short_text( $f, $v );
			}
		}
		foreach ( self::BANNER_MESSAGE_FIELDS as $f ) {
			if ( ! array_key_exists( $f, $args ) ) {
				continue;
			}
			if ( ! is_string( $args[ $f ] ) || strlen( $args[ $f ] ) > 5000 ) {
				throw new \Exception( esc_html( $f ) . ' must be text of at most 5000 characters.' );
			}
			// Drop script and style blocks with their contents, then keep basic formatting.
			$message      = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $args[ $f ] );
			$fields[ $f ] = wp_kses( $message, [
				'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
				'strong' => [],
				'b'      => [],
				'em'     => [],
				'i'      => [],
				'br'     => [],
				'p'      => [],
			] );
		}
		return $fields;
	}

	private static function short_text( $field, $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 500 ) {
			throw new \Exception( esc_html( $field ) . ' must be text of at most 500 characters.' );
		}
		return sanitize_text_field( $value );
	}

	private static function is_banner_field( $f ) {
		return in_array( $f, self::BANNER_TEXT_FIELDS, true ) || in_array( $f, self::BANNER_TOGGLE_FIELDS, true ) || in_array( $f, self::BANNER_MESSAGE_FIELDS, true );
	}

	/** Stored values of the given banner fields, toggles as {text, show}. */
	private static function banner_values( $banner, array $fields ) {
		$out = [];
		foreach ( $fields as $f ) {
			$out[ $f ] = in_array( $f, self::BANNER_TOGGLE_FIELDS, true ) ? self::toggle_value( $banner->{$f} ) : (string) $banner->{$f};
		}
		return $out;
	}

	private static function toggle_value( $v ) {
		if ( is_string( $v ) ) {
			$u = maybe_unserialize( $v );
			$v = is_array( $u ) ? $u : [ 'text' => $v, 'show' => true ];
		}
		$v = is_array( $v ) ? $v : [];
		return [ 'text' => (string) ( $v['text'] ?? '' ), 'show' => ! empty( $v['show'] ) ];
	}

	/**
	 * Banner rows straight from the table: Complianz's own banner list is cached
	 * and not refreshed when a banner is added.
	 */
	private static function banner_rows() {
		global $wpdb;
		if ( ! self::table_exists( 'cmplz_cookiebanners' ) ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( "SELECT ID, title, `default` FROM {$wpdb->prefix}cmplz_cookiebanners ORDER BY ID ASC" );
	}

	/** A banner ID that exists: the given one or the default. */
	private static function banner_id( array $args ) {
		$id = array_key_exists( 'banner_id', $args ) ? self::positive_int( $args['banner_id'] ) : (int) cmplz_get_default_banner_id();
		if ( $id <= 0 || ! in_array( $id, array_map( 'intval', wp_list_pluck( self::banner_rows(), 'ID' ) ), true ) ) {
			throw new \Exception( array_key_exists( 'banner_id', $args ) ? 'No cookie banner with that ID.' : 'Complianz has no cookie banner yet; finish its wizard first.' );
		}
		return $id;
	}

	/**
	 * A banner loaded with its stored values (no defaults filled in, so saving
	 * does not write default texts into empty fields).
	 */
	private static function banner_for_write( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $id > 0 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cmplz_cookiebanners WHERE ID = %d", (int) $id ) ) > 0;
		$banner = $exists ? new \CMPLZ_COOKIEBANNER( (int) $id, false ) : null;
		if ( ! $banner || ! $banner->ID ) {
			throw new \Exception( 'No cookie banner with that ID.' );
		}
		// Complianz switches its banner setting back on when a banner is saved,
		// with a function that only exists in wp-admin.
		if ( empty( $banner->disable_cookiebanner ) && 'no' === cmplz_get_option( 'enable_cookie_banner' ) && ! function_exists( 'cmplz_update_option_no_hooks' ) ) {
			throw new \Exception( 'The cookie banner is switched off in Complianz settings. Switch it on in Complianz first, then change its texts.' );
		}
		return $banner;
	}

	private static function cookie_columns( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, name, purpose, retention, cookieFunction, collectedPersonalData, sync FROM {$wpdb->prefix}cmplz_cookies WHERE ID = %d", (int) $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( [ 'purpose', 'retention', 'cookieFunction', 'collectedPersonalData' ] as $c ) {
			$row[ $c ] = (string) $row[ $c ];
		}
		$row['sync'] = (int) $row['sync'];
		return $row;
	}

	/** Write cookie columns, then clear the caches Complianz reads them through. */
	private static function write_cookie( $id, array $columns ) {
		global $wpdb;
		if ( ! $columns ) {
			return;
		}
		$formats = [];
		foreach ( $columns as $c => $v ) {
			$formats[] = 'sync' === $c ? '%d' : '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->update( $wpdb->prefix . 'cmplz_cookies', $columns, [ 'ID' => (int) $id ], $formats, [ '%d' ] );
		if ( false === $written ) {
			throw new \Exception( 'The cookie could not be updated.' );
		}
		wp_cache_delete( 'cmplz_cookie_' . (int) $id, 'complianz' );
		delete_transient( 'cmplz_cookies' );
		cmplz_delete_transient( 'cmplz_cookie_shredder_list' );
	}

	private static function public_cookie_fields( array $cols ) {
		$map = array_flip( self::COOKIE_FIELDS );
		$out = [];
		foreach ( $cols as $c => $v ) {
			if ( 'sync' === $c ) {
				$out['sync'] = (bool) $v;
			} elseif ( isset( $map[ $c ] ) ) {
				$out[ $map[ $c ] ] = (string) $v;
			}
		}
		return $out;
	}

	private static function cookie_row( $r ) {
		return [
			'id'                      => (int) $r->ID,
			'name'                    => (string) $r->name,
			'service'                 => null !== $r->service_name ? (string) $r->service_name : null,
			'service_id'              => (int) $r->serviceID > 0 ? (int) $r->serviceID : null,
			'purpose'                 => (string) $r->purpose,
			'retention'               => (string) $r->retention,
			'cookie_function'         => (string) $r->cookieFunction,
			'collected_personal_data' => (string) $r->collectedPersonalData,
			'type'                    => (string) $r->type,
			'domain'                  => (string) $r->domain,
			'language'                => (string) $r->language,
			'is_translation_of'       => (int) $r->isTranslationFrom > 0 ? (int) $r->isTranslationFrom : null,
			'sync'                    => ! empty( $r->sync ),
			'show_on_policy'          => ! empty( $r->showOnPolicy ),
			'ignored'                 => ! empty( $r->ignored ),
			'deleted'                 => ! empty( $r->deleted ),
			'last_updated'            => self::date( $r->lastUpdatedDate ),
		];
	}

	/** A generated page keeps following Complianz updates unless it was unlinked to edit by hand. */
	private static function follows_updates( $page_id ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return null;
		}
		if ( false !== strpos( (string) $post->post_content, 'wp:complianz/document' ) ) {
			return false === strpos( (string) $post->post_content, '"documentSyncStatus":"unlink"' );
		}
		return 'unlink' !== get_post_meta( $page_id, 'cmplz_document_status', true );
	}

	private static function language_clause( array $args, $alias, array &$where, array &$params ) {
		$lang = isset( $args['language'] ) && is_string( $args['language'] ) ? strtolower( trim( $args['language'] ) ) : '';
		if ( 'all' === $lang ) {
			return;
		}
		if ( '' === $lang ) {
			$where[] = $alias . 'isTranslationFrom = 0';
			return;
		}
		if ( ! preg_match( '/^[a-z]{2,3}(_[a-z]{2})?$/', $lang ) ) {
			throw new \Exception( 'language must be a language code such as en or nl, or all.' );
		}
		$where[]  = $alias . 'language = %s';
		$params[] = $lang;
	}

	private static function paging( array $args ) {
		$per_page = (int) max( 1, min( is_scalar( $args['per_page'] ?? null ) ? (float) $args['per_page'] : 50, 100 ) );
		$page     = (int) max( 1, min( is_scalar( $args['page'] ?? null ) ? (float) $args['page'] : 1, 1000000 ) );
		return [ $page, $per_page ];
	}

	private static function prepare( $sql, array $params ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only
		return $params ? $wpdb->prepare( $sql, $params ) : $sql;
	}

	private static function positive_int( $v ) {
		if ( is_int( $v ) ) {
			return $v;
		}
		return is_string( $v ) && ctype_digit( $v ) ? (int) $v : 0;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	private static function require_table( $table ) {
		if ( ! self::table_exists( $table ) ) {
			throw new \Exception( 'Complianz has not set up its cookie tables yet; open Complianz in wp-admin once.' );
		}
	}

	/** A stored Unix time or date as ISO 8601 UTC, or null. */
	private static function date( $v ) {
		if ( is_numeric( $v ) && (int) $v > 0 ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', (int) $v );
		}
		if ( is_string( $v ) && '' !== $v && false !== strtotime( $v ) ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $v ) );
		}
		return null;
	}
}
