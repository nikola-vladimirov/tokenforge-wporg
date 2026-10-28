<?php
/**
 * Extras: radius, borders & dividers, shadows, icons, header height and the
 * inverted-radius framework.
 *
 * Generates the radius scale + border/divider variables, a 5-slot box-shadow
 * elevation scale (+ text/drop shadows), an icon system ([data-icon] / .icon
 * with sizes, boxed/plain styles, hover colours and icon lists), and their
 * utility classes. Variable-first (like ACSS 4.x) with convenience utilities
 * on top. build() returns the icon CSS separately so it can be layered.
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Border/radius + shadow + icon tokens & utilities.
 */
class Extras {

	const OPTION = 'tokenforge_extras';

	/** Icon sizes (small → large). */
	const ICON_SIZES = array( 'xs', 's', 'm', 'l', 'xl', '2xl' );

	/** Inverted-radius corner positions. */
	const IR_POSITIONS = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'top-outside-left', 'top-outside-right', 'bottom-outside-left', 'bottom-outside-right', 'top-left-outside', 'top-right-outside', 'bottom-left-outside', 'bottom-right-outside', 'top-outside-left-outside', 'top-outside-right-outside', 'bottom-outside-left-outside', 'bottom-outside-right-outside' );

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'extras', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'enable_radius' => true,
			'enable_border' => true,
			'enable_shadow' => true,
			'enable_icon'   => true,
			'radius_xs'     => '',        // empty = derived (s ÷ ratio)
			'radius_s'      => '0.25rem',
			'radius_m'      => '0.5rem',
			'radius_l'      => '1rem',
			'radius_xl'     => '1.5rem',
			'radius_xxl'    => '',        // empty = derived (xl × ratio)
			'border_size'   => '1px',
			'border_style'  => 'solid',
			'border_color'  => 'var(--base-ultra-light, #e5e7eb)', // dark-scheme border (on light backgrounds)
			'border_color_light' => 'rgb(255 255 255 / .2)',      // for dark backgrounds
			// Dividers.
			'divider_size'        => '1px',
			'divider_style'       => 'solid',
			'divider_color_dark'  => 'var(--border-color-dark)',
			'divider_color_light' => 'var(--border-color-light)',
			'divider_gap'         => 'var(--content-gap)',
			'shadow_color'  => '15 23 42', // rgb channels used in rgba()
			// Icons.
			'icon_xs'       => '0.75rem',
			'icon_s'        => '1rem',
			'icon_m'        => '1.5rem',
			'icon_l'        => '2rem',
			'icon_xl'       => '4rem',
			'icon_2xl'      => '8rem',
			'icon_padding'  => '.35em',
			'icon_padding_xs' => '', 'icon_padding_s' => '', 'icon_padding_m' => '', 'icon_padding_l' => '', 'icon_padding_xl' => '', 'icon_padding_2xl' => '',
			'icon_color'           => '',
			'icon_color_hover'     => 'var(--primary)',
			'icon_background'      => 'var(--neutral-ultra-light, #eef1f5)',
			'icon_background_hover' => 'var(--neutral-light)',
			'icon_border_width'    => '0',
			'icon_border_style'    => 'solid',
			'icon_border_color'    => 'transparent',
			'icon_border_color_hover' => 'inherit',
			'icon_radius'          => 'var(--radius-m)',
			'icon_scheme'          => '',   // light | dark: colour scheme of icons
			'icon_default_style'   => 'plain', // plain | boxed
			'icon_list_gap'        => '1em',
			'icon_list_icon_size'  => '1em',
			'boxed_list_icons'     => false,
			'header_height' => '0px',   // desktop (or the only value)
			'header_height_min' => '',  // mobile, when both are px, the height is fluid
			// Custom box shadows (empty = generated from the shadow colour).
			'shadow_1'      => '',
			'shadow_2'      => '',
			'shadow_3'      => '',
			'shadow_4'      => '',
			'shadow_5'      => '',
			// Text + drop shadows (filter).
			'enable_text_shadow' => true,
			'text_shadow_1' => '0 1px 2px rgb(0 0 0 / .2)',
			'text_shadow_2' => '0 2px 4px rgb(0 0 0 / .2)',
			'text_shadow_3' => '0 4px 8px rgb(0 0 0 / .25)',
			'enable_drop_shadow' => true,
			'drop_shadow_1' => '0 1px 2px rgb(0 0 0 / .15)',
			'drop_shadow_2' => '0 4px 6px rgb(0 0 0 / .15)',
			'drop_shadow_3' => '0 10px 15px rgb(0 0 0 / .2)',
			// Round images (and other selectors) automatically with --radius.
			'auto_radius'   => false,
			'auto_radius_selectors' => ':where(img):not(header img)',
			// Inverted (concave) corners via data-inverted-radius-* attributes.
			'inverted_radius' => false,
			// Corner ribbons (.ribbon + .ribbon--top-left/-top-right or data-ribbon-position).
			// Each value is the fallback of a --ribbon-* variable you can set per element.
			'ribbons'           => false,
			'ribbon_background' => 'var(--primary)',
			'ribbon_text_color' => 'var(--white)',
			'ribbon_text_size'  => 'var(--text-s)',
			'ribbon_padding'    => '0.5em 1em',
			'ribbon_width'      => '300px',
			'ribbon_offset'     => '-20px',
			'ribbon_shadow'     => '0 5px 10px color-mix(in oklch, var(--neutral) 10%, transparent)',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return Modules::apply( 'extras', wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d     = self::defaults();
		$clean = array();
		foreach ( $d as $k => $v ) {
			if ( is_bool( $v ) ) {
				$clean[ $k ] = ! empty( $in[ $k ] );
			} elseif ( 'auto_radius_selectors' === $k ) {
				$clean[ $k ] = isset( $in[ $k ] ) ? trim( str_replace( array( ';', '{', '}', '<' ), '', sanitize_text_field( $in[ $k ] ) ) ) : $v;
			} else {
				$clean[ $k ] = isset( $in[ $k ] ) ? trim( str_replace( array( ';', '{', '}', '<', '>' ), '', sanitize_text_field( $in[ $k ] ) ) ) : $v;
			}
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * rgba() from "r g b" channels + alpha.
	 *
	 * @param string $rgb   "r g b".
	 * @param string $alpha Alpha 0-1.
	 * @return string
	 */
	private static function rgba( $rgb, $alpha ) {
		$parts = preg_split( '/[\s,]+/', trim( $rgb ) );
		$parts = array_slice( array_pad( $parts, 3, '0' ), 0, 3 );
		return 'rgba(' . implode( ',', $parts ) . ',' . $alpha . ')';
	}

	/**
	 * Variables to emit in :root and sync to Bricks.
	 *
	 * @return array [{name,value,group,enabled}]
	 */
	public static function variables() {
		$s   = self::settings();
		$out = array();
		$add = function ( $n, $v, $g ) use ( &$out ) {
			if ( '' !== (string) $v ) {
				$out[] = array( 'name' => $n, 'value' => $v, 'group' => $g, 'enabled' => true );
			}
		};

		if ( $s['enable_radius'] ) {
			// Derived ends keep the step ratio: xs = s²/m, xxl = xl²/l (same units only).
			$extend = function ( $a, $b, $fallback ) {
				if ( preg_match( '/^([0-9.]+)(px|rem|em)$/', trim( $a ), $x ) && preg_match( '/^([0-9.]+)(px|rem|em)$/', trim( $b ), $y ) && $x[2] === $y[2] && (float) $y[1] > 0 ) {
					return rtrim( rtrim( sprintf( '%.4f', (float) $x[1] * (float) $x[1] / (float) $y[1] ), '0' ), '.' ) . $x[2];
				}
				return $fallback;
			};
			$add( 'radius-xs', '' !== $s['radius_xs'] ? $s['radius_xs'] : $extend( $s['radius_s'], $s['radius_m'], $s['radius_s'] ), 'radius' );
			$add( 'radius-s', $s['radius_s'], 'radius' );
			$add( 'radius-m', $s['radius_m'], 'radius' );
			$add( 'radius-l', $s['radius_l'], 'radius' );
			$add( 'radius-xl', $s['radius_xl'], 'radius' );
			$add( 'radius-xxl', '' !== $s['radius_xxl'] ? $s['radius_xxl'] : $extend( $s['radius_xl'], $s['radius_l'], $s['radius_xl'] ), 'radius' );
			$add( 'radius', 'var(--radius-m)', 'radius' );
			$add( 'radius-circle', '50vw', 'radius' );
			$add( 'radius-50', '50%', 'radius' );
			$add( 'radius-none', '0', 'radius' );
		}
		if ( $s['enable_border'] ) {
			$add( 'border-size', $s['border_size'], 'other' );
			$add( 'border-width', 'var(--border-size)', 'other' );
			$add( 'border-style', $s['border_style'], 'other' );
			$add( 'border-color', $s['border_color'], 'other' );
			$add( 'border-color-dark', 'var(--border-color)', 'other' );
			$add( 'border-color-light', $s['border_color_light'], 'other' );
			$add( 'border', 'var(--border-size) var(--border-style) var(--border-color)', 'other' );
			$add( 'border-dark', 'var(--border-size) var(--border-style) var(--border-color-dark)', 'other' );
			$add( 'border-light', 'var(--border-size) var(--border-style) var(--border-color-light)', 'other' );
			$add( 'divider-size', $s['divider_size'], 'other' );
			$add( 'divider-style', $s['divider_style'], 'other' );
			$add( 'divider-color-dark', $s['divider_color_dark'], 'other' );
			$add( 'divider-color-light', $s['divider_color_light'], 'other' );
			$add( 'divider-gap', $s['divider_gap'], 'other' );
			$add( 'divider-inline-size', '100%', 'other' );
			$add( 'divider', 'var(--divider-size) var(--divider-style) var(--divider-color-dark)', 'other' );
			$add( 'divider-dark', 'var(--divider-size) var(--divider-style) var(--divider-color-dark)', 'other' );
			$add( 'divider-light', 'var(--divider-size) var(--divider-style) var(--divider-color-light)', 'other' );
		}
		if ( $s['enable_shadow'] ) {
			$c   = $s['shadow_color'];
			$gen = array(
				1 => '0 1px 2px ' . self::rgba( $c, '0.06' ),
				2 => '0 2px 4px ' . self::rgba( $c, '0.08' ),
				3 => '0 4px 10px ' . self::rgba( $c, '0.10' ),
				4 => '0 10px 24px ' . self::rgba( $c, '0.12' ),
				5 => '0 20px 48px ' . self::rgba( $c, '0.16' ),
			);
			foreach ( $gen as $i => $value ) {
				$add( 'shadow-' . $i, '' !== $s[ 'shadow_' . $i ] ? $s[ 'shadow_' . $i ] : $value, 'shadow' );
				$add( 'box-shadow-' . $i, 'var(--shadow-' . $i . ')', 'shadow' );
			}
			$add( 'shadow-m', 'var(--shadow-3)', 'shadow' );
		}
		if ( $s['enable_text_shadow'] ) {
			for ( $i = 1; $i <= 3; $i++ ) {
				$add( 'text-shadow-' . $i, $s[ 'text_shadow_' . $i ], 'shadow' );
			}
		}
		if ( $s['enable_drop_shadow'] ) {
			for ( $i = 1; $i <= 3; $i++ ) {
				if ( '' !== $s[ 'drop_shadow_' . $i ] ) {
					$add( 'drop-shadow-' . $i, 'drop-shadow(' . $s[ 'drop_shadow_' . $i ] . ')', 'shadow' );
				}
			}
		}
		if ( $s['enable_icon'] ) {
			foreach ( self::ICON_SIZES as $sz ) {
				$add( 'icon-size-' . $sz, $s[ 'icon_' . $sz ], 'other' );
				$add( 'icon-padding-' . $sz, '' !== $s[ 'icon_padding_' . $sz ] ? $s[ 'icon_padding_' . $sz ] : 'var(--icon-padding)', 'other' );
			}
			$add( 'icon-size', 'var(--icon-size-m)', 'other' );
			$add( 'icon-padding', $s['icon_padding'], 'other' );
			$add( 'icon-color', $s['icon_color'], 'color' );
			$add( 'icon-color-hover', $s['icon_color_hover'], 'color' );
			$add( 'icon-background', $s['icon_background'], 'color' );
			$add( 'icon-background-hover', $s['icon_background_hover'], 'color' );
			$add( 'icon-border-width', $s['icon_border_width'], 'other' );
			$add( 'icon-border-style', $s['icon_border_style'], 'other' );
			$add( 'icon-border-color', $s['icon_border_color'], 'other' );
			$add( 'icon-border-color-hover', $s['icon_border_color_hover'], 'other' );
			$add( 'icon-radius', $s['icon_radius'], 'radius' );
			$add( 'icon-scheme', $s['icon_scheme'], 'other' );
			$add( 'icon-list-gap', $s['icon_list_gap'], 'space' );
			$add( 'icon-list-icon-size', $s['icon_list_icon_size'], 'other' );
		}
		// Header height (sticky offsets, scroll offsets …). Fluid when both ends are px.
		$hmax = Width::to_px( $s['header_height'] );
		$hmin = '' === $s['header_height_min'] ? null : Width::to_px( $s['header_height_min'] );
		if ( null !== $hmax && null !== $hmin && $hmin !== $hmax ) {
			$vw = Scale_Engine::settings();
			$add( 'header-height', Scale_Engine::fluid( $hmin, $hmax, (float) $vw['min_vw'], (float) $vw['max_vw'] ), 'other' );
		} else {
			$add( 'header-height', $s['header_height'], 'other' );
		}
		return $out;
	}

	/**
	 * Utility CSS + class names (icons returned separately for layering).
	 *
	 * @return array [ 'css' => string, 'icons' => string, 'classes' => string[] ]
	 */
	public static function build() {
		$s       = self::settings();
		$css     = array();
		$icons   = array();
		$classes = array();

		if ( $s['enable_radius'] ) {
			foreach ( array( 'xs', 's', 'm', 'l', 'xl', 'xxl' ) as $step ) {
				$css[]     = ".radius-$step{border-radius:var(--radius-$step)}";
				$classes[] = "radius-$step";
			}
			$css[]     = '.radius-full,.radius-circle{border-radius:9999px}';
			$classes[] = 'radius-full';
			$classes[] = 'radius-circle';
			// Double-dash names that sites built with other frameworks already use.
			foreach ( array( 'xs', 's', 'm', 'l', 'xl', 'xxl', '50', 'circle', 'none' ) as $step ) {
				$css[] = ".radius--$step,.rounded--$step{border-radius:var(--radius-$step)}";
				array_push( $classes, "radius--$step", "rounded--$step" );
			}
		}
		if ( $s['enable_border'] ) {
			$css[] = '.border{border:var(--border)}.border-top{border-top:var(--border)}.border-bottom{border-bottom:var(--border)}';
			$css[] = '.border--light{border:var(--border-light)}.border--dark{border:var(--border-dark)}';
			$css[] = '.divider{border:0;border-top:var(--divider);block-size:0;inline-size:var(--divider-inline-size, 100%);margin-block:var(--divider-gap, var(--space-m))}';
			$css[] = '.divider--light{border-top:var(--divider-light)}.divider--dark{border-top:var(--divider-dark)}';
			array_push( $classes, 'border', 'border-top', 'border-bottom', 'border--light', 'border--dark', 'divider', 'divider--light', 'divider--dark' );
		}
		if ( $s['enable_shadow'] ) {
			for ( $i = 1; $i <= 5; $i++ ) {
				$css[]     = ".shadow-$i{box-shadow:var(--shadow-$i)}";
				$classes[] = "shadow-$i";
			}
			$css[]     = '.shadow-none{box-shadow:none}';
			$classes[] = 'shadow-none';
		}
		if ( $s['enable_text_shadow'] ) {
			for ( $i = 1; $i <= 3; $i++ ) {
				if ( '' !== $s[ 'text_shadow_' . $i ] ) {
					$css[]     = ".text-shadow--$i{text-shadow:var(--text-shadow-$i)}";
					$classes[] = "text-shadow--$i";
				}
			}
		}
		if ( $s['enable_drop_shadow'] ) {
			for ( $i = 1; $i <= 3; $i++ ) {
				if ( '' !== $s[ 'drop_shadow_' . $i ] ) {
					$css[]     = ".drop-shadow--$i{filter:var(--drop-shadow-$i)}";
					$classes[] = "drop-shadow--$i";
				}
			}
		}
		if ( $s['auto_radius'] && '' !== trim( $s['auto_radius_selectors'] ) ) {
			// Selector lists may arrive quoted (ACSS stores "sel", "sel"), unquote.
			$sel   = trim( str_replace( array( '"', "'" ), '', $s['auto_radius_selectors'] ), " ,\n" );
			$css[] = ':where(' . $sel . '){border-radius:var(--radius)}';
		}

		if ( $s['enable_icon'] ) {
			// An icon is the svg/<i> itself (or a link) with [data-icon], or a .icon wrapper.
			$icon  = '[data-icon]:is(svg,i,a),.icon';
			$boxed = 'padding:var(--icon-padding, 12px);border-width:var(--icon-border-width, 0);border-style:var(--icon-border-style, solid);border-radius:var(--icon-radius, var(--radius));background:var(--icon-background, var(--neutral-ultra-light));border-color:var(--icon-border-color, transparent)';
			$base  = 'color-scheme:var(--icon-scheme, normal);display:flex;flex-shrink:0;align-items:center;justify-content:center;box-sizing:content-box;color:var(--icon-color, currentColor);font-size:var(--icon-size);'
				. 'block-size:var(--icon-height, var(--icon-size, 24px));inline-size:var(--icon-width, var(--icon-size, 24px));transition:var(--icon-transition, var(--transition, none));box-shadow:var(--icon-shadow, none);overflow:visible';
			$icons[] = $icon . '{' . $base . ( 'boxed' === $s['icon_default_style'] ? ';' . $boxed : '' ) . '}';
			$icons[] = '[data-icon]:is(i)::before{inline-size:100%;block-size:100%;text-align:center;font-size:var(--icon-size)}';
			$icons[] = '[data-icon].brxe-icon{font-size:var(--icon-size)}';
			$icons[] = '[data-icon] svg,.icon svg,svg.icon{inline-size:100%;block-size:100%;color:var(--icon-color, currentColor);fill:currentColor}';
			$icons[] = '[data-icon]:is(a):hover,a:hover :is([data-icon],.icon),[data-icon] [data-icon-hover]:hover{--icon-background:var(--icon-background-hover);--icon-color:var(--icon-color-hover);--icon-border-color:var(--icon-border-color-hover)}';
			foreach ( self::ICON_SIZES as $sz ) {
				$icons[]   = ".icon--$sz,[data-icon-size=\"$sz\"]{--icon-size:var(--icon-size-$sz);--icon-padding:var(--icon-padding-$sz)}";
				$classes[] = "icon--$sz";
			}
			$icons[] = '.icon--boxed,[data-icon-style="boxed"]{' . $boxed . '}';
			$icons[] = '.icon--plain,[data-icon-style="plain"]{--icon-background:transparent;--icon-border-width:0;--icon-padding:0;padding:0;background:none;border-width:0}';
			// Icon lists: a column of items, each with a small icon.
			$list    = ':is([data-icon-list],.icon-list)';
			$icons[] = $list . '{display:flex;flex-direction:column;gap:var(--icon-list-gap, 1em);list-style:none;padding:0}';
			$icons[] = $list . ' li{display:flex;align-items:flex-start;gap:var(--space-xs, .5em)}';
			$icons[] = $list . ' :where(svg,i){--icon-size:var(--icon-list-icon-size, 1em);position:relative;translate:var(--icon-inline-offset, 0) var(--icon-block-offset, 0);flex-shrink:0;'
				. 'display:flex;align-items:center;justify-content:center;box-sizing:content-box;color:var(--icon-color, currentColor);font-size:var(--icon-size);block-size:var(--icon-size);inline-size:var(--icon-size)'
				. ( ! empty( $s['boxed_list_icons'] ) ? ';' . $boxed : '' ) . '}';
			$icons[] = $list . ' a:hover,:where(a,button):has(>svg,>i):hover{--icon-color:var(--icon-color-hover, var(--primary));--icon-background:var(--icon-background-hover, var(--primary-hover))}';
			array_push( $classes, 'icon', 'icon--boxed', 'icon--plain', 'icon-list' );
		}

		if ( $s['inverted_radius'] ) {
			// Concave corners: an absolutely positioned tab whose pseudo-elements draw
			// the inverted curve with a box-shadow in the parent's background colour.
			$r   = 'var(--radius)';
			$neg = 'calc(var(--radius) * -1)';
			$pos = array(
				'top-left' => "0 auto auto 0", 'top-right' => "0 0 auto auto", 'bottom-left' => "auto auto 0 0", 'bottom-right' => "auto 0 0 auto",
				'top-left-outside' => "0 auto auto $neg", 'top-right-outside' => "0 $neg auto auto", 'bottom-left-outside' => "auto 0 0 $neg", 'bottom-right-outside' => "auto $neg 0 auto",
				'top-outside-left' => "$neg auto auto 0", 'top-outside-right' => "$neg 0 auto auto", 'bottom-outside-left' => "auto 0 $neg 0", 'bottom-outside-right' => "auto 0 $neg auto",
				'top-outside-left-outside' => "$neg auto auto $neg", 'top-outside-right-outside' => "$neg $neg auto auto", 'bottom-outside-left-outside' => "auto auto $neg $neg", 'bottom-outside-right-outside' => "auto $neg $neg auto",
			);
			$slice  = array( 'top-left' => "$r 0 0 0", 'top-right' => "0 $r 0 0", 'bottom-right' => "0 0 $r 0", 'bottom-left' => "0 0 0 $r" );
			$bg     = 'var(--bg, var(--white))';
			$shadow = array( 'bottom-right' => "$r $r 0 $r $bg", 'bottom-left' => "$neg $r 0 $r $bg", 'top-right' => "$r $neg 0 $r $bg", 'top-left' => "$neg $neg 0 $r $bg" );
			$vars   = array();
			foreach ( $slice as $k => $v ) {
				$vars[] = "--slice-$k:$v";
			}
			foreach ( $pos as $k => $v ) {
				$vars[] = "--position-$k:$v";
			}
			foreach ( $shadow as $k => $v ) {
				$vars[] = "--shadow-$k:$v";
			}
			$css[] = '[data-inverted-radius-1-position],[data-inverted-radius-2-position]{position:var(--inverted-radius-parent-position, absolute);background:' . $bg . ';z-index:1;isolation:isolate;' . implode( ';', $vars ) . ';--inverted-radius-1-shadow:var(--shadow-top-left);--inverted-radius-2-shadow:var(--shadow-top-left)}';
			foreach ( array( 1 => 'before', 2 => 'after' ) as $n => $pseudo ) {
				foreach ( array_keys( $pos ) as $k ) {
					$css[] = "[data-inverted-radius-$n-position=\"$k\"]{--inverted-radius-$n-position:var(--position-$k)}";
				}
				foreach ( array_keys( $slice ) as $k ) {
					$css[] = "[data-inverted-radius-$n-slice=\"$k\"]{--inverted-radius-$n-slice:var(--slice-$k);--inverted-radius-$n-shadow:var(--shadow-$k)}";
				}
				$css[] = "[data-inverted-radius-$n-position]::$pseudo{content:\"\";position:absolute;inset:var(--inverted-radius-$n-position);border-radius:var(--inverted-radius-$n-slice);box-shadow:var(--inverted-radius-$n-shadow);inline-size:$r;block-size:$r;aspect-ratio:1;z-index:-1;background:transparent}";
			}
		}

		if ( $s['ribbons'] ) {
			// A band across a corner: rotated 45° about its bottom corner, then moved
			// down by (width × sin 45° − its own height) so its lower edge cuts the
			// corner at the same distance on both sides. The parent clips it.
			$w     = 'var(--ribbon-width, ' . $s['ribbon_width'] . ')';
			$off   = 'var(--ribbon-offset, ' . $s['ribbon_offset'] . ')';
			$css[] = ':has(> .ribbon){position:relative;overflow:hidden}';
			$css[] = '.ribbon{position:absolute;display:block;box-sizing:border-box;inline-size:' . $w . ';padding:var(--ribbon-padding, ' . $s['ribbon_padding'] . ');background-color:var(--ribbon-background-color, ' . $s['ribbon_background'] . ');'
				. 'color:var(--ribbon-text-color, ' . $s['ribbon_text_color'] . ');font-size:var(--ribbon-text-size, ' . $s['ribbon_text_size'] . ');text-align:center;box-shadow:var(--ribbon-shadow, ' . $s['ribbon_shadow'] . ')}';
			$css[] = '.ribbon--top-right,.ribbon[data-ribbon-position="top-right"]{inset-block-start:' . $off . ';inset-inline-end:' . $off . ';transform-origin:var(--ribbon-transform-origin, bottom right);'
				. 'transform:var(--ribbon-transform, translateY(calc(' . $w . ' * 0.70710678 - 100%)) rotate(45deg))}';
			$css[] = '.ribbon--top-left,.ribbon[data-ribbon-position="top-left"]{inset-block-start:' . $off . ';inset-inline-start:' . $off . ';transform-origin:var(--ribbon-transform-origin, bottom left);'
				. 'transform:var(--ribbon-transform, translateY(calc(' . $w . ' * 0.70710678 - 100%)) rotate(-45deg))}';
			if ( Platforms\Bricks::is_active() ) {
				$css[] = 'span.ribbon{inline-size:' . $w . '!important}'; // Bricks gives text spans width:auto
			}
			array_push( $classes, 'ribbon', 'ribbon--top-left', 'ribbon--top-right' );
		}

		return array(
			'css'     => "\n/* --- TokenForge extras (radius/border/shadow) --- */\n" . implode( "\n", $css ) . "\n",
			'icons'   => $icons ? "\n/* --- TokenForge icons --- */\n" . implode( "\n", $icons ) . "\n" : '',
			'classes' => $classes,
		);
	}
}
