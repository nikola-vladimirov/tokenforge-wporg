<?php
/**
 * Old block editor preset names keep working when TokenForge replaces the presets.
 *
 * Replace mode removes the theme's presets, but posts and the theme's own styles
 * still use their names (has-contrast-color, var(--wp--preset--color--contrast)).
 * This writes those variables and classes back, pointing each old name at the
 * nearest TokenForge token, plus the tf- names the earlier TokenForge palette used.
 * Nothing is stored: the CSS is built from the theme files and the current tokens.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy preset mapping.
 */
class Block_Legacy {

	/**
	 * Preset type => [ theme.json path, css variable part, classes ( suffix => property ) ].
	 */
	const TYPES = array(
		'color'       => array(
			array( 'color', 'palette' ),
			'color',
			array(
				'color'            => 'color',
				'background-color' => 'background-color',
				'border-color'     => 'border-color',
			),
		),
		'font-size'   => array( array( 'typography', 'fontSizes' ), 'font-size', array( 'font-size' => 'font-size' ) ),
		'font-family' => array( array( 'typography', 'fontFamilies' ), 'font-family', array( 'font-family' => 'font-family' ) ),
		'spacing'     => array( array( 'spacing', 'spacingSizes' ), 'spacing', array() ),
		'shadow'      => array( array( 'shadow', 'presets' ), 'shadow', array() ),
	);

	/**
	 * Per-request cache of the theme presets.
	 *
	 * @var array|null
	 */
	private static $theme = null;

	/**
	 * The theme's own presets, read from its theme.json files (child first, then
	 * parent) and the classic theme supports, without the theme.json filters, so
	 * reading them never runs TokenForge's own preset filter.
	 * shortcut: style variations and per-block presets are not read; add them if a theme maps them.
	 *
	 * @return array type => [ slug => value ]
	 */
	public static function theme_presets() {
		if ( null !== self::$theme ) {
			return self::$theme;
		}
		$out = array_fill_keys( array_keys( self::TYPES ), array() );
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$file = $dir . '/theme.json';
			$json = is_readable( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;
			if ( ! is_array( $json ) || empty( $json['settings'] ) ) {
				continue;
			}
			foreach ( self::TYPES as $type => $t ) {
				$list = isset( $json['settings'][ $t[0][0] ][ $t[0][1] ] ) ? $json['settings'][ $t[0][0] ][ $t[0][1] ] : array();
				foreach ( (array) $list as $p ) {
					$slug = isset( $p['slug'] ) ? _wp_to_kebab_case( (string) $p['slug'] ) : '';
					if ( '' !== $slug && ! isset( $out[ $type ][ $slug ] ) ) { // the child theme wins.
						$out[ $type ][ $slug ] = self::preset_value( $type, $p );
					}
				}
			}
		}
		// Classic themes: add_theme_support( 'editor-color-palette' / 'editor-font-sizes' ).
		foreach ( array(
			'color'     => 'editor-color-palette',
			'font-size' => 'editor-font-sizes',
		) as $type => $support ) {
			$list = get_theme_support( $support );
			$list = is_array( $list ) && isset( $list[0] ) && is_array( $list[0] ) ? $list[0] : array();
			foreach ( $list as $p ) {
				$slug = isset( $p['slug'] ) ? _wp_to_kebab_case( (string) $p['slug'] ) : '';
				if ( '' === $slug || isset( $out[ $type ][ $slug ] ) ) {
					continue;
				}
				if ( 'color' === $type ) {
					$out[ $type ][ $slug ] = isset( $p['color'] ) ? (string) $p['color'] : '';
				} else {
					$size                  = isset( $p['size'] ) ? $p['size'] : '';
					$out[ $type ][ $slug ] = is_numeric( $size ) ? $size . 'px' : (string) $size; // classic sizes are px numbers.
				}
			}
		}
		self::$theme = $out;
		return $out;
	}

