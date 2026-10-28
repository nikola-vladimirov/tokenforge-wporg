<?php
/**
 * Motion: transition + easing variables and the effect classes.
 *
 * - Transition variables (--transition*) and named easing presets (--ease-*).
 * - Scroll-driven entrance/exit effects (.on-enter--*, .on-exit--* and the
 *   .on-enter-all--* / .on-exit-all--* variants that animate every child),
 *   pure CSS via animation-timeline: view(). Browsers without scroll timelines
 *   get a small script that plays the entrance once as the element scrolls in.
 * - Visible effects (.on-visible--*): play once when the element comes into
 *   view (IntersectionObserver). Hidden states only apply once the script has
 *   run, so content is never stuck invisible without JavaScript.
 * - Hover effects (.on-hover--*) and hover border effects (ripple, outline,
 *   underline).
 *
 * Effects compose (".on-enter--fade on-enter--float"), respect
 * prefers-reduced-motion, and can be switched off inside page builders.
 * Class names follow the Automatic.css API so existing markup keeps working;
 * the implementation is original.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Motion tokens + effect utilities.
 */
class Motion {

	const OPTION = 'tokenforge_motion';

	/** Scroll/visible effects: class suffix => the setting that holds its amount. */
	const SCROLL_FX = array( 'fade', 'float', 'sink', 'slide-left', 'slide-right', 'grow', 'shrink', 'blur' );

	/** Hover effects. */
	const HOVER_FX = array( 'grow', 'shrink', 'float', 'sink', 'slide-right', 'slide-left', 'shadow', 'glow', 'fade', 'brighten' );

	/** Hover border effects. */
	const BORDER_FX = array( 'ripple-out', 'ripple-in', 'outline-out', 'outline-in', 'underline-left', 'underline-center', 'underline-right' );

