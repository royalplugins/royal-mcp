<?php
/**
 * Skills served through the MCP skills extension (io.modelcontextprotocol/skills).
 *
 * Each loaded skill is a small directory of Markdown files:
 *   skill://<id>/SKILL.md                 the frontmatter, then the core with links to its workflows
 *   skill://<id>/workflows/<section>.md   one workflow
 *
 * skills/list and skills/get describe a skill (its frontmatter as a JSON object and a manifest
 * with the digest and byte size of every file); resources/read returns the files. The files are
 * built from the same texts as the mcp_get_skill tool, the royal-mcp://skill/ resources and the
 * skill prompts, and the same skills are offered: Skills::loaded() decides (administrators only,
 * the skill's plugin active, the skill switched on).
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skill_Files {

	/** Extension id, declared under capabilities.extensions. */
	const EXTENSION = 'io.modelcontextprotocol/skills';

	const SCHEME = 'skill://';

	/** How long a client may treat a listing or a file as fresh. */
	const TTL_MS = 300000;

	/** The skills offered depend on the signed-in user, so no cache may be shared between callers. */
	const CACHE_SCOPE = 'private';

	/** Top-level frontmatter keys of the Agent Skills format. */
	const FRONTMATTER_KEYS = [ 'name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools' ];

	/** The value of capabilities.extensions: this server supports skills, without directory reads. */
	public static function capability() {
		return [ self::EXTENSION => new \stdClass() ];
	}

	/** Whether a resource URI is in the skill scheme (it is then answered here, never by another resource). */
	public static function is_skill_uri( $uri ) {
		return is_string( $uri ) && 0 === strncasecmp( $uri, self::SCHEME, strlen( self::SCHEME ) );
	}

	/**
	 * Parse the subset of YAML the bundled frontmatter uses: "key: value" lines, and one level of
	 * "key:" followed by lines indented two spaces. Values are plain, or quoted with " or '.
	 * Anything else gives null, and the skill is then not offered through this extension.
	 *
	 * @return array<string, string|array<string, string>>|null
	 */
	public static function parse_frontmatter( $yaml ) {
		if ( is_string( $yaml ) ) {
			$yaml = str_replace( [ "\r\n", "\r" ], "\n", $yaml );
		}
		if ( ! is_string( $yaml ) || '' === trim( $yaml ) || false !== strpos( $yaml, "\t" ) ) {
			return null;
		}
		$out    = [];
		$parent = null;
		foreach ( explode( "\n", $yaml ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( preg_match( '/^  ([A-Za-z0-9_-]+):(?: (.*))?$/', $line, $m ) ) {
				if ( null === $parent || ! isset( $m[2] ) ) {
					return null;
				}
				$value = self::scalar( $m[2] );
				if ( null === $value || isset( $out[ $parent ][ $m[1] ] ) ) {
					return null;
				}
				$out[ $parent ][ $m[1] ] = $value;
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z0-9_-]+):(?: (.*))?$/', $line, $m ) || isset( $out[ $m[1] ] ) ) {
				return null;
			}
			if ( ! isset( $m[2] ) || '' === trim( $m[2] ) ) {
				$out[ $m[1] ] = [];
				$parent       = $m[1];
				continue;
			}
			$value = self::scalar( $m[2] );
			if ( null === $value ) {
				return null;
			}
			$out[ $m[1] ] = $value;
			$parent       = null;
		}
		foreach ( $out as $key => $value ) {
			// A key opened for nested values must have some; unknown top-level keys are not served.
			if ( ( is_array( $value ) && [] === $value ) || ! in_array( $key, self::FRONTMATTER_KEYS, true ) ) {
				return null;
			}
		}
		if ( ! isset( $out['name'], $out['description'] ) || ! is_string( $out['name'] ) || ! is_string( $out['description'] ) || '' === $out['description'] ) {
			return null;
		}
		return $out;
	}

	/** One YAML scalar on a single line, or null for a form not used here. */
	private static function scalar( $raw ) {
		$raw = trim( (string) $raw, ' ' );
		if ( '' === $raw ) {
			return null;
		}
		if ( '"' === $raw[0] ) {
			if ( ! preg_match( '/^"((?:[^"\\\\]|\\\\["\\\\])*)"$/', $raw, $m ) ) {
				return null;
			}
			return str_replace( [ '\\"', '\\\\' ], [ '"', '\\' ], $m[1] );
		}
		if ( "'" === $raw[0] ) {
			if ( ! preg_match( "/^'((?:[^']|'')*)'$/", $raw, $m ) ) {
				return null;
			}
			return str_replace( "''", "'", $m[1] );
		}
		// Plain scalars: no indicator at the start, no ": " or " #" inside, and nothing YAML would
		// read as a number, a boolean, a null or a date (those must be quoted to stay text).
		if ( false !== strpos( '[]{}&*!|>%@`#,', $raw[0] ) || preg_match( '/^[-?:](?: |$)/', $raw ) || false !== strpos( $raw, ': ' ) || false !== strpos( $raw, ' #' ) || ':' === substr( $raw, -1 ) ) {
			return null;
		}
		if ( preg_match( '/^(?:true|false|yes|no|on|off|y|n|null|~|[-+]?[0-9][0-9_.:eE+-]*|[-+]?\.(?:inf|nan))$/i', $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * The files of a bundled skill, SKILL.md first, as URI => exact bytes served; [] when the
	 * skill has no text or its frontmatter cannot be served.
	 *
	 * @return array<string, string>
	 */
	public static function files( $id ) {
		static $cache = [];
		$id = (string) $id;
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}
		$cache[ $id ] = [];
		$parts        = Skills::parts( $id );
		$front        = self::parse_frontmatter( $parts['frontmatter'] );
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) || '' === $parts['core'] || null === $front || $front['name'] !== $id ) {
			return [];
		}
		$base  = self::SCHEME . $id . '/';
		$links = [];
		$flows = [];
		foreach ( $parts['sections'] as $slug => $section ) {
			$slug = (string) $slug;
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				continue;
			}
			$links[]                                   = '- [' . str_replace( [ '[', ']' ], [ '(', ')' ], $section['title'] ) . '](workflows/' . $slug . '.md): ' . $section['summary'];
			$flows[ $base . 'workflows/' . $slug . '.md' ] = rtrim( $section['text'], "\n" ) . "\n";
		}
		$block = "## Workflows\n\nEach workflow is a part of this skill, in its own file next to this one. Read the one you need before you start that work.\n\n" . implode( "\n", $links );
		$core  = self::with_workflow_links( $parts['core'], $block );
		$out   = [ $base . 'SKILL.md' => "---\n" . $parts['frontmatter'] . "\n---\n\n" . rtrim( $core, "\n" ) . "\n" ];
		$cache[ $id ] = $out + $flows;
		return $cache[ $id ];
	}

	/** The core with its "Workflows" section (which names the mcp_get_skill tool) replaced by links to the files. */
	private static function with_workflow_links( $core, $block ) {
		$start = 0 === strpos( $core, "## Workflows\n" ) ? 0 : strpos( $core, "\n## Workflows\n" );
		if ( false === $start ) {
			return rtrim( $core, "\n" ) . "\n\n" . $block;
		}
		$start += 0 === $start ? 0 : 1;
		$end    = strpos( $core, "\n## ", $start + 1 );
		return substr( $core, 0, $start ) . $block . ( false === $end ? '' : "\n" . substr( $core, $end ) );
	}

	/** The skills offered to this caller, id => entry (as in skills/list). */
	public static function entries() {
		$out = [];
		foreach ( array_keys( Skills::loaded() ) as $id ) {
			$entry = self::entry( $id );
			if ( null !== $entry ) {
				$out[ $id ] = $entry;
			}
		}
		return $out;
	}

	/** A skill entry: SKILL.md URI, frontmatter as stored, and the manifest of every file served. */
	private static function entry( $id ) {
		$files = self::files( $id );
		if ( ! $files ) {
			return null;
		}
		$manifest = [];
		foreach ( $files as $uri => $bytes ) {
			$manifest[] = [
				'uri'    => $uri,
				'digest' => 'sha256:' . hash( 'sha256', $bytes ),
				'size'   => strlen( $bytes ),
			];
		}
		return [
			'uri'         => self::SCHEME . $id . '/SKILL.md',
			'frontmatter' => self::parse_frontmatter( Skills::parts( $id )['frontmatter'] ),
			'resources'   => $manifest,
		];
	}

	/** The id of an offered skill whose URI tree holds $uri, or null. Only exact URIs match. */
	private static function offered_id( $uri ) {
		if ( ! is_string( $uri ) || ! preg_match( '#^skill://([a-z0-9]+(?:-[a-z0-9]+)*)/(?:SKILL\.md|workflows/[a-z0-9]+(?:-[a-z0-9]+)*\.md)$#D', $uri, $m ) ) {
			return null;
		}
		$loaded = Skills::loaded();
		return isset( $loaded[ $m[1] ] ) && isset( self::files( $m[1] )[ $uri ] ) ? $m[1] : null;
	}

	/** Reply of skills/list: every offered skill, on one page. */
	public static function handle_list( $params, $id ) {
		if ( is_array( $params ) && isset( $params['cursor'] ) && '' !== $params['cursor'] ) {
			return self::error( $id, 'Invalid or expired cursor. Request skills/list again without a cursor.' );
		}
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'resultType' => 'complete',
				'skills'     => array_values( self::entries() ),
				'ttlMs'      => self::TTL_MS,
				'cacheScope' => self::CACHE_SCOPE,
			],
		];
	}

	/** Reply of skills/get: the entry of one skill, by the URI of its SKILL.md. */
	public static function handle_get( $params, $id ) {
		$uri   = is_array( $params ) && isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
		$skill = self::offered_id( $uri );
		if ( null === $skill || self::SCHEME . $skill . '/SKILL.md' !== $uri ) {
			return self::error( $id, '' === $uri ? 'A skill uri is required.' : 'Unknown skill.', $uri );
		}
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'resultType' => 'complete',
				'skill'      => self::entry( $skill ),
				'ttlMs'      => self::TTL_MS,
				'cacheScope' => self::CACHE_SCOPE,
			],
		];
	}

	/** Reply of resources/read for a skill:// URI: the file's exact bytes, or -32602 when it is not served. */
	public static function read_reply( $uri, $id ) {
		$skill = self::offered_id( $uri );
		if ( null === $skill ) {
			return self::error( $id, 'Unknown skill file.', $uri );
		}
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'resultType' => 'complete',
				'contents'   => [
					[
						'uri'      => $uri,
						'mimeType' => 'text/markdown',
						'text'     => self::files( $skill )[ $uri ],
					],
				],
				'ttlMs'      => self::TTL_MS,
				'cacheScope' => self::CACHE_SCOPE,
			],
		];
	}

	/** Invalid params: an unknown skill or file is answered the same way as one the caller may not see. */
	private static function error( $id, $message, $uri = null ) {
		$error = [
			'code'    => -32602,
			'message' => $message,
		];
		if ( is_string( $uri ) && '' !== $uri ) {
			$error['data'] = [ 'uri' => substr( sanitize_text_field( $uri ), 0, 200 ) ];
		}
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		];
	}
}
