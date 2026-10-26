<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Royal_MCP\OAuth\Server as OAuth_Server;

/**
 * Walks the discovery path an AI client follows to sign in to Royal MCP and
 * records who answers each step:
 *
 *   1. the 401 challenge from the MCP endpoint (its resource_metadata URL),
 *   2. the protected-resource document that URL returns,
 *   3. the authorization-server document of the issuer that document names,
 *   4. the same challenge on the other host name (www / no www).
 *
 * When another plugin answers a step, the result names the plugin where it
 * can and gives the site owner a fix. Shared by the admin notice and the
 * coexistence report so both read the same facts.
 */
class Coexistence_Check {

	const CHAIN_TRANSIENT = 'royal_mcp_discovery_chain';
	const CHAIN_TTL       = 12 * HOUR_IN_SECONDS;
	const USER_AGENT      = 'Royal MCP Self-Check';
	const TIMEOUT         = 5;

	/**
	 * The discovery-path report, cached for CHAIN_TTL.
	 *
	 * @param bool $fresh Probe again instead of reading the cache.
	 * @return array{ok: bool, issuer: string, steps: array, first_problem: ?array, checked_at: int}
	 */
	public static function discovery_chain( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CHAIN_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['steps'] ) ) {
				return $cached;
			}
		}
		$report = self::run_discovery_chain();
		set_transient( self::CHAIN_TRANSIENT, $report, self::CHAIN_TTL );
		return $report;
	}

	/** Forget the cached report. */
	public static function clear() {
		delete_transient( self::CHAIN_TRANSIENT );
	}

	/**
	 * Probe every step now.
	 *
	 * @return array See discovery_chain().
	 */
	public static function run_discovery_chain() {
		$endpoint = OAuth_Server::canonical_resource_url( OAuth_Server::default_resource_path() );
		$issuer   = self::expected_issuer();
		$steps    = [];

		$challenge = self::probe_challenge( $endpoint );
		$steps[]   = $challenge;

		$prm_url = (string) ( $challenge['metadata_url'] ?? '' );
		if ( '' !== $prm_url ) {
			$fetched = self::fetch_site_json( $prm_url );
			$steps[] = self::step(
				'protected_resource',
				$prm_url,
				$fetched['status'],
				self::classify_protected_resource( $fetched['json'], $endpoint, $issuer ),
				$fetched['json']
			);
			$named_issuer = is_array( $fetched['json'] ) && ! empty( $fetched['json']['authorization_servers'][0] ) && is_string( $fetched['json']['authorization_servers'][0] )
				? rtrim( $fetched['json']['authorization_servers'][0], '/' )
				: $issuer;
			$as_url  = self::authorization_server_document_url( $named_issuer );
			$as      = self::fetch_site_json( $as_url );
			$steps[] = self::step(
				'authorization_server',
				$as_url,
				$as['status'],
				self::classify_authorization_server( $as['json'], $issuer ),
				$as['json']
			);
		}

		// When this host does not answer at all, the other host name tells us nothing
		// and only adds another timeout to the admin page that runs the check.
		$alt = 'unknown' === $challenge['answered_by'] ? '' : self::alternate_host_url( $endpoint );
		if ( '' !== $alt ) {
			$steps[] = self::probe_alternate_host( $alt );
		}

		$first_problem = null;
		foreach ( $steps as $s ) {
			if ( ! in_array( $s['answered_by'], [ 'royal_mcp', 'redirects', 'unreachable' ], true ) ) {
				$first_problem = $s;
				break;
			}
		}

		return [
			'ok'            => null === $first_problem,
			'issuer'        => $issuer,
			'steps'         => $steps,
			'first_problem' => $first_problem,
			'checked_at'    => time(),
		];
	}

	/** The issuer Royal MCP's documents name. */
	public static function expected_issuer() {
		$ns = OAuth_Server::namespace_issuer();
		return '' !== $ns ? rtrim( $ns, '/' ) : rtrim( OAuth_Server::canonical_resource_url( '/' ), '/' );
	}

	/**
	 * RFC 8414 document address for an issuer: the path goes after
	 * /.well-known/oauth-authorization-server.
	 *
	 * @param string $issuer Issuer URL.
	 * @return string
	 */
	public static function authorization_server_document_url( $issuer ) {
		$parts = wp_parse_url( (string) $issuer );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
		$path   = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		return $origin . '/.well-known/oauth-authorization-server' . $path;
	}

	/**
	 * Is this the protected-resource document Royal MCP serves for its endpoint?
	 *
	 * @param mixed  $doc      Decoded document (null when not JSON).
	 * @param string $endpoint Royal MCP's endpoint URL.
	 * @param string $issuer   Royal MCP's issuer.
	 * @return string royal_mcp | other | nobody
	 */
	public static function classify_protected_resource( $doc, $endpoint, $issuer ) {
		if ( ! is_array( $doc ) ) {
			return 'nobody';
		}
		$resource = isset( $doc['resource'] ) && is_string( $doc['resource'] ) ? rtrim( $doc['resource'], '/' ) : '';
		$as       = isset( $doc['authorization_servers'][0] ) && is_string( $doc['authorization_servers'][0] ) ? rtrim( $doc['authorization_servers'][0], '/' ) : '';
		return ( $resource === rtrim( $endpoint, '/' ) && $as === rtrim( $issuer, '/' ) ) ? 'royal_mcp' : 'other';
	}

	/**
	 * Is this Royal MCP's authorization-server document?
	 *
	 * @param mixed  $doc    Decoded document.
	 * @param string $issuer Royal MCP's issuer.
	 * @return string royal_mcp | other | nobody
	 */
	public static function classify_authorization_server( $doc, $issuer ) {
		if ( ! is_array( $doc ) ) {
			return 'nobody';
		}
		$ours  = OAuth_Server::build_authorization_server_metadata( '' !== OAuth_Server::namespace_issuer() );
		$same  = static function ( $a, $b ) {
			return is_string( $a ) && is_string( $b ) && rtrim( $a, '/' ) === rtrim( $b, '/' );
		};
		return ( $same( $doc['issuer'] ?? null, $issuer )
			&& $same( $doc['authorization_endpoint'] ?? null, $ours['authorization_endpoint'] ?? '' )
			&& $same( $doc['token_endpoint'] ?? null, $ours['token_endpoint'] ?? '' ) ) ? 'royal_mcp' : 'other';
	}

	/**
	 * Which plugin served a document Royal MCP did not, and how to fix it.
	 *
	 * @param array $doc Decoded document from another plugin.
	 * @return array{name: string, fix: string}|null
	 */
	public static function identify_plugin( array $doc ) {
		$urls = [];
		foreach ( [ 'issuer', 'resource', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint' ] as $k ) {
			if ( isset( $doc[ $k ] ) && is_string( $doc[ $k ] ) ) {
				$urls[] = $doc[ $k ];
			}
		}
		if ( isset( $doc['authorization_servers'][0] ) && is_string( $doc['authorization_servers'][0] ) ) {
			$urls[] = $doc['authorization_servers'][0];
		}
		$all = strtolower( implode( ' ', $urls ) );

		if ( false !== strpos( $all, '/wp-json/mcp/v1' ) || false !== strpos( $all, 'rest_route=/mcp/v1' ) ) {
			return [
				'name' => 'AI Engine',
				'fix'  => __( 'Turn off the MCP module in AI Engine\'s settings if this site does not need it.', 'royal-mcp' ),
			];
		}
		if ( false !== strpos( $all, '/easy-mcp-ai/' ) ) {
			return [
				'name' => 'Easy MCP AI',
				'fix'  => __( 'Turn off OAuth sign-in in Easy MCP AI\'s settings if this site does not need it.', 'royal-mcp' ),
			];
		}
		$authorize = isset( $doc['authorization_endpoint'] ) && is_string( $doc['authorization_endpoint'] ) ? (string) wp_parse_url( $doc['authorization_endpoint'], PHP_URL_PATH ) : '';
		if ( '/oauth/authorize' === rtrim( $authorize, '/' ) && class_exists( '\WPMedia\MCP\OAuth\Bootstrap', false ) ) {
			$host = self::bundled_sign_in_host();
			return [
				'name' => '' !== $host ? $host : __( 'A plugin with a built-in MCP sign-in', 'royal-mcp' ),
				'fix'  => self::bundled_sign_in_fix(),
			];
		}
		foreach ( $urls as $u ) {
			$namespace = self::registered_rest_namespace( $u );
			if ( '' !== $namespace && 0 !== strpos( $namespace, 'royal-mcp' ) ) {
				$name = self::plugin_name_for_rest_namespace( $namespace );
				if ( '' !== $name ) {
					return [
						'name' => $name,
						/* translators: %s: plugin name */
						'fix'  => sprintf( __( 'Deactivate %s if this site does not need it, or ask its author to answer only its own sign-in addresses.', 'royal-mcp' ), $name ),
					];
				}
			}
		}
		return null;
	}

	/**
	 * www.example.com for example.com and the other way round; empty for IP hosts.
	 *
	 * @param string $url A URL on this site.
	 * @return string
	 */
	public static function alternate_host_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' === $host || false === strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) || preg_match( '/\.(local|test|localhost)$/', $host ) ) {
			return '';
		}
		$alt = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		return (string) preg_replace( '#^(https?://)' . preg_quote( $host, '#' ) . '#i', '${1}' . $alt, (string) $url, 1 );
	}

	/** Addresses MCP clients and AI crawlers read at the site root. */
	const SHARED_PATHS = [
		'/.well-known/oauth-authorization-server',
		'/.well-known/oauth-protected-resource',
		'/.well-known/mcp/server-card.json',
		'/llms.txt',
		'/robots.txt',
	];

	/**
	 * What else on this site speaks MCP and who answers the addresses MCP
	 * clients read. The keys before `discovery` keep the shape Royally's
	 * coexistence monitor already parses.
	 *
	 * @return array
	 */
	public static function report() {
		$servers   = self::other_servers();
		$addresses = self::shared_addresses();
		return [
			'checked_at'        => gmdate( 'c' ),
			'royal_mcp'         => [
				'endpoint' => rest_url( 'royal-mcp/v1/mcp' ),
				'version'  => defined( 'ROYAL_MCP_PRO_VERSION' ) ? (string) ROYAL_MCP_PRO_VERSION : ( defined( 'ROYAL_MCP_VERSION' ) ? (string) ROYAL_MCP_VERSION : '' ),
			],
			'other_mcp_servers' => $servers,
			'shared_addresses'  => $addresses,
			'abilities'         => self::ability_exposure( $servers ),
			'discovery'         => self::discovery_chain( true ),
		];
	}

	/** One-line summary of a report, for the tool's text reply. */
	public static function summary( array $report ) {
		$others = count( array_filter( (array) ( $report['other_mcp_servers'] ?? [] ), static function ( $s ) { return ! empty( $s['active'] ); } ) );
		$taken  = count( array_filter( (array) ( $report['shared_addresses'] ?? [] ), static function ( $a ) { return 'other' === ( $a['answered_by'] ?? '' ); } ) );
		$text   = sprintf( '%d other MCP server(s) or hub(s) active; %d shared address(es) answered by another plugin.', $others, $taken );
		$p      = $report['discovery']['first_problem'] ?? null;
		if ( is_array( $p ) && 'other' === ( $p['answered_by'] ?? '' ) ) {
			$text .= sprintf( ' %s answers part of Royal MCP\'s sign-in (%s).', ! empty( $p['plugin'] ) ? $p['plugin'] : 'Another plugin', (string) ( $p['step'] ?? '' ) );
		}
		return $text;
	}

	/**
	 * Other MCP servers and hubs: known ones by their classes or switches, plus any
	 * active plugin whose name says it is an MCP server.
	 */
	private static function other_servers() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		$plugins = get_plugins();
		$out     = [];

		$elementor_mcp = class_exists( '\\Royal_MCP\\Integrations\\Elementor_Coexistence' ) && Elementor_Coexistence::is_native_mcp_active();
		$out[] = [ 'id' => 'elementor-mcp', 'name' => 'Elementor MCP', 'active' => (bool) $elementor_mcp, 'detail' => $elementor_mcp ? 'switched on' : ( defined( 'ELEMENTOR_VERSION' ) ? 'Elementor active, its MCP server off' : 'Elementor not active' ) ];
		$adapter = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		$adapter_detail = $adapter ? self::adapter_source() : 'not loaded';
		$copies         = $adapter ? \Royal_MCP\Abilities\MCP_Adapter_Server::loaded_copies() : [];
		if ( count( $copies ) > 1 ) {
			$adapter_detail = sprintf(
				'classes loaded from %d copies (%s), so versions are mixed; Royal MCP keeps its adapter server off until one copy is left (its own endpoint is unaffected)',
				count( $copies ),
				implode( ', ', array_values( array_unique( $copies ) ) )
			);
		}
		$out[] = [ 'id' => 'mcp-adapter', 'name' => 'WordPress MCP Adapter', 'active' => $adapter, 'detail' => $adapter_detail ];

		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( 0 === strpos( $file, 'royal-mcp' ) || ! isset( $plugins[ $file ] ) ) {
				continue;
			}
			$name = (string) ( $plugins[ $file ]['Name'] ?? '' );
			if ( preg_match( '/\bMCP\b|Model Context Protocol|\bAngie\b/i', $name . ' ' . $file ) && false === stripos( $file, 'mcp-adapter/' ) ) {
				$out[] = [
					'id'     => sanitize_key( dirname( $file ) ),
					'name'   => $name,
					'active' => true,
					'detail' => 'version ' . (string) ( $plugins[ $file ]['Version'] ?? '' ),
				];
			}
		}
		return $out;
	}

	/** Which plugin supplies the loaded MCP Adapter classes. */
	private static function adapter_source() {
		try {
			$file = wp_normalize_path( ( new \ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' ) )->getFileName() );
		} catch ( \ReflectionException $e ) {
			return 'loaded';
		}
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$inside      = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $file, $plugins_dir ) : 0 === strpos( $file, $plugins_dir );
		if ( $inside ) {
			$slug = strtok( substr( $file, strlen( $plugins_dir ) ), '/' );
			return 'mcp-adapter' === $slug ? 'standalone plugin' : 'bundled in ' . $slug;
		}
		return 'loaded';
	}

	/** Who answers each shared address: Royal MCP, another plugin, the site, or nobody. */
	private static function shared_addresses() {
		$out   = [];
		$down  = '';
		foreach ( self::SHARED_PATHS as $path ) {
			// One address the site does not answer means the rest would time out too.
			if ( '' !== $down ) {
				$out[] = [ 'path' => $path, 'status' => null, 'answered_by' => 'unknown', 'detail' => $down ];
				continue;
			}
			// Follows redirects that stay on this site, as AI clients do (WordPress
			// adds a trailing slash before some plugins answer).
			$resp = self::get_on_site( home_url( $path ), 'Royal MCP Coexistence Check' );
			if ( is_wp_error( $resp ) ) {
				$out[] = [ 'path' => $path, 'status' => null, 'answered_by' => 'unknown', 'detail' => $resp->get_error_message() ];
				$down  = 'not checked: the site did not answer ' . $path;
				continue;
			}
			$code  = (int) wp_remote_retrieve_response_code( $resp );
			$body  = (string) wp_remote_retrieve_body( $resp );
			$out[] = [ 'path' => $path, 'status' => $code, 'answered_by' => self::who_answered( $path, $code, $body ), 'detail' => self::address_detail( $path, $code, $body ) ];
		}
		return $out;
	}

	/**
	 * Who answered a shared address.
	 *
	 * @param string $path Address.
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return string royal_mcp | other | site | nobody
	 */
	public static function who_answered( $path, $code, $body ) {
		if ( $code < 200 || $code >= 300 ) {
			// A JSON error is a plugin answering the address (for example "requires
			// HTTPS"); an empty or HTML error page means nothing answers it.
			$err = json_decode( (string) $body, true );
			return ( is_array( $err ) && ( isset( $err['error'] ) || isset( $err['code'] ) ) ) ? 'other' : 'nobody';
		}
		if ( '/robots.txt' === $path || '/llms.txt' === $path ) {
			return 'site';
		}
		$doc = json_decode( (string) $body, true );
		if ( ! is_array( $doc ) ) {
			return 'other';
		}
		// The site-root sign-in document is ours when its token endpoint is ours.
		if ( isset( $doc['token_endpoint'] ) ) {
			$as = OAuth_Server::build_authorization_server_metadata();
			return ( isset( $as['token_endpoint'] ) && $as['token_endpoint'] === $doc['token_endpoint'] ) ? 'royal_mcp' : 'other';
		}
		$ours = rtrim( (string) wp_parse_url( rest_url( 'royal-mcp/v1/' ), PHP_URL_PATH ), '/' );
		$blob = wp_json_encode( $doc );
		return ( is_string( $blob ) && '' !== $ours && false !== strpos( str_replace( '\\/', '/', $blob ), $ours ) ) ? 'royal_mcp' : 'other';
	}

	private static function address_detail( $path, $code, $body ) {
		if ( '/robots.txt' === $path && 200 === $code ) {
			$blocked = self::robots_blocked_bots( (string) $body, [ 'GPTBot', 'ClaudeBot', 'Claude-User', 'ChatGPT-User', 'PerplexityBot', 'Google-Extended' ] );
			return $blocked ? 'blocks ' . implode( ', ', $blocked ) : 'no AI bot blocked site-wide';
		}
		if ( 200 === $code ) {
			$doc = json_decode( (string) $body, true );
			if ( is_array( $doc ) && isset( $doc['issuer'] ) && is_string( $doc['issuer'] ) ) {
				return 'issuer ' . $doc['issuer'];
			}
			if ( is_array( $doc ) && isset( $doc['resource'] ) && is_string( $doc['resource'] ) ) {
				return 'resource ' . $doc['resource'];
			}
			return strlen( (string) $body ) . ' bytes';
		}
		return '';
	}

	/**
	 * Which of the named bots robots.txt blocks from the whole site: a group
	 * (its User-agent lines plus the rules after them) that names the bot and
	 * has "Disallow: /". Only the first 512 KB is read.
	 *
	 * @param string $body robots.txt.
	 * @param array  $bots Bot names.
	 * @return string[]
	 */
	public static function robots_blocked_bots( $body, array $bots ) {
		$groups  = [];
		$agents  = [];
		$rules   = [];
		$in_rule = false;
		foreach ( preg_split( '/\r\n|\r|\n/', substr( (string) $body, 0, 524288 ) ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( $in_rule ) {
					$groups[] = [ $agents, $rules ];
					$agents   = [];
					$rules    = [];
					$in_rule  = false;
				}
				$agents[] = strtolower( $value );
			} elseif ( in_array( $field, [ 'disallow', 'allow' ], true ) ) {
				$in_rule = true;
				$rules[] = [ $field, $value ];
			}
		}
		if ( $agents ) {
			$groups[] = [ $agents, $rules ];
		}
		$out = [];
		foreach ( $bots as $bot ) {
			foreach ( $groups as $g ) {
				if ( in_array( strtolower( $bot ), $g[0], true ) && in_array( [ 'disallow', '/' ], $g[1], true ) ) {
					$out[] = $bot;
					break;
				}
			}
		}
		return $out;
	}

	/** Whether Royal MCP tools can be reached through other servers, and whether those calls are logged. */
	private static function ability_exposure( array $servers ) {
		$registered = 0;
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( (array) wp_get_abilities() as $ability ) {
				if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && 0 === strpos( (string) $ability->get_name(), 'royal-mcp/' ) ) {
					$registered++;
				}
			}
		}
		$via = [];
		foreach ( $servers as $s ) {
			if ( ! empty( $s['active'] ) && in_array( $s['id'], [ 'mcp-adapter', 'elementor-mcp' ], true ) ) {
				$via[] = $s['id'];
			}
			if ( ! empty( $s['active'] ) && false !== stripos( (string) $s['name'], 'angie' ) ) {
				$via[] = $s['id'];
			}
		}
		return [
			'registered'                   => $registered,
			'reachable_through'            => $registered ? array_values( array_unique( $via ) ) : [],
			'calls_logged_in_activity_log' => true,
		];
	}

	/* ------------------------------------------------------------------ */

	/** POST an unauthenticated initialize and read the challenge. */
	private static function probe_challenge( $endpoint ) {
		$r = wp_remote_post(
			$endpoint,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => self::USER_AGENT,
				'headers'     => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json, text/event-stream',
				],
				'body'        => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
			]
		);
		if ( is_wp_error( $r ) ) {
			return self::step( 'challenge', $endpoint, 0, 'unknown', null ) + [ 'metadata_url' => '' ];
		}
		$code   = (int) wp_remote_retrieve_response_code( $r );
		$header = (string) wp_remote_retrieve_header( $r, 'www-authenticate' );
		$url    = preg_match( '/resource_metadata="([^"]+)"/', $header, $m ) ? esc_url_raw( $m[1] ) : '';
		$by     = ( 401 === $code && '' !== $url ) ? 'royal_mcp' : ( in_array( $code, [ 403, 429, 503 ], true ) ? 'blocked' : 'nobody' );
		return self::step( 'challenge', $endpoint, $code, $by, null ) + [ 'metadata_url' => $url ];
	}

	/** The challenge on the other host name: a redirect to this host, or Royal MCP's own challenge, is fine. */
	private static function probe_alternate_host( $alt_endpoint ) {
		$r = wp_remote_post(
			$alt_endpoint,
			[
				'timeout'     => 4,
				'redirection' => 0,
				'user-agent'  => self::USER_AGENT,
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
			]
		);
		if ( is_wp_error( $r ) ) {
			return self::step( 'alternate_host', $alt_endpoint, 0, 'unreachable', null );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code >= 300 && $code < 400 ) {
			return self::step( 'alternate_host', $alt_endpoint, $code, 'redirects', null );
		}
		$header = (string) wp_remote_retrieve_header( $r, 'www-authenticate' );
		if ( 401 !== $code || ! preg_match( '/resource_metadata="([^"]+)"/', $header, $m ) ) {
			return self::step( 'alternate_host', $alt_endpoint, $code, 'unreachable', null );
		}
		$doc  = self::fetch_site_json( $m[1] );
		$ours = 'royal_mcp' === self::classify_protected_resource( $doc['json'], $alt_endpoint, self::alternate_host_url( self::expected_issuer() ) )
			|| 'royal_mcp' === self::classify_protected_resource( $doc['json'], OAuth_Server::canonical_resource_url( OAuth_Server::default_resource_path() ), self::expected_issuer() );
		return self::step( 'alternate_host', $alt_endpoint, $code, $ours ? 'royal_mcp' : 'other', $doc['json'] );
	}

	/**
	 * Is the URL on this site's host or its www / no-www twin?
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_site_url( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || '' === $home ) {
			return false;
		}
		$alt = (string) wp_parse_url( self::alternate_host_url( home_url( '/' ) ), PHP_URL_HOST );
		return $host === $home || ( '' !== $alt && $host === strtolower( $alt ) );
	}

	/**
	 * Fetch a document only when it is on this site: an address on another host
	 * cannot be Royal MCP's, and the check never follows it there.
	 */
	private static function fetch_site_json( $url ) {
		if ( ! self::is_site_url( $url ) ) {
			return [ 'status' => 0, 'json' => [ 'resource' => (string) $url ] ];
		}
		return self::fetch_json( $url );
	}

	/**
	 * GET a URL on this site, following up to three redirects while they stay
	 * on it. A redirect off the site is returned as is, never followed.
	 *
	 * @param string $url        URL.
	 * @param string $user_agent User agent.
	 * @return array|\WP_Error Response.
	 */
	private static function get_on_site( $url, $user_agent = self::USER_AGENT ) {
		$r = null;
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			$r = wp_remote_get(
				$url,
				[
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'user-agent'  => $user_agent,
					'headers'     => [ 'Accept' => 'application/json' ],
				]
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$code     = (int) wp_remote_retrieve_response_code( $r );
			$location = (string) wp_remote_retrieve_header( $r, 'location' );
			if ( $code < 300 || $code >= 400 || '' === $location ) {
				return $r;
			}
			$next = \WP_Http::make_absolute_url( $location, $url );
			if ( ! self::is_site_url( $next ) ) {
				return $r;
			}
			$url = $next;
		}
		return $r;
	}

	/** GET a JSON document on this site (see get_on_site()). */
	private static function fetch_json( $url ) {
		if ( '' === (string) $url ) {
			return [ 'status' => 0, 'json' => null ];
		}
		$r = self::get_on_site( $url );
		if ( is_wp_error( $r ) ) {
			return [ 'status' => 0, 'json' => null ];
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $r, 'location' );
			$next     = '' !== $location ? \WP_Http::make_absolute_url( $location, $url ) : '';
			return [ 'status' => $code, 'json' => ( '' !== $next && ! self::is_site_url( $next ) ) ? [ 'resource' => $next ] : null ];
		}
		$body = (string) wp_remote_retrieve_body( $r );
		$json = strlen( $body ) <= 65536 ? json_decode( $body, true ) : null;
		return [ 'status' => $code, 'json' => is_array( $json ) ? $json : null ];
	}

	/** One step of the report; names the other plugin when another one answered. */
	private static function step( $name, $url, $status, $answered_by, $doc ) {
		$plugin = ( 'other' === $answered_by && is_array( $doc ) ) ? self::identify_plugin( $doc ) : null;
		return [
			'step'        => $name,
			'url'         => (string) $url,
			'http_status' => (int) $status,
			'answered_by' => $answered_by,
			'plugin'      => $plugin ? $plugin['name'] : null,
			'fix'         => $plugin ? $plugin['fix'] : null,
		];
	}

	/** Plugin name for the plugin folder a class file sits in. */
	/**
	 * Name of the plugin whose bundled MCP sign-in library is loaded (Rank
	 * Math and others ship one), or '' when none is.
	 */
	public static function bundled_sign_in_host() {
		if ( ! class_exists( '\WPMedia\MCP\OAuth\Bootstrap', false ) ) {
			return '';
		}
		return self::plugin_name_for_class( '\WPMedia\MCP\OAuth\Bootstrap' );
	}

	/** How a site owner turns that bundled sign-in off. */
	public static function bundled_sign_in_fix() {
		return sprintf(
			/* translators: %s: a line of PHP */
			__( 'Its built-in MCP sign-in only accepts Claude, so ChatGPT shows "Unknown OAuth client." while it answers. It can be turned off: add %s to a small must-use plugin, then re-save Settings > Permalinks.', 'royal-mcp' ),
			"add_filter( 'wpmedia_mcp_oauth_server_enabled', '__return_false' );"
		);
	}

	private static function plugin_name_for_class( $class ) {
		try {
			$file = ( new \ReflectionClass( $class ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
		return is_string( $file ) ? self::plugin_name_for_file( $file ) : '';
	}

	/**
	 * The registered REST namespace a URL points into (/wp-json/ns/v1/... or
	 * ?rest_route=/ns/v1/...), longest match first; empty when none.
	 *
	 * @param string $url URL from another plugin's document.
	 * @return string
	 */
	private static function registered_rest_namespace( $url ) {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return '';
		}
		$route = '';
		if ( preg_match( '#/wp-json/([^?\#]+)#', (string) $url, $m ) ) {
			$route = $m[1];
		} elseif ( preg_match( '#[?&]rest_route=/([^&\#]+)#', (string) $url, $m ) ) {
			$route = rawurldecode( $m[1] );
		}
		$segments = array_values( array_filter( explode( '/', strtolower( $route ) ), 'strlen' ) );
		if ( ! $segments ) {
			return '';
		}
		$known = rest_get_server()->get_namespaces();
		for ( $n = min( 3, count( $segments ) ); $n >= 1; $n-- ) {
			$candidate = implode( '/', array_slice( $segments, 0, $n ) );
			if ( in_array( $candidate, $known, true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/** Plugin name for the plugin that registered a REST namespace. */
	private static function plugin_name_for_rest_namespace( $namespace ) {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return '';
		}
		foreach ( rest_get_server()->get_routes( $namespace ) as $handlers ) {
			foreach ( (array) $handlers as $h ) {
				$cb = is_array( $h ) && isset( $h['callback'] ) ? $h['callback'] : null;
				try {
					if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
						$ref = new \ReflectionMethod( $cb[0], $cb[1] );
					} elseif ( $cb instanceof \Closure || ( is_string( $cb ) && function_exists( $cb ) ) ) {
						$ref = new \ReflectionFunction( $cb );
					} else {
						continue;
					}
				} catch ( \ReflectionException $e ) {
					continue;
				}
				$name = self::plugin_name_for_file( (string) $ref->getFileName() );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}
		return '';
	}

	/** Plugin name from a file path inside wp-content/plugins. */
	private static function plugin_name_for_file( $file ) {
		$base = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
		$file = wp_normalize_path( $file );
		// Windows paths are case-insensitive and PHP may report them in a different case.
		$inside = '\\' === DIRECTORY_SEPARATOR ? 0 === stripos( $file, $base ) : 0 === strpos( $file, $base );
		if ( ! $inside ) {
			return '';
		}
		$folder = strtok( substr( $file, strlen( $base ) ), '/' );
		if ( ! is_string( $folder ) || '' === $folder ) {
			return '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $key => $data ) {
			if ( 0 === strpos( $key, $folder . '/' ) && ! empty( $data['Name'] ) ) {
				return (string) $data['Name'];
			}
		}
		return '';
	}
}
