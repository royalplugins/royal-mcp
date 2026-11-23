<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;
use Royal_MCP\MCP\Support\SafeText;
use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FormForge Pro: forms, entry counts, one entry, analytics, and two ways to
 * make a new form (a copy of a form, or from one of FormForge's templates).
 *
 * Entries go through Form_Entry_Privacy like every other form plugin's:
 * personal fields come back masked unless the site lets them through.
 */
class FormForge {

	const PLUGIN = 'formforge';

	const TOOLS = [
		'formforge_list_forms'           => 'list_forms',
		'formforge_get_entry'            => 'get_entry',
		'formforge_get_entry_counts'     => 'get_entry_counts',
		'formforge_get_analytics'        => 'get_analytics',
		'formforge_duplicate'            => 'duplicate',
		'formforge_create_from_template' => 'create_from_template',
	];

	/** The time windows FormForge's analytics know. */
	const ANALYTICS_RANGES = [ '7d', '30d', '90d', 'all' ];

	/** Statuses a new form may be given. */
	const FORM_STATUSES = [ 'active', 'inactive' ];

	/** Days a link to an uploaded file in an entry reply keeps working. */
	const FILE_LINK_DAYS = 7;

	/** Largest settings argument, as JSON, in bytes. */
	const MAX_SETTINGS_BYTES = 20000;

	/** Longest form title FormForge stores, in characters. */
	const MAX_TITLE = 255;

	/** Most fields of one entry, most characters of one answer, and most forms in a count, that a reply carries. */
	const MAX_FIELDS      = 200;
	const MAX_VALUE_CHARS = 2000;
	const MAX_COUNT_FORMS = 200;

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function is_available() {
		return class_exists( 'FormForge_Database' ) && class_exists( 'FormForge_Entry' ) && class_exists( 'RFP_Analytics' ) && class_exists( 'RFP_Templates' );
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown FormForge tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ] ], is_array( $args ) ? $args : [] );
	}

	/** Forms and their entries hold visitors' data: administrators only. */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use the FormForge tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'FormForge Pro is not active on this site.' );
		}
	}

	/** A form row as FormForge returns it, or null. */
	private static function form( $form_id ) {
		$form = $form_id > 0 ? \FormForge_Database::get_form( $form_id ) : null;
		return is_array( $form ) && ! empty( $form['id'] ) ? $form : null;
	}

	/** A JSON column that FormForge may hand back as text or as a list. */
	private static function decoded( $value ) {
		if ( is_string( $value ) && '' !== $value ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : [];
	}

	private static function fields_of( array $form ) {
		$data   = self::decoded( $form['form_data'] ?? null );
		$fields = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : [];
		return array_values( array_filter( $fields, 'is_array' ) );
	}

	// ------------------------------------------------------------------
	// Reads
	// ------------------------------------------------------------------

	private static function list_forms( array $args ) {
		self::require_cap();
		if ( isset( $args['status'] ) && ! is_string( $args['status'] ) ) {
			return Envelope::error( 'invalid_args', 'status must be text: all, or a form status such as active.' );
		}
		$status = isset( $args['status'] ) && '' !== sanitize_key( $args['status'] ) ? sanitize_key( $args['status'] ) : 'all';
		foreach ( [ 'limit', 'offset' ] as $key ) {
			if ( isset( $args[ $key ] ) && ! ( is_int( $args[ $key ] ) && $args[ $key ] >= 0 ) && ! ( is_string( $args[ $key ] ) && ctype_digit( $args[ $key ] ) ) ) {
				return Envelope::error( 'invalid_args', $key . ' must be a whole number, zero or more.' );
			}
		}
		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, min( 100000, (int) $args['offset'] ) ) : 0;
		$forms  = (array) \FormForge_Database::get_forms( [ 'status' => 'all' === $status ? '' : $status, 'limit' => $limit, 'offset' => $offset ] );
		$rows   = [];
		foreach ( $forms as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$id     = (int) ( $f['id'] ?? 0 );
			$rows[] = [
				'id'          => $id,
				'title'       => SafeText::field( (string) ( $f['title'] ?? '' ) ),
				'status'      => sanitize_key( (string) ( $f['status'] ?? '' ) ),
				'field_count' => count( self::fields_of( $f ) ),
				'entries'     => (int) \FormForge_Database::get_entries_count( $id, 'active' ),
				'created_at'  => (string) ( $f['created_at'] ?? '' ),
				'updated_at'  => (string) ( $f['updated_at'] ?? '' ),
			];
		}
		return Envelope::success(
			sprintf( '%d FormForge form(s) (%s).', count( $rows ), $status ),
			[ 'forms' => $rows, 'count' => count( $rows ), 'status' => $status, 'limit' => $limit, 'offset' => $offset ]
		);
	}

	private static function get_entry( array $args ) {
		self::require_cap();
		$entry_id = Form_Entry_Privacy::positive_int( $args['entry_id'] ?? null );
		if ( $entry_id <= 0 ) {
			return Envelope::error( 'invalid_args', 'entry_id must be a positive whole number.' );
		}
		$row = \FormForge_Database::get_entry( $entry_id );
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return Envelope::error( 'not_found', 'FormForge entry not found.' );
		}
		$form_id = (int) ( $row['form_id'] ?? 0 );
		$form    = self::form( $form_id );
		$reveal  = Form_Entry_Privacy::reveal_list( self::PLUGIN, $form_id, 'single' );
		$values  = self::decoded( $row['entry_data'] ?? null );
		$fields  = [];
		$total   = count( $values );
		foreach ( $form ? self::fields_of( $form ) : [] as $field ) {
			$id = isset( $field['id'] ) && is_scalar( $field['id'] ) ? (string) $field['id'] : '';
			if ( '' === $id || ! array_key_exists( $id, $values ) ) {
				continue;
			}
			if ( count( $fields ) < self::MAX_FIELDS ) {
				$type  = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'unknown';
				$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
				$also  = array_values( array_filter( [ $field['placeholder'] ?? '', $field['admin_label'] ?? '', $field['name'] ?? '', $field['description'] ?? '', $field['help_text'] ?? '', $field['help'] ?? '' ], 'is_string' ) );
				$one   = Form_Entry_Privacy::field( $id, $label, $type, $values[ $id ], $reveal, false, $also );
				if ( ! $one['masked'] ) {
					$one['value'] = 'file' === $type ? self::file_links( $one['value'] ) : self::bounded( $one['value'], Form_Entry_Privacy::reveals_field( $reveal, $id, $type ) );
				}
				$fields[] = self::tidy_field( $one );
			}
			unset( $values[ $id ] );
		}
		// Values the form no longer lists: their type is unknown, so they are masked.
		foreach ( $values as $id => $value ) {
			if ( '' !== (string) $id && count( $fields ) < self::MAX_FIELDS ) {
				$fields[] = self::tidy_field( Form_Entry_Privacy::field( (string) $id, '', 'unknown', $value, $reveal ) );
			}
		}
		$out = [
			'entry_id'    => $entry_id,
			'form_id'     => $form_id,
			'form_title'  => $form ? SafeText::field( (string) ( $form['title'] ?? '' ) ) : null,
			'status'      => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'is_read'     => ! empty( $row['is_read'] ),
			'is_starred'  => ! empty( $row['is_starred'] ),
			'user_id'     => Form_Entry_Privacy::user_id( $row['user_id'] ?? 0, $reveal ),
			// Written by the database server's clock, returned as stored.
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
			'field_count' => count( $fields ),
			// True when the entry holds more answers than one reply carries.
			'truncated'   => $total > count( $fields ),
			'masked'      => count( array_filter( array_column( $fields, 'masked' ) ) ),
			'fields'      => $fields,
		];
		$shown = Form_Entry_Privacy::submitter( [ 'ip' => isset( $row['ip_address'] ) ? (string) $row['ip_address'] : null, 'browser' => isset( $row['user_agent'] ) ? (string) $row['user_agent'] : null ], $reveal );
		if ( $shown ) {
			$out['submitter'] = $shown;
		}
		return Envelope::success( sprintf( 'Entry %d of form %d: %d field(s), %d masked as personal data.', $entry_id, $form_id, $out['field_count'], $out['masked'] ), $out );
	}

	/** A field's name, label and type as plain, short text; a name that is an email address is replaced. */
	private static function tidy_field( array $one ) {
		$one['name']  = mb_substr( Form_Entry_Privacy::hide_emails( SafeText::field( (string) $one['name'] ), false ), 0, 100 );
		$one['label'] = mb_substr( SafeText::field( (string) $one['label'] ), 0, 200 );
		$one['type']  = mb_substr( sanitize_key( (string) $one['type'] ), 0, 40 );
		return $one;
	}

	/**
	 * An answer cut to what a reply should carry. Unless the site let the field through by name,
	 * the names inside a list answer have email addresses replaced and long bare numbers are hidden,
	 * since a phone number can be stored as a number.
	 */
	private static function bounded( $value, $named = false, $depth = 0 ) {
		if ( is_string( $value ) ) {
			return mb_strlen( $value ) > self::MAX_VALUE_CHARS ? mb_substr( $value, 0, self::MAX_VALUE_CHARS ) . '…' : $value;
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && ! $named && strlen( preg_replace( '/\D/', '', (string) $value ) ) >= 7 ) {
			return '[number hidden]';
		}
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : null;
		}
		if ( $depth > 3 || strlen( (string) wp_json_encode( $value ) ) > 4 * self::MAX_VALUE_CHARS ) {
			return '[answer too large to return]';
		}
		$out = [];
		foreach ( $value as $k => $v ) {
			$k         = is_string( $k ) && ! $named ? Form_Entry_Privacy::hide_emails( $k ) : $k;
			$out[ $k ] = self::bounded( $v, $named, $depth + 1 );
		}
		return $out;
	}

	/**
	 * A file answer as FormForge's own expiring links. Each stored address is signed when it is one
	 * of FormForge's uploads; any other text is returned only when it is an http or https address.
	 */
	private static function file_links( $value, $depth = 0 ) {
		if ( is_string( $value ) ) {
			$link = is_callable( [ 'FormForge_Files', 'link' ] ) ? (string) \FormForge_Files::link( $value, self::FILE_LINK_DAYS ) : $value;
			return strlen( $link ) <= 2000 ? esc_url_raw( $link, [ 'http', 'https' ] ) : '';
		}
		if ( ! is_array( $value ) || $depth > 2 ) {
			return null;
		}
		$out = [];
		foreach ( array_slice( $value, 0, 50, true ) as $k => $one ) {
			$out[ $k ] = ( is_string( $one ) && ( is_int( $k ) || in_array( $k, [ 'url', 'file', 'path' ], true ) ) ) || is_array( $one ) ? self::file_links( $one, $depth + 1 ) : ( is_scalar( $one ) ? mb_substr( SafeText::field( (string) $one ), 0, 200 ) : null );
		}
		return $out;
	}

	private static function get_entry_counts( array $args ) {
		self::require_cap();
		global $wpdb;
		$form_id = 0;
		if ( isset( $args['form_id'] ) ) {
			$form_id = Form_Entry_Privacy::positive_int( $args['form_id'] );
			if ( $form_id <= 0 ) {
				return Envelope::error( 'invalid_args', 'form_id must be a positive whole number.' );
			}
			if ( ! self::form( $form_id ) ) {
				return Envelope::error( 'not_found', 'FormForge form not found.' );
			}
		}
		$since = false;
		if ( isset( $args['since'] ) ) {
			$since = is_string( $args['since'] ) && strlen( $args['since'] ) <= 40 ? strtotime( $args['since'] ) : false;
			if ( false === $since || $since <= 0 || $since > time() ) {
				return Envelope::error( 'invalid_args', 'since must be a past date, for example 2026-10-01 or "30 days ago".' );
			}
		}
		$range = [ 'date_from' => '', 'date_to' => '' ];
		foreach ( array_keys( $range ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
				$value = $args[ $key ];
				if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return Envelope::error( 'invalid_args', $key . ' must be a real date as YYYY-MM-DD.' );
				}
				$range[ $key ] = $value;
			}
		}
		if ( '' !== $range['date_from'] && '' !== $range['date_to'] && $range['date_from'] > $range['date_to'] ) {
			return Envelope::error( 'invalid_args', 'date_from must not be after date_to.' );
		}
		$by_day_on = isset( $args['by_day'] ) && in_array( $args['by_day'], [ true, 1, '1', 'true' ], true );
		if ( isset( $args['by_day'] ) && ! $by_day_on && ! in_array( $args['by_day'], [ false, 0, '0', 'false', '' ], true ) ) {
			return Envelope::error( 'invalid_args', 'by_day must be true or false.' );
		}
		$table = \FormForge_Database::get_entries_table();
		$where = [ '1=1' ];
		$vals  = [];
		if ( $form_id > 0 ) {
			$where[] = 'form_id = %d';
			$vals[]  = $form_id;
		}
		if ( '' !== $range['date_from'] ) {
			$where[] = 'created_at >= %s';
			$vals[]  = $range['date_from'] . ' 00:00:00';
		}
		if ( '' !== $range['date_to'] ) {
			$where[] = 'created_at <= %s';
			$vals[]  = $range['date_to'] . ' 23:59:59';
		}
		if ( false !== $since ) {
			// Entry times are written by the database clock, so the window is measured on it too.
			$where[] = 'created_at >= DATE_SUB( NOW(), INTERVAL %d SECOND )';
			$vals[]  = max( 0, time() - (int) $since );
		}
		$where_sql = implode( ' AND ', $where );
		$max       = self::MAX_COUNT_FORMS;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from FormForge; values bound; ids cast to int
		$sum_sql = "SELECT COUNT(*) AS n, COUNT(DISTINCT form_id) AS forms FROM {$table} WHERE {$where_sql}";
		$sums    = (array) $wpdb->get_row( $vals ? $wpdb->prepare( $sum_sql, $vals ) : $sum_sql, ARRAY_A );
		$top_sql = "SELECT form_id FROM {$table} WHERE {$where_sql} GROUP BY form_id ORDER BY COUNT(*) DESC, form_id ASC LIMIT {$max}";
		$ids     = array_map( 'intval', (array) $wpdb->get_col( $vals ? $wpdb->prepare( $top_sql, $vals ) : $top_sql ) );
		$rows    = [];
		if ( $ids ) {
			$sql  = "SELECT form_id, status, COUNT(*) AS n, MAX(created_at) AS last_at FROM {$table} WHERE {$where_sql} AND form_id IN (" . implode( ',', $ids ) . ') GROUP BY form_id, status';
			$rows = (array) $wpdb->get_results( $vals ? $wpdb->prepare( $sql, $vals ) : $sql, ARRAY_A );
		}
		// phpcs:enable
		$byform = array_fill_keys( $ids, null );
		foreach ( $rows as $r ) {
			$id = (int) $r['form_id'];
			if ( ! isset( $byform[ $id ] ) ) {
				$form          = self::form( $id );
				$byform[ $id ] = [ 'form_id' => $id, 'title' => $form ? SafeText::field( (string) ( $form['title'] ?? '' ) ) : '', 'form_exists' => (bool) $form, 'total' => 0, 'by_status' => [], 'last_entry_at' => null ];
			}
			$status = sanitize_key( (string) $r['status'] );
			$byform[ $id ]['by_status'][ '' === $status ? 'none' : $status ] = (int) $r['n'] + ( $byform[ $id ]['by_status'][ '' === $status ? 'none' : $status ] ?? 0 );
			$byform[ $id ]['total']                                         += (int) $r['n'];
			if ( null === $byform[ $id ]['last_entry_at'] || (string) $r['last_at'] > $byform[ $id ]['last_entry_at'] ) {
				$byform[ $id ]['last_entry_at'] = (string) $r['last_at'];
			}
		}
		$byform = array_filter( $byform );
		$list  = array_values( $byform );
		$total = (int) ( $sums['n'] ?? 0 );
		$forms = (int) ( $sums['forms'] ?? 0 );
		// forms holds the forms with the most entries; total and forms_total cover every form.
		$out   = [ 'forms' => $list, 'total' => $total, 'forms_total' => $forms, 'truncated' => $forms > count( $list ), 'since' => false !== $since ? gmdate( 'c', (int) $since ) : null, 'date_from' => '' !== $range['date_from'] ? $range['date_from'] : null, 'date_to' => '' !== $range['date_to'] ? $range['date_to'] : null ];
		if ( $by_day_on ) {
			$day_sql = "SELECT DATE( created_at ) AS day, COUNT(*) AS n FROM {$table} WHERE {$where_sql} GROUP BY day ORDER BY day DESC LIMIT 366";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name from FormForge; values bound
			$days          = (array) $wpdb->get_results( $vals ? $wpdb->prepare( $day_sql, $vals ) : $day_sql, ARRAY_A );
			$out['by_day'] = array_map( static function ( $d ) { return [ 'date' => (string) $d['day'], 'total' => (int) $d['n'] ]; }, $days );
		}
		return Envelope::success( sprintf( '%d entr%s across %d form(s)%s.', $total, 1 === $total ? 'y' : 'ies', $forms, $forms > count( $list ) ? sprintf( '; the %d forms with the most entries are listed', count( $list ) ) : '' ), $out );
	}

	private static function get_analytics( array $args ) {
		self::require_cap();
		$form_id = Form_Entry_Privacy::positive_int( $args['form_id'] ?? null );
		if ( $form_id <= 0 ) {
			return Envelope::error( 'invalid_args', 'form_id must be a positive whole number.' );
		}
		$range = $args['date_range'] ?? '30d';
		if ( ! is_string( $range ) || ! in_array( $range, self::ANALYTICS_RANGES, true ) ) {
			return Envelope::error( 'invalid_args', 'date_range must be one of: ' . implode( ', ', self::ANALYTICS_RANGES ) . '.' );
		}
		$form = self::form( $form_id );
		if ( ! $form ) {
			return Envelope::error( 'not_found', 'FormForge form not found.' );
		}
		$analytics = \RFP_Analytics::instance()->get_form_analytics( $form_id, $range );
		if ( ! is_array( $analytics ) ) {
			return Envelope::error( 'not_supported', 'FormForge returned no analytics for this form.' );
		}
		$totals = isset( $analytics['totals'] ) && is_array( $analytics['totals'] ) ? $analytics['totals'] : [];
		$rows   = static function ( $list, array $ints, $max ) {
			$out = [];
			foreach ( array_slice( is_array( $list ) ? $list : [], 0, $max ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$one = [];
				foreach ( $row as $k => $v ) {
					$k         = sanitize_key( (string) $k );
					$one[ $k ] = in_array( $k, $ints, true ) ? (int) $v : SafeText::field( is_scalar( $v ) ? (string) $v : '' );
				}
				$out[] = $one;
			}
			return $out;
		};
		// A referrer is kept as its site only: paths and query strings can carry visitors' own data.
		$referrers = [];
		foreach ( $rows( $analytics['referrers'] ?? [], [ 'count' ], 10 ) as $ref ) {
			$host = wp_parse_url( (string) ( $ref['referrer'] ?? '' ), PHP_URL_HOST );
			$host = is_string( $host ) ? strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', $host ) ) : '';
			if ( '' !== $host ) {
				$referrers[ $host ] = (int) ( $ref['count'] ?? 0 ) + ( $referrers[ $host ] ?? 0 );
			}
		}
		arsort( $referrers );
		$ref_list = [];
		foreach ( $referrers as $from => $count ) {
			$ref_list[] = [ 'referrer' => substr( $from, 0, 100 ), 'count' => $count ];
		}
		$devices = [];
		foreach ( is_array( $analytics['devices'] ?? null ) ? $analytics['devices'] : [] as $k => $v ) {
			if ( is_scalar( $v ) && count( $devices ) < 10 ) {
				$devices[ sanitize_key( (string) $k ) ] = (int) $v;
			}
		}
		// Without its tracking table FormForge can only count submissions, and says so in a note.
		$tracked     = ! isset( $analytics['note'] );
		$views       = $tracked ? (int) ( $totals['views'] ?? 0 ) : null;
		$submissions = (int) ( $totals['submissions'] ?? 0 );
		$daily_all   = is_array( $analytics['daily'] ?? null ) ? $analytics['daily'] : [];
		return Envelope::success(
			$tracked ? sprintf( 'Form %d, %s: %d view(s), %d submission(s).', $form_id, 'all' === $range ? 'all time' : 'last ' . $range, $views, $submissions ) : sprintf( 'Form %d, %s: %d submission(s). Views are not tracked on this site, so there is no view count or conversion rate.', $form_id, 'all' === $range ? 'all time' : 'last ' . $range, $submissions ),
			[
				'form_id'         => $form_id,
				'form_title'      => SafeText::field( (string) ( $form['title'] ?? '' ) ),
				'date_range'      => $range,
				'views_tracked'   => $tracked,
				'views'           => $views,
				'submissions'     => $submissions,
				'conversion_rate' => $tracked ? (float) ( $totals['conversion_rate'] ?? 0 ) : null,
				// Oldest first; when there are more than 400 days the newest 400 are kept.
				'daily'           => $rows( array_slice( $daily_all, -400 ), [ 'views', 'submissions' ], 400 ),
				'daily_truncated' => count( $daily_all ) > 400,
				'hourly'          => $rows( $analytics['hourly'] ?? [], [ 'hour', 'count' ], 24 ),
				'referrers'       => $ref_list,
				'devices'         => $devices ? $devices : new \stdClass(),
			]
		);
	}

	// ------------------------------------------------------------------
	// New forms
	// ------------------------------------------------------------------

	/** The title argument as plain text, or the default. null when the argument is not usable text. */
	private static function title_arg( array $args, $key, $default ) {
		if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
			return mb_substr( SafeText::field( (string) $default ), 0, self::MAX_TITLE );
		}
		if ( ! is_string( $args[ $key ] ) || mb_strlen( $args[ $key ] ) > self::MAX_TITLE ) {
			return null;
		}
		// Characters that change the reading direction of what follows are taken out of a title.
		$title = trim( (string) preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', SafeText::field( $args[ $key ] ) ) );
		return '' === $title ? null : $title;
	}

	/** Whether $new only changes what $old already has: the same kind of value, and no new names at any depth. */
	private static function same_shape( $old, $new, $depth = 0 ) {
		if ( is_array( $old ) !== is_array( $new ) || $depth > 6 ) {
			return false;
		}
		if ( ! is_array( $old ) ) {
			// Text, numbers and switches may replace one another (FormForge stores "1" and true alike); nothing else may.
			return is_scalar( $new ) || null === $new;
		}
		$old_list = ! $old || array_keys( $old ) === range( 0, count( $old ) - 1 );
		$new_list = ! $new || array_keys( $new ) === range( 0, count( $new ) - 1 );
		if ( $old_list && $new_list ) {
			// A plain list (choices, recipients): entries must be of the kind the list already holds.
			foreach ( $new as $item ) {
				if ( is_array( $item ) && ( ! $old || ! is_array( reset( $old ) ) || ! self::same_shape( reset( $old ), $item, $depth + 1 ) ) ) {
					return false;
				}
			}
			return true;
		}
		if ( $old_list !== $new_list ) {
			return false;
		}
		foreach ( $new as $name => $value ) {
			if ( ! array_key_exists( $name, $old ) || ! self::same_shape( $old[ $name ], $value, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Settings to lay over the form's own. A call can change the value of a setting the form already
	 * has, at any depth, and cannot add a setting or change what kind of value one holds. The values
	 * themselves (a recipient, a web address) are the caller's to choose, as in FormForge's own screen.
	 * null when the argument is unusable.
	 */
	private static function setting_overrides( array $args, array $base ) {
		if ( ! isset( $args['setting_overrides'] ) ) {
			return [];
		}
		$over = json_decode( (string) wp_json_encode( $args['setting_overrides'] ), true );
		$json = is_array( $over ) ? wp_json_encode( $over ) : false;
		if ( ! is_array( $over ) || false === $json || strlen( $json ) > self::MAX_SETTINGS_BYTES ) {
			return null;
		}
		foreach ( $over as $name => $value ) {
			if ( ! is_string( $name ) || ! array_key_exists( $name, $base ) || ! self::same_shape( $base[ $name ], $value ) ) {
				return null;
			}
		}
		return self::clean_setting_values( $over );
	}

	/**
	 * Override values cleaned the way FormForge's own settings screen cleans them: message HTML
	 * through wp_kses_post for every user, recipients and sender addresses
	 * through sanitize_email (smart tags such as {admin_email} kept), web addresses through
	 * esc_url_raw, other text as plain text.
	 */
	private static function clean_setting_values( array $over, $depth = 0 ) {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) ) {
				$over[ $key ] = $depth < 6 ? self::clean_setting_values( $value, $depth + 1 ) : [];
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$k = is_string( $key ) ? strtolower( $key ) : '';
			if ( 'message' === $k || 'content' === $k || 'html' === $k ) {
				$over[ $key ] = wp_kses_post( $value );
			} elseif ( in_array( $k, [ 'email', 'to', 'cc', 'bcc', 'recipients' ], true ) ) {
				$over[ $key ] = self::clean_recipients( $value );
			} elseif ( 'from_email' === $k || 'reply_to' === $k || 'replyto' === $k ) {
				$over[ $key ] = self::is_smart_tag( $value ) ? $value : ( false !== strpos( $value, '@' ) ? sanitize_email( $value ) : sanitize_text_field( $value ) );
			} elseif ( 'url' === $k || '_url' === substr( $k, -4 ) || 'redirect' === $k ) {
				$over[ $key ] = esc_url_raw( trim( $value ), [ 'http', 'https' ] );
			} elseif ( false !== strpos( $k, 'message' ) ) {
				$over[ $key ] = sanitize_textarea_field( $value );
			} else {
				$over[ $key ] = sanitize_text_field( $value );
			}
		}
		return $over;
	}

	private static function is_smart_tag( $value ) {
		return 1 === preg_match( '/^\{[a-z0-9_:\-="\' ]{1,60}\}$/i', trim( (string) $value ) );
	}

	/** A comma separated recipients text with only valid addresses and smart tags left. */
	private static function clean_recipients( $value ) {
		$out = [];
		foreach ( explode( ',', (string) $value ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( self::is_smart_tag( $part ) ) {
				$out[] = $part;
			} elseif ( is_email( $part ) ) {
				$out[] = sanitize_email( $part );
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Settings with the overrides laid over them. Lists of entries that carry an id (webhooks) are
	 * matched by id; any other list given is the whole new list, so nothing left over from the
	 * original stays behind by position.
	 */
	private static function merge_settings( array $base, array $over, $depth = 0 ) {
		foreach ( $over as $key => $value ) {
			$old = $base[ $key ] ?? null;
			if ( ! is_array( $value ) || ! is_array( $old ) || $depth > 6 ) {
				$base[ $key ] = $value;
				continue;
			}
			$old_list = array_keys( $old ) === range( 0, count( $old ) - 1 );
			$new_list = ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $old_list && $new_list ) {
				$by_id = (bool) $old && ! array_filter( $old, static function ( $e ) { return ! is_array( $e ) || ! isset( $e['id'] ) || ! is_scalar( $e['id'] ); } );
				if ( $by_id && ! array_filter( $value, static function ( $e ) { return ! is_array( $e ) || ! isset( $e['id'] ) || ! is_scalar( $e['id'] ); } ) ) {
					foreach ( $value as $entry ) {
						foreach ( $old as $i => $was ) {
							if ( (string) $was['id'] === (string) $entry['id'] ) {
								$old[ $i ] = self::merge_settings( $was, $entry, $depth + 1 );
								continue 2;
							}
						}
					}
					$base[ $key ] = $old;
				} else {
					$base[ $key ] = $value;
				}
				continue;
			}
			$base[ $key ] = self::merge_settings( $old, $value, $depth + 1 );
		}
		return $base;
	}

	private static function duplicate( array $args ) {
		self::require_cap();
		$source_id = Form_Entry_Privacy::positive_int( $args['source_form_id'] ?? null );
		if ( $source_id <= 0 ) {
			return Envelope::error( 'invalid_args', 'source_form_id must be a positive whole number.' );
		}
		$source = self::form( $source_id );
		if ( ! $source ) {
			return Envelope::error( 'not_found', 'FormForge form not found.' );
		}
		$status = $args['status_override'] ?? 'inactive';
		if ( ! is_string( $status ) || ! in_array( $status, self::FORM_STATUSES, true ) ) {
			return Envelope::error( 'invalid_args', 'status_override must be one of: ' . implode( ', ', self::FORM_STATUSES ) . '.' );
		}
		$title = self::title_arg( $args, 'title_override', mb_substr( (string) ( $source['title'] ?? 'Untitled' ), 0, self::MAX_TITLE - 7 ) . ' (Copy)' );
		if ( null === $title ) {
			return Envelope::error( 'invalid_args', 'title_override must be text of at most 255 characters.' );
		}
		$settings = self::decoded( $source['settings'] ?? null );
		$over     = self::setting_overrides( $args, $settings );
		if ( null === $over ) {
			return Envelope::error( 'invalid_args', 'setting_overrides may only change settings the source form already has, keeping each one\'s kind of value (at most 20 KB).', [ 'settings_of_source' => array_slice( array_map( 'strval', array_keys( $settings ) ), 0, 100 ) ] );
		}
		return self::create( 'formforge_duplicate', [
			'title'       => $title,
			'description' => is_string( $source['description'] ?? null ) ? $source['description'] : '',
			'form_data'   => self::decoded( $source['form_data'] ?? null ),
			'settings'    => self::merge_settings( $settings, $over ),
			'status'      => $status,
		], [ 'source' => 'duplicate', 'source_form_id' => $source_id ] );
	}

	private static function create_from_template( array $args ) {
		self::require_cap();
		$slug = isset( $args['template_slug'] ) && is_string( $args['template_slug'] ) ? sanitize_key( $args['template_slug'] ) : '';
		if ( '' === $slug || $slug !== $args['template_slug'] ) {
			return Envelope::error( 'invalid_args', 'template_slug must be a template name in lower-case letters, digits, hyphens or underscores.' );
		}
		$templates = \RFP_Templates::instance();
		$all       = (array) $templates->get_templates();
		$template  = isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : null;
		if ( ! $template ) {
			return Envelope::error( 'not_found', 'FormForge has no template with that name.', [ 'templates' => array_slice( array_map( 'strval', array_keys( $all ) ), 0, 100 ) ] );
		}
		if ( ! $templates->can_use_template( $slug ) ) {
			return Envelope::error( 'not_supported', 'This site\'s FormForge licence does not include that template.', [ 'required_tier' => sanitize_key( (string) ( $template['tier'] ?? 'pro' ) ) ] );
		}
		$status = $args['status'] ?? 'inactive';
		if ( ! is_string( $status ) || ! in_array( $status, self::FORM_STATUSES, true ) ) {
			return Envelope::error( 'invalid_args', 'status must be one of: ' . implode( ', ', self::FORM_STATUSES ) . '.' );
		}
		$title = self::title_arg( $args, 'title', (string) ( $template['name'] ?? ( $template['title'] ?? 'New form' ) ) );
		if ( null === $title ) {
			return Envelope::error( 'invalid_args', 'title must be text of at most 255 characters.' );
		}
		$settings = isset( $template['settings'] ) && is_array( $template['settings'] ) ? $template['settings'] : [];
		$over     = self::setting_overrides( $args, $settings );
		if ( null === $over ) {
			return Envelope::error( 'invalid_args', 'setting_overrides may only change settings the template already has, keeping each one\'s kind of value (at most 20 KB).', [ 'settings_of_template' => array_slice( array_map( 'strval', array_keys( $settings ) ), 0, 100 ) ] );
		}
		return self::create( 'formforge_create_from_template', [
			'title'       => $title,
			'description' => is_string( $template['description'] ?? null ) ? $template['description'] : '',
			'form_data'   => [ 'fields' => isset( $template['fields'] ) && is_array( $template['fields'] ) ? $template['fields'] : [] ],
			'settings'    => self::merge_settings( $settings, $over ),
			'status'      => $status,
		], [ 'source' => 'template', 'template_slug' => $slug ] );
	}

	/** What tells an untouched form from one edited after it was made. */
	private static function fingerprint( array $form ) {
		return md5( (string) wp_json_encode( [ (string) ( $form['title'] ?? '' ), (string) ( $form['description'] ?? '' ), self::decoded( $form['form_data'] ?? null ), self::decoded( $form['settings'] ?? null ), (string) ( $form['status'] ?? '' ) ] ) );
	}

	private static function create( $tool, array $data, array $extra ) {
		// FormForge keeps the status in the form's settings as well; the two must agree.
		if ( isset( $data['settings']['status'] ) ) {
			$data['settings']['status'] = $data['status'];
		}
		$new_id = (int) \FormForge_Database::insert_form( $data );
		$made   = $new_id > 0 ? self::form( $new_id ) : null;
		if ( ! $made ) {
			return Envelope::error( 'write_failed', 'FormForge did not save the new form.' );
		}
		$undo = \Royal_MCP\MCP\Undo_Store::store( [
			'op'           => $tool,
			'summary'      => sprintf( 'Delete FormForge form %d ("%s") made by this call', $new_id, $data['title'] ),
			'target'       => [ 'form_id' => $new_id ],
			'pre_op_state' => [ 'fingerprint' => self::fingerprint( $made ) ],
		] );
		return Envelope::success(
			sprintf( 'Created FormForge form %d ("%s"), status %s. Undo deletes it while it has no entries and has not been edited.', $new_id, $data['title'], $data['status'] ),
			[ 'created_form_id' => $new_id, 'title' => $data['title'], 'status' => $data['status'], 'field_count' => count( self::fields_of( $made ) ) ] + $extra,
			$undo ?: null
		);
	}

	/** Entries and unfinished submissions a form holds, by kind. */
	private static function holdings( $form_id ) {
		global $wpdb;
		$out = [ 'entries' => (int) \FormForge_Database::get_entries_count( $form_id, '' ) ];
		foreach ( [ 'saved_entries' => 'rfp_saved_entries', 'partial_entries' => 'rfp_partial_entries', 'analytics_rows' => 'rfp_analytics' ] as $key => $name ) {
			$table       = $wpdb->prefix . $name;
			$out[ $key ] = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the prefix and a fixed list
				$out[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d", (int) $form_id ) );
			}
		}
		return $out;
	}

	/**
	 * Undo of either create tool: delete the form it made. A form that has entries, or was edited
	 * since, is left in place unless forced, because deleting it takes those with it.
	 */
	public static function undo( array $snapshot, $force = false ) {
		self::require_cap();
		$form_id = (int) ( $snapshot['target']['form_id'] ?? 0 );
		$op      = (string) ( $snapshot['op'] ?? 'formforge_duplicate' );
		if ( $form_id <= 0 ) {
			throw new \Exception( 'Undo snapshot missing the form.' );
		}
		$target = [ 'form_id' => $form_id ];
		$form   = self::form( $form_id );
		if ( ! $form ) {
			return Envelope::success( sprintf( 'Form %d was already deleted; nothing to undo.', $form_id ), [ 'undone' => false, 'op' => $op, 'target' => $target, 'reason' => 'deleted_since_write' ] );
		}
		$held  = self::holdings( $form_id );
		// View statistics go with the form but are not something a visitor sent, so they do not hold an undo back.
		$count = (int) $held['entries'] + (int) $held['saved_entries'] + (int) $held['partial_entries'];
		$title = mb_substr( SafeText::field( (string) ( $form['title'] ?? '' ) ), 0, 100 );
		if ( $count > 0 && ! $force ) {
			throw new Tool_Refusal( 'has_entries', esc_html( sprintf( 'Form %d has received %d entr%s or unfinished submission(s) since it was made, so the undo left it in place. Deleting the form deletes them too; pass force: true to do that.', $form_id, $count, 1 === $count ? 'y' : 'ies' ) ) );
		}
		$was = (string) ( $snapshot['pre_op_state']['fingerprint'] ?? '' );
		if ( ! $force && ( '' === $was || ! hash_equals( $was, self::fingerprint( $form ) ) ) ) {
			throw new Tool_Refusal( 'edited_since_write', esc_html( sprintf( 'Form %d was edited after it was made, so the undo left it in place. Pass force: true to delete it anyway.', $form_id ) ) );
		}
		if ( ! \FormForge_Database::delete_form( $form_id ) ) {
			throw new \Exception( 'FormForge could not delete the form; nothing was undone.' );
		}
		return Envelope::success(
			sprintf( 'Deleted FormForge form %d ("%s")%s.', $form_id, $title, $count > 0 ? sprintf( ' with its %d entr%s and unfinished submission(s) (forced)', $count, 1 === $count ? 'y' : 'ies' ) : '' ),
			[ 'undone' => true, 'op' => $op, 'target' => $target + [ 'title' => $title ], 'deleted' => $held ]
		);
	}

	// ------------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------------

	private static function schemas() {
		$id = static function ( $what ) {
			return [ 'type' => 'integer', 'minimum' => 1, 'description' => $what ];
		};
		return [
			'formforge_list_forms' => [
				'name'        => 'formforge_list_forms',
				'description' => 'List FormForge forms: forms [{id, title, status, field_count, entries (active), created_at, updated_at}]. status filters by form status (all by default; active is the status FormForge accepts entries for). Page with limit (up to 100) and offset. Read-only. Requires FormForge Pro and manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'status' => [ 'type' => 'string', 'description' => 'all (default), or a form status such as active.' ],
						'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
						'offset' => [ 'type' => 'integer', 'minimum' => 0 ],
					],
					'additionalProperties' => false,
				],
			],
			'formforge_get_entry' => [
				'name'        => 'formforge_get_entry',
				'description' => 'Get one FormForge entry: fields [{name, label, type, value, masked}], its form, status, read and starred flags and the time it was stored. Fields that hold personal data (email, phone, names, addresses, files, hidden values and the like) come back with value null and masked true, and email addresses and phone numbers inside other answers are replaced, unless the site lets them through with the royal_mcp_form_entry_fields filter; the sender\'s IP address and browser are left out the same way. An uploaded file that is let through is given as a link that works for about a week (7 to 8 days). An entry with more than 200 answers is cut and marked truncated; a long answer is cut at 2,000 characters. Read-only. Requires FormForge Pro and manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'required'             => [ 'entry_id' ],
					'properties'           => [ 'entry_id' => $id( 'The entry ID.' ) ],
					'additionalProperties' => false,
				],
			],
			'formforge_get_entry_counts' => [
				'name'        => 'formforge_get_entry_counts',
				'description' => 'Count FormForge entries (leads): forms [{form_id, title, total, by_status, last_entry_at}] and the overall total and forms_total, for one form (form_id) or all; with more than 200 forms the 200 with the most entries are listed and truncated is true. since counts back from now ("30 days ago"; a bare date means midnight UTC of that day); date_from and date_to (YYYY-MM-DD) are whole days on the database server\'s clock, the clock entries are stored on. by_day true adds by_day [{date, total}], newest first, up to 366 days. No entry contents. Read-only. Requires FormForge Pro and manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'form_id'   => $id( 'Count one form only.' ),
						'since'     => [ 'type' => 'string', 'description' => 'A past date or a phrase such as "30 days ago".' ],
						'date_from' => [ 'type' => 'string', 'description' => 'YYYY-MM-DD.' ],
						'date_to'   => [ 'type' => 'string', 'description' => 'YYYY-MM-DD.' ],
						'by_day'    => [ 'type' => 'boolean', 'description' => 'Add totals per day.' ],
					],
					'additionalProperties' => false,
				],
			],
			'formforge_get_analytics' => [
				'name'        => 'formforge_get_analytics',
				'description' => 'Get FormForge\'s analytics for one form: views, submissions, conversion_rate, daily [{date, views, submissions}], hourly [{hour, count}], referrers [{referrer, count}] (the referring sites among FormForge\'s top 10 referrers) and devices. date_range is 7d, 30d (default), 90d or all. Days and hours are on the database server\'s clock; daily is oldest first and keeps the newest 400 days (daily_truncated says when it was cut). When views_tracked is false FormForge is not recording views on this site: views and conversion_rate are null and only submissions are counted. A form that has not been shown since tracking began gives zeros. Read-only. Requires FormForge Pro and manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'required'             => [ 'form_id' ],
					'properties'           => [
						'form_id'    => $id( 'The form ID.' ),
						'date_range' => [ 'type' => 'string', 'enum' => self::ANALYTICS_RANGES, 'description' => 'Time window. Default 30d.' ],
					],
					'additionalProperties' => false,
				],
			],
			'formforge_duplicate' => [
				'name'        => 'formforge_duplicate',
				'description' => 'Make a copy of a FormForge form: same fields, description and settings (notifications and integrations included, so a copy set to active sends the same emails as the original). The copy is inactive unless status_override says otherwise, and is titled "<title> (Copy)" unless title_override is given. setting_overrides changes the values of settings the source form already has, at any depth; it cannot add a setting or change what kind of value one holds. Values are cleaned as FormForge\'s settings screen cleans them (message HTML filtered, invalid recipient addresses dropped, web addresses checked); a list given (notifications) replaces the whole list, while webhooks are matched by id. Entries are not copied. Returns created_form_id and an undo token; undo deletes the copy, with its view statistics, while it has no entries and has not been edited (force: true deletes it with its entries). Requires FormForge Pro and manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'required'             => [ 'source_form_id' ],
					'properties'           => [
						'source_form_id'    => $id( 'The form to copy.' ),
						'title_override'    => [ 'type' => 'string', 'maxLength' => 255 ],
						'status_override'   => [ 'type' => 'string', 'enum' => self::FORM_STATUSES, 'description' => 'Default inactive.' ],
						'setting_overrides' => [ 'type' => 'object', 'description' => 'Settings to change in the copy; only names the source form already has.' ],
					],
					'additionalProperties' => false,
				],
			],
			'formforge_create_from_template' => [
				'name'        => 'formforge_create_from_template',
				'description' => 'Make a new FormForge form from one of FormForge\'s own templates (template_slug, such as contact or newsletter; an unknown name returns the list). The form is inactive unless status says otherwise and takes the template\'s name unless title is given. setting_overrides changes the values of settings the template already has; it cannot add one. Values are cleaned as FormForge\'s settings screen cleans them, and a list given replaces the whole list. A template the site\'s FormForge licence does not include is refused. Returns created_form_id and an undo token; undo deletes the form while it has no entries and has not been edited (force: true deletes it with its entries). Requires FormForge Pro and manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'required'             => [ 'template_slug' ],
					'properties'           => [
						'template_slug'     => [ 'type' => 'string', 'description' => 'The template\'s name in FormForge.' ],
						'title'             => [ 'type' => 'string', 'maxLength' => 255 ],
						'status'            => [ 'type' => 'string', 'enum' => self::FORM_STATUSES, 'description' => 'Default inactive.' ],
						'setting_overrides' => [ 'type' => 'object', 'description' => 'Settings to change; only names the template already has.' ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}
}
