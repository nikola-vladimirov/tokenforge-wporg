<?php
/**
 * Contrast checker: text, links, focus rings and buttons against their
 * backgrounds, in light and (when dark mode is on) dark mode.
 *
 * The WCAG 2 ratio decides pass or fail (4.5 for text, 3 for focus rings).
 * The APCA Lc value is shown beside it as information only: APCA is a draft
 * for WCAG 3. Colours are read the way the browser reads them, through the
 * variables of the last generated stylesheet, taking the light or the dark
 * side of light-dark(). A failing pair gets a fix: the nearest colour token
 * (closest in OKLab as drawn) that passes in every mode.
 *
 * Original implementation. Formulas: WCAG 2.2 "relative luminance" and
 * "contrast ratio"; APCA 0.1.9 (apca-w3).
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Contrast report + fixes.
 */
class Contrast {

	/** Text and links (WCAG 1.4.3). */
	const TEXT = 4.5;

	/** Focus rings (WCAG 1.4.11). */
	const UI = 3.0;

	/** What a translucent background sits on: the page (white when nothing is set, as WCAG assumes). */
	const PAGE = 'var(--body-bg-color, var(--bg-ultra-light, #fff))';

	/** Shades a fix may use, light to dark. */
	const STEPS = array( 'ultra-light', 'light', 'semi-light', '', 'semi-dark', 'dark', 'ultra-dark' );

