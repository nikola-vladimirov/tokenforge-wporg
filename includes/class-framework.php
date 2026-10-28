<?php
/**
 * Utility-class framework generator.
 *
 * Original implementation. Turns the stored design tokens into a layer of
 * utility classes (color, spacing, typography) plus a static layout layer,
 * and reports the generated class names so they can be registered with Bricks
 * for autocomplete. Each group can be toggled in settings.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the utility CSS + class-name list from tokens.
 */
class Framework {

	const OPTION = 'tokenforge_framework';

	/**
	 * Channel suffixes that are not standalone colors.
	 *
	 * @var array
	 */
	const CHANNEL_SUFFIXES = array( '-hex', '-hsl', '-rgb', '-h', '-s', '-l', '-r', '-g', '-b' );

	/**
	 * Default framework toggles.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'framework', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'utilities_color'      => true,
			'utilities_spacing'    => true,
			'utilities_typography' => true,
			'utilities_layout'     => true,
			'register_with_bricks' => true,
			'prune_unused'         => false, // leave out utility classes the site does not use (after a scan)
			'prune_keep'           => '',    // class names (or prefixes ending in *) always kept
		);
	}

	/**
	 * Current framework settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return Modules::apply( 'framework', wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() ) );
	}

	/**
	 * Save framework settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d     = self::defaults();
		$clean = array();
		foreach ( $d as $k => $v ) {
			if ( is_string( $v ) ) {
				$clean[ $k ] = isset( $in[ $k ] ) && is_string( $in[ $k ] ) ? sanitize_textarea_field( $in[ $k ] ) : $v;
				continue;
			}
			$clean[ $k ] = ! empty( $in[ $k ] );
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Is a color token a real, usable color (not a channel fragment)? A token
	 * that points at another variable counts when that one is a colour (or is
	 * not known here), so --link-transition: var(--transition) makes no class.
	 *
	 * @param array $t    Token.
	 * @param array $vars name => value of the variables, to follow var(--x).
	 * @return bool
	 */
	public static function is_color_value( $t, $vars = array() ) {
		if ( 'color' !== $t['group'] ) {
			return false;
		}
		foreach ( self::CHANNEL_SUFFIXES as $suf ) {
			if ( substr( $t['name'], -strlen( $suf ) ) === $suf ) {
				return false;
			}
		}
		$v = trim( $t['value'] );
		if ( preg_match( '/^#([0-9a-f]{3,8})$/i', $v ) || preg_match( '/^(rgb|hsl)a?\(/i', $v ) ) {
			return true;
		}
		if ( 0 !== strpos( $v, 'var(' ) ) {
			return false;
		}
		// var(--x) alone (a fallback allowed): follow it to its value.
		for ( $depth = 0; $depth < 6 && preg_match( '/^var\(\s*--([a-z0-9-]+)\s*(?:,[^()]*)?\)$/i', $v, $m ); $depth++ ) {
			$name = strtolower( $m[1] );
			if ( ! isset( $vars[ $name ] ) ) {
				return true; // set somewhere else (a scheme colour, the theme): keep the class
			}
			$v = trim( $vars[ $name ] );
		}
		return 0 !== strpos( $v, 'var(' ) && self::looks_like_color( $v );
	}

	/**
	 * Does a CSS value look like a colour?
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	private static function looks_like_color( $v ) {
		$v = strtolower( trim( $v ) );
		if ( in_array( $v, array( 'none', 'auto', 'inherit', 'initial', 'unset', 'revert', 'normal' ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\(|oklch\(|oklab\(|lab\(|lch\(|hwb\(|color\(|color-mix\(|light-dark\(|[a-z]+$)/', $v );
	}

	/**
	 * A size name for a font-size class: text-xs … text-xxl, text-m-to-s,
	 * h1 … h6, h2-to-h4, font-size-l (not text-m-line-height, h1-max-width).
	 *
	 * @param string $name Token name.
	 * @return bool
	 */
	private static function is_size_name( $name ) {
		$step = '[0-9]*x*[sml]';
		return (bool) preg_match( '/^(?:(?:text|font-size)-' . $step . '(?:-to-' . $step . ')?|h[1-6](?:-to-h[1-6])?)$/', $name );
	}

