<?php
/**
 * Design token files: export the tokens as W3C Design Tokens (DTCG 2025.10)
 * files and import such files with a preview first.
 *
 * Two files: tokenforge.tokens.json (every token, light values) and
 * tokenforge.dark.tokens.json (only the colours that differ in dark mode).
 * Groups are one level deep with the full TokenForge name as the key
 * (color.primary, typography.text-m), so a round trip is exact. Fluid sizes
 * go out as their desktop size plus min, max and viewports in
 * $extensions.com.tokenforge.fluid; colours as sRGB plus the stored CSS in
 * $extensions.com.tokenforge.css.
 *
 * An imported file is untrusted: nothing from it reaches the stylesheet as
 * written. Every value is read as the type it claims and the CSS is rebuilt
 * from the parsed numbers; the two extension strings are only used when they
 * pass a strict grammar and say the same as the standard value. Changed and
 * new tokens are stored as tokens (a stored token wins over what the settings
 * make), after a history snapshot.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Token file export, preview and import.
 */
class Token_Files {

	const MAX_BYTES  = 2097152; // 2 MB per file
	const MAX_DEPTH  = 12;      // group nesting
	const MAX_TOKENS = 5000;
	const EXT        = 'com.tokenforge';
	const LIGHT      = 'tokenforge.tokens.json';
	const DARK       = 'tokenforge.dark.tokens.json';

	/** One colour in the strict grammar the css extension and colour strings must pass. */
	const ATOM = '/^(?:#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\((?:[0-9.\s,\/%+-]|deg|none)+\)|var\(--[a-z0-9-]+\)|white|black|transparent)$/';

	/** Colour spaces the format allows: CSS function, or '' for color(<space> ...). */
	const SPACES = array(
		'srgb'         => 'rgb',
		'hsl'          => 'hsl',
		'hwb'          => 'hwb',
		'lab'          => 'lab',
		'lch'          => 'lch',
		'oklab'        => 'oklab',
		'oklch'        => 'oklch',
		'srgb-linear'  => '',
		'display-p3'   => '',
		'a98-rgb'      => '',
		'prophoto-rgb' => '',
		'rec2020'      => '',
		'xyz-d65'      => '',
		'xyz-d50'      => '',
	);

	/** Token types read on import, with the Tokens Studio names they also accept. */
	const TYPES = array(
		'color'        => 'color',
		'dimension'    => 'dimension',
		'spacing'      => 'dimension',
		'sizing'       => 'dimension',
		'borderRadius' => 'dimension',
		'borderWidth'  => 'dimension',
		'fontSizes'    => 'dimension',
		'fontFamily'   => 'fontFamily',
		'fontFamilies' => 'fontFamily',
		'fontWeight'   => 'fontWeight',
		'fontWeights'  => 'fontWeight',
		'shadow'       => 'shadow',
		'boxShadow'    => 'shadow',
	);

	const WEIGHTS = array(
		'thin'        => 100,
		'hairline'    => 100,
		'extra-light' => 200,
		'ultra-light' => 200,
		'light'       => 300,
		'normal'      => 400,
		'regular'     => 400,
		'book'        => 400,
		'medium'      => 500,
		'semi-bold'   => 600,
		'demi-bold'   => 600,
		'bold'        => 700,
		'extra-bold'  => 800,
		'ultra-bold'  => 800,
		'black'       => 900,
		'heavy'       => 900,
		'extra-black' => 950,
		'ultra-black' => 950,
	);

	const GENERIC_FONTS = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong' );

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Both files, built now (nothing is stored).
	 *
	 * @return array { light, dark, files: [ light name, dark name ], skipped: [ [ name, why ] ], count }
	 */
	public static function export() {
		$vars  = Contrast::variables();
		$list  = array();
		$skip  = array();
		$alias = array();
		foreach ( Settings::get_instance()->all_variables() as $t ) {
			if ( empty( $t['enabled'] ) ) {
				continue;
			}
			if ( preg_match( '/^var\(\s*--([a-z0-9-]+)\s*\)$/', $t['value'], $m ) ) {
				$alias[ $t['name'] ] = array( $t, $m[1] );
				continue;
			}
			$why = '';
			$tok = self::to_token( $t, $vars, $why );
			if ( null === $tok ) {
				$skip[] = array( $t['name'], $why );
				continue;
			}
			$list[ $t['name'] ] = array( $t['group'], $tok );
		}
		// Aliases point at another exported token (chains resolve in a few passes).
		for ( $pass = 0; $pass < 10 && $alias; $pass++ ) {
			$more = false;
			foreach ( $alias as $name => $a ) {
				if ( isset( $list[ $a[1] ] ) ) {
					$to            = $list[ $a[1] ];
					$list[ $name ] = array(
						$a[0]['group'],
						array(
							'$type'  => $to[1]['$type'],
							'$value' => '{' . self::ref_group( $to[0] ) . '.' . $a[1] . '}',
						),
					);
					unset( $alias[ $name ] );
					$more = true;
				}
			}
			if ( ! $more ) {
				break;
			}
		}
		foreach ( $alias as $name => $a ) {
			$skip[] = array( $name, 'points at a variable that has no token type' );
		}
		// Shadow colours that are variables become references to the exported colour.
		$light = array();
		$dark  = array();
		foreach ( $list as $name => $row ) {
			list( $group, $tok ) = $row;
			$g = self::ref_group( $group );
			if ( isset( $tok['dark'] ) ) {
				$dark[ $g ][ $name ] = $tok['dark'];
				unset( $tok['dark'] );
			}
			if ( 'shadow' === $tok['$type'] && is_array( $tok['$value'] ) ) {
				$tok['$value'] = self::shadow_refs( $tok['$value'], $list );
			}
			if ( $g !== $group || ! in_array( $group, Tokens::GROUPS, true ) ) {
				$tok['$extensions'][ self::EXT ]['group'] = $group;
			}
			$light[ $g ][ $name ] = $tok;
		}
		return array(
			'light'   => $light ? $light : new \stdClass(),
			'dark'    => $dark ? $dark : new \stdClass(),
			'files'   => array( self::LIGHT, self::DARK ),
			'skipped' => $skip,
			'count'   => count( $list ),
		);
	}

	/**
	 * A group name as a DTCG group key (never one that starts with $).
	 *
	 * @param string $group TokenForge group.
	 * @return string
	 */
	private static function ref_group( $group ) {
		$group = Tokens::sanitize_group( $group );
		return '' === $group ? 'other' : $group;
	}