	/**
	 * A CSS colour as sRGB [ r, g, b, alpha ] (each 0-1), or null when it is
	 * not a plain colour (color-mix(), currentColor, a missing variable …).
	 *
	 * @param string $v     CSS value.
	 * @param array  $vars  Variables, name => value.
	 * @param bool   $dark  Take the dark side of light-dark().
	 * @param int    $depth Recursion guard.
	 * @return array|null
	 */
	public static function parse( $v, $vars, $dark = false, $depth = 0 ) {
		$v = trim( (string) $v );
		if ( '' === $v || $depth > 12 ) {
			return null;
		}
		$ld = Ai::light_dark( $v );
		if ( $ld ) {
			return self::parse( $ld[ $dark ? 1 : 0 ], $vars, $dark, $depth + 1 );
		}
		if ( preg_match( '/^var\(\s*--([a-z0-9-]+)\s*(?:,(.*))?\)$/is', $v, $m ) ) {
			$n = strtolower( $m[1] );
			if ( isset( $vars[ $n ] ) && '' !== trim( (string) $vars[ $n ] ) ) {
				return self::parse( $vars[ $n ], $vars, $dark, $depth + 1 );
			}
			return isset( $m[2] ) ? self::parse( $m[2], $vars, $dark, $depth + 1 ) : null;
		}
		if ( preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v, $m ) ) {
			$h = strlen( $m[1] ) <= 4 ? preg_replace( '/(.)/', '$1$1', $m[1] ) : $m[1];
			$c = array_map( 'hexdec', str_split( $h, 2 ) );
			return array( $c[0] / 255, $c[1] / 255, $c[2] / 255, isset( $c[3] ) ? $c[3] / 255 : 1.0 );
		}
		$named = array( 'white' => array( 1, 1, 1, 1 ), 'black' => array( 0, 0, 0, 1 ), 'transparent' => array( 0, 0, 0, 0 ) );
		if ( isset( $named[ strtolower( $v ) ] ) ) {
			return $named[ strtolower( $v ) ];
		}
		if ( ! preg_match( '/^(rgba?|hsla?|oklch)\(\s*([^()]*)\)$/i', $v, $m ) ) {
			return null;
		}
		$p = preg_split( '/[\s,\/]+/', trim( $m[2] ) );
		if ( count( $p ) < 3 || count( $p ) > 4 ) {
			return null;
		}
		// A number, or a percentage of $full; null for anything else ("none", calc()).
		$num = function ( $s, $full ) {
			$pct = '%' === substr( $s, -1 );
			$s   = $pct ? substr( $s, 0, -1 ) : preg_replace( '/deg$/i', '', $s );
			return is_numeric( $s ) ? ( $pct ? (float) $s / 100 * $full : (float) $s ) : null;
		};
		$a  = isset( $p[3] ) ? $num( $p[3], 1 ) : 1.0;
		$fn = rtrim( strtolower( $m[1] ), 'a' );
		if ( 'rgb' === $fn ) {
			$c = array( $num( $p[0], 255 ), $num( $p[1], 255 ), $num( $p[2], 255 ) );
			$c = in_array( null, $c, true ) ? null : array( $c[0] / 255, $c[1] / 255, $c[2] / 255 );
		} elseif ( 'hsl' === $fn ) {
			$c = array( $num( $p[0], 360 ), $num( $p[1], 100 ), $num( $p[2], 100 ) );
			$c = in_array( null, $c, true ) ? null : Color_Engine::hex_to_rgb( Color_Engine::hsl_to_hex( fmod( fmod( $c[0], 360 ) + 360, 360 ), max( 0, min( 100, $c[1] ) ), max( 0, min( 100, $c[2] ) ) ) );
			$c = null === $c ? null : array( $c[0] / 255, $c[1] / 255, $c[2] / 255 );
		} else {
			$c = array( $num( $p[0], 1 ), $num( $p[1], 0.4 ), $num( $p[2], 360 ) );
			$c = in_array( null, $c, true ) ? null : self::oklch_srgb( $c[0], $c[1], $c[2] );
		}
		if ( null === $c || null === $a ) {
			return null;
		}
		foreach ( $c as $i => $x ) {
			$c[ $i ] = max( 0.0, min( 1.0, $x ) );
		}
		$c[] = max( 0.0, min( 1.0, $a ) );
		return $c;
	}

	/**
	 * OKLCH to sRGB (0-1, gamma encoded).
	 *
	 * ponytail: out-of-gamut colours are clipped; browsers map them to the
	 * gamut instead, which moves the luminance a little for very vivid colours.
	 *
	 * @param float $L Lightness 0-1.
	 * @param float $C Chroma.
	 * @param float $H Hue deg.
	 * @return array
	 */
	private static function oklch_srgb( $L, $C, $H ) {
		$a   = $C * cos( deg2rad( $H ) );
		$b   = $C * sin( deg2rad( $H ) );
		$l   = pow( $L + 0.3963377774 * $a + 0.2158037573 * $b, 3 );
		$m   = pow( $L - 0.1055613458 * $a - 0.0638541728 * $b, 3 );
		$s   = pow( $L - 0.0894841775 * $a - 1.2914855480 * $b, 3 );
		$lin = array(
			4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
			-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
			-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
		);
		return array_map(
			function ( $x ) {
				$x = max( 0.0, min( 1.0, $x ) );
				return $x <= 0.0031308 ? 12.92 * $x : 1.055 * pow( $x, 1 / 2.4 ) - 0.055;
			},
			$lin
		);
	}

	/**
	 * $fg painted over $bg (alpha blending, as the browser does), opaque.
	 *
	 * @param array $fg Colour.
	 * @param array $bg Opaque colour.
	 * @return array
	 */
	private static function over( $fg, $bg ) {
		$a = $fg[3];
		return array( $fg[0] * $a + $bg[0] * ( 1 - $a ), $fg[1] * $a + $bg[1] * ( 1 - $a ), $fg[2] * $a + $bg[2] * ( 1 - $a ), 1.0 );
	}

	/**
	 * WCAG 2 relative luminance.
	 *
	 * @param array $c Colour.
	 * @return float
	 */
	public static function luminance( $c ) {
		$lin = function ( $x ) {
			return $x <= 0.04045 ? $x / 12.92 : pow( ( $x + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $lin( $c[0] ) + 0.7152 * $lin( $c[1] ) + 0.0722 * $lin( $c[2] );
	}

	/**
	 * WCAG 2 contrast ratio (1 to 21).
	 *
	 * @param array $a Colour.
	 * @param array $b Colour.
	 * @return float
	 */
	public static function ratio( $a, $b ) {
		$x = self::luminance( $a );
		$y = self::luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/**
	 * APCA lightness contrast Lc (negative for light text on a dark background).
	 *
	 * @param array $txt Text colour.
	 * @param array $bg  Background colour.
	 * @return float
	 */
	public static function apca( $txt, $bg ) {
		$y = function ( $c ) {
			$y = 0.2126729 * pow( $c[0], 2.4 ) + 0.7151522 * pow( $c[1], 2.4 ) + 0.0721750 * pow( $c[2], 2.4 );
			return $y > 0.022 ? $y : $y + pow( 0.022 - $y, 1.414 );
		};
		$t = $y( $txt );
		$b = $y( $bg );
		if ( abs( $b - $t ) < 0.0005 ) {
			return 0.0;
		}
		if ( $b > $t ) {
			$s = ( pow( $b, 0.56 ) - pow( $t, 0.57 ) ) * 1.14;
			return $s < 0.1 ? 0.0 : ( $s - 0.027 ) * 100;
		}
		$s = ( pow( $b, 0.65 ) - pow( $t, 0.62 ) ) * 1.14;
		return $s > -0.1 ? 0.0 : ( $s + 0.027 ) * 100;
	}

	/**
	 * Every variable of the stylesheet with its value (switched-on ones).
	 *
	 * @return array name => value
	 */
	public static function variables() {
		$vars = array();
		foreach ( Token_Catalog::every_variable() as $t ) {
			if ( ! empty( $t['enabled'] ) ) {
				$vars[ $t['name'] ] = (string) $t['value'];
			}
		}
		return $vars;
	}

	/**
	 * The pairs to check: where, what, text colour, background, the ratio it
	 * needs and the setting a fix writes (module, key …).
	 *
	 * @return array
	 */
	public static function pairs() {
		$sch  = Scheme::settings();
		$comp = Components::settings();
		$def  = Defaults::settings();
		$out  = array();
		$add  = function ( $where, $what, $fg, $bg, $min, $set ) use ( &$out ) {
			$out[] = array( 'where' => $where, 'what' => $what, 'fg' => $fg, 'bg' => $bg, 'min' => $min, 'set' => $set );
		};
		$page  = __( 'Page', 'tokenforge' );
		$links = ! empty( $comp['base_links'] );
		$focus = ! empty( $def['focus_styles'] );

		$add( $page, __( 'Text', 'tokenforge' ), 'var(--text-color, var(--text-dark))', self::PAGE, self::TEXT, array( 'components', 'text_color' ) );
		if ( '' !== (string) $comp['heading_color'] ) {
			$add( $page, __( 'Headings', 'tokenforge' ), 'var(--heading-color)', self::PAGE, self::TEXT, array( 'components', 'heading_color' ) );
		}
		if ( $links ) {
			$add( $page, __( 'Links', 'tokenforge' ), 'var(--link-color)', self::PAGE, self::TEXT, array( 'components', 'link_color' ) );
			$add( $page, __( 'Links (hover)', 'tokenforge' ), 'var(--link-color-hover)', self::PAGE, self::TEXT, array( 'components', 'link_color_hover' ) );
		}
		if ( $focus ) {
			$add( $page, __( 'Focus ring', 'tokenforge' ), 'var(--focus-color)', self::PAGE, self::UI, array( 'defaults', 'focus_color' ) );
		}

		// Background classes: what each .bg--* puts inside it.
		foreach ( Scheme::CONTEXTS as $c ) {
			$k    = 'ctx_' . str_replace( '-', '_', $c ) . '_';
			$tone = in_array( $c, array( 'ultra-light', 'light' ), true ) ? 'dark' : 'light';
			$bg   = "var(--bg-$c)";
			$text = "var(--bg-$c-text, var(--text-$tone))";
			$add( ".bg--$c", __( 'Text', 'tokenforge' ), $text, $bg, self::TEXT, array( 'scheme', $k . 'text' ) );
			if ( '' !== $sch[ $k . 'heading' ] && $sch[ $k . 'heading' ] !== $sch[ $k . 'text' ] ) {
				$add( ".bg--$c", __( 'Headings', 'tokenforge' ), "var(--bg-$c-heading)", $bg, self::TEXT, array( 'scheme', $k . 'heading' ) );
			}
			$add( ".bg--$c", __( 'Muted text', 'tokenforge' ), "var(--text-$tone-muted)", $bg, self::TEXT, array( 'scheme', "text_{$tone}_muted" ) );
			if ( $links || '' !== $sch[ $k . 'link' ] ) {
				$add( ".bg--$c", __( 'Links', 'tokenforge' ), '' !== $sch[ $k . 'link' ] ? $sch[ $k . 'link' ] : 'var(--link-color)', $bg, self::TEXT, array( 'scheme', $k . 'link' ) );
			}
			if ( $focus || '' !== $sch[ $k . 'focus' ] ) {
				$add( ".bg--$c", __( 'Focus ring', 'tokenforge' ), '' !== $sch[ $k . 'focus' ] ? $sch[ $k . 'focus' ] : 'var(--focus-color)', $bg, self::UI, array( 'scheme', $k . 'focus' ) );
			}
		}

		// Buttons: text on the button, as it is and hovered; the focus ring on the page.
		if ( ! empty( $comp['base_buttons'] ) ) {
			$names = array(
				'solid'         => __( 'Solid', 'tokenforge' ),
				'dark'          => __( 'Dark', 'tokenforge' ),
				'light'         => __( 'Light', 'tokenforge' ),
				'outline'       => __( 'Outline', 'tokenforge' ),
				'outline-dark'  => __( 'Outline dark', 'tokenforge' ),
				'outline-light' => __( 'Outline light', 'tokenforge' ),
			);
			$variants = array( 'solid' );
			if ( ! empty( $comp['btn_shades'] ) ) {
				array_push( $variants, 'dark', 'light' );
			}
			if ( ! empty( $comp['btn_outline'] ) ) {
				$variants[] = 'outline';
				if ( ! empty( $comp['btn_shades'] ) ) {
					array_push( $variants, 'outline-dark', 'outline-light' );
				}
			}
			foreach ( Components::btn_families( $comp ) as $f ) {
				$where = ".btn--$f";
				foreach ( $variants as $vr ) {
					$m   = Components::btn_var_map( $comp, $f, $vr );
					$set = array( 'components', 'btn_overrides', $f, $vr );
					$add( $where, $names[ $vr ], $m['btn-text-color'], $m['btn-background'], self::TEXT, array_merge( $set, array( 'text' ) ) );
					/* translators: %s: button variant, e.g. Solid */
					$add( $where, sprintf( __( '%s (hover)', 'tokenforge' ), $names[ $vr ] ), $m['btn-text-color-hover'], $m['btn-background-hover'], self::TEXT, array_merge( $set, array( 'hover_text' ) ) );
					if ( 'solid' === $vr ) {
						$add( $where, __( 'Focus ring', 'tokenforge' ), $m['focus-color'], self::PAGE, self::UI, array_merge( $set, array( 'focus' ) ) );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * The pair in each mode: the colours as shown, the ratio (rounded down, so a
	 * shown 4.5 really is 4.5) and the APCA Lc. Null when a colour can't be read.
	 *
	 * @param string $fg    Text colour.
	 * @param string $bg    Background.
	 * @param array  $vars  Variables.
	 * @param array  $modes [ false ] or [ false, true ] (dark).
	 * @return array|null
	 */
	private static function measure( $fg, $bg, $vars, $modes ) {
		$out = array();
		foreach ( $modes as $dark ) {
			$f = self::parse( $fg, $vars, $dark );
			$b = self::parse( $bg, $vars, $dark );
			if ( null === $f || null === $b ) {
				return null;
			}
			$white = array( 1.0, 1.0, 1.0, 1.0 );
			$page  = self::parse( self::PAGE, $vars, $dark );
			$b     = self::over( $b, null === $page ? $white : self::over( $page, $white ) );
			$f     = self::over( $f, $b );
			$out[] = array(
				'fg'    => self::hex( $f ),
				'bg'    => self::hex( $b ),
				'ratio' => floor( self::ratio( $f, $b ) * 100 + 1e-9 ) / 100,
				'apca'  => round( self::apca( $f, $b ) ),
			);
		}
		return $out;
	}

	/**
	 * One pair in one mode: the live ratio of the custom fix (a colour the person chose).
	 *
	 * @param string     $fg   Text colour.
	 * @param string     $bg   Background.
	 * @param float      $min  Ratio needed.
	 * @param bool       $dark Measure the dark side of light-dark().
	 * @param array|null $vars Variables (built here when null).
	 * @return array|null { fg, bg, ratio, apca, pass }
	 */
	public static function pair( $fg, $bg, $min, $dark = false, $vars = null ) {
		$m = self::measure( $fg, $bg, null === $vars ? self::variables() : $vars, array( (bool) $dark ) );
		return $m ? $m[0] + array( 'pass' => $m[0]['ratio'] >= $min ) : null;
	}

	/**
	 * #rrggbb of a colour.
	 *
	 * @param array $c Colour.
	 * @return string
	 */
	private static function hex( $c ) {
		return sprintf( '#%02x%02x%02x', (int) round( $c[0] * 255 ), (int) round( $c[1] * 255 ), (int) round( $c[2] * 255 ) );
	}

	/**
	 * Does a measured pair reach $min in every mode?
	 *
	 * @param array $m   measure() result.
	 * @param float $min Ratio needed.
	 * @return bool
	 */
	private static function passes( $m, $min ) {
		foreach ( $m as $x ) {
			if ( $x['ratio'] < $min ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The report the Colors screen shows.
	 *
	 * @return array { dark: bool, rows: [ pair + modes, pass, fix ], fail: int, skipped: string[] }
	 */
	public static function report() {
		$vars    = self::variables();
		$sch     = Scheme::settings();
		$modes   = 'auto' === $sch['dark_mode'] ? array( false, true ) : array( false );
		$rows    = array();
		$skipped = array();
		$fail    = 0;
		$pairs   = self::pairs();
		$fams    = array();
		foreach ( Palette::settings()['families'] as $name => $f ) {
			if ( '' !== $f['hex'] && $f['on'] ) {
				$fams[] = $name;
			}
		}
		foreach ( $pairs as $p ) {
			$m = self::measure( $p['fg'], $p['bg'], $vars, $modes );
			if ( null === $m ) {
				$skipped[] = $p['where'] . ' ' . $p['what'];
				continue;
			}
			$p['modes'] = $m;
			$p['pass']  = self::passes( $m, $p['min'] );
			$p['fix']   = null;
			if ( ! $p['pass'] ) {
				$fail++;
				// A fix changes one setting: every pair using that setting must pass with it.
				$same     = array_filter( $pairs, function ( $q ) use ( $p ) {
					return $q['set'] === $p['set'];
				} );
				$p['fix'] = self::fix( $p, $same, $vars, $modes, $fams );
			}
			$rows[] = $p;
		}
		return array( 'dark' => count( $modes ) > 1, 'rows' => $rows, 'fail' => $fail, 'skipped' => $skipped );
	}

	/**
	 * The nearest passing colour token for one text colour on one background (the
	 * builder's page check: a colour measured on the canvas, in the mode it shows).
	 *
	 * @param string $fg   Text colour (a var() or a colour).
	 * @param string $bg   Background (a var() or a colour).
	 * @param float  $min  Ratio needed.
	 * @param bool   $dark Measure the dark side of light-dark().
	 * @return array|null { value, modes }
	 */
	public static function nearest( $fg, $bg, $min, $dark = false, &$ctx = null ) {
		if ( ! is_array( $ctx ) ) { // a caller with many pairs passes the same $ctx to build this once.
			$fams = array();
			foreach ( Palette::settings()['families'] as $name => $f ) {
				if ( '' !== $f['hex'] && $f['on'] ) {
					$fams[] = $name;
				}
			}
			$ctx = array( self::variables(), $fams );
		}
		$p = array( 'fg' => (string) $fg, 'bg' => (string) $bg, 'min' => (float) $min );
		return self::fix( $p, array( $p ), $ctx[0], array( (bool) $dark ), $ctx[1] );
	}

	/**
	 * The colour family a value comes from (following its variables), or ''.
	 *
	 * @param string $v    CSS value.
	 * @param array  $vars Variables.
	 * @param array  $fams Family names.
	 * @return string
	 */
	private static function family_of( $v, $vars, $fams ) {
		for ( $i = 0; $i < 12 && preg_match( '/^var\(\s*--([a-z0-9-]+)\s*(?:,(.*))?\)$/is', trim( (string) $v ), $m ); $i++ ) {
			foreach ( $fams as $f ) {
				if ( preg_match( '/^' . preg_quote( $f, '/' ) . '(?:-(?:ultra-light|light|semi-light|semi-dark|dark|ultra-dark|hover))?(?:-ref)?$/', $m[1] ) ) {
					return $f;
				}
			}
			if ( isset( $vars[ $m[1] ] ) ) {
				$v  = $vars[ $m[1] ];
				$ld = Ai::light_dark( $v );
				$v  = $ld ? $ld[0] : $v;
			} elseif ( isset( $m[2] ) ) {
				$v = $m[2];
			} else {
				break;
			}
		}
		return '';
	}

	/**
	 * The nearest colour token (by OKLab distance of the drawn colour) that makes a failing pair
	 * pass in every mode: a shade of the text's or the background's family,
	 * its fixed light value (-ref, the same in dark mode), or the text roles.
	 *
	 * @param array $p     Pair.
	 * @param array $same  Pairs using the same setting ($p included).
	 * @param array $vars  Variables.
	 * @param array $modes Modes.
	 * @param array $fams  Colour families that are on.
	 * @return array|null { value, modes }
	 */
	private static function fix( $p, $same, $vars, $modes, $fams ) {
		$names = array();
		foreach ( array_unique( array_filter( array( self::family_of( $p['fg'], $vars, $fams ), self::family_of( $p['bg'], $vars, $fams ) ) ) ) as $f ) {
			foreach ( array( '', '-ref' ) as $ref ) {
				foreach ( self::STEPS as $sh ) {
					$names[] = $f . ( '' === $sh ? '' : "-$sh" ) . $ref;
				}
			}
		}
		$names = array_merge( $names, array( 'text-dark', 'text-light', 'white', 'black' ) );
		$cur   = self::measure( $p['fg'], $p['bg'], $vars, $modes );
		if ( null === $cur ) {
			return null;
		}
		$best  = null;
		foreach ( array_unique( $names ) as $n ) {
			$v = "var(--$n)";
			if ( ! isset( $vars[ $n ] ) || $v === $p['fg'] ) {
				continue;
			}
			$m = self::measure( $v, $p['bg'], $vars, $modes );
			if ( null === $m || ! self::passes( $m, $p['min'] ) ) {
				continue;
			}
			foreach ( $same as $q ) {
				$qm = self::measure( $v, $q['bg'], $vars, $modes );
				if ( null === $qm || ! self::passes( $qm, $q['min'] ) ) {
					continue 2;
				}
			}
			// OKLab distance of the colours as drawn (alpha applied), summed over modes: perceptual, and muted text stays muted.
			$d = 0.0;
			foreach ( $m as $i => $x ) {
				$d += self::lab_distance( $cur[ $i ]['fg'], $x['fg'] );
			}
			if ( null === $best || $d < $best[0] ) {
				$best = array( $d, $v, $m );
			}
		}
		return $best ? array( 'value' => $best[1], 'modes' => $best[2] ) : null;
	}

	/**
	 * OKLab distance between two #rrggbb colours.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 * @return float
	 */
	private static function lab_distance( $a, $b ) {
		$lab = function ( $h ) {
			list( $r, $g, $bl ) = sscanf( $h, '#%2x%2x%2x' );
			list( $L, $C, $H )  = Color_Engine::rgb_to_oklch( $r, $g, $bl );
			return array( $L, $C * cos( deg2rad( $H ) ), $C * sin( deg2rad( $H ) ) );
		};
		$x = $lab( $a );
		$y = $lab( $b );
		return sqrt( pow( $x[0] - $y[0], 2 ) + pow( $x[1] - $y[1], 2 ) + pow( $x[2] - $y[2], 2 ) );
	}
}
