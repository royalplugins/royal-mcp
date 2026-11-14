<?php
/**
 * "Dashboard" screen: the MCP address and how to connect, what a client is
 * sent, who is connected, the safety mode, what needs attention, and what AI
 * clients changed lately.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

use Royal_MCP\MCP\Support\Change_View;
use Royal_MCP\MCP\Support\Site_Context;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Page {

	/** The plugin's top menu entry opens this screen. */
	const PAGE_SLUG = 'royal-mcp';

	/** Groups of recent changes shown on the dashboard. */
	const RECENT_GROUPS = 4;

	/** Newest changes read to build those groups. */
	const RECENT_CHANGES = 60;

	public static function register() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== (string) $hook ) {
			return;
		}
		$path = ROYAL_MCP_PLUGIN_DIR . 'assets/css/dashboard.css';
		wp_enqueue_style( 'royal-mcp-dashboard', ROYAL_MCP_PLUGIN_URL . 'assets/css/dashboard.css', [], ROYAL_MCP_VERSION . '.' . ( file_exists( $path ) ? filemtime( $path ) : '0' ) );
	}

	public static function url( $slug, array $args = [] ) {
		return add_query_arg( [ 'page' => $slug ] + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Everything the screen shows, as plain data.
	 *
	 * @return array
	 */
	public static function data() {
		global $wpdb;
		$settings = get_option( 'royal_mcp_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$enabled  = ! empty( $settings['enabled'] );

		if ( ! empty( $settings['read_only_mode'] ) ) {
			$mode = [ 'key' => 'read-only', 'label' => __( 'Read-only', 'royal-mcp' ), 'note' => __( 'AI clients can read; nothing can be changed.', 'royal-mcp' ) ];
		} elseif ( ! empty( $settings['drafts_only_mode'] ) ) {
			$mode = [ 'key' => 'drafts-only', 'label' => __( 'Drafts only', 'royal-mcp' ), 'note' => __( 'AI clients can write drafts; nothing goes live.', 'royal-mcp' ) ];
		} else {
			$mode = [ 'key' => 'full', 'label' => __( 'Full access', 'royal-mcp' ), 'note' => __( 'No site-wide limit. A connection can still have a lower level of its own.', 'royal-mcp' ) ];
		}

		// Connected means a live sign-in, counted per app; a registered app that never signed in, or was revoked, is not connected.
		$clients = [ 'active' => 0, 'pending' => 0 ];
		try {
			$clients['active'] = count( array_unique( array_column( (array) \Royal_MCP\OAuth\Token_Store::list_connections( 500 ), 'client_id' ) ) );
		} catch ( \Throwable $e ) {
			$clients['active'] = 0;
		}
		$table   = \Royal_MCP\OAuth\Token_Store::clients_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check of our own table
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			$safe = esc_sql( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above; two counts for the dashboard
			foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM `{$safe}` GROUP BY status", ARRAY_A ) as $row ) {
				if ( 'pending_approval' === $row['status'] ) {
					$clients['pending'] = (int) $row['c'];
				}
			}
		}

		try {
			$tools = \Royal_MCP\MCP\Server::list_summary();
		} catch ( \Throwable $e ) {
			$tools = null;
		}

		$others = [];
		try {
			foreach ( (array) \Royal_MCP\Integrations\Coexistence_Check::known_servers_present() as $server ) {
				$name = is_array( $server ) && ! empty( $server['on'] ) && isset( $server['name'] ) ? trim( (string) $server['name'] ) : '';
				if ( '' !== $name ) {
					$others[] = $name;
				}
			}
		} catch ( \Throwable $e ) {
			$others = [];
		}

		$context_set = '' !== Site_Context::get();
		try {
			// The newest changes only: enough to show a few groups without reading the whole list.
			$changes = array_values( array_filter( Undo_Store::all_changes( null, false, self::RECENT_CHANGES ), [ Change_View::class, 'can_view' ] ) );
			$groups  = array_slice( Change_View::groups( $changes ), 0, self::RECENT_GROUPS );
		} catch ( \Throwable $e ) {
			$changes = [];
			$groups  = [];
		}
		$window_full = count( $changes ) >= self::RECENT_CHANGES;

		$attention = [];
		if ( ! $enabled ) {
			$attention[] = [ 'text' => __( 'Royal MCP is switched off, so no AI client can connect.', 'royal-mcp' ), 'link' => self::url( Menu_Layout::CONNECTIONS ), 'action' => __( 'Open Connections', 'royal-mcp' ) ];
		}
		if ( $clients['pending'] > 0 ) {
			/* translators: %s: number of AI clients */
			$attention[] = [ 'text' => sprintf( _n( '%s AI client is waiting for your approval.', '%s AI clients are waiting for your approval.', $clients['pending'], 'royal-mcp' ), number_format_i18n( $clients['pending'] ) ), 'link' => self::url( Pending_Clients_Page::MENU_SLUG ), 'action' => __( 'Review', 'royal-mcp' ) ];
		}
		if ( $others ) {
			/* translators: %s: plugin names */
			$attention[] = [ 'text' => sprintf( __( 'Another MCP plugin is active on this site (%s). AI clients may connect to it instead of Royal MCP.', 'royal-mcp' ), implode( ', ', $others ) ), 'link' => Help_Page::tab_url( 'troubleshooting' ), 'action' => __( 'See how they work together', 'royal-mcp' ) ];
		}
		if ( ! $context_set ) {
			$attention[] = [ 'text' => __( 'You have not written a site context yet. A few lines about your site save you explaining it in every conversation.', 'royal-mcp' ), 'link' => self::url( Context_Page::PAGE_SLUG ), 'action' => __( 'Write it', 'royal-mcp' ) ];
		}
		if ( Site_Context::masked_parts() > 0 ) {
			$attention[] = [ 'text' => __( 'Part of your site context has the shape of a password or key and is hidden from AI clients.', 'royal-mcp' ), 'link' => self::url( Context_Page::PAGE_SLUG ), 'action' => __( 'Check it', 'royal-mcp' ) ];
		}

		// Royal plugins and whether each is on this site; this plugin itself is left out.
		$family = [];
		if ( class_exists( '\\Royal_MCP\\Chrome\\Royal_MCP_Chrome' ) ) {
			try {
				foreach ( (array) \Royal_MCP\Chrome\Royal_MCP_Chrome::get_instance()->get_family_plugins_with_state() as $slug => $plugin ) {
					if ( is_array( $plugin ) && 'here' !== ( $plugin['state'] ?? '' ) && ! empty( $plugin['name'] ) && ! empty( $plugin['basename'] ) ) {
						$family[ (string) $slug ] = $plugin;
					}
				}
			} catch ( \Throwable $e ) {
				$family = [];
			}
		}

		return [
			'family'      => $family,
			'enabled'     => $enabled,
			'endpoint'    => rest_url( 'royal-mcp/v1/mcp' ),
			'mode'        => $mode,
			'clients'     => $clients,
			'tools'       => $tools,
			'context_set' => $context_set,
			'others'      => $others,
			'attention'   => $attention,
			'groups'      => $groups,
			'changes'     => count( $changes ),
			'window_full' => $window_full,
		];
	}

	/** Things to ask an AI client first, for the owner to copy. */
	private static function starters() {
		return [
			__( 'Look at my site and tell me what you can do here. Do not change anything yet.', 'royal-mcp' ),
			__( 'List my five most recent posts with their status, and tell me which ones have no featured image.', 'royal-mcp' ),
			__( 'Write a draft post about [topic] in the tone of my site. Leave it as a draft for me to review.', 'royal-mcp' ),
			__( 'Check my site\'s SEO titles and descriptions and tell me which pages are missing them. Do not change anything.', 'royal-mcp' ),
		];
	}

	private static function icon( $name ) {
		$paths = [
			'copy'  => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			'arrow' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		];
		return '<svg style="width:14px;height:14px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	public static function render() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); every figure shown is this site's own
			wp_die( esc_html__( 'You do not have permission to view this page.', 'royal-mcp' ) );
		}
		$d      = self::data();
		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$btn    = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;';

		echo '<div class="wrap royal-mcp-dash">';
		echo '<span id="royal-mcp-dash-live" class="screen-reader-text" role="status" aria-live="polite"></span>';
		echo '<div class="royal-mcp-dash-head">';
		echo '<h1>' . esc_html__( 'Royal MCP', 'royal-mcp' ) . '</h1>';
		echo '<span class="royal-mcp-dash-pill ' . ( $d['enabled'] ? 'is-on' : 'is-off' ) . '">' . esc_html( $d['enabled'] ? __( 'On', 'royal-mcp' ) : __( 'Off', 'royal-mcp' ) ) . '</span>';
		echo '<a class="royal-mcp-dash-help" href="' . esc_url( self::url( Help_Page::SLUG ) ) . '">' . esc_html__( 'Help', 'royal-mcp' ) . '</a>';
		echo '</div>';
		// WordPress puts admin notices (ours and other plugins') after this marker, not inside the header row.
		echo '<hr class="wp-header-end">';

		// ---- connect
		echo '<div class="royal-mcp-dash-card royal-mcp-dash-connect">';
		echo '<h2>' . esc_html__( 'Connect an AI client', 'royal-mcp' ) . '</h2>';
		echo '<p class="royal-mcp-dash-sub">' . esc_html__( 'This is your site\'s MCP address. Paste it into Claude, ChatGPT or any AI app that supports MCP, then sign in with your WordPress account when it asks.', 'royal-mcp' ) . '</p>';
		echo '<div class="royal-mcp-dash-url"><input type="text" readonly id="royal-mcp-dash-endpoint" value="' . esc_attr( $d['endpoint'] ) . '" aria-label="' . esc_attr__( 'MCP address', 'royal-mcp' ) . '" onfocus="this.select();" />';
		echo '<button type="button" class="button button-primary" id="royal-mcp-dash-copy" style="' . esc_attr( $btn ) . '">' . self::icon( 'copy' ) . '<span>' . esc_html__( 'Copy', 'royal-mcp' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() returns fixed SVG markup
		echo '<ol class="royal-mcp-dash-steps"><li>' . esc_html__( 'Copy the address.', 'royal-mcp' ) . '</li><li>' . esc_html__( 'In your AI app, add a custom connector (MCP server) and paste it.', 'royal-mcp' ) . '</li><li>' . esc_html__( 'Sign in and approve. The app can now work on this site.', 'royal-mcp' ) . '</li></ol>';
		echo '<p><a href="' . esc_url( self::url( Help_Page::SLUG ) ) . '">' . esc_html__( 'Step-by-step guides for each AI app', 'royal-mcp' ) . '</a> &middot; <a href="' . esc_url( self::url( Menu_Layout::CONNECTIONS ) ) . '">' . esc_html__( 'Connect with an API key instead', 'royal-mcp' ) . '</a></p>';
		echo '</div>';

		// ---- status strip
		echo '<div class="royal-mcp-dash-strip">';
		if ( is_array( $d['tools'] ) ) {
			/* translators: 1: number of tokens in thousands, 2: number of tools left out */
			$tools_note = sprintf( __( 'to a connection signed in as an administrator: about %1$sk tokens; %2$s left out (plugin not active). Other connections are sent the whole list.', 'royal-mcp' ), number_format_i18n( (int) round( $d['tools']['tokens'] / 1000 ) ), number_format_i18n( $d['tools']['left_out'] ) );
			self::tile( __( 'Tools sent', 'royal-mcp' ), number_format_i18n( $d['tools']['listed'] ), $tools_note, self::url( Context_Page::PAGE_SLUG ), __( 'Context', 'royal-mcp' ) );
		} else {
			self::tile( __( 'Tools sent', 'royal-mcp' ), '?', __( 'The tool list could not be worked out on this page load.', 'royal-mcp' ), self::url( Context_Page::PAGE_SLUG ), __( 'Context', 'royal-mcp' ) );
		}
		/* translators: %s: number of AI clients waiting for approval */
		self::tile( __( 'Connected clients', 'royal-mcp' ), number_format_i18n( $d['clients']['active'] ), $d['clients']['pending'] > 0 ? sprintf( _n( '%s waiting for approval', '%s waiting for approval', $d['clients']['pending'], 'royal-mcp' ), number_format_i18n( $d['clients']['pending'] ) ) : __( 'AI apps signed in right now. Connections that use the API key are not counted.', 'royal-mcp' ), self::url( Connected_Clients_Page::MENU_SLUG ), __( 'Manage', 'royal-mcp' ) );
		self::tile( __( 'Safety mode', 'royal-mcp' ), $d['mode']['label'], $d['mode']['note'], self::url( Menu_Layout::SAFETY ), __( 'Change', 'royal-mcp' ) );
		self::tile( __( 'Site context', 'royal-mcp' ), $d['context_set'] ? __( 'Written', 'royal-mcp' ) : __( 'Not written', 'royal-mcp' ), $d['context_set'] ? __( 'Every AI client receives it when it connects.', 'royal-mcp' ) : __( 'Tell AI clients what your site is and what to leave alone.', 'royal-mcp' ), self::url( Context_Page::PAGE_SLUG ), $d['context_set'] ? __( 'Edit', 'royal-mcp' ) : __( 'Write it', 'royal-mcp' ) );
		echo '</div>';

		echo '<div class="royal-mcp-dash-cols"><div class="royal-mcp-dash-main">';

		// ---- needs attention
		echo '<div class="royal-mcp-dash-card">';
		echo '<h2>' . esc_html__( 'Needs your attention', 'royal-mcp' ) . '</h2>';
		if ( ! $d['attention'] ) {
			echo '<p class="royal-mcp-dash-ok">' . esc_html__( 'Nothing right now.', 'royal-mcp' ) . '</p>';
		} else {
			echo '<ul class="royal-mcp-dash-attention">';
			foreach ( $d['attention'] as $item ) {
				echo '<li><span>' . esc_html( $item['text'] ) . '</span> <a class="button button-small" href="' . esc_url( $item['link'] ) . '">' . esc_html( $item['action'] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		// ---- recent activity
		echo '<div class="royal-mcp-dash-card">';
		echo '<h2>' . esc_html__( 'What AI clients changed lately', 'royal-mcp' ) . '</h2>';
		if ( ! $d['groups'] ) {
			/* translators: %s: the undo window, e.g. "72 hours" */
			echo '<p class="royal-mcp-dash-sub">' . esc_html( sprintf( __( 'No changes in the last %s. Changes an AI client makes show up here, and each one can be undone.', 'royal-mcp' ), Undo_Store::window_label() ) ) . '</p>';
		} else {
			echo '<ul class="royal-mcp-dash-activity">';
			foreach ( $d['groups'] as $group ) {
				$who   = get_userdata( (int) $group['user_id'] );
				$login = $who ? $who->user_login : __( 'unknown user', 'royal-mcp' );
				$open  = (int) ( $group['statuses']['active'] ?? 0 );
				$more  = $d['window_full'] && $group === $d['groups'][ count( $d['groups'] ) - 1 ];
				echo '<li><strong>';
				/* translators: 1: connection, for example Claude or API key; 2: WordPress user login */
				echo esc_html( sprintf( __( '%1$s as %2$s', 'royal-mcp' ), $group['connection']['label'], $login ) );
				echo '</strong><span>';
				/* translators: 1: number of changes, 2: date and time, 3: number that can still be undone */
				echo esc_html( sprintf( _n( '%1$s change, %2$s; %3$s can be undone', '%1$s changes, %2$s; %3$s can be undone', $group['count'], 'royal-mcp' ), number_format_i18n( $group['count'] ) . ( $more ? '+' : '' ), wp_date( $format, (int) $group['to'] ), number_format_i18n( $open ) . ( $more ? '+' : '' ) ) );
				echo '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p><a class="button" style="' . esc_attr( $btn ) . '" href="' . esc_url( self::url( Changes_Page::MENU_SLUG ) ) . '"><span>' . esc_html__( 'Review and undo changes', 'royal-mcp' ) . '</span>' . self::icon( 'arrow' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() returns fixed SVG markup
		echo '</div>';

		// ---- prompt starters
		echo '<div class="royal-mcp-dash-card">';
		echo '<h2>' . esc_html__( 'Things to ask first', 'royal-mcp' ) . '</h2>';
		echo '<p class="royal-mcp-dash-sub">' . esc_html__( 'Once an AI app is connected, try one of these. Click to copy.', 'royal-mcp' ) . '</p>';
		echo '<ul class="royal-mcp-dash-starters">';
		foreach ( self::starters() as $prompt ) {
			echo '<li><button type="button" class="royal-mcp-dash-starter" data-copy="' . esc_attr( $prompt ) . '">' . esc_html( $prompt ) . '</button></li>';
		}
		echo '</ul></div>';

		echo '</div><div class="royal-mcp-dash-side">';

		// ---- upgrade
		echo '<div class="royal-mcp-dash-card royal-mcp-dash-upgrade">';
		echo '<h2>' . esc_html__( 'One connection for everything your AI manages', 'royal-mcp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Royally puts your whole stack behind one MCP connection: every WordPress site you run, plus Cloudflare, UptimeRobot, Notion and other MCP servers. Each change can wait for your approval, is recorded and can be undone. Royal MCP Pro comes with it.', 'royal-mcp' ) . '</p>';
		echo '<p><a class="button button-primary" style="' . esc_attr( $btn ) . '" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( [ 'utm_source' => 'royal_mcp_free', 'utm_medium' => 'free_plugin', 'utm_content' => 'dashboard_upgrade_card', 'utm_campaign' => 'royal_mcp_admin' ], 'https://royalplugins.com/royally/' ) ) . '"><span>' . esc_html__( 'See Royally', 'royal-mcp' ) . '</span>' . self::icon( 'arrow' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() returns fixed SVG markup
		echo '</div>';

		// ---- help
		echo '<div class="royal-mcp-dash-card">';
		echo '<h2>' . esc_html__( 'Help', 'royal-mcp' ) . '</h2>';
		echo '<ul class="royal-mcp-dash-links">';
		echo '<li><a href="' . esc_url( self::url( Help_Page::SLUG ) ) . '">' . esc_html__( 'Setup guides and troubleshooting', 'royal-mcp' ) . '</a></li>';
		echo '<li><a href="' . esc_url( self::url( 'royal-mcp-logs' ) ) . '">' . esc_html__( 'Activity Log', 'royal-mcp' ) . '</a></li>';
		echo '<li><a target="_blank" rel="noopener" href="https://wordpress.org/support/plugin/royal-mcp/">' . esc_html__( 'Support forum', 'royal-mcp' ) . '</a></li>';
		echo '</ul></div>';

		echo '</div></div>';

		// ---- Royal plugins on this site
		if ( $d['family'] ) {
			echo '<div class="royal-mcp-dash-card">';
			echo '<h2>' . esc_html__( 'Royal plugins on this site', 'royal-mcp' ) . '</h2>';
			echo '<p class="royal-mcp-dash-sub">' . esc_html__( 'More plugins from the makers of Royal MCP, and whether each is on this site.', 'royal-mcp' ) . '</p>';
			echo '<div class="royal-mcp-dash-family">';
			foreach ( $d['family'] as $plugin ) {
				self::family_card( $plugin );
			}
			echo '</div></div>';
		}

		$script = '(function(){function c(t,b){var d=function(){var o=b.querySelector("span")||b,x=o.textContent;o.textContent=' . wp_json_encode( __( 'Copied', 'royal-mcp' ) ) . ';var l=document.getElementById("royal-mcp-dash-live");if(l){l.textContent=' . wp_json_encode( __( 'Copied to the clipboard', 'royal-mcp' ) ) . ';}setTimeout(function(){o.textContent=x;},1400);};'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(d);}else{var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();try{document.execCommand("copy");d();}catch(e){}document.body.removeChild(a);}}'
			. 'var u=document.getElementById("royal-mcp-dash-copy"),e=document.getElementById("royal-mcp-dash-endpoint");if(u&&e){u.addEventListener("click",function(){c(e.value,u);});}'
			. 'var s=document.querySelectorAll(".royal-mcp-dash-starter");for(var i=0;i<s.length;i++){s[i].addEventListener("click",function(){c(this.getAttribute("data-copy")||"",this);});}'
			. '})();';
		wp_print_inline_script_tag( $script );
		echo '</div>';
	}

	/** One Royal plugin: its name, whether it is on this site, and the next step. */
	private static function family_card( array $plugin ) {
		$state   = (string) ( $plugin['state'] ?? 'not_installed' );
		$premium = ! empty( $plugin['install_url'] );
		echo '<div class="royal-mcp-dash-plugin' . ( 'active' === $state ? ' is-active' : '' ) . '">';
		echo '<div class="royal-mcp-dash-plugin-head"><strong>' . esc_html( (string) $plugin['name'] ) . '</strong>';
		if ( 'active' === $state ) {
			echo '<span class="royal-mcp-dash-pill is-on">' . esc_html__( 'Active', 'royal-mcp' ) . '</span>';
		} elseif ( 'inactive' === $state ) {
			echo '<span class="royal-mcp-dash-pill is-idle">' . esc_html__( 'Installed, not active', 'royal-mcp' ) . '</span>';
		} else {
			echo '<span class="royal-mcp-dash-pill is-idle">' . esc_html__( 'Not installed', 'royal-mcp' ) . '</span>';
		}
		echo '</div>';
		echo '<p>' . esc_html( (string) ( $plugin['pitch'] ?? '' ) ) . '</p>';
		echo '<p class="royal-mcp-dash-plugin-actions">';
		if ( 'inactive' === $state && current_user_can( 'activate_plugin', (string) $plugin['basename'] ) ) {
			$activate = wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( (string) $plugin['basename'] ) ), 'activate-plugin_' . (string) $plugin['basename'] );
			echo '<a class="button button-small" href="' . esc_url( $activate ) . '">' . esc_html__( 'Activate', 'royal-mcp' ) . '</a> ';
		} elseif ( 'not_installed' === $state && $premium ) {
			echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) $plugin['install_url'] ) . '">' . esc_html__( 'Get it', 'royal-mcp' ) . '</a> ';
		} elseif ( 'not_installed' === $state && current_user_can( 'install_plugins' ) && ! empty( $plugin['search_slug'] ) ) {
			echo '<a class="button button-small" href="' . esc_url( admin_url( 'plugin-install.php?s=' . rawurlencode( (string) $plugin['search_slug'] ) . '&tab=search&type=term' ) ) . '">' . esc_html__( 'Install free', 'royal-mcp' ) . '</a> ';
		}
		if ( ! empty( $plugin['learn_url'] ) ) {
			echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) $plugin['learn_url'] ) . '">' . esc_html__( 'Learn more', 'royal-mcp' ) . '</a>';
		}
		echo '</p></div>';
	}

	private static function tile( $title, $value, $note, $link, $action ) {
		echo '<div class="royal-mcp-dash-tile"><span class="royal-mcp-dash-tile-title">' . esc_html( $title ) . '</span><strong>' . esc_html( $value ) . '</strong><span class="royal-mcp-dash-tile-note">' . esc_html( $note ) . '</span><a href="' . esc_url( $link ) . '">' . esc_html( $action ) . '</a></div>';
	}
}