	/**
	 * Short-scale detector for spacing tokens (xs,s,m,l,xl,2xl… or numeric).
	 *
	 * @param string $name Token name.
	 * @return string|null The scale suffix used for the utility, or null to skip.
	 */
	private static function spacing_scale( $name ) {
		// Widths are not spacing (content-width is the page width).
		if ( false !== strpos( $name, 'width' ) ) {
			return null;
		}
		// Accept names like space-m, section-m, gap-l, or plain m/l/xl.
		if ( preg_match( '/^(?:space|gap|section|content)-(.+)$/', $name, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/^(xs|s|m|l|xl|2xl|3xl|4xl)$/', $name, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Build the utility CSS and collect class names. With "Leave out unused
	 * utility classes" on, the CSS has only the classes the site uses; the
	 * names list is always complete (the builder's class list shows them all).
	 *
	 * @param bool $full Every class, whatever the site uses (builder canvas, block editor).
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build( $full = false ) {
		$s       = self::settings();
		$tokens  = Settings::get_instance()->all_variables();
		$css     = array();
		$classes = array();
		$keep    = $full ? null : Class_Usage::keep_set();
		$emit    = function ( $selector, $body ) use ( &$css, &$classes, $keep ) {
			$name      = ltrim( $selector, '.' );
			$classes[] = $name;
			if ( null === $keep || Class_Usage::kept( $name, $keep ) ) {
				$css[] = $selector . '{' . $body . '}';
			}
		};

		$vars = array();
		foreach ( $tokens as $t ) {
			if ( ! empty( $t['enabled'] ) ) {
				$vars[ $t['name'] ] = $t['value'];
			}
		}

		// ---- Colors ----
		if ( $s['utilities_color'] ) {
			foreach ( $tokens as $t ) {
				if ( empty( $t['enabled'] ) || ! self::is_color_value( $t, $vars ) ) {
					continue;
				}
				$n = $t['name'];
				$emit( ".text-$n", "color:var(--$n)" );
				$emit( ".bg-$n", "background-color:var(--$n)" );
				$emit( ".border-$n", "border-color:var(--$n)" );
			}
		}

		// ---- Spacing ----
		if ( $s['utilities_spacing'] ) {
			foreach ( $tokens as $t ) {
				if ( empty( $t['enabled'] ) || 'space' !== $t['group'] ) {
					continue;
				}
				$scale = self::spacing_scale( $t['name'] );
				if ( null === $scale ) {
					continue;
				}
				$var = "var(--{$t['name']})";
				$emit( ".pad-$scale", "padding:$var" );
				$emit( ".pad-inline-$scale", "padding-inline:$var" );
				$emit( ".pad-block-$scale", "padding-block:$var" );
				$emit( ".mar-$scale", "margin:$var" );
				$emit( ".mar-block-$scale", "margin-block:$var" );
				$emit( ".mar-inline-$scale", "margin-inline:$var" );
				$emit( ".gap-$scale", "gap:$var" );
				// Double-dash names that sites built with other frameworks already
				// use, from the space-* steps only (logical sides, like theirs).
				if ( 0 === strpos( $t['name'], 'space-' ) ) {
					$emit( ".padding--$scale", "padding:$var" );
					$emit( ".pad--$scale", "padding:$var" );
					foreach ( array( 'top' => 'block-start', 'right' => 'inline-end', 'bottom' => 'block-end', 'left' => 'inline-start', 'block' => 'block', 'inline' => 'inline' ) as $side => $prop ) {
						$emit( ".margin-$side--$scale", "margin-$prop:$var" );
					}
					$emit( ".gap--$scale", "gap:$var" );
					$emit( ".row-gap--$scale", "row-gap:$var" );
					$emit( ".col-gap--$scale", "column-gap:$var" );
				}
			}
		}

		// ---- Typography ----
		if ( $s['utilities_typography'] ) {
			foreach ( $tokens as $t ) {
				if ( empty( $t['enabled'] ) || 'typography' !== $t['group'] ) {
					continue;
				}
				if ( self::is_size_name( $t['name'] ) ) {
					$emit( ".{$t['name']}", "font-size:var(--{$t['name']})" );
				}
			}
		}

		// ---- Static layout layer ----
		if ( $s['utilities_layout'] ) {
			$static = array(
				'.flex'            => 'display:flex',
				'.inline-flex'     => 'display:inline-flex',
				'.grid'            => 'display:grid',
				'.block'           => 'display:block',
				'.inline-block'    => 'display:inline-block',
				'.hidden'          => 'display:none',
				'.flex-col'        => 'flex-direction:column',
				'.flex-row'        => 'flex-direction:row',
				'.flex-wrap'       => 'flex-wrap:wrap',
				'.flex-nowrap'     => 'flex-wrap:nowrap',
				'.items-center'    => 'align-items:center',
				'.items-start'     => 'align-items:flex-start',
				'.items-end'       => 'align-items:flex-end',
				'.justify-center'  => 'justify-content:center',
				'.justify-between' => 'justify-content:space-between',
				'.justify-start'   => 'justify-content:flex-start',
				'.justify-end'     => 'justify-content:flex-end',
				'.text-center'     => 'text-align:center',
				'.text-left'       => 'text-align:left',
				'.text-right'      => 'text-align:right',
				'.width-full'      => 'width:100%',
				'.width-auto'      => 'width:auto',
				'.relative'        => 'position:relative',
				'.absolute'        => 'position:absolute',
				'.rounded'         => 'border-radius:var(--radius-m,.5rem)',
				'.rounded-full'    => 'border-radius:9999px',
			);
			foreach ( $static as $sel => $body ) {
				$emit( $sel, $body );
			}
		}

		// Grid / content-grid utilities from the scale engine.
		if ( $s['utilities_layout'] ) {
			$grid = Scale_Engine::grid_css();
			if ( $grid['css'] ) {
				$css[]   = null === $keep ? $grid['css'] : self::kept_rules( $grid['css'], $keep );
				$classes = array_merge( $classes, $grid['classes'] );
			}
		}

		$header = "\n/* --- TokenForge utility framework --- */\n";
		return array(
			'css'     => $header . implode( "\n", $css ) . "\n",
			'classes' => array_values( array_unique( $classes ) ),
		);
	}

	/**
	 * One-rule-per-line CSS with only the rules of kept classes (a rule for
	 * several classes stays when one of them is kept).
	 *
	 * @param string $text CSS.
	 * @param array  $keep Class_Usage::keep_set().
	 * @return string
	 */
	private static function kept_rules( $text, $keep ) {
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( ! preg_match( '/^(\.[A-Za-z0-9_,.-]+)\{/', $line, $m ) ) {
				$out[] = $line; // comments and blank lines
				continue;
			}
			foreach ( explode( ',', $m[1] ) as $sel ) {
				if ( Class_Usage::kept( ltrim( $sel, '.' ), $keep ) ) {
					$out[] = $line;
					break;
				}
			}
		}
		return implode( "\n", $out );
	}
}