	/**
	 * One TokenForge token as a DTCG token, or null with the reason.
	 *
	 * @param array  $t    Token.
	 * @param array  $vars Variables, name => value.
	 * @param string $why  Why it was left out.
	 * @return array|null
	 */
	private static function to_token( $t, $vars, &$why ) {
		$name = $t['name'];
		$v    = trim( (string) $t['value'] );
		if ( 'color' === $t['group'] && preg_match( '/-(hex|hsl|rgb|alt|h|s|l|r|g|b)$/', $name ) ) {
			$why = 'colour channel or dark copy, made from the colour itself';
			return null;
		}
		$fluid = self::clamp_parts( $v );
		if ( $fluid ) {
			return array(
				'$type'       => 'dimension',
				'$value'      => self::dim_obj( $fluid['max'], 'px' ),
				'$extensions' => array(
					self::EXT => array(
						'fluid' => array(
							'min'         => self::dim_obj( $fluid['min'], 'px' ),
							'max'         => self::dim_obj( $fluid['max'], 'px' ),
							'minViewport' => self::dim_obj( $fluid['min_vw'], 'px' ),
							'maxViewport' => self::dim_obj( $fluid['max_vw'], 'px' ),
							'clamp'       => $v,
						),
					),
				),
			);
		}
		if ( preg_match( '/^(-?\d*\.?\d+)(px|rem)?$/', $v, $m ) && ( isset( $m[2] ) || 0.0 === (float) $m[1] ) ) {
			return array(
				'$type'  => 'dimension',
				'$value' => self::dim_obj( (float) $m[1], isset( $m[2] ) ? $m[2] : 'px' ),
			);
		}
		if ( false !== strpos( $name, 'weight' ) ) {
			$w = strtolower( $v );
			$w = isset( self::WEIGHTS[ $w ] ) ? self::WEIGHTS[ $w ] : ( ctype_digit( $w ) ? (int) $w : 0 );
			if ( $w >= 1 && $w <= 1000 ) {
				return array( '$type' => 'fontWeight', '$value' => $w );
			}
		}
		if ( preg_match( '/font|family/', $name ) && ! preg_match( '/size|weight|style/', $name ) ) {
			$fam = self::font_list( $v );
			if ( $fam ) {
				return array( '$type' => 'fontFamily', '$value' => 1 === count( $fam ) ? $fam[0] : $fam );
			}
		}
		$layers = self::shadow_layers( $v );
		if ( $layers ) {
			$out = array();
			foreach ( $layers as $l ) {
				$c = Contrast::parse( $l['color'], $vars );
				if ( null === $c ) {
					$why = 'shadow colour is not a plain colour';
					return null;
				}
				$out[] = array(
					'color'   => preg_match( '/^var\(--([a-z0-9-]+)\)$/', $l['color'], $cm ) ? array( 'ref' => $cm[1], 'obj' => self::color_obj( $c ) ) : self::color_obj( $c ),
					'offsetX' => self::dim_obj( $l['d'][0], 'px' ),
					'offsetY' => self::dim_obj( $l['d'][1], 'px' ),
					'blur'    => self::dim_obj( $l['d'][2], 'px' ),
					'spread'  => self::dim_obj( $l['d'][3], 'px' ),
					'inset'   => $l['inset'],
				);
			}
			return array( '$type' => 'shadow', '$value' => 1 === count( $out ) ? $out[0] : $out );
		}
		$c = Contrast::parse( $v, $vars );
		if ( null !== $c ) {
			$tok = array( '$type' => 'color', '$value' => self::color_obj( $c ) );
			if ( strtolower( $v ) !== $tok['$value']['hex'] ) {
				$tok['$extensions'][ self::EXT ]['css'] = $v;
			}
			$d = Contrast::parse( $v, $vars, true );
			if ( null !== $d && ! self::same_rgba( $c, $d ) ) {
				$tok['dark'] = array( '$type' => 'color', '$value' => self::color_obj( $d ) );
				$ld          = Ai::light_dark( $v );
				if ( $ld ) {
					$tok['dark']['$extensions'][ self::EXT ]['css'] = $ld[1];
				}
			}
			return $tok;
		}
		$why = 'no token type for this value (only colours, sizes, font families, weights and shadows go out)';
		return null;
	}

	/**
	 * Shadow layers with a colour variable that is exported: a reference to it.
	 *
	 * @param array $value Shadow value (one layer or a list).
	 * @param array $list  Exported tokens, name => [ group, token ].
	 * @return array
	 */
	private static function shadow_refs( $value, $list ) {
		$one    = isset( $value['color'] );
		$layers = $one ? array( $value ) : $value;
		foreach ( $layers as $i => $l ) {
			if ( isset( $l['color']['ref'] ) ) {
				$r                     = $l['color']['ref'];
				$layers[ $i ]['color'] =isset( $list[ $r ] ) && 'color' === $list[ $r ][1]['$type'] ? '{' . self::ref_group( $list[ $r ][0] ) . '.' . $r . '}' : $l['color']['obj'];
			}
		}
		return $one ? $layers[0] : $layers;
	}

	/**
	 * clamp(lo, B + Cvw, hi) as sizes at two viewports (px), or null.
	 *
	 * @param string $v CSS value.
	 * @return array|null { min, max, min_vw, max_vw } (min = size at the small viewport).
	 */
	private static function clamp_parts( $v ) {
		$n = '(-?\d*\.?\d+)(rem|px)';
		if ( ! preg_match( '/^clamp\(\s*' . $n . '\s*,\s*(?:calc\()?\s*' . $n . '\s*\+\s*(-?\d*\.?\d+)vw\s*\)?\s*,\s*' . $n . '\s*\)$/', $v, $m ) ) {
			return null;
		}
		$r  = Defaults::root_px();
		$px = function ( $num, $unit ) use ( $r ) {
			return 'rem' === $unit ? (float) $num * $r : (float) $num;
		};
		$lo = $px( $m[1], $m[2] );
		$b  = $px( $m[3], $m[4] );
		$c  = (float) $m[5];
		$hi = $px( $m[6], $m[7] );
		if ( 0.0 === $c || $lo >= $hi ) {
			return null;
		}
		// size(W) = b + c * W / 100 → the viewport where each end is reached.
		$at  = function ( $size ) use ( $b, $c ) {
			return round( ( $size - $b ) * 100 / $c, 2 );
		};
		$out = $c > 0
			? array( 'min' => $lo, 'max' => $hi, 'min_vw' => $at( $lo ), 'max_vw' => $at( $hi ) )
			: array( 'min' => $hi, 'max' => $lo, 'min_vw' => $at( $hi ), 'max_vw' => $at( $lo ) );
		return $out['min_vw'] >= 0 && $out['min_vw'] < $out['max_vw'] ? $out : null;
	}

