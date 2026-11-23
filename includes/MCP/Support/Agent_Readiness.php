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

	const AI_BOTS = [ 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai', 'Google-Extended', 'Google-CloudVertexBot', 'PerplexityBot', 'Perplexity-User', 'CCBot', 'Bytespider', 'Applebot-Extended', 'Meta-ExternalAgent', 'Amazonbot', 'DuckAssistBot', 'MistralAI-User', 'cohere-ai' ];

	/** The whole run stays inside this many seconds. */
	const RUN_BUDGET = 25;

	/** Set when any request could not be made or was blocked: the result says so. */
	private static $unreachable = false;
	/** Network failures in a row; after two the site cannot reach itself and the rest is skipped. */
	private static $fails_in_row = 0;
	/** When the run started. */
	private static $started = 0.0;

	public static function register() {
		add_action( 'admin_post_' . self::RUN_ACTION, [ __CLASS__, 'handle_run' ] );
	}

	/** The stored result, cleaned (a damaged option gives null or safe values). */
	public static function last() {
		$r = get_option( self::RESULT_OPTION, null );
		if ( ! is_array( $r ) || ! isset( $r['checks'], $r['level'] ) || ! is_array( $r['checks'] ) ) {
			return null;
		}
		$str    = static function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; };
		$level  = max( 0, min( 5, (int) $r['level'] ) );
		$checks = [];
		foreach ( $r['checks'] as $ch ) {
			if ( is_array( $ch ) ) {
				$checks[] = [
					'id'     => $str( $ch['id'] ?? '' ),
					'label'  => $str( $ch['label'] ?? '' ),
					'state'  => in_array( $ch['state'] ?? '', [ 'pass', 'fail', 'unknown', 'info' ], true ) ? $ch['state'] : 'unknown',
					'detail' => $str( $ch['detail'] ?? '' ),
					'fix'    => $str( $ch['fix'] ?? '' ),
				];
			}
		}
		$by_id   = array_column( $checks, null, 'id' );
		$counted = array_values( array_filter( $checks, static function ( $c ) { return 'info' !== $c['state']; } ) );
		return [
			'level'       => $level,
			'level_name'  => self::LEVELS[ $level ],
			'checked_at'  => (int) ( $r['checked_at'] ?? 0 ),
			// Only a check the level depends on, and that could not be made, makes the level uncertain.
			'unreachable' => (bool) array_filter( self::level_checks( $level ), static function ( $id ) use ( $by_id ) { return 'unknown' === ( $by_id[ $id ]['state'] ?? '' ); } ),
			'checks'      => $checks,
			'passed'      => count( array_filter( $counted, static function ( $c ) { return 'pass' === $c['state']; } ) ),
			'counted'     => count( $counted ),
			'next'        => self::next_hint( $level, $by_id ),
			'not_checked' => $str( $r['not_checked'] ?? '' ),
		];
	}

	/** Check ids the current level and the next one are decided by. */
	private static function level_checks( $level ) {
		$by_level = [
			[ 'robotsTxt', 'sitemap', 'linkHeaders' ],
			[ 'robotsTxtAiRules', 'contentSignals' ],
			[ 'markdownNegotiation' ],
			[ 'mcpServerCard', 'a2aAgentCard', 'agentSkills', 'apiCatalog' ],
			[ 'webBotAuth', 'mcpServerCard', 'a2aAgentCard', 'agentSkills', 'apiCatalog', 'oauthDiscovery', 'oauthProtectedResource', 'authMd' ],
		];
		$ids = [];
		for ( $i = 0; $i <= min( 4, (int) $level ); $i++ ) {
			$ids = array_merge( $ids, $by_level[ $i ] );
		}
		return array_values( array_unique( $ids ) );
	}

	/** What the next level still needs, naming only the checks that do not pass yet. */
	private static function next_hint( $level, array $by_id ) {
		$open = static function ( array $ids ) use ( $by_id ) {
			$out = [];
			foreach ( $ids as $id ) {
				if ( isset( $by_id[ $id ] ) && 'pass' !== $by_id[ $id ]['state'] ) {
					$out[] = $by_id[ $id ]['label'];
				}
			}
			return $out;
		};
		switch ( (int) $level ) {
			case 0:
				/* translators: %s: list of checks */
				return sprintf( __( 'Next level: two of robots.txt, a sitemap and agent Link headers (not passing yet: %s).', 'royal-mcp' ), implode( ', ', $open( [ 'robotsTxt', 'sitemap', 'linkHeaders' ] ) ) );
			case 1:
				$need = $open( [ 'robotsTxtAiRules', 'contentSignals' ] );
				/* translators: %s: list of checks */
				return $need ? sprintf( __( 'Next level: %s.', 'royal-mcp' ), implode( ' and ', $need ) ) : '';
			case 2:
				return __( 'Next level: Markdown for AI agents.', 'royal-mcp' );
			case 3:
				return __( 'Next level: one integration document (the MCP server card Royal MCP serves counts).', 'royal-mcp' );
			case 4:
				return __( 'Next level: two of a Web Bot Auth key directory, all four integration documents, sign-in discovery.', 'royal-mcp' );
		}
		return '';
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

	/**
	 * Scheme, host and port of a URL in one comparable string (default ports filled in);
	 * empty when the URL has no http(s) scheme or no host.
	 */
	public static function origin( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $p['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$port = isset( $p['port'] ) ? (int) $p['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( (string) $p['host'] ) . ':' . $port;
	}

	/**
	 * One GET to this site, following up to three redirects that stay on the site's own
	 * scheme, host and port. Null when the request could not be made, was blocked
	 * (401 / 403 / 429 / 5xx: a firewall or maintenance page), the time budget is used up,
	 * or the site has already failed to reach itself twice in a row.
	 */
	private static function fetch( $path, array $headers = [] ) {
		if ( self::$fails_in_row >= 2 ) {
			self::$unreachable = true;
			return null;
		}
		$home = self::origin( home_url( '/' ) );
		$url  = home_url( $path );
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			if ( '' === $home || self::origin( $url ) !== $home ) {
				return [ 'code' => 0, 'type' => '', 'link' => '', 'body' => '' ]; // redirected off the site: not followed
			}
			// Each hop gets only what is left of the run's budget.
			$left = self::RUN_BUDGET - ( microtime( true ) - self::$started );
			if ( $left < 1 ) {
				self::$unreachable = true;
				return null;
			}
			$res = wp_remote_get( $url, [ 'timeout' => min( 4, max( 1, (int) floor( $left ) ) ), 'redirection' => 0, 'sslverify' => false, 'headers' => $headers, 'limit_response_size' => 262144 ] );
			if ( is_wp_error( $res ) ) {
				self::$fails_in_row++;
				self::$unreachable = true;
				return null;
			}
			self::$fails_in_row = 0;
			$code = (int) wp_remote_retrieve_response_code( $res );
			$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $loc ) {
				$url = 0 === strpos( $loc, '/' ) ? home_url( $loc ) : $loc;
				continue;
			}
			if ( in_array( $code, [ 401, 403, 429 ], true ) || $code >= 500 ) {
				self::$unreachable = true;
				return null;
			}
			return [
				'code' => $code,
				'type' => strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ),
				'link' => wp_remote_retrieve_header( $res, 'link' ),
				'body' => (string) wp_remote_retrieve_body( $res ),
			];
		}
		return [ 'code' => 0, 'type' => '', 'link' => '', 'body' => '' ];
	}

	private static function json( $r ) {
		if ( ! $r || 200 !== $r['code'] ) {
			return null;
		}
		$j = json_decode( $r['body'], true );
		return is_array( $j ) ? $j : null;
	}

	/** robots.txt as lines without comments, blank lines or a leading byte-order mark. */
	private static function robots_lines( $robots ) {
		$robots = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $robots );
		$out    = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $robots ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/** Whether any Link header names one of the given relation types (each link parsed on its own). */
	private static function link_has_rel( $headers, array $rels ) {
		foreach ( (array) $headers as $h ) {
			foreach ( preg_split( '/,(?=\s*<)/', (string) $h ) as $link ) {
				if ( preg_match( '/;\s*rel\s*=\s*(?:"([^"]*)"|([^;,\s]+))/i', $link, $m ) ) {
					$vals = preg_split( '/\s+/', strtolower( '' !== $m[1] ? $m[1] : ( $m[2] ?? '' ) ) );
					if ( array_intersect( $vals, $rels ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * One check result. A "fail" whose request could not be made ($fetched null) is reported
	 * as "unknown" instead.
	 */
	private static function check( $id, $label, $pass, $fetched, $fix = '', $detail = '', $fail_state = 'fail' ) {
		$state = $pass ? 'pass' : ( null === $fetched ? 'unknown' : $fail_state );
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
	 * @return array{level:int, level_name:string, checked_at:int, unreachable:bool, checks:array, next:string, not_checked:string}
	 */
	public static function run() {
		self::$unreachable  = false;
		self::$fails_in_row = 0;
		self::$started      = microtime( true );
		$c    = [];
		$raif = self::plugin_active( 'royal-ai-firewall' );
		$fc   = self::plugin_active( 'forgecache' );

		// Discoverability.
		$robots_r = self::fetch( '/robots.txt' );
		$robots_ok = $robots_r && 200 === $robots_r['code'] && false !== strpos( $robots_r['type'], 'text/plain' );
		$robots   = $robots_ok ? self::robots_lines( $robots_r['body'] ) : '';
		$c['robotsTxt'] = self::check( 'robotsTxt', __( 'robots.txt', 'royal-mcp' ), '' !== $robots && preg_match( '/^user-agent\s*:/im', $robots ), $robots_r, __( 'WordPress serves a robots.txt unless a file in the site root or the host replaces it. Make sure /robots.txt answers with plain text and at least one User-agent line.', 'royal-mcp' ) );
		$sm = self::fetch( '/sitemap.xml' );
		$sm_ok = ( $sm && 200 === $sm['code'] && preg_match( '/<(urlset|sitemapindex)\b/i', $sm['body'] ) ) || preg_match( '/^sitemap\s*:/im', $robots );
		$c['sitemap'] = self::check( 'sitemap', __( 'Sitemap', 'royal-mcp' ), $sm_ok, ( null === $sm && null === $robots_r ) ? null : [], __( 'WordPress has a built-in sitemap at /wp-sitemap.xml; an SEO plugin usually adds /sitemap.xml. Listing it in robots.txt (Sitemap: line) also counts.', 'royal-mcp' ) );
		if ( ! $sm_ok && null === $sm ) {
			$c['sitemap']['state'] = 'unknown';
		}
		$home = self::fetch( '/' );
		$c['linkHeaders'] = self::check( 'linkHeaders', __( 'Link headers for agents', 'royal-mcp' ), $home && self::link_has_rel( $home['link'], [ 'service-desc', 'service-doc', 'describedby', 'api-catalog' ] ), $home, __( 'A Link header on the home page that points agents at your API catalog or service description. Few sites send one yet; it is one of three basic checks (two are enough).', 'royal-mcp' ) );

		// Bot access control.
		$bots     = '/^user-agent\s*:\s*(' . implode( '|', array_map( static function ( $b ) { return preg_quote( $b, '/' ); }, self::AI_BOTS ) ) . '|\*)$/im';
		$rule_fix = $raif ? __( 'Set your AI bot rules in Royal AI Firewall; it writes them into robots.txt.', 'royal-mcp' ) : __( 'Add rules for AI bots (GPTBot, ClaudeBot, Google-Extended and others) to robots.txt. Royal AI Firewall manages these rules for you.', 'royal-mcp' );
		$c['robotsTxtAiRules'] = self::check( 'robotsTxtAiRules', __( 'Rules for AI bots in robots.txt', 'royal-mcp' ), '' !== $robots && preg_match( $bots, $robots ), $robots_r, $rule_fix );
		$c['contentSignals'] = self::check( 'contentSignals', __( 'Content signals (AI training / search / AI input)', 'royal-mcp' ), (bool) preg_match( '/^content-signal\s*:.*\b(ai-train|search|ai-input)\s*=\s*(yes|no)\b/im', $robots ), $robots_r, $raif ? __( 'Royal AI Firewall can add Content-Signal lines that say whether your content may be used for AI training, search and AI answers.', 'royal-mcp' ) : __( 'Add Content-Signal lines to robots.txt saying whether your content may be used for AI training, search and AI answers. Royal AI Firewall can manage them.', 'royal-mcp' ) );
		$wba_r = self::fetch( '/.well-known/http-message-signatures-directory' );
		$wba   = self::json( $wba_r );
		$c['webBotAuth'] = self::check( 'webBotAuth', __( 'Web Bot Auth key directory (for information)', 'royal-mcp' ), is_array( $wba ) && ! empty( $wba['keys'] ), $wba_r, '', __( 'Only sites that sign their own bot requests publish this; it does not lower the level.', 'royal-mcp' ), 'info' );

		// Content accessibility.
		$md = self::fetch( '/', [ 'Accept' => 'text/markdown' ] );
		$c['markdownNegotiation'] = self::check( 'markdownNegotiation', __( 'Markdown for AI agents', 'royal-mcp' ), $md && false !== strpos( $md['type'], 'text/markdown' ), $md, $fc ? __( 'Switch on ForgeCache\'s agent mode so pages are served as markdown when an AI agent asks for it.', 'royal-mcp' ) : __( 'Serve pages as markdown when an agent asks for it (Accept: text/markdown). ForgeCache\'s agent mode does this.', 'royal-mcp' ) );

		// Discovery.
		$served_fix = __( 'Royal MCP serves this. If it fails, run the Connection Doctor: pretty permalinks, a security plugin or the host can block /.well-known/ addresses.', 'royal-mcp' );
		$card = false; $card_r = null;
		foreach ( [ '/.well-known/mcp/server-card.json', '/.well-known/mcp/server-cards.json', '/.well-known/mcp.json' ] as $p ) {
			$fr = self::fetch( $p );
			$card_r = $card_r ?? $fr;
			$j = self::json( $fr );
			if ( is_array( $j ) && ( ! empty( $j['serverInfo']['name'] ) || ! empty( $j['name'] ) ) ) { $card = true; break; }
		}
		$c['mcpServerCard'] = self::check( 'mcpServerCard', __( 'MCP server card', 'royal-mcp' ), $card, $card_r, $served_fix );
		$a2a_r = self::fetch( '/.well-known/agent-card.json' );
		$a2a   = self::json( $a2a_r );
		$c['a2aAgentCard'] = self::check( 'a2aAgentCard', __( 'A2A agent card', 'royal-mcp' ), is_array( $a2a ) && ! empty( $a2a['name'] ) && ! empty( $a2a['version'] ) && ! empty( $a2a['supportedInterfaces'] ), $a2a_r, __( 'For sites that run their own AI agent. Not needed for an MCP server; one integration check is enough for the level.', 'royal-mcp' ) );
		$sk_r   = self::fetch( '/.well-known/agent-skills/index.json' );
		$skills = self::json( $sk_r );
		if ( ! is_array( $skills ) ) {
			$sk_r2  = self::fetch( '/.well-known/skills/index.json' );
			$skills = self::json( $sk_r2 );
			$sk_r   = $sk_r ?? $sk_r2;
		}
		$c['agentSkills'] = self::check( 'agentSkills', __( 'Agent skills index', 'royal-mcp' ), is_array( $skills ) && isset( $skills['skills'] ) && is_array( $skills['skills'] ), $sk_r, $served_fix );
		$settings = get_option( 'royal_mcp_settings', [] );
		$c['webMcp'] = self::check( 'webMcp', __( 'WebMCP for browser agents', 'royal-mcp' ), is_array( $settings ) && ! empty( $settings['webmcp_enabled'] ), [], __( 'Switch on WebMCP under Royal MCP → Safety → Advanced so AI agents in the browser can use the site\'s tools.', 'royal-mcp' ), __( 'Read from Royal MCP\'s own setting.', 'royal-mcp' ) );
		$cat = self::fetch( '/.well-known/api-catalog' );
		$cj  = $cat && 200 === $cat['code'] ? json_decode( $cat['body'], true ) : null;
		$c['apiCatalog'] = self::check( 'apiCatalog', __( 'API catalog', 'royal-mcp' ), $cat && false !== strpos( $cat['type'], 'linkset+json' ) && is_array( $cj ) && ! empty( $cj['linkset'] ), $cat, __( 'A list of the site\'s APIs for agents. No Royal plugin publishes one yet; the MCP server card already covers the integration level.', 'royal-mcp' ) );
		$as_r = self::fetch( '/.well-known/oauth-authorization-server' );
		$as   = self::json( $as_r );
		if ( ! is_array( $as ) ) {
			$as_r2 = self::fetch( '/.well-known/openid-configuration' );
			$as    = self::json( $as_r2 );
			$as_r  = $as_r ?? $as_r2;
		}
		$c['oauthDiscovery'] = self::check( 'oauthDiscovery', __( 'Sign-in discovery (authorization server)', 'royal-mcp' ), is_array( $as ) && ! empty( $as['issuer'] ) && ! empty( $as['token_endpoint'] ), $as_r, $served_fix );
		$prm_r = self::fetch( '/.well-known/oauth-protected-resource' );
		$prm   = self::json( $prm_r );
		$c['oauthProtectedResource'] = self::check( 'oauthProtectedResource', __( 'Sign-in discovery (protected resource)', 'royal-mcp' ), is_array( $prm ) && ! empty( $prm['resource'] ) && ! empty( $prm['authorization_servers'] ), $prm_r, $served_fix );
		$am = self::fetch( '/auth.md' );
		$c['authMd'] = self::check( 'authMd', __( 'auth.md', 'royal-mcp' ), $am && 200 === $am['code'] && 'pass' === $c['oauthProtectedResource']['state'], $am, __( 'A short page telling agents how to sign in. Optional: sign-in discovery above already counts for the level.', 'royal-mcp' ) );

		$p  = static function ( $id ) use ( $c ) { return 'pass' === $c[ $id ]['state']; };
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
			echo '<p><strong>' . esc_html( sprintf( __( 'Level %1$d of 5: %2$s', 'royal-mcp' ), $last['level'], $last['level_name'] ) ) . '</strong>';
			/* translators: 1: checks passing, 2: checks counted */
			echo ' <span class="royal-mcp-dash-sub">' . esc_html( sprintf( __( '%1$d of %2$d checks pass', 'royal-mcp' ), $last['passed'], $last['counted'] ) ) . '</span></p>';
			if ( $last['unreachable'] ) {
				echo '<p class="royal-mcp-dash-sub">' . esc_html__( 'A check this level depends on could not be completed (the site did not answer in time, or a firewall or maintenance page answered). It shows as "?", so the level may be higher than shown; try Check again.', 'royal-mcp' ) . '</p>';
			}
			$icons = [ 'pass' => '&#10003;', 'fail' => '&#10007;', 'unknown' => '?', 'info' => 'i' ];
			$map   = [ 'pass' => 'ok', 'fail' => 'problem', 'unknown' => 'warn', 'info' => 'info' ];
			echo '<ol class="royal-mcp-doctor-steps">';
			foreach ( $last['checks'] as $ch ) {
				$state = $ch['state'];
				echo '<li class="is-' . esc_attr( $map[ $state ] ) . '"><span class="royal-mcp-doctor-mark" aria-hidden="true">' . wp_kses( $icons[ $state ], [] ) . '</span><span><strong>' . esc_html( $ch['label'] ) . '</strong>';
				$text = trim( $ch['detail'] . ' ' . $ch['fix'] );
				if ( '' !== $text && 'pass' !== $state ) {
					echo '<span class="royal-mcp-doctor-detail">' . esc_html( $text ) . '</span>';
				}
				echo '</span></li>';
			}
			echo '</ol>';
			if ( '' !== $last['next'] ) {
				echo '<p>' . esc_html( $last['next'] ) . '</p>';
			}
			if ( '' !== $last['not_checked'] ) {
				echo '<p class="royal-mcp-dash-sub">' . esc_html( $last['not_checked'] ) . '</p>';
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '">';
		wp_nonce_field( self::RUN_ACTION );
		echo '<button type="submit" class="button">' . esc_html( null === $last ? __( 'Check agent readiness', 'royal-mcp' ) : __( 'Check again', 'royal-mcp' ) ) . '</button>';
		echo '</form>';
		echo '</div>';
	}
}
