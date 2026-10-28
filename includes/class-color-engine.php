<?php
/**
 * Color engine: derives channel variables and a shade scale from a base hex color.
 *
 * Original implementation. Given a color name + hex, produces the full set of
 * CSS custom properties a utility framework needs: raw hex, HSL/RGB channels,
 * OKLCH shades (ultra-light … ultra-dark) plus a hover step, each as
 * light-dark() for dark mode, -ref values and -trans-* steps.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless color math + token derivation.
 */
class Color_Engine {

	/**
	 * HSL lightness of each shade (percent); the OKLCH shade takes its lightness
	 * from it. Keys become the token suffix, e.g. primary-ultra-light.
	 *
	 * @var array
	 */
	const SHADES = array(
		'ultra-light' => 95,
		'light'       => 85,
		'semi-light'  => 65,
		'semi-dark'   => 35,
		'dark'        => 25,
		'ultra-dark'  => 10,
	);

	/**
	 * Parse a hex string to [r,g,b] 0-255.
	 *
	 * @param string $hex Hex color (#rgb or #rrggbb).
	 * @return array|null
	 */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return null;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * RGB (0-255) to HSL with h in deg, s/l in percent (floats).
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @return array [h,s,l]
	 */
	public static function rgb_to_hsl( $r, $g, $b ) {
		$r /= 255;
		$g /= 255;
		$b /= 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;
		if ( 0.0 === (float) $d ) {
			$h = 0;
			$s = 0;
		} else {
			$s = $d / ( 1 - abs( 2 * $l - 1 ) );
			switch ( $max ) {
				case $r:
					$h = 60 * fmod( ( ( $g - $b ) / $d ), 6 );
					break;
				case $g:
					$h = 60 * ( ( $b - $r ) / $d + 2 );
					break;
				default:
					$h = 60 * ( ( $r - $g ) / $d + 4 );
					break;
			}
		}
		if ( $h < 0 ) {
			$h += 360;
		}
		return array( round( $h ), round( $s * 100 ), round( $l * 100 ) );
	}

	/**
	 * HSL (deg, %, %) to hex string.
	 *
	 * @param float $h Hue.
	 * @param float $s Saturation percent.
	 * @param float $l Lightness percent.
	 * @return string
	 */
	public static function hsl_to_hex( $h, $s, $l ) {
		$s /= 100;
		$l /= 100;
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		if ( $h < 60 ) {
			$rgb = array( $c, $x, 0 );
		} elseif ( $h < 120 ) {
			$rgb = array( $x, $c, 0 );
		} elseif ( $h < 180 ) {
			$rgb = array( 0, $c, $x );
		} elseif ( $h < 240 ) {
			$rgb = array( 0, $x, $c );
		} elseif ( $h < 300 ) {
			$rgb = array( $x, 0, $c );
		} else {
			$rgb = array( $c, 0, $x );
		}
		return sprintf(
			'#%02x%02x%02x',
			(int) round( ( $rgb[0] + $m ) * 255 ),
			(int) round( ( $rgb[1] + $m ) * 255 ),
			(int) round( ( $rgb[2] + $m ) * 255 )
		);
	}

	/**
	 * Build the full token set for one base color (default palette options).
	 *
	 * @param string $name Color token base name (e.g. "primary").
	 * @param string $hex  Base hex color.
	 * @return array List of normalised tokens (name/value/group/enabled).
	 */
	public static function derive( $name, $hex ) {
		return self::family( $name, $hex );
	}

	// ---- OKLCH -------------------------------------------------------------
	//
	// Shades are made the way Automatic.css 4 makes them: each shade keeps the
	// family's OKLCH hue and chroma, takes its lightness from the matching HSL
	// shade (so the familiar 95/85/65/35/25/10 steps look the same), and lowers
	// the chroma only where the colour would fall outside the Display P3 gamut.
	// Dark mode swaps the light and dark shades with light-dark().

	/** Shade => its "opposite" in dark mode. */
	const MIRROR = array(
		'ultra-light' => 'ultra-dark',
		'light'       => 'dark',
		'semi-light'  => 'semi-dark',
		'semi-dark'   => 'semi-light',
		'dark'        => 'light',
		'ultra-dark'  => 'ultra-light',
	);

