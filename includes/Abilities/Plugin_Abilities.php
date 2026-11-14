<?php
/**
 * Other plugins' WordPress abilities as MCP tools on Royal MCP's endpoint.
 *
 * Off until the site owner turns on a plugin's ability namespace, or single
 * abilities from it. Each
 * bridged ability is listed as an ext_* tool labelled third-party, runs its
 * own permission check as the connected user, and passes the same read-only,
 * drafts-only, access-level and endpoint-profile rules as every other tool.
 * Royal MCP has no undo for them.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin_Abilities {

	const OPTION = 'royal_mcp_bridged_namespaces';
	/** Single abilities turned on without their whole namespace. */
	const ABILITIES_OPTION = 'royal_mcp_bridged_abilities';
	/** Abilities that can change the site, each allowed on its own. */
	const WRITES_OPTION = 'royal_mcp_bridged_writes';
	/** Per turned-on plugin: the abilities it had when the owner last saved. */
	const KNOWN_OPTION = 'royal_mcp_bridged_known';
	/** Largest bridged result returned in full (bytes of JSON). */
	const MAX_RESULT_BYTES = 262144;
	/** Deepest nesting returned in a bridged result. */
	const MAX_RESULT_DEPTH = 32;
	/** Largest result that is checked for credentials at all (bytes of JSON); anything larger is not returned. */
	const MAX_CHECKED_BYTES = 4194304;
	/** Longest ability description passed on in a tool description. */
	const MAX_DESCRIPTION_CHARS = 1000;
	const TOOL_NAME_START = 'ext_';

	/** Namespaces never bridged: our own, and the adapter's dispatcher. */
	const NEVER = [ 'royal-mcp', 'mcp-adapter' ];

	public static function register() {
		add_filter( 'royal_mcp_tools', [ __CLASS__, 'add_tools' ], 25 );
		add_filter( 'royal_mcp_execute_tool_external', [ __CLASS__, 'run' ], 10, 3 );
		add_filter( 'royal_mcp_call_reads', [ __CLASS__, 'call_reads' ], 10, 2 );
	}

	/** Tells the server, call by call, that a bridged ability which says it only reads is a read. */
	public static function call_reads( $reads, $name ) {
		if ( $reads || 0 !== strpos( (string) $name, self::TOOL_NAME_START ) ) {
			return $reads;
		}
		$map = self::bridged();
		return isset( $map[ $name ] ) && self::ability_reads( $map[ $name ] );
	}

	/** Whether another Royal MCP component already offers these tools, so they are not listed twice. */
	public static function handled_elsewhere() {
		return class_exists( '\\Royal_MCP_Pro\\Third_Party_Abilities', false );
	}

	private static function has_admin_caps() {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/** @return string[] Namespaces the owner turned on. */
	public static function enabled_namespaces() {
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			return [];
		}
		return array_values( array_diff( array_filter( array_map( [ __CLASS__, 'sanitize_namespace' ], $saved ) ), self::NEVER ) );
	}

	public static function save_namespaces( array $namespaces ) {
		$clean = array_values( array_unique( array_diff( array_filter( array_map( [ __CLASS__, 'sanitize_namespace' ], $namespaces ) ), self::NEVER ) ) );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/** @return string[] Single abilities the owner turned on, by full name. */
	public static function enabled_abilities() {
		$saved = get_option( self::ABILITIES_OPTION, [] );
		if ( ! is_array( $saved ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( [ __CLASS__, 'sanitize_ability_name' ], $saved ) ) ) );
	}

	public static function save_abilities( array $names ) {
		$clean = array_values( array_unique( array_filter( array_map( [ __CLASS__, 'sanitize_ability_name' ], $names ) ) ) );
		update_option( self::ABILITIES_OPTION, $clean, false );
		return $clean;
	}

	/** A full ability name (namespace/name), or '' for anything else, our own and dispatchers included. */
	public static function sanitize_ability_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		if ( ! preg_match( '#^([a-z0-9][a-z0-9-]{0,63})/[a-z0-9][a-z0-9-]{0,127}$#', $name, $m ) ) {
			return '';
		}
		if ( in_array( $m[1], self::NEVER, true ) || self::is_dispatcher( $name ) ) {
			return '';
		}
		return $name;
	}

	/** Abilities allowed one by one although they can change the site. */
	public static function enabled_writes() {
		$saved = get_option( self::WRITES_OPTION, [] );
		if ( ! is_array( $saved ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( [ __CLASS__, 'sanitize_ability_name' ], $saved ) ) ) );
	}

	/**
	 * Abilities each turned-on plugin had when the owner last saved.
	 *
	 * @return array<string, string[]>
	 */
	public static function known_abilities() {
		$saved = get_option( self::KNOWN_OPTION, [] );
		if ( ! is_array( $saved ) ) {
			return [];
		}
		$out = [];
		foreach ( $saved as $ns => $names ) {
			$ns = self::sanitize_namespace( $ns );
			if ( '' !== $ns && is_array( $names ) ) {
				$out[ $ns ] = array_values( array_unique( array_filter( array_map( [ __CLASS__, 'sanitize_ability_name' ], $names ) ) ) );
			}
		}
		return $out;
	}

	/**
	 * Save the owner's choice. A turned-on plugin covers the read-only
	 * abilities it registers right now; abilities that can change the site
	 * are on only when listed in $writes; $reads are single read-only
	 * abilities of plugins that are not turned on as a whole.
	 *
	 * @param string[] $namespaces Plugins turned on.
	 * @param string[] $reads      Single read-only abilities.
	 * @param string[] $writes     Abilities that can change the site.
	 * @return array{namespaces: string[], reads: string[], writes: string[]}
	 */
	public static function save_selection( array $namespaces, array $reads, array $writes ) {
		$available  = self::available();
		$namespaces = self::save_namespaces( array_values( array_intersect( array_map( [ __CLASS__, 'sanitize_namespace' ], $namespaces ), array_keys( $available ) ) ) );
		$reads      = array_map( [ __CLASS__, 'sanitize_ability_name' ], $reads );
		$writes     = array_map( [ __CLASS__, 'sanitize_ability_name' ], $writes );
		$known      = [];
		$reads_ok   = [];
		$writes_ok  = [];
		foreach ( $available as $ns => $group ) {
			$ns_on = in_array( $ns, $namespaces, true );
			if ( $ns_on ) {
				$known[ $ns ] = [];
			}
			foreach ( array_keys( $group['abilities'] ) as $name ) {
				$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
				if ( ! $ability ) {
					continue;
				}
				if ( self::ability_reads( $ability ) ) {
					// Remembered as a read: an ability that starts to call itself read-only later is new, and stays off.
					if ( $ns_on ) {
						$known[ $ns ][] = $name;
					}
					if ( ! $ns_on && in_array( $name, $reads, true ) ) {
						$reads_ok[] = $name;
					}
				} elseif ( in_array( $name, $writes, true ) ) {
					$writes_ok[] = $name;
				}
			}
		}
		// Keep the order the owner gave.
		$reads_ok  = array_values( array_unique( array_intersect( $reads, $reads_ok ) ) );
		$writes_ok = array_values( array_unique( array_intersect( $writes, $writes_ok ) ) );
		update_option( self::KNOWN_OPTION, $known, false );
		update_option( self::WRITES_OPTION, $writes_ok, false );
		return [
			'namespaces' => $namespaces,
			'reads'      => self::save_abilities( $reads_ok ),
			'writes'     => self::enabled_writes(),
		];
	}

	/** Whether $name belongs to a turned-on plugin but arrived after the owner last saved. */
	public static function is_new_since_saved( $name ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $name ) : null;
		if ( ! $ability || ! self::ability_reads( $ability ) ) {
			return false;
		}
		$ns    = (string) strtok( (string) $name, '/' );
		$known = self::known_abilities();
		return in_array( $ns, self::enabled_namespaces(), true ) && ! in_array( (string) $name, $known[ $ns ] ?? [], true );
	}

	/** Whether an ability says it only reads. */
	public static function ability_reads( $ability ) {
		return ! empty( self::annotations( $ability )['readOnlyHint'] );
	}

	public static function sanitize_namespace( $ns ) {
		$ns = strtolower( trim( (string) $ns ) );
		return preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $ns ) ? $ns : '';
	}

	/** An ability that runs other abilities is never bridged, or the opt-in would mean nothing. */
	private static function is_dispatcher( $name ) {
		$part = (string) substr( (string) strrchr( '/' . (string) $name, '/' ), 1 );
		if ( in_array( $part, [ 'execute', 'run', 'call', 'invoke', 'dispatch' ], true ) ) {
			return true;
		}
		return false !== strpos( $part, 'abilit' ) && (bool) preg_match( '/(execute|run|call|invoke|dispatch)/', $part );
	}

	/**
	 * Third-party abilities grouped by namespace, for the settings page.
	 *
	 * @return array<string, array{label:string, abilities:array<string,string>}>
	 */
	public static function available() {
		$out = [];
		foreach ( self::all_abilities() as $ability ) {
			$name = (string) $ability->get_name();
			$ns   = (string) strtok( $name, '/' );
			if ( '' === $ns || in_array( $ns, self::NEVER, true ) || self::is_dispatcher( $name ) ) {
				continue;
			}
			if ( ! isset( $out[ $ns ] ) ) {
				$out[ $ns ] = [ 'label' => self::namespace_label( $ns ), 'abilities' => [] ];
			}
			$out[ $ns ]['abilities'][ $name ] = (string) $ability->get_label();
		}
		ksort( $out );
		return $out;
	}

	private static function all_abilities() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}
		return array_filter( (array) wp_get_abilities(), static function ( $a ) {
			return is_object( $a ) && method_exists( $a, 'get_name' ) && method_exists( $a, 'execute' );
		} );
	}

	/**
	 * Royal MCP's own tools for the plugin behind a namespace, when it has them
	 * (for example wc_* for woocommerce), or ''.
	 */
	public static function own_tools_for( $ns ) {
		$overlap = \Royal_MCP\Integrations\Coexistence_Check::OVERLAPPING_ABILITIES[ self::sanitize_namespace( (string) $ns ) . '/' ] ?? null;
		return is_array( $overlap ) ? (string) $overlap['royal_mcp_tools'] : '';
	}

	public static function namespace_label( $ns ) {
		$known = [ 'rank-math' => 'Rank Math', 'core' => 'WordPress', 'elementor' => 'Elementor', 'woocommerce' => 'WooCommerce', 'yoast' => 'Yoast SEO', 'angie' => 'Angie' ];
		return $known[ $ns ] ?? ucwords( str_replace( '-', ' ', $ns ) );
	}

	/**
	 * Bridged abilities by tool name.
	 *
	 * @return array<string, object>
	 */
	public static function bridged() {
		if ( self::handled_elsewhere() ) {
			return [];
		}
		$enabled = self::enabled_namespaces();
		$singles = self::enabled_abilities();
		$writes  = self::enabled_writes();
		if ( ! $enabled && ! $singles && ! $writes ) {
			return [];
		}
		$known  = self::known_abilities();
		$out    = [];
		$shared = [];
		foreach ( self::all_abilities() as $ability ) {
			$plain            = self::tool_name( (string) $ability->get_name() );
			$shared[ $plain ] = ( $shared[ $plain ] ?? 0 ) + 1;
		}
		foreach ( self::all_abilities() as $ability ) {
			$name = (string) $ability->get_name();
			$ns   = (string) strtok( $name, '/' );
			if ( in_array( $ns, self::NEVER, true ) || self::is_dispatcher( $name ) ) {
				continue;
			}
			if ( self::ability_reads( $ability ) ) {
				$on = in_array( $name, $singles, true )
					|| ( in_array( $ns, $enabled, true ) && in_array( $name, $known[ $ns ] ?? [], true ) );
			} else {
				$on = in_array( $name, $writes, true );
			}
			if ( ! $on ) {
				continue;
			}
			$tool = self::tool_name( $name );
			if ( ( $shared[ $tool ] ?? 0 ) > 1 ) {
				// Abilities that read alike (rank-math/a-b, rank-math-a/b) each get a name tied to
				// their own ability name, whichever of them are turned on, so a name never changes hands.
				$tool = substr( $tool, 0, 55 ) . '_' . substr( md5( $name ), 0, 8 );
			}
			if ( ! isset( $out[ $tool ] ) ) {
				$out[ $tool ] = $ability;
			}
		}
		return $out;
	}

	/** rank-math/audit-site-seo → ext_rank_math_audit_site_seo (64 characters at most). */
	public static function tool_name( $ability_name ) {
		$tool = self::TOOL_NAME_START . trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $ability_name ) ), '_' );
		return strlen( $tool ) > 64 ? substr( $tool, 0, 55 ) . '_' . substr( md5( (string) $ability_name ), 0, 8 ) : $tool;
	}

	/** MCP annotations from the ability's own; anything it does not say is read as destructive. */
	public static function annotations( $ability ) {
		$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : [];
		$a    = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];
		// Only the boolean true counts, and an ability that also calls itself destructive is a change.
		$read = true === ( $a['readonly'] ?? null ) && true !== ( $a['destructive'] ?? null );
		return [
			'readOnlyHint'    => $read,
			'destructiveHint' => $read ? false : ( isset( $a['destructive'] ) ? (bool) $a['destructive'] : true ),
			'idempotentHint'  => ! empty( $a['idempotent'] ),
		];
	}

	/** Annotations for an ext_ tool name; null when it is not a bridged ability. */
	public static function annotations_for_tool( $tool ) {
		$map = self::bridged();
		return isset( $map[ $tool ] ) ? self::annotations( $map[ $tool ] ) : null;
	}

	/**
	 * The tool's input schema and how arguments map to the ability's input:
	 * none (the ability takes no input), object (passed as is) or wrapped (a
	 * non-object input passed as the tool's input argument).
	 */
	private static function input_schema( $ability ) {
		$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : [];
		if ( ! is_array( $schema ) || ! $schema ) {
			return [ [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ], 'none' ];
		}
		if ( 'object' === ( $schema['type'] ?? 'object' ) ) {
			$schema['type'] = 'object';
			if ( empty( $schema['properties'] ) ) {
				$schema['properties'] = new \stdClass();
			}
			return [ $schema, 'object' ];
		}
		return [ [ 'type' => 'object', 'properties' => [ 'input' => $schema ], 'required' => [ 'input' ], 'additionalProperties' => false ], 'wrapped' ];
	}

	public static function add_tools( $tools ) {
		// Our own abilities are registered from this list; third-party
		// abilities are not re-registered under our namespace.
		if ( ! is_array( $tools ) || doing_action( 'wp_abilities_api_init' ) || ! self::has_admin_caps() ) {
			return $tools;
		}
		$taken = array_flip( array_filter( array_column( $tools, 'name' ), 'is_string' ) );
		foreach ( self::bridged() as $tool => $ability ) {
			list( $schema ) = self::input_schema( $ability );
			// A name another tool already has, or a schema that cannot be sent as JSON, is left out: one would hide the other, the second would break the whole list.
			if ( isset( $taken[ $tool ] ) || false === wp_json_encode( $schema ) ) {
				continue;
			}
			$name    = (string) $ability->get_name();
			$ns      = (string) strtok( $name, '/' );
			$label   = self::namespace_label( $ns );
			$own     = self::own_tools_for( $ns );
			$tools[] = [
				'name'        => $tool,
				'description' => sprintf(
					'[Third-party: %1$s ability %2$s] %3$s Runs %1$s\'s own code with the connected user\'s WordPress permissions. Royal MCP logs the call but has no undo for it.%4$s',
					$label,
					$name,
					self::clip( trim( wp_strip_all_tags( (string) $ability->get_description() ) ), self::MAX_DESCRIPTION_CHARS ),
					'' !== $own ? sprintf( ' Royal MCP\'s own %1$s tools cover %2$s with undo and the read-only and drafts-only checks: use those first, and this one only for what they do not do.', $own, $label ) : ''
				),
				'inputSchema' => $schema,
				'annotations' => self::annotations( $ability ),
			];
		}
		return $tools;
	}

	/**
	 * Run a bridged ability for an ext_ tool call.
	 *
	 * @param mixed  $result Null unless an earlier callback answered.
	 * @param string $name   Tool name.
	 * @param array  $args   Tool arguments.
	 * @return mixed
	 */
	public static function run( $result, $name, $args ) {
		if ( null !== $result || 0 !== strpos( (string) $name, self::TOOL_NAME_START ) ) {
			return $result;
		}
		// Not an administrator: the same answer as for a tool that does not exist.
		if ( ! self::has_admin_caps() ) {
			return $result;
		}
		$map = self::bridged();
		if ( ! isset( $map[ $name ] ) ) {
			return $result;
		}
		$ability = $map[ $name ];
		$args    = is_array( $args ) ? $args : [];
		list( , $mode ) = self::input_schema( $ability );
		if ( 'none' === $mode && $args ) {
			return new \WP_Error( 'invalid_args', sprintf( '%s takes no input.', $ability->get_name() ) );
		}
		$input = 'none' === $mode ? null : ( 'wrapped' === $mode ? ( $args['input'] ?? null ) : $args );
		// Anything the ability prints is dropped: it would reach the client unchecked, ahead of the reply.
		$level = ob_get_level();
		ob_start();
		try {
			$out = $ability->execute( $input );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
		if ( is_wp_error( $out ) ) {
			return new \WP_Error( $out->get_error_code(), sprintf( '%s: %s', $ability->get_name(), \Royal_MCP\MCP\Support\Secret_Scrubber::scrub_text( (string) $out->get_error_message(), 'strict' ) ) );
		}
		return self::finish_result( (string) $ability->get_name(), is_array( $out ) ? $out : [ 'result' => $out ] );
	}

	/**
	 * What the client receives from an ability. Visitor fields go before the
	 * result is masked and trimmed (a trimmed result is text).
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $out          Its output.
	 * @return array|\WP_Error
	 */
	private static function finish_result( $ability_name, array $out ) {
		if ( isset( self::VISITOR_FIELDS[ $ability_name ] ) ) {
			$plain = json_decode( (string) wp_json_encode( $out ), true );
			if ( ! is_array( $plain ) ) {
				return new \WP_Error( 'result_not_encodable', 'The ability returned a result that cannot be sent as JSON.' );
			}
			$out = self::hide_visitor_paths( self::drop_fields( $plain, self::VISITOR_FIELDS[ $ability_name ] ) );
		}
		return self::redact( $out );
	}

	/** Fields about site visitors removed from a bridged ability's result, by ability. */
	const VISITOR_FIELDS = [
		'rank-math/get-404-logs' => [ 'referer', 'referrer', 'user_agent', 'useragent', 'agent', 'ip', 'ip_address' ],
	];

	/** $out without the named keys, at any depth. */
	private static function drop_fields( array $out, array $keys ) {
		foreach ( $out as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), $keys, true ) ) {
				unset( $out[ $k ] );
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = self::drop_fields( $v, $keys );
			}
		}
		return $out;
	}

	/** $out with every logged address cut back to what the native 404 tool shows, at any depth. */
	private static function hide_visitor_paths( array $out ) {
		foreach ( $out as $k => $v ) {
			if ( is_array( $v ) ) {
				$out[ $k ] = self::hide_visitor_paths( $v );
			} elseif ( is_string( $v ) && is_string( $k ) && in_array( strtolower( $k ), [ 'uri', 'uri_decoded', 'url' ], true ) ) {
				$out[ $k ] = \Royal_MCP\Integrations\SeoReads::clean_path( $v );
			}
		}
		return $out;
	}

	/** Layers of JSON text inside JSON text that are opened and checked. */
	const REDACT_MAX_JSON_LAYERS = 8;

	/**
	 * A bridged result with credentials masked the same way as Royal MCP's
	 * own tools. The result is first reduced to what the client would
	 * receive as JSON (public properties, JsonSerializable output), so the
	 * mask never adds fields; JSON text inside the result is masked too.
	 *
	 * @param array $out Ability output.
	 * @return array|\WP_Error
	 */
	private static function redact( array $out ) {
		static $server = null;
		if ( null === $server ) {
			if ( ! class_exists( '\Royal_MCP\MCP\Server' ) ) {
				return new \WP_Error( 'redaction_unavailable', 'The result could not be checked for credentials, so it was not returned.' );
			}
			$server = new \Royal_MCP\MCP\Server();
		}
		$json = wp_json_encode( $out );
		if ( false === $json ) {
			return new \WP_Error( 'result_not_encodable', 'The ability returned a result that cannot be sent as JSON.' );
		}
		if ( strlen( $json ) > self::MAX_CHECKED_BYTES ) {
			return [ '_royal_mcp_note' => sprintf( 'The result was %1$d bytes, more than the %2$d that can be checked and returned. Nothing was returned; ask for less (filters, paging).', strlen( $json ), self::MAX_CHECKED_BYTES ) ];
		}
		$plain = json_decode( $json, true );
		if ( ! is_array( $plain ) ) {
			return new \WP_Error( 'result_not_encodable', 'The ability returned a result that cannot be sent as JSON.' );
		}
		return self::cap( self::redact_json_text( $server->redact_for_display( $plain ), $server, 0 ) );
	}

	/**
	 * Trim a masked result to MAX_RESULT_DEPTH levels and MAX_RESULT_BYTES,
	 * saying so in `_royal_mcp_note`.
	 *
	 * @param array $out
	 * @return array
	 */
	private static function cap( array $out ) {
		// The note key is Royal MCP's own; a result that brings one has it moved aside.
		if ( array_key_exists( '_royal_mcp_note', $out ) ) {
			$out['_royal_mcp_note_from_ability'] = $out['_royal_mcp_note'];
			unset( $out['_royal_mcp_note'] );
		}
		$trimmed = false;
		$out     = self::cap_depth( $out, 1, $trimmed );
		$notes   = [];
		if ( $trimmed ) {
			$notes[] = sprintf( 'Nesting below %d levels was left out.', self::MAX_RESULT_DEPTH );
		}
		$json = (string) wp_json_encode( $out );
		if ( strlen( $json ) > self::MAX_RESULT_BYTES ) {
			$out     = [ 'partial' => wp_check_invalid_utf8( substr( $json, 0, self::MAX_RESULT_BYTES ), true ) ];
			$notes[] = sprintf( 'The result was %1$d bytes; the first %2$d bytes are in "partial" as JSON text. Ask for less (filters, paging) to get the full result.', strlen( $json ), self::MAX_RESULT_BYTES );
		}
		if ( $notes ) {
			$out['_royal_mcp_note'] = implode( ' ', $notes );
		}
		return $out;
	}

	/** Text cut to a number of characters. */
	private static function clip( $text, $max ) {
		$text = (string) $text;
		return ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) > $max ? ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max ) ) . '...' : $text;
	}

	private static function cap_depth( $value, $depth, &$trimmed ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_RESULT_DEPTH ) {
			$trimmed = true;
			return '[nested too deep]';
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::cap_depth( $v, $depth + 1, $trimmed );
		}
		return $value;
	}

	/**
	 * Mask credentials inside string values that hold a JSON object or
	 * array, keeping them as JSON text. JSON text nested deeper than
	 * REDACT_MAX_JSON_LAYERS is withheld rather than returned unchecked.
	 *
	 * @param mixed               $value  Already decoded, so at most 512 levels deep.
	 * @param \Royal_MCP\MCP\Server $server
	 * @param int                 $layers JSON texts opened above this value.
	 * @return mixed
	 */
	private static function redact_json_text( $value, $server, $layers ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::redact_json_text( $v, $server, $layers );
			}
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$trimmed = ltrim( $value );
		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return $value;
		}
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return $value;
		}
		if ( $layers >= self::REDACT_MAX_JSON_LAYERS ) {
			return '[REDACTED]';
		}
		$masked = self::redact_json_text( $server->redact_for_display( $decoded ), $server, $layers + 1 );
		if ( $masked === $decoded ) {
			return $value;
		}
		$encoded = wp_json_encode( $masked );
		return false === $encoded ? '[REDACTED]' : $encoded;
	}
}
