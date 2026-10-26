<?php
/**
 * Registers "royal-mcp-server" with the WordPress MCP Adapter. Enables customers
 * running MCP Adapter to reach Royal MCP tools over MCP Adapter's HTTP transport
 * in addition to our native /wp-json/royal-mcp/mcp endpoint and WP core
 * /wp-json/wp-abilities/v1/* REST.
 *
 * Named-server registration + explicit ability list. Our abilities set
 * meta.mcp.public = false (see Meta_Config.php), so the adapter's default server
 * does not list them; this named server is how adapter clients reach them.
 *
 * Silent no-op if the MCP Adapter plugin isn't installed or hasn't loaded yet.
 */

namespace Royal_MCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_Adapter_Server {

	const SERVER_ID        = 'royal-mcp-server';
	const NAMESPACE_ROUTE  = 'royal-mcp-server/v1';
	const ROUTE            = 'mcp';
	const SERVER_NAME      = 'Royal MCP';
	const SERVER_DESC      = 'Royal MCP — WordPress core operations plus WooCommerce, Elementor, SEO, and cross-plugin abilities. Same handlers as the native /wp-json/royal-mcp/mcp endpoint; MCP Adapter transport is an additional access path, not a replacement.';

	/**
	 * Fires on mcp_adapter_init (dispatched by MCP Adapter core on its own init).
	 * By this hook, wp_abilities_api_init has already fired and the abilities
	 * registry is populated — we can safely collect ability names.
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return;
		}
		if ( ! class_exists( '\WP\MCP\Transport\HttpTransport' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}

		$settings = get_option( 'royal_mcp_settings', array() );
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// Several plugins can bundle their own copy of the adapter. When its
		// classes come from more than one copy, versions are mixed and a server
		// can fail in ways the adapter does not catch, so ours stays off; Royal
		// MCP's own endpoint does not use the adapter.
		if ( count( self::loaded_copies() ) > 1 ) {
			return;
		}

		$ability_names = self::collect_our_ability_names();
		if ( empty( $ability_names ) ) {
			return;
		}

		// Another plugin may refuse calls on every adapter server; say what to do
		// when that happens on ours.
		add_filter( 'mcp_adapter_pre_tool_call', array( __CLASS__, 'explain_foreign_refusal' ), PHP_INT_MAX, 4 );

		try {
			self::create_server( $ability_names );
		} catch ( \Throwable $e ) {
			// Older bundled adapter copies don't catch server-construction
			// errors themselves; skip our server rather than break REST.
			return;
		}
	}

	/**
	 * A call on Royal MCP's adapter server refused by Elementor's Angie for
	 * missing consent gets a message that says where to allow it.
	 *
	 * @param mixed  $args      Tool arguments, or a WP_Error from an earlier callback.
	 * @param string $tool_name Tool name.
	 * @param mixed  $mcp_tool  Adapter tool.
	 * @param mixed  $server    Adapter server.
	 * @return mixed
	 */
	public static function explain_foreign_refusal( $args, $tool_name = '', $mcp_tool = null, $server = null ) {
		if ( ! is_wp_error( $args ) || 'angie_consent_required' !== $args->get_error_code() ) {
			return $args;
		}
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || self::SERVER_ID !== $server->get_server_id() ) {
			return $args;
		}
		return new \WP_Error(
			'angie_consent_required',
			sprintf(
				'Elementor\'s Angie blocks tool calls on this MCP Adapter server until its consent setting allows them. Allow it in Angie\'s settings, or connect to Royal MCP\'s own endpoint at %s, which Angie does not affect.',
				rest_url( 'royal-mcp/v1/mcp' )
			)
		);
	}

	/**
	 * The copies of the MCP Adapter that loaded classes so far: one per plugin
	 * that carries a copy ('standalone plugin' or 'bundled in <plugin folder>'),
	 * or per folder outside the plugins folder.
	 *
	 * @return array<string,string> copy key => label
	 */
	public static function loaded_copies(): array {
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$copies      = array();
		foreach ( array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) as $class ) {
			if ( 0 !== strpos( $class, 'WP\\MCP\\' ) ) {
				continue;
			}
			try {
				$file = ( new \ReflectionClass( $class ) )->getFileName();
			} catch ( \ReflectionException $e ) {
				continue;
			}
			// Classes declared at runtime have no copy behind them.
			if ( ! is_string( $file ) || '' === $file || false !== strpos( $file, "eval()'d code" ) ) {
				continue;
			}
			$file = wp_normalize_path( $file );
			// A copy inside the plugins folder is the plugin that carries it.
			if ( 0 === stripos( $file, $plugins_dir ) ) {
				$slug = (string) strtok( substr( $file, strlen( $plugins_dir ) ), '/' );
				$key  = strtolower( $slug );
				if ( ! isset( $copies[ $key ] ) ) {
					$copies[ $key ] = 'mcp-adapter' === $slug ? 'standalone plugin' : 'bundled in ' . $slug;
				}
				continue;
			}
			// Elsewhere, PSR-4: WP\MCP\Core\McpAdapter lives in <copy>/Core/McpAdapter.php.
			$rel  = str_replace( '\\', '/', substr( $class, strlen( 'WP\\MCP\\' ) ) ) . '.php';
			$root = ( strlen( $file ) > strlen( $rel ) && 0 === substr_compare( $file, '/' . $rel, -strlen( $rel ) - 1, strlen( $rel ) + 1, true ) )
				? substr( $file, 0, -strlen( $rel ) )
				: dirname( $file ) . '/';
			$key = strtolower( $root );
			if ( ! isset( $copies[ $key ] ) ) {
				$copies[ $key ] = 'outside the plugins folder';
			}
		}
		return $copies;
	}

	private static function create_server( array $ability_names ): void {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			self::SERVER_NAME,
			self::SERVER_DESC,
			defined( 'ROYAL_MCP_VERSION' ) ? ROYAL_MCP_VERSION : '1.0.0',
			array( \WP\MCP\Transport\HttpTransport::class ),
			null, // error_handler → NullMcpErrorHandler default
			null, // observability_handler → NullMcpObservabilityHandler default
			$ability_names // tools (explicit list)
		);
	}

	/**
	 * Walk WP abilities registry, return every royal-mcp/* ability name.
	 */
	private static function collect_our_ability_names(): array {
		$names = array();
		$all   = wp_get_abilities();
		foreach ( $all as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( strpos( $name, 'royal-mcp/' ) === 0 ) {
				$names[] = $name;
			}
		}
		return $names;
	}
}
