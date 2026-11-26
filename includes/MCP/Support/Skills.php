<?php
/**
 * Skills served to connected AI clients. A skill is a text of instructions for
 * one kind of work (for example editing Elementor pages). The site owner
 * switches a skill on; a client then gets a short pointer when it connects and
 * reads the skill when it needs it, as a tool reply, a resource or a prompt.
 *
 * A skill is served in parts: the core (when to use it, how to start, the
 * list of its workflows, what to watch for) and one workflow at a time.
 *
 * The texts ship with the plugin, one file per skill in includes/Skills/.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skills {

	/** Option: skill id => 1 or 0, the owner's choice. A skill with no entry is on. */
	const OPTION = 'royal_mcp_skills';

	const URI_PREFIX = 'royal-mcp://skill/';

	const PROMPT_PREFIX = 'skill-';

	/** Skills whose text ships with the plugin. */
	const BUNDLED = [ 'elementor', 'divi', 'woocommerce', 'sitevault' ];

	/** Whether the text of this skill ships with the plugin. */
	public static function is_bundled( $id ) {
		return in_array( $id, self::BUNDLED, true );
	}

	/** The owner's choices, as stored. */
	public static function choices() {
		$stored = get_option( self::OPTION, [] );
		$out    = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $on ) {
			if ( self::is_bundled( $id ) ) {
				$out[ $id ] = in_array( $on, [ 1, '1', true ], true ) ? 1 : 0;
			}
		}
		return $out;
	}

	/** Store the owner's choice for the given skills; other skills keep theirs. */
	public static function save( array $choices ) {
		$stored = self::choices();
		foreach ( $choices as $id => $on ) {
			if ( self::is_bundled( $id ) ) {
				$stored[ $id ] = $on ? 1 : 0;
			}
		}
		update_option( self::OPTION, $stored, false );
	}

	/** Whether the owner has this skill switched on (the default). */
	public static function is_switched_on( $id ) {
		$choices = self::choices();
		return ! isset( $choices[ $id ] ) || 1 === $choices[ $id ];
	}

	/**
	 * The skills clients get: bundled, switched on, and for a plugin that is active here.
	 *
	 * @return array<string, array{name:string, does:string}>
	 */
	public static function loaded() {
		// A loaded skill shows that its plugin is active, which only an administrator may learn.
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) || ! class_exists( '\\Royal_MCP\\Admin\\Skills_Page' ) ) {
			return [];
		}
		$out = [];
		foreach ( \Royal_MCP\Admin\Skills_Page::catalog() as $id => $skill ) {
			if ( self::is_bundled( $id ) && $skill['active'] && self::is_switched_on( $id ) ) {
				$out[ $id ] = [ 'name' => $skill['name'], 'does' => $skill['does'] ];
			}
		}
		return $out;
	}

	/**
	 * A bundled skill's parts.
	 *
	 * @return array{version:string, frontmatter:string, core:string, sections:array<string, array{title:string, summary:string, text:string}>}
	 */
	public static function parts( $id ) {
		static $cache = [];
		$empty = [ 'version' => '', 'frontmatter' => '', 'core' => '', 'sections' => [] ];
		if ( ! self::is_bundled( $id ) ) {
			return $empty;
		}
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}
		$file = ROYAL_MCP_PLUGIN_DIR . 'includes/Skills/' . $id . '.php';
		$data = null;
		if ( is_readable( $file ) ) {
			// A file that cannot be loaded counts as no skill, and nothing it prints reaches the reply.
			ob_start();
			try {
				$data = include $file;
			} catch ( \Throwable $e ) {
				$data = null;
			}
			ob_end_clean();
		}
		$out  = $empty;
		// Skill files may be saved with Windows line endings; the texts always use plain newlines.
		$lf = static function ( $s ) {
			return str_replace( [ "\r\n", "\r" ], "\n", (string) $s );
		};
		if ( is_array( $data ) && isset( $data['core'] ) && is_string( $data['core'] ) ) {
			$out['core']    = $lf( $data['core'] );
			$out['version'] = isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : '';
			// The skill's YAML frontmatter, served verbatim at the top of its SKILL.md.
			$out['frontmatter'] = isset( $data['frontmatter'] ) && is_string( $data['frontmatter'] ) ? trim( $lf( $data['frontmatter'] ), "\n" ) : '';
			foreach ( isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : [] as $slug => $section ) {
				if ( is_array( $section ) && isset( $section['text'] ) && is_string( $section['text'] ) && '' !== $section['text'] ) {
					$out['sections'][ (string) $slug ] = [
						'title'   => $lf( isset( $section['title'] ) && is_string( $section['title'] ) ? $section['title'] : (string) $slug ),
						'summary' => $lf( isset( $section['summary'] ) && is_string( $section['summary'] ) ? $section['summary'] : '' ),
						'text'    => $lf( $section['text'] ),
					];
				}
			}
		}
		$cache[ $id ] = $out;
		return $out;
	}

	/** The core of a bundled skill, or '' when there is none. */
	public static function text( $id ) {
		return self::parts( $id )['core'];
	}

	/** One workflow of a bundled skill, or '' when there is none by that id. */
	public static function section_text( $id, $section ) {
		$parts = self::parts( $id );
		return is_string( $section ) && isset( $parts['sections'][ $section ] ) ? $parts['sections'][ $section ]['text'] : '';
	}

	/** The workflows of a skill: id, title and one line each. */
	public static function section_list( $id ) {
		$out = [];
		foreach ( self::parts( $id )['sections'] as $slug => $section ) {
			$out[] = [ 'section' => $slug, 'title' => $section['title'], 'summary' => $section['summary'] ];
		}
		return $out;
	}

	/** Lines for the instructions a client gets when it connects; '' when no skill is loaded. */
	public static function instructions() {
		$loaded = self::loaded();
		if ( ! $loaded ) {
			return '';
		}
		$lines = [ 'Skills for this site. Before you start the kind of work a skill covers, read the skill and follow it: call mcp_get_skill with its id, or read the resource ' . self::URI_PREFIX . '<id>.' ];
		foreach ( $loaded as $id => $skill ) {
			$lines[] = '- ' . $id . ': ' . $skill['does'];
		}
		return implode( "\n", $lines );
	}

	/** An id as sent by a client: text, lower case, no outer spaces; null when it is not text. */
	private static function clean_id( $value ) {
		return is_string( $value ) ? strtolower( trim( $value, " \t\n\r" ) ) : null;
	}

	/**
	 * Reply of the mcp_get_skill tool. Without a skill: the list. With a skill:
	 * its core. With a skill and a section: that workflow. The text is the
	 * reply's text; the structured part holds only what identifies it.
	 *
	 * @throws \Exception When the skill or the section is not there.
	 */
	public static function tool_reply( array $args ) {
		$loaded = self::loaded();
		$asked  = $args['skill'] ?? '';
		$id     = self::clean_id( $asked );
		$list   = [];
		foreach ( $loaded as $key => $skill ) {
			$list[] = [ 'skill' => $key, 'name' => $skill['name'], 'covers' => $skill['does'] ];
		}
		if ( '' === $id || ( null === $id && null === $asked ) ) {
			return [
				'skills' => $list,
				'count'  => count( $list ),
				'note'   => $list ? 'Call mcp_get_skill with one of these ids to read the skill.' : 'No skill is loaded on this site.',
			];
		}
		$core = is_string( $id ) && isset( $loaded[ $id ] ) ? self::text( $id ) : '';
		if ( '' === $core ) {
			throw new \Exception( $list ? 'That skill is not loaded on this site. Loaded skills: ' . implode( ', ', array_keys( $loaded ) ) . '.' : 'No skill is loaded on this site.' );
		}
		$sections   = self::section_list( $id );
		$asked_part = $args['section'] ?? '';
		$part       = self::clean_id( $asked_part );
		$struct     = [
			'skill'    => $id,
			'name'     => $loaded[ $id ]['name'],
			'covers'   => $loaded[ $id ]['does'],
			'version'  => self::parts( $id )['version'],
			'sections' => $sections,
		];
		if ( '' === $part || ( null === $part && null === $asked_part ) ) {
			return Envelope::success( $core, $struct + [ 'section' => '' ] );
		}
		$text = self::section_text( $id, $part );
		if ( '' === $text ) {
			throw new \Exception( 'That skill has no section by that id. Its sections: ' . implode( ', ', array_column( $sections, 'section' ) ) . '.' );
		}
		return Envelope::success( $text, $struct + [ 'section' => $part ] );
	}

	/**
	 * Resources, one per loaded skill.
	 *
	 * @return array<string, array{title:string, description:string}>
	 */
	public static function resources() {
		$out = [];
		foreach ( self::loaded() as $id => $skill ) {
			$out[ self::URI_PREFIX . $id ] = [ 'title' => $skill['name'], 'description' => 'Skill: ' . $skill['does'] . ' Read it before that kind of work. One workflow is at ' . self::URI_PREFIX . $id . '/<section>.' ];
		}
		return $out;
	}

	/** The resource document for a skill address (the core) or a skill/section address, or null. */
	public static function read_resource( $uri ) {
		$path   = explode( '/', substr( (string) $uri, strlen( self::URI_PREFIX ) ) );
		$id     = $path[0];
		$loaded = self::loaded();
		if ( count( $path ) > 2 || ! isset( $loaded[ $id ] ) || ( 2 === count( $path ) && '' === $path[1] ) ) {
			return null;
		}
		$part = $path[1] ?? '';
		$text = '' === $part ? self::text( $id ) : self::section_text( $id, $part );
		if ( '' === $text ) {
			return null;
		}
		return [ 'skill' => $id, 'name' => $loaded[ $id ]['name'], 'section' => $part, 'text' => $text, 'sections' => self::section_list( $id ) ];
	}

	/** Prompts, one per loaded skill, with an optional section. */
	public static function prompts() {
		$out = [];
		foreach ( self::loaded() as $id => $skill ) {
			$out[] = [
				'name'        => self::PROMPT_PREFIX . $id,
				'title'       => $skill['name'],
				'description' => $skill['does'],
				'arguments'   => [
					[
						'name'        => 'section',
						'description' => 'One workflow of the skill: ' . implode( ', ', array_column( self::section_list( $id ), 'section' ) ) . '. Leave out for the core of the skill.',
						'required'    => false,
					],
				],
			];
		}
		return $out;
	}

	/** The result of prompts/get for a skill prompt, or null. */
	public static function prompt( $name, $arguments = [] ) {
		$name = (string) $name;
		if ( 0 !== strpos( $name, self::PROMPT_PREFIX ) ) {
			return null;
		}
		$id     = substr( $name, strlen( self::PROMPT_PREFIX ) );
		$loaded = self::loaded();
		if ( ! isset( $loaded[ $id ] ) ) {
			return null;
		}
		$part = is_array( $arguments ) && isset( $arguments['section'] ) ? self::clean_id( $arguments['section'] ) : '';
		$text = '' === $part ? self::text( $id ) : self::section_text( $id, $part );
		if ( '' === $text ) {
			return null;
		}
		return [
			'description' => $loaded[ $id ]['does'],
			'messages'    => [
				[
					'role'    => 'user',
					'content' => [ 'type' => 'text', 'text' => "Follow this skill for the work on this site.\n\n" . $text ],
				],
			],
		];
	}
}