	/** Status families get a deeper "dark" shade (HSL 15 instead of 25). */
	const STATUS = array( 'success', 'danger', 'warning', 'info' );

	/** Shades that also get -trans-10 … -trans-90 ('' = the base colour). */
	const TRANS = array( '', 'light', 'dark', 'ultra-dark' );

	/**
	 * sRGB channel (0-1) to linear light.
	 *
	 * @param float $c Channel.
	 * @return float
	 */
	private static function lin( $c ) {
		return $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * sRGB (0-255) to OKLCH.
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @return array [L 0-1, C, H deg]
	 */
	public static function rgb_to_oklch( $r, $g, $b ) {
		$r  = self::lin( $r / 255 );
		$g  = self::lin( $g / 255 );
		$b  = self::lin( $b / 255 );
		$l  = pow( 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b, 1 / 3 );
		$m  = pow( 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b, 1 / 3 );
		$s  = pow( 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b, 1 / 3 );
		$L  = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
		$A  = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
		$B  = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
		$C  = sqrt( $A * $A + $B * $B );
		$H  = $C < 1e-7 ? 0.0 : fmod( rad2deg( atan2( $B, $A ) ) + 360, 360 );
		return array( $L, $C, $H );
	}

	/**
	 * Is OKLCH(L C H) inside the Display P3 gamut?
	 *
	 * @param float $L Lightness 0-1.
	 * @param float $C Chroma.
	 * @param float $H Hue deg.
	 * @return bool
	 */
	public static function in_p3( $L, $C, $H ) {
		$a  = $C * cos( deg2rad( $H ) );
		$bb = $C * sin( deg2rad( $H ) );
		$l  = pow( $L + 0.3963377774 * $a + 0.2158037573 * $bb, 3 );
		$m  = pow( $L - 0.1055613458 * $a - 0.0638541728 * $bb, 3 );
		$s  = pow( $L - 0.0894841775 * $a - 1.2914855480 * $bb, 3 );
		// OKLab → linear sRGB → linear Display P3 (via XYZ D65, folded into one matrix).
		$r  = 4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s;
		$g  = -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s;
		$b  = -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s;
		$p3 = array(
			0.8225927332 * $r + 0.1775339606 * $g,
			0.0331995967 * $r + 0.9667835426 * $g,
			0.0170853336 * $r + 0.0723957193 * $g + 0.9103014471 * $b,
		);
		foreach ( $p3 as $v ) {
			if ( $v < -0.000001 || $v > 1.000001 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The chroma to use at a lightness/hue: the wanted chroma, or the most P3 allows.
	 *
	 * @param float $L Lightness.
	 * @param float $C Wanted chroma.
	 * @param float $H Hue.
	 * @return float
	 */
	public static function fit_chroma( $L, $C, $H ) {
		if ( $C <= 0 || self::in_p3( $L, $C, $H ) ) {
			return max( 0, $C );
		}
		$lo = 0.0;
		$hi = $C;
		for ( $i = 0; $i < 32; $i++ ) {
			$mid = ( $lo + $hi ) / 2;
			if ( self::in_p3( $L, $mid, $H ) ) {
				$lo = $mid;
			} else {
				$hi = $mid;
			}
		}
		return $lo;
	}

	/**
	 * OKLCH lightness of an HSL colour (as the 8-bit colour a browser would show).
	 *
	 * @param float $h Hue.
	 * @param float $s Saturation %.
	 * @param float $l Lightness %.
	 * @return float
	 */
	public static function hsl_oklch_l( $h, $s, $l ) {
		$rgb = self::hex_to_rgb( self::hsl_to_hex( $h, $s, max( 0, min( 100, $l ) ) ) );
		return self::rgb_to_oklch( $rgb[0], $rgb[1], $rgb[2] )[0];
	}

	/**
	 * Round to 3 decimals the way the browser-side tools do (0.7475 → 0.747 when
	 * the float is 0.74749…), so hover steps match Automatic.css.
	 *
	 * @param float $v Value.
	 * @return float
	 */
	private static function r3( $v ) {
		return floor( $v * 1000 + 0.5 ) / 1000;
	}

	/**
	 * CSS oklch() with trimmed numbers.
	 *
	 * @param float $L Lightness.
	 * @param float $C Chroma.
	 * @param float $H Hue.
	 * @return string
	 */
	public static function oklch_css( $L, $C, $H ) {
		$n = function ( $v, $d ) {
			$v = rtrim( rtrim( number_format( (float) $v, $d, '.', '' ), '0' ), '.' );
			return '-0' === $v ? '0' : ( '' === $v ? '0' : $v );
		};
		return 'oklch(' . $n( $L, 6 ) . ' ' . $n( $C, 3 ) . ' ' . $n( $H, 2 ) . ')';
	}

	/**
	 * Every token of a colour family, in the shape Automatic.css 4 outputs
	 * (plus the HSL/RGB channels and -trans-* steps it had in version 3).
	 *
	 * Options:
	 *  - lightness: null = the colour's own OKLCH lightness, or 0-1 to unify
	 *    the base lightness of several families;
	 *  - alt: a hex colour for dark mode ('' = the same colour);
	 *  - trans: add the -trans-10 … -trans-90 steps.
	 *
	 * @param string $name Family name.
	 * @param string $hex  Base colour.
	 * @param array  $opt  Options.
	 * @return array Tokens.
	 */
	public static function family( $name, $hex, $opt = array() ) {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return array();
		}
		$opt = array_merge( array( 'lightness' => null, 'alt' => '', 'trans' => true, 'alt_l' => '', 'alt_c' => '', 'hover_l' => '', 'hover_c' => '', 'alt_tokens' => false ), (array) $opt );
		list( $r, $g, $b ) = $rgb;
		list( $h, $s, $l ) = self::rgb_to_hsl( $r, $g, $b );
		list( $L0, $C0, $H0 ) = self::rgb_to_oklch( $r, $g, $b );
		$H = round( $H0 );
		$C = round( $C0, 3 );

		$tokens = array();
		$add    = function ( $n, $v ) use ( &$tokens ) {
			$tokens[] = array( 'name' => $n, 'value' => (string) $v, 'group' => 'color', 'enabled' => true );
		};

		// HSL shade lightness (%), as in Automatic.css.
		$shades = self::SHADES;
		if ( in_array( $name, self::STATUS, true ) ) {
			$shades['dark'] = 15;
		}
		$hover_l = min( 100, $l * 1.15 );

		// Channels.
		$add( "$name-hex", sprintf( '#%02x%02x%02x', $r, $g, $b ) );
		$add( "$name-hsl", "$h $s% $l%" );
		$add( "$name-rgb", "$r $g $b" );
		$add( "$name-r", $r );
		$add( "$name-g", $g );
		$add( "$name-b", $b );
		$add( "$name-h", $h );
		$add( "$name-s", $s . '%' );
		$add( "$name-l", $l . '%' );
		foreach ( array_merge( array( 'hover' => $hover_l ), $shades ) as $sh => $sl ) {
			$add( "$name-$sh-h", $h );
			$add( "$name-$sh-s", $s . '%' );
			$add( "$name-$sh-l", round( $sl ) . '%' );
		}

		// OKLCH: base, hover and shades.
		$base_l = null === $opt['lightness'] ? round( $L0, 3 ) : (float) $opt['lightness'];
		$ok     = array();
		$ok['']      = array( $base_l, self::fit_chroma( $base_l, $C, $H ), $H );
		$hl          = self::r3( $base_l * 1.15 <= 0.98 ? $base_l * 1.15 : $base_l * 0.85 );
		$ok['hover'] = array( $hl, self::fit_chroma( $hl, $ok[''][1], $H ), $H );
		foreach ( $shades as $sh => $sl ) {
			$sL        = round( self::hsl_oklch_l( $h, $s, $sl ), 6 );
			$ok[ $sh ] = array( $sL, self::fit_chroma( $sL, $ok[''][1], $H ), $H );
		}
		$css = array();
		foreach ( $ok as $k => $v ) {
			$css[ $k ] = self::oklch_css( $v[0], $v[1], $v[2] );
		}

		// Dark mode: the alt colour for the base (and its hover), shades swap.
		$dark = array( '' => $css[''], 'hover' => $css['hover'] );
		$dm   = $ok[''];     // dark main [ L, C, H ]
		$dh   = $ok['hover']; // dark hover
		$alt  = self::hex_to_rgb( (string) $opt['alt'] );
		if ( null !== $alt ) {
			list( $aL, $aC, $aH ) = self::rgb_to_oklch( $alt[0], $alt[1], $alt[2] );
			$aL = round( $aL, 3 );
			$aH = round( $aH );
			$ahl = self::r3( $aL * 1.15 <= 0.98 ? $aL * 1.15 : $aL * 0.85 );
			$dm  = array( $aL, self::fit_chroma( $aL, round( $aC, 3 ), $aH ), $aH );
			$dh  = array( $ahl, self::fit_chroma( $ahl, round( $aC, 3 ), $aH ), $aH );
		}
		// Fine tuning: the dark main colour's lightness / chroma (its hue stays),
		// then the dark hover's (else the hover follows the dark main colour).
		$num = function ( $v ) {
			return is_numeric( $v ) ? (float) $v : null;
		};
		$al = $num( $opt['alt_l'] );
		$ac = $num( $opt['alt_c'] );
		if ( null !== $al || null !== $ac ) {
			$L   = null !== $al ? $al : $dm[0];
			$dm  = array( $L, self::fit_chroma( $L, null !== $ac ? $ac : $dm[1], $dm[2] ), $dm[2] );
			$hl  = self::r3( $L * 1.15 <= 0.98 ? $L * 1.15 : $L * 0.85 );
			$dh  = array( $hl, self::fit_chroma( $hl, $dm[1], $dm[2] ), $dm[2] );
		}
		$hlo = $num( $opt['hover_l'] );
		$hco = $num( $opt['hover_c'] );
		if ( null !== $hlo || null !== $hco ) {
			$L  = null !== $hlo ? $hlo : $dh[0];
			$dh = array( $L, self::fit_chroma( $L, null !== $hco ? $hco : $dh[1], $dh[2] ), $dh[2] );
		}
		if ( null !== $alt || null !== $al || null !== $ac || null !== $hlo || null !== $hco ) {
			$dark['']      = self::oklch_css( $dm[0], $dm[1], $dm[2] );
			$dark['hover'] = self::oklch_css( $dh[0], $dh[1], $dh[2] );
		}
		foreach ( self::MIRROR as $sh => $opp ) {
			$dark[ $sh ] = $css[ $opp ];
		}
		foreach ( $css as $k => $v ) {
			$n = '' === $k ? $name : "$name-$k";
			$add( $n, 'light-dark(' . $v . ', ' . $dark[ $k ] . ')' );
		}
		// The dark mode value of every colour as its own variable (--primary-alt,
		// --primary-light-alt …), to use it anywhere without switching the scheme.
		if ( ! empty( $opt['alt_tokens'] ) ) {
			foreach ( $dark as $k => $v ) {
				$add( ( '' === $k ? $name : "$name-$k" ) . '-alt', $v );
			}
		}

		// Transparent steps (from the sRGB colour of each shade).
		if ( $opt['trans'] ) {
			foreach ( self::TRANS as $sh ) {
				$c = '' === $sh ? $rgb : self::hex_to_rgb( self::hsl_to_hex( $h, $s, $shades[ $sh ] ) );
				for ( $i = 1; $i <= 9; $i++ ) {
					$add( $name . ( '' === $sh ? '' : "-$sh" ) . '-trans-' . ( $i * 10 ), sprintf( 'rgba(%d, %d, %d, %s)', $c[0], $c[1], $c[2], '0.' . $i ) );
				}
			}
		}

		// Plain (light) values, for relative colours and tools.
		foreach ( $css as $k => $v ) {
			$add( ( '' === $k ? $name : "$name-$k" ) . '-ref', $v );
		}
		return $tokens;
	}

	/**
	 * Regex for every token name a family owns (so a regenerated family
	 * replaces its old tokens and nothing else, e.g. not base-text-lh).
	 *
	 * @param string $name Family name.
	 * @return string
	 */
	public static function family_regex( $name ) {
		return '/^' . preg_quote( $name, '/' ) . '(?:-(?:hover|ultra-light|light|semi-light|semi-dark|dark|ultra-dark))?(?:-(?:ref|hex|hsl|rgb|r|g|b|h|s|l|alt|trans-\d+))?$/';
	}
}
