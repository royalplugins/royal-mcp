<?php
/**
 * Colour palette and base typography of classic themes that keep their own
 * design settings (Astra, GeneratePress, Kadence, Blocksy), read and written in
 * one shape, with undo. Also a read of Spectra's global block styles.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Theme_Design {

	const TOOLS = [ 'wp_get_theme_design', 'wp_update_theme_design', 'spectra_get_global_styles' ];

	const THEMES = [
		'astra'         => 'Astra',
		'generatepress' => 'GeneratePress',
		'kadence'       => 'Kadence',
		'blocksy'       => 'Blocksy',
	];

	/** Astra's slot names when the theme cannot be asked (current installs). */
	const ASTRA_LABELS = [ 'Brand', 'Alternate Brand', 'Headings', 'Text', 'Primary Background', 'Secondary Background', 'Alternate Background', 'Subtle Background', 'Other Supporting' ];

	const ASTRA_FONT_KEYS = [ 'body-font-family', 'body-font-weight', 'font-size-body', 'headings-font-family', 'headings-font-weight' ];

	/** Kadence's stored value for "work out a complementing colour". */
	const KADENCE_AUTO = '#FfFfFf';

	const BLOCKSY_DEFAULTS = [ '#2872fa', '#1559ed', '#3A4F66', '#192a3d', '#e1e8ed', '#f2f5f7', '#FAFBFC', '#ffffff' ];

	const BLOCKSY_HEADING_SIZES = [ 1 => '40px', 2 => '35px', 3 => '30px', 4 => '25px', 5 => '20px', 6 => '16px' ];

	const FAMILY_KEYWORDS = [ 'inherit', 'initial', 'unset', 'revert', 'default', 'none' ];

	public static function get_tools() {
		$typo = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'body'     => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'family' => [ 'type' => 'string', 'description' => 'One font family name, e.g. "Inter" (letters, digits, spaces, - and _). The font must already be available to the theme (system font, or one the theme loads).' ],
						'weight' => [ 'type' => 'string', 'description' => '100-900, normal or bold. An italic style is kept.' ],
						'size'   => [ 'type' => [ 'string', 'number' ], 'description' => 'Desktop size: a number (px) or e.g. "17px", "1.1rem". Tablet and mobile sizes are kept.' ],
					],
				],
				'headings' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'family' => [ 'type' => 'string' ],
						'weight' => [ 'type' => 'string', 'description' => 'Applied to H1-H6.' ],
					],
				],
			],
		];
		return [
			[
				'name'        => 'wp_get_theme_design',
				'description' => 'Read-only: the colour palette and base typography (body and heading font, weight, size) of the active theme when it is Astra, GeneratePress, Kadence or Blocksy, in one shape. Palette slots are the ids to pass to wp_update_theme_design. "mixed" means the headings differ between levels; "auto" (Kadence) means the theme works the colour out itself; an empty value means the theme\'s built-in default. For block themes use wp_get_global_styles.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'wp_update_theme_design',
				'description' => 'Change palette colours and/or base typography of the active Astra, GeneratePress, Kadence or Blocksy theme, with undo. Only the slots and fields you pass change; undo puts back exactly those settings. Requires the "Allow AI to modify theme appearance" setting. Read wp_get_theme_design first for slot ids. dry_run shows what would change.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'palette'    => [ 'type' => 'object', 'description' => 'Slot id => hex colour, e.g. {"0":"#1e40af"} on Astra, {"contrast":"#111111"} on GeneratePress, {"palette1":"#1e40af"} on Kadence, {"color1":"#1e40af"} on Blocksy.', 'additionalProperties' => [ 'type' => 'string' ] ],
						'typography' => $typo,
						'dry_run'    => [ 'type' => 'boolean' ],
					],
				],
			],
			[
				'name'        => 'spectra_get_global_styles',
				'description' => 'Read-only: Spectra\'s global block styles (saved style presets for Spectra blocks): name, class, block type and how many posts use each. include_attributes adds each style\'s saved block settings.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'include_attributes' => [ 'type' => 'boolean' ],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, array $args ) {
		switch ( $name ) {
			case 'wp_get_theme_design':
				if ( ! current_user_can( 'edit_theme_options' ) ) {
					throw new \Exception( 'You do not have permission to read the theme design (edit_theme_options).' );
				}
				return self::read_tool();
			case 'wp_update_theme_design':
				return self::update_tool( $args );
			case 'spectra_get_global_styles':
				return self::spectra_tool( $args );
		}
		throw new \Exception( 'Unknown theme design tool.' );
	}

	/** The active parent theme's slug when it is one of the supported themes, else ''. */
	public static function theme() {
		$slug = strtolower( (string) get_template() );
		return isset( self::THEMES[ $slug ] ) ? $slug : '';
	}

	public static function theme_supported() {
		return '' !== self::theme();
	}

	public static function spectra_active() {
		return defined( 'UAGB_VER' ) || class_exists( '\UAGB_Loader' );
	}

	/* ------------------------------------------------------------------
	 *  Reads
	 * ------------------------------------------------------------------ */

	private static function read_tool() {
		$theme = self::theme();
		if ( '' === $theme ) {
			return [
				'theme'     => (string) get_template(),
				'supported' => false,
				'message'   => wp_is_block_theme() ? 'This is a block theme: read its palette and fonts with wp_get_global_styles.' : 'The active theme is not Astra, GeneratePress, Kadence or Blocksy. Its own settings may be readable with wp_get_theme_mods.',
			];
		}
		$out = array_merge( [ 'theme' => $theme, 'theme_name' => self::THEMES[ $theme ], 'supported' => true ], self::design( $theme ) );
		$out['writable'] = self::writes_allowed();
		$block           = self::write_block( $theme );
		if ( '' !== $block ) {
			$out['writable'] = false;
			$out['note']     = $block;
		}
		return $out;
	}

	/** Normalised palette + typography for $theme. */
	private static function design( $theme ) {
		switch ( $theme ) {
			case 'astra':
				$pal     = self::astra_palette_data();
				$cur     = (string) $pal['currentPalette'];
				$cols    = array_values( (array) ( $pal['palettes'][ $cur ] ?? [] ) );
				$labels  = self::astra_labels();
				$retired = self::astra_retired_slots( $pal );
				$palette = [];
				foreach ( $cols as $i => $c ) {
					if ( isset( $retired[ $i ] ) ) {
						continue;
					}
					$palette[] = [ 'slot' => (string) $i, 'label' => (string) ( $labels[ $i ] ?? sprintf( 'Custom %d', $i - 8 ) ), 'color' => (string) $c ];
				}
				$s    = self::astra_settings();
				$d    = self::astra_defaults();
				$size = $s['font-size-body'] ?? ( $d['font-size-body'] ?? [] );
				return [
					'palette_set' => $cur,
					'palette'     => $palette,
					'typography'  => [
						'body'     => [ 'family' => self::astra_family_name( $s['body-font-family'] ?? ( $d['body-font-family'] ?? 'inherit' ) ), 'weight' => (string) ( $s['body-font-weight'] ?? ( $d['body-font-weight'] ?? '' ) ), 'size' => self::size_string( is_array( $size ) ? ( $size['desktop'] ?? '' ) : '', is_array( $size ) ? ( $size['desktop-unit'] ?? 'px' ) : 'px' ) ],
						'headings' => [ 'family' => self::astra_family_name( $s['headings-font-family'] ?? ( $d['headings-font-family'] ?? 'inherit' ) ), 'weight' => (string) ( $s['headings-font-weight'] ?? ( $d['headings-font-weight'] ?? '' ) ) ],
					],
				];
			case 'generatepress':
				$s       = self::gp_settings();
				$palette = [];
				foreach ( self::gp_colors( $s ) as $c ) {
					$palette[] = [ 'slot' => (string) ( $c['slug'] ?? '' ), 'label' => (string) ( $c['name'] ?? '' ), 'color' => (string) ( $c['color'] ?? '' ) ];
				}
				$typo = [ 'body' => [ 'family' => '', 'weight' => '', 'size' => '' ], 'headings' => [ 'family' => '', 'weight' => '' ] ];
				if ( self::gp_dynamic( $s ) ) {
					foreach ( (array) ( $s['typography'] ?? [] ) as $e ) {
						if ( ! is_array( $e ) ) {
							continue;
						}
						if ( 'body' === ( $e['selector'] ?? '' ) ) {
							$typo['body'] = [ 'family' => (string) ( $e['fontFamily'] ?? '' ), 'weight' => (string) ( $e['fontWeight'] ?? '' ), 'size' => self::size_string( $e['fontSize'] ?? '', $e['fontSizeUnit'] ?? 'px' ) ];
						} elseif ( 'all-headings' === ( $e['selector'] ?? '' ) ) {
							$typo['headings'] = [ 'family' => (string) ( $e['fontFamily'] ?? '' ), 'weight' => (string) ( $e['fontWeight'] ?? '' ) ];
						}
					}
				} else {
					$typo['body'] = [ 'family' => (string) ( $s['font_body'] ?? '' ), 'weight' => (string) ( $s['body_font_weight'] ?? '' ), 'size' => self::size_string( $s['body_font_size'] ?? '', 'px' ) ];
				}
				return [ 'palette' => $palette, 'typography' => $typo ];
			case 'kadence':
				$k       = self::kadence_palette_data();
				$set     = (string) $k['active'];
				$palette = [];
				foreach ( (array) ( $k[ $set ] ?? [] ) as $c ) {
					if ( is_array( $c ) ) {
						$color     = (string) ( $c['color'] ?? '' );
						$palette[] = [ 'slot' => (string) ( $c['slug'] ?? '' ), 'label' => (string) ( $c['name'] ?? '' ), 'color' => self::KADENCE_AUTO === $color ? 'auto' : $color ];
					}
				}
				$base    = self::kadence_font( 'base_font' );
				$head    = self::kadence_font( 'heading_font' );
				$weights = [];
				for ( $i = 1; $i <= 6; $i++ ) {
					$weights[] = (string) ( self::kadence_font( 'h' . $i . '_font' )['weight'] ?? '' );
				}
				return [
					'palette_set' => $set,
					'palette'     => $palette,
					'typography'  => [
						'body'     => [ 'family' => (string) ( $base['family'] ?? '' ), 'weight' => (string) ( $base['weight'] ?? '' ), 'size' => self::size_string( is_array( $base['size'] ?? null ) ? ( $base['size']['desktop'] ?? '' ) : '', is_string( $base['sizeType'] ?? null ) ? $base['sizeType'] : 'px' ) ],
						'headings' => [ 'family' => (string) ( $head['family'] ?? '' ), 'weight' => self::same_or_mixed( $weights ) ],
					],
				];
			case 'blocksy':
				$p       = self::blocksy_palette();
				$palette = [];
				foreach ( $p as $key => $c ) {
					if ( preg_match( '/^color\d+$/', (string) $key ) && is_array( $c ) ) {
						$palette[] = [ 'slot' => (string) $key, 'label' => sprintf( 'Color %d', (int) substr( (string) $key, 5 ) ), 'color' => (string) ( $c['color'] ?? '' ) ];
					}
				}
				$root     = self::blocksy_font( 'rootTypography', 0 );
				$families = [];
				$weights  = [];
				for ( $i = 1; $i <= 6; $i++ ) {
					$h          = self::blocksy_font( 'h' . $i . 'Typography', $i );
					$families[] = (string) ( $h['family'] ?? '' );
					$weights[]  = self::blocksy_weight( $h['variation'] ?? '' );
				}
				return [
					'palette'    => $palette,
					'typography' => [
						'body'     => [ 'family' => (string) ( $root['family'] ?? '' ), 'weight' => self::blocksy_weight( $root['variation'] ?? '' ), 'size' => self::blocksy_size( $root['size'] ?? '' ) ],
						'headings' => [ 'family' => self::same_or_mixed( $families ), 'weight' => self::same_or_mixed( $weights ) ],
					],
				];
		}
		return [];
	}

	private static function same_or_mixed( array $values ) {
		$values = array_values( array_unique( $values ) );
		return 1 === count( $values ) ? (string) $values[0] : 'mixed';
	}

	/* ------------------------------------------------------------------
	 *  Write
	 * ------------------------------------------------------------------ */

	private static function writes_allowed() {
		$settings = get_option( 'royal_mcp_settings', [] );
		return is_array( $settings ) && ! empty( $settings['allow_theme_writes'] );
	}

	/** A reason this theme's design cannot be written right now, or ''. */
	private static function write_block( $theme ) {
		if ( 'kadence' === $theme && function_exists( 'Kadence\is_fse_mode' ) && \Kadence\is_fse_mode() ) {
			return 'Kadence is in Site Editor mode: its palette and fonts come from the global styles. Use wp_update_global_styles.';
		}
		return '';
	}

	private static function update_tool( array $args ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new \Exception( 'You do not have permission to change the theme design (edit_theme_options).' );
		}
		if ( ! self::writes_allowed() ) {
			throw new Tool_Refusal( 'theme_writes_disabled', 'Theme writes are disabled. Enable "Allow AI to modify theme appearance" under Royal MCP > Settings.' );
		}
		$theme = self::theme();
		if ( '' === $theme ) {
			throw new \Exception( wp_is_block_theme() ? 'This is a block theme: change its palette and fonts with wp_update_global_styles.' : 'The active theme is not Astra, GeneratePress, Kadence or Blocksy.' );
		}
		$block = self::write_block( $theme );
		if ( '' !== $block ) {
			throw new Tool_Refusal( 'theme_mode', $block );
		}
		list( $palette, $typo ) = self::clean_request( $theme, $args );
		if ( ! $palette && ! $typo ) {
			throw new \Exception( 'Pass palette (slot => hex colour) and/or typography (body / headings: family, weight, size). Read wp_get_theme_design for the slot ids.' );
		}
		$before = self::design( $theme );
		list( , $differs ) = self::compare( $before, $palette, $typo );
		if ( ! empty( $args['dry_run'] ) ) {
			return [ 'state' => 'dry_run', 'would_execute' => (bool) $differs, 'theme' => $theme, 'would_change' => $differs, 'palette' => (object) $palette, 'typography' => $typo ];
		}
		// Values the theme already shows are not written: the first save of a theme that runs on its defaults would otherwise store a settings record for no visible change.
		if ( ! $differs ) {
			return Envelope::success( 'Nothing changed: the theme already has those values.', [ 'theme' => $theme, 'changed' => false, 'saved_fields' => [], 'not_kept' => [], 'palette' => $before['palette'] ?? [], 'typography' => $before['typography'] ?? [] ] );
		}
		$specs = self::units( $theme );
		$prior = self::read_units( $specs );
		$next  = self::apply( $theme, $prior, $palette, $typo );
		// Only the settings this call changes are written, kept for undo and checked for later edits.
		$touched_prior = [];
		$touched_next  = [];
		foreach ( $next as $i => $row ) {
			if ( $row !== $prior[ $i ] ) {
				$touched_prior[] = $prior[ $i ];
				$touched_next[]  = $row;
			}
		}
		self::write_units( $touched_next );
		self::refresh( $theme, $args );
		$written = self::read_units( self::specs_of( $touched_prior ) );
		$after   = self::design( $theme );

		list( $landed, $not_kept ) = self::compare( $after, $palette, $typo );
		$changed  = $written != $touched_prior; // phpcs:ignore Universal.Operators.StrictComparisons -- stored values may come back with numeric strings.
		$warnings = [];
		$undo     = [];
		if ( $changed ) {
			$undo = \Royal_MCP\MCP\Undo_Store::store( [
				'op'           => 'wp_update_theme_design',
				'summary'      => sprintf( 'Put the %s palette and fonts back as they were.', self::THEMES[ $theme ] ),
				'target'       => [ 'theme' => $theme, 'stylesheet' => (string) get_stylesheet() ],
				'pre_op_state' => [ 'prior' => $touched_prior, 'written' => $written ],
			] );
			if ( ! $undo ) {
				$warnings[] = 'Undo is not available for this change: the earlier values could not be stored.';
			}
		}
		$text = $changed ? sprintf( 'Updated the %s design (%s)', self::THEMES[ $theme ], implode( ', ', $landed ? $landed : [ 'no visible change' ] ) ) : 'Nothing changed: the theme already had those values';
		if ( $not_kept ) {
			$text .= sprintf( '. Not kept as sent: %s', implode( ', ', $not_kept ) );
		}
		$out = [
			'theme'        => $theme,
			'changed'      => $changed,
			'saved_fields' => $landed,
			'not_kept'     => $not_kept,
			'palette'      => $after['palette'] ?? [],
			'typography'   => $after['typography'] ?? [],
		];
		if ( $warnings ) {
			$out['warnings'] = $warnings;
			$text           .= '. ' . implode( ' ', $warnings );
		}
		return Envelope::success( rtrim( $text, '.' ) . '.', $out, $undo );
	}

	/**
	 * Validate and normalise the request.
	 *
	 * @return array{0: array<string,string>, 1: array}
	 */
	private static function clean_request( $theme, array $args ) {
		$palette = [];
		if ( isset( $args['palette'] ) ) {
			if ( ! is_array( $args['palette'] ) && ! is_object( $args['palette'] ) ) {
				throw new \Exception( 'palette must be an object of slot => hex colour.' );
			}
			$slots = array_column( self::design( $theme )['palette'] ?? [], 'slot' );
			foreach ( (array) $args['palette'] as $slot => $color ) {
				$slot = (string) $slot;
				if ( ! in_array( $slot, $slots, true ) ) {
					throw new \Exception( sprintf( 'Unknown palette slot "%s". Slots on this theme: %s.', esc_html( $slot ), esc_html( implode( ', ', $slots ) ) ) );
				}
				$hex = is_string( $color ) ? sanitize_hex_color( trim( $color ) ) : null;
				if ( ! $hex ) {
					throw new \Exception( sprintf( 'Palette slot "%s" needs a hex colour such as #1e40af.', esc_html( $slot ) ) );
				}
				$palette[ $slot ] = $hex;
			}
		}
		$typo = [];
		if ( isset( $args['typography'] ) ) {
			if ( ! is_array( $args['typography'] ) && ! is_object( $args['typography'] ) ) {
				throw new \Exception( 'typography must be an object with body and/or headings.' );
			}
			foreach ( (array) $args['typography'] as $part => $fields ) {
				if ( ! in_array( $part, [ 'body', 'headings' ], true ) || ( ! is_array( $fields ) && ! is_object( $fields ) ) ) {
					throw new \Exception( 'typography takes body and headings objects only.' );
				}
				foreach ( (array) $fields as $field => $value ) {
					$allowed = 'body' === $part ? [ 'family', 'weight', 'size' ] : [ 'family', 'weight' ];
					if ( ! in_array( $field, $allowed, true ) ) {
						throw new \Exception( sprintf( 'typography.%s takes %s.', esc_html( $part ), esc_html( implode( ', ', $allowed ) ) ) );
					}
					$typo[ $part ][ $field ] = self::clean_typo_value( $field, $value, $part );
				}
			}
			if ( $typo && 'generatepress' === $theme && ! self::gp_dynamic( self::gp_settings() ) ) {
				throw new \Exception( 'This GeneratePress site uses the older typography system; switch on dynamic typography in the Customizer before changing fonts here.' );
			}
			if ( $typo && 'kadence' === $theme && function_exists( 'Kadence\kadence' ) && 'theme_mod' !== \Kadence\kadence()->get_option_type() ) {
				throw new \Exception( 'This Kadence site stores its fonts in a custom place; change them in the Customizer.' );
			}
		}
		return [ $palette, $typo ];
	}

	private static function clean_typo_value( $field, $value, $part ) {
		$where = $part . '.' . $field;
		if ( 'family' === $field ) {
			$v = is_string( $value ) ? trim( $value, " \t\"'" ) : '';
			$v = (string) preg_replace( '/ {2,}/', ' ', $v );
			// One family name of plain characters: the themes print it into CSS without escaping.
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 _-]{0,79}$/', $v ) || in_array( strtolower( $v ), self::FAMILY_KEYWORDS, true ) ) {
				throw new \Exception( sprintf( '%s needs one font family name such as "Inter" (letters, digits, spaces, - and _).', esc_html( $where ) ) );
			}
			return $v;
		}
		if ( 'weight' === $field ) {
			$v = strtolower( trim( (string) ( is_scalar( $value ) ? $value : '' ) ) );
			$v = [ 'normal' => '400', 'bold' => '700' ][ $v ] ?? $v;
			if ( ! preg_match( '/^[1-9]00$/', $v ) ) {
				throw new \Exception( sprintf( '%s takes 100-900 (in hundreds), normal or bold.', esc_html( $where ) ) );
			}
			return $v;
		}
		$v = is_int( $value ) || is_float( $value ) ? $value . 'px' : strtolower( trim( (string) ( is_string( $value ) ? $value : '' ) ) );
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em)$/', $v, $m ) ) {
			throw new \Exception( sprintf( '%s takes a number (px) or a size such as "17px" or "1.1rem".', esc_html( $where ) ) );
		}
		$n  = (float) $m[1];
		$ok = 'px' === $m[2] ? ( $n >= 8 && $n <= 120 ) : ( $n >= 0.5 && $n <= 8 );
		if ( ! $ok ) {
			throw new \Exception( sprintf( '%s is out of range (8-120px, 0.5-8rem/em).', esc_html( $where ) ) );
		}
		return [ 'value' => 0.0 === fmod( $n, 1.0 ) ? (int) $n : $n, 'unit' => $m[2] ];
	}

	/**
	 * Settings this theme's design lives in: [kind, name, key]. kind is option or mod;
	 * key names one entry inside an option array ('' for the whole option).
	 */
	private static function units( $theme ) {
		switch ( $theme ) {
			case 'astra':
				$u = [ [ 'option', 'astra-color-palettes', '' ], [ 'option', 'astra-settings', 'global-color-palette' ] ];
				foreach ( self::ASTRA_FONT_KEYS as $k ) {
					$u[] = [ 'option', 'astra-settings', $k ];
				}
				return $u;
			case 'generatepress':
				return [ [ 'option', 'generate_settings', 'global_colors' ], [ 'option', 'generate_settings', 'typography' ] ];
			case 'kadence':
				$u = [ [ 'option', 'kadence_global_palette', '' ], [ 'mod', 'base_font', '' ], [ 'mod', 'heading_font', '' ] ];
				for ( $i = 1; $i <= 6; $i++ ) {
					$u[] = [ 'mod', 'h' . $i . '_font', '' ];
				}
				return $u;
			case 'blocksy':
				$u = [ [ 'mod', 'colorPalette', '' ], [ 'mod', 'rootTypography', '' ] ];
				for ( $i = 1; $i <= 6; $i++ ) {
					$u[] = [ 'mod', 'h' . $i . 'Typography', '' ];
				}
				return $u;
		}
		return [];
	}

	private static function specs_of( array $rows ) {
		return array_map( static function ( $r ) {
			return [ (string) ( $r['kind'] ?? '' ), (string) ( $r['name'] ?? '' ), (string) ( $r['key'] ?? '' ) ];
		}, $rows );
	}

	/** An option as stored, without translation filters (Astra keeps translatable texts in its settings). */
	private static function raw_option( $name, $missing ) {
		if ( 'astra-settings' === $name && function_exists( 'astra_get_raw_options' ) ) {
			return astra_get_raw_options( $missing );
		}
		return get_option( $name, $missing );
	}

	/** Stored values: list of {kind, name, key, exists, value}. */
	private static function read_units( array $specs ) {
		$out  = [];
		$mods = (array) get_theme_mods();
		foreach ( $specs as $u ) {
			list( $kind, $name, $key ) = array_pad( $u, 3, '' );
			$miss = new \stdClass();
			if ( 'option' === $kind ) {
				$v = self::raw_option( $name, $miss );
				if ( '' === $key ) {
					$exists = $v !== $miss;
				} else {
					$exists = is_array( $v ) && array_key_exists( $key, $v );
					$v      = $exists ? $v[ $key ] : null;
				}
			} else {
				$exists = array_key_exists( $name, $mods );
				$v      = $exists ? $mods[ $name ] : null;
			}
			$out[] = [ 'kind' => $kind, 'name' => $name, 'key' => $key, 'exists' => $exists, 'value' => $exists ? $v : null ];
		}
		return $out;
	}

	private static function write_units( array $rows ) {
		$keyed = [];
		foreach ( $rows as $r ) {
			$key = (string) ( $r['key'] ?? '' );
			if ( 'option' === $r['kind'] && '' !== $key ) {
				$keyed[ $r['name'] ][ $key ] = $r;
				continue;
			}
			if ( 'option' === $r['kind'] ) {
				if ( empty( $r['exists'] ) ) {
					delete_option( $r['name'] );
				} else {
					update_option( $r['name'], $r['value'] );
				}
			} elseif ( empty( $r['exists'] ) ) {
				remove_theme_mod( $r['name'] );
			} else {
				set_theme_mod( $r['name'], $r['value'] );
			}
		}
		foreach ( $keyed as $name => $entries ) {
			$miss = new \stdClass();
			$opt  = self::raw_option( $name, $miss );
			$had  = $opt !== $miss;
			$opt  = is_array( $opt ) ? $opt : [];
			foreach ( $entries as $key => $r ) {
				if ( empty( $r['exists'] ) ) {
					unset( $opt[ $key ] );
				} else {
					$opt[ $key ] = $r['value'];
				}
			}
			if ( ! $had && ! $opt ) {
				continue;
			}
			update_option( $name, $opt );
		}
	}

	private static function unit_value( array $rows, $name, $key = '' ) {
		foreach ( $rows as $r ) {
			if ( $r['name'] === $name && (string) $r['key'] === $key ) {
				return $r['exists'] ? $r['value'] : null;
			}
		}
		return null;
	}

	private static function set_unit( array &$rows, $name, $value, $key = '' ) {
		foreach ( $rows as &$r ) {
			if ( $r['name'] === $name && (string) $r['key'] === $key ) {
				$r['exists'] = true;
				$r['value']  = $value;
			}
		}
		unset( $r );
	}

	/** Kadence variant for a weight, keeping italic. */
	private static function kadence_variant( $weight, $old_variant ) {
		$italic = is_string( $old_variant ) && 'italic' === substr( $old_variant, -6 );
		if ( '400' === $weight ) {
			return $italic ? 'italic' : 'regular';
		}
		return $weight . ( $italic ? 'italic' : '' );
	}

	/** Blocksy variation for a weight, keeping italic. */
	private static function blocksy_variation( $weight, $old ) {
		return ( is_string( $old ) && 'i' === substr( $old, 0, 1 ) ? 'i' : 'n' ) . substr( $weight, 0, 1 );
	}

	/** New stored values with the request applied. */
	private static function apply( $theme, array $rows, array $palette, array $typo ) {
		switch ( $theme ) {
			case 'astra':
				if ( $palette ) {
					$pal = self::astra_palette_data();
					$cur = (string) $pal['currentPalette'];
					$set = array_values( (array) ( $pal['palettes'][ $cur ] ?? [] ) );
					foreach ( $palette as $slot => $hex ) {
						$set[ (int) $slot ] = $hex;
					}
					$pal['palettes'][ $cur ] = $set;
					unset( $pal['presets'] );
					self::set_unit( $rows, 'astra-color-palettes', $pal );
					$gp            = self::unit_value( $rows, 'astra-settings', 'global-color-palette' );
					$gp            = is_array( $gp ) ? $gp : [];
					$gp['palette'] = $set;
					self::set_unit( $rows, 'astra-settings', $gp, 'global-color-palette' );
				}
				foreach ( [ 'body', 'headings' ] as $part ) {
					if ( isset( $typo[ $part ]['family'] ) ) {
						self::set_unit( $rows, 'astra-settings', "'" . $typo[ $part ]['family'] . "', sans-serif", $part . '-font-family' );
					}
					if ( isset( $typo[ $part ]['weight'] ) ) {
						self::set_unit( $rows, 'astra-settings', $typo[ $part ]['weight'], $part . '-font-weight' );
					}
				}
				if ( isset( $typo['body']['size'] ) ) {
					$size = self::unit_value( $rows, 'astra-settings', 'font-size-body' );
					$size = is_array( $size ) ? $size : (array) ( self::astra_defaults()['font-size-body'] ?? [] );
					$size['desktop']      = $typo['body']['size']['value'];
					$size['desktop-unit'] = $typo['body']['size']['unit'];
					self::set_unit( $rows, 'astra-settings', $size, 'font-size-body' );
				}
				return $rows;
			case 'generatepress':
				if ( $palette ) {
					$cols = self::gp_colors( self::gp_settings() );
					foreach ( $cols as &$c ) {
						if ( isset( $palette[ (string) ( $c['slug'] ?? '' ) ] ) ) {
							$c['color'] = $palette[ (string) $c['slug'] ];
						}
					}
					unset( $c );
					self::set_unit( $rows, 'generate_settings', array_values( $cols ), 'global_colors' );
				}
				if ( $typo ) {
					$list = self::unit_value( $rows, 'generate_settings', 'typography' );
					$list = is_array( $list ) ? array_values( $list ) : [];
					foreach ( [ 'body' => [ 'body', 'base' ], 'headings' => [ 'all-headings', 'content' ] ] as $part => $where ) {
						if ( empty( $typo[ $part ] ) ) {
							continue;
						}
						$idx = null;
						foreach ( $list as $i => $e ) {
							if ( is_array( $e ) && $where[0] === ( $e['selector'] ?? '' ) ) {
								$idx = $i;
								break;
							}
						}
						if ( null === $idx ) {
							$base   = class_exists( '\GeneratePress_Typography' ) && method_exists( '\GeneratePress_Typography', 'get_defaults' ) ? (array) \GeneratePress_Typography::get_defaults() : [];
							$list[] = array_merge( $base, [ 'selector' => $where[0], 'module' => 'core', 'group' => $where[1] ] );
							$idx    = count( $list ) - 1;
						}
						if ( isset( $typo[ $part ]['family'] ) ) {
							$list[ $idx ]['fontFamily'] = $typo[ $part ]['family'];
						}
						if ( isset( $typo[ $part ]['weight'] ) ) {
							$list[ $idx ]['fontWeight'] = $typo[ $part ]['weight'];
						}
						if ( isset( $typo[ $part ]['size'] ) ) {
							$list[ $idx ]['fontSize']     = $typo[ $part ]['size']['value'];
							$list[ $idx ]['fontSizeUnit'] = $typo[ $part ]['size']['unit'];
						}
					}
					self::set_unit( $rows, 'generate_settings', $list, 'typography' );
				}
				return $rows;
			case 'kadence':
				if ( $palette ) {
					$k   = self::kadence_palette_data();
					$set = (string) $k['active'];
					foreach ( $k[ $set ] as &$c ) {
						if ( is_array( $c ) && isset( $palette[ (string) ( $c['slug'] ?? '' ) ] ) ) {
							$c['color'] = $palette[ (string) $c['slug'] ];
						}
					}
					unset( $c );
					self::set_unit( $rows, 'kadence_global_palette', (string) wp_json_encode( $k ) );
				}
				if ( ! empty( $typo['body'] ) ) {
					$f = self::kadence_font( 'base_font' );
					if ( isset( $typo['body']['family'] ) && $typo['body']['family'] !== ( $f['family'] ?? '' ) ) {
						$f['family'] = $typo['body']['family'];
						$f['google'] = self::kadence_is_google_font( $typo['body']['family'] );
					}
					if ( isset( $typo['body']['weight'] ) ) {
						$f['weight']  = $typo['body']['weight'];
						$f['variant'] = self::kadence_variant( $typo['body']['weight'], $f['variant'] ?? '' );
					}
					if ( isset( $typo['body']['size'] ) ) {
						$f['size']            = isset( $f['size'] ) && is_array( $f['size'] ) ? $f['size'] : [];
						$f['size']['desktop'] = $typo['body']['size']['value'];
						$f['sizeType']        = $typo['body']['size']['unit'];
					}
					self::set_unit( $rows, 'base_font', $f );
				}
				if ( isset( $typo['headings']['family'] ) ) {
					$f = self::kadence_font( 'heading_font' );
					if ( $typo['headings']['family'] !== ( $f['family'] ?? '' ) ) {
						$f['family'] = $typo['headings']['family'];
						$f['google'] = self::kadence_is_google_font( $typo['headings']['family'] );
						self::set_unit( $rows, 'heading_font', $f );
					}
				}
				if ( isset( $typo['headings']['weight'] ) ) {
					for ( $i = 1; $i <= 6; $i++ ) {
						$f = self::kadence_font( 'h' . $i . '_font' );
						if ( (string) ( $f['weight'] ?? '' ) === $typo['headings']['weight'] ) {
							continue;
						}
						$f['weight']  = $typo['headings']['weight'];
						$f['variant'] = self::kadence_variant( $typo['headings']['weight'], $f['variant'] ?? '' );
						self::set_unit( $rows, 'h' . $i . '_font', $f );
					}
				}
				return $rows;
			case 'blocksy':
				if ( $palette ) {
					$p = self::blocksy_palette();
					foreach ( $palette as $slot => $hex ) {
						$p[ $slot ]          = isset( $p[ $slot ] ) && is_array( $p[ $slot ] ) ? $p[ $slot ] : [];
						$p[ $slot ]['color'] = $hex;
					}
					self::set_unit( $rows, 'colorPalette', $p );
				}
				if ( ! empty( $typo['body'] ) ) {
					$f = self::blocksy_font( 'rootTypography', 0 );
					if ( isset( $typo['body']['family'] ) ) {
						$f['family'] = $typo['body']['family'];
					}
					if ( isset( $typo['body']['weight'] ) ) {
						$f['variation'] = self::blocksy_variation( $typo['body']['weight'], $f['variation'] ?? '' );
					}
					if ( isset( $typo['body']['size'] ) ) {
						$css = $typo['body']['size']['value'] . $typo['body']['size']['unit'];
						if ( isset( $f['size'] ) && is_array( $f['size'] ) ) {
							$f['size']['desktop'] = $css;
						} else {
							$f['size'] = $css;
						}
					}
					self::set_unit( $rows, 'rootTypography', $f );
				}
				if ( ! empty( $typo['headings'] ) ) {
					for ( $i = 1; $i <= 6; $i++ ) {
						$f   = self::blocksy_font( 'h' . $i . 'Typography', $i );
						$was = $f;
						if ( isset( $typo['headings']['family'] ) ) {
							$f['family'] = $typo['headings']['family'];
						}
						if ( isset( $typo['headings']['weight'] ) ) {
							$f['variation'] = self::blocksy_variation( $typo['headings']['weight'], $f['variation'] ?? '' );
						}
						if ( $f !== $was ) {
							self::set_unit( $rows, 'h' . $i . 'Typography', $f );
						}
					}
				}
				return $rows;
		}
		return $rows;
	}

	/** Rebuild the theme's generated CSS after a write. */
	private static function refresh( $theme, array $args = [] ) {
		if ( self::theme() !== $theme ) {
			return;
		}
		switch ( $theme ) {
			case 'astra':
				if ( class_exists( '\Astra_Theme_Options' ) && method_exists( '\Astra_Theme_Options', 'refresh' ) ) {
					\Astra_Theme_Options::refresh();
				}
				/** Astra's hook for settings written outside the Customizer, so anything that caches generated assets refreshes. */
				do_action( 'astra_ability_settings_updated', 'royal-mcp/update-theme-design', $args );
				break;
			case 'generatepress':
				if ( function_exists( 'generate_update_dynamic_css_cache' ) ) {
					generate_update_dynamic_css_cache();
				}
				break;
			case 'blocksy':
				if ( function_exists( 'blocksy_manager' ) ) {
					$m = blocksy_manager();
					if ( isset( $m->db ) && is_object( $m->db ) && method_exists( $m->db, 'wipe_cache' ) ) {
						$m->db->wipe_cache();
					}
				}
				do_action( 'blocksy:dynamic-css:refresh-caches' );
				break;
		}
	}

	/**
	 * What landed and what did not, read back from the theme.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	private static function compare( array $after, array $palette, array $typo ) {
		$landed = [];
		$miss   = [];
		$cols   = [];
		foreach ( (array) ( $after['palette'] ?? [] ) as $c ) {
			$cols[ (string) $c['slot'] ] = strtolower( (string) $c['color'] );
		}
		foreach ( $palette as $slot => $hex ) {
			if ( ( $cols[ (string) $slot ] ?? '' ) === strtolower( $hex ) ) {
				$landed[] = 'palette ' . $slot;
			} else {
				$miss[] = 'palette ' . $slot;
			}
		}
		foreach ( $typo as $part => $fields ) {
			foreach ( $fields as $field => $want ) {
				$have = (string) ( $after['typography'][ $part ][ $field ] ?? '' );
				$want = is_array( $want ) ? $want['value'] . $want['unit'] : (string) $want;
				if ( strtolower( $have ) === strtolower( $want ) ) {
					$landed[] = $part . ' ' . $field;
				} else {
					$miss[] = $part . ' ' . $field;
				}
			}
		}
		return [ $landed, $miss ];
	}

	/**
	 * Undo of wp_update_theme_design: puts back exactly the settings that write changed.
	 * Refuses (unless force) when any of them changed after the write, and always while
	 * another theme (or child theme) is active.
	 */
	public static function undo( array $snapshot, $force ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new \Exception( 'edit_theme_options capability required to undo this operation.' );
		}
		if ( ! self::writes_allowed() ) {
			throw new Tool_Refusal( 'theme_writes_disabled', 'Theme writes are disabled. Enable "Allow AI to modify theme appearance" under Royal MCP > Settings to undo this change.' );
		}
		$pre     = is_array( $snapshot['pre_op_state'] ?? null ) ? $snapshot['pre_op_state'] : [];
		$theme   = (string) ( $snapshot['target']['theme'] ?? '' );
		$sheet   = (string) ( $snapshot['target']['stylesheet'] ?? '' );
		$prior   = is_array( $pre['prior'] ?? null ) ? array_values( $pre['prior'] ) : [];
		$written = is_array( $pre['written'] ?? null ) ? array_values( $pre['written'] ) : [];
		if ( ! isset( self::THEMES[ $theme ] ) || ! $prior || count( $prior ) !== count( $written ) ) {
			throw new \Exception( 'This undo record is incomplete.' );
		}
		if ( self::theme() !== $theme || ( '' !== $sheet && (string) get_stylesheet() !== $sheet ) ) {
			throw new \Exception( sprintf( 'The active theme is no longer the one this change was made on (%s); switch back to it to undo this change.', esc_html( '' !== $sheet ? $sheet : self::THEMES[ $theme ] ) ) );
		}
		$specs   = self::specs_of( $prior );
		$allowed = self::units( $theme );
		foreach ( $specs as $i => $spec ) {
			if ( ! in_array( $spec, $allowed, true ) || $spec !== self::specs_of( [ $written[ $i ] ] )[0] ) {
				throw new \Exception( 'This undo record does not match the theme\'s settings.' );
			}
		}
		if ( ! $force && self::read_units( $specs ) != $written ) { // phpcs:ignore Universal.Operators.StrictComparisons -- stored values may come back with numeric strings.
			throw new Tool_Refusal( 'edited_since_write', sprintf( 'The %s palette or fonts were changed after this operation, so undoing it would also remove those later changes. Pass force: true to put the earlier values back anyway.', self::THEMES[ $theme ] ) );
		}
		self::write_units( $prior );
		self::refresh( $theme );
		return [ 'undone' => true, 'op' => 'wp_update_theme_design', 'restored' => true, 'theme' => $theme ];
	}

	/* ------------------------------------------------------------------
	 *  Spectra
	 * ------------------------------------------------------------------ */

	private static function spectra_tool( array $args ) {
		if ( ! self::spectra_active() ) {
			throw new \Exception( 'Spectra is not active on this site.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to read Spectra\'s global styles (manage_options).' );
		}
		$enabled = 'disabled' !== ( class_exists( '\UAGB_Admin_Helper' ) ? (string) \UAGB_Admin_Helper::get_admin_settings_option( 'uag_enable_gbs_extension', 'enabled' ) : 'enabled' );
		$raw     = get_option( 'spectra_global_block_styles', [] );
		$styles  = [];
		foreach ( is_array( $raw ) ? $raw : [] as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$row = [
				'label'      => (string) ( is_scalar( $s['label'] ?? null ) ? $s['label'] : '' ),
				'class'      => (string) ( is_scalar( $s['value'] ?? null ) ? $s['value'] : '' ),
				'block_type' => (string) ( is_scalar( $s['blockName'] ?? null ) ? $s['blockName'] : '' ),
				'used_on'    => isset( $s['post_ids'] ) && is_array( $s['post_ids'] ) ? count( array_unique( array_filter( array_map( 'intval', $s['post_ids'] ) ) ) ) : 0,
			];
			if ( ! empty( $args['include_attributes'] ) && isset( $s['attributes'] ) && is_array( $s['attributes'] ) ) {
				$row['attributes'] = $s['attributes'];
			}
			$styles[] = $row;
			if ( count( $styles ) >= 200 ) {
				break;
			}
		}
		return [ 'enabled' => $enabled, 'count' => count( $styles ), 'styles' => $styles ];
	}

	/* ------------------------------------------------------------------
	 *  Theme helpers
	 * ------------------------------------------------------------------ */

	private static function size_string( $value, $unit ) {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return ( 0.0 === fmod( (float) $value, 1.0 ) ? (string) (int) $value : (string) (float) $value ) . ( is_string( $unit ) && '' !== $unit ? $unit : 'px' );
	}

	private static function astra_settings() {
		$s = self::raw_option( 'astra-settings', [] );
		return is_array( $s ) ? $s : [];
	}

	private static function astra_defaults() {
		if ( class_exists( '\Astra_Theme_Options' ) && method_exists( '\Astra_Theme_Options', 'defaults' ) ) {
			$d = \Astra_Theme_Options::defaults();
			return is_array( $d ) ? $d : [];
		}
		return [];
	}

	private static function astra_labels() {
		if ( class_exists( '\Astra_Global_Palette' ) && method_exists( '\Astra_Global_Palette', 'get_palette_labels' ) ) {
			$l = \Astra_Global_Palette::get_palette_labels();
			if ( is_array( $l ) ) {
				return array_values( $l );
			}
		}
		return self::ASTRA_LABELS;
	}

	/** Custom colour slots the owner removed (kept in the data, not shown by the theme). */
	private static function astra_retired_slots( array $pal ) {
		$out = [];
		foreach ( array_values( (array) ( $pal['customColors'] ?? [] ) ) as $i => $c ) {
			if ( is_array( $c ) && ! empty( $c['retired'] ) ) {
				$out[ 9 + $i ] = true;
			}
		}
		return $out;
	}

	private static function astra_palette_data() {
		$pal = get_option( 'astra-color-palettes', [] );
		if ( ! is_array( $pal ) || empty( $pal['palettes'] ) ) {
			$pal = class_exists( '\Astra_Global_Palette' ) && method_exists( '\Astra_Global_Palette', 'get_default_color_palette' ) ? (array) \Astra_Global_Palette::get_default_color_palette() : [];
			// With no saved palette sets, the colours the theme shows are the ones in its settings.
			$shown = self::astra_settings()['global-color-palette']['palette'] ?? null;
			if ( is_array( $shown ) && $shown ) {
				$cur                               = isset( $pal['currentPalette'] ) && is_string( $pal['currentPalette'] ) ? $pal['currentPalette'] : 'palette_1';
				$pal['palettes'][ $cur ]           = array_values( $shown );
				$pal['currentPalette']             = $cur;
			}
		}
		$pal['palettes']       = isset( $pal['palettes'] ) && is_array( $pal['palettes'] ) ? $pal['palettes'] : [];
		$cur                   = isset( $pal['currentPalette'] ) && is_string( $pal['currentPalette'] ) ? $pal['currentPalette'] : 'palette_1';
		$pal['currentPalette'] = $cur;
		if ( ! isset( $pal['palettes'][ $cur ] ) || ! is_array( $pal['palettes'][ $cur ] ) ) {
			$pal['palettes'][ $cur ] = array_values( (array) ( self::astra_settings()['global-color-palette']['palette'] ?? array_fill( 0, 9, '#000000' ) ) );
		}
		return $pal;
	}

	/** "'Inter', sans-serif" -> "Inter"; system values stay as they are. */
	private static function astra_family_name( $v ) {
		$v = (string) $v;
		if ( preg_match( "/^'([^']+)'\\s*,/", $v, $m ) ) {
			return $m[1];
		}
		return $v;
	}

	private static function gp_settings() {
		$raw = get_option( 'generate_settings', [] );
		$raw = is_array( $raw ) ? $raw : [];
		return function_exists( 'generate_get_defaults' ) ? wp_parse_args( $raw, (array) generate_get_defaults() ) : $raw;
	}

	private static function gp_dynamic( array $s ) {
		return function_exists( 'generate_is_using_dynamic_typography' ) ? (bool) generate_is_using_dynamic_typography() : ! empty( $s['use_dynamic_typography'] );
	}

	private static function gp_colors( array $s ) {
		return array_values( array_filter( (array) ( $s['global_colors'] ?? [] ), 'is_array' ) );
	}

	private static function kadence_palette_data() {
		$raw = get_option( 'kadence_global_palette', '' );
		$k   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $k ) || empty( $k['palette'] ) ) {
			$def = '';
			if ( class_exists( '\Kadence\Options\Component' ) && method_exists( '\Kadence\Options\Component', 'palette_defaults' ) ) {
				$def = (string) \Kadence\Options\Component::palette_defaults();
			}
			$k = json_decode( $def, true );
			$k = is_array( $k ) ? $k : [ 'palette' => [] ];
		}
		$active       = isset( $k['active'] ) && is_string( $k['active'] ) && isset( $k[ $k['active'] ] ) && is_array( $k[ $k['active'] ] ) ? $k['active'] : 'palette';
		$k['active']  = $active;
		$k[ $active ] = array_values( (array) $k[ $active ] );
		return $k;
	}

	private static function kadence_font( $key ) {
		$v = null;
		if ( function_exists( 'Kadence\kadence' ) ) {
			$v = \Kadence\kadence()->option( $key );
		}
		if ( ! is_array( $v ) ) {
			$v = get_theme_mod( $key, [] );
		}
		return is_array( $v ) ? $v : [];
	}

	/** Whether Kadence lists $family as a Google font (it then loads it). */
	private static function kadence_is_google_font( $family ) {
		static $list = null;
		if ( null === $list ) {
			$file = trailingslashit( get_template_directory() ) . 'inc/customizer/google-font-variants.php';
			$list = is_readable( $file ) ? include $file : [];
			$list = is_array( $list ) ? $list : [];
		}
		return isset( $list[ $family ] );
	}

	private static function blocksy_palette() {
		$p = get_theme_mod( 'colorPalette', null );
		if ( ! is_array( $p ) ) {
			$p = [];
			foreach ( self::BLOCKSY_DEFAULTS as $i => $hex ) {
				$p[ 'color' . ( $i + 1 ) ] = [ 'color' => $hex ];
			}
		}
		return $p;
	}

	private static function blocksy_font( $key, $level ) {
		$v = get_theme_mod( $key, null );
		if ( is_array( $v ) ) {
			return $v;
		}
		$base = 0 === $level
			? [ 'family' => 'System Default', 'variation' => 'n4', 'size' => '16px', 'line-height' => '1.65', 'letter-spacing' => '0em', 'text-transform' => 'none', 'text-decoration' => 'none' ]
			: [ 'size' => self::BLOCKSY_HEADING_SIZES[ $level ], 'variation' => 'n7', 'line-height' => '1.5' ];
		return function_exists( 'blocksy_typography_default_values' ) ? (array) blocksy_typography_default_values( $base ) : $base;
	}

	private static function blocksy_weight( $variation ) {
		return preg_match( '/^[ni]([1-9])$/', (string) $variation, $m ) ? $m[1] . '00' : '';
	}

	private static function blocksy_size( $size ) {
		if ( is_array( $size ) ) {
			$size = $size['desktop'] ?? '';
		}
		return is_string( $size ) ? $size : '';
	}
}
