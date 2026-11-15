<?php
/**
 * Agent readiness: checks what an AI agent finds when it visits this site (robots.txt
 * rules for AI bots, content signals, markdown for agents, the MCP server card, agent
 * skills, sign-in discovery and so on) and grades it on the 0-5 levels the public
 * agent-readiness scanners use. Read-only: it only reads the site's public answers.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Readiness {

	const RESULT_OPTION = 'royal_mcp_agent_readiness';
	const RUN_ACTION    = 'royal_mcp_run_agent_readiness';

	const LEVELS = [ 'Not ready', 'Basic web presence', 'Bot-aware', 'Agent-readable', 'Agent-integrated', 'Agent-native' ];

	const AI_BOTS = [ 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai', 'Google-Extended', 'PerplexityBot', 'Perplexity-User', 'CCBot', 'Bytespider', 'Applebot-Extended', 'Meta-ExternalAgent', 'Amazonbot' ];

	/** Set when the site could not reach itself: later checks are reported as not checked. */
	private static $unreachable = false;

	public static function register() {
		add_action( 'admin_post_' . self::RUN_ACTION, [ __CLASS__, 'handle_run' ] );
	}

	public static function last() {
		$r = get_option( self::RESULT_OPTION, null );
		return is_array( $r ) && isset( $r['checks'], $r['level'] ) && is_array( $r['checks'] ) ? $r : null;
	}

	public static function handle_run() {
		if ( ! current_user_can( \Royal_MCP\Admin\Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability(); per-site option only
			wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::RUN_ACTION );
		update_option( self::RESULT_OPTION, self::run(), false );
		wp_safe_redirect( admin_url( 'admin.php?page=royal-mcp#royal-mcp-agent-readiness' ) );
		exit;
	}

	/** One GET to this site; null when the site could not reach itself. */
	private static function fetch( $path, array $headers = [] ) {
		if ( self::$unreachable ) {
			return null;
		}
		$res = wp_remote_get( home_url( $path ), [ 'timeout' => 4, 'redirection' => 3, 'sslverify' => false, 'headers' => $headers, 'limit_response_size' => 262144 ] );
		if ( is_wp_error( $res ) ) {
			self::$unreachable = true;
			return null;
		}
		return [
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'type' => strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ),
			'link' => wp_remote_retrieve_header( $res, 'link' ),
			'body' => (string) wp_remote_retrieve_body( $res ),
		];
	}

	private static function json( $r ) {
		if ( ! $r || 200 !== $r['code'] ) {
			return null;
		}
		$j = json_decode( $r['body'], true );
		return is_array( $j ) ? $j : null;
	}

	private static function check( $id, $label, $state, $detail = '', $fix = '' ) {
		return [ 'id' => $id, 'label' => $label, 'state' => $state, 'detail' => $detail, 'fix' => $fix ];
	}

	private static function plugin_active( $needle ) {
		foreach ( (array) get_option( 'active_plugins', [] ) as $p ) {
			if ( false !== strpos( (string) $p, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run every check.
	 *
	 * @return array{level:int, level_name:string, checked_at:int, unreachable:bool, checks:array, next:string}
	 */
	public static function run() {
		self::$unreachable = false;
		$c        = [];
		$raif     = self::plugin_active( 'royal-ai-firewall' );
		$fc       = self::plugin_active( 'forgecache' );
		$robots_r = self::fetch( '/robots.txt' );
		$robots   = ( $robots_r && 200 === $robots_r['code'] ) ? $robots_r['body'] : '';
		$na       = static function ( $r ) { return null === $r ? 'unknown' : null; };

		// Discoverability.
		$c['robotsTxt'] = $na( $robots_r ) ? self::check( 'robotsTxt', __( 'robots.txt', 'royal-mcp' ), 'unknown' )
			: self::check( 'robotsTxt', __( 'robots.txt', 'royal-mcp' ), ( '' !== $robots && false !== strpos( $robots_r['type'], 'text/plain' ) && preg_match( '/^\s*user-agent\s*:/im', $robots ) ) ? 'pass' : 'fail', '', __( 'WordPress serves a robots.txt unless a file in the site root or the host replaces it. Make sure /robots.txt answers with plain text and at least one User-agent line.', 'royal-mcp' ) );
		$sm   = self::fetch( '/sitemap.xml' );
		$c['sitemap'] = ( null === $sm && '' === $robots ) ? self::check( 'sitemap', __( 'Sitemap', 'royal-mcp' ), 'unknown' )
			: self::check( 'sitemap', __( 'Sitemap', 'royal-mcp' ), ( ( $sm && 200 === $sm['code'] && preg_match( '/<(urlset|sitemapindex)\b/i', $sm['body'] ) ) || preg_match( '/^\s*sitemap\s*:/im', $robots ) ) ? 'pass' : 'fail', '', __( 'WordPress has a built-in sitemap at /wp-sitemap.xml; an SEO plugin usually adds /sitemap.xml. Listing it in robots.txt (Sitemap: line) also counts.', 'royal-mcp' ) );
		$home = self::fetch( '/' );
		$link = $home ? implode( ', ', (array) $home['link'] ) : '';
		$c['linkHeaders'] = null === $home ? self::check( 'linkHeaders', __( 'Link headers for agents', 'royal-mcp' ), 'unknown' )
			: self::check( 'linkHeaders', __( 'Link headers for agents', 'royal-mcp' ), preg_match( '/rel="?[^"]*\b(service-desc|service-doc|describedby|api-catalog)\b/i', $link ) ? 'pass' : 'fail', '', __( 'A Link header on the home page that points agents at your API catalog or service description. Few sites send one yet; it is one of three basic checks (two are enough).', 'royal-mcp' ) );

		// Bot access control.
		$bots = '/^\s*user-agent\s*:\s*(' . implode( '|', array_map( 'preg_quote', self::AI_BOTS ) ) . '|\*)\s*$/im';
		$rule_fix = $raif ? __( 'Set your AI bot rules in Royal AI Firewall; it writes them into robots.txt.', 'royal-mcp' ) : __( 'Add rules for AI bots (GPTBot, ClaudeBot, Google-Extended and others) to robots.txt. Royal AI Firewall manages these rules for you.', 'royal-mcp' );
		$c['robotsTxtAiRules'] = $na( $robots_r ) ? self::check( 'robotsTxtAiRules', __( 'Rules for AI bots in robots.txt', 'royal-mcp' ), 'unknown' )
			: self::check( 'robotsTxtAiRules', __( 'Rules for AI bots in robots.txt', 'royal-mcp' ), ( '' !== $robots && preg_match( $bots, $robots ) ) ? 'pass' : 'fail', '', $rule_fix );
		$c['contentSignals'] = $na( $robots_r ) ? self::check( 'contentSignals', __( 'Content signals (AI training / search / AI input)', 'royal-mcp' ), 'unknown' )
			: self::check( 'contentSignals', __( 'Content signals (AI training / search / AI input)', 'royal-mcp' ), preg_match( '/^\s*content-signal\s*:.*\b(ai-train|search|ai-input)\s*=/im', $robots ) ? 'pass' : 'fail', '', $raif ? __( 'Royal AI Firewall can add Content-Signal lines that say whether your content may be used for AI training, search and AI answers.', 'royal-mcp' ) : __( 'Add Content-Signal lines to robots.txt saying whether your content may be used for AI training, search and AI answers. Royal AI Firewall can manage them.', 'royal-mcp' ) );
		$wba = self::json( self::fetch( '/.well-known/http-message-signatures-directory' ) );
		$c['webBotAuth'] = self::check( 'webBotAuth', __( 'Web Bot Auth key directory (for information)', 'royal-mcp' ), is_array( $wba ) && ! empty( $wba['keys'] ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'info' ), __( 'Only sites that sign their own bot requests publish this; it does not lower the level.', 'royal-mcp' ) );

		// Content accessibility.
		$md = self::fetch( '/', [ 'Accept' => 'text/markdown' ] );
		$c['markdownNegotiation'] = null === $md ? self::check( 'markdownNegotiation', __( 'Markdown for AI agents', 'royal-mcp' ), 'unknown' )
			: self::check( 'markdownNegotiation', __( 'Markdown for AI agents', 'royal-mcp' ), false !== strpos( $md['type'], 'text/markdown' ) ? 'pass' : 'fail', '', $fc ? __( 'Switch on ForgeCache\'s agent mode so pages are served as markdown when an AI agent asks for it.', 'royal-mcp' ) : __( 'Serve pages as markdown when an agent asks for it (Accept: text/markdown). ForgeCache\'s agent mode does this.', 'royal-mcp' ) );

		// Discovery.
		$card = null;
		foreach ( [ '/.well-known/mcp/server-card.json', '/.well-known/mcp/server-cards.json', '/.well-known/mcp.json' ] as $p ) {
			$j = self::json( self::fetch( $p ) );
			if ( is_array( $j ) && ( ! empty( $j['serverInfo']['name'] ) || ! empty( $j['name'] ) ) ) { $card = $j; break; }
		}
		$served_fix = __( 'Royal MCP serves this. If it fails, run the Connection Doctor: pretty permalinks, a security plugin or the host can block /.well-known/ addresses.', 'royal-mcp' );
		$c['mcpServerCard'] = self::check( 'mcpServerCard', __( 'MCP server card', 'royal-mcp' ), $card ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', $served_fix );
		$a2a = self::json( self::fetch( '/.well-known/agent-card.json' ) );
		$c['a2aAgentCard'] = self::check( 'a2aAgentCard', __( 'A2A agent card', 'royal-mcp' ), ( is_array( $a2a ) && ! empty( $a2a['name'] ) && ! empty( $a2a['version'] ) && ! empty( $a2a['supportedInterfaces'] ) ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', __( 'For sites that run their own AI agent. Not needed for an MCP server; one integration check is enough for the level.', 'royal-mcp' ) );
		$skills = self::json( self::fetch( '/.well-known/agent-skills/index.json' ) );
		if ( ! is_array( $skills ) ) { $skills = self::json( self::fetch( '/.well-known/skills/index.json' ) ); }
		$c['agentSkills'] = self::check( 'agentSkills', __( 'Agent skills index', 'royal-mcp' ), ( is_array( $skills ) && isset( $skills['skills'] ) && is_array( $skills['skills'] ) ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', $served_fix );
		$settings = get_option( 'royal_mcp_settings', [] );
		$c['webMcp'] = self::check( 'webMcp', __( 'WebMCP for browser agents', 'royal-mcp' ), ( is_array( $settings ) && ! empty( $settings['webmcp_enabled'] ) ) ? 'pass' : 'fail', __( 'Read from Royal MCP\'s own setting.', 'royal-mcp' ), __( 'Switch on WebMCP under Royal MCP > Settings so AI agents in the browser can use the site\'s tools.', 'royal-mcp' ) );
		$cat = self::fetch( '/.well-known/api-catalog' );
		$cj  = $cat && 200 === $cat['code'] ? json_decode( $cat['body'], true ) : null;
		$c['apiCatalog'] = self::check( 'apiCatalog', __( 'API catalog', 'royal-mcp' ), ( $cat && false !== strpos( $cat['type'], 'linkset+json' ) && is_array( $cj ) && ! empty( $cj['linkset'] ) ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', __( 'A list of the site\'s APIs for agents. No Royal plugin publishes one yet; the MCP server card already covers the integration level.', 'royal-mcp' ) );
		$as = self::json( self::fetch( '/.well-known/oauth-authorization-server' ) );
		if ( ! is_array( $as ) ) { $as = self::json( self::fetch( '/.well-known/openid-configuration' ) ); }
		$c['oauthDiscovery'] = self::check( 'oauthDiscovery', __( 'Sign-in discovery (authorization server)', 'royal-mcp' ), ( is_array( $as ) && ! empty( $as['issuer'] ) && ! empty( $as['token_endpoint'] ) ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', $served_fix );
		$prm = self::json( self::fetch( '/.well-known/oauth-protected-resource' ) );
		$c['oauthProtectedResource'] = self::check( 'oauthProtectedResource', __( 'Sign-in discovery (protected resource)', 'royal-mcp' ), ( is_array( $prm ) && ! empty( $prm['resource'] ) && ! empty( $prm['authorization_servers'] ) ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', $served_fix );
		$am = self::fetch( '/auth.md' );
		$c['authMd'] = self::check( 'authMd', __( 'auth.md', 'royal-mcp' ), ( $am && 200 === $am['code'] && 'pass' === $c['oauthDiscovery']['state'] && 'pass' === $c['oauthProtectedResource']['state'] ) ? 'pass' : ( self::$unreachable ? 'unknown' : 'fail' ), '', __( 'A short page telling agents how to sign in. Optional: sign-in discovery above already counts for the level.', 'royal-mcp' ) );

		$p = static function ( $id ) use ( $c ) { return 'pass' === $c[ $id ]['state']; };
		$l1 = ( (int) $p( 'robotsTxt' ) + (int) $p( 'sitemap' ) + (int) $p( 'linkHeaders' ) ) >= 2;
		$l2 = $l1 && $p( 'robotsTxtAiRules' ) && $p( 'contentSignals' );
		$l3 = $l2 && $p( 'markdownNegotiation' );
		$integration = [ 'mcpServerCard', 'a2aAgentCard', 'agentSkills', 'apiCatalog' ];
		$l4 = $l3 && count( array_filter( $integration, $p ) ) >= 1;
		$groups = (int) $p( 'webBotAuth' ) + (int) ( count( array_filter( $integration, $p ) ) === 4 ) + (int) ( $p( 'oauthDiscovery' ) || $p( 'oauthProtectedResource' ) || $p( 'authMd' ) );
		$l5 = $l4 && $groups >= 2;
		$level = $l5 ? 5 : ( $l4 ? 4 : ( $l3 ? 3 : ( $l2 ? 2 : ( $l1 ? 1 : 0 ) ) ) );
		$next = [
			0 => __( 'Next level: two of robots.txt, a sitemap and agent Link headers.', 'royal-mcp' ),
			1 => __( 'Next level: rules for AI bots and content signals in robots.txt.', 'royal-mcp' ),
			2 => __( 'Next level: serve markdown to AI agents.', 'royal-mcp' ),
			3 => __( 'Next level: one integration document (the MCP server card Royal MCP serves counts).', 'royal-mcp' ),
			4 => __( 'Next level: two of a Web Bot Auth key directory, all four integration documents, sign-in discovery.', 'royal-mcp' ),
			5 => '',
		][ $level ];
		return [
			'level'       => $level,
			'level_name'  => self::LEVELS[ $level ],
			'checked_at'  => time(),
			'unreachable' => self::$unreachable,
			'checks'      => array_values( $c ),
			'next'        => $next,
			'not_checked' => __( 'Not checked here: DNS records for agents, AI catalogs, personal agent and commerce protocols.', 'royal-mcp' ),
		];
	}

	/** Dashboard card. */
	public static function render_card() {
		$last = self::last();
		echo '<div class="royal-mcp-dash-card royal-mcp-dash-doctor" id="royal-mcp-agent-readiness">';
		echo '<h2>' . esc_html__( 'Agent readiness', 'royal-mcp' ) . '</h2>';
		echo '<p class="royal-mcp-dash-sub">' . esc_html__( 'What an AI agent finds when it visits this site, graded on the 0-5 levels the public agent-readiness scanners use.', 'royal-mcp' ) . '</p>';
		if ( null === $last ) {
			echo '<p>' . esc_html__( 'Not run yet.', 'royal-mcp' ) . '</p>';
		} else {
			/* translators: 1: level number 0-5, 2: level name */
			echo '<p><strong>' . esc_html( sprintf( __( 'Level %1$d of 5: %2$s', 'royal-mcp' ), (int) $last['level'], (string) ( $last['level_name'] ?? '' ) ) ) . '</strong></p>';
			if ( ! empty( $last['unreachable'] ) ) {
				echo '<p class="royal-mcp-doctor-first">' . esc_html__( 'The site could not reach its own addresses, so some checks could not run. The level may be higher than shown.', 'royal-mcp' ) . '</p>';
			}
			$icons = [ 'pass' => '&#10003;', 'fail' => '&#10007;', 'unknown' => '?', 'info' => 'i' ];
			$map   = [ 'pass' => 'ok', 'fail' => 'problem', 'unknown' => 'warn', 'info' => 'info' ];
			echo '<ol class="royal-mcp-doctor-steps">';
			foreach ( (array) $last['checks'] as $ch ) {
				if ( ! is_array( $ch ) ) {
					continue;
				}
				$state = (string) ( $ch['state'] ?? 'unknown' );
				echo '<li class="is-' . esc_attr( $map[ $state ] ?? 'info' ) . '"><span class="royal-mcp-doctor-mark" aria-hidden="true">' . wp_kses( $icons[ $state ] ?? '?', [] ) . '</span><span><strong>' . esc_html( (string) ( $ch['label'] ?? '' ) ) . '</strong>';
				$text = 'pass' === $state ? (string) ( $ch['detail'] ?? '' ) : trim( (string) ( $ch['detail'] ?? '' ) . ' ' . (string) ( $ch['fix'] ?? '' ) );
				if ( '' !== $text && 'pass' !== $state ) {
					echo '<span class="royal-mcp-doctor-detail">' . esc_html( $text ) . '</span>';
				}
				echo '</span></li>';
			}
			echo '</ol>';
			if ( ! empty( $last['next'] ) ) {
				echo '<p>' . esc_html( (string) $last['next'] ) . '</p>';
			}
			echo '<p class="royal-mcp-dash-sub">' . esc_html( (string) ( $last['not_checked'] ?? '' ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '">';
		wp_nonce_field( self::RUN_ACTION );
		echo '<button type="submit" class="button">' . esc_html( null === $last ? __( 'Check agent readiness', 'royal-mcp' ) : __( 'Check again', 'royal-mcp' ) ) . '</button>';
		echo '</form>';
		echo '</div>';
	}
}