	/**
	 * A DTCG dimension.
	 *
	 * @param float  $v    Number.
	 * @param string $unit px or rem.
	 * @return array
	 */
	private static function dim_obj( $v, $unit ) {
		return array( 'value' => round( (float) $v, 4 ) + 0, 'unit' => $unit );
	}

	/**
	 * A DTCG sRGB colour from [ r, g, b, a ] (0-1).
	 *
	 * @param array $c Colour.
	 * @return array
	 */
	private static function color_obj( $c ) {
		$out = array(
			'colorSpace' => 'srgb',
			'components' => array( round( $c[0], 6 ) + 0, round( $c[1], 6 ) + 0, round( $c[2], 6 ) + 0 ),
		);
		if ( $c[3] < 1 ) {
			$out['alpha'] = round( $c[3], 4 ) + 0;
		}
		$out['hex'] = sprintf( '#%02x%02x%02x', round( $c[0] * 255 ), round( $c[1] * 255 ), round( $c[2] * 255 ) );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * What importing these files would do. Nothing is written.
	 *
	 * @param string|null $light Text of the tokens file (or null).
	 * @param string|null $dark  Text of the dark file (or null).
	 * @return array { error, rows: [ { name, status, old, new, note } ], counts, save: name => [ value, group ] }
	 */
	public static function plan( $light, $dark ) {
		$fail = function ( $msg ) {
			return array( 'error' => $msg, 'rows' => array(), 'counts' => array(), 'save' => array() );
		};
		$files = array();
		foreach ( array( 'light' => $light, 'dark' => $dark ) as $which => $text ) {
			if ( null === $text || '' === $text ) {
				continue;
			}
			$label = 'light' === $which ? 'The tokens file' : 'The dark file';
			$doc   = self::decode( (string) $text, $err );
			if ( null === $doc ) {
				return $fail( $label . ': ' . $err );
			}
			$found = self::collect( $doc, $err );
			if ( null === $found ) {
				return $fail( $label . ': ' . $err );
			}
			$files[ $which ] = $found;
		}
		if ( ! $files ) {
			return $fail( 'Choose a tokens file first.' );
		}

		$cur = array();
		foreach ( Settings::get_instance()->all_variables() as $t ) {
			$cur[ $t['name'] ] = $t;
		}
		// Palette colours not overridden yet: a stored copy would lose its shades and be dropped on the next palette edit.
		$palette = array_diff_key( array_column( Palette::tokens(), 'name', 'name' ), array_column( Settings::get_instance()->get_tokens(), 'name', 'name' ) );
		$vars  = Contrast::variables();
		$rows  = array();
		$save  = array();
		$skip  = function ( $name, $why, $file ) use ( &$rows ) {
			$rows[] = array( 'name' => $name, 'status' => 'skipped', 'old' => '', 'new' => '', 'note' => ( 'dark' === $file ? 'Dark file: ' : '' ) . $why );
		};

		// Values from each file: name => [ css, type, group, note, dark (from the css extension) ].
		$got = array();
		foreach ( $files as $which => $found ) {
			// The dark file may refer to tokens of the tokens file.
			$light_file = 'dark' === $which && isset( $files['light'] ) ? $files['light'] : array( 'names' => array(), 'tokens' => array() );
			$names      = $found['names'] + $light_file['names'];
			$all        = $found['tokens'] + $light_file['tokens'];
			foreach ( $found['skipped'] as $s ) {
				$skip( $s[0], $s[1], $which );
			}
			foreach ( $found['tokens'] as $name => $tok ) {
				$why = '';
				$val = self::value_css( $tok, $all, $names, $cur, $vars, $why );
				if ( null === $val ) {
					$skip( $tok['path'], $why, $which );
					continue;
				}
				if ( 'alias' === $val['type'] ) { // an untyped reference takes its target's type
					$to = substr( $val['css'], 6, -1 );
					$tt = isset( $all[ $to ] ) ? $all[ $to ]['type'] : '';
					if ( ( isset( self::TYPES[ $tt ] ) && 'color' === self::TYPES[ $tt ] ) || ( '' === $tt && isset( $cur[ $to ] ) && 'color' === $cur[ $to ]['group'] ) ) {
						$val['type'] = 'color';
					}
				}
				if ( 'dark' === $which && 'color' !== $val['type'] ) {
					$skip( $tok['path'], 'only colours can have a dark value', $which );
					continue;
				}
				if ( ! self::safe( $val['css'] ) || ( '' !== $val['dark'] && ! self::safe( $val['dark'] ) ) ) {
					$skip( $tok['path'], 'value has characters a token cannot hold', $which );
					continue;
				}
				$got[ $which ][ $name ] = $val + array( 'group' => $tok['group'] );
			}
		}
		$light_got = isset( $got['light'] ) ? $got['light'] : array();
		$dark_got  = isset( $got['dark'] ) ? $got['dark'] : array();
		foreach ( array_keys( $dark_got ) as $name ) {
			if ( ! isset( $light_got[ $name ] ) && ! isset( $cur[ $name ] ) ) {
				$skip( $name, 'no colour with this name in the tokens file or on this site', 'dark' );
				unset( $dark_got[ $name ] );
			}
		}

		foreach ( array_unique( array_merge( array_keys( $light_got ), array_keys( $dark_got ) ) ) as $name ) {
			$old   = isset( $cur[ $name ] ) ? (string) $cur[ $name ]['value'] : null;
			$old_l = $old;
			$old_d = null;
			$ld    = null === $old ? null : Ai::light_dark( $old );
			if ( $ld ) {
				list( $old_l, $old_d ) = $ld;
			}
			$in   = isset( $light_got[ $name ] ) ? $light_got[ $name ] : null;
			$note = $in ? $in['note'] : '';
			if ( $in && ( 'color' !== $in['type'] || ( 0 === strpos( $in['css'], 'var(' ) && ! isset( $dark_got[ $name ] ) ) ) ) {
				$new = $in['css']; // a reference follows its target in both modes
			} else {
				if ( $in ) {
					$l = $in['css'];
				} elseif ( null !== $old_l && Contrast::parse( $old, $vars ) ) {
					$l = $old_l;
				} else {
					$skip( $name, 'no colour with this name in the tokens file or on this site', 'dark' );
					continue;
				}
				if ( isset( $dark_got[ $name ] ) ) {
					$d = $dark_got[ $name ]['css'];
				} elseif ( $in && '' !== $in['dark'] ) {
					$d = $in['dark'];
				} elseif ( null !== $old_d ) {
					$d = $old_d;
					if ( ! self::same( $l, $old_l, $vars ) ) {
						$note = trim( $note . ' Dark value kept from this site.' );
					}
				} else {
					$d = '';
				}
				$new = '' === $d || $d === $l ? $l : 'light-dark(' . $l . ', ' . $d . ')';
			}
			$group = $in && '' !== $in['group'] ? $in['group'] : ( isset( $cur[ $name ] ) ? $cur[ $name ]['group'] : '' );
			if ( null === $old ) {
				$status = 'new';
			} elseif ( self::same( $new, $old, $vars ) ) {
				$status = 'unchanged';
			} else {
				$status = 'changed';
			}
			if ( 'unchanged' !== $status && isset( $palette[ $name ] ) ) {
				$skip( $name, 'a palette colour: change it under Colors, so its shades follow', 'light' );
				continue;
			}
			$rows[] = array( 'name' => $name, 'status' => $status, 'old' => (string) $old, 'new' => $new, 'note' => $note );
			if ( 'unchanged' !== $status ) {
				$save[ $name ] = array( $new, '' !== $group ? $group : ( $in ? self::group_for( $in['type'], $name ) : 'color' ) );
			}
		}

		$counts = array( 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'skipped' => 0 );
		foreach ( $rows as $r ) {
			$counts[ $r['status'] ]++;
		}
		return array( 'error' => '', 'rows' => $rows, 'counts' => $counts, 'save' => $save );
	}

	/**
	 * Import: plan, snapshot, store the new and changed tokens.
	 *
	 * @param string|null $light Text of the tokens file.
	 * @param string|null $dark  Text of the dark file.
	 * @return array The plan (with 'saved' => count when it was applied).
	 */
	public static function import( $light, $dark ) {
		$plan = self::plan( $light, $dark );
		if ( '' !== $plan['error'] ) {
			return $plan;
		}
		$plan['saved'] = count( $plan['save'] );
		if ( ! $plan['save'] ) {
			return $plan;
		}
		History::snapshot( 'import' );
		$stored = Settings::get_instance()->get_tokens();
		$at     = array();
		foreach ( $stored as $i => $t ) {
			$at[ $t['name'] ] = $i;
		}
		foreach ( $plan['save'] as $name => $s ) {
			if ( isset( $at[ $name ] ) ) {
				$stored[ $at[ $name ] ]['value']   = $s[0];
				$stored[ $at[ $name ] ]['enabled'] = true;
			} else {
				$stored[] = array( 'name' => $name, 'value' => $s[0], 'group' => $s[1], 'enabled' => true );
			}
		}
		Settings::get_instance()->save_tokens( $stored );
		return $plan;
	}

	/**
	 * Decode one file: size first, then UTF-8, then JSON, top level an object.
	 *
	 * @param string $text File text.
	 * @param string $err  The problem.
	 * @return array|null
	 */
	private static function decode( $text, &$err ) {
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$err = 'larger than 2 MB, so it was not read.';
			return null;
		}
		if ( ! preg_match( '//u', $text ) ) {
			$err = 'not UTF-8 text.';
			return null;
		}
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$doc  = json_decode( $text, true, 2 * self::MAX_DEPTH + 8 );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			$err = 'nested too deeply.';
			return null;
		}
		if ( ! is_array( $doc ) || JSON_ERROR_NONE !== json_last_error() ) {
			$err = 'not a JSON file' . ( JSON_ERROR_NONE !== json_last_error() ? ' (' . json_last_error_msg() . ').' : '.' );
			return null;
		}
		if ( ! $doc || array_keys( $doc ) === range( 0, count( $doc ) - 1 ) ) {
			$err = 'the file must hold an object of token groups.';
			return null;
		}
		return $doc;
	}

	/**
	 * Tokens in a decoded file: name => { path, type, value, ext, group }, plus
	 * the path → name map for references and the tokens left out.
	 *
	 * @param array  $doc Decoded file.
	 * @param string $err The problem with the whole file.
	 * @return array|null { tokens, names, skipped }
	 */
	private static function collect( $doc, &$err ) {
		// Tokens Studio: one set in an envelope is fine; several are not.
		$order = isset( $doc['$metadata']['tokenSetOrder'] ) && is_array( $doc['$metadata']['tokenSetOrder'] ) ? array_values( $doc['$metadata']['tokenSetOrder'] ) : array();
		if ( count( $order ) > 1 || ( isset( $doc['$themes'] ) && ! empty( $doc['$themes'] ) ) ) {
			$err = 'this file holds several Tokens Studio sets. Export a single set and import that.';
			return null;
		}
		if ( 1 === count( $order ) && is_string( $order[0] ) && isset( $doc[ $order[0] ] ) && is_array( $doc[ $order[0] ] ) ) {
			$doc = $doc[ $order[0] ];
		}
		// DTCG ($value) or the Tokens Studio legacy format (value + type).
		$json   = (string) wp_json_encode( $doc );
		$legacy = false === strpos( $json, '"$value"' );
		if ( $legacy && ! preg_match( '/"value"\s*:/', $json ) ) {
			$err = 'no design tokens found (no $value anywhere).';
			return null;
		}
		$found = array();
		$skip  = array();
		$err   = '';
		self::walk( $doc, array(), '', 1, $legacy, $found, $skip, $err );
		if ( '' !== $err ) {
			return null;
		}
		if ( count( $found ) > self::MAX_TOKENS ) {
			$err = 'more than ' . self::MAX_TOKENS . ' tokens.';
			return null;
		}
		$tokens = array();
		$names  = array();
		foreach ( $found as $f ) {
			$label = implode( '.', $f['path'] );
			$map   = self::name_for( $f['path'], $f['raw'] );
			if ( null === $map ) {
				$skip[] = array( $label, 'name has characters a CSS variable cannot use (letters, digits and hyphens only)' );
				continue;
			}
			list( $name, $group ) = $map;
			if ( isset( $tokens[ $name ] ) ) {
				$skip[] = array( $label, 'same name as another token in the file (' . $name . ')' );
				continue;
			}
			$names[ $label ] = $name;
			$tokens[ $name ] = array(
				'path'  => $label,
				'type'  => $f['type'],
				'value' => $legacy ? $f['raw']['value'] : $f['raw']['$value'],
				'ext'   => isset( $f['raw']['$extensions'][ self::EXT ] ) && is_array( $f['raw']['$extensions'][ self::EXT ] ) ? $f['raw']['$extensions'][ self::EXT ] : array(),
				'group' => $group,
			);
		}
		return array( 'tokens' => $tokens, 'names' => $names, 'skipped' => $skip );
	}

	/**
	 * Walk groups, gathering tokens with their (inherited) type.
	 *
	 * @param array  $node   Group.
	 * @param array  $path   Its path.
	 * @param string $type   Inherited $type.
	 * @param int    $depth  Nesting level.
	 * @param bool   $legacy Tokens Studio legacy format.
	 * @param array  $found  Tokens found.
	 * @param array  $skip   Left out: [ path, why ].
	 * @param string $err    Set when the file is too deep.
	 * @return void
	 */
	private static function walk( $node, $path, $type, $depth, $legacy, &$found, &$skip, &$err ) {
		if ( $depth > self::MAX_DEPTH ) {
			$err = 'groups are nested more than ' . self::MAX_DEPTH . ' levels deep.';
			return;
		}
		if ( ! $legacy && isset( $node['$type'] ) && is_string( $node['$type'] ) ) {
			$type = $node['$type'];
		}
		foreach ( $node as $key => $child ) {
			$key = (string) $key;
			if ( ! is_array( $child ) || count( $found ) > self::MAX_TOKENS || '' !== $err ) {
				continue;
			}
			$here = '$root' === $key ? $path : array_merge( $path, array( $key ) );
			if ( '$' === substr( $key, 0, 1 ) && '$root' !== $key ) {
				continue; // $extensions, $themes … (unknown $ keys are ignored)
			}
			$is_token = $legacy ? array_key_exists( 'value', $child ) && ( ! is_array( $child['value'] ) || isset( $child['type'] ) ) : array_key_exists( '$value', $child );
			if ( ! $is_token ) {
				if ( '$root' !== $key ) {
					self::walk( $child, $here, $type, $depth + 1, $legacy, $found, $skip, $err );
				}
				continue;
			}
			if ( ! $here ) {
				continue;
			}
			if ( ! $legacy ) {
				foreach ( $child as $k => $x ) {
					if ( '$' !== substr( (string) $k, 0, 1 ) && is_array( $x ) ) {
						$skip[] = array( implode( '.', $here ), 'a token cannot also be a group' );
						continue 2;
					}
				}
			}
			$own     = $legacy ? ( isset( $child['type'] ) && is_string( $child['type'] ) ? $child['type'] : '' ) : ( isset( $child['$type'] ) && is_string( $child['$type'] ) ? $child['$type'] : $type );
			$found[] = array( 'path' => $here, 'type' => $own, 'raw' => $child );
		}
	}

	/**
	 * The TokenForge name and group for a token path: a first level that is a
	 * TokenForge group (or the group the file names) is the group, the rest
	 * joined with hyphens is the name.
	 *
	 * @param array $path Path segments.
	 * @param array $raw  The token.
	 * @return array|null [ name, group ] or null when the name is not usable.
	 */
	private static function name_for( $path, $raw ) {
		foreach ( $path as $seg ) {
			if ( '' === $seg || preg_match( '/[{}.]/', $seg ) || '$' === $seg[0] ) {
				return null;
			}
		}
		$tagged = isset( $raw['$extensions'][ self::EXT ]['group'] ) && is_string( $raw['$extensions'][ self::EXT ]['group'] ) ? $raw['$extensions'][ self::EXT ]['group'] : '';
		$group  = '';
		if ( count( $path ) > 1 && ( in_array( strtolower( $path[0] ), Tokens::GROUPS, true ) || ( '' !== $tagged && self::ref_group( $tagged ) === $path[0] ) ) ) {
			$group = '' !== $tagged ? Tokens::sanitize_group( $tagged ) : strtolower( $path[0] );
			$path  = array_slice( $path, 1 );
		}
		$name = strtolower( implode( '-', $path ) );
		return preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) && strlen( $name ) <= 100 ? array( $name, $group ) : null;
	}

	/**
	 * A token's value as CSS, built from what it parses to.
	 *
	 * @param array  $tok   Token from collect().
	 * @param array  $all   Every token of the file, name => token.
	 * @param array  $names Path → name of every token in the file.
	 * @param array  $cur   This site's tokens, name => token.
	 * @param array  $vars  Variables for colour parsing.
	 * @param string $why   Why it was left out.
	 * @return array|null { css, type, note, dark }
	 */
	private static function value_css( $tok, $all, $names, $cur, $vars, &$why ) {
		$v = $tok['value'];
		if ( is_array( $v ) && isset( $v['$ref'] ) ) {
			$why = 'property references ($ref) are not supported';
			return null;
		}
		// A reference to another token: var(--name).
		if ( is_string( $v ) && preg_match( '/^\{([^{}]+)\}$/', trim( $v ), $m ) ) {
			$to = self::ref_name( $m[1], $names, $cur );
			if ( null === $to ) {
				$why = 'refers to ' . $m[1] . ', which is not in the file or on this site';
				return null;
			}
			// A chain inside the file must end somewhere.
			$seen = array( $to => true );
			$next = $to;
			for ( $i = 0; $i < 10; $i++ ) {
				$tv = isset( $all[ $next ] ) ? $all[ $next ]['value'] : null;
				if ( ! is_string( $tv ) || ! preg_match( '/^\{([^{}]+)\}$/', trim( $tv ), $mm ) ) {
					break;
				}
				$next = self::ref_name( $mm[1], $names, $cur );
				if ( null === $next || isset( $seen[ $next ] ) ) {
					$why = 'circular reference';
					return null;
				}
				$seen[ $next ] = true;
			}
			if ( 10 === $i ) {
				$why = 'reference chain longer than 10';
				return null;
			}
			$type = isset( self::TYPES[ $tok['type'] ] ) ? self::TYPES[ $tok['type'] ] : '';
			return array( 'css' => 'var(--' . $to . ')', 'type' => '' !== $type ? $type : 'alias', 'note' => '', 'dark' => '' );
		}
		if ( '' === $tok['type'] ) {
			$why = 'no $type (on the token or a group above it)';
			return null;
		}
		if ( ! isset( self::TYPES[ $tok['type'] ] ) ) {
			$why = 'type ' . $tok['type'] . ' is not imported';
			return null;
		}
		$type = self::TYPES[ $tok['type'] ];
		$note = '';
		$dark = '';
		switch ( $type ) {
			case 'color':
				$css = self::color_css( $v, $names, $cur, $why );
				if ( null === $css ) {
					return null;
				}
				// The stored CSS rides along when it says the same as the standard value.
				$ext = isset( $tok['ext']['css'] ) && is_string( $tok['ext']['css'] ) ? self::color_ext( $tok['ext']['css'] ) : null;
				if ( $ext ) {
					$a = Contrast::parse( $ext[0], $vars );
					$b = Contrast::parse( $css, $vars );
					if ( $a && $b && self::same_rgba( $a, $b ) ) {
						$css  = $ext[0];
						$dark = $ext[1];
					}
				}
				break;
			case 'dimension':
				$px = self::dim_px( $v );
				if ( null === $px ) {
					$why = 'not a dimension (a number with px or rem)';
					return null;
				}
				$css   = self::n( $px[0] ) . $px[1];
				$fluid = isset( $tok['ext']['fluid'] ) && is_array( $tok['ext']['fluid'] ) ? $tok['ext']['fluid'] : null;
				if ( $fluid ) {
					$f = self::fluid_css( $fluid, $px, $why );
					if ( null === $f && '' !== $why ) {
						return null;
					}
					if ( null === $f ) {
						$note = 'Fixed size: the fluid range in the file does not end at this value.';
					} else {
						$css = $f;
					}
				}
				break;
			case 'fontFamily':
				$list = is_string( $v ) ? array( $v ) : ( is_array( $v ) && $v && array_keys( $v ) === range( 0, count( $v ) - 1 ) ? $v : array() );
				$out  = array();
				foreach ( $list as $f ) {
					$f = is_string( $f ) ? self::font_list( $f ) : null;
					if ( ! $f ) {
						$why = 'not a font family name';
						return null;
					}
					$out = array_merge( $out, $f );
				}
				if ( ! $out ) {
					$why = 'not a font family name';
					return null;
				}
				$css = implode(
					', ',
					array_map(
						function ( $f ) {
							return in_array( strtolower( $f ), self::GENERIC_FONTS, true ) ? strtolower( $f ) : '"' . $f . '"';
						},
						$out
					)
				);
				break;
			case 'fontWeight':
				$w = is_string( $v ) && isset( self::WEIGHTS[ $v ] ) ? self::WEIGHTS[ $v ] : ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ? (int) $v : 0 );
				if ( $w < 1 || $w > 1000 ) {
					$why = 'not a font weight (1 to 1000)';
					return null;
				}
				$css = (string) $w;
				break;
			default: // shadow.
				$css = self::shadow_css( $v, $names, $cur, $why );
				if ( null === $css ) {
					return null;
				}
		}
		return array( 'css' => $css, 'type' => $type, 'note' => $note, 'dark' => $dark );
	}

	/**
	 * A reference path ("color.primary") as a token name in the file or on the site.
	 *
	 * @param string $path  Path.
	 * @param array  $names Path → name of the file's tokens.
	 * @param array  $cur   This site's tokens.
	 * @return string|null
	 */
	private static function ref_name( $path, $names, $cur ) {
		if ( isset( $names[ $path ] ) ) {
			return $names[ $path ];
		}
		$map = self::name_for( explode( '.', $path ), array() );
		return $map && isset( $cur[ $map[0] ] ) ? $map[0] : null;
	}

	/**
	 * A colour value (DTCG object, a colour string, or a reference) as CSS.
	 *
	 * @param mixed  $v     Value.
	 * @param array  $names Path → name.
	 * @param array  $cur   This site's tokens.
	 * @param string $why   The problem.
	 * @return string|null
	 */
	private static function color_css( $v, $names, $cur, &$why ) {
		$why = 'not a valid colour';
		if ( is_string( $v ) ) {
			$s = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $v ) ) );
			if ( preg_match( '/^\{([^{}]+)\}$/', $s, $m ) ) {
				$to = self::ref_name( $m[1], $names, $cur );
				return null === $to ? null : 'var(--' . $to . ')';
			}
			return preg_match( self::ATOM, $s ) && 0 !== strpos( $s, 'var(' ) ? $s : null;
		}
		if ( ! is_array( $v ) || ! isset( $v['colorSpace'], $v['components'] ) || ! is_string( $v['colorSpace'] ) || ! is_array( $v['components'] ) ) {
			return null;
		}
		$space = $v['colorSpace'];
		$c     = array_values( $v['components'] );
		if ( ! isset( self::SPACES[ $space ] ) || 3 !== count( $c ) ) {
			$why = 'colour space or components not valid';
			return null;
		}
		foreach ( $c as $x ) {
			if ( 'none' !== $x && ! self::num( $x ) ) {
				return null;
			}
		}
		$alpha = array_key_exists( 'alpha', $v ) ? $v['alpha'] : 1;
		if ( ! self::num( $alpha ) || $alpha < 0 || $alpha > 1 ) {
			$why = 'alpha must be 0 to 1';
			return null;
		}
		if ( isset( $v['hex'] ) && ( ! is_string( $v['hex'] ) || ! preg_match( '/^#[0-9a-f]{6}$/i', $v['hex'] ) ) ) {
			$why = 'hex must be # and 6 digits';
			return null;
		}
		$in = function ( $x, $lo, $hi ) {
			return 'none' === $x || ( $x >= $lo && $x <= $hi );
		};
		$ok = true;
		switch ( $space ) {
			case 'srgb':
			case 'srgb-linear':
			case 'display-p3':
			case 'a98-rgb':
			case 'prophoto-rgb':
			case 'rec2020':
				$ok = $in( $c[0], 0, 1 ) && $in( $c[1], 0, 1 ) && $in( $c[2], 0, 1 );
				break;
			case 'hsl':
			case 'hwb':
				$ok = $in( $c[0], 0, 360 ) && $in( $c[1], 0, 100 ) && $in( $c[2], 0, 100 );
				break;
			case 'lab':
				$ok = $in( $c[0], 0, 100 );
				break;
			case 'lch':
				$ok = $in( $c[0], 0, 100 ) && $in( $c[1], 0, 1000 ) && $in( $c[2], 0, 360 );
				break;
			case 'oklab':
				$ok = $in( $c[0], 0, 1 );
				break;
			case 'oklch':
				$ok = $in( $c[0], 0, 1 ) && $in( $c[1], 0, 1000 ) && $in( $c[2], 0, 360 );
				break;
		}
		if ( ! $ok ) {
			$why = 'colour components out of range for ' . $space;
			return null;
		}
		$n = function ( $x ) {
			return 'none' === $x ? 'none' : self::n( $x );
		};
		$a = (float) $alpha >= 1 ? '' : ' / ' . self::n( $alpha );
		if ( 'srgb' === $space && ! in_array( 'none', $c, true ) ) {
			$rgb = array( round( $c[0] * 255 ), round( $c[1] * 255 ), round( $c[2] * 255 ) );
			return '' === $a ? sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] ) : sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], self::n( $alpha ) );
		}
		$pct = in_array( $space, array( 'hsl', 'hwb' ), true ) ? '%' : '';
		$fn  = self::SPACES[ $space ];
		$arg = $n( $c[0] ) . ' ' . $n( $c[1] ) . ( 'none' === $c[1] ? '' : $pct ) . ' ' . $n( $c[2] ) . ( 'none' === $c[2] ? '' : $pct ) . $a;
		return '' === $fn ? 'color(' . $space . ' ' . $arg . ')' : $fn . '(' . $arg . ')';
	}

	/**
	 * The css extension of a colour, when it passes the strict grammar:
	 * [ light, dark ('' when the same) ].
	 *
	 * @param string $css Stored CSS.
	 * @return array|null
	 */
	private static function color_ext( $css ) {
		$css = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $css ) ) );
		$ld  = Ai::light_dark( $css );
		$set = $ld ? $ld : array( $css, '' );
		foreach ( $set as $atom ) {
			if ( '' !== $atom && ! preg_match( self::ATOM, $atom ) ) {
				return null;
			}
		}
		return $set;
	}

	/**
	 * A dimension as [ number, unit ] (a bare number is px, as Tokens Studio writes it).
	 *
	 * @param mixed $d Value.
	 * @return array|null
	 */
	private static function dim_px( $d ) {
		if ( is_array( $d ) ) {
			return isset( $d['value'], $d['unit'] ) && self::num( $d['value'] ) && in_array( $d['unit'], array( 'px', 'rem' ), true ) && abs( $d['value'] ) < 100000 ? array( (float) $d['value'], $d['unit'] ) : null;
		}
		if ( self::num( $d ) ) {
			return abs( $d ) < 100000 ? array( (float) $d, 'px' ) : null;
		}
		if ( is_string( $d ) && preg_match( '/^\s*(-?\d*\.?\d+)(px|rem)?\s*$/', $d, $m ) ) {
			return array( (float) $m[1], isset( $m[2] ) ? $m[2] : 'px' );
		}
		return null;
	}

	/**
	 * A fluid size from the extension, rebuilt as clamp() (the clamp string in
	 * the file is never used). Null with $why set: refused; null without: the
	 * range does not end at the standard value (it was edited elsewhere).
	 *
	 * @param array  $f   The fluid extension.
	 * @param array  $val The standard value [ number, unit ].
	 * @param string $why The problem.
	 * @return string|null
	 */
	private static function fluid_css( $f, $val, &$why ) {
		$r  = Defaults::root_px();
		$px = function ( $d ) use ( $r ) {
			$p = self::dim_px( is_array( $d ) ? $d : null );
			return null === $p ? null : ( 'rem' === $p[1] ? $p[0] * $r : $p[0] );
		};
		$min = $px( isset( $f['min'] ) ? $f['min'] : null );
		$max = $px( isset( $f['max'] ) ? $f['max'] : null );
		$vw0 = $px( isset( $f['minViewport'] ) ? $f['minViewport'] : null );
		$vw1 = $px( isset( $f['maxViewport'] ) ? $f['maxViewport'] : null );
		if ( null === $min || null === $max || null === $vw0 || null === $vw1 ) {
			$why = 'fluid size needs min, max, minViewport and maxViewport as dimensions';
			return null;
		}
		if ( $vw0 < 0 || $vw0 >= $vw1 ) {
			$why = 'fluid size: minViewport must be below maxViewport';
			return null;
		}
		$std = 'rem' === $val[1] ? $val[0] * $r : $val[0];
		if ( abs( $std - $max ) > 0.01 ) {
			return null;
		}
		return Scale_Engine::fluid( $min, $max, $vw0, $vw1 );
	}

	/**
	 * A shadow value (one layer or a list) as CSS.
	 *
	 * @param mixed  $v     Value.
	 * @param array  $names Path → name.
	 * @param array  $cur   This site's tokens.
	 * @param string $why   The problem.
	 * @return string|null
	 */
	private static function shadow_css( $v, $names, $cur, &$why ) {
		if ( ! is_array( $v ) || ! $v ) {
			$why = 'not a shadow';
			return null;
		}
		$layers = array_keys( $v ) === range( 0, count( $v ) - 1 ) ? $v : array( $v );
		$out    = array();
		foreach ( $layers as $l ) {
			if ( ! is_array( $l ) ) {
				$why = 'shadow layers must be objects (references inside a shadow list are not supported)';
				return null;
			}
			// Tokens Studio writes x / y and type innerShadow.
			$x     = isset( $l['offsetX'] ) ? $l['offsetX'] : ( isset( $l['x'] ) ? $l['x'] : null );
			$y     = isset( $l['offsetY'] ) ? $l['offsetY'] : ( isset( $l['y'] ) ? $l['y'] : null );
			$parts = array( $x, $y, isset( $l['blur'] ) ? $l['blur'] : null, isset( $l['spread'] ) ? $l['spread'] : null );
			$dims  = array();
			foreach ( $parts as $p ) {
				$d = null === $p ? null : self::dim_px( $p );
				if ( null === $d ) {
					$why = 'shadow needs offsetX, offsetY, blur and spread as dimensions';
					return null;
				}
				$dims[] = self::n( $d[0] ) . $d[1];
			}
			$c = isset( $l['color'] ) ? self::color_css( $l['color'], $names, $cur, $why ) : null;
			if ( null === $c ) {
				$why = 'shadow colour: ' . $why;
				return null;
			}
			$inset = ( isset( $l['inset'] ) && true === $l['inset'] ) || ( isset( $l['type'] ) && 'innerShadow' === $l['type'] );
			$out[] = ( $inset ? 'inset ' : '' ) . implode( ' ', $dims ) . ' ' . $c;
		}
		return implode( ', ', $out );
	}

	/* ------------------------------------------------------------------ */
	/* Shared helpers                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Shadow CSS as layers { inset, d: [ x, y, blur, spread ] px, color }, or null.
	 *
	 * @param string $css CSS.
	 * @return array|null
	 */
	private static function shadow_layers( $css ) {
		$css = trim( (string) $css );
		if ( '' === $css || preg_match( '/^[a-z-]+\(/i', $css ) && ! preg_match( '/^(?:rgba?|hsla?|oklch|var)\(/i', $css ) ) {
			return null; // drop-shadow(), color-mix() …
		}
		$r      = Defaults::root_px();
		$layers = array();
		foreach ( self::split_top( $css, ',' ) as $layer ) {
			$l = array( 'inset' => false, 'd' => array(), 'color' => '' );
			foreach ( self::split_top( $layer, ' ' ) as $w ) {
				if ( 'inset' === strtolower( $w ) ) {
					$l['inset'] = true;
				} elseif ( preg_match( '/^(-?\d*\.?\d+)(px|rem)?$/', $w, $m ) && ( isset( $m[2] ) || 0.0 === (float) $m[1] ) ) {
					$l['d'][] = isset( $m[2] ) && 'rem' === $m[2] ? (float) $m[1] * $r : (float) $m[1];
				} elseif ( '' === $l['color'] && preg_match( self::ATOM, strtolower( $w ) ) ) {
					$l['color'] = strtolower( $w );
				} else {
					return null;
				}
			}
			if ( count( $l['d'] ) < 2 || count( $l['d'] ) > 4 || '' === $l['color'] ) {
				return null;
			}
			$l['d']   = array_pad( $l['d'], 4, 0.0 );
			$layers[] = $l;
		}
		return $layers ? $layers : null;
	}

	/**
	 * Split on a character outside parentheses (empty parts dropped).
	 *
	 * @param string $s   Text.
	 * @param string $sep One character.
	 * @return array
	 */
	private static function split_top( $s, $sep ) {
		$out   = array();
		$depth = 0;
		$buf   = '';
		for ( $i = 0, $n = strlen( $s ); $i < $n; $i++ ) {
			$ch = $s[ $i ];
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			}
			if ( 0 === $depth && ( $ch === $sep || ( ' ' === $sep && ctype_space( $ch ) ) ) ) {
				$out[] = trim( $buf );
				$buf   = '';
				continue;
			}
			$buf .= $ch;
		}
		$out[] = trim( $buf );
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/**
	 * A font stack as plain family names, or null when one is not a safe name.
	 *
	 * @param string $v Font stack.
	 * @return array|null
	 */
	private static function font_list( $v ) {
		$out = array();
		foreach ( explode( ',', (string) $v ) as $f ) {
			$f = trim( trim( $f ), '"\'' );
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 _-]{0,62}$/', $f ) ) {
				return null;
			}
			$out[] = $f;
		}
		return $out ? $out : null;
	}

	/**
	 * Do two values say the same (spacing, rounding, #fff vs #ffffff, shadow
	 * written with or without the zero spread)?
	 *
	 * @param string $a    Value.
	 * @param string $b    Value.
	 * @param array  $vars Variables.
	 * @return bool
	 */
	private static function same( $a, $b, $vars ) {
		if ( Tokens::same_value( $a, $b ) ) {
			return true;
		}
		$sa = self::shadow_layers( $a );
		$sb = self::shadow_layers( $b );
		if ( $sa && $sb ) {
			if ( count( $sa ) !== count( $sb ) ) {
				return false;
			}
			foreach ( $sa as $i => $l ) {
				if ( $l['inset'] !== $sb[ $i ]['inset'] || ! self::same( $l['color'], $sb[ $i ]['color'], $vars ) ) {
					return false;
				}
				foreach ( $l['d'] as $k => $d ) {
					if ( abs( $d - $sb[ $i ]['d'][ $k ] ) > 0.01 ) {
						return false;
					}
				}
			}
			return true;
		}
		foreach ( array( false, true ) as $dark ) {
			$ca = Contrast::parse( $a, $vars, $dark );
			$cb = Contrast::parse( $b, $vars, $dark );
			if ( ! $ca || ! $cb || ! self::same_rgba( $ca, $cb ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Same colour within 8-bit rounding.
	 *
	 * @param array $a [ r, g, b, a ].
	 * @param array $b [ r, g, b, a ].
	 * @return bool
	 */
	private static function same_rgba( $a, $b ) {
		foreach ( array( 0, 1, 2, 3 ) as $i ) {
			if ( abs( $a[ $i ] - $b[ $i ] ) > 0.6 / 255 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Last guard before a value is stored: only the characters the builders above write.
	 *
	 * @param string $css Value.
	 * @return bool
	 */
	private static function safe( $css ) {
		return '' !== $css && strlen( $css ) <= 2000 && (bool) preg_match( '/^[a-z0-9#.,%()\s\/+"_-]+$/i', $css ) && false === strpos( $css, '/*' ) && ! preg_match( '/(?:url|expression|image-set|src)\s*\(/i', $css );
	}

	/**
	 * Group for a new token from its type.
	 *
	 * @param string $type Token type.
	 * @param string $name Name.
	 * @return string
	 */
	private static function group_for( $type, $name ) {
		$by = array( 'color' => 'color', 'shadow' => 'shadow', 'fontFamily' => 'typography', 'fontWeight' => 'typography' );
		return isset( $by[ $type ] ) ? $by[ $type ] : Tokens::derive_group( $name );
	}

	/**
	 * A finite JSON number.
	 *
	 * @param mixed $x Value.
	 * @return bool
	 */
	private static function num( $x ) {
		return ( is_int( $x ) || is_float( $x ) ) && is_finite( (float) $x );
	}

	/**
	 * Number without trailing zeros.
	 *
	 * @param float $v Number.
	 * @return string
	 */
	private static function n( $v ) {
		$s = rtrim( rtrim( sprintf( '%.6F', (float) $v ), '0' ), '.' );
		return '-0' === $s ? '0' : $s;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /token-files: both files and what was left out.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_export() {
		return rest_ensure_response( self::export() );
	}

	/**
	 * POST /token-files/preview and /token-files/import, body { light, dark }
	 * (the file texts). The body size is checked before it is parsed.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_import( $req ) {
		if ( strlen( (string) $req->get_body() ) > 2 * self::MAX_BYTES + 4096 ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'The files are larger than 2 MB each, so they were not read.' ) );
		}
		$body  = $req->get_json_params();
		$text  = function ( $k ) use ( $body ) {
			return is_array( $body ) && isset( $body[ $k ] ) && is_string( $body[ $k ] ) ? $body[ $k ] : null;
		};
		$apply = '/import' === substr( $req->get_route(), -7 );
		$plan  = $apply ? self::import( $text( 'light' ), $text( 'dark' ) ) : self::plan( $text( 'light' ), $text( 'dark' ) );
		if ( '' !== $plan['error'] ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $plan['error'] ) );
		}
		if ( $apply && $plan['saved'] ) {
			Css_Generator::get_instance()->rebuild();
		}
		unset( $plan['save'], $plan['error'] );
		return rest_ensure_response( array( 'ok' => true ) + $plan );
	}
}
