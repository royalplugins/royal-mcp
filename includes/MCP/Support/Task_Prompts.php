<?php
/**
 * Task prompts served to connected AI clients through prompts/list and
 * prompts/get. Clients show them as slash commands. Each prompt is a short
 * instruction: which Royal MCP tools to call, in what order, and what to
 * report. Nothing runs until the AI calls the tools, under the connection's
 * normal permissions and safety modes.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Task_Prompts {

	/** Shared closing lines for every task prompt. */
	const RULES = "Rules:\n- Only read. Do not change anything on the site unless the user asks you to after seeing the report.\n- A tool that is not in your tool list or that refuses the call: skip it, and say in the report which check could not be done and why.\n- Tools marked \"if available\" belong to other plugins; use them only when they are in your tool list.\n- Keep the report short: group findings as Urgent, Needs attention and Fine, name the tool each finding came from, and end with up to five suggested next steps.";

	/**
	 * The prompts, as prompts/list gives them.
	 *
	 * @return array<int, array{name:string, title:string, description:string, arguments:array}>
	 */
	public static function prompts() {
		$out = [];
		foreach ( self::definitions() as $name => $def ) {
			$out[] = [
				'name'        => $name,
				'title'       => $def['title'],
				'description' => $def['description'],
				'arguments'   => $def['arguments'],
			];
		}
		return $out;
	}

	/** Whether a prompt by this name exists. */
	public static function has( $name ) {
		return is_string( $name ) && isset( self::definitions()[ $name ] );
	}

	/**
	 * The result of prompts/get for a task prompt, or null when there is none by that name.
	 *
	 * @param string $name      Prompt name.
	 * @param array  $arguments Arguments the client sent (strings).
	 * @return array|null
	 */
	public static function prompt( $name, $arguments = [] ) {
		if ( ! self::has( $name ) ) {
			return null;
		}
		$def  = self::definitions()[ $name ];
		$args = self::clean_arguments( is_array( $arguments ) ? $arguments : [] );
		$text = call_user_func( $def['text'], $args ) . "\n\n" . self::RULES;
		return [
			'description' => $def['description'],
			'messages'    => [
				[
					'role'    => 'user',
					'content' => [ 'type' => 'text', 'text' => $text ],
				],
			],
		];
	}

	/**
	 * Arguments the prompts understand, checked; anything else is dropped.
	 *
	 * @return array{days:int|null, post_type:string, url:string}
	 */
	public static function clean_arguments( array $arguments ) {
		$days = null;
		if ( isset( $arguments['days'] ) && is_scalar( $arguments['days'] ) && preg_match( '/^\s*\d{1,4}\s*$/', (string) $arguments['days'] ) ) {
			$days = max( 1, min( 365, (int) $arguments['days'] ) );
		}
		$post_type = '';
		if ( isset( $arguments['post_type'] ) && is_string( $arguments['post_type'] ) ) {
			$candidate = sanitize_key( $arguments['post_type'] );
			if ( '' !== $candidate && post_type_exists( $candidate ) ) {
				$post_type = $candidate;
			}
		}
		$url = '';
		if ( isset( $arguments['url'] ) && is_string( $arguments['url'] ) && strlen( $arguments['url'] ) <= 500 ) {
			$candidate = esc_url_raw( trim( $arguments['url'] ), [ 'http', 'https' ] );
			if ( '' !== $candidate ) {
				$url = $candidate;
			}
		}
		return [ 'days' => $days, 'post_type' => $post_type, 'url' => $url ];
	}

	/** The prompt definitions, by name. */
	private static function definitions() {
		$days_arg = static function ( $default ) {
			return [
				'name'        => 'days',
				'description' => 'How many days back to look (1 to 365). Default ' . (int) $default . '.',
				'required'    => false,
			];
		};
		return [
			'weekly-site-check'  => [
				'title'       => 'Weekly site check',
				'description' => 'Check the site\'s health, errors, updates, recent changes, comments, backups and security, and report what needs attention.',
				'arguments'   => [ $days_arg( 7 ) ],
				'text'        => [ __CLASS__, 'weekly_site_check' ],
			],
			'launch-checklist'   => [
				'title'       => 'Launch checklist',
				'description' => 'Check that the site is ready to go live: search engine visibility, permalinks, titles and descriptions, rendered pages, leftover test content and site health.',
				'arguments'   => [
					[
						'name'        => 'url',
						'description' => 'A page to check as visitors see it, in addition to the home page.',
						'required'    => false,
					],
				],
				'text'        => [ __CLASS__, 'launch_checklist' ],
			],
			'security-review'    => [
				'title'       => 'Security review',
				'description' => 'Review administrator accounts, application passwords, plugin and theme updates, security plugin findings and recent errors.',
				'arguments'   => [ $days_arg( 30 ) ],
				'text'        => [ __CLASS__, 'security_review' ],
			],
			'content-audit'      => [
				'title'       => 'Content audit',
				'description' => 'Audit posts or pages: counts by status, stale drafts, old content, missing SEO titles and descriptions, and media without alt text.',
				'arguments'   => [
					[
						'name'        => 'post_type',
						'description' => 'Post type to audit, for example post or page. Default post.',
						'required'    => false,
					],
					$days_arg( 180 ),
				],
				'text'        => [ __CLASS__, 'content_audit' ],
			],
		];
	}

	/** Text of the weekly site check. */
	public static function weekly_site_check( array $args ) {
		$days = null !== $args['days'] ? $args['days'] : 7;
		return "Run a weekly check of this WordPress site covering the last {$days} days.\n\n"
			. "Call these Royal MCP tools in order:\n"
			. "1. royal_mcp_connection_health: confirm the connection and note its access level.\n"
			. "2. wp_get_site_status: WordPress, PHP and database health.\n"
			. "3. wp_get_plugins: plugins and themes with updates waiting, and plugins that are installed but inactive.\n"
			. "4. wp_get_php_errors (or wp_get_error_log_tail): PHP errors from the last {$days} days, grouped by plugin or file.\n"
			. "5. mcp_list_recent_changes: what AI clients changed through Royal MCP in the last {$days} days.\n"
			. "6. wp_get_pending_comments: comments waiting for moderation.\n"
			. "7. Backups, if available: sv_get_backup_status, or the tool of the backup plugin in your list. Report when the last good backup finished.\n"
			. "8. Security, if available: gp_get_security_status, or the tool of the security plugin in your list.\n\n"
			. 'Report: a one-line verdict, then the findings.';
	}

	/** Text of the launch checklist. */
	public static function launch_checklist( array $args ) {
		$extra = '' !== $args['url'] ? ' and on ' . $args['url'] : '';
		return "Check whether this WordPress site is ready to go live.\n\n"
			. "Call these Royal MCP tools:\n"
			. "1. wp_get_site_info: site title and tagline are set and are not the WordPress defaults.\n"
			. "2. wp_get_option with blog_public: search engines are allowed to index the site (1 means allowed).\n"
			. "3. wp_get_permalink_structure: pretty permalinks are on.\n"
			. "4. wp_audit_seo_bulk or seo_audit_meta_tags: pages and posts missing an SEO title or description.\n"
			. "5. wp_verify_rendered_page on the home page{$extra}: the page answers 200 and shows the expected title, description and heading.\n"
			. "6. wp_get_posts and wp_get_pages: leftover sample content (\"Hello world!\", \"Sample Page\") and drafts that should be published or deleted.\n"
			. "7. wp_get_plugins: maintenance or coming-soon plugins still active, and debugging plugins left on.\n"
			. "8. wp_get_site_status: anything critical.\n"
			. "9. wp_audit_agent_readiness, if available: how well AI agents can read the site.\n"
			. "10. wp_prepare_for_launch, if available: read its description and run only its check or dry-run mode.\n\n"
			. 'Report: a checklist with pass, fail or not checked for each item, then the fixes in the order to do them.';
	}

	/** Text of the security review. */
	public static function security_review( array $args ) {
		$days = null !== $args['days'] ? $args['days'] : 30;
		return "Review the security of this WordPress site, looking back {$days} days where a tool takes a time range.\n\n"
			. "Call these Royal MCP tools:\n"
			. "1. wp_security_triage: the site's security overview.\n"
			. "2. wp_get_users with role administrator: every administrator account; flag ones that look unused or unexpected.\n"
			. "3. wp_audit_application_passwords: application passwords, who holds them and when they were last used.\n"
			. "4. wp_get_plugins: plugins and themes with updates waiting, and inactive ones that could be removed.\n"
			. "5. wp_get_php_errors: errors from the last {$days} days that point at a broken or tampered plugin.\n"
			. "6. Security plugin findings, if available: gp_get_security_status and gp_get_vulnerability_results, or the tools of the security plugin in your list (for example Wordfence or Solid Security).\n"
			. "7. mcp_list_recent_changes: changes AI clients made through Royal MCP in the last {$days} days.\n\n"
			. 'Report: findings ranked by risk, each with the fix.';
	}

	/** Text of the content audit. */
	public static function content_audit( array $args ) {
		$type = '' !== $args['post_type'] ? $args['post_type'] : 'post';
		$days = null !== $args['days'] ? $args['days'] : 180;
		return "Audit the {$type} content of this WordPress site.\n\n"
			. "Call these Royal MCP tools:\n"
			. "1. wp_count_posts for {$type}: how many are published, drafts, pending, scheduled and in the trash.\n"
			. "2. wp_get_posts for {$type} with status draft: drafts not touched for more than {$days} days.\n"
			. "3. wp_get_posts for {$type}, published: content not updated for more than {$days} days that may need a refresh.\n"
			. "4. wp_audit_seo_bulk for {$type}: items missing an SEO title or description, or with ones that are too long.\n"
			. "5. wp_find_media or wp_get_media: images without alt text.\n"
			. "6. Broken links, if available: seobolt_list_broken_links or the tool of the SEO plugin in your list.\n\n"
			. 'Report: the counts, then lists of the items to fix (title, id and what is wrong), at most 20 per list.';
	}
}
