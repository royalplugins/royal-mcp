<?php
/**
 * Royal MCP — Divi integration.
 *
 * Free-tier Divi tooling:
 *
 *   divi_get_page_format        Detect D4/D5/mixed/not_divi + compat modules
 *                                + optional builder_session probe.
 *   divi_validate_layout        Structural validation of D4 shortcodes +
 *                                D5 block nesting, dual-input (post_id or
 *                                raw_content).
 *   divi_get_page_outline       Normalized Section > Row > Column > Module
 *                                tree, same shape for D4 and D5.
 *   divi_list_local_templates   Enumerate et_pb_layout library items.
 *   divi_library_get            Fetch a single library entry with format meta.
 *   divi_replace_text           Dual-format text substitution write tool.
 *   divi_clone_page             Dual-format post duplication with meta
 *                                preservation + fresh D5 clientIds.
 *   divi_replace_image          Dual-format bulk image URL swap.
 *   divi_import_template        Apply library entry to target page
 *                                (merge or replace).
 *
 * Safety helpers are named to match the sibling Pro implementation
 * (Royal_MCP_Pro\Integrations\Divi) so Pro's Divi tools can share the
 * same helper surface when the two coexist. `Divi_Safety` lives in its
 * own top-level file (Royal_MCP\Divi_Safety) mirroring Pro's placement.
 */

namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Builder_Safety;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Divi {

	/**
	 * Lowest Divi builder version this integration is tested against.
	 * Below this, reads still work but write tools surface a caveat.
	 */
	const MIN_SUPPORTED_VERSION = '4.0.0';

	/** Lowest Divi 5 version validated. */
	const MIN_D5_VERSION = '5.0.0';

	/** Post meta keys copied from source → clone. */
	const CLONE_META_KEYS = [
		'_et_pb_use_builder',
		'_et_pb_page_layout',
		'_et_pb_side_nav',
		'_et_pb_show_title',
		'_et_pb_post_hide_nav',
		'_et_pb_old_content',
		'_et_pb_ab_bounce_rate_limit',
		'_et_pb_ab_stats_refresh_interval',
		'_et_pb_ab_current_shortcode',
		'_et_pb_ab_subjects',
		'_et_pb_ab_goal_module',
		'_et_pb_enable_ab_testing',
		'_et_pb_first_image',
		'_et_pb_truncate_post_date',
		'_et_pb_truncate_post',
		'_et_pb_builder_version',
	];

	/** Backup meta key stamped by divi_import_template. */
	const BACKUP_META_KEY_PREFIX = '_royal_mcp_pro_divi_backup_';

	/**
	 * Per-tool minimum-version map. Every Divi tool that ships should have
	 * an entry so callers can pre-check compatibility via get_version_support().
	 */
	const TOOL_VERSION_MAP = [
		'divi_get_page_format'      => [ 'min_version' => '4.0.0' ],
		'divi_validate_layout'      => [ 'min_version' => '4.0.0' ],
		'divi_get_page_outline'     => [ 'min_version' => '4.0.0' ],
		'divi_list_local_templates' => [ 'min_version' => '4.0.0' ],
		'divi_library_get'          => [ 'min_version' => '4.0.0' ],
		'divi_replace_text'         => [ 'min_version' => '4.0.0' ],
		'divi_clone_page'           => [ 'min_version' => '4.0.0' ],
		'divi_replace_image'        => [ 'min_version' => '4.0.0' ],
		'divi_import_template'      => [ 'min_version' => '4.0.0' ],
	];

	// ----------------------------------------------------------------------
	// Environment detection
	// ----------------------------------------------------------------------

	/**
	 * Soft "is Divi 5 around at all" hint — used by D5-only guards. Divi
	 * doesn't autoload its constants until the theme boots, so we accept
	 * any of the plausible D5 enablement signals.
	 */
	public static function is_divi_5_available() {
		return defined( 'ET_BUILDER_5_ENABLED' )
			|| function_exists( 'et_builder_d5_enabled' )
			|| class_exists( '\\ET\\Builder\\Framework\\Utility\\Feature' );
	}

	/**
	 * Detect the Divi format of a post.
	 *
	 * Delegates to Divi_Safety::get_post_builder_version which reads the
	 * `_et_builder_version` postmeta FIRST (authoritative per-post signal),
	 * falling back to content-shape only when postmeta is missing. NEVER
	 * infers from the currently-active theme version — the two are
	 * decoupled on aged sites (theme updated many times without individual
	 * posts being re-saved).
	 *
	 * @return string 'divi_4_shortcodes' | 'divi_5_blocks' | 'mixed' | 'not_divi'
	 */
	public static function detect_format( $post_id ) {
		$info = \Royal_MCP\Divi_Safety::get_post_builder_version( (int) $post_id );
		$format = (string) ( isset( $info['format'] ) ? $info['format'] : 'not_divi' );
		// Divi_Safety returns 'unknown' when postmeta version is present but
		// outside 4.x/5.x range — treat as not_divi for handler purposes.
		return in_array( $format, [ 'divi_4_shortcodes', 'divi_5_blocks', 'mixed', 'not_divi' ], true )
			? $format
			: 'not_divi';
	}

	// ----------------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------------

	/**
	 * Divi 4 modules that structurally cannot contain inner content.
	 * Divi's own writer emits these in the self-closing form (`... /]`).
	 * Third-party layouts occasionally omit the slash — treat these as
	 * closed whether or not the slash is present, matching WordPress's
	 * own do_shortcode() tolerance for void modules.
	 */
	private static function is_known_void_module( $name ) {
		static $void = [
			'et_pb_image', 'et_pb_divider', 'et_pb_fullwidth_header',
			'et_pb_icon', 'et_pb_number_counter', 'et_pb_blurb',
			'et_pb_button', 'et_pb_line_break', 'et_pb_video',
			'et_pb_audio', 'et_pb_countdown_timer', 'et_pb_signup',
		];
		return in_array( $name, $void, true );
	}

	/**
	 * Small window of surrounding content for error reporting — lets
	 * callers locate the offending tag in one round-trip without
	 * re-fetching the whole post.
	 */
	private static function excerpt_around( $content, $offset, $length, $before = 30, $after = 30 ) {
		$start = max( 0, $offset - $before );
		$end   = min( strlen( $content ), $offset + $length + $after );
		$slice = substr( $content, $start, $end - $start );
		$slice = preg_replace( '/\s+/', ' ', (string) $slice );
		return trim( (string) $slice );
	}

	// ----------------------------------------------------------------------
	// Safety-layer public helpers (names match Pro's Divi integration)
	// ----------------------------------------------------------------------

	/**
	 * Pre-write validator for D4 shortcode content. Recognizes both paired
	 * (`[et_pb_X]...[/et_pb_X]`) and self-closing (`[et_pb_X ... /]`) forms,
	 * plus known-void modules that render without a close tag.
	 *
	 * Error entries include the shortcode name, byte offset, and a
	 * surrounding excerpt so callers can locate the failing tag without
	 * re-fetching the whole post.
	 *
	 * Also validates that any @ET-DC@…@ dynamic-content tokens present in
	 * body text are balanced. Malformed / truncated tokens render as
	 * literal text on the front end — a silent user-visible corruption.
	 *
	 * @return array {
	 *   valid: bool,
	 *   errors: array<int, {severity: string, code: string, message: string, shortcode?: string, offset?: int, excerpt?: string}>
	 * }
	 */
	public static function validate_shortcode_structure( $content ) {
		$errors = [];

		if ( ! is_string( $content ) || $content === '' ) {
			return [ 'valid' => true, 'errors' => [] ];
		}

		// Case-sensitive: Divi emits lowercase tags, and uppercase authored
		// tags would not render as Divi modules — treat them as literal
		// text rather than validating them as if they were Divi content.
		if ( ! preg_match_all(
			'/\[(\/?)(et_pb_[a-z0-9_]+)([^\]]*)\]/',
			$content,
			$tag_matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		) ) {
			// No et_pb_* tags present — still check for stranded DC tokens.
			return self::check_dynamic_content_tokens( $content, [] );
		}

		$stack = [];
		foreach ( $tag_matches as $m ) {
			$whole        = $m[0][0];
			$whole_offset = (int) $m[0][1];
			$is_close     = $m[1][0] === '/';
			$name         = $m[2][0];
			$attr_tail    = $m[3][0];
			$known_void   = self::is_known_void_module( $name );

			if ( $is_close ) {
				if ( $known_void ) {
					// Known-void closes are noops — mirrors do_shortcode()'s
					// own tolerance for void modules in either paired or
					// bare form.
					continue;
				}
				if ( empty( $stack ) ) {
					$errors[] = [
						'severity'  => 'error',
						'code'      => 'unbalanced_close',
						'message'   => "unexpected closing tag [/$name]",
						'shortcode' => $name,
						'offset'    => $whole_offset,
						'excerpt'   => self::excerpt_around( $content, $whole_offset, strlen( $whole ) ),
					];
					continue;
				}
				$expected = array_pop( $stack );
				if ( $expected['name'] !== $name ) {
					$errors[] = [
						'severity'  => 'error',
						'code'      => 'tag_mismatch',
						'message'   => "expected [/{$expected['name']}], found [/$name]",
						'shortcode' => $name,
						'offset'    => $whole_offset,
						'excerpt'   => self::excerpt_around( $content, $whole_offset, strlen( $whole ) ),
					];
				}
				continue;
			}

			// Known-void modules never push onto the stack — paired,
			// self-closing, and bare forms all treated as immediately closed.
			if ( $known_void ) {
				continue;
			}

			// Self-closing form for non-void modules: attribute string ends
			// with a slash (possibly followed by whitespace).
			if ( preg_match( '/\/\s*$/', $attr_tail ) ) {
				continue;
			}

			$stack[] = [
				'name'         => $name,
				'offset'       => $whole_offset,
				'whole_length' => strlen( $whole ),
			];
		}

		if ( ! empty( $stack ) ) {
			foreach ( $stack as $unclosed ) {
				$errors[] = [
					'severity'  => 'error',
					'code'      => 'unclosed_tag',
					'message'   => "unclosed tag [{$unclosed['name']}]",
					'shortcode' => $unclosed['name'],
					'offset'    => $unclosed['offset'],
					'excerpt'   => self::excerpt_around( $content, $unclosed['offset'], $unclosed['whole_length'] ),
				];
			}
		}

		return self::check_dynamic_content_tokens( $content, $errors );
	}

	/**
	 * Extend the error list with any dynamic-content token corruption.
	 * Divi wraps dynamic-content bindings as `@ET-DC@<base64>@` — malformed
	 * or truncated tokens render as literal text on the front end.
	 */
	private static function check_dynamic_content_tokens( $content, array $errors ) {
		if ( false === strpos( $content, '@ET-DC@' ) ) {
			return [ 'valid' => empty( $errors ), 'errors' => $errors ];
		}
		$dc_open  = substr_count( $content, '@ET-DC@' );
		$dc_pairs = preg_match_all( '/@ET-DC@[A-Za-z0-9+\/=]+@/', $content, $unused );
		if ( $dc_pairs !== $dc_open ) {
			$errors[] = [
				'severity' => 'error',
				'code'     => 'truncated_dynamic_content_token',
				'message'  => sprintf(
					'Detected %d dynamic-content tokens but only %d parse cleanly. Preserve @ET-DC@…@ tokens verbatim.',
					$dc_open,
					$dc_pairs
				),
			];
		}
		return [ 'valid' => empty( $errors ), 'errors' => $errors ];
	}

	/**
	 * Divi caches a static CSS file per post. Any post_content or preset
	 * change must trigger a purge; otherwise design updates don't reach
	 * visitors. Also invalidates dynamic-cache postmeta so subsequent
	 * renders regenerate.
	 */
	public static function purge_divi_static_css( $post_id ) {
		if ( class_exists( '\\ET_Core_PageResource' )
			&& is_callable( [ '\\ET_Core_PageResource', 'remove_static_resources' ] ) ) {
			call_user_func( [ '\\ET_Core_PageResource', 'remove_static_resources' ], (int) $post_id, 'all', true );
		}
		// Both meta keys documented in Divi source.
		delete_post_meta( (int) $post_id, '_et_dynamic_cached_shortcodes' );
		delete_post_meta( (int) $post_id, '_et_dynamic_cached_attributes' );
	}

	/**
	 * Re-assert Divi meta flags after every write so the builder still
	 * recognizes the post. Skips when format is non-Divi so we don't tag
	 * unrelated content as builder-managed.
	 */
	public static function assert_divi_meta_flags( $post_id, $format_detected ) {
		if ( $format_detected === 'not_divi' ) {
			return;
		}
		update_post_meta( (int) $post_id, '_et_pb_use_builder', 'on' );
		if ( get_post_meta( (int) $post_id, '_et_pb_page_layout', true ) === '' ) {
			update_post_meta( (int) $post_id, '_et_pb_page_layout', 'et_full_width_page' );
		}
		if ( defined( 'ET_BUILDER_VERSION' ) ) {
			update_post_meta( (int) $post_id, '_et_pb_builder_version', constant( 'ET_BUILDER_VERSION' ) );
		}
	}

	// ----------------------------------------------------------------------
	// Version-support tracking (Free-only helper for tool caveats)
	// ----------------------------------------------------------------------

	/**
	 * Report whether a named Divi tool is supported on the currently-loaded
	 * Divi version, plus any caveats callers should surface. Policy:
	 * detect + degrade + surface. Never gate-and-refuse purely on version.
	 *
	 * @param string $tool_name
	 * @return array {supported: bool, min_version: string, current_version: string, caveats: array<int, string>}
	 */
	public static function get_version_support( $tool_name ) {
		$current = defined( 'ET_BUILDER_VERSION' ) ? (string) constant( 'ET_BUILDER_VERSION' ) : '';
		$result  = [
			'supported'       => false,
			'min_version'     => '',
			'current_version' => $current,
			'caveats'         => [],
		];

		if ( ! isset( self::TOOL_VERSION_MAP[ $tool_name ] ) ) {
			$result['caveats'][] = sprintf( 'Tool "%s" is not registered in the Divi version map.', $tool_name );
			return $result;
		}

		$min = self::TOOL_VERSION_MAP[ $tool_name ]['min_version'];
		$result['min_version'] = $min;

		if ( '' === $current ) {
			$result['caveats'][] = 'Divi builder is not loaded on this site.';
			return $result;
		}

		if ( version_compare( $current, $min, '<' ) ) {
			$result['caveats'][] = sprintf(
				'Divi %s is below the tested minimum for %s (%s). Behavior may differ.',
				$current, $tool_name, $min
			);
			return $result;
		}

		$result['supported'] = true;
		return $result;
	}

	// ----------------------------------------------------------------------
	// Tool registration + dispatch
	// ----------------------------------------------------------------------

	public static function get_tools() {
		return [
			[
				'name'        => 'divi_get_page_format',
				'description' => 'Detect how a post is stored by the Divi Builder: Divi 4 shortcode format, Divi 5 block format, mixed, or not-Divi. Reports the site-loaded Divi version, the per-post builder version stamped by Divi at last save (authoritative when present), any modules running in compatibility mode, and whether a human editor session is currently open on the post. Read-only, safe to probe. Use before any Divi write tool to plan the operation with format-known state.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID to inspect.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'divi_validate_layout',
				'description' => 'Structurally validate Divi content before a write. Two input paths: pass post_id to validate an existing post\'s stored content, OR pass raw_content plus format to validate an in-memory string (agent\'s own generation, pre-save). Checks Divi 4 shortcode balance (recognizing known-void modules and self-closing forms) plus dynamic-content token preservation, and Divi 5 block nesting rules (row inside section, column inside row, modules inside column). Errors carry code + message + location (shortcode name + offset + surrounding excerpt for D4; section_idx/row_idx/column_idx/module_idx for D5).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer', 'description' => 'Path A: validate the stored content of this post/page.' ],
						'raw_content' => [ 'type' => 'string',  'description' => 'Path B: validate this arbitrary content string. Pair with format.' ],
						'format'      => [ 'type' => 'string', 'enum' => [ 'auto', 'divi_4_shortcodes', 'divi_5_blocks', 'mixed', 'not_divi' ], 'description' => 'Path B: format of raw_content. Default auto (detect from content shape).' ],
					],
				],
			],
			[
				'name'        => 'divi_get_page_outline',
				'description' => 'Return a normalized Section → Row → Column → Module tree for a Divi post — same shape regardless of whether the post is stored as Divi 4 shortcodes or Divi 5 blocks. Each node carries a stable path-derived id (e.g. s0.r1.c0.m2) plus optional text snippet. Response size targets under 2KB by omitting per-module settings; pass include_settings=true when the full attribute payload is needed (blows the budget on large pages).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'          => [ 'type' => 'integer', 'description' => 'Post or page ID to outline.' ],
						'include_settings' => [ 'type' => 'boolean', 'description' => 'Include full per-module settings/attributes. Default false; setting true can exceed the 2KB outline budget on large pages.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'divi_list_local_templates',
				'description' => 'Enumerate items from the Divi Library (et_pb_layout CPT). Filter by scope: "all" returns everything, "global" returns only items in the global layout_category, "layout"|"section"|"row"|"module" filter by the _et_pb_module_type postmeta. Returns per-template template_id + title + layout_type + format (D4 or D5) + is_global flag + size_bytes + created_at. Use before divi_library_get to discover items, and before importing to inspect what\'s available.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'scope' => [ 'type' => 'string', 'enum' => [ 'all', 'global', 'layout', 'section', 'row', 'module' ], 'description' => 'Filter. Default "all". "global" filters by layout_category taxonomy; the module-type values filter by _et_pb_module_type postmeta.' ],
						'limit' => [ 'type' => 'integer', 'description' => 'Max templates to return. Default 50, capped at 500.' ],
					],
				],
			],
			[
				'name'        => 'divi_library_get',
				'description' => 'Read a single Divi Library item (et_pb_layout CPT) by template_id. Returns the normalized Section → Row → Column → Module tree via the same shape as divi_get_page_outline, so agents reason about library items and pages with one mental model. Also returns title + layout_type + format + is_global + size_bytes. Raw post_content is omitted by default to keep the response under the 2KB outline budget; pass include_raw=true when the exact bytes are needed (e.g. byte-diff comparison before import).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'template_id' => [ 'type' => 'integer', 'description' => 'ID of the et_pb_layout library item, obtained from divi_list_local_templates.' ],
						'include_raw' => [ 'type' => 'boolean', 'description' => 'When true, include raw post_content string. Default false (content_tree only).' ],
					],
					'required'   => [ 'template_id' ],
				],
			],
			[
				'name'        => 'divi_replace_text',
				'description' => 'Single-page bulk text substitution across Divi shortcode attributes + block content. Dual-format walker: Divi 4 uses quote-aware shortcode attribute parser (safe on class="foo bar" style attrs); Divi 5 walks parse_blocks() output substituting in flat text-bearing block attrs + innerContent. Preserves @ET-DC@…@ dynamic-content tokens verbatim. Validates D4 shortcode structure post-substitution and refuses the write with post_write_would_corrupt if invalid. Guards against active editor sessions via WP core _edit_lock (override with force:true). Purges Divi static CSS cache and re-asserts builder meta flags after write. Returns per-replacement counts + total + telemetry. Known limitations: (1) Divi 5 nested attribute shapes (attrs.content.desktop.value) are not walked — only flat string attrs; (2) counts may inflate when the same text appears in both attrs and body of a D5 block. Note: replace values with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — response includes a warnings entry when detected. Decode client-side before sending or verify rendered output.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'        => [ 'type' => 'integer', 'description' => 'Post/page ID to substitute in. Must be Divi-built.' ],
						'replacements'   => [
							'type'        => 'array',
							'description' => 'Array of { find: string, replace: string } pairs. Applied in order; later pairs see the output of earlier ones.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'find'    => [ 'type' => 'string', 'description' => 'Substring to find.' ],
									'replace' => [ 'type' => 'string', 'description' => 'Replacement text.' ],
								],
								'required'   => [ 'find', 'replace' ],
							],
						],
						'case_sensitive' => [ 'type' => 'boolean', 'description' => 'Default false. Case-insensitive matching is Unicode-aware.' ],
						'force'          => [ 'type' => 'boolean', 'description' => 'Default false. When true, bypasses the active-editor-session guard.' ],
					],
					'required'   => [ 'post_id', 'replacements' ],
				],
			],
			[
				'name'        => 'divi_clone_page',
				'description' => 'Duplicate a Divi page/post (D4 shortcode, D5 blocks, or mixed) as a new draft. Preserves all _et_pb_* meta, regenerates D5 clientIds, validates D4 shortcode structure before write. 72h undo deletes the created post.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'source_post_id' => [ 'type' => 'integer', 'description' => 'Post ID to clone from. Must be a Divi-built page (has _et_pb_use_builder=on OR contains [et_pb_* shortcodes / divi/* blocks).' ],
						'new_title'      => [ 'type' => 'string',  'description' => 'Title for the created clone.' ],
						'new_status'     => [ 'type' => 'string',  'enum' => [ 'draft', 'publish', 'private', 'pending' ], 'description' => 'Publish state for the clone. Defaults to draft.' ],
					],
					'required'   => [ 'source_post_id', 'new_title' ],
				],
			],
			[
				'name'        => 'divi_replace_image',
				'description' => 'Swap an image URL across every image-bearing Divi element on a post — module src, background_image, D5 image block URLs, gallery entries. Dual-format (D4 shortcodes + D5 blocks + mixed). Validates result. 72h undo restores prior content + meta.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer', 'description' => 'Divi-built post/page ID to modify. Refuses on non-Divi content.' ],
						'find_url'    => [ 'type' => 'string',  'description' => 'Existing image URL to search for (exact match).' ],
						'replace_url' => [ 'type' => 'string',  'description' => 'New image URL to substitute.' ],
						'force'       => [ 'type' => 'boolean', 'description' => 'Default false. When true, bypasses the active-editor-session guard.' ],
					],
					'required'   => [ 'post_id', 'find_url', 'replace_url' ],
				],
			],
			[
				'name'        => 'divi_import_template',
				'description' => 'Apply an et_pb_layout library entry to a target page. mode=merge appends (top or bottom); mode=replace overwrites (with backup meta stamp). Dual-format aware — logs warning on target/template format mismatch. 72h undo restores prior post_content.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'target_post_id' => [ 'type' => 'integer', 'description' => 'The post/page ID that receives the imported template.' ],
						'template_id'    => [ 'type' => 'integer', 'description' => 'ID of the et_pb_layout library item to apply. Use divi_list_local_templates + divi_library_get to discover items.' ],
						'mode'           => [ 'type' => 'string',  'enum' => [ 'merge', 'replace' ], 'description' => 'merge = append template content to existing (default); replace = overwrite target content (backup stamped to a versioned meta key).' ],
						'position'       => [ 'type' => 'string',  'enum' => [ 'top', 'bottom' ], 'description' => 'Merge mode only — insert template at top or bottom of existing content. Defaults to bottom.' ],
						'force'          => [ 'type' => 'boolean', 'description' => 'Default false. When true, bypasses the active-editor-session guard.' ],
					],
					'required'   => [ 'target_post_id', 'template_id' ],
				],
			],
		];
	}

	/**
	 * Dispatch a divi_* tool call to its handler. Umbrella cap check runs
	 * first so unprivileged callers can't fingerprint whether Divi is
	 * present on the site — matches sibling integration pattern.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Envelope::error(
				'insufficient_caps',
				'You do not have permission to use Divi tools.'
			);
		}

		switch ( $name ) {
			case 'divi_get_page_format':
				return self::handle_get_page_format( $args );

			case 'divi_validate_layout':
				return self::handle_validate_layout( $args );

			case 'divi_get_page_outline':
				return self::handle_get_page_outline( $args );

			case 'divi_list_local_templates':
				return self::handle_list_local_templates( $args );

			case 'divi_library_get':
				return self::handle_library_get( $args );

			case 'divi_replace_text':
				return self::handle_replace_text( $args );

			case 'divi_clone_page':
				return self::handle_clone_page( $args );

			case 'divi_replace_image':
				return self::handle_replace_image( $args );

			case 'divi_import_template':
				return self::handle_import_template( $args );

			default:
				return Envelope::error(
					'unknown_tool',
					sprintf( 'Unknown Divi tool: %s', $name ),
					[ 'name' => $name ]
				);
		}
	}

	// ----------------------------------------------------------------------
	// Tool handlers
	// ----------------------------------------------------------------------

	private static function handle_get_page_format( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return Envelope::error(
				'invalid_args',
				'post_id must be a positive integer.',
				[ 'received' => isset( $args['post_id'] ) ? $args['post_id'] : null ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return Envelope::error(
				'not_found',
				sprintf( 'Post %d not found.', $post_id ),
				[ 'post_id' => $post_id ]
			);
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return Envelope::error(
				'insufficient_caps',
				sprintf( 'read_post capability required on post %d.', $post_id ),
				[ 'post_id' => $post_id ]
			);
		}

		$version_info   = \Royal_MCP\Divi_Safety::get_post_builder_version( $post_id );
		$site_version   = isset( $version_info['theme_version'] ) && is_string( $version_info['theme_version'] )
			? $version_info['theme_version']
			: '';
		$compat_modules = self::detect_compatibility_mode_modules( $post );
		$session        = Builder_Safety::detect_active_editor_session( $post_id );

		$format_readable = [
			'divi_4_shortcodes' => 'Divi 4 shortcodes',
			'divi_5_blocks'     => 'Divi 5 blocks',
			'mixed'             => 'mixed Divi 4 shortcode + Divi 5 block content',
			'not_divi'          => 'no Divi content',
			'unknown'           => 'unknown format',
		];
		$format_text = isset( $format_readable[ $version_info['format'] ] )
			? $format_readable[ $version_info['format'] ]
			: 'unknown format';

		$version_text = ( '' !== $site_version )
			? sprintf( 'Divi %s is loaded on the site.', $site_version )
			: 'Divi builder is not loaded on this site.';

		$compat_text = ! empty( $compat_modules )
			? sprintf( ' Detected %d compatibility-mode module type(s).', count( $compat_modules ) )
			: '';

		$session_text = ( ! empty( $session['active'] ) )
			? sprintf( ' Editor session currently open by user %d.', (int) $session['editor_user_id'] )
			: '';

		$summary = sprintf(
			'Post %d is stored as %s. %s%s%s',
			$post_id, $format_text, $version_text, $compat_text, $session_text
		);

		return Envelope::success( $summary, [
			'post_id'                    => $post_id,
			'site_divi_version'          => $site_version,
			'format_detected'            => $version_info['format'],
			'per_post_builder_version'   => $version_info['version'],
			'per_post_version_source'    => $version_info['source'],
			'gap_from_theme'             => $version_info['gap_from_theme'],
			'meta_key_used'              => $version_info['meta_key_used'],
			'compatibility_mode_modules' => $compat_modules,
			'builder_session'            => $session,
		] );
	}

	/**
	 * Walk a post's block tree looking for Divi 5 modules running in
	 * compatibility mode. The exact attribute key Divi 5 stamps varies
	 * across minor releases; filterable so sites can extend the key set.
	 */
	private static function detect_compatibility_mode_modules( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$content = (string) $post->post_content;
		if ( '' === $content || false === strpos( $content, '<!-- wp:divi/' ) ) {
			return [];
		}
		if ( ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		/**
		 * Filter the list of block-attribute keys treated as
		 * compatibility-mode markers on Divi 5 blocks.
		 *
		 * @param array $keys Default attribute keys checked.
		 */
		$keys = apply_filters( 'royal_mcp_divi_compat_mode_keys', [
			'compatibility_mode',
			'compatibilityMode',
			'__compatibilityMode',
			'compat_mode',
		] );
		if ( ! is_array( $keys ) || empty( $keys ) ) {
			return [];
		}
		// Guard against filter returning non-string entries. PHP 8+ warns
		// on array-offset access with non-scalar keys.
		$keys = array_values( array_filter( $keys, 'is_string' ) );
		if ( empty( $keys ) ) {
			return [];
		}

		$counts = [];
		$walk = function( $blocks ) use ( &$walk, &$counts, $keys ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block['blockName'] ) && is_string( $block['blockName'] )
					&& 0 === strpos( $block['blockName'], 'divi/' ) ) {
					$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
					foreach ( $keys as $key ) {
						if ( ! empty( $attrs[ $key ] ) ) {
							$counts[ $block['blockName'] ] = isset( $counts[ $block['blockName'] ] )
								? $counts[ $block['blockName'] ] + 1
								: 1;
							break;
						}
					}
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};

		$blocks = parse_blocks( $content );
		if ( is_array( $blocks ) ) {
			$walk( $blocks );
		}

		$out = [];
		foreach ( $counts as $module_type => $count ) {
			$out[] = [ 'module_type' => $module_type, 'count' => $count ];
		}
		return $out;
	}

	/**
	 * Handler for divi_validate_layout. Two mutually-exclusive input paths:
	 *   - post_id: read stored content, validate against detected format
	 *   - raw_content + format: validate in-memory string without site touch
	 */
	private static function handle_validate_layout( $args ) {
		// isset-only, not !empty — passing post_id: 0 must reach the value-
		// validation branch to get a precise error, not the generic prompt.
		$has_post_id = isset( $args['post_id'] );
		$has_raw     = isset( $args['raw_content'] );

		if ( ! $has_post_id && ! $has_raw ) {
			return Envelope::error( 'invalid_args', 'Provide either post_id or raw_content.' );
		}
		if ( $has_post_id && $has_raw ) {
			return Envelope::error( 'invalid_args', 'Provide either post_id or raw_content, not both.' );
		}

		$content = '';
		$format  = 'auto';

		if ( $has_post_id ) {
			$post_id = (int) $args['post_id'];
			if ( $post_id <= 0 ) {
				return Envelope::error(
					'invalid_args',
					'post_id must be a positive integer.',
					[ 'received' => $args['post_id'] ]
				);
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return Envelope::error(
					'not_found',
					sprintf( 'Post %d not found.', $post_id ),
					[ 'post_id' => $post_id ]
				);
			}
			if ( ! current_user_can( 'read_post', $post_id ) ) {
				return Envelope::error(
					'insufficient_caps',
					sprintf( 'read_post capability required on post %d.', $post_id ),
					[ 'post_id' => $post_id ]
				);
			}
			$content = (string) $post->post_content;
			$format  = self::detect_format( $post_id );
		} else {
			$content = (string) $args['raw_content'];
			$format  = isset( $args['format'] ) ? sanitize_text_field( (string) $args['format'] ) : 'auto';
			$allowed = [ 'auto', 'divi_4_shortcodes', 'divi_5_blocks', 'mixed', 'not_divi' ];
			if ( ! in_array( $format, $allowed, true ) ) {
				return Envelope::error(
					'invalid_args',
					sprintf( 'format must be one of: %s.', implode( ', ', $allowed ) ),
					[ 'received' => $format ]
				);
			}
			if ( 'auto' === $format ) {
				$format = self::detect_format_from_content( $content );
			}
		}

		$errors = [];

		if ( in_array( $format, [ 'divi_4_shortcodes', 'mixed' ], true ) ) {
			$d4 = self::validate_shortcode_structure( $content );
			foreach ( $d4['errors'] as $e ) {
				$location = [];
				if ( isset( $e['shortcode'] ) )  $location['shortcode'] = $e['shortcode'];
				if ( isset( $e['offset'] ) )     $location['offset']    = $e['offset'];
				if ( isset( $e['excerpt'] ) )    $location['excerpt']   = $e['excerpt'];
				$errors[] = [
					'severity' => isset( $e['severity'] ) ? $e['severity'] : 'error',
					'code'     => $e['code'],
					'message'  => $e['message'],
					'location' => $location,
				];
			}
		}

		if ( in_array( $format, [ 'divi_5_blocks', 'mixed' ], true ) ) {
			$d5 = self::validate_d5_block_structure( $content );
			foreach ( $d5['errors'] as $e ) {
				$errors[] = $e;
			}
		}

		$has_fatal = false;
		foreach ( $errors as $e ) {
			$sev = isset( $e['severity'] ) ? $e['severity'] : 'error';
			if ( 'error' === $sev ) {
				$has_fatal = true;
				break;
			}
		}
		$valid = ! $has_fatal;

		$summary = $valid
			? sprintf( 'Layout is valid (%s, %d warning%s).', $format, count( $errors ), 1 === count( $errors ) ? '' : 's' )
			: sprintf( 'Layout has %d error%s (%s).', count( $errors ), 1 === count( $errors ) ? '' : 's', $format );

		return Envelope::success( $summary, [
			'valid'           => $valid,
			'format_detected' => $format,
			'errors'          => $errors,
		] );
	}

	/**
	 * Content-shape format detector used by the raw_content path. Mirrors
	 * the fallback branch of Divi_Safety::get_post_builder_version but
	 * doesn't require a post fetch — inspects the string directly.
	 */
	private static function detect_format_from_content( $content ) {
		$has_d5 = ( false !== strpos( $content, '<!-- wp:divi/' ) );
		$has_d4 = ( false !== strpos( $content, '[et_pb_section' ) )
			|| ( false !== strpos( $content, '[et_pb_row' ) )
			|| ( preg_match( '/\[et_pb_[a-z0-9_]+[\s\]]/', $content ) === 1 );

		if ( $has_d5 && $has_d4 ) return 'mixed';
		if ( $has_d5 )            return 'divi_5_blocks';
		if ( $has_d4 )            return 'divi_4_shortcodes';
		return 'not_divi';
	}

	/**
	 * Validate Divi 5 block nesting rules. Every error carries a location
	 * {section_idx, row_idx?, column_idx?, module_idx?} for tree navigation.
	 * Block-attribute type validation is intentionally out of scope —
	 * evolves too fast across Divi 5 minors to hardcode.
	 */
	private static function validate_d5_block_structure( $content ) {
		$errors = [];

		if ( ! is_string( $content ) || '' === $content ) {
			return [ 'valid' => true, 'errors' => [] ];
		}
		if ( ! function_exists( 'parse_blocks' ) ) {
			return [ 'valid' => true, 'errors' => [] ];
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return [ 'valid' => true, 'errors' => [] ];
		}

		$section_idx = 0;
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( ! is_string( $name ) || 0 !== strpos( $name, 'divi/' ) ) {
				continue;
			}

			if ( 'divi/section' !== $name ) {
				$errors[] = [
					'severity' => 'error',
					'code'     => 'invalid_root_block',
					'message'  => sprintf( 'Root Divi block must be divi/section; got %s.', $name ),
					'location' => [ 'section_idx' => $section_idx ],
				];
				$section_idx++;
				continue;
			}

			self::validate_d5_section_children( $block, $section_idx, $errors );
			$section_idx++;
		}

		return [ 'valid' => empty( $errors ), 'errors' => $errors ];
	}

	private static function validate_d5_section_children( $section, $section_idx, &$errors ) {
		$inner = isset( $section['innerBlocks'] ) && is_array( $section['innerBlocks'] ) ? $section['innerBlocks'] : [];
		$row_idx = 0;
		foreach ( $inner as $child ) {
			$name = isset( $child['blockName'] ) ? $child['blockName'] : '';
			if ( ! is_string( $name ) || 0 !== strpos( $name, 'divi/' ) ) {
				continue;
			}
			if ( 'divi/row' !== $name ) {
				$errors[] = [
					'severity' => 'error',
					'code'     => 'invalid_section_child',
					'message'  => sprintf( 'divi/section may only contain divi/row children; found %s.', $name ),
					'location' => [ 'section_idx' => $section_idx, 'row_idx' => $row_idx ],
				];
				$row_idx++;
				continue;
			}
			self::validate_d5_row_children( $child, $section_idx, $row_idx, $errors );
			$row_idx++;
		}
	}

	private static function validate_d5_row_children( $row, $section_idx, $row_idx, &$errors ) {
		$inner = isset( $row['innerBlocks'] ) && is_array( $row['innerBlocks'] ) ? $row['innerBlocks'] : [];
		$col_idx = 0;
		foreach ( $inner as $child ) {
			$name = isset( $child['blockName'] ) ? $child['blockName'] : '';
			if ( ! is_string( $name ) || 0 !== strpos( $name, 'divi/' ) ) {
				continue;
			}
			if ( 'divi/column' !== $name ) {
				$errors[] = [
					'severity' => 'error',
					'code'     => 'invalid_row_child',
					'message'  => sprintf( 'divi/row may only contain divi/column children; found %s.', $name ),
					'location' => [ 'section_idx' => $section_idx, 'row_idx' => $row_idx, 'column_idx' => $col_idx ],
				];
				$col_idx++;
				continue;
			}
			self::validate_d5_column_children( $child, $section_idx, $row_idx, $col_idx, $errors );
			$col_idx++;
		}
	}

	private static function validate_d5_column_children( $col, $section_idx, $row_idx, $col_idx, &$errors ) {
		$inner = isset( $col['innerBlocks'] ) && is_array( $col['innerBlocks'] ) ? $col['innerBlocks'] : [];
		$module_idx = 0;
		$structural = [ 'divi/section', 'divi/row', 'divi/column' ];
		foreach ( $inner as $child ) {
			$name = isset( $child['blockName'] ) ? $child['blockName'] : '';
			if ( ! is_string( $name ) || 0 !== strpos( $name, 'divi/' ) ) {
				continue;
			}
			if ( in_array( $name, $structural, true ) ) {
				$errors[] = [
					'severity' => 'error',
					'code'     => 'invalid_column_child',
					'message'  => sprintf( 'divi/column may only contain modules; found structural block %s.', $name ),
					'location' => [ 'section_idx' => $section_idx, 'row_idx' => $row_idx, 'column_idx' => $col_idx, 'module_idx' => $module_idx ],
				];
			}
			$module_idx++;
		}
	}

	/**
	 * Handler for divi_get_page_outline. Returns a normalized tree from
	 * either D4 or D5 content, using shared root offsets so a mixed-format
	 * post gets unique IDs across walkers.
	 */
	private static function handle_get_page_outline( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return Envelope::error(
				'invalid_args',
				'post_id must be a positive integer.',
				[ 'received' => isset( $args['post_id'] ) ? $args['post_id'] : null ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return Envelope::error(
				'not_found',
				sprintf( 'Post %d not found.', $post_id ),
				[ 'post_id' => $post_id ]
			);
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return Envelope::error(
				'insufficient_caps',
				sprintf( 'read_post capability required on post %d.', $post_id ),
				[ 'post_id' => $post_id ]
			);
		}

		$include_settings = ! empty( $args['include_settings'] );
		$content          = (string) $post->post_content;
		$format           = self::detect_format( $post_id );

		$tree           = [];
		$total_modules  = 0;
		$top_sections   = 0;
		$root_offsets   = [];

		if ( in_array( $format, [ 'divi_4_shortcodes', 'mixed' ], true ) ) {
			$d4 = self::build_d4_outline( $content, $include_settings, $root_offsets );
			foreach ( $d4['tree'] as $node ) {
				$tree[] = $node;
			}
			$total_modules += $d4['total_modules'];
			$top_sections  += $d4['top_level_sections'];
		}

		if ( in_array( $format, [ 'divi_5_blocks', 'mixed' ], true ) ) {
			$d5 = self::build_d5_outline( $content, $include_settings, $root_offsets );
			foreach ( $d5['tree'] as $node ) {
				$tree[] = $node;
			}
			$total_modules += $d5['total_modules'];
			$top_sections  += $d5['top_level_sections'];
		}

		$summary = sprintf(
			'Post %d outline: %d top-level section(s), %d module(s) total (%s).',
			$post_id, $top_sections, $total_modules, $format
		);

		return Envelope::success( $summary, [
			'post_id'            => $post_id,
			'format_detected'    => $format,
			'tree'               => $tree,
			'total_modules'      => $total_modules,
			'top_level_sections' => $top_sections,
		] );
	}

	// ----------------------------------------------------------------------
	// D4 outline walker
	// ----------------------------------------------------------------------

	private static function build_d4_outline( $content, $include_settings, &$root_offsets = [] ) {
		$result = [ 'tree' => [], 'total_modules' => 0, 'top_level_sections' => 0 ];

		if ( '' === $content ) return $result;
		if ( ! preg_match_all( '/\[(\/?)(et_pb_[a-z0-9_]+)([^\]]*)\]/', $content, $matches, PREG_SET_ORDER ) ) {
			return $result;
		}

		$root  = [ 'type' => 'root', 'children' => [] ];
		$stack = [ &$root ];
		$counters = [];
		foreach ( $root_offsets as $type => $offset ) {
			$counters[ '|' . $type ] = (int) $offset;
		}

		foreach ( $matches as $m ) {
			$is_close  = ( '/' === $m[1] );
			$tag       = $m[2];
			$attr_text = trim( $m[3] );
			$self_close = ( '' !== $attr_text && '/' === substr( $attr_text, -1 ) );
			if ( $self_close ) {
				$attr_text = rtrim( substr( $attr_text, 0, -1 ) );
			}
			$known_void = self::is_known_void_module( $tag );

			if ( $is_close ) {
				if ( $known_void ) {
					continue;
				}
				if ( count( $stack ) > 1 ) {
					array_pop( $stack );
				}
				continue;
			}

			$type   = self::d4_tag_to_type( $tag );
			$parent = &$stack[ count( $stack ) - 1 ];
			$parent_path = isset( $parent['id'] ) ? $parent['id'] : '';

			$counter_key = $parent_path . '|' . $type;
			$idx = isset( $counters[ $counter_key ] ) ? $counters[ $counter_key ] : 0;
			$counters[ $counter_key ] = $idx + 1;

			$id_segment = self::type_to_id_prefix( $type ) . $idx;
			$node_id    = ( '' === $parent_path ) ? $id_segment : $parent_path . '.' . $id_segment;

			$node = [ 'type' => $type, 'id' => $node_id ];
			if ( 'module' === $type ) {
				$node['module_type'] = $tag;
				$result['total_modules']++;
			}
			if ( 'section' === $type && '' === $parent_path ) {
				$result['top_level_sections']++;
			}

			$parsed_attrs = ( '' === $attr_text ) ? [] : shortcode_parse_atts( $attr_text );
			if ( ! is_array( $parsed_attrs ) ) $parsed_attrs = [];

			$snippet = self::d4_snippet_from_attrs( $tag, $parsed_attrs );
			if ( '' !== $snippet ) $node['snippet'] = $snippet;
			if ( $include_settings ) $node['settings'] = $parsed_attrs;

			$node['children'] = [];
			$parent['children'][] = $node;

			// Known-void modules never push onto the stack — matches
			// validate_shortcode_structure's own behavior.
			if ( ! $self_close && ! $known_void ) {
				$last_idx = count( $parent['children'] ) - 1;
				$stack[]  = &$parent['children'][ $last_idx ];
			}
			unset( $parent );
		}

		$result['tree'] = $root['children'];

		foreach ( $counters as $key => $value ) {
			if ( '|' === substr( $key, 0, 1 ) ) {
				$type = substr( $key, 1 );
				$root_offsets[ $type ] = $value;
			}
		}

		return $result;
	}

	private static function d4_tag_to_type( $tag ) {
		if ( 'et_pb_section' === $tag || 'et_pb_section_inner' === $tag ) return 'section';
		if ( 'et_pb_row' === $tag || 'et_pb_row_inner' === $tag )         return 'row';
		if ( 'et_pb_column' === $tag || 'et_pb_column_inner' === $tag )   return 'column';
		return 'module';
	}

	private static function type_to_id_prefix( $type ) {
		switch ( $type ) {
			case 'section': return 's';
			case 'row':     return 'r';
			case 'column':  return 'c';
			case 'module':  return 'm';
			default:        return 'x';
		}
	}

	private static function d4_snippet_from_attrs( $tag, $attrs ) {
		// Text-carrying attribute names — matches the set walked by Pro's
		// d4_walk_attrs_text so snippet lookup and substitution stay
		// aligned across integrations.
		$candidates = [ 'title', 'button_text', 'header_text', 'text', 'header', 'admin_label' ];
		foreach ( $candidates as $key ) {
			if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && '' !== trim( $attrs[ $key ] ) ) {
				$plain = wp_strip_all_tags( $attrs[ $key ] );
				return function_exists( 'mb_strimwidth' )
					? mb_strimwidth( $plain, 0, 80, '...' )
					: substr( $plain, 0, 80 );
			}
		}
		return '';
	}

	// ----------------------------------------------------------------------
	// D5 outline walker
	// ----------------------------------------------------------------------

	private static function build_d5_outline( $content, $include_settings, &$root_offsets = [] ) {
		$result = [ 'tree' => [], 'total_modules' => 0, 'top_level_sections' => 0 ];

		if ( '' === $content || false === strpos( $content, '<!-- wp:divi/' ) ) return $result;
		if ( ! function_exists( 'parse_blocks' ) ) return $result;

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) return $result;

		$counters = [];
		foreach ( $root_offsets as $type => $offset ) {
			$counters[ $type ] = (int) $offset;
		}

		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( ! is_string( $name ) || 0 !== strpos( $name, 'divi/' ) ) continue;
			$type = self::d5_block_to_type( $name );
			$idx  = isset( $counters[ $type ] ) ? $counters[ $type ] : 0;
			$counters[ $type ] = $idx + 1;

			$node = self::build_d5_node( $block, '', $idx, $include_settings, $result );
			if ( null !== $node ) {
				$result['tree'][] = $node;
				if ( 'section' === $node['type'] ) {
					$result['top_level_sections']++;
				}
			}
		}

		foreach ( $counters as $type => $value ) {
			$root_offsets[ $type ] = $value;
		}

		return $result;
	}

	private static function build_d5_node( $block, $parent_path, $position_idx, $include_settings, &$result ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'divi/' ) ) return null;

		$type       = self::d5_block_to_type( $name );
		$id_segment = self::type_to_id_prefix( $type ) . $position_idx;
		$node_id    = ( '' === $parent_path ) ? $id_segment : $parent_path . '.' . $id_segment;

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$node  = [ 'type' => $type, 'id' => $node_id ];

		if ( 'module' === $type ) {
			$node['module_type'] = $name;
			$result['total_modules']++;
		}

		$snippet = self::d5_snippet_from_attrs( $attrs );
		if ( '' !== $snippet ) $node['snippet'] = $snippet;
		if ( $include_settings ) $node['settings'] = $attrs;

		$node['children'] = [];
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$counters = [];
		foreach ( $inner as $child_block ) {
			$child_name = isset( $child_block['blockName'] ) ? $child_block['blockName'] : '';
			if ( ! is_string( $child_name ) || 0 !== strpos( $child_name, 'divi/' ) ) continue;
			$child_type = self::d5_block_to_type( $child_name );
			$idx = isset( $counters[ $child_type ] ) ? $counters[ $child_type ] : 0;
			$counters[ $child_type ] = $idx + 1;

			$child_node = self::build_d5_node( $child_block, $node_id, $idx, $include_settings, $result );
			if ( null !== $child_node ) {
				$node['children'][] = $child_node;
			}
		}

		return $node;
	}

	private static function d5_block_to_type( $block_name ) {
		if ( 'divi/section' === $block_name ) return 'section';
		if ( 'divi/row' === $block_name )     return 'row';
		if ( 'divi/column' === $block_name )  return 'column';
		return 'module';
	}

	/**
	 * Handler for divi_list_local_templates.
	 *
	 * Enumerates the et_pb_layout CPT (Divi's Library). Cap-gated at
	 * manage_options to match Pro's library-CRUD tools and Divi's own
	 * admin-side access requirements.
	 *
	 * The scope filter dispatches to either:
	 *   - `global`     → tax_query on layout_category=global
	 *   - `layout|section|row|module` → meta_query on _et_pb_module_type
	 *   - `all`        → no filter
	 *
	 * Per-template fields match Pro's divi_library_* handler responses so
	 * downstream tools (Pro's create/update/delete, Free's future
	 * library_get) speak the same shape.
	 */
	private static function handle_list_local_templates( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Envelope::error(
				'insufficient_caps',
				'manage_options capability required for Divi Library access.'
			);
		}

		$scope = isset( $args['scope'] ) ? sanitize_key( (string) $args['scope'] ) : 'all';
		$allowed_scopes = [ 'all', 'global', 'layout', 'section', 'row', 'module' ];
		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			return Envelope::error(
				'invalid_args',
				sprintf( 'scope must be one of: %s.', implode( ', ', $allowed_scopes ) ),
				[ 'received' => $scope ]
			);
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		if ( $limit <= 0 )   $limit = 50;
		if ( $limit > 500 )  $limit = 500;

		$query_args = [
			'post_type'      => 'et_pb_layout',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'suppress_filters' => false,
		];

		if ( 'global' === $scope ) {
			$query_args['tax_query'] = [ [
				'taxonomy' => 'layout_category',
				'field'    => 'slug',
				'terms'    => 'global',
			] ];
		} elseif ( in_array( $scope, [ 'layout', 'section', 'row', 'module' ], true ) ) {
			$query_args['meta_query'] = [ [
				'key'   => '_et_pb_module_type',
				'value' => $scope,
			] ];
		}

		$query = new \WP_Query( $query_args );
		$templates = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) continue;
			$templates[] = [
				'template_id' => (int) $post->ID,
				'title'       => (string) $post->post_title,
				'layout_type' => (string) ( get_post_meta( $post->ID, '_et_pb_module_type', true ) ?: 'unknown' ),
				'format'      => self::detect_format( $post->ID ),
				'is_global'   => has_term( 'global', 'layout_category', $post->ID ),
				'size_bytes'  => strlen( (string) $post->post_content ),
				'created_at'  => (string) $post->post_date_gmt,
			];
		}

		$summary = sprintf(
			'Found %d Divi library template(s) (scope=%s, limit=%d).',
			count( $templates ), $scope, $limit
		);

		return Envelope::success( $summary, [
			'templates'      => $templates,
			'total_returned' => count( $templates ),
			'scope_applied'  => $scope,
			'limit_applied'  => $limit,
		] );
	}

	/**
	 * Handler for divi_library_get.
	 *
	 * Single-item read from et_pb_layout CPT. Pairs with
	 * divi_list_local_templates for a discover-then-inspect flow. Cap-gated
	 * at manage_options to match the list tool and Pro's library-CRUD.
	 *
	 * Content is returned as a normalized tree via the same build_d4_outline
	 * / build_d5_outline walkers divi_get_page_outline uses — one mental
	 * model for library items and pages. Raw post_content is omitted by
	 * default (2KB budget); opt in via include_raw for byte-diff use cases.
	 */
	private static function handle_library_get( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Envelope::error(
				'insufficient_caps',
				'manage_options capability required for Divi Library access.'
			);
		}

		$template_id = isset( $args['template_id'] ) ? (int) $args['template_id'] : 0;
		if ( $template_id <= 0 ) {
			return Envelope::error(
				'invalid_args',
				'template_id must be a positive integer.',
				[ 'received' => isset( $args['template_id'] ) ? $args['template_id'] : null ]
			);
		}

		$template = get_post( $template_id );
		if ( ! $template instanceof \WP_Post || 'et_pb_layout' !== $template->post_type ) {
			return Envelope::error(
				'template_not_found',
				sprintf( 'Template %d not found in et_pb_layout.', $template_id ),
				[ 'template_id' => $template_id ]
			);
		}

		$include_raw = ! empty( $args['include_raw'] );
		$content     = (string) $template->post_content;
		$format      = self::detect_format( $template_id );
		$layout_type = (string) ( get_post_meta( $template_id, '_et_pb_module_type', true ) ?: 'unknown' );

		// Build the outline via the same walkers pages use. Shared root
		// offsets prevent D4/D5 collisions on mixed-format library items.
		$tree          = [];
		$total_modules = 0;
		$top_sections  = 0;
		$root_offsets  = [];

		if ( in_array( $format, [ 'divi_4_shortcodes', 'mixed' ], true ) ) {
			$d4 = self::build_d4_outline( $content, false, $root_offsets );
			foreach ( $d4['tree'] as $node ) {
				$tree[] = $node;
			}
			$total_modules += $d4['total_modules'];
			$top_sections  += $d4['top_level_sections'];
		}
		if ( in_array( $format, [ 'divi_5_blocks', 'mixed' ], true ) ) {
			$d5 = self::build_d5_outline( $content, false, $root_offsets );
			foreach ( $d5['tree'] as $node ) {
				$tree[] = $node;
			}
			$total_modules += $d5['total_modules'];
			$top_sections  += $d5['top_level_sections'];
		}

		$structured = [
			'template_id'    => $template_id,
			'title'          => (string) $template->post_title,
			'layout_type'    => $layout_type,
			'format'         => $format,
			'is_global'      => has_term( 'global', 'layout_category', $template_id ),
			'content_tree'   => $tree,
			'total_modules'  => $total_modules,
			'top_level_sections' => $top_sections,
			'size_bytes'     => strlen( $content ),
			'created_at'     => (string) $template->post_date_gmt,
			'modified_at'    => (string) $template->post_modified_gmt,
		];

		if ( $include_raw ) {
			$structured['raw_content'] = $content;
		}

		$summary = sprintf(
			'Library item %d "%s" — layout_type=%s, format=%s, %d module(s), %d byte(s)%s.',
			$template_id,
			$template->post_title,
			$layout_type,
			$format,
			$total_modules,
			strlen( $content ),
			$include_raw ? ', raw content included' : ''
		);

		return Envelope::success( $summary, $structured );
	}

	/**
	 * Handler for divi_replace_text — Free's first write tool.
	 *
	 * Applies an ordered list of find/replace pairs across the Divi content
	 * of a single post. D4 walks shortcode body-text + text-bearing
	 * attributes (title, button_text, etc.); D5 walks parse_blocks output
	 * substituting in known text-bearing block attrs. @ET-DC@…@ dynamic-
	 * content tokens are preserved verbatim.
	 *
	 * Safety cascade (matches Pro's handle_replace_image pattern):
	 *   1. Umbrella + object cap check (edit_posts + edit_post on target).
	 *   2. Post-existence check → post_not_found.
	 *   3. Builder-session guard via Builder_Safety — overridable with force:true.
	 *   4. Format detection → post_not_divi if content isn't Divi.
	 *   5. Snapshot content-length-before for telemetry.
	 *   6. Walk D4 then D5 per detected format (mixed runs both).
	 *   7. Post-walk validate_shortcode_structure — refuse with
	 *      post_write_would_corrupt if result is invalid.
	 *   8. wp_update_post with wp_slash($content).
	 *   9. assert_divi_meta_flags + purge_divi_static_css post-write.
	 */
	private static function handle_replace_text( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return Envelope::error(
				'invalid_args',
				'post_id must be a positive integer.',
				[ 'received' => isset( $args['post_id'] ) ? $args['post_id'] : null ]
			);
		}

		$replacements = isset( $args['replacements'] ) && is_array( $args['replacements'] )
			? $args['replacements']
			: null;
		if ( null === $replacements || empty( $replacements ) ) {
			return Envelope::error(
				'invalid_args',
				'replacements must be a non-empty array of { find, replace } pairs.'
			);
		}

		// Normalize + validate each pair. Preserve original_index so
		// warnings + per-replacement telemetry point callers at the
		// position in the input array they actually sent, not the
		// compact index of the post-filter array.
		$pairs = [];
		foreach ( $replacements as $i => $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['find'] ) || ! isset( $pair['replace'] ) ) {
				return Envelope::error(
					'invalid_args',
					sprintf( 'replacements[%d] must be an object with find + replace string fields.', $i )
				);
			}
			$find = (string) $pair['find'];
			if ( '' === $find ) {
				// Empty find is a no-op; skip rather than error so callers can
				// batch pairs including conditional/optional entries.
				continue;
			}
			$pairs[] = [
				'find'           => $find,
				'replace'        => (string) $pair['replace'],
				'original_index' => (int) $i,
			];
		}
		if ( empty( $pairs ) ) {
			return Envelope::error(
				'invalid_args',
				'replacements contained no non-empty find strings.'
			);
		}

		$case_sensitive = ! empty( $args['case_sensitive'] );
		$force          = ! empty( $args['force'] );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Envelope::error(
				'insufficient_caps',
				sprintf( 'edit_post capability required on post %d.', $post_id ),
				[ 'post_id' => $post_id ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return Envelope::error(
				'post_not_found',
				sprintf( 'Post %d not found.', $post_id ),
				[ 'post_id' => $post_id ]
			);
		}

		// Builder-session guard (Art R2 write-collision prevention). Uses
		// WordPress core _edit_lock via Builder_Safety, so any editor open on
		// this post — Divi, block editor, classic — trips the guard.
		if ( ! $force ) {
			$session = Builder_Safety::detect_active_editor_session( $post_id );
			if ( ! empty( $session['active'] ) ) {
				return Envelope::error(
					'builder_session_active',
					'An editor session is currently open on this post. Close the editor or pass force:true to override.',
					[
						'post_id'         => $post_id,
						'builder_session' => $session,
					]
				);
			}
		}

		$format = self::detect_format( $post_id );
		if ( 'not_divi' === $format ) {
			return Envelope::error(
				'post_not_divi',
				sprintf( 'Post %d does not appear to be a Divi-built page.', $post_id ),
				[ 'post_id' => $post_id, 'format_detected' => $format ]
			);
		}

		$content_before = (string) $post->post_content;
		$content        = $content_before;
		$per_pair       = [];
		$total          = 0;

		foreach ( $pairs as $pair ) {
			$counter = [ 'count' => 0 ];
			if ( in_array( $format, [ 'divi_4_shortcodes', 'mixed' ], true ) ) {
				$content = self::d4_walk_text( $content, $pair['find'], $pair['replace'], ! $case_sensitive, $counter );
			}
			if ( in_array( $format, [ 'divi_5_blocks', 'mixed' ], true ) ) {
				$blocks  = parse_blocks( $content );
				$blocks  = self::d5_walk_text( $blocks, $pair['find'], $pair['replace'], ! $case_sensitive, $counter );
				$content = serialize_blocks( $blocks );
			}
			$per_pair[ $pair['find'] ] = ( isset( $per_pair[ $pair['find'] ] ) ? $per_pair[ $pair['find'] ] : 0 ) + $counter['count'];
			$total += $counter['count'];
		}

		// Post-walk D4 validation — belt-and-suspenders per every-write policy.
		if ( in_array( $format, [ 'divi_4_shortcodes', 'mixed' ], true ) ) {
			$val = self::validate_shortcode_structure( $content );
			if ( ! $val['valid'] ) {
				return Envelope::error(
					'post_write_would_corrupt',
					'Refusing write — resulting content did not validate. Original content preserved.',
					[
						'post_id' => $post_id,
						'errors'  => $val['errors'],
					]
				);
			}
		}

		wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_slash( $content ) ] );
		self::assert_divi_meta_flags( $post_id, $format );
		self::purge_divi_static_css( $post_id );

		// P5b.2 backslash escape-sequence detection. Warn (do not refuse)
		// when any replace value contains \u or \\ sequences that may be
		// silently mangled somewhere in the MCP → REST → write pipeline.
		// The warning is informational — the write already happened; the
		// agent can verify rendered output and retry if needed.
		$warnings = [];
		foreach ( $pairs as $pair ) {
			if ( false !== strpos( $pair['replace'], '\\u' ) || false !== strpos( $pair['replace'], '\\\\' ) ) {
				$warnings[] = [
					'code'       => 'backslash_escape_in_replace',
					'message'    => sprintf(
						'replacements[%d].replace contains backslash escape sequences that may not survive to storage as literal backslashes. Verify rendered output.',
						$pair['original_index']
					),
					'pair_index' => $pair['original_index'],
				];
			}
		}

		$summary = sprintf(
			'Replaced %d occurrence(s) across %d pair(s) on post %d (format=%s).',
			$total, count( $pairs ), $post_id, $format
		);

		$structured = [
			'post_id'                => $post_id,
			'format_detected'        => $format,
			'replacements_applied'   => $total,
			'per_replacement_counts' => $per_pair,
			'telemetry'              => [
				'content_length_before' => strlen( $content_before ),
				'content_length_after'  => strlen( $content ),
				'divi_format'           => $format,
			],
		];
		if ( ! empty( $warnings ) ) {
			$structured['warnings'] = $warnings;
		}

		return Envelope::success( $summary, $structured );
	}

	// ----------------------------------------------------------------------
	// Text-substitution walkers (ported verbatim from Pro's Divi.php:
	// d4_walk_text, d4_walk_attrs_text, d5_walk_text, str_replace_counted)
	// ----------------------------------------------------------------------

	/**
	 * Walk Divi 4 shortcode content substituting text in body regions and
	 * text-bearing attribute values. Splits on shortcode boundaries so we
	 * substitute only in text bodies between tags; attribute walking is a
	 * separate pass. Preserves @ET-DC@…@ dynamic-content tokens.
	 */
	private static function d4_walk_text( $content, $find, $replace, $case_insensitive, array &$counter ) {
		if ( $find === '' || $content === '' ) {
			return $content;
		}
		$pattern = '/(\[\/?et_pb_[^\]]*\])/';
		$parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out     = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/^\[\/?et_pb_/', $part ) ) {
				$out .= self::d4_walk_attrs_text( $part, $find, $replace, $case_insensitive, $counter );
				continue;
			}
			if ( strpos( $part, '@ET-DC@' ) !== false ) {
				$dc_parts = preg_split( '/(@ET-DC@[^@]*@)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE );
				foreach ( $dc_parts as $dp ) {
					if ( strpos( $dp, '@ET-DC@' ) === 0 ) {
						$out .= $dp;
					} else {
						$out .= self::str_replace_counted( $find, $replace, $dp, $case_insensitive, $counter );
					}
				}
			} else {
				$out .= self::str_replace_counted( $find, $replace, $part, $case_insensitive, $counter );
			}
		}
		return $out;
	}

	private static function d4_walk_attrs_text( $tag, $find, $replace, $case_insensitive, array &$counter ) {
		$text_attrs = [
			'title', 'button_text', 'header_text', 'text', 'sub_text', 'body_text',
			'button_one_text', 'button_two_text', 'quote_text', 'author_name',
			'caption', 'alt', 'placeholder', 'header', 'admin_label',
		];
		return preg_replace_callback(
			'/(\b(' . implode( '|', array_map( 'preg_quote', $text_attrs ) ) . ')=)("([^"]*)"|\'([^\']*)\')/',
			static function ( $m ) use ( $find, $replace, $case_insensitive, &$counter ) {
				$attr_prefix = $m[1];
				$value       = $m[4] !== '' ? $m[4] : $m[5];
				$quote_char  = $m[4] !== '' ? '"' : '\'';
				$new_value   = self::str_replace_counted( $find, $replace, $value, $case_insensitive, $counter );
				return $attr_prefix . $quote_char . $new_value . $quote_char;
			},
			$tag
		);
	}

	/**
	 * Walk Divi 5 block tree substituting text in text-bearing block attrs
	 * plus innerHTML / innerContent for body-text blocks (e.g. divi/text).
	 * Recurses into innerBlocks.
	 */
	private static function d5_walk_text( array $blocks, $find, $replace, $case_insensitive, array &$counter ) {
		$text_keys = [ 'title', 'text', 'content', 'buttonText', 'body', 'header', 'subtitle', 'caption', 'alt', 'title_text', 'description', 'placeholder' ];
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$out[] = $block;
				continue;
			}
			if ( isset( $block['blockName'] )
				&& is_string( $block['blockName'] )
				&& strpos( $block['blockName'], 'divi/' ) === 0
				&& isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( $text_keys as $k ) {
					if ( isset( $block['attrs'][ $k ] ) && is_string( $block['attrs'][ $k ] ) ) {
						$block['attrs'][ $k ] = self::str_replace_counted( $find, $replace, $block['attrs'][ $k ], $case_insensitive, $counter );
					}
				}
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::d5_walk_text( $block['innerBlocks'], $find, $replace, $case_insensitive, $counter );
			}
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$block['innerHTML'] = self::str_replace_counted( $find, $replace, $block['innerHTML'], $case_insensitive, $counter );
			}
			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				$block['innerContent'] = array_map( static function ( $ic ) use ( $find, $replace, $case_insensitive, &$counter ) {
					return is_string( $ic ) ? self::str_replace_counted( $find, $replace, $ic, $case_insensitive, $counter ) : $ic;
				}, $block['innerContent'] );
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * Counted string replace. Case-insensitive path uses a Unicode-aware
	 * regex so multi-byte characters (é/É, ü/Ü, Cyrillic, CJK) match
	 * regardless of case. Case-sensitive path uses native str_replace.
	 */
	private static function str_replace_counted( $find, $replace, $subject, $case_insensitive, array &$counter ) {
		if ( '' === $find || '' === $subject ) {
			return $subject;
		}
		$count = 0;
		if ( $case_insensitive ) {
			$result = preg_replace(
				'/' . preg_quote( $find, '/' ) . '/ui',
				addcslashes( $replace, '\\$' ),
				$subject,
				-1,
				$count
			);
			if ( null === $result ) {
				// preg_replace error (e.g. malformed pattern) — safely no-op.
				return $subject;
			}
		} else {
			$result = str_replace( $find, $replace, $subject, $count );
		}
		$counter['count'] += (int) $count;
		return $result;
	}

	private static function d5_snippet_from_attrs( $attrs ) {
		// Text-carrying keys — matches the set walked by Pro's d5_walk_text
		// so snippet extraction and substitution stay aligned.
		$candidates = [ 'title', 'text', 'content', 'buttonText', 'body', 'header', 'subtitle', 'caption', 'admin_label' ];
		foreach ( $candidates as $key ) {
			if ( isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && '' !== trim( $attrs[ $key ] ) ) {
				$plain = wp_strip_all_tags( $attrs[ $key ] );
				return function_exists( 'mb_strimwidth' )
					? mb_strimwidth( $plain, 0, 80, '...' )
					: substr( $plain, 0, 80 );
			}
		}
		return '';
	}

	// ----------------------------------------------------------------------
	// divi_clone_page, divi_replace_image, divi_import_template + helpers
	// ----------------------------------------------------------------------

	public static function handle_clone_page( array $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Envelope::error( 'insufficient_caps', 'edit_posts capability required.' );
		}
		$source_id  = (int) ( $args['source_post_id'] ?? 0 );
		$new_title  = isset( $args['new_title'] ) ? sanitize_text_field( (string) $args['new_title'] ) : '';
		$new_status = isset( $args['new_status'] ) ? sanitize_key( (string) $args['new_status'] ) : 'draft';
		if ( ! in_array( $new_status, [ 'draft', 'publish', 'private', 'pending' ], true ) ) {
			$new_status = 'draft';
		}
		if ( $source_id <= 0 || $new_title === '' ) {
			return Envelope::error( 'invalid_args', 'source_post_id and new_title are required.' );
		}
		$source = get_post( $source_id );
		if ( ! $source ) {
			return Envelope::error( 'source_not_found', 'Source post not found.' );
		}
		if ( ! current_user_can( 'read_post', $source_id ) ) {
			return Envelope::error( 'insufficient_caps', 'read_post on source_post_id required.' );
		}

		$format = self::detect_format( $source_id );
		if ( $format === 'not_divi' ) {
			return Envelope::error( 'source_not_divi', 'Source post does not appear to be a Divi-built page.' );
		}
		$content = (string) $source->post_content;

		if ( $format === 'divi_4_shortcodes' || $format === 'mixed' ) {
			$val = self::validate_shortcode_structure( $content );
			if ( ! $val['valid'] ) {
				return Envelope::error( 'source_content_invalid', 'Source post content did not validate.', [ 'errors' => $val['errors'] ] );
			}
		}

		if ( $format === 'divi_5_blocks' || $format === 'mixed' ) {
			$blocks  = parse_blocks( $content );
			$blocks  = self::d5_regenerate_client_ids( $blocks );
			$content = serialize_blocks( $blocks );
		}

		$new_id = wp_insert_post( [
			'post_title'   => $new_title,
			'post_status'  => $new_status,
			'post_type'    => $source->post_type,
			'post_content' => wp_slash( $content ),
			'post_author'  => get_current_user_id() ?: (int) $source->post_author,
		], true );
		if ( is_wp_error( $new_id ) ) {
			return Envelope::error( 'insert_failed', $new_id->get_error_message() );
		}

		foreach ( self::CLONE_META_KEYS as $key ) {
			$val = get_post_meta( $source_id, $key, true );
			if ( $val !== '' && $val !== null && $val !== false ) {
				update_post_meta( $new_id, $key, $val );
			}
		}
		self::assert_divi_meta_flags( (int) $new_id, $format );
		self::purge_divi_static_css( (int) $new_id );

		$shortcode_count = 0;
		$block_count     = 0;
		if ( preg_match_all( '/\[et_pb_[a-z0-9_]+/', $content, $sm ) ) {
			$shortcode_count = count( $sm[0] );
		}
		if ( function_exists( 'parse_blocks' ) ) {
			$flat_blocks = self::flatten_blocks( parse_blocks( $content ) );
			$block_count = count( array_filter( $flat_blocks, static function ( $b ) {
				return is_array( $b ) && isset( $b['blockName'] ) && strpos( (string) $b['blockName'], 'divi/' ) === 0;
			} ) );
		}

		$undo_envelope = Undo_Store::store( [
			'op'           => 'divi_clone_page',
			'target'       => [ 'created_post_id' => (int) $new_id ],
			'pre_op_state' => [ 'created_by_op' => true ],
			'summary'      => sprintf( 'Delete the cloned Divi post %d.', (int) $new_id ),
		] );

		$edit_url = admin_url( 'post.php?post=' . $new_id . '&action=edit' );

		return Envelope::success(
			sprintf( 'Cloned Divi post %d → %d ("%s", format=%s, shortcodes=%d, blocks=%d). Edit: %s',
				$source_id, (int) $new_id, $new_title, $format, $shortcode_count, $block_count, $edit_url
			),
			[
				'new_post_id'      => (int) $new_id,
				'source_post_id'   => $source_id,
				'new_title'        => $new_title,
				'new_status'       => $new_status,
				'format_detected'  => $format,
				'shortcode_count'  => $shortcode_count,
				'block_count'      => $block_count,
				'edit_url'         => $edit_url,
			],
			$undo_envelope
		);
	}

	public static function handle_replace_image( array $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Envelope::error( 'insufficient_caps', 'edit_posts capability required.' );
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$find    = isset( $args['find_url'] ) ? esc_url_raw( (string) $args['find_url'] ) : '';
		$repl    = isset( $args['replace_url'] ) ? esc_url_raw( (string) $args['replace_url'] ) : '';
		$force   = ! empty( $args['force'] );
		if ( $post_id <= 0 || $find === '' || $repl === '' ) {
			return Envelope::error( 'invalid_args', 'post_id, find_url, and replace_url are required.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Envelope::error( 'insufficient_caps', 'edit_post on post_id required.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return Envelope::error( 'post_not_found', 'Post not found.' );
		}
		if ( ! $force ) {
			$session = Builder_Safety::detect_active_editor_session( $post_id );
			if ( ! empty( $session['active'] ) ) {
				return Envelope::error(
					'builder_session_active',
					'An editor session is currently open on this post. Close the editor or pass force:true to override.',
					[ 'post_id' => $post_id, 'builder_session' => $session ]
				);
			}
		}
		$format = self::detect_format( $post_id );
		if ( $format === 'not_divi' ) {
			return Envelope::error( 'post_not_divi', 'Post does not appear to be a Divi-built page.' );
		}

		$snapshot = self::snapshot_post_content( $post_id );
		$content  = (string) $post->post_content;
		$counter  = [ 'count' => 0 ];

		if ( $format === 'divi_4_shortcodes' || $format === 'mixed' ) {
			$content = self::d4_walk_image( $content, $find, $repl, $counter );
		}
		if ( $format === 'divi_5_blocks' || $format === 'mixed' ) {
			$blocks  = parse_blocks( $content );
			$blocks  = self::d5_walk_image( $blocks, $find, $repl, $counter );
			$content = serialize_blocks( $blocks );
		}

		if ( $format === 'divi_4_shortcodes' || $format === 'mixed' ) {
			$val = self::validate_shortcode_structure( $content );
			if ( ! $val['valid'] ) {
				return Envelope::error( 'post_write_would_corrupt', 'Refusing write — resulting content did not validate.', [ 'errors' => $val['errors'] ] );
			}
		}

		wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_slash( $content ) ] );
		self::assert_divi_meta_flags( $post_id, $format );
		self::purge_divi_static_css( $post_id );

		$undo_envelope = Undo_Store::store( [
			'op'           => 'divi_replace_image',
			'target'       => [ 'post_id' => $post_id ],
			'pre_op_state' => $snapshot,
			'summary'      => sprintf( 'Restore prior content of post %d (before divi_replace_image).', $post_id ),
		] );

		return Envelope::success(
			sprintf( 'Replaced %d image URL(s) on post %d (format=%s).', $counter['count'], $post_id, $format ),
			[
				'post_id'         => $post_id,
				'format_detected' => $format,
				'replacements'    => $counter['count'],
				'telemetry'       => [
					'content_length_before' => strlen( (string) ( $snapshot['post_content'] ?? '' ) ),
					'content_length_after'  => strlen( $content ),
					'divi_format'           => (string) $format,
				],
			],
			$undo_envelope
		);
	}

	public static function handle_import_template( array $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Envelope::error( 'insufficient_caps', 'edit_posts capability required.' );
		}
		$target_id   = (int) ( $args['target_post_id'] ?? 0 );
		$template_id = (int) ( $args['template_id'] ?? 0 );
		$mode        = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : 'merge';
		$position    = isset( $args['position'] ) ? sanitize_key( (string) $args['position'] ) : 'bottom';
		$force       = ! empty( $args['force'] );
		if ( ! in_array( $mode, [ 'merge', 'replace' ], true ) ) $mode = 'merge';
		if ( ! in_array( $position, [ 'top', 'bottom' ], true ) ) $position = 'bottom';
		if ( $target_id <= 0 || $template_id <= 0 ) {
			return Envelope::error( 'invalid_args', 'target_post_id and template_id are required.' );
		}
		$target = get_post( $target_id );
		if ( ! $target ) return Envelope::error( 'target_not_found', 'Target post not found.' );
		$template = get_post( $template_id );
		if ( ! $template || $template->post_type !== 'et_pb_layout' ) {
			return Envelope::error( 'template_not_found', 'Template not found in et_pb_layout.' );
		}
		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			return Envelope::error( 'insufficient_caps', 'edit_post on target_post_id required.' );
		}
		if ( ! $force ) {
			$session = Builder_Safety::detect_active_editor_session( $target_id );
			if ( ! empty( $session['active'] ) ) {
				return Envelope::error(
					'builder_session_active',
					'An editor session is currently open on this post. Close the editor or pass force:true to override.',
					[ 'target_post_id' => $target_id, 'builder_session' => $session ]
				);
			}
		}

		$snapshot        = self::snapshot_post_content( $target_id );
		$tpl_content     = (string) $template->post_content;
		$target_format   = self::detect_format( $target_id );
		$template_format = self::detect_format( $template_id );

		if ( $template_format === 'divi_4_shortcodes' || $template_format === 'mixed' ) {
			$val = self::validate_shortcode_structure( $tpl_content );
			if ( ! $val['valid'] ) {
				return Envelope::error( 'template_content_invalid', 'Template content did not validate.', [ 'errors' => $val['errors'] ] );
			}
		}

		$original = (string) $target->post_content;
		if ( $mode === 'replace' ) {
			$new_content = $tpl_content;
		} elseif ( $position === 'top' ) {
			$new_content = $tpl_content . "\n" . $original;
		} else {
			$new_content = $original . "\n" . $tpl_content;
		}

		if ( in_array( $target_format, [ 'divi_4_shortcodes', 'mixed' ], true )
			|| in_array( $template_format, [ 'divi_4_shortcodes', 'mixed' ], true ) ) {
			$val = self::validate_shortcode_structure( $new_content );
			if ( ! $val['valid'] ) {
				return Envelope::error( 'merged_content_invalid', 'Merged content did not validate — refusing write.', [ 'errors' => $val['errors'] ] );
			}
		}

		wp_update_post( [ 'ID' => $target_id, 'post_content' => wp_slash( $new_content ) ] );
		$effective_format = $template_format === 'not_divi' ? $target_format : $template_format;
		self::assert_divi_meta_flags( $target_id, $effective_format );
		self::purge_divi_static_css( $target_id );

		$backup_meta_key = self::BACKUP_META_KEY_PREFIX . time();
		update_post_meta( $target_id, $backup_meta_key, wp_slash( $original ) );

		$modules_added = 0;
		if ( preg_match_all( '/\[et_pb_[a-z0-9_]+/', $tpl_content, $mods ) ) $modules_added += count( $mods[0] );
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks_added = count( array_filter( self::flatten_blocks( parse_blocks( $tpl_content ) ), static function ( $b ) {
				return is_array( $b ) && isset( $b['blockName'] ) && strpos( (string) $b['blockName'], 'divi/' ) === 0;
			} ) );
			$modules_added += $blocks_added;
		}

		$undo_envelope = Undo_Store::store( [
			'op'           => 'divi_import_template',
			'target'       => [ 'target_post_id' => $target_id ],
			'pre_op_state' => $snapshot,
			'summary'      => sprintf( 'Restore prior content of post %d (before divi_import_template).', $target_id ),
		] );

		$warnings = [];
		if ( $target_format !== 'not_divi' && $template_format !== 'not_divi' && $target_format !== $template_format ) {
			$warnings[] = sprintf( 'format_mismatch: target=%s, template=%s', $target_format, $template_format );
		}

		return Envelope::success(
			sprintf( 'Applied template %d to post %d (mode=%s, position=%s, modules=%d).', $template_id, $target_id, $mode, $position, $modules_added ),
			[
				'target_post_id'  => $target_id,
				'template_id'     => $template_id,
				'mode'            => $mode,
				'position'        => $position,
				'modules_added'   => $modules_added,
				'backup_meta_key' => $backup_meta_key,
				'warnings'        => $warnings,
				'telemetry'       => [
					'content_length_before' => strlen( (string) ( $snapshot['post_content'] ?? '' ) ),
					'content_length_after'  => strlen( $new_content ),
					'divi_format'           => (string) $effective_format,
				],
			],
			$undo_envelope
		);
	}

	// ---- helpers below shared by clone_page + replace_image + import_template ----

	private static function d4_walk_image( $content, $old_url, $new_url, array &$counter ) {
		if ( $old_url === '' || $content === '' ) {
			return $content;
		}
		$url_attrs = [
			'src', 'background_image', 'background_url', 'image_url',
			'button_bg_image', 'video_url', 'og_image', 'logo_image_url',
		];
		$pattern = '/\b(' . implode( '|', array_map( 'preg_quote', $url_attrs ) ) . ')=("[^"]*"|\'[^\']*\')/';
		return preg_replace_callback( $pattern, static function ( $m ) use ( $old_url, $new_url, &$counter ) {
			$attr = $m[1];
			$val  = trim( $m[2], "\"'" );
			if ( $val === $old_url ) {
				$counter['count']++;
				$quote = $m[2][0] === '"' ? '"' : '\'';
				return $attr . '=' . $quote . $new_url . $quote;
			}
			return $m[0];
		}, $content );
	}

	private static function d5_walk_image( array $blocks, $old_url, $new_url, array &$counter ) {
		$url_keys = [ 'src', 'url', 'imageUrl', 'backgroundImageUrl', 'videoUrl', 'logoUrl', 'href' ];
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$out[] = $block;
				continue;
			}
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( $url_keys as $k ) {
					if ( isset( $block['attrs'][ $k ] ) && is_string( $block['attrs'][ $k ] ) && $block['attrs'][ $k ] === $old_url ) {
						$block['attrs'][ $k ] = $new_url;
						$counter['count']++;
					}
					if ( isset( $block['attrs'][ $k ] ) && is_array( $block['attrs'][ $k ] ) && ( $block['attrs'][ $k ]['url'] ?? null ) === $old_url ) {
						$block['attrs'][ $k ]['url'] = $new_url;
						$counter['count']++;
					}
				}
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::d5_walk_image( $block['innerBlocks'], $old_url, $new_url, $counter );
			}
			$out[] = $block;
		}
		return $out;
	}

	private static function d5_regenerate_client_ids( array $blocks ) {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$out[] = $block;
				continue;
			}
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) && isset( $block['attrs']['_uid'] ) ) {
				$block['attrs']['_uid'] = self::gen_client_id();
			}
			if ( isset( $block['attrs']['clientId'] ) ) {
				$block['attrs']['clientId'] = self::gen_client_id();
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::d5_regenerate_client_ids( $block['innerBlocks'] );
			}
			$out[] = $block;
		}
		return $out;
	}

	private static function gen_client_id() {
		return bin2hex( random_bytes( 6 ) );
	}

	private static function flatten_blocks( array $blocks ) {
		$out = [];
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) ) continue;
			$out[] = $b;
			if ( isset( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten_blocks( $b['innerBlocks'] ) );
			}
		}
		return $out;
	}

	private static function snapshot_post_content( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return [ 'post_id' => (int) $post_id, 'post_content' => '' ];
		$meta_snap = [];
		foreach ( self::CLONE_META_KEYS as $k ) {
			$v = get_post_meta( (int) $post_id, $k, true );
			if ( $v !== '' && $v !== false ) $meta_snap[ $k ] = $v;
		}
		return [
			'post_id'      => (int) $post_id,
			'post_content' => (string) $post->post_content,
			'meta'         => $meta_snap,
		];
	}
}
