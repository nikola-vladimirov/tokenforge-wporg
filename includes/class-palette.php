<?php
/**
 * Palette: the colour families and how their shades are generated.
 *
 * Each family (primary, secondary, … and your own) is made from a base colour
 * by Color_Engine::family(): OKLCH shades, hover, light-dark() for dark mode,
 * -ref values, channels and -trans-* steps. This module stores the base (and
 * optional dark mode) colour per family, whether the family is on, and the
 * palette-wide options. Since 0.37 the family variables are made from these
 * settings whenever the stylesheet is built (tokens()), like the variables the
 * other settings make; a stored token with the same name replaces one.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Palette settings + the colour variables they make.
 */
class Palette {

	const OPTION = 'tokenforge_palette';

	/** Families whose base lightness "unify brand lightness" sets. */
	const BRAND = array( 'primary', 'secondary', 'tertiary', 'accent', 'base' );

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'palette', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'unify_brand'  => true,  // primary … base share one base lightness
			'unify_status' => true,  // success, danger, warning, info too
			'lightness'    => 0.65,  // that lightness (OKLCH 0-1)
			'trans'        => true,  // -trans-10 … -trans-90 steps
			'alt_tokens'   => false, // --primary-alt … : the dark mode value of every colour
			'families'     => array(), // name => { hex, alt, on, alt_l, alt_c, hover_l, hover_c }
		);
	}

	/**
	 * Settings. Before the first save the families are read from the tokens.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, null );
		if ( ! is_array( $s ) ) {
			$s = array( 'families' => self::detect() );
		}
		$s = wp_parse_args( $s, self::defaults() );
		$s['families'] = self::clean_families( $s['families'] );
		return $s;
	}

	/**
	 * Sanitise the family list.
	 *
	 * @param mixed $in Raw families.
	 * @return array name => { hex, alt, on }
	 */
	private static function clean_families( $in ) {
		$out = array();
		foreach ( (array) $in as $name => $f ) {
			$name = Tokens::sanitize_name( $name );
			if ( '' === $name || ! is_array( $f ) ) {
				continue;
			}
			$out[ $name ] = array(
				'hex'     => self::hex( isset( $f['hex'] ) ? $f['hex'] : '' ),
				'alt'     => self::hex( isset( $f['alt'] ) ? $f['alt'] : '' ),
				'on'      => ! isset( $f['on'] ) || ! empty( $f['on'] ),
				// Dark mode fine tuning (OKLCH lightness 0-1, chroma 0-0.4; '' = not set).
				'alt_l'   => self::part( isset( $f['alt_l'] ) ? $f['alt_l'] : '', 1 ),
				'alt_c'   => self::part( isset( $f['alt_c'] ) ? $f['alt_c'] : '', 0.4 ),
				'hover_l' => self::part( isset( $f['hover_l'] ) ? $f['hover_l'] : '', 1 ),
				'hover_c' => self::part( isset( $f['hover_c'] ) ? $f['hover_c'] : '', 0.4 ),
			);
		}
		return $out;
	}

	/**
	 * A #rrggbb colour or ''.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function hex( $v ) {
		$rgb = Color_Engine::hex_to_rgb( (string) $v );
		return null === $rgb ? '' : sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}

	/**
	 * An OKLCH part between 0 and $max ("65%" for lightness reads as 0.65), or ''.
	 *
	 * @param mixed $v   Value.
	 * @param float $max Largest value.
	 * @return string
	 */
	private static function part( $v, $max ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^([\d.]+)%$/', $v, $m ) ) {
			$v = (string) ( (float) $m[1] / 100 );
		}
		if ( ! is_numeric( $v ) || (float) $v < 0 || (float) $v > $max ) {
			return '';
		}
		return rtrim( rtrim( number_format( (float) $v, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Families present in the stored tokens (standard ones and your own), with
	 * the base colour from their -hex token where there is one.
	 *
	 * @return array
	 */
	public static function detect() {
		$tokens = Settings::get_instance()->get_tokens();
		$by     = array();
		foreach ( $tokens as $t ) {
			$by[ $t['name'] ] = $t;
		}
		$names = Components::FAMILIES;
		foreach ( $tokens as $t ) {
			if ( 'color' === $t['group'] && preg_match( '/^([a-z0-9]+)-hex$/', $t['name'], $m ) && ( isset( $by[ $m[1] . '-dark' ] ) || isset( $by[ $m[1] . '-ultra-light' ] ) ) ) {
				$names[] = $m[1];
			}
		}
		$out = array();
		foreach ( array_unique( $names ) as $n ) {
			$has = isset( $by[ $n ] ) || isset( $by[ "$n-dark" ] );
			if ( ! $has && ! in_array( $n, Components::FAMILIES, true ) ) {
				continue;
			}
			$on = false;
			foreach ( $tokens as $t ) {
				if ( $t['enabled'] && preg_match( Color_Engine::family_regex( $n ), $t['name'] ) ) {
					$on = true;
					break;
				}
			}
			$out[ $n ] = array(
				'hex' => isset( $by[ "$n-hex" ] ) ? self::hex( $by[ "$n-hex" ]['value'] ) : '',
				'alt' => '',
				'on'  => $has ? $on : true,
			);
		}
		// Automatic.css settings on this site fill in base colours we don't have.
		$acss = get_option( 'automatic_css_settings' );
		if ( is_array( $acss ) ) {
			foreach ( $out as $n => $f ) {
				if ( '' === $f['hex'] && isset( $acss[ 'color-' . $n ] ) && is_string( $acss[ 'color-' . $n ] ) ) {
					$out[ $n ]['hex'] = self::hex( $acss[ 'color-' . $n ] );
				}
			}
		}
		return $out;
	}

	/**
	 * Engine options for one family.
	 *
	 * @param string $name Family.
	 * @param array  $s    Settings.
	 * @return array
	 */
	public static function options_for( $name, $s ) {
		$unify = ( $s['unify_brand'] && in_array( $name, self::BRAND, true ) ) || ( $s['unify_status'] && in_array( $name, Color_Engine::STATUS, true ) );
		return array(
			'lightness' => $unify ? (float) $s['lightness'] : null,
			'alt'       => isset( $s['families'][ $name ]['alt'] ) ? $s['families'][ $name ]['alt'] : '',
			'trans'     => (bool) $s['trans'],
			'alt_l'     => isset( $s['families'][ $name ]['alt_l'] ) ? $s['families'][ $name ]['alt_l'] : '',
			'alt_c'     => isset( $s['families'][ $name ]['alt_c'] ) ? $s['families'][ $name ]['alt_c'] : '',
			'hover_l'   => isset( $s['families'][ $name ]['hover_l'] ) ? $s['families'][ $name ]['hover_l'] : '',
			'hover_c'   => isset( $s['families'][ $name ]['hover_c'] ) ? $s['families'][ $name ]['hover_c'] : '',
			'alt_tokens' => ! empty( $s['alt_tokens'] ),
		);
	}

	/**
	 * Store palette settings as they are (import, reset). The stored tokens
	 * stay as they are.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function store( $in ) {
		$d     = self::defaults();
		$light = isset( $in['lightness'] ) ? (float) $in['lightness'] : $d['lightness'];
		update_option(
			self::OPTION,
			array(
				'unify_brand'  => ! isset( $in['unify_brand'] ) || ! empty( $in['unify_brand'] ),
				'unify_status' => ! isset( $in['unify_status'] ) || ! empty( $in['unify_status'] ),
				'lightness'    => ( $light >= 0.3 && $light <= 0.9 ) ? round( $light, 3 ) : $d['lightness'],
				'trans'        => ! isset( $in['trans'] ) || ! empty( $in['trans'] ),
				'alt_tokens'   => ! empty( $in['alt_tokens'] ),
				'families'     => self::clean_families( isset( $in['families'] ) ? $in['families'] : array() ),
			),
			false
		);
	}

	/**
	 * Raw input as save_settings() stores it.
	 *
	 * @param array $in       Raw input.
	 * @param array $families Families kept when the input has none.
	 * @return array
	 */
	private static function clean( $in, $families ) {
		$d     = self::defaults();
		$light = isset( $in['lightness'] ) ? (float) $in['lightness'] : $d['lightness'];
		return array(
			'unify_brand'  => ! empty( $in['unify_brand'] ),
			'unify_status' => ! empty( $in['unify_status'] ),
			'lightness'    => ( $light >= 0.3 && $light <= 0.9 ) ? round( $light, 3 ) : $d['lightness'],
			'trans'        => ! empty( $in['trans'] ),
			'alt_tokens'   => ! empty( $in['alt_tokens'] ),
			'families'     => self::clean_families( isset( $in['families'] ) ? $in['families'] : $families ),
		);
	}

	/**
	 * A fingerprint of the settings (same settings, same fingerprint): the stored ones, or a raw array as save_settings() would store it.
	 *
	 * @param array|null $in Raw settings (default: the stored ones).
	 * @return string
	 */
	public static function rev( $in = null ) {
		$old = self::settings();
		return md5( (string) wp_json_encode( self::clean( null === $in ? $old : $in, $old['families'] ) ) );
	}

	/**
	 * Save settings; stored tokens that would hide a changed family go.
	 *
	 * @param array $in Raw input.
	 * @return array Names of the families that changed or were switched.
	 */
	public static function save_settings( $in ) {
		$old = self::settings();
		$new = self::clean( $in, $old['families'] );
		update_option( self::OPTION, $new, false );

		$regen  = array();
		$toggle = array();
		foreach ( $new['families'] as $name => $f ) {
			$was = isset( $old['families'][ $name ] ) ? $old['families'][ $name ] : array( 'hex' => '', 'alt' => '', 'on' => true );
			if ( '' !== $f['hex'] && ( $f['hex'] !== $was['hex'] || self::options_for( $name, $new ) !== self::options_for( $name, $old ) ) ) {
				$regen[] = $name;
			}
			if ( $f['on'] !== $was['on'] ) {
				$toggle[] = $name;
			}
		}
		if ( $regen || $toggle ) {
			self::apply( $regen, $toggle, $new );
		}
		return array_values( array_unique( array_merge( $regen, $toggle ) ) );
	}

	/**
	 * The colour variables the families make now: every family that is on and
	 * has a colour. They go into the stylesheet with the variables the other
	 * settings make (Settings::all_variables()); a stored token with the same
	 * name replaces one.
	 *
	 * @param array|null $s Settings (default: the saved ones).
	 * @return array Tokens.
	 */
	public static function tokens( $s = null ) {
		$s   = $s ? $s : self::settings();
		$key = md5( (string) wp_json_encode( $s ) );
		static $memo = array();
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		$out = array();
		foreach ( (array) $s['families'] as $name => $f ) {
			if ( empty( $f['hex'] ) || empty( $f['on'] ) ) {
				continue;
			}
			foreach ( Color_Engine::family( $name, $f['hex'], self::options_for( $name, $s ) ) as $t ) {
				$t['enabled'] = true;
				$out[]        = $t;
			}
		}
		if ( count( $memo ) > 8 ) {
			$memo = array();
		}
		$memo[ $key ] = $out;
		return $out;
	}

	/**
	 * Leave out stored colour tokens the families make anyway: the ones with
	 * the same value (switched on), and switched-off ones of a family that is
	 * off. Tokens with a value of their own stay (the Tokens screen shows them).
	 *
	 * @param array      $tokens Stored tokens.
	 * @param array|null $s      Settings.
	 * @return array [ tokens, how many were left out ]
	 */
	public static function without_repeats( $tokens, $s = null ) {
		$s    = $s ? $s : self::settings();
		$made = array();
		foreach ( self::tokens( $s ) as $t ) {
			$made[ $t['name'] ] = $t['value'];
		}
		$off = array();
		foreach ( (array) $s['families'] as $name => $f ) {
			if ( ! empty( $f['hex'] ) && empty( $f['on'] ) ) {
				$off[] = Color_Engine::family_regex( $name );
			}
		}
		$out = array();
		$n   = 0;
		foreach ( $tokens as $t ) {
			if ( isset( $made[ $t['name'] ] ) && ! empty( $t['enabled'] ) && Tokens::same_value( $t['value'], $made[ $t['name'] ] ) ) {
				$n++;
				continue;
			}
			if ( empty( $t['enabled'] ) && ! isset( $made[ $t['name'] ] ) ) {
				foreach ( $off as $re ) {
					if ( preg_match( $re, $t['name'] ) ) {
						$n++;
						continue 2;
					}
				}
			}
			$out[] = $t;
		}
		return array( $out, $n );
	}

	/**
	 * After a family changed: stored tokens with its names would hide the new
	 * colours, so they go. After a family was switched: its stored tokens are
	 * switched with it.
	 *
	 * @param array      $regen  Families whose colours changed.
	 * @param array      $toggle Families switched on or off.
	 * @param array|null $s      Settings.
	 * @return void
	 */
	public static function apply( $regen, $toggle = array(), $s = null ) {
		$s        = $s ? $s : self::settings();
		$settings = Settings::get_instance();
		$tokens   = $settings->get_tokens();
		$changed  = false;
		foreach ( $regen as $name ) {
			$re   = Color_Engine::family_regex( $name );
			$keep = array();
			foreach ( $tokens as $t ) {
				if ( preg_match( $re, $t['name'] ) ) {
					$changed = true;
					continue;
				}
				$keep[] = $t;
			}
			$tokens = $keep;
		}
		foreach ( $toggle as $name ) {
			$on = ! empty( $s['families'][ $name ]['on'] );
			$re = Color_Engine::family_regex( $name );
			foreach ( $tokens as $i => $t ) {
				if ( preg_match( $re, $t['name'] ) && $t['enabled'] !== $on ) {
					$tokens[ $i ]['enabled'] = $on;
					$changed                 = true;
				}
			}
		}
		if ( $changed ) {
			$settings->save_tokens( $tokens );
		}
	}

	/**
	 * Add (or replace) a family from a base colour, e.g. "Generate a family".
	 *
	 * @param string $name Family name.
	 * @param string $hex  Base colour.
	 * @param string $alt  Dark mode colour ('' = the same).
	 * @return int Number of variables the family makes (0 = invalid input).
	 */
	public static function add( $name, $hex, $alt = '' ) {
		$name = Tokens::sanitize_name( $name );
		$hex  = self::hex( $hex );
		if ( '' === $name || '' === $hex ) {
			return 0;
		}
		$s                     = self::settings();
		$s['families'][ $name ] = array( 'hex' => $hex, 'alt' => self::hex( $alt ), 'on' => true );
		update_option( self::OPTION, $s, false );
		self::apply( array( $name ), array(), $s );
		return count( Color_Engine::family( $name, $hex, self::options_for( $name, $s ) ) );
	}

	/**
	 * Remove one of your own families (and its tokens). Standard families can
	 * only be switched off.
	 *
	 * @param string $name Family.
	 * @return bool
	 */
	public static function remove( $name ) {
		$name = Tokens::sanitize_name( $name );
		if ( '' === $name || in_array( $name, Components::FAMILIES, true ) ) {
			return false;
		}
		$s = self::settings();
		unset( $s['families'][ $name ] );
		update_option( self::OPTION, $s, false );
		$re       = Color_Engine::family_regex( $name );
		$settings = Settings::get_instance();
		$settings->save_tokens(
			array_values(
				array_filter(
					$settings->get_tokens(),
					function ( $t ) use ( $re ) {
						return ! preg_match( $re, $t['name'] );
					}
				)
			)
		);
		return true;
	}

	/**
	 * Take the palette options and base colours from Automatic.css settings
	 * (the tokens themselves come from its generated variables).
	 *
	 * @param array $a Automatic.css settings.
	 * @return array Report rows.
	 */
	public static function import_acss( $a ) {
		$s    = self::settings();
		$rows = array();
		$on   = function ( $k ) use ( $a ) {
			return isset( $a[ $k ] ) ? in_array( strtolower( (string) $a[ $k ] ), array( 'on', 'true', '1' ), true ) : null;
		};
		// Automatic.css 4 has "unified lightness": one lightness for every brand
		// colour (primary … base) and every status colour. Automatic.css 3 and
		// older have no such setting, so their settings never carry these keys.
		// When the source has none of them it never had unified lightness, so unify
		// goes off: TokenForge's own default (on, 0.65) would otherwise lift every
		// brand colour, base included, to one lightness and change the design.
		$unify = array( 'unify_brand' => 'option-palette-unify-brand-lightness', 'unify_status' => 'option-palette-unify-status-lightness' );
		$known = array_key_exists( 'unified-lightness-value', $a );
		foreach ( $unify as $ak ) {
			$known = $known || array_key_exists( $ak, $a );
		}
		if ( ! $known ) {
			$s['unify_brand']  = false;
			$s['unify_status'] = false;
			$rows[]            = 'unify_brand, unify_status ← off (the source settings have no unified lightness)';
		} else {
			foreach ( $unify as $k => $ak ) {
				if ( null !== $on( $ak ) ) {
					$s[ $k ] = $on( $ak );
					$rows[]  = "$k ← $ak = " . ( $s[ $k ] ? 'on' : 'off' );
				}
			}
			if ( isset( $a['unified-lightness-value'] ) && is_numeric( $a['unified-lightness-value'] ) && $a['unified-lightness-value'] >= 0.3 && $a['unified-lightness-value'] <= 0.9 ) {
				$s['lightness'] = round( (float) $a['unified-lightness-value'], 3 );
				$rows[]         = 'lightness ← unified-lightness-value = ' . $s['lightness'];
			}
		}
		foreach ( Components::FAMILIES as $f ) {
			$hex = isset( $a[ 'color-' . $f ] ) && is_string( $a[ 'color-' . $f ] ) ? self::hex( $a[ 'color-' . $f ] ) : '';
			if ( '' === $hex ) {
				continue;
			}
			$cur                  = isset( $s['families'][ $f ] ) ? $s['families'][ $f ] : array( 'alt' => '', 'on' => true );
			$enabled              = $on( 'option-' . $f . '-clr' );
			$s['families'][ $f ] = array_merge( $cur, array( 'hex' => $hex, 'alt' => $cur['alt'], 'on' => null === $enabled ? $cur['on'] : $enabled ) );
			$rows[]               = "families.$f ← color-$f = $hex" . ( false === $enabled ? ' (off)' : '' );
			// Dark mode overrides of the main colour and its hover (lightness, chroma).
			foreach ( array( 'alt_l' => 'l-alt-oklch', 'alt_c' => 'c-alt-oklch', 'hover_l' => 'hover-l-alt-oklch', 'hover_c' => 'hover-c-alt-oklch' ) as $k => $suffix ) {
				$v = isset( $a[ $f . '-' . $suffix ] ) && is_scalar( $a[ $f . '-' . $suffix ] ) ? self::part( $a[ $f . '-' . $suffix ], in_array( $k, array( 'alt_l', 'hover_l' ), true ) ? 1 : 0.4 ) : '';
				if ( '' !== $v ) {
					$s['families'][ $f ][ $k ] = $v;
					$rows[]                    = "families.$f.$k ← $f-$suffix = $v";
				}
			}
		}
		update_option( self::OPTION, $s, false );
		return $rows;
	}
}