	/** Default easing presets (the elastic curve is a sampled damped spring). */
	const EASINGS = "smooth=cubic-bezier(0.4, 0, 0.2, 1)\nsnappy=cubic-bezier(0.16, 1, 0.3, 1)\ngentle=cubic-bezier(0.65, 0, 0.35, 1)\nbouncy=cubic-bezier(0.68, -0.55, 0.265, 1.55)\nelastic=linear(0, 0.01 1.1%, 0.061 2.8%, 0.166 4.9%, 0.322 7.2%, 0.514 9.8%, 0.719 12.5%, 0.911 15.4%, 1.068 18.4%, 1.176 21.6%, 1.227 24.9%, 1.228 28.3%, 1.19 31.9%, 1.13 35.5%, 1.064 39.2%, 1.007 43.1%, 0.967 47%, 0.948 51%, 0.947 55.1%, 0.958 59.2%, 0.975 63.5%, 0.992 67.8%, 1.005 72.2%, 1.012 76.7%, 1.013 81.2%, 1.01 85.8%, 1.005 90.5%, 1.001 95.2%, 1)";

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'motion', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			// ---- Transitions + easing ----
			'transitions'         => true,
			'transition_duration' => '.3s',
			'transition_timing'   => 'ease-in-out',
			'transition_delay'    => '0s',
			'transition_class'    => false, // .transition utility
			'easing'              => true,
			'easings'             => self::EASINGS,
			// ---- Scroll-driven entrance (.on-enter--*) ----
			'enter'               => true,
			'enter_range_start'   => 'entry 20%',
			'enter_range_end'     => 'entry 100%',
			'enter_stagger'       => '5%',
			'enter_opacity'       => '0',
			'enter_float'         => '40px',
			'enter_sink'          => '40px',
			'enter_slide'         => '40px',
			'enter_grow'          => '0.9',
			'enter_shrink'        => '1.1',
			'enter_blur'          => '8px',
			'enter_parallax'      => '150px',
			// Fallback (no scroll timelines): played once, time-based.
			'fallback_duration'   => '0.6s',
			'fallback_timing'     => 'var(--ease-snappy, ease-out)',
			'fallback_delay'      => '0s',
			// ---- Scroll-driven exit (.on-exit--*) ----
			'exit'                => true,
			'exit_range_start'    => 'cover 50%',
			'exit_range_end'      => 'cover 100%',
			'exit_stagger'        => '5%',
			'exit_opacity'        => '0',
			'exit_float'          => '40px',
			'exit_sink'           => '40px',
			'exit_slide'          => '40px',
			'exit_grow'           => '1.1',
			'exit_shrink'         => '0.9',
			'exit_blur'           => '8px',
			// ---- Visible (.on-visible--*, IntersectionObserver) ----
			'visible'             => true,
			'visible_duration'    => '0.6s',
			'visible_timing'      => 'var(--ease-smooth, ease)',
			'visible_delay'       => '0s',
			'visible_threshold'   => '0.2',
			'visible_stagger'     => '0.1s',
			'visible_opacity'     => '0',
			'visible_float'       => '40px',
			'visible_sink'        => '40px',
			'visible_slide'       => '40px',
			'visible_grow'        => '0.9',
			'visible_shrink'      => '1.1',
			'visible_blur'        => '8px',
			// ---- Hover (.on-hover--*) ----
			'hover'               => true,
			'hover_duration'      => 'var(--transition-duration, .3s)',
			'hover_timing'        => 'var(--transition-timing, ease-in-out)',
			'hover_grow'          => '1.05',
			'hover_shrink'        => '0.95',
			'hover_float'         => '-8px',
			'hover_sink'          => '8px',
			'hover_slide_right'   => '8px',
			'hover_slide_left'    => '-8px',
			'hover_shadow'        => '0 8px 24px rgb(0 0 0 / .15)',
			'hover_glow_color'    => 'var(--primary, currentColor)',
			'hover_glow_spread'   => '20px',
			'hover_glow_opacity'  => '0.3',
			'hover_fade'          => '0.8',
			'hover_brighten'      => '1.1',
			// ---- Hover border effects ----
			'border_fx'           => true,
			'ripple_color'        => 'currentColor',
			'ripple_width'        => '4px',
			'ripple_duration'     => '1s',
			'ripple_distance'     => '12px',
			'outline_color'       => 'currentColor',
			'outline_width'       => '4px',
			'outline_duration'    => '.3s',
			'underline_color'     => 'currentColor',
			'underline_width'     => '3px',
			'underline_duration'  => '.3s',
			// ---- Builders ----
			'disable_in_builder'  => true, // no scroll/visible effects in the Bricks canvas or block editor
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return Modules::apply( 'motion', wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() ) );
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
			} elseif ( 'easings' === $k ) {
				$clean[ $k ] = isset( $in[ $k ] ) ? self::clean_easings( (string) $in[ $k ] ) : $v;
			} else {
				$clean[ $k ] = isset( $in[ $k ] ) ? self::clean( $in[ $k ] ) : $v;
			}
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * A CSS value safe to drop into a declaration.
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	private static function clean( $v ) {
		return trim( str_replace( array( ';', '{', '}', '<', '>' ), '', sanitize_text_field( (string) $v ) ) );
	}

	/**
	 * "name=value" lines, one easing per line.
	 *
	 * @param string $raw Raw text.
	 * @return string
	 */
	private static function clean_easings( $raw ) {
		$out = array();
		foreach ( self::parse_easings( $raw ) as $name => $value ) {
			$out[] = $name . '=' . $value;
		}
		return implode( "\n", $out );
	}

	/**
	 * Parse easing lines into name => value.
	 *
	 * @param string $raw Text.
	 * @return array
	 */
	public static function parse_easings( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$name  = trim( preg_replace( '/[^a-z0-9\-]/', '', strtolower( str_replace( ' ', '-', trim( $parts[0] ) ) ) ), '-' );
			$value = trim( str_replace( array( ';', '{', '}', '<', '>' ), '', $parts[1] ) );
			if ( '' !== $name && '' !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Variables for :root and the Bricks variable picker.
	 *
	 * @param array|null $s Settings (default: the saved ones).
	 * @return array [{name,value,group,enabled}]
	 */
	public static function variables( $s = null ) {
		$s   = null === $s ? self::settings() : wp_parse_args( $s, self::defaults() );
		$out = array();
		$add = function ( $n, $v ) use ( &$out ) {
			$out[] = array( 'name' => $n, 'value' => $v, 'group' => 'other', 'enabled' => true );
		};
		if ( $s['transitions'] ) {
			$add( 'transition-duration', $s['transition_duration'] );
			$add( 'transition-timing', $s['transition_timing'] );
			$add( 'transition-delay', $s['transition_delay'] );
			$add( 'transition', 'var(--transition-duration) var(--transition-timing) var(--transition-delay)' );
		}
		if ( $s['easing'] ) {
			foreach ( self::parse_easings( $s['easings'] ) as $name => $value ) {
				$add( 'ease-' . $name, $value );
			}
		}
		return $out;
	}

	/**
	 * Stored tokens win over generated variables, so a stored --transition*
	 * or --ease-* token (seed, ACSS import) would hide these settings. After the
	 * settings change, write the new values into those stored tokens.
	 *
	 * @param array $old Settings before the change.
	 * @return bool Whether stored tokens were updated.
	 */
	public static function sync_stored_tokens( $old ) {
		$before = array_column( self::variables( $old ), 'value', 'name' );
		$after  = array_column( self::variables(), 'value', 'name' );
		$names  = array();
		foreach ( $after as $name => $value ) {
			if ( ! isset( $before[ $name ] ) || $before[ $name ] !== $value ) {
				$names[ $name ] = $value;
			}
		}
		// The --transition shorthand follows its three parts.
		if ( isset( $after['transition'] ) && array_intersect_key( $names, array_flip( array( 'transition-duration', 'transition-timing', 'transition-delay' ) ) ) ) {
			$names['transition'] = $after['transition'];
		}
		if ( ! $names ) {
			return false;
		}
		$settings = Settings::get_instance();
		$tokens   = $settings->get_tokens();
		$changed  = false;
		foreach ( $tokens as $i => $t ) {
			if ( isset( $names[ $t['name'] ] ) && $t['value'] !== $names[ $t['name'] ] ) {
				$tokens[ $i ]['value'] = $names[ $t['name'] ];
				$changed               = true;
			}
		}
		if ( $changed ) {
			$settings->save_tokens( $tokens );
		}
		return $changed;
	}

	/**
	 * First run: show the transition values already in use (stored tokens).
	 *
	 * @return void
	 */
	public static function adopt_stored_tokens() {
		$map = array( 'transition-duration' => 'transition_duration', 'transition-timing' => 'transition_timing', 'transition-delay' => 'transition_delay' );
		$s   = self::settings();
		$hit = false;
		foreach ( Settings::get_instance()->get_tokens() as $t ) {
			if ( isset( $map[ $t['name'] ] ) && '' !== $t['value'] && false === strpos( $t['value'], 'var(' ) ) {
				$s[ $map[ $t['name'] ] ] = $t['value'];
				$hit                     = true;
			}
		}
		if ( $hit ) {
			update_option( self::OPTION, $s );
		}
	}

	/**
	 * Split "entry 20%" into [ 'entry', '20%' ] (null when it isn't that shape).
	 *
	 * @param string $range Range value.
	 * @return array|null
	 */
	private static function split_range( $range ) {
		if ( preg_match( '/^(cover|contain|entry|exit|entry-crossing|exit-crossing)\s+(-?[0-9.]+%)$/', trim( (string) $range ), $m ) ) {
			return array( $m[1], $m[2] );
		}
		return null;
	}

	/**
	 * Selector list for an effect family: ".on-enter--fade, …, .on-enter-all--fade > *, …".
	 *
	 * @param string $family enter|exit|visible.
	 * @param array  $fx     Effect suffixes.
	 * @return string
	 */
	private static function sel( $family, $fx ) {
		$single = array();
		$all    = array();
		foreach ( $fx as $f ) {
			$single[] = ".on-$family--$f";
			$all[]    = ".on-$family-all--$f>*";
		}
		return implode( ',', array_merge( $single, $all ) );
	}

	/**
	 * Per-effect custom properties for the shared keyframes.
	 *
	 * @param string $family enter|exit|visible.
	 * @param string $p      Property prefix (e.g. --tf-e).
	 * @return array suffix => declarations
	 */
	private static function fx_props( $family, $p ) {
		$v = "--$family";
		$o = 'exit' === $family ? "var(--exit-opacity-target, 0)" : "var($v-opacity-start, 0)";
		return array(
			'fade'        => "{$p}o:$o",
			'float'       => "{$p}y:var($v-float-distance, 40px)",
			'sink'        => "{$p}y:calc(var($v-sink-distance, 40px) * -1)",
			'slide-left'  => "{$p}x:var($v-slide-distance, 40px)",
			'slide-right' => "{$p}x:calc(var($v-slide-distance, 40px) * -1)",
			'grow'        => "{$p}s:" . ( 'exit' === $family ? 'var(--exit-scale-grow, 1.1)' : "var($v-scale-start, .9)" ),
			'shrink'      => "{$p}s:" . ( 'exit' === $family ? 'var(--exit-scale-amount, .9)' : "var($v-scale-shrink, 1.1)" ),
			'blur'        => "{$p}b:var($v-blur-amount, 8px)",
		);
	}

	/**
	 * Build the effect CSS + class names.
	 *
	 * @param bool $for_editor Block editor build (no scripts there, effects stay still).
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build( $for_editor = false ) {
		$s       = self::settings();
		$css     = array();
		$vars    = array();
		$classes = array();

		if ( $s['transitions'] && $s['transition_class'] ) {
			$css[]     = '.transition{transition:var(--transition)}';
			$classes[] = 'transition';
		}

		// ---- Parameters (ACSS-compatible variable names) ----
		if ( $s['enter'] ) {
			$vars += array(
				'animate-range-start'     => $s['enter_range_start'],
				'animate-range-end'       => $s['enter_range_end'],
				'enter-stagger-interval'  => $s['enter_stagger'],
				'enter-opacity-start'     => $s['enter_opacity'],
				'enter-float-distance'    => $s['enter_float'],
				'enter-sink-distance'     => $s['enter_sink'],
				'enter-slide-distance'    => $s['enter_slide'],
				'enter-scale-start'       => $s['enter_grow'],
				'enter-scale-shrink'      => $s['enter_shrink'],
				'enter-blur-amount'       => $s['enter_blur'],
				'enter-parallax-distance' => $s['enter_parallax'],
				'scroll-animation-duration' => $s['fallback_duration'],
				'scroll-animation-timing'   => $s['fallback_timing'],
				'scroll-animation-delay'    => $s['fallback_delay'],
			);
		}
		if ( $s['exit'] ) {
			$vars += array(
				'exit-range-start'      => $s['exit_range_start'],
				'exit-range-end'        => $s['exit_range_end'],
				'exit-stagger-interval' => $s['exit_stagger'],
				'exit-opacity-target'   => $s['exit_opacity'],
				'exit-float-distance'   => $s['exit_float'],
				'exit-sink-distance'    => $s['exit_sink'],
				'exit-slide-distance'   => $s['exit_slide'],
				'exit-scale-grow'       => $s['exit_grow'],
				'exit-scale-amount'     => $s['exit_shrink'],
				'exit-blur-amount'      => $s['exit_blur'],
			);
		}
		if ( $s['visible'] ) {
			$vars += array(
				'visible-duration'         => $s['visible_duration'],
				'visible-timing'           => $s['visible_timing'],
				'visible-delay'            => $s['visible_delay'],
				'visible-stagger-interval' => $s['visible_stagger'],
				'visible-opacity-start'    => $s['visible_opacity'],
				'visible-float-distance'   => $s['visible_float'],
				'visible-sink-distance'    => $s['visible_sink'],
				'visible-slide-distance'   => $s['visible_slide'],
				'visible-scale-start'      => $s['visible_grow'],
				'visible-scale-shrink'     => $s['visible_shrink'],
				'visible-blur-amount'      => $s['visible_blur'],
			);
		}
		if ( $s['hover'] ) {
			$vars += array(
				'hover-duration'           => $s['hover_duration'],
				'hover-timing'             => $s['hover_timing'],
				'hover-grow-amount'        => $s['hover_grow'],
				'hover-shrink-amount'      => $s['hover_shrink'],
				'hover-float-amount'       => $s['hover_float'],
				'hover-sink-amount'        => $s['hover_sink'],
				'hover-slide-right-amount' => $s['hover_slide_right'],
				'hover-slide-left-amount'  => $s['hover_slide_left'],
				'hover-shadow'             => $s['hover_shadow'],
				'hover-glow-color'         => $s['hover_glow_color'],
				'hover-glow-spread'        => $s['hover_glow_spread'],
				'hover-glow-opacity'       => $s['hover_glow_opacity'],
				'hover-fade-opacity'       => $s['hover_fade'],
				'hover-brighten-amount'    => $s['hover_brighten'],
			);
		}
		if ( $s['border_fx'] ) {
			$vars += array(
				'border-ripple-color'       => $s['ripple_color'],
				'border-ripple-width'       => $s['ripple_width'],
				'border-ripple-duration'    => $s['ripple_duration'],
				'border-ripple-distance'    => $s['ripple_distance'],
				'border-outline-color'      => $s['outline_color'],
				'border-outline-width'      => $s['outline_width'],
				'border-outline-duration'   => $s['outline_duration'],
				'border-underline-color'    => $s['underline_color'],
				'border-underline-width'    => $s['underline_width'],
				'border-underline-duration' => $s['underline_duration'],
			);
		}
		$decl = array();
		foreach ( $vars as $n => $v ) {
			if ( '' !== (string) $v ) {
				$decl[] = "--$n:$v";
			}
		}
		if ( $decl ) {
			$css[] = ':root{' . implode( ';', $decl ) . '}';
		}

		// ---- Scroll-driven entrance / exit ----
		// One shared keyframe per direction reads per-element custom properties,
		// so effects combine. Only a from- (enter) or to- (exit) keyframe is given:
		// the other end is the element's own value (its own transform/opacity).
		$still = array();
		if ( $s['enter'] || $s['exit'] ) {
			$scroll   = array();
			$enter    = self::sel( 'enter', self::SCROLL_FX );
			$exit     = self::sel( 'exit', self::SCROLL_FX );
			$ident    = function ( $p ) {
				return "{$p}o:1;{$p}x:0px;{$p}y:0px;{$p}s:1;{$p}b:0px";
			};
			$frame    = function ( $p ) {
				return "opacity:var({$p}o);transform:translate(var({$p}x),var({$p}y)) scale(var({$p}s));filter:blur(var({$p}b))";
			};
			$e_range  = 'var(--animate-range-start, entry 20%) var(--animate-range-end, entry 100%)';
			$x_range  = 'var(--exit-range-start, cover 50%) var(--exit-range-end, cover 100%)';
			if ( $s['enter'] ) {
				$scroll[] = '@keyframes tf-enter{from{' . $frame( '--tf-e' ) . '}}';
				$scroll[] = $enter . '{' . $ident( '--tf-e' ) . ';animation-name:tf-enter;animation-duration:auto;animation-timing-function:linear;animation-fill-mode:both;animation-timeline:view();animation-range:' . $e_range . '}';
				foreach ( self::fx_props( 'enter', '--tf-e' ) as $f => $d ) {
					$scroll[] = ".on-enter--$f,.on-enter-all--$f>*{" . $d . '}';
				}
				// Stagger: each child's range starts a little later.
				$r = self::split_range( $s['enter_range_start'] );
				if ( $r ) {
					$scroll[] = ".on-enter--stagger>*{animation-range-start:{$r[0]} calc({$r[1]} + var(--tf-i, 0) * var(--enter-stagger-interval, 5%))}";
				}
				// Parallax (independent `translate`, so it composes with transforms).
				$scroll[] = '@keyframes tf-parallax{from{translate:0 calc(var(--enter-parallax-distance, 150px) / 2)}to{translate:0 calc(var(--enter-parallax-distance, 150px) / -2)}}';
				$scroll[] = '.on-enter--parallax{animation-name:tf-parallax;animation-duration:auto;animation-timing-function:linear;animation-fill-mode:both;animation-timeline:view();animation-range:cover 0% cover 100%}';
				foreach ( self::SCROLL_FX as $f ) {
					array_push( $classes, "on-enter--$f", "on-enter-all--$f" );
				}
				array_push( $classes, 'on-enter--stagger', 'on-enter--parallax' );
				$still[] = $enter . ',.on-enter--parallax';
			}
			if ( $s['exit'] ) {
				$scroll[] = '@keyframes tf-exit{to{' . $frame( '--tf-x' ) . '}}';
				// Forwards only: before its range an exit must not hold an entrance back.
				$scroll[] = $exit . '{' . $ident( '--tf-x' ) . ';animation-name:tf-exit;animation-duration:auto;animation-timing-function:linear;animation-fill-mode:forwards;animation-timeline:view();animation-range:' . $x_range . '}';
				foreach ( self::fx_props( 'exit', '--tf-x' ) as $f => $d ) {
					$scroll[] = ".on-exit--$f,.on-exit-all--$f>*{" . $d . '}';
				}
				$r = self::split_range( $s['exit_range_start'] );
				if ( $r ) {
					$scroll[] = ".on-exit--stagger>*{animation-range-start:{$r[0]} calc({$r[1]} + var(--tf-i, 0) * var(--exit-stagger-interval, 5%))}";
				}
				foreach ( self::SCROLL_FX as $f ) {
					array_push( $classes, "on-exit--$f", "on-exit-all--$f" );
				}
				$classes[] = 'on-exit--stagger';
				$still[]   = $exit;
			}
			if ( $s['enter'] && $s['exit'] ) {
				// Both on one element: run the two animations side by side.
				$scroll[] = ':is(' . $enter . '):is(' . $exit . '){animation-name:tf-enter,tf-exit;animation-fill-mode:both,forwards;animation-range:' . $e_range . ',' . $x_range . '}';
			}
			$css[] = '@supports (animation-timeline:view()){' . implode( '', $scroll ) . '}';
			if ( $s['enter'] ) {
				// No scroll timelines: the script adds .tf-motion-fallback and plays
				// the entrance once (time-based) as each element scrolls into view.
				$css[] = '@supports not (animation-timeline:view()){'
					. '@keyframes tf-enter{from{' . $frame( '--tf-e' ) . '}}'
					. $enter . '{' . $ident( '--tf-e' ) . '}'
					. implode( '', array_map( function ( $f, $d ) { return ".on-enter--$f,.on-enter-all--$f>*{" . $d . '}'; }, array_keys( self::fx_props( 'enter', '--tf-e' ) ), self::fx_props( 'enter', '--tf-e' ) ) )
					. ':is(' . str_replace( ',', ',.tf-motion-fallback ', '.tf-motion-fallback ' . $enter ) . '){animation:tf-enter var(--scroll-animation-duration, .6s) var(--scroll-animation-timing, ease-out) calc(var(--scroll-animation-delay, 0s) + var(--tf-i, 0) * var(--visible-stagger-interval, .1s)) both paused}'
					. '.tf-motion-fallback :is(.tf-in,.tf-in>*){animation-play-state:running}'
					. '}';
			}
			if ( $s['enter'] || $s['exit'] ) {
				// Native stagger index where supported (the script sets --tf-i otherwise).
				$css[] = '@supports (z-index:sibling-index()){:is(.on-enter--stagger,.on-exit--stagger,.on-visible--stagger)>*{--tf-i:calc(sibling-index() - 1)}}';
			}
		}

		// ---- Visible (once, when scrolled into view) ----
		// Hidden states apply only under .tf-motion (set by the script), so no
		// script = content simply shows.
		if ( $s['visible'] ) {
			// data-tf-reveal="fade" = .on-visible--fade; "up" = .on-visible--float plus the fade; appended after the classes.
			$rev   = array( 'fade' => '[data-tf-reveal="fade"]', 'float' => '[data-tf-reveal="up"]' );
			$vis   = self::sel( 'visible', self::SCROLL_FX );
			$hid   = function ( $fx ) use ( $rev ) {
				$out = array();
				foreach ( (array) $fx as $f ) {
					$out[] = ".tf-motion .on-visible--$f:not(.tf-in)";
					$out[] = ".tf-motion .on-visible-all--$f:not(.tf-in)>*";
				}
				foreach ( array_intersect_key( $rev, array_flip( (array) $fx ) ) as $a ) {
					$out[] = ".tf-motion $a:not(.tf-in)";
				}
				return implode( ',', $out );
			};
			$css[] = $vis . ',' . implode( ',', $rev ) . '{--tf-vx:0px;--tf-vy:0px;--tf-vs:1}';
			foreach ( array(
				'float'       => '--tf-vy:var(--visible-float-distance, 40px)',
				'sink'        => '--tf-vy:calc(var(--visible-sink-distance, 40px) * -1)',
				'slide-left'  => '--tf-vx:var(--visible-slide-distance, 40px)',
				'slide-right' => '--tf-vx:calc(var(--visible-slide-distance, 40px) * -1)',
				'grow'        => '--tf-vs:var(--visible-scale-start, .9)',
				'shrink'      => '--tf-vs:var(--visible-scale-shrink, 1.1)',
			) as $f => $d ) {
				$css[] = ".on-visible--$f,.on-visible-all--$f>*" . ( isset( $rev[ $f ] ) ? ',' . $rev[ $f ] : '' ) . '{' . $d . '}';
			}
			// Transitions apply until the script marks the element done, then give
			// way to the element's own (e.g. hover) transitions.
			$pend  = array();
			foreach ( self::SCROLL_FX as $f ) {
				$pend[] = ".tf-motion .on-visible--$f:not(.tf-done)";
				$pend[] = ".tf-motion .on-visible-all--$f:not(.tf-done)>*";
			}
			foreach ( $rev as $a ) {
				$pend[] = ".tf-motion $a:not(.tf-done)";
			}
			$css[] = ':is(' . implode( ',', $pend ) . '){transition-property:opacity,translate,scale,filter;transition-duration:var(--visible-duration, .6s);transition-timing-function:var(--visible-timing, ease);transition-delay:var(--visible-delay, 0s)}';
			$css[] = '.tf-motion .on-visible--stagger:not(.tf-done)>*{transition-delay:calc(var(--visible-delay, 0s) + var(--tf-i, 0) * var(--visible-stagger-interval, .1s))}';
			$css[] = ':is(' . $hid( 'fade' ) . ',.tf-motion [data-tf-reveal="up"]:not(.tf-in)){opacity:var(--visible-opacity-start, 0)}';
			$css[] = ':is(' . $hid( array( 'float', 'sink', 'slide-left', 'slide-right' ) ) . '){translate:var(--tf-vx) var(--tf-vy)}';
			$css[] = ':is(' . $hid( array( 'grow', 'shrink' ) ) . '){scale:var(--tf-vs)}';
			$css[] = ':is(' . $hid( 'blur' ) . '){filter:blur(var(--visible-blur-amount, 8px))}';
			foreach ( self::SCROLL_FX as $f ) {
				array_push( $classes, "on-visible--$f", "on-visible-all--$f" );
			}
			$classes[] = 'on-visible--stagger';
		}

		// ---- Hover ----
		if ( $s['hover'] ) {
			$all   = array();
			foreach ( self::HOVER_FX as $f ) {
				$all[]     = ".on-hover--$f";
				$classes[] = "on-hover--$f";
			}
			$css[] = implode( ',', $all ) . '{transition-property:translate,scale,box-shadow,opacity,filter;transition-duration:var(--hover-duration, .3s);transition-timing-function:var(--hover-timing, ease-in-out)}';
			// Movement only when the visitor hasn't asked for reduced motion.
			$css[] = '@media (prefers-reduced-motion:no-preference){'
				. '.on-hover--grow:hover{scale:var(--hover-grow-amount, 1.05)}'
				. '.on-hover--shrink:hover{scale:var(--hover-shrink-amount, .95)}'
				. '.on-hover--float:hover{translate:0 var(--hover-float-amount, -8px)}'
				. '.on-hover--sink:hover{translate:0 var(--hover-sink-amount, 8px)}'
				. '.on-hover--slide-right:hover{translate:var(--hover-slide-right-amount, 8px) 0}'
				. '.on-hover--slide-left:hover{translate:var(--hover-slide-left-amount, -8px) 0}'
				. '}';
			$css[] = '.on-hover--shadow:hover{box-shadow:var(--hover-shadow)}';
			$css[] = '.on-hover--glow:hover{box-shadow:0 0 var(--hover-glow-spread, 20px) color-mix(in srgb, var(--hover-glow-color, currentColor) calc(var(--hover-glow-opacity, .3) * 100%), transparent)}';
			$css[] = '.on-hover--fade:hover{opacity:var(--hover-fade-opacity, .8)}';
			$css[] = '.on-hover--brighten:hover{filter:brightness(var(--hover-brighten-amount, 1.1))}';
		}

		// ---- Hover border effects ----
		if ( $s['border_fx'] ) {
			$w     = 'var(--border-ripple-width, 4px) solid';
			$css[] = '@media (prefers-reduced-motion:no-preference){'
				. '@keyframes tf-ripple-out{from{outline:' . $w . ' var(--border-ripple-color, currentColor);outline-offset:0}to{outline:' . $w . ' transparent;outline-offset:var(--border-ripple-distance, 12px)}}'
				. '@keyframes tf-ripple-in{from{outline:' . $w . ' transparent;outline-offset:var(--border-ripple-distance, 12px)}70%{outline:' . $w . ' var(--border-ripple-color, currentColor);outline-offset:0}to{outline:' . $w . ' transparent;outline-offset:0}}'
				. '.on-hover--ripple-out:hover{animation:tf-ripple-out var(--border-ripple-duration, 1s) ease-out}'
				. '.on-hover--ripple-in:hover{animation:tf-ripple-in var(--border-ripple-duration, 1s) ease-out}'
				. '}';
			// Outline slides out from the edge (out) or in towards it (in).
			$ow    = 'var(--border-outline-width, 4px)';
			$ot    = 'var(--border-outline-duration, .3s) var(--hover-timing, ease-in-out)';
			$css[] = '.on-hover--outline-out,.on-hover--outline-in{outline:' . $ow . ' solid transparent;transition:outline-color ' . $ot . ',outline-offset ' . $ot . '}';
			$css[] = '.on-hover--outline-out{outline-offset:0}.on-hover--outline-in{outline-offset:calc(' . $ow . ' * 2)}';
			$css[] = '.on-hover--outline-out:hover{outline-color:var(--border-outline-color, currentColor);outline-offset:' . $ow . '}';
			$css[] = '.on-hover--outline-in:hover{outline-color:var(--border-outline-color, currentColor);outline-offset:0}';
			// Underline drawn by a pseudo-element, growing from the chosen side.
			$u     = '.on-hover--underline-left,.on-hover--underline-center,.on-hover--underline-right';
			$css[] = ':where(' . $u . '){position:relative}';
			$css[] = ':is(' . $u . ')::after{content:"";position:absolute;inset-inline:0;inset-block-end:0;block-size:var(--border-underline-width, 3px);background:var(--border-underline-color, currentColor);scale:0 1;pointer-events:none;transition:scale var(--border-underline-duration, .3s) var(--hover-timing, ease-in-out)}';
			$css[] = '.on-hover--underline-left::after{transform-origin:left}.on-hover--underline-center::after{transform-origin:center}.on-hover--underline-right::after{transform-origin:right}';
			$css[] = ':is(' . $u . '):hover::after{scale:1 1}';
			foreach ( self::BORDER_FX as $f ) {
				$classes[] = "on-hover--$f";
			}
		}

		// Reduced motion: scroll effects off (visible never hides, see script).
		if ( $still ) {
			$css[] = '@media (prefers-reduced-motion:reduce){' . self::still_css() . '}';
		}
		// The block editor has no effect script, and scroll effects there only get in the way.
		if ( $for_editor && ( $s['disable_in_builder'] || $still ) ) {
			$css[] = self::still_css();
		}

		return array(
			'css'     => $css ? "\n/* --- TokenForge motion (transitions, effects) --- */\n" . implode( "\n", $css ) . "\n" : '',
			'classes' => $classes,
		);
	}

	/**
	 * Rules that stop the scroll-driven effects (reduced motion, builders).
	 *
	 * @return string
	 */
	public static function still_css() {
		$s   = self::settings();
		$sel = array();
		if ( $s['enter'] ) {
			$sel[] = self::sel( 'enter', self::SCROLL_FX ) . ',.on-enter--parallax';
		}
		if ( $s['exit'] ) {
			$sel[] = self::sel( 'exit', self::SCROLL_FX );
		}
		return $sel ? ':is(' . implode( ',', $sel ) . '){animation:none!important}' : '';
	}

	/**
	 * Does the page need the effect script (visible effects, stagger indices,
	 * entrance fallback)?
	 *
	 * @return bool
	 */
	public static function needs_script() {
		$s = self::settings();
		return $s['visible'] || $s['enter'] || $s['exit'];
	}

	/**
	 * Head script: hide visible-effect elements from the first paint (so nothing
	 * above the fold flashes), and switch on the entrance fallback where scroll
	 * timelines are missing. Reveals everything again if the footer script never
	 * runs (blocked, errored), so content can't get stuck hidden.
	 *
	 * @return string
	 */
	public static function head_script() {
		$s   = self::settings();
		$add = array();
		if ( $s['visible'] ) {
			$add[] = 'R.classList.add("tf-motion");';
		}
		if ( $s['enter'] ) {
			$add[] = 'if(!(W.CSS&&CSS.supports&&CSS.supports("animation-timeline: view()"))){R.classList.add("tf-motion-fallback")}';
		}
		if ( ! $add ) {
			return '';
		}
		$js = <<<'JS'
(function(){var R=document.documentElement,W=window;if(!("IntersectionObserver" in W)||(W.matchMedia&&W.matchMedia("(prefers-reduced-motion: reduce)").matches)){return}
%ADD%
setTimeout(function(){if(!W.tfMotionReady){R.classList.remove("tf-motion","tf-motion-fallback")}},4000)})();
JS;
		return str_replace( '%ADD%', implode( '', $add ), $js );
	}

	/**
	 * Footer script: stagger indices, the visible observer and the entrance
	 * fallback. Dependency-free; a no-op on pages without effect classes.
	 *
	 * @return string
	 */
	public static function script() {
		$s = self::settings();
		$t = (float) $s['visible_threshold'];
		$t = ( $t > 0 && $t <= 1 ) ? $t : 0.2;
		$js = <<<'JS'
(function(){var d=document,R=d.documentElement,W=window;W.tfMotionReady=1;
d.querySelectorAll(".on-enter--stagger,.on-exit--stagger,.on-visible--stagger").forEach(function(p){for(var i=0;i<p.children.length;i++){p.children[i].style.setProperty("--tf-i",i)}});
var RV='[data-tf-reveal="fade"],[data-tf-reveal="up"]',V=R.classList.contains("tf-motion"),F=R.classList.contains("tf-motion-fallback"),io;
var T=%T%,th=[0];for(var x=.05;x<T;x+=.05){th.push(x)}th.push(T);
function secs(v){return Math.max.apply(null,String(v).split(",").map(function(p){p=p.trim();return p.slice(-2)==="ms"?parseFloat(p)/1000:(parseFloat(p)||0)}))}
function done(el){var c=/(^|\s)on-visible-all--/.test(el.getAttribute("class")||"")?(el.lastElementChild||el):el,cs=getComputedStyle(c);setTimeout(function(){el.classList.add("tf-done")},(secs(cs.transitionDuration)+secs(cs.transitionDelay))*1000+60)}
function has(c,fam,skip){return new RegExp("(^|\\s)on-"+fam+"(-all)?--(?!("+skip+")(\\s|$))").test(c)}
function obs(e){io=io||new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting&&(e.intersectionRatio>=T||e.intersectionRect.height>=W.innerHeight*T)){e.target.classList.add("tf-in");if(e.target.tfV){done(e.target)}io.unobserve(e.target)}})},{threshold:th});io.observe(e)}
function scan(r){var l=[].slice.call(r.querySelectorAll('[class*="on-visible"],[class*="on-enter"],[data-tf-reveal]')),a=0,b=0;if(r.nodeType===1){l.unshift(r)}l.forEach(function(e){var c=e.getAttribute("class")||"";if(V&&!e.tfV&&(e.matches(RV)||has(c,"visible","stagger"))){e.tfV=1;a++;obs(e)}if(F&&!e.tfE&&has(c,"enter","stagger|parallax")){e.tfE=1;b++;obs(e)}});return[a,b]}
var n=scan(d);
if(!n[0]){V=0;R.classList.remove("tf-motion")}if(!n[1]){F=0;R.classList.remove("tf-motion-fallback")}
if(!(V||F)){return}
new MutationObserver(function(ms){ms.forEach(function(m){[].forEach.call(m.addedNodes,function(x){if(1===x.nodeType){scan(x)}})})}).observe(d.body,{childList:true,subtree:true})})();
JS;
		return str_replace( '%T%', (string) $t, $js );
	}
}
