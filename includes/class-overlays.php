<?php
/**
 * Overlays + surfaces.
 *
 * - Basic overlay: .overlay (and any overlay--* class) paints a tinted
 *   pseudo-element over the element's background, below its content;
 *   --overlay-color changes the tint per element.
 * - Custom overlays: .overlay-1 … (or .overlay-{name}) with a colour, a gradient
 *   or an image, blur, opacity, blend mode, radius, inset, animation and an
 *   optional second gradient layered on top.
 * - Surfaces: .surface-1 … (or .surface-{name}), background textures from an
 *   image URL, raw SVG markup or a CSS gradient, with an optional overlay layer
 *   and a colour relationship that switches the text to light or dark.
 *
 * Every value is also a variable (--overlay-1-opacity, --surface-1-size …) and
 * each rule reads an unnumbered override first (--overlay-opacity,
 * --surface-size …), so one element can be adjusted without a new preset.
 * Class/variable names follow Automatic.css; the implementation is original.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Overlay + surface presets.
 */
class Overlays {

	const OPTION = 'tokenforge_overlays';

	/** Maximum presets of each kind. */
	const MAX = 10;

	/** @var array Surface number => its declarations (filled by build(); used by the texture() mixins). */
	private static $surface_decls = array();

	/** Colour relationships → the readable text colour they set. */
	const RELATIONSHIPS = array(
		'ultra-light' => 'dark',
		'light'       => 'dark',
		'dark'        => 'light',
		'ultra-dark'  => 'light',
	);

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'overlays', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'basic'         => true,
			'basic_pseudo'  => 'before',
			'basic_color'   => 'rgb(0 0 0 / .7)',
			'custom_pseudo' => 'before',
			'overlays'      => array(),
			'surfaces'      => array(),
		);
	}

	/**
	 * Defaults for one custom overlay.
	 *
	 * @return array
	 */
	public static function overlay_defaults() {
		return array(
			'name'       => '',
			'color'      => '',        // background colour
			'type'       => 'none',    // none | gradient | image
			'gradient'   => '',
			'image'      => '',        // URL
			'size'       => 'cover',
			'position'   => 'center',
			'attachment' => 'scroll',
			'repeat'     => 'no-repeat',
			'blur'       => '',        // backdrop blur, e.g. 6px
			'opacity'    => '0.7',
			'blend'      => '',        // mix-blend-mode
			'radius'     => '',
			'inset'      => '0',
			'animation'  => '',
			'layer'      => '',        // extra gradient painted on top
		);
	}

	/**
	 * Defaults for one surface.
	 *
	 * @return array
	 */
	public static function surface_defaults() {
		return array(
			'name'         => '',
			'color'        => '',       // background colour under the texture
			'asset'        => '',       // URL, raw <svg>, or a CSS image (gradient)
			'asset_is_url' => true,
			'size'         => '200px',
			'position'     => 'center center',
			'attachment'   => 'scroll',
			'repeat'       => 'repeat',
			'relationship' => 'none',   // none | ultra-light | light | dark | ultra-dark
			'animation'    => '',
			'layer'        => '',       // gradient painted over the texture
		);
	}

	/**
	 * Settings (lists normalised).
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		foreach ( array( 'overlays' => self::overlay_defaults(), 'surfaces' => self::surface_defaults() ) as $k => $d ) {
			$list = array();
			foreach ( is_array( $s[ $k ] ) ? array_values( $s[ $k ] ) : array() as $item ) {
				if ( is_array( $item ) ) {
					$list[] = wp_parse_args( $item, $d );
				}
			}
			$s[ $k ] = $list;
		}
		return Modules::apply( 'overlays', $s );
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
	 * Preset name → class-safe slug.
	 *
	 * @param mixed $v Raw name.
	 * @return string
	 */
	public static function slug( $v ) {
		return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( trim( (string) $v ) ) ), '-' );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$pseudo = function ( $v ) {
			return 'after' === $v ? 'after' : 'before';
		};
		$clean = array(
			'basic'         => ! empty( $in['basic'] ),
			'basic_pseudo'  => $pseudo( isset( $in['basic_pseudo'] ) ? $in['basic_pseudo'] : '' ),
			'basic_color'   => isset( $in['basic_color'] ) ? self::clean( $in['basic_color'] ) : self::defaults()['basic_color'],
			'custom_pseudo' => $pseudo( isset( $in['custom_pseudo'] ) ? $in['custom_pseudo'] : '' ),
			'overlays'      => array(),
			'surfaces'      => array(),
		);
		foreach ( array_slice( isset( $in['overlays'] ) && is_array( $in['overlays'] ) ? array_values( $in['overlays'] ) : array(), 0, self::MAX ) as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$d = self::overlay_defaults();
			$c = array();
			foreach ( $d as $k => $v ) {
				$raw = isset( $o[ $k ] ) ? $o[ $k ] : $v;
				if ( 'name' === $k ) {
					$c[ $k ] = self::slug( $raw );
				} elseif ( 'type' === $k ) {
					$c[ $k ] = in_array( $raw, array( 'gradient', 'image' ), true ) ? $raw : 'none';
				} elseif ( 'image' === $k ) {
					$c[ $k ] = esc_url_raw( trim( (string) $raw ) );
				} else {
					$c[ $k ] = self::clean( $raw );
				}
			}
			$clean['overlays'][] = $c;
		}
		foreach ( array_slice( isset( $in['surfaces'] ) && is_array( $in['surfaces'] ) ? array_values( $in['surfaces'] ) : array(), 0, self::MAX ) as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$d = self::surface_defaults();
			$c = array();
			foreach ( $d as $k => $v ) {
				$raw = isset( $o[ $k ] ) ? $o[ $k ] : $v;
				if ( 'name' === $k ) {
					$c[ $k ] = self::slug( $raw );
				} elseif ( 'asset_is_url' === $k ) {
					$c[ $k ] = ! empty( $raw );
				} elseif ( 'relationship' === $k ) {
					$c[ $k ] = isset( self::RELATIONSHIPS[ $raw ] ) ? $raw : 'none';
				} elseif ( 'asset' === $k ) {
					$c[ $k ] = self::clean_asset( $raw );
				} else {
					$c[ $k ] = self::clean( $raw );
				}
			}
			$clean['surfaces'][] = $c;
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Surface asset: a URL, SVG markup (kept, it is URL-encoded on output, and
	 * scripts never run in a CSS background), or a CSS image value.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private static function clean_asset( $raw ) {
		$v = trim( (string) $raw );
		if ( '' === $v ) {
			return '';
		}
		if ( '<' === $v[0] ) {
			$v = preg_replace( '#<script\b.*?</script>#is', '', $v );
			$v = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $v );
			return substr( $v, 0, 50000 );
		}
		return trim( str_replace( array( ';', '{', '}', '<', '>' ), '', $v ) );
	}

	/**
	 * CSS image for a surface asset.
	 *
	 * @param array $o Surface.
	 * @return string '' when empty.
	 */
	public static function asset_css( $o ) {
		$v = trim( (string) $o['asset'] );
		if ( '' === $v ) {
			return '';
		}
		if ( '<' === $v[0] ) {
			return 'url("data:image/svg+xml,' . rawurlencode( preg_replace( '/\s+/', ' ', $v ) ) . '")';
		}
		if ( ! empty( $o['asset_is_url'] ) || preg_match( '#^(https?:)?//|^/#i', $v ) ) {
			return 'url("' . str_replace( array( '"', '\\', "\n", "\r" ), '', esc_url_raw( $v ) ) . '")';
		}
		return $v;
	}

	/**
	 * Selector for preset $i (1-based) with an optional custom name.
	 *
	 * @param string $kind overlay|surface.
	 * @param int    $i    Number.
	 * @param string $name Slug.
	 * @return array [ selector, class names ]
	 */
	private static function selector( $kind, $i, $name ) {
		$names = array( "$kind-$i" );
		if ( '' !== $name && (string) $i !== $name ) {
			$names[] = "$kind-$name";
		}
		return array( '.' . implode( ',.', $names ), $names );
	}

	/**
	 * Build CSS + class names.
	 *
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build() {
		self::$surface_decls = array();
		$s       = self::settings();
		$css     = array();
		$vars    = array();
		$classes = array();

		// ---- Basic overlay ----
		if ( $s['basic'] ) {
			$b     = '.overlay,[class*="overlay--"]';
			$css[] = $b . '{position:relative;z-index:0;isolation:isolate}';
			$css[] = ':is(' . $b . ')::' . $s['basic_pseudo'] . '{content:"";position:absolute;inset:0;z-index:var(--overlay-z-index, -1);background:var(--overlay-color, ' . ( '' !== $s['basic_color'] ? $s['basic_color'] : 'rgb(0 0 0 / .7)' ) . ');pointer-events:none}';
			// Over media inside a figure the overlay has to sit above the image.
			$css[] = 'figure:where(:has(>img,>picture,>svg,>video,>iframe)):where(' . $b . '){--overlay-z-index:0}';
			$classes[] = 'overlay';
		}

		// ---- Custom overlays ----
		foreach ( $s['overlays'] as $n => $o ) {
			$i      = $n + 1;
			$has_bg = '' !== $o['color'] || ( 'gradient' === $o['type'] && '' !== $o['gradient'] ) || ( 'image' === $o['type'] && '' !== $o['image'] ) || '' !== $o['layer'];
			if ( ! $has_bg && '' === $o['blur'] ) {
				continue; // Nothing to paint.
			}
			list( $sel, $names ) = self::selector( 'overlay', $i, $o['name'] );
			$p      = "--overlay-$i";
			$v      = function ( $prop, $fallback = '' ) use ( $p ) {
				return 'var(--overlay-' . $prop . ', var(' . $p . '-' . $prop . ( '' !== $fallback ? ', ' . $fallback : '' ) . '))';
			};
			$images = array();
			$decl   = array( 'content:""', 'position:absolute', 'pointer-events:none', 'inset:' . $v( 'inset', '0' ), 'z-index:var(--overlay-z-index, var(' . $p . '-z-index, -1))', 'opacity:' . $v( 'opacity', '1' ) );
			if ( '' !== $o['color'] ) {
				$vars[ "overlay-$i-bg-color" ] = $o['color'];
				$decl[]                         = 'background-color:' . $v( 'bg-color' );
			}
			if ( '' !== $o['layer'] ) {
				$vars[ "overlay-$i-layer" ] = $o['layer'];
				$images[]                    = array( $v( 'layer' ), '100% 100%', 'center', 'no-repeat' );
			}
			if ( 'gradient' === $o['type'] && '' !== $o['gradient'] ) {
				$vars[ "overlay-$i-background" ] = $o['gradient'];
				$images[]                         = array( $v( 'background' ), $o['size'], $o['position'], $o['repeat'] );
			} elseif ( 'image' === $o['type'] && '' !== $o['image'] ) {
				$vars[ "overlay-$i-background" ] = 'url("' . str_replace( array( '"', '\\' ), '', $o['image'] ) . '")';
				$images[]                         = array( $v( 'background' ), $o['size'], $o['position'], $o['repeat'] );
			}
			if ( $images ) {
				$decl[] = 'background-image:' . implode( ',', array_column( $images, 0 ) );
				$decl[] = 'background-size:' . implode( ',', array_map( array( __CLASS__, 'or_auto' ), array_column( $images, 1 ) ) );
				$decl[] = 'background-position:' . implode( ',', array_map( array( __CLASS__, 'or_center' ), array_column( $images, 2 ) ) );
				$decl[] = 'background-repeat:' . implode( ',', array_map( array( __CLASS__, 'or_repeat' ), array_column( $images, 3 ) ) );
				if ( '' !== $o['attachment'] && 'static' !== $o['attachment'] ) {
					$decl[] = 'background-attachment:' . $o['attachment'];
				}
			}
			foreach ( array( 'inset' => 'inset', 'opacity' => 'opacity' ) as $key => $prop ) {
				if ( '' !== $o[ $key ] ) {
					$vars[ "overlay-$i-$prop" ] = $o[ $key ];
				}
			}
			if ( '' !== $o['blur'] ) {
				$vars[ "overlay-$i-background-blur" ] = $o['blur'];
				$decl[]                                = 'backdrop-filter:blur(' . $v( 'background-blur' ) . ')';
			}
			if ( '' !== $o['blend'] ) {
				$vars[ "overlay-$i-blend-mode" ] = $o['blend'];
				$decl[]                           = 'mix-blend-mode:' . $v( 'blend-mode' );
			}
			if ( '' !== $o['radius'] ) {
				$vars[ "overlay-$i-border-radius" ] = $o['radius'];
				$decl[]                              = 'border-radius:' . $v( 'border-radius' );
			} else {
				$decl[] = 'border-radius:inherit';
			}
			if ( '' !== $o['animation'] ) {
				$vars[ "overlay-$i-animation" ] = $o['animation'];
				$decl[]                          = 'animation:' . $v( 'animation' );
			}
			$css[]   = ':where(' . $sel . '){position:relative}';
			$css[]   = $sel . '{isolation:isolate}';
			$css[]   = ':is(' . $sel . ')::' . $s['custom_pseudo'] . '{' . implode( ';', $decl ) . '}';
			$css[]   = 'figure:where(:has(>img,>picture,>svg,>video,>iframe)):where(' . $sel . '){--overlay-z-index:0}';
			$classes = array_merge( $classes, $names );
		}

		// ---- Surfaces ----
		foreach ( $s['surfaces'] as $n => $o ) {
			$i     = $n + 1;
			$asset = self::asset_css( $o );
			if ( '' === $asset && '' === $o['animation'] && '' === $o['layer'] && '' === $o['color'] ) {
				continue;
			}
			list( $sel, $names ) = self::selector( 'surface', $i, $o['name'] );
			$p      = "--surface-$i";
			$v      = function ( $prop, $fallback = '' ) use ( $p ) {
				return 'var(--surface-' . $prop . ', var(' . $p . '-' . $prop . ( '' !== $fallback ? ', ' . $fallback : '' ) . '))';
			};
			$decl   = array();
			$images = array();
			if ( '' !== $o['color'] ) {
				$vars[ "surface-$i-bg-color" ] = $o['color'];
				$decl[]                         = 'background-color:' . $v( 'bg-color' );
			}
			if ( '' !== $o['layer'] ) {
				$vars[ "surface-$i-layer" ] = $o['layer'];
				$images[]                    = array( $v( 'layer' ), '100% 100%', 'center', 'no-repeat' );
			}
			if ( '' !== $asset ) {
				$vars[ "surface-$i-asset" ]    = $asset;
				$vars[ "surface-$i-size" ]     = '' !== $o['size'] ? $o['size'] : 'auto';
				$vars[ "surface-$i-position" ] = '' !== $o['position'] ? $o['position'] : 'center';
				$vars[ "surface-$i-repeat" ]   = '' !== $o['repeat'] ? $o['repeat'] : 'repeat';
				$images[]                       = array( $v( 'asset' ), $v( 'size' ), $v( 'position' ), $v( 'repeat' ) );
			}
			if ( $images ) {
				$decl[] = 'background-image:' . implode( ',', array_column( $images, 0 ) );
				$decl[] = 'background-size:' . implode( ',', array_column( $images, 1 ) );
				$decl[] = 'background-position:' . implode( ',', array_column( $images, 2 ) );
				$decl[] = 'background-repeat:' . implode( ',', array_column( $images, 3 ) );
				if ( '' !== $o['attachment'] && 'scroll' !== $o['attachment'] ) {
					$vars[ "surface-$i-attachment" ] = $o['attachment'];
					$decl[]                           = 'background-attachment:' . $v( 'attachment' );
				}
			}
			if ( '' !== $o['animation'] ) {
				$vars[ "surface-$i-animation" ] = $o['animation'];
				$decl[]                          = 'animation:' . $v( 'animation' );
			}
			if ( isset( self::RELATIONSHIPS[ $o['relationship'] ] ) ) {
				// Same readable text as the matching .bg--* class.
				$t      = self::RELATIONSHIPS[ $o['relationship'] ];
				$fb     = 'light' === $t ? '#fff' : '#111';
				$decl[] = "color:var(--text-$t, $fb);--text:var(--text-$t, $fb);--text-muted:var(--text-$t-muted, var(--text-$t, $fb))";
				$css[]  = ':is(' . $sel . ') :is(h1,h2,h3,h4,h5,h6){color:var(--text-' . $t . ', ' . $fb . ')}';
			}
			if ( $decl ) {
				$css[] = $sel . '{' . implode( ';', $decl ) . '}';
				self::$surface_decls[ $i ] = implode( ';', $decl );
			}
			$classes = array_merge( $classes, $names );
		}

		if ( $vars ) {
			$decl = array();
			foreach ( $vars as $k => $val ) {
				$decl[] = "--$k:$val";
			}
			array_unshift( $css, ':root{' . implode( ';', $decl ) . '}' );
		}

		return array(
			'css'     => $css ? "\n/* --- TokenForge overlays + surfaces --- */\n" . implode( "\n", $css ) . "\n" : '',
			'classes' => array_values( array_unique( $classes ) ),
		);
	}

	/** @param string $v Size. @return string */
	public static function or_auto( $v ) {
		return '' === (string) $v ? 'auto' : $v;
	}

	/** @param string $v Position. @return string */
	public static function or_center( $v ) {
		return '' === (string) $v ? 'center' : $v;
	}

	/** @param string $v Repeat. @return string */
	public static function or_repeat( $v ) {
		return '' === (string) $v ? 'no-repeat' : $v;
	}

	/**
	 * Declarations of each defined surface (number => "prop:value;…"), for the
	 * texture() / texture-overlay() SCSS mixins.
	 *
	 * @return array
	 */
	public static function surface_declarations() {
		self::build();
		return self::$surface_decls;
	}
}