	/**
	 * Forget the cached theme presets (tests, a theme switch within one request).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$theme = null;
	}

	/**
	 * A preset's value as CSS.
	 *
	 * @param string $type Preset type.
	 * @param array  $p    Preset.
	 * @return string
	 */
	private static function preset_value( $type, $p ) {
		$key = array(
			'color'       => 'color',
			'font-family' => 'fontFamily',
			'shadow'      => 'shadow',
		);
		if ( isset( $key[ $type ] ) ) {
			return isset( $p[ $key[ $type ] ] ) ? (string) $p[ $key[ $type ] ] : '';
		}
		// A fluid font size grows to its max: compare on that.
		if ( 'font-size' === $type && isset( $p['fluid'] ) && is_array( $p['fluid'] ) && ! empty( $p['fluid']['max'] ) ) {
			return (string) $p['fluid']['max'];
		}
		return isset( $p['size'] ) ? (string) $p['size'] : '';
	}

	/**
	 * The largest length in a CSS value, in px (clamp() and min() give their max), or null.
	 *
	 * @param string $v CSS value.
	 * @return float|null
	 */
	public static function px( $v ) {
		if ( ! preg_match_all( '/(-?[\d.]+)(px|rem|em)\b/i', (string) $v, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$max = null;
		foreach ( $m as $x ) {
			$n   = (float) $x[1] * ( 'px' === strtolower( $x[2] ) ? 1 : 16 );
			$max = null === $max ? $n : max( $max, $n );
		}
		return $max;
	}

	/**
	 * Map each theme preset that TokenForge does not provide to a TokenForge value.
	 *
	 * @param array $theme type => [ slug => value ] (theme_presets()).
	 * @param array $ours  type => [ slug, ... ] TokenForge's own presets already define.
	 * @param array $vars  TokenForge variables, name => value.
	 * @return array type => [ slug => CSS value ]
	 */
	public static function map( $theme, $ours, $vars ) {
		$out = array();
		foreach ( array_keys( self::TYPES ) as $type ) {
			$skip  = array_flip( isset( $ours[ $type ] ) ? (array) $ours[ $type ] : array() );
			$index = 0;
			foreach ( isset( $theme[ $type ] ) ? (array) $theme[ $type ] : array() as $slug => $value ) {
				if ( isset( $skip[ $slug ] ) ) {
					continue;
				}
				$to = self::nearest( $type, (string) $value, $vars, $index++ );
				// Nothing to map to: keep the theme's own value, so the look stays.
				$out[ $type ][ $slug ] = null === $to ? (string) $value : 'var(--' . $to . ')';
			}
		}
		return $out;
	}

	/**
	 * The TokenForge variable closest to a theme preset value, or null.
	 *
	 * @param string $type  Preset type.
	 * @param string $value Theme value.
	 * @param array  $vars  TokenForge variables.
	 * @param int    $index Position among the theme's presets of this type.
	 * @return string|null Variable name without the dashes.
	 */
	private static function nearest( $type, $value, $vars, $index ) {
		if ( 'font-family' === $type ) {
			// Code fonts stay; everything else follows the TokenForge text font.
			if ( preg_match( '/mono|code|courier|consol/i', $value ) ) {
				return null;
			}
			return isset( $vars['text-font-family'] ) ? 'text-font-family' : null;
		}
		if ( 'shadow' === $type ) {
			$name = 'shadow-' . ( $index + 1 );
			return isset( $vars[ $name ] ) ? $name : null;
		}
		if ( 'color' === $type ) {
			return self::nearest_color( $value, $vars );
		}
		$want = self::px( $value );
		if ( null === $want ) {
			return null;
		}
		$pattern = 'font-size' === $type ? '/^(text-(xs|s|m|l|xl|xxl)|h[1-6])$/' : '/^(section-)?space-(xs|s|m|l|xl|xxl)$/';
		$best    = null;
		$dist    = INF;
		foreach ( $vars as $name => $v ) {
			$px = preg_match( $pattern, $name ) ? self::px( $v ) : null;
			if ( null !== $px && abs( $px - $want ) < $dist ) {
				$dist = abs( $px - $want );
				$best = $name;
			}
		}
		return $best;
	}

	/**
	 * The palette colour closest to a CSS colour (OKLab distance, light side), or null.
	 *
	 * @param string $value CSS colour.
	 * @param array  $vars  TokenForge variables.
	 * @return string|null
	 */
	private static function nearest_color( $value, $vars ) {
		$want = Contrast::parse( $value, $vars );
		if ( ! $want || $want[3] < 1 ) {
			return null; // color-mix(), currentColor, transparent: not a plain colour.
		}
		$best = null;
		$dist = INF;
		foreach ( $vars as $name => $v ) {
			if ( ! preg_match( Blocks::PALETTE_NAME, $name ) ) {
				continue;
			}
			$c = Contrast::parse( $v, $vars );
			if ( $c && $c[3] >= 1 ) {
				$d = self::lab_distance( $want, $c );
				if ( $d < $dist ) {
					$dist = $d;
					$best = $name;
				}
			}
		}
		return $best;
	}

	/**
	 * OKLab distance of two sRGB colours (Contrast::parse() arrays, 0-1).
	 *
	 * @param array $a Colour.
	 * @param array $b Colour.
	 * @return float
	 */
	private static function lab_distance( $a, $b ) {
		$lab = function ( $c ) {
			list( $l, $ch, $h ) = Color_Engine::rgb_to_oklch( $c[0] * 255, $c[1] * 255, $c[2] * 255 );
			return array( $l, $ch * cos( deg2rad( $h ) ), $ch * sin( deg2rad( $h ) ) );
		};
		$x   = $lab( $a );
		$y   = $lab( $b );
		return sqrt( pow( $x[0] - $y[0], 2 ) + pow( $x[1] - $y[1], 2 ) + pow( $x[2] - $y[2], 2 ) );
	}

	/**
	 * The CSS: variables on :root and the classes core would have written, for
	 * the theme's mapped presets (replace mode with the mapping on) and the tf-
	 * colour names of the earlier palette (replace mode only: "add" still has them as presets).
	 *
	 * @param array|null $settings Settings (default: stored).
	 * @param array|null $ours     type => slugs TokenForge provides (default: Blocks::preset_slugs()).
	 * @param array|null $theme    Theme presets (default: theme_presets()).
	 * @param array|null $vars     Variables (default: Contrast::variables()).
	 * @return string
	 */
	public static function css( $settings = null, $ours = null, $theme = null, $vars = null ) {
		$s    = null === $settings ? Settings::get_instance()->get_settings() : $settings;
		$mode = isset( $s['gutenberg_palette'] ) ? $s['gutenberg_palette'] : 'off';
		$vars = null === $vars ? Contrast::variables() : $vars;
		$map  = array();
		if ( 'replace' === $mode && ! empty( $s['block_map_legacy'] ) ) {
			if ( null === $ours ) {
				$ours = method_exists( Blocks::class, 'preset_slugs' ) ? Blocks::preset_slugs( $mode ) : array();
			}
			$map = self::map( null === $theme ? self::theme_presets() : $theme, $ours, $vars );
		}
		if ( 'replace' === $mode ) {
			foreach ( Blocks::palette_entries() as $e ) {
				if ( ! isset( $map['color'][ $e['slug'] ] ) ) {
					$map['color'][ $e['slug'] ] = $e['color'];
				}
			}
		}
		$root  = '';
		$rules = '';
		foreach ( $map as $type => $list ) {
			foreach ( $list as $slug => $value ) {
				if ( '' === $value || preg_match( '/[;{}<>]/', $value ) ) {
					continue; // never let a theme value break out of its declaration.
				}
				$var   = '--wp--preset--' . self::TYPES[ $type ][1] . '--' . $slug;
				$root .= $var . ':' . $value . ';';
				foreach ( self::TYPES[ $type ][2] as $suffix => $prop ) {
					$rules .= '.has-' . $slug . '-' . $suffix . '{' . $prop . ':var(' . $var . ') !important}';
				}
			}
		}
		return '' === $root ? '' : ':root{' . $root . '}' . $rules;
	}
}
