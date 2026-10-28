<?php
/**
 * Two-way sync between Global SCSS and Bricks global classes.
 *
 * Each top-level class block in Global SCSS (".my-class { … }") is paired with
 * the Bricks global class of the same name:
 * Global SCSS wins. What the builder shows for a class is its Style fields
 * overridden by its CSS panel (the builder lets the CSS panel win; with the
 * builder's own "CSS sync" on, the CSS panel mirrors the fields).
 * - SCSS → builder (live while you type in the dock, stored on Save): the block
 *   is compiled on its own and every declaration the builder has a field for
 *   (spacing, border, radius, typography, background colour, size, layout,
 *   position …, ":hover"-style states, breakpoints matched by width) is written
 *   into the class's Style fields. Other plain CSS in the block (custom
 *   properties, !important, properties without a field, nested rules such as
 *   "&::before" or "& .icon") moves into the block's builder Custom CSS part,
 *   which becomes the class's CSS panel. The CSS panel never holds a value the
 *   block sets, so it can never override the SCSS. SCSS-only code (mixins,
 *   $variables, functions, #{…}) stays SCSS-only; TokenForge outputs the whole
 *   block as before.
 * - Builder → SCSS (live while you work in the builder, stored when the builder
 *   saves): the values you changed, in the Style fields or in the CSS panel,
 *   are rewritten in place inside the class's block, so mixins, comments and
 *   other code in the block are kept; the rest of the CSS panel goes into the
 *   Custom CSS part.
 *
 * Values are compared unit by unit ("_padding:tablet_portrait|top"), and the
 * last synced values are remembered so a stale copy saved by another builder
 * tab can't undo an SCSS change.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Scss;
use TokenForge\Css_Generator;
use TokenForge\Platforms\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Global SCSS <-> Bricks class fields.
 */
class Scss_Sync {

	const STATE = 'tokenforge_scss_sync';

	/** CSS property => Bricks setting for single-value fields. */
	const SIMPLE = array(
		'display'               => '_display',
		'flex-direction'        => '_direction',
		'flex-wrap'             => '_flexWrap',
		'align-self'            => '_alignSelf',
		'row-gap'               => '_rowGap',
		'column-gap'            => '_columnGap',
		'grid-template-columns' => '_gridTemplateColumns',
		'grid-template-rows'    => '_gridTemplateRows',
		'grid-auto-flow'        => '_gridAutoFlow',
		'grid-auto-columns'     => '_gridAutoColumns',
		'grid-auto-rows'        => '_gridAutoRows',
		'grid-column'           => '_gridItemColumnSpan',
		'grid-row'              => '_gridItemRowSpan',
		'justify-self'          => '_gridItemJustifySelf',
		'position'              => '_position',
		'top'                   => '_top',
		'right'                 => '_right',
		'bottom'                => '_bottom',
		'left'                  => '_left',
		'z-index'               => '_zIndex',
		'order'                 => '_order',
		'width'                 => '_width',
		'min-width'             => '_widthMin',
		'max-width'             => '_widthMax',
		'height'                => '_height',
		'min-height'            => '_heightMin',
		'max-height'            => '_heightMax',
		'aspect-ratio'          => '_aspectRatio',
		'flex-grow'             => '_flexGrow',
		'flex-shrink'           => '_flexShrink',
		'flex-basis'            => '_flexBasis',
		'overflow'              => '_overflow',
		'opacity'               => '_opacity',
		'cursor'                => '_cursor',
		'visibility'            => '_visibility',
		'isolation'             => '_isolation',
		'mix-blend-mode'        => '_mixBlendMode',
		'pointer-events'        => '_pointerEvents',
		'object-fit'            => '_objectFit',
		'transform-origin'      => '_transformOrigin',
		'transition'            => '_cssTransition',
	);

	/** Properties whose Bricks field depends on display: [ flex field, grid field ]. */
	const ALIGN = array(
		'justify-content' => array( '_justifyContent', '_justifyContentGrid' ),
		'align-items'     => array( '_alignItems', '_alignItemsGrid' ),
		'align-content'   => array( '', '_alignContentGrid' ),
		'justify-items'   => array( '', '_justifyItemsGrid' ),
	);

	/** Typography sub-fields (CSS property = sub-key). */
	const TYPO = array( 'color', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'text-align', 'text-decoration', 'white-space' );

	/** Border styles (to split the border shorthand). */
	const BORDER_STYLES = array( 'none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset' );

	const SIDES = array( 'top', 'right', 'bottom', 'left' );

	/** CSS functions (values and selectors): anything else in a snippet is SCSS. */
	const CSS_FUNCTIONS = array(
		'var', 'calc', 'clamp', 'min', 'max', 'abs', 'sign', 'round', 'mod', 'rem', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2', 'pow', 'sqrt', 'hypot', 'log', 'exp',
		'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix', 'light-dark', 'url', 'image-set', 'cross-fade', 'element',
		'linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient',
		'translate', 'translatex', 'translatey', 'translatez', 'translate3d', 'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'scale', 'scalex', 'scaley', 'scalez', 'scale3d',
		'skew', 'skewx', 'skewy', 'matrix', 'matrix3d', 'perspective', 'cubic-bezier', 'steps', 'linear', 'attr', 'counter', 'counters', 'env', 'fit-content', 'minmax', 'repeat',
		'format', 'local', 'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia',
		'circle', 'ellipse', 'inset', 'polygon', 'path', 'rect', 'xywh', 'ray', 'anchor', 'anchor-size', 'view', 'scroll', 'symbols',
		'not', 'is', 'where', 'has', 'nth-child', 'nth-of-type', 'nth-last-child', 'nth-last-of-type', 'lang', 'dir', 'host', 'host-context', 'slotted', 'part', 'state', 'highlight',
	);

	/** @var bool Re-entrancy guard (our own writes trigger the option hook). */
	private static $busy = false;

	/**
	 * Constructor: watch Bricks saving its global classes.
	 */
	public function __construct() {
		add_action( 'update_option_bricks_global_classes', array( __CLASS__, 'on_classes_saved' ), 20, 2 );
	}

	/**
	 * Is the sync switched on (and Bricks active)?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = Scss::settings();
		return ! empty( $s['sync'] ) && Bricks::is_active();
	}

	// ------------------------------------------------------------------
	// Bricks context
	// ------------------------------------------------------------------

	/**
	 * Bricks breakpoints: width => key (non-base only) and whether mobile first.
	 *
	 * @return array [ array $by_width, bool $mobile_first ]
	 */
	private static function breakpoints() {
		$list = array();
		if ( class_exists( '\Bricks\Breakpoints' ) && method_exists( '\Bricks\Breakpoints', 'get_breakpoints' ) ) {
			$list = (array) \Bricks\Breakpoints::get_breakpoints();
		}
		if ( ! $list ) {
			$list = array(
				array( 'key' => 'desktop', 'width' => 1279, 'base' => true ),
				array( 'key' => 'tablet_portrait', 'width' => 991 ),
				array( 'key' => 'mobile_landscape', 'width' => 767 ),
				array( 'key' => 'mobile_portrait', 'width' => 478 ),
			);
		}
		$by     = array();
		$mobile = class_exists( '\Bricks\Breakpoints' ) && ! empty( \Bricks\Breakpoints::$is_mobile_first );
		foreach ( $list as $b ) {
			if ( empty( $b['base'] ) && ! empty( $b['key'] ) && isset( $b['width'] ) ) {
				$by[ (int) $b['width'] ] = (string) $b['key'];
			}
		}
		return array( $by, $mobile );
	}

	/**
	 * Pseudo-classes Bricks has fields for (":hover", "::before" …).
	 *
	 * @return string[]
	 */
	private static function pseudos() {
		$p = get_option( 'bricks_global_pseudo_classes', array( ':hover', ':active', ':focus' ) );
		return is_array( $p ) && $p ? array_values( array_map( 'strval', $p ) ) : array( ':hover', ':active', ':focus' );
	}

	// ------------------------------------------------------------------
	// CSS helpers
	// ------------------------------------------------------------------

	/**
	 * Split at top-level separators (outside parentheses).
	 *
	 * @param string $v   Value.
	 * @param string $sep ' ' or ','.
	 * @return string[]
	 */
	public static function split( $v, $sep = ' ' ) {
		$out   = array();
		$cur   = '';
		$depth = 0;
		$v     = trim( (string) $v );
		for ( $i = 0, $n = strlen( $v ); $i < $n; $i++ ) {
			$ch = $v[ $i ];
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			}
			$is_sep = ' ' === $sep ? ctype_space( $ch ) : $ch === $sep;
			if ( $is_sep && 0 === $depth ) {
				if ( '' !== trim( $cur ) ) {
					$out[] = trim( $cur );
				}
				$cur = '';
			} else {
				$cur .= $ch;
			}
		}
		if ( '' !== trim( $cur ) ) {
			$out[] = trim( $cur );
		}
		return $out;
	}

	/**
	 * CSS rules of compiled CSS: [ media, selector, body ] (one media level).
	 *
	 * @param string $css   CSS.
	 * @param string $media Current media query.
	 * @param array  $out   Rules (by reference).
	 * @return void
	 */
	private static function css_rules( $css, $media, &$out ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) {
				break;
			}
			$sel   = trim( substr( $css, $i, $open - $i ) );
			$depth = 1;
			$j     = $open + 1;
			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					$depth++;
				} elseif ( '}' === $css[ $j ] ) {
					$depth--;
				}
				$j++;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			$i    = $j;
			if ( 0 === strpos( $sel, '@media' ) ) {
				self::css_rules( $body, trim( substr( $sel, 6 ) ), $out );
			} elseif ( '' !== $sel && '@' !== $sel[0] ) {
				$out[] = array( $media, $sel, $body );
			}
		}
	}

	/**
	 * Declarations of a CSS body: [ [ prop, value, important ], … ].
	 *
	 * @param string $body Body.
	 * @return array
	 */
	public static function css_decls( $body ) {
		$out = array();
		foreach ( self::split( $body, ';' ) as $d ) {
			$p = strpos( $d, ':' );
			if ( false === $p ) {
				continue;
			}
			$prop  = strtolower( trim( substr( $d, 0, $p ) ) );
			$value = trim( substr( $d, $p + 1 ) );
			$imp   = (bool) preg_match( '/!\s*important\s*$/i', $value );
			$value = trim( preg_replace( '/!\s*important\s*$/i', '', $value ) );
			if ( '' !== $prop && '' !== $value ) {
				$out[] = array( $prop, $value, $imp );
			}
		}
		return $out;
	}

	/**
	 * 1 to 4 box values → [ top, right, bottom, left ].
	 *
	 * @param string $v Value.
	 * @return array|null
	 */
	private static function box( $v ) {
		$p = self::split( $v );
		switch ( count( $p ) ) {
			case 1:
				return array( $p[0], $p[0], $p[0], $p[0] );
			case 2:
				return array( $p[0], $p[1], $p[0], $p[1] );
			case 3:
				return array( $p[0], $p[1], $p[2], $p[1] );
			case 4:
				return $p;
		}
		return null;
	}

	/**
	 * Does a value look like a length (or a var()/calc that could be one)?
	 *
	 * @param string $t Token.
	 * @return bool
	 */
	private static function lengthish( $t ) {
		return (bool) preg_match( '/^(-?[\d.]+[a-z%]*|thin|medium|thick|(calc|clamp|min|max)\(.*\))$/i', $t );
	}

	/**
	 * A number without trailing zeros.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		$s = rtrim( rtrim( sprintf( '%.4F', (float) $n ), '0' ), '.' );
		return '-0' === $s ? '0' : $s;
	}

	/**
	 * One shadow (box or text) → the builder's field value, or null when the
	 * field can't hold it (several shadows, no colour, a var() in a length slot …).
	 *
	 * @param string $css CSS value.
	 * @param bool   $box Box shadow (inset + spread allowed).
	 * @return array|null
	 */
	public static function shadow_array( $css, $box ) {
		$css = trim( (string) $css );
		if ( '' === $css || count( self::split( $css, ',' ) ) > 1 ) {
			return null;
		}
		$inset = false;
		$len   = array();
		$color = null;
		foreach ( self::split( $css ) as $t ) {
			if ( $box && 'inset' === strtolower( $t ) ) {
				$inset = true;
			} elseif ( self::lengthish( $t ) && ! preg_match( '/^(thin|medium|thick)$/i', $t ) ) {
				$len[] = $t;
			} elseif ( null === $color ) {
				$color = $t;
			} else {
				return null;
			}
		}
		// Without a colour a shadow is currentColor; the builder would make it transparent.
		if ( null === $color || count( $len ) < 2 || count( $len ) > ( $box ? 4 : 3 ) ) {
			return null;
		}
		$keys   = array( 'offsetX', 'offsetY', 'blur', 'spread' );
		$values = array();
		foreach ( $len as $i => $l ) {
			$values[ $keys[ $i ] ] = $l;
		}
		$out = array( 'values' => $values, 'color' => array( 'raw' => $color ) );
		if ( $inset ) {
			$out['inset'] = true;
		}
		return $out;
	}

	/**
	 * The builder's shadow field → CSS (the way the builder writes it).
	 *
	 * @param mixed $a   Field value.
	 * @param bool  $box Box shadow.
	 * @return string
	 */
	public static function shadow_css( $a, $box ) {
		if ( ! is_array( $a ) ) {
			return '';
		}
		$v   = isset( $a['values'] ) && is_array( $a['values'] ) ? $a['values'] : array();
		$out = array();
		if ( $box && ! empty( $a['inset'] ) ) {
			$out[] = 'inset';
		}
		foreach ( $box ? array( 'offsetX', 'offsetY', 'blur', 'spread' ) : array( 'offsetX', 'offsetY', 'blur' ) as $k ) {
			$x     = isset( $v[ $k ] ) && '' !== trim( (string) $v[ $k ] ) ? trim( (string) $v[ $k ] ) : '0';
			$out[] = is_numeric( $x ) && 0.0 !== (float) $x ? $x . 'px' : $x;
		}
		$color = self::color_css( isset( $a['color'] ) ? $a['color'] : '' );
		$out[] = '' !== $color ? $color : 'transparent';
		return implode( ' ', $out );
	}

	/** Filter functions the builder has fields for => unit the builder adds. */
	const FILTERS = array( 'blur' => 'px', 'brightness' => '%', 'contrast' => '%', 'hue-rotate' => 'deg', 'invert' => '%', 'opacity' => '%', 'saturate' => '%', 'sepia' => '%' );

	/**
	 * A filter list → the builder's field value, or null.
	 *
	 * @param string $css CSS value.
	 * @return array|null
	 */
	public static function filters_array( $css ) {
		$out = array();
		foreach ( self::split( trim( (string) $css ) ) as $t ) {
			if ( ! preg_match( '/^([a-z-]+)\(\s*([^()]*?)\s*\)$/i', $t, $m ) ) {
				return null;
			}
			$fn  = strtolower( $m[1] );
			$arg = $m[2];
			if ( ! isset( self::FILTERS[ $fn ] ) || isset( $out[ $fn ] ) ) {
				return null;
			}
			$unit = self::FILTERS[ $fn ];
			if ( 'px' === $unit || 'deg' === $unit ) {
				if ( ! preg_match( '/^(-?[\d.]+)(' . $unit . ')?$/', $arg, $n ) || ( empty( $n[2] ) && 0.0 !== (float) $n[1] ) ) {
					return null;
				}
				$out[ $fn ] = self::num( $n[1] );
			} elseif ( preg_match( '/^([\d.]+)%$/', $arg, $n ) ) {
				$out[ $fn ] = self::num( $n[1] );
			} elseif ( preg_match( '/^[\d.]+$/', $arg ) ) {
				$out[ $fn ] = self::num( (float) $arg * 100 );
			} else {
				return null;
			}
		}
		return $out ? $out : null;
	}

	/**
	 * The builder's filter field → CSS.
	 *
	 * @param mixed $a Field value.
	 * @return string
	 */
	public static function filters_css( $a ) {
		$out = array();
		foreach ( is_array( $a ) ? $a : array() as $fn => $v ) {
			if ( '' === (string) $v ) {
				continue;
			}
			$out[] = $fn . '(' . $v . ( isset( self::FILTERS[ $fn ] ) ? self::FILTERS[ $fn ] : '' ) . ')';
		}
		return implode( ' ', $out );
	}

	/**
	 * A transform list → the builder's field value (functions in order), or null.
	 *
	 * @param string $css CSS value.
	 * @return array|null
	 */
	public static function transform_array( $css ) {
		$out = array();
		$add = function ( $k, $v ) use ( &$out ) {
			if ( isset( $out[ $k ] ) ) {
				return false;
			}
			$out[ $k ] = $v;
			return true;
		};
		$deg = function ( $v ) {
			return preg_match( '/^(-?\d+)(deg)?$/', $v, $m ) && ( ! empty( $m[2] ) || '0' === $m[1] ) ? $m[1] : null;
		};
		foreach ( self::split( trim( (string) $css ) ) as $i => $t ) {
			if ( ! preg_match( '/^([a-zA-Z]+)\((.*)\)$/', $t, $m ) ) {
				return null;
			}
			$fn   = $m[1];
			$args = array_map( 'trim', self::split( $m[2], ',' ) );
			$ok   = true;
			switch ( $fn ) {
				case 'translate':
					$ok = $add( 'translateX', $args[0] ) && ( ! isset( $args[1] ) || $add( 'translateY', $args[1] ) );
					break;
				case 'translateX':
				case 'translateY':
					$ok = $add( $fn, $args[0] );
					break;
				case 'scale':
					$ok = $add( 'scaleX', $args[0] ) && $add( 'scaleY', isset( $args[1] ) ? $args[1] : $args[0] );
					break;
				case 'scaleX':
				case 'scaleY':
					$ok = $add( $fn, $args[0] );
					break;
				case 'rotate':
				case 'rotateZ':
				case 'rotateX':
				case 'rotateY':
					$d  = $deg( $args[0] );
					$ok = null !== $d && $add( 'rotate' === $fn ? 'rotateZ' : $fn, $d );
					break;
				case 'skew':
					$x  = $deg( $args[0] );
					$y  = isset( $args[1] ) ? $deg( $args[1] ) : '0';
					$ok = null !== $x && null !== $y && $add( 'skewX', $x ) && ( ! isset( $args[1] ) || $add( 'skewY', $y ) );
					break;
				case 'skewX':
				case 'skewY':
					$d  = $deg( $args[0] );
					$ok = null !== $d && $add( $fn, $d );
					break;
				case 'perspective':
					$ok = 0 === $i && $add( 'perspective', $args[0] ); // the builder always writes it first
					break;
				default:
					$ok = false;
			}
			if ( ! $ok || count( $args ) > 2 ) {
				return null;
			}
		}
		return $out ? $out : null;
	}

	/**
	 * The builder's transform field → CSS (the way the builder writes it).
	 *
	 * @param mixed $a Field value.
	 * @return string
	 */
	public static function transform_css( $a ) {
		$out  = '';
		$pers = '';
		foreach ( is_array( $a ) ? $a : array() as $fn => $v ) {
			$v = trim( (string) $v );
			if ( '' === $v || 0 === strpos( $fn, 'scale3d' ) ) {
				continue;
			}
			if ( in_array( $fn, array( 'translateX', 'translateY', 'perspective' ), true ) && is_numeric( $v ) ) {
				$v .= 'px';
			} elseif ( in_array( $fn, array( 'rotateX', 'rotateY', 'rotateZ', 'skewX', 'skewY' ), true ) ) {
				$v = intval( $v ) . 'deg';
			}
			if ( 'perspective' === $fn ) {
				$pers = "perspective($v)";
			} else {
				$out .= " $fn($v)";
			}
		}
		return trim( $pers . $out );
	}

	// ------------------------------------------------------------------
	// CSS → units
	// ------------------------------------------------------------------

	/**
	 * Map compiled CSS of one class block to units: ctx => [ "base|sub" => value ].
	 * ctx = "" | ":tablet_portrait" | ":hover" | ":tablet_portrait:hover".
	 *
	 * @param string $css   Compiled CSS.
	 * @param string $class Class name (without dot).
	 * @return array
	 */
	public static function css_to_units( $css, $class ) {
		list( $bps, $mobile_first ) = self::breakpoints();
		$pseudos = self::pseudos();
		$rules   = array();
		self::css_rules( $css, '', $rules );
		$units = array();
		$root  = '.' . $class;
		// Pass 1: contexts + declarations; display per context (align/gap fields depend on it).
		$ctx_decls = array();
		foreach ( $rules as $r ) {
			list( $media, $sel, $body ) = $r;
			$bp = '';
			if ( '' !== $media ) {
				$re = $mobile_first ? '/^\(\s*min-width\s*:\s*([\d.]+)px\s*\)$/' : '/^\(\s*max-width\s*:\s*([\d.]+)px\s*\)$/';
				if ( ! preg_match( $re, trim( $media ), $m ) || ! isset( $bps[ (int) round( (float) $m[1] ) ] ) ) {
					continue; // not a Bricks breakpoint: SCSS only
				}
				$bp = ':' . $bps[ (int) round( (float) $m[1] ) ];
			}
			foreach ( self::split( $sel, ',' ) as $one ) {
				$one = trim( $one );
				if ( 0 !== strpos( $one, $root ) ) {
					continue;
				}
				$rest = substr( $one, strlen( $root ) );
				if ( '' !== $rest && ! in_array( $rest, $pseudos, true ) ) {
					continue; // descendant / other selector: SCSS only
				}
				$ctx = $bp . $rest;
				foreach ( self::css_decls( $body ) as $d ) {
					$ctx_decls[ $ctx ][] = $d;
				}
			}
		}
		$display = array();
		foreach ( $ctx_decls as $ctx => $decls ) {
			foreach ( $decls as $d ) {
				if ( 'display' === $d[0] && ! $d[2] ) {
					$display[ $ctx ] = $d[1];
				}
			}
		}
		foreach ( $ctx_decls as $ctx => $decls ) {
			$disp = isset( $display[ $ctx ] ) ? $display[ $ctx ] : ( isset( $display[''] ) ? $display[''] : '' );
			$grid = false !== strpos( $disp, 'grid' );
			foreach ( $decls as $d ) {
				list( $prop, $value, $imp ) = $d;
				if ( $imp || 0 === strpos( $prop, '--' ) ) {
					continue;
				}
				foreach ( self::map_decl( $prop, $value, $grid ) as $k => $v ) {
					$units[ $ctx ][ $k ] = $v;
				}
			}
		}
		return $units;
	}

	/**
	 * One declaration → units ( "base|sub" => value ), or none.
	 *
	 * @param string $prop  Property.
	 * @param string $value Value.
	 * @param bool   $grid  Element is a grid in this context.
	 * @return array
	 */
	public static function map_decl( $prop, $value, $grid ) {
		$u = array();
		if ( isset( self::SIMPLE[ $prop ] ) ) {
			$u[ self::SIMPLE[ $prop ] . '|' ] = $value;
			return $u;
		}
		if ( isset( self::ALIGN[ $prop ] ) ) {
			$key = self::ALIGN[ $prop ][ $grid ? 1 : 0 ];
			if ( '' !== $key ) {
				$u[ $key . '|' ] = $value;
			}
			return $u;
		}
		if ( 'gap' === $prop || 'grid-gap' === $prop ) {
			if ( $grid ) {
				$u['_gridGap|'] = $value;
			} else {
				$p = self::split( $value );
				if ( count( $p ) <= 2 ) {
					$u['_rowGap|']    = $p[0];
					$u['_columnGap|'] = isset( $p[1] ) ? $p[1] : $p[0];
				}
			}
			return $u;
		}
		if ( 'inset' === $prop ) {
			$b = self::box( $value );
			if ( $b ) {
				foreach ( self::SIDES as $i => $s ) {
					$u[ '_' . $s . '|' ] = $b[ $i ];
				}
			}
			return $u;
		}
		// Padding / margin.
		if ( preg_match( '/^(padding|margin)(?:-(top|right|bottom|left|block|inline)(?:-(start|end))?)?$/', $prop, $m ) ) {
			$base = '_' . $m[1];
			$side = isset( $m[2] ) ? $m[2] : '';
			$edge = isset( $m[3] ) ? $m[3] : '';
			if ( '' === $side ) {
				$b = self::box( $value );
				if ( $b ) {
					foreach ( self::SIDES as $i => $s ) {
						$u[ "$base|$s" ] = $b[ $i ];
					}
				}
			} elseif ( in_array( $side, self::SIDES, true ) ) {
				$u[ "$base|$side" ] = $value;
			} else {
				$p     = self::split( $value );
				$pair  = 'block' === $side ? array( 'top', 'bottom' ) : array( 'left', 'right' );
				if ( 'start' === $edge ) {
					$u[ "$base|{$pair[0]}" ] = $value;
				} elseif ( 'end' === $edge ) {
					$u[ "$base|{$pair[1]}" ] = $value;
				} elseif ( count( $p ) <= 2 ) {
					$u[ "$base|{$pair[0]}" ] = $p[0];
					$u[ "$base|{$pair[1]}" ] = isset( $p[1] ) ? $p[1] : $p[0];
				}
			}
			return $u;
		}
		// Border radius.
		if ( 'border-radius' === $prop ) {
			if ( false === strpos( $value, '/' ) ) {
				$b = self::box( $value );
				if ( $b ) {
					foreach ( self::SIDES as $i => $s ) {
						$u[ "_border|radius|$s" ] = $b[ $i ];
					}
				}
			}
			return $u;
		}
		$corners = array( 'border-top-left-radius' => 'top', 'border-top-right-radius' => 'right', 'border-bottom-right-radius' => 'bottom', 'border-bottom-left-radius' => 'left' );
		if ( isset( $corners[ $prop ] ) ) {
			$u[ '_border|radius|' . $corners[ $prop ] ] = $value;
			return $u;
		}
		// Border.
		if ( preg_match( '/^border(?:-(top|right|bottom|left))?(?:-(width|style|color))?$/', $prop, $m ) ) {
			$sides = ! empty( $m[1] ) ? array( $m[1] ) : self::SIDES;
			$what  = isset( $m[2] ) ? $m[2] : '';
			if ( 'width' === $what ) {
				$b = empty( $m[1] ) ? self::box( $value ) : null;
				foreach ( $sides as $i => $s ) {
					$u[ "_border|width|$s" ] = $b ? $b[ array_search( $s, self::SIDES, true ) ] : $value;
				}
			} elseif ( 'style' === $what ) {
				if ( 1 === count( self::split( $value ) ) ) {
					$u['_border|style'] = $value;
				}
			} elseif ( 'color' === $what ) {
				if ( 1 === count( self::split( $value ) ) ) {
					$u['_border|color'] = $value;
				}
			} else {
				// Shorthand: width style color in any order.
				$parts = self::split( $value );
				if ( 1 === count( $parts ) && preg_match( '/^var\(/', $parts[0] ) ) {
					return array(); // e.g. var(--border): can't be split into fields
				}
				foreach ( $parts as $t ) {
					if ( in_array( strtolower( $t ), self::BORDER_STYLES, true ) ) {
						$u['_border|style'] = strtolower( $t );
					} elseif ( self::lengthish( $t ) ) {
						foreach ( $sides as $s ) {
							$u[ "_border|width|$s" ] = $t;
						}
					} else {
						$u['_border|color'] = $t;
					}
				}
			}
			return $u;
		}
		// Shadows, filters, transform: fields with their own shape. The unit holds the
		// CSS the builder would write for the field, so both sides compare equal.
		if ( 'box-shadow' === $prop || 'text-shadow' === $prop ) {
			$box = 'box-shadow' === $prop;
			$a   = self::shadow_array( $value, $box );
			if ( null !== $a ) {
				$u[ $box ? '_boxShadow|' : '_typography|text-shadow' ] = self::shadow_css( $a, $box );
			}
			return $u;
		}
		if ( 'filter' === $prop ) {
			$a = self::filters_array( $value );
			if ( null !== $a ) {
				$u['_cssFilters|'] = self::filters_css( $a );
			}
			return $u;
		}
		if ( 'transform' === $prop ) {
			$a = self::transform_array( $value );
			if ( null !== $a ) {
				$u['_transform|'] = self::transform_css( $a );
			}
			return $u;
		}
		// Typography.
		if ( in_array( $prop, self::TYPO, true ) ) {
			$u[ "_typography|$prop" ] = $value;
			return $u;
		}
		// Background colour.
		if ( 'background-color' === $prop || ( 'background' === $prop && 1 === count( self::split( $value ) ) && ! preg_match( '/url\(|gradient\(/i', $value ) ) ) {
			$u['_background|color'] = $value;
		}
		return $u;
	}

	// ------------------------------------------------------------------
	// Bricks settings ↔ units
	// ------------------------------------------------------------------

	/**
	 * Setting base keys TokenForge manages.
	 *
	 * @return string[]
	 */
	private static function managed_bases() {
		$b = array_values( self::SIMPLE );
		foreach ( self::ALIGN as $pair ) {
			$b = array_merge( $b, array_filter( $pair ) );
		}
		return array_merge( $b, array( '_gridGap', '_gap', '_flexDirection', '_padding', '_margin', '_border', '_typography', '_background', '_boxShadow', '_cssFilters', '_transform' ) );
	}

	/**
	 * A Bricks colour value → CSS.
	 *
	 * @param mixed $c Colour setting.
	 * @return string
	 */
	private static function color_css( $c ) {
		if ( is_string( $c ) ) {
			return $c;
		}
		if ( ! is_array( $c ) ) {
			return '';
		}
		foreach ( array( 'raw', 'hex', 'rgb', 'hsl' ) as $k ) {
			if ( ! empty( $c[ $k ] ) && is_string( $c[ $k ] ) ) {
				return $c[ $k ];
			}
		}
		return '';
	}

	/**
	 * A Bricks number (unitless = px) → CSS.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function num_css( $v ) {
		$v = trim( (string) $v );
		return ( is_numeric( $v ) && 0.0 !== (float) $v ) ? $v . 'px' : $v;
	}

	/**
	 * Bricks class settings → units (managed fields only).
	 *
	 * @param array $settings Class settings.
	 * @return array ctx => [ "base|sub" => value ]
	 */
	public static function settings_to_units( $settings ) {
		$units   = array();
		$managed = array_flip( self::managed_bases() );
		foreach ( (array) $settings as $key => $val ) {
			$pos  = strpos( $key, ':' );
			$base = false === $pos ? $key : substr( $key, 0, $pos );
			$ctx  = false === $pos ? '' : substr( $key, $pos );
			if ( ! isset( $managed[ $base ] ) ) {
				continue;
			}
			if ( '_padding' === $base || '_margin' === $base ) {
				foreach ( self::SIDES as $s ) {
					if ( isset( $val[ $s ] ) && '' !== (string) $val[ $s ] ) {
						$units[ $ctx ][ "$base|$s" ] = self::num_css( $val[ $s ] );
					}
				}
			} elseif ( '_border' === $base ) {
				foreach ( array( 'width', 'radius' ) as $part ) {
					foreach ( self::SIDES as $s ) {
						if ( isset( $val[ $part ][ $s ] ) && '' !== (string) $val[ $part ][ $s ] ) {
							$unit = isset( $val[ $part ]['unit'][ $s ] ) && ! in_array( $val[ $part ]['unit'][ $s ], array( '', '-', 'none' ), true ) ? $val[ $part ]['unit'][ $s ] : '';
							$v    = (string) $val[ $part ][ $s ];
							$units[ $ctx ][ "_border|$part|$s" ] = '' !== $unit && false === strpos( $v, $unit ) ? $v . $unit : self::num_css( $v );
						}
					}
				}
				if ( ! empty( $val['style'] ) ) {
					$units[ $ctx ]['_border|style'] = (string) $val['style'];
				}
				if ( isset( $val['color'] ) && '' !== self::color_css( $val['color'] ) ) {
					$units[ $ctx ]['_border|color'] = self::color_css( $val['color'] );
				}
			} elseif ( '_typography' === $base ) {
				foreach ( self::TYPO as $t ) {
					if ( ! isset( $val[ $t ] ) ) {
						continue;
					}
					$v = 'color' === $t ? self::color_css( $val[ $t ] ) : ( is_scalar( $val[ $t ] ) ? (string) $val[ $t ] : '' );
					if ( '' !== $v ) {
						$units[ $ctx ][ "_typography|$t" ] = $v;
					}
				}
				if ( isset( $val['text-shadow'] ) && is_array( $val['text-shadow'] ) && ! empty( $val['text-shadow']['values'] ) ) {
					$units[ $ctx ]['_typography|text-shadow'] = self::shadow_css( $val['text-shadow'], false );
				}
			} elseif ( '_boxShadow' === $base ) {
				if ( is_array( $val ) && ! empty( $val['values'] ) ) {
					$units[ $ctx ]['_boxShadow|'] = self::shadow_css( $val, true );
				}
			} elseif ( '_cssFilters' === $base ) {
				$css = self::filters_css( $val );
				if ( '' !== $css ) {
					$units[ $ctx ]['_cssFilters|'] = $css;
				}
			} elseif ( '_transform' === $base ) {
				$css = self::transform_css( $val );
				if ( '' !== $css ) {
					$units[ $ctx ]['_transform|'] = $css;
				}
			} elseif ( '_background' === $base ) {
				if ( isset( $val['color'] ) && '' !== self::color_css( $val['color'] ) ) {
					$units[ $ctx ]['_background|color'] = self::color_css( $val['color'] );
				}
			} elseif ( is_scalar( $val ) && '' !== (string) $val ) {
				$b = '_flexDirection' === $base ? '_direction' : ( '_gap' === $base ? '_gridGap' : $base );
				$units[ $ctx ][ "$b|" ] = (string) $val;
			}
		}
		return $units;
	}

	/**
	 * Write units into class settings; remove the ones listed in $remove.
	 *
	 * @param array $settings Settings.
	 * @param array $units    ctx => units to set.
	 * @param array $remove   ctx => [ unit keys ] to delete.
	 * @return array
	 */
	public static function apply_units( $settings, $units, $remove = array() ) {
		$settings = is_array( $settings ) ? $settings : array();
		foreach ( $remove as $ctx => $keys ) {
			foreach ( $keys as $uk ) {
				list( $base, $sub ) = explode( '|', $uk, 2 );
				$key = $base . $ctx;
				if ( '' === $sub ) {
					unset( $settings[ $key ] );
					if ( '_direction' === $base ) {
						unset( $settings[ '_flexDirection' . $ctx ] );
					}
					continue;
				}
				$path = explode( '|', $sub );
				if ( 2 === count( $path ) ) {
					unset( $settings[ $key ][ $path[0] ][ $path[1] ] );
					if ( empty( $settings[ $key ][ $path[0] ] ) || array( 'unit' ) === array_keys( $settings[ $key ][ $path[0] ] ) ) {
						unset( $settings[ $key ][ $path[0] ] );
					}
				} else {
					unset( $settings[ $key ][ $path[0] ] );
				}
				if ( isset( $settings[ $key ] ) && empty( $settings[ $key ] ) ) {
					unset( $settings[ $key ] );
				}
			}
		}
		foreach ( $units as $ctx => $list ) {
			foreach ( $list as $uk => $v ) {
				list( $base, $sub ) = explode( '|', $uk, 2 );
				$key = $base . $ctx;
				if ( '' === $sub ) {
					// Fields with their own shape get it back from the CSS.
					if ( '_boxShadow' === $base ) {
						$v = self::shadow_array( $v, true );
					} elseif ( '_cssFilters' === $base ) {
						$v = self::filters_array( $v );
					} elseif ( '_transform' === $base ) {
						$v = self::transform_array( $v );
					}
					if ( null === $v ) {
						unset( $settings[ $key ] );
					} else {
						$settings[ $key ] = $v;
					}
					continue;
				}
				if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
					$settings[ $key ] = array();
				}
				$path  = explode( '|', $sub );
				if ( array( 'text-shadow' ) === $path ) {
					$a = self::shadow_array( $v, false );
					if ( null === $a ) {
						unset( $settings[ $key ]['text-shadow'] );
					} else {
						$settings[ $key ]['text-shadow'] = $a;
					}
					continue;
				}
				$color = 'color' === end( $path );
				if ( 2 === count( $path ) ) {
					if ( ! isset( $settings[ $key ][ $path[0] ] ) || ! is_array( $settings[ $key ][ $path[0] ] ) ) {
						$settings[ $key ][ $path[0] ] = array();
					}
					$settings[ $key ][ $path[0] ][ $path[1] ] = $v;
					unset( $settings[ $key ][ $path[0] ]['unit'][ $path[1] ] ); // the value carries its unit
				} else {
					$settings[ $key ][ $path[0] ] = $color ? self::color_setting( $v ) : $v;
				}
			}
		}
		return $settings;
	}

	/**
	 * A colour value as the builder stores it. var(--x) of a colour in the
	 * TokenForge palette carries that palette colour's id, so the builder's colour
	 * field shows it as the palette colour.
	 *
	 * @param string $v CSS value.
	 * @return array
	 */
	private static function color_setting( $v ) {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array();
			foreach ( (array) get_option( 'bricks_color_palette', array() ) as $p ) {
				if ( isset( $p['id'], $p['colors'] ) && Bricks::CATEGORY_ID === $p['id'] && is_array( $p['colors'] ) ) {
					foreach ( $p['colors'] as $c ) {
						if ( isset( $c['id'], $c['raw'] ) ) {
							$ids[ $c['raw'] ] = array( 'id' => $c['id'], 'name' => isset( $c['name'] ) ? $c['name'] : '' );
						}
					}
				}
			}
		}
		$v = (string) $v;
		return isset( $ids[ $v ] ) ? array( 'id' => $ids[ $v ]['id'], 'name' => $ids[ $v ]['name'], 'raw' => $v ) : array( 'raw' => $v );
	}

	/**
	 * Colours synced before the palette had them ({ raw: "var(--danger)" }) get
	 * the palette colour's id too (text, background and border colours, every
	 * breakpoint and state).
	 *
	 * @param array $settings Class settings.
	 * @return array
	 */
	private static function link_palette_colors( $settings ) {
		foreach ( $settings as $key => $val ) {
			if ( ! is_array( $val ) || ! preg_match( '/^_(typography|background|border)(:|$)/', (string) $key ) || ! isset( $val['color'] ) ) {
				continue;
			}
			$c = $val['color'];
			if ( is_array( $c ) && ! isset( $c['id'] ) && isset( $c['raw'] ) && 1 === count( $c ) ) {
				$settings[ $key ]['color'] = self::color_setting( $c['raw'] );
			}
		}
		return $settings;
	}

	// ------------------------------------------------------------------
	// Units → SCSS
	// ------------------------------------------------------------------

	/**
	 * The family a unit belongs to (rewritten together in SCSS).
	 *
	 * @param string $uk Unit key "base|sub".
	 * @return string
	 */
	private static function family( $uk ) {
		list( $base, $sub ) = explode( '|', $uk, 2 );
		if ( '_padding' === $base || '_margin' === $base ) {
			return substr( $base, 1 );
		}
		if ( '_border' === $base ) {
			return 0 === strpos( $sub, 'radius' ) ? 'radius' : 'border';
		}
		if ( '_typography' === $base ) {
			return $sub;
		}
		if ( '_background' === $base ) {
			return 'background-color';
		}
		$own = array( '_boxShadow' => 'box-shadow', '_cssFilters' => 'filter', '_transform' => 'transform' );
		if ( isset( $own[ $base ] ) ) {
			return $own[ $base ];
		}
		if ( in_array( $base, array( '_rowGap', '_columnGap', '_gridGap', '_gap' ), true ) ) {
			return 'gap';
		}
		foreach ( self::ALIGN as $prop => $pair ) {
			if ( in_array( $base, $pair, true ) ) {
				return $prop;
			}
		}
		$css = array_search( $base, self::SIMPLE, true );
		return false === $css ? '' : $css;
	}

	/**
	 * Regex of the CSS properties a family owns (to remove before rewriting).
	 *
	 * @param string $family Family.
	 * @return string
	 */
	private static function family_regex( $family ) {
		switch ( $family ) {
			case 'padding':
			case 'margin':
				return '/^' . $family . '(-(top|right|bottom|left|block|inline)(-(start|end))?)?$/';
			case 'border':
				return '/^border(-(top|right|bottom|left))?(-(width|style|color))?$/';
			case 'radius':
				return '/^border(-(top|bottom)-(left|right))?-radius$/';
			case 'gap':
				return '/^(gap|row-gap|column-gap|grid-gap)$/';
			case 'background-color':
				return '/^background(-color)?$/';
		}
		return '/^' . preg_quote( $family, '/' ) . '$/';
	}

	/**
	 * Collapse four box values to the shortest shorthand.
	 *
	 * @param array $b [ top, right, bottom, left ].
	 * @return string
	 */
	private static function box_css( $b ) {
		if ( $b[1] === $b[3] ) {
			if ( $b[0] === $b[2] ) {
				return $b[0] === $b[1] ? $b[0] : $b[0] . ' ' . $b[1];
			}
			return $b[0] . ' ' . $b[1] . ' ' . $b[2];
		}
		return implode( ' ', $b );
	}

	/**
	 * CSS declarations for one family from the units of a context.
	 *
	 * @param string $family Family.
	 * @param array  $u      Units of the context.
	 * @return string[] "prop: value" lines.
	 */
	private static function family_css( $family, $u ) {
		$out = array();
		$get = function ( $k ) use ( $u ) {
			return isset( $u[ $k ] ) ? $u[ $k ] : null;
		};
		switch ( $family ) {
			case 'padding':
			case 'margin':
				$b = array();
				foreach ( self::SIDES as $s ) {
					$b[] = $get( "_$family|$s" );
				}
				if ( ! in_array( null, $b, true ) ) {
					$out[] = "$family: " . self::box_css( $b );
				} else {
					foreach ( self::SIDES as $i => $s ) {
						if ( null !== $b[ $i ] ) {
							$out[] = "$family-$s: {$b[ $i ]}";
						}
					}
				}
				break;
			case 'border':
				$w = array();
				foreach ( self::SIDES as $s ) {
					$w[] = $get( "_border|width|$s" );
				}
				if ( ! in_array( null, $w, true ) && 1 === count( array_unique( $w ) ) && null !== $get( '_border|style' ) && null !== $get( '_border|color' ) ) {
					$out[] = 'border: ' . $w[0] . ' ' . $get( '_border|style' ) . ' ' . $get( '_border|color' );
					break;
				}
				if ( ! in_array( null, $w, true ) ) {
					$out[] = 'border-width: ' . self::box_css( $w );
				} else {
					foreach ( self::SIDES as $i => $s ) {
						if ( null !== $w[ $i ] ) {
							$out[] = "border-$s-width: {$w[ $i ]}";
						}
					}
				}
				if ( null !== $get( '_border|style' ) ) {
					$out[] = 'border-style: ' . $get( '_border|style' );
				}
				if ( null !== $get( '_border|color' ) ) {
					$out[] = 'border-color: ' . $get( '_border|color' );
				}
				break;
			case 'radius':
				$r = array();
				foreach ( self::SIDES as $s ) {
					$r[] = $get( "_border|radius|$s" );
				}
				if ( ! in_array( null, $r, true ) ) {
					$out[] = 'border-radius: ' . self::box_css( $r );
				} else {
					$names = array( 'top' => 'border-top-left-radius', 'right' => 'border-top-right-radius', 'bottom' => 'border-bottom-right-radius', 'left' => 'border-bottom-left-radius' );
					foreach ( self::SIDES as $i => $s ) {
						if ( null !== $r[ $i ] ) {
							$out[] = "{$names[ $s ]}: {$r[ $i ]}";
						}
					}
				}
				break;
			case 'gap':
				if ( null !== $get( '_gridGap|' ) ) {
					$out[] = 'gap: ' . $get( '_gridGap|' );
				} elseif ( null !== $get( '_rowGap|' ) && $get( '_rowGap|' ) === $get( '_columnGap|' ) ) {
					$out[] = 'gap: ' . $get( '_rowGap|' );
				} else {
					if ( null !== $get( '_rowGap|' ) ) {
						$out[] = 'row-gap: ' . $get( '_rowGap|' );
					}
					if ( null !== $get( '_columnGap|' ) ) {
						$out[] = 'column-gap: ' . $get( '_columnGap|' );
					}
				}
				break;
			case 'background-color':
				if ( null !== $get( '_background|color' ) ) {
					$out[] = 'background-color: ' . $get( '_background|color' );
				}
				break;
			case 'box-shadow':
			case 'filter':
			case 'transform':
				$k = array( 'box-shadow' => '_boxShadow|', 'filter' => '_cssFilters|', 'transform' => '_transform|' )[ $family ];
				if ( null !== $get( $k ) ) {
					$out[] = "$family: " . $get( $k );
				}
				break;
			case 'text-shadow':
				if ( null !== $get( '_typography|text-shadow' ) ) {
					$out[] = 'text-shadow: ' . $get( '_typography|text-shadow' );
				}
				break;
			default:
				if ( in_array( $family, self::TYPO, true ) ) {
					if ( null !== $get( "_typography|$family" ) ) {
						$out[] = "$family: " . $get( "_typography|$family" );
					}
				} elseif ( isset( self::ALIGN[ $family ] ) ) {
					foreach ( self::ALIGN[ $family ] as $k ) {
						if ( '' !== $k && null !== $get( "$k|" ) ) {
							$out[] = "$family: " . $get( "$k|" );
							break;
						}
					}
				} elseif ( isset( self::SIMPLE[ $family ] ) && null !== $get( self::SIMPLE[ $family ] . '|' ) ) {
					$out[] = "$family: " . $get( self::SIMPLE[ $family ] . '|' );
				}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// SCSS source structure
	// ------------------------------------------------------------------

	/**
	 * Index just past a comment/string/interpolation starting at $i, or $i.
	 *
	 * @param string $s     Source.
	 * @param int    $i     Index.
	 * @param int    $paren Parenthesis depth (// is a comment only outside parentheses).
	 * @return int
	 */
	private static function skip( $s, $i, $paren = 0 ) {
		$n  = strlen( $s );
		$c  = $s[ $i ];
		$c2 = $i + 1 < $n ? $s[ $i + 1 ] : '';
		if ( '/' === $c && '*' === $c2 ) {
			$e = strpos( $s, '*/', $i + 2 );
			return false === $e ? $n : $e + 2;
		}
		if ( '/' === $c && '/' === $c2 && 0 === $paren ) {
			$e = strpos( $s, "\n", $i );
			return false === $e ? $n : $e;
		}
		if ( '"' === $c || "'" === $c ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( '\\' === $s[ $j ] ) {
					$j++;
				} elseif ( $s[ $j ] === $c ) {
					return $j + 1;
				}
			}
			return $n;
		}
		if ( '#' === $c && '{' === $c2 ) {
			$d = 0;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( '{' === $s[ $j ] ) {
					$d++;
				} elseif ( '}' === $s[ $j ] && 0 === --$d ) {
					return $j + 1;
				}
			}
			return $n;
		}
		return $i;
	}

	/**
	 * Items (statements and blocks) between $from and $to.
	 *
	 * @param string $s    Source.
	 * @param int    $from Start.
	 * @param int    $to   End (exclusive).
	 * @return array [ [ type, start, end, header|text, open, close ], … ]
	 */
	public static function items( $s, $from, $to ) {
		$items = array();
		$i     = $from;
		$start = $from;
		$paren = 0;
		while ( $i < $to ) {
			$k = self::skip( $s, $i, $paren );
			if ( $k !== $i ) {
				$i = $k;
				continue;
			}
			$c = $s[ $i ];
			if ( '(' === $c ) {
				$paren++;
			} elseif ( ')' === $c ) {
				$paren = max( 0, $paren - 1 );
			} elseif ( '{' === $c && 0 === $paren ) {
				$close = self::match( $s, $i, $to );
				$items[] = array( 'type' => 'block', 'start' => self::content_start( $s, $start, $i ), 'end' => $close + 1, 'header' => trim( self::strip_comments( substr( $s, $start, $i - $start ) ) ), 'open' => $i, 'close' => $close );
				$i     = $close + 1;
				$start = $i;
				continue;
			} elseif ( ';' === $c && 0 === $paren ) {
				$text = self::strip_comments( substr( $s, $start, $i - $start ) );
				if ( '' !== trim( $text ) ) {
					$items[] = array( 'type' => 'stmt', 'start' => self::content_start( $s, $start, $i ), 'end' => $i + 1, 'text' => trim( $text ) );
				}
				$start = $i + 1;
			}
			$i++;
		}
		$tail = self::strip_comments( substr( $s, $start, $to - $start ) );
		if ( '' !== trim( $tail ) ) {
			$items[] = array( 'type' => 'stmt', 'start' => self::content_start( $s, $start, $to ), 'end' => $to, 'text' => trim( $tail ), 'open_end' => true );
		}
		return $items;
	}

	/**
	 * Index of the "}" matching the "{" at $open.
	 *
	 * @param string $s    Source.
	 * @param int    $open Index of "{".
	 * @param int    $to   Limit.
	 * @return int
	 */
	private static function match( $s, $open, $to ) {
		$d = 0;
		$paren = 0;
		for ( $i = $open; $i < $to; ) {
			$k = self::skip( $s, $i, $paren );
			if ( $k !== $i ) {
				$i = $k;
				continue;
			}
			$c = $s[ $i ];
			if ( '(' === $c ) {
				$paren++;
			} elseif ( ')' === $c ) {
				$paren = max( 0, $paren - 1 );
			} elseif ( '{' === $c ) {
				$d++;
			} elseif ( '}' === $c && 0 === --$d ) {
				return $i;
			}
			$i++;
		}
		return $to - 1;
	}

	/**
	 * First index of real content (skipping whitespace and comments).
	 *
	 * @param string $s    Source.
	 * @param int    $from Start.
	 * @param int    $to   End.
	 * @return int
	 */
	private static function content_start( $s, $from, $to ) {
		$i = $from;
		while ( $i < $to ) {
			if ( ctype_space( $s[ $i ] ) ) {
				$i++;
				continue;
			}
			$k = self::skip( $s, $i );
			if ( $k !== $i && ( '/' === $s[ $i ] ) ) {
				$i = $k;
				continue;
			}
			break;
		}
		return $i;
	}

	/**
	 * Remove comments from a snippet.
	 *
	 * @param string $t Text.
	 * @return string
	 */
	private static function strip_comments( $t ) {
		return preg_replace( array( '#/\*.*?\*/#s', '#(^|[^:])//[^\n]*#' ), array( '', '$1' ), $t );
	}

	/**
	 * Top-level class blocks: name => item.
	 *
	 * @param string $src SCSS.
	 * @return array
	 */
	public static function class_blocks( $src ) {
		$out = array();
		foreach ( self::items( $src, 0, strlen( $src ) ) as $it ) {
			if ( 'block' === $it['type'] && preg_match( '/^\.(-?[_a-zA-Z][\w-]*)$/', $it['header'], $m ) && ! isset( $out[ $m[1] ] ) ) {
				$out[ $m[1] ] = $it;
			}
		}
		return $out;
	}

	/**
	 * Indentation of the line containing index $i.
	 *
	 * @param string $s Source.
	 * @param int    $i Index.
	 * @return string
	 */
	private static function indent_at( $s, $i ) {
		$ls = strrpos( substr( $s, 0, $i ), "\n" );
		$ls = false === $ls ? 0 : $ls + 1;
		preg_match( '/^[ \t]*/', substr( $s, $ls ), $m );
		return $m[0];
	}

	/**
	 * Header of the nested block for a context piece (breakpoint or pseudo).
	 *
	 * @param string $bp     Bricks breakpoint key ('' for none).
	 * @return string
	 */
	private static function bp_header( $bp ) {
		list( $bps, $mobile_first ) = self::breakpoints();
		$width = array_search( $bp, $bps, true );
		foreach ( Scss::breakpoints() as $name => $px ) {
			if ( (int) $px === (int) $width ) {
				return ( $mobile_first ? '@include breakpoint-up(' : '@include breakpoint(' ) . $name . ')';
			}
		}
		return '@media (' . ( $mobile_first ? 'min' : 'max' ) . '-width: ' . (int) $width . 'px)';
	}

	/**
	 * Does a nested block header stand for this breakpoint?
	 *
	 * @param string $header Header.
	 * @param string $bp     Bricks breakpoint key.
	 * @return bool
	 */
	private static function header_is_bp( $header, $bp ) {
		list( $bps, $mobile_first ) = self::breakpoints();
		$width = (int) array_search( $bp, $bps, true );
		if ( preg_match( '/^@include\s+breakpoint(-up)?\(\s*([\w-]+)\s*\)$/', $header, $m ) ) {
			$tf = Scss::breakpoints();
			return ( '' !== $m[1] ) === $mobile_first && isset( $tf[ $m[2] ] ) && (int) $tf[ $m[2] ] === $width;
		}
		if ( preg_match( '/^@media\s*\(\s*(min|max)-width\s*:\s*([\d.]+)px\s*\)$/', $header, $m ) ) {
			return ( 'min' === $m[1] ) === $mobile_first && (int) round( (float) $m[2] ) === $width;
		}
		return false;
	}

	/**
	 * Rewrite families of one context inside a class block.
	 *
	 * @param string $src      SCSS source.
	 * @param array  $block    Class block item (from class_blocks()).
	 * @param string $ctx      Context ("", ":hover", ":tablet_portrait", ":tablet_portrait:hover").
	 * @param array  $families Families to rewrite.
	 * @param array  $units    Current units of that context.
	 * @return string New source.
	 */
	public static function write_context( $src, $block, $ctx, $families, $units ) {
		list( $bps ) = self::breakpoints();
		$bp     = '';
		$pseudo = $ctx;
		if ( preg_match( '/^:([a-z_]+)(.*)$/i', $ctx, $m ) && in_array( $m[1], $bps, true ) ) {
			$bp     = $m[1];
			$pseudo = $m[2];
		}
		$lines = array();
		foreach ( $families as $f ) {
			$lines = array_merge( $lines, self::family_css( $f, $units ) );
		}
		// Find (or create) the target body: [ open, close ].
		$target = array( $block['open'], $block['close'] );
		foreach ( array( $bp, $pseudo ) as $level => $piece ) {
			if ( '' === $piece ) {
				continue;
			}
			$header = 0 === $level ? self::bp_header( $piece ) : '&' . $piece;
			$found  = self::find_child( $src, $target, $level, $piece );
			if ( ! $found ) {
				if ( ! $lines ) {
					return $src; // nothing to write, nothing to remove
				}
				$pind   = self::indent_at( $src, $target[0] );
				$before = rtrim( substr( $src, 0, $target[1] ) );
				$src    = $before . "\n" . $pind . "\t" . $header . " {}\n" . $pind . substr( $src, $target[1] );
				$target = array( $target[0], self::match( $src, $target[0], strlen( $src ) ) );
				$found  = self::find_child( $src, $target, $level, $piece );
				if ( ! $found ) {
					return $src;
				}
			}
			$target = $found;
		}
		// Remove the family's declarations in this body.
		$items  = self::items( $src, $target[0] + 1, $target[1] );
		$remove = array();
		$regs   = array_map( array( __CLASS__, 'family_regex' ), $families );
		$last   = null;
		foreach ( $items as $it ) {
			if ( 'stmt' !== $it['type'] ) {
				continue;
			}
			if ( '@' !== $it['text'][0] && preg_match( '/^([a-zA-Z-]+)\s*:/', $it['text'], $pm ) ) {
				$prop = strtolower( $pm[1] );
				$hit  = false;
				foreach ( $regs as $re ) {
					if ( preg_match( $re, $prop ) ) {
						$hit = true;
						break;
					}
				}
				if ( 'background' === $prop && preg_match( '/url\(|gradient\(/i', $it['text'] ) ) {
					$hit = false; // a background image stays
				}
				if ( $hit ) {
					$remove[] = $it;
					continue;
				}
			}
			$last = $it;
		}
		$ind = self::indent_at( $src, $target[0] ) . "\t";
		foreach ( $items as $it ) {
			$ls = strrpos( substr( $src, 0, $it['start'] ), "\n" );
			if ( false !== $ls && $ls > $target[0] ) {
				$ind = self::indent_at( $src, $it['start'] );
				break;
			}
		}
		// Insertion point: where the property was, else after the last kept statement (so
		// it overrides @includes), else the body start.
		$at = $last ? $last['end'] : $target[0] + 1;
		if ( $remove ) {
			$prev_end = $target[0] + 1;
			foreach ( $items as $it ) {
				if ( $it === $remove[0] ) {
					break;
				}
				$prev_end = $it['end'];
			}
			$at = $prev_end;
		}
		$text = '';
		foreach ( $lines as $l ) {
			$text .= "\n" . $ind . $l . ';';
		}
		if ( ! $last && ! $items && '' !== $text ) {
			$text .= "\n" . self::indent_at( $src, $target[0] ); // "{}" → one declaration per line, "}" below
		}
		// Apply removals (bottom-up) and the insertion.
		$edits = array();
		foreach ( $remove as $it ) {
			$s = $it['start'];
			$e = $it['end'];
			// Take the whole line when the statement is alone on it.
			$ls = strrpos( substr( $src, 0, $s ), "\n" );
			if ( false !== $ls && '' === trim( substr( $src, $ls + 1, $s - $ls - 1 ) ) ) {
				$s = $ls;
			}
			$edits[] = array( $s, $e, '' );
		}
		$edits[] = array( $at, $at, $text );
		usort( $edits, function ( $a, $b ) { return $b[0] - $a[0] ?: $b[1] - $a[1]; } );
		foreach ( $edits as $e ) {
			$src = substr( $src, 0, $e[0] ) . $e[2] . substr( $src, $e[1] );
		}
		return self::drop_empty_contexts( $src );
	}

	/**
	 * A nested breakpoint (level 0) or "&:pseudo" (level 1) block inside a body.
	 *
	 * @param string $src    Source.
	 * @param array  $target [ open, close ] of the parent body.
	 * @param int    $level  0 = breakpoint, 1 = pseudo.
	 * @param string $piece  Breakpoint key or pseudo.
	 * @return array|null [ open, close ]
	 */
	private static function find_child( $src, $target, $level, $piece ) {
		foreach ( self::items( $src, $target[0] + 1, $target[1] ) as $it ) {
			if ( 'block' !== $it['type'] ) {
				continue;
			}
			if ( 0 === $level ? self::header_is_bp( $it['header'], $piece ) : preg_replace( '/\s+/', '', $it['header'] ) === '&' . $piece ) {
				return array( $it['open'], $it['close'] );
			}
		}
		return null;
	}

	/**
	 * Remove nested "&:pseudo {}" / breakpoint blocks left empty inside class blocks.
	 *
	 * @param string $src Source.
	 * @return string
	 */
	private static function drop_empty_contexts( $src ) {
		for ( $guard = 0; $guard < 20; $guard++ ) {
			$changed = false;
			foreach ( self::class_blocks( $src ) as $block ) {
				foreach ( self::nested_blocks( $src, $block['open'] + 1, $block['close'] ) as $it ) {
					if ( ! preg_match( '/^(&:|@include\s+breakpoint|@media)/', $it['header'] ) ) {
						continue;
					}
					if ( '' === trim( self::strip_comments( substr( $src, $it['open'] + 1, $it['close'] - $it['open'] - 1 ) ) ) ) {
						$s  = $it['start'];
						$ls = strrpos( substr( $src, 0, $s ), "\n" );
						if ( false !== $ls && '' === trim( substr( $src, $ls + 1, $s - $ls - 1 ) ) ) {
							$s = $ls;
						}
						$src     = substr( $src, 0, $s ) . substr( $src, $it['end'] );
						$changed = true;
						break 2;
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $src;
	}

	/**
	 * Blocks inside a range, depth-first (children before parents).
	 *
	 * @param string $src  Source.
	 * @param int    $from Start.
	 * @param int    $to   End.
	 * @return array
	 */
	private static function nested_blocks( $src, $from, $to ) {
		$out = array();
		foreach ( self::items( $src, $from, $to ) as $it ) {
			if ( 'block' === $it['type'] ) {
				$out   = array_merge( $out, self::nested_blocks( $src, $it['open'] + 1, $it['close'] ), array( $it ) );
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Sync
	// ------------------------------------------------------------------

	/**
	 * A block without its plain "@include mixin(...);" statements (mixins with a
	 * { } body, like breakpoint(), stay): their output isn't something you wrote
	 * for this class, so it isn't copied into Bricks fields.
	 *
	 * @param string $block Block source.
	 * @return string
	 */
	private static function without_includes( $block ) {
		$cuts = array();
		$walk = function ( $from, $to ) use ( &$walk, &$cuts, $block ) {
			foreach ( self::items( $block, $from, $to ) as $it ) {
				if ( 'stmt' === $it['type'] && 0 === strpos( $it['text'], '@include' ) ) {
					$cuts[] = array( $it['start'], $it['end'] );
				} elseif ( 'block' === $it['type'] ) {
					$walk( $it['open'] + 1, $it['close'] );
				}
			}
		};
		$walk( 0, strlen( $block ) );
		usort( $cuts, function ( $a, $b ) { return $b[0] - $a[0]; } );
		foreach ( $cuts as $c ) {
			$block = substr( $block, 0, $c[0] ) . substr( $block, $c[1] );
		}
		return $block;
	}

	// ------------------------------------------------------------------
	// The class's Custom CSS field ↔ a marked region in its SCSS block
	// ------------------------------------------------------------------

	const CUSTOM_OPEN  = '/* builder custom CSS */';
	const CUSTOM_CLOSE = '/* end builder custom CSS */';

	/**
	 * The Custom CSS region of a class block: [ start, end, inner text ], or null.
	 *
	 * @param string $src   Source.
	 * @param array  $block Class block item.
	 * @return array|null
	 */
	private static function custom_region( $src, $block ) {
		$from = $block['open'] + 1;
		$body = substr( $src, $from, $block['close'] - $from );
		$a    = strpos( $body, self::CUSTOM_OPEN );
		$b    = false === $a ? false : strpos( $body, self::CUSTOM_CLOSE, $a );
		if ( false === $a || false === $b ) {
			return null;
		}
		$inner = substr( $body, $a + strlen( self::CUSTOM_OPEN ), $b - $a - strlen( self::CUSTOM_OPEN ) );
		return array( $from + $a, $from + $b + strlen( self::CUSTOM_CLOSE ), $inner );
	}

	/**
	 * Remove the common indentation of a text.
	 *
	 * @param string $t Text.
	 * @return string
	 */
	private static function dedent( $t ) {
		$lines = explode( "\n", str_replace( "\r", '', trim( $t, "\n" ) ) );
		$min   = null;
		foreach ( $lines as $l ) {
			if ( '' !== trim( $l ) ) {
				preg_match( '/^[ \t]*/', $l, $m );
				$min = null === $min ? strlen( $m[0] ) : min( $min, strlen( $m[0] ) );
			}
		}
		return implode( "\n", array_map( function ( $l ) use ( $min ) {
			return rtrim( (string) substr( $l, (int) $min ) );
		}, $lines ) );
	}

	/**
	 * The SCSS region → the builder's Custom CSS ("&" → "%root%"; rules without
	 * "&" sit inside the class, "@at-root" rules are global).
	 *
	 * @param string $inner Region text.
	 * @return string
	 */
	public static function custom_to_bricks( $inner ) {
		$inner = self::dedent( $inner );
		$out   = array();
		$loose = array();
		foreach ( self::items( $inner, 0, strlen( $inner ) ) as $it ) {
			if ( 'block' !== $it['type'] ) {
				if ( '' !== $it['text'] ) {
					$loose[] = rtrim( $it['text'], ';' ) . ';';
				}
				continue;
			}
			$h    = $it['header'];
			$body = substr( $inner, $it['open'] + 1, $it['close'] - $it['open'] - 1 );
			if ( 0 === strpos( $h, '@at-root' ) ) {
				$sel = trim( substr( $h, 8 ) );
			} elseif ( '@' === $h[0] ) {
				$sel  = $h;
				$body = str_replace( '&', '%root%', $body );
			} else {
				$sel = implode( ', ', array_map( function ( $x ) {
					$x = trim( $x );
					return false !== strpos( $x, '&' ) ? str_replace( '&', '%root%', $x ) : '%root% ' . $x;
				}, self::split( $h, ',' ) ) );
			}
			$out[] = $sel . ' {' . rtrim( $body ) . ( false !== strpos( $body, "\n" ) ? "\n" : ' ' ) . '}';
		}
		if ( $loose ) {
			array_unshift( $out, "%root% {\n  " . implode( "\n  ", $loose ) . "\n}" );
		}
		return trim( implode( "\n", $out ) );
	}

	/**
	 * The builder's Custom CSS → SCSS for the region ("%root%" → "&"; rules
	 * without "%root%" are global: "@at-root").
	 *
	 * @param string $css Custom CSS.
	 * @return string
	 */
	public static function custom_to_scss( $css ) {
		$css = self::dedent( (string) $css );
		$out = array();
		foreach ( self::items( $css, 0, strlen( $css ) ) as $it ) {
			if ( 'block' !== $it['type'] ) {
				continue; // a declaration without a selector does nothing in Custom CSS
			}
			$h    = $it['header'];
			$body = substr( $css, $it['open'] + 1, $it['close'] - $it['open'] - 1 );
			if ( '@' === $h[0] ) {
				$sel  = $h;
				$body = str_replace( '%root%', '&', $body );
			} elseif ( false !== strpos( $h, '%root%' ) ) {
				$sel = str_replace( '%root%', '&', $h );
			} else {
				$sel = '@at-root ' . $h;
			}
			$out[] = $sel . ' {' . rtrim( $body ) . ( false !== strpos( $body, "\n" ) ? "\n" : ' ' ) . '}';
		}
		return implode( "\n", $out );
	}

	/**
	 * Write (or with '' remove) the Custom CSS region of a class block.
	 *
	 * @param string $src   Source.
	 * @param array  $block Class block item.
	 * @param string $css   The builder's Custom CSS.
	 * @return string
	 */
	private static function write_custom( $src, $block, $css ) {
		return self::write_region( $src, $block, '' === trim( (string) $css ) ? '' : self::custom_to_scss( $css ) );
	}

	/**
	 * Write (or with '' remove) the Custom CSS region of a class block from SCSS text.
	 *
	 * @param string $src   Source.
	 * @param array  $block Class block item.
	 * @param string $scss  Region content (not indented).
	 * @return string
	 */
	private static function write_region( $src, $block, $scss ) {
		$outer  = self::indent_at( $src, $block['open'] );
		$ind    = $outer . "\t";
		$scss   = rtrim( trim( (string) $scss, "\n" ) );
		$text   = '';
		if ( '' !== trim( $scss ) ) {
			$body = implode( "\n", array_map( function ( $l ) use ( $ind ) {
				return '' === trim( $l ) ? '' : $ind . $l;
			}, explode( "\n", $scss ) ) );
			$text = $ind . self::CUSTOM_OPEN . "\n" . $body . "\n" . $ind . self::CUSTOM_CLOSE;
		}
		$region = self::custom_region( $src, $block );
		if ( $region ) {
			$s  = $region[0];
			$ls = strrpos( substr( $src, 0, $s ), "\n" );
			if ( false !== $ls && '' === trim( substr( $src, $ls + 1, $s - $ls - 1 ) ) ) {
				$s = '' === $text ? $ls : $ls + 1; // the whole line
			}
			return substr( $src, 0, $s ) . $text . substr( $src, $region[1] );
		}
		if ( '' === $text ) {
			return $src;
		}
		$before = rtrim( substr( $src, 0, $block['close'] ) );
		return $before . "\n" . $text . "\n" . $outer . substr( $src, $block['close'] );
	}

	// ------------------------------------------------------------------
	// What the builder shows: Style fields + CSS panel
	// ------------------------------------------------------------------

	/**
	 * CSS in a form fit for comparing (comments, spacing and a block's last
	 * semicolon do not count).
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function canon_css( $css ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([{};,>])\s*/', '$1', $css );
		$css = preg_replace( '/\s*:\s*/', ':', $css );
		return trim( str_replace( ';}', '}', $css ) );
	}

	/**
	 * A CSS panel rule on the class itself: "%root%" or ".name", optionally with a
	 * state the builder has fields for. Returns that state ('' for none), or null.
	 *
	 * @param string $header Rule header.
	 * @param string $name   Class name.
	 * @return string|null
	 */
	private static function root_state( $header, $name ) {
		$h = self::root_header( $header, $name );
		if ( 0 !== strpos( $h, '%root%' ) ) {
			return null;
		}
		$rest = substr( $h, 6 );
		if ( '' === $rest ) {
			return '';
		}
		return in_array( $rest, self::pseudos(), true ) ? $rest : null;
	}

	/**
	 * A rule header with the class written as %root% (as the builder's CSS panel does).
	 *
	 * @param string $header Header.
	 * @param string $name   Class name.
	 * @return string
	 */
	private static function root_header( $header, $name ) {
		return trim( (string) preg_replace( '/(^|,\s*)\.' . preg_quote( $name, '/' ) . '(?![\w-])/', '$1%root%', trim( $header ) ) );
	}

	/**
	 * What the builder shows for a class: its Style fields, overridden by what its
	 * CSS panel sets (the builder lets the CSS panel win), as units; and the rest
	 * of the CSS panel (what no field holds) as builder CSS for the block's Custom
	 * CSS part. The declaration the builder adds by itself (align-items: initial
	 * for a grid) is left out. Covers the CSS panel of every breakpoint and state
	 * (_cssCustom:tablet_portrait:hover).
	 *
	 * @param array  $settings Class settings.
	 * @param string $name     Class name.
	 * @return array [ 'units' => flat units, 'extras' => builder CSS ('' for none) ]
	 */
	public static function builder_view( $settings, $name ) {
		$settings = is_array( $settings ) ? $settings : array();
		$units    = self::flat( self::settings_to_units( $settings ) );
		list( $bps, $mobile_first ) = self::breakpoints();
		$extras = array();
		ksort( $settings ); // "_cssCustom" before its breakpoint versions
		foreach ( $settings as $key => $css ) {
			if ( ! is_string( $css ) || '' === trim( $css ) || ! preg_match( '/^_cssCustom(:.*)?$/', (string) $key, $km ) ) {
				continue;
			}
			$ctx    = isset( $km[1] ) ? $km[1] : '';
			$bp     = '';
			$pseudo = $ctx;
			if ( preg_match( '/^:([a-z_]+)(.*)$/i', $ctx, $cm ) && in_array( $cm[1], $bps, true ) ) {
				$bp     = ':' . $cm[1];
				$pseudo = $cm[2];
			}
			$text = self::dedent( $css );
			$keep = array();
			foreach ( self::items( $text, 0, strlen( $text ) ) as $it ) {
				if ( 'block' !== $it['type'] ) {
					continue; // a declaration outside a rule does nothing in the CSS panel
				}
				$body  = substr( $text, $it['open'] + 1, $it['close'] - $it['open'] - 1 );
				$state = self::root_state( $it['header'], $name );
				if ( null === $state || false !== strpos( self::strip_comments( $body ), '{' ) ) {
					$keep[] = self::root_header( $it['header'], $name ) . ' {' . rtrim( $body ) . "\n}";
					continue;
				}
				$uctx = $bp . $pseudo . $state;
				$disp = isset( $units[ $uctx . '#_display|' ] ) ? $units[ $uctx . '#_display|' ] : ( isset( $units[ $bp . '#_display|' ] ) ? $units[ $bp . '#_display|' ] : ( isset( $units['#_display|'] ) ? $units['#_display|'] : '' ) );
				$decls = self::css_decls( $body );
				foreach ( $decls as $d ) {
					if ( 'display' === $d[0] && ! $d[2] ) {
						$disp = $d[1];
					}
				}
				$grid = false !== strpos( $disp, 'grid' );
				$left = array();
				foreach ( $decls as $d ) {
					list( $prop, $value, $imp ) = $d;
					if ( $grid && 'align-items' === $prop && 'initial' === strtolower( $value ) ) {
						continue; // written by the builder for a grid, not by you
					}
					$mapped = ( $imp || 0 === strpos( $prop, '--' ) ) ? array() : self::map_decl( $prop, $value, $grid );
					if ( $mapped ) {
						foreach ( $mapped as $k => $v ) {
							$units[ $uctx . '#' . $k ] = $v;
						}
					} else {
						$left[] = $prop . ': ' . $value . ( $imp ? ' !important' : '' ) . ';';
					}
				}
				if ( $left ) {
					$keep[] = '%root%' . $pseudo . $state . " {\n  " . implode( "\n  ", $left ) . "\n}";
				}
			}
			if ( ! $keep ) {
				continue;
			}
			$chunk = implode( "\n", $keep );
			if ( '' !== $bp ) {
				$width = (int) array_search( substr( $bp, 1 ), $bps, true );
				$chunk = '@media (' . ( $mobile_first ? 'min' : 'max' ) . '-width: ' . $width . "px) {\n  " . str_replace( "\n", "\n  ", $chunk ) . "\n}";
			}
			$extras[] = $chunk;
		}
		return array( 'units' => $units, 'extras' => trim( implode( "\n", $extras ) ) );
	}

	/**
	 * The builder CSS the block's Custom CSS part stands for ('' for none).
	 *
	 * @param string $src  Source.
	 * @param string $name Class name.
	 * @return string
	 */
	private static function region_css( $src, $name ) {
		$blocks = self::class_blocks( $src );
		if ( ! isset( $blocks[ $name ] ) ) {
			return '';
		}
		$region = self::custom_region( $src, $blocks[ $name ] );
		return null === $region ? '' : self::custom_to_bricks( $region[2] );
	}

	/**
	 * The Custom CSS part holds only what no Style field holds. A field value in it
	 * (for example "color: #0c8f0a" on the class itself) goes when the block sets
	 * that too (the block wins), or moves into the block as a declaration.
	 *
	 * @param string $src  Source.
	 * @param string $name Class name.
	 * @param array  $scss Flat units the block sets (without the part).
	 * @return string New source.
	 */
	public static function normalize_region( $src, $name, $scss ) {
		$css = self::region_css( $src, $name );
		if ( '' === trim( $css ) ) {
			return $src;
		}
		$view = self::builder_view( array( '_cssCustom' => $css ), $name );
		if ( ! $view['units'] ) {
			return $src;
		}
		$move = array_diff_key( $view['units'], $scss );
		if ( $move ) {
			$src = self::rewrite_block( $src, $name, array_keys( $move ), self::unflat( $scss + $move ) );
		}
		return self::write_custom( $src, self::class_blocks( $src )[ $name ], $view['extras'] );
	}

	/**
	 * One class as the builder shows it → its block: the units that changed since
	 * $last are rewritten in place, and the Custom CSS part follows the CSS panel.
	 *
	 * @param string $src  Source.
	 * @param string $name Class name.
	 * @param array  $view builder_view() of the class.
	 * @param array  $last Flat units the block was in step with.
	 * @return array [ string $src, array $now (flat units), bool $changed ]
	 */
	private static function builder_into_block( $src, $name, $view, $last ) {
		$changed = false;
		$diff    = array();
		foreach ( array_keys( $view['units'] + $last ) as $fk ) {
			$in = isset( $view['units'][ $fk ] ) ? $view['units'][ $fk ] : null;
			$la = isset( $last[ $fk ] ) ? $last[ $fk ] : null;
			if ( $in !== $la ) {
				$diff[] = $fk;
			}
		}
		$now = $last;
		foreach ( $diff as $fk ) {
			if ( isset( $view['units'][ $fk ] ) ) {
				$now[ $fk ] = $view['units'][ $fk ];
			} else {
				unset( $now[ $fk ] );
			}
		}
		if ( $diff ) {
			$src     = self::rewrite_block( $src, $name, $diff, self::unflat( $now ) );
			$changed = true;
		}
		$have = self::region_css( $src, $name );
		$have = '' === $have ? '' : self::builder_view( array( '_cssCustom' => $have ), $name )['extras'];
		if ( self::canon_css( $have ) !== self::canon_css( $view['extras'] ) ) {
			if ( ! isset( self::class_blocks( $src )[ $name ] ) ) {
				$src = rtrim( $src ) . ( '' === trim( $src ) ? '' : "\n\n" ) . '.' . $name . " {\n}\n";
			}
			$src     = self::write_custom( $src, self::class_blocks( $src )[ $name ], $view['extras'] );
			$changed = true;
		}
		return array( $src, $now, $changed );
	}

	/**
	 * Live, while you work in the builder: classes changed there (not saved yet)
	 * → the SCSS you are editing. Nothing is stored.
	 *
	 * @param string $src  The SCSS in the editor.
	 * @param array  $list [ { name, settings, old } ] (old = the settings before the change).
	 * @return string
	 */
	public static function live_pull( $src, $list ) {
		$opts   = Scss::settings();
		$blocks = self::class_blocks( $src );
		foreach ( (array) $list as $c ) {
			$name = isset( $c['name'] ) ? (string) $c['name'] : '';
			if ( '' === $name || ! preg_match( '/^-?[_a-zA-Z][\w-]*$/', $name ) ) {
				continue;
			}
			$settings = isset( $c['settings'] ) && is_array( $c['settings'] ) ? $c['settings'] : array();
			foreach ( $settings as $k => $v ) {
				if ( is_string( $v ) && preg_match( '/^_cssCustom(:|$)/', (string) $k ) && substr_count( $v, '{' ) !== substr_count( $v, '}' ) ) {
					continue 2; // still being typed (the builder itself drops such CSS on save)
				}
			}
			$new = self::builder_view( $settings, $name );
			$old = self::builder_view( isset( $c['old'] ) && is_array( $c['old'] ) ? $c['old'] : array(), $name );
			if ( $new['units'] === $old['units'] && self::canon_css( $new['extras'] ) === self::canon_css( $old['extras'] ) ) {
				continue; // the builder only rewrote its CSS panel from the fields
			}
			if ( ! isset( $blocks[ $name ] ) ) {
				if ( empty( $opts['sync_pull'] ) ) {
					continue;
				}
				$old = array( 'units' => array(), 'extras' => '' ); // a new block gets the whole class
			}
			list( $src ) = self::builder_into_block( $src, $name, $new, $old['units'] );
			$blocks      = self::class_blocks( $src );
		}
		return $src;
	}

	/**
	 * Is a snippet plain CSS that the builder's Custom CSS can hold (no SCSS-only syntax)?
	 *
	 * @param string $t Snippet.
	 * @return bool
	 */
	public static function plain_css( $t ) {
		$t = self::strip_comments( (string) $t );
		if ( preg_match( '/\$|#\{|@|!default|!global|(^|[\s,(])%[\w-]/', $t ) ) {
			return false; // variables, interpolation, at-rules and mixins, placeholders
		}
		if ( preg_match_all( '/(?<![\w-])([a-zA-Z_][\w-]*)\s*\(/', $t, $m ) ) {
			foreach ( $m[1] as $fn ) {
				$fn = preg_replace( '/^-(webkit|moz|ms|o)-/', '', strtolower( $fn ) );
				if ( ! in_array( $fn, self::CSS_FUNCTIONS, true ) ) {
					return false; // an SCSS function (darken, math.div, if …)
				}
			}
		}
		return ! preg_match( '/(?<![\w-])[\w-]+\.[a-zA-Z_][\w-]*\s*\(/', $t ); // module functions (color.adjust)
	}

	/**
	 * Move what the builder has no Style field for into the block's Custom CSS part:
	 * plain declarations without a field (custom properties, !important, other
	 * properties) and plain nested rules ("&::before", "& .icon", ".icon",
	 * "&:focus-visible" …). SCSS-only code, "&:hover"-style states the builder has
	 * fields for, breakpoint and other @ blocks, and "&__part" / "&-modifier" rules
	 * (other classes) stay where they are.
	 *
	 * @param string $src  Source.
	 * @param string $name Class.
	 * @return string New source (unchanged when there is nothing to move).
	 */
	public static function adopt_leftovers( $src, $name ) {
		$blocks = self::class_blocks( $src );
		if ( ! isset( $blocks[ $name ] ) ) {
			return $src;
		}
		$block   = $blocks[ $name ];
		$region  = self::custom_region( $src, $block );
		$pseudos = self::pseudos();
		$items   = self::items( $src, $block['open'] + 1, $block['close'] );
		// The element's display decides which align fields exist (like css_to_units()).
		$grid = false;
		foreach ( $items as $it ) {
			if ( 'stmt' === $it['type'] && preg_match( '/^display\s*:\s*(.+)$/is', $it['text'], $dm ) ) {
				$grid = false !== strpos( $dm[1], 'grid' );
			}
		}
		$move = array();
		foreach ( $items as $it ) {
			if ( $region && $it['start'] >= $region[0] && $it['start'] < $region[1] ) {
				continue; // already in the Custom CSS part
			}
			if ( 'stmt' === $it['type'] ) {
				$t = rtrim( trim( $it['text'] ), ';' );
				$p = strpos( $t, ':' );
				if ( false === $p || ! self::plain_css( $t ) ) {
					continue;
				}
				$prop  = strtolower( trim( substr( $t, 0, $p ) ) );
				$value = trim( substr( $t, $p + 1 ) );
				$imp   = (bool) preg_match( '/!\s*important\s*$/i', $value );
				if ( '' === $value || ! preg_match( '/^(--[\w-]+|-?[a-z][\w-]*)$/', $prop ) ) {
					continue;
				}
				if ( ! $imp && 0 !== strpos( $prop, '--' ) && self::map_decl( $prop, $value, $grid ) ) {
					continue; // the builder has a field for it
				}
				$move[] = array( $it['start'], $it['end'], $prop . ': ' . $value . ';' );
				continue;
			}
			$h = $it['header'];
			if ( '' === $h || '@' === $h[0] || ':' === substr( $h, -1 ) || preg_match( '/&(?=[\w-])/', $h ) || ! self::plain_css( $h ) ) {
				continue; // @ blocks, nested properties ("font: { … }"), "&__part" / "&-modifier"
			}
			$states = true;
			foreach ( self::split( $h, ',' ) as $part ) {
				$part = trim( $part );
				if ( 0 !== strpos( $part, '&' ) || ! in_array( substr( $part, 1 ), $pseudos, true ) ) {
					$states = false;
				}
			}
			if ( $states ) {
				continue; // "&:hover" and the like fill the state's Style fields
			}
			$body = substr( $src, $it['open'] + 1, $it['close'] - $it['open'] - 1 );
			$flat = self::strip_comments( $body );
			if ( false !== strpos( $flat, '{' ) || ! self::plain_css( $flat ) ) {
				continue;
			}
			$decls = array();
			foreach ( self::css_decls( $flat ) as $d ) {
				$decls[] = $d[0] . ': ' . $d[1] . ( $d[2] ? ' !important' : '' ) . ';';
			}
			if ( ! $decls ) {
				continue;
			}
			$move[] = array( $it['start'], $it['end'], $h . " {\n\t" . implode( "\n\t", $decls ) . "\n}" );
		}
		if ( ! $move ) {
			return $src;
		}
		$have = $region ? self::dedent( $region[2] ) : '';
		// Cut from the end so earlier positions stay valid; a line left empty goes too.
		foreach ( array_reverse( $move ) as $mv ) {
			list( $s, $e ) = $mv;
			$ls = strrpos( substr( $src, 0, $s ), "\n" );
			if ( false !== $ls && '' === trim( substr( $src, $ls + 1, $s - $ls - 1 ) ) ) {
				$le = strpos( $src, "\n", $e );
				if ( false !== $le && '' === trim( substr( $src, $e, $le - $e ) ) ) {
					$s = $ls;
					$e = $le;
				}
			}
			$src = substr( $src, 0, $s ) . substr( $src, $e );
		}
		$add = implode( "\n", array_map( function ( $mv ) { return $mv[2]; }, $move ) );
		$now = self::class_blocks( $src );
		return self::write_region( $src, $now[ $name ], ( '' !== trim( $have ) ? $have . "\n" : '' ) . $add );
	}

	/**
	 * Flat unit keys to remove → ctx => [ keys ].
	 *
	 * @param string[] $flat Flat keys.
	 * @return array
	 */
	private static function rm_list( $flat ) {
		$rm = array();
		foreach ( $flat as $fk ) {
			list( $ctx, $k ) = explode( '#', $fk, 2 );
			$rm[ $ctx ][]   = $k;
		}
		return $rm;
	}

	/**
	 * Saved sync state.
	 *
	 * @return array
	 */
	private static function state() {
		$s = get_option( self::STATE, array() );
		$s = is_array( $s ) && isset( $s['classes'] ) && is_array( $s['classes'] ) ? $s : array( 'classes' => array() );
		if ( ! isset( $s['custom'] ) || ! is_array( $s['custom'] ) ) {
			$s['custom'] = array();
		}
		return $s;
	}

	/**
	 * Flatten units ctx => [k => v] to "ctx#k" => v.
	 *
	 * @param array $u Units.
	 * @return array
	 */
	private static function flat( $u ) {
		$out = array();
		foreach ( (array) $u as $ctx => $list ) {
			foreach ( (array) $list as $k => $v ) {
				$out[ $ctx . '#' . $k ] = (string) $v;
			}
		}
		return $out;
	}

	/**
	 * Unflatten.
	 *
	 * @param array $f Flat units.
	 * @return array
	 */
	private static function unflat( $f ) {
		$out = array();
		foreach ( $f as $fk => $v ) {
			list( $ctx, $k ) = explode( '#', $fk, 2 );
			$out[ $ctx ][ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Rewrite the given flat unit keys (grouped into families) in a class block.
	 *
	 * @param string $src   Source.
	 * @param string $name  Class.
	 * @param array  $keys  Flat unit keys that changed.
	 * @param array  $units Current units (ctx => list).
	 * @return string
	 */
	private static function rewrite_block( $src, $name, $keys, $units ) {
		$blocks = self::class_blocks( $src );
		if ( ! isset( $blocks[ $name ] ) ) {
			$src    = rtrim( $src ) . ( '' === trim( $src ) ? '' : "\n\n" ) . '.' . $name . " {}\n";
			$blocks = self::class_blocks( $src );
		}
		$by_ctx = array();
		foreach ( $keys as $fk ) {
			list( $ctx, $k ) = explode( '#', $fk, 2 );
			$f = self::family( $k );
			if ( '' !== $f ) {
				$by_ctx[ $ctx ][ $f ] = true;
			}
		}
		// Base context first, then states and breakpoints.
		uksort( $by_ctx, function ( $a, $b ) { return strlen( $a ) - strlen( $b ); } );
		foreach ( $by_ctx as $ctx => $fams ) {
			$blocks = self::class_blocks( $src );
			$src    = self::write_context( $src, $blocks[ $name ], $ctx, array_keys( $fams ), isset( $units[ $ctx ] ) ? $units[ $ctx ] : array() );
		}
		return $src;
	}

	/**
	 * SCSS → builder classes. Global SCSS wins: every value a class block sets is
	 * what the builder class shows, in its Style fields; its CSS panel holds only
	 * what no field holds (the block's Custom CSS part), so it can never
	 * contradict the block. The first time a class syncs, what only the builder
	 * has comes into the block first, so nothing is lost.
	 *
	 * With 'dry' (live preview while you type): nothing is stored, nothing is
	 * brought into the SCSS; 'source' and 'classes' (name => settings, the
	 * builder's unsaved copy) stand in for the stored ones.
	 *
	 * @param array $args { source?: string, classes?: [ { name, settings } ], dry?: bool }.
	 * @return array [ 'classes' => [ {id, name, settings} ], 'source_changed' => bool, 'errors' => [ name => message ] ]
	 */
	public static function push( $args = array() ) {
		$dry    = ! empty( $args['dry'] );
		$result = array( 'classes' => array(), 'source_changed' => false, 'errors' => array() );
		if ( ! self::enabled() || ( self::$busy && ! $dry ) ) {
			return $result;
		}
		$opts   = Scss::settings();
		$src    = isset( $args['source'] ) ? (string) $args['source'] : (string) $opts['source'];
		$blocks = self::class_blocks( $src );
		if ( ! $blocks ) {
			return $result;
		}
		$classes = get_option( 'bricks_global_classes', array() );
		$classes = is_array( $classes ) ? $classes : array();
		$index   = array();
		foreach ( $classes as $i => $c ) {
			if ( ! empty( $c['name'] ) ) {
				$index[ $c['name'] ] = $i;
			}
		}
		foreach ( isset( $args['classes'] ) && is_array( $args['classes'] ) ? $args['classes'] : array() as $c ) {
			if ( isset( $c['name'], $index[ $c['name'] ] ) && isset( $c['settings'] ) && is_array( $c['settings'] ) ) {
				$classes[ $index[ $c['name'] ] ]['settings'] = $c['settings'];
			}
		}
		$state   = self::state();
		$changed = false;
		foreach ( array_keys( $blocks ) as $name ) {
			if ( ! isset( self::class_blocks( $src )[ $name ] ) ) {
				continue;
			}
			if ( ! isset( $index[ $name ] ) ) {
				if ( $dry || empty( $opts['sync_create'] ) ) {
					continue;
				}
				$classes[]      = array( 'id' => strtolower( substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz' ), 0, 6 ) ), 'name' => $name, 'settings' => array() );
				$index[ $name ] = count( $classes ) - 1;
			}
			$i        = $index[ $name ];
			$settings = isset( $classes[ $i ]['settings'] ) && is_array( $classes[ $i ]['settings'] ) ? $classes[ $i ]['settings'] : array();
			$prev     = isset( $state['classes'][ $name ]['units'] ) ? $state['classes'][ $name ]['units'] : null;
			$view     = self::builder_view( $settings, $name );
			$was      = $src;
			// Plain CSS without a field joins the Custom CSS part (the CSS panel).
			$src = self::adopt_leftovers( $src, $name );
			// The Custom CSS region goes to its own field, not into the other fields.
			$r = self::block_units( $src, $name );
			if ( null !== $r['error'] ) {
				$result['errors'][ $name ] = $r['error'];
				continue;
			}
			$scss = $r['units'];
			// The Custom CSS part never holds a field value (the block wins).
			$src = self::normalize_region( $src, $name, $scss );
			if ( $src !== $was ) {
				$scss = self::block_units( $src, $name )['units'];
			}
			// First sync of this class: what only the builder has comes into the block.
			if ( null === $prev && ! $dry ) {
				$pull = array_diff_key( $view['units'], $scss );
				if ( $pull ) {
					$src   = self::rewrite_block( $src, $name, array_keys( $pull ), self::unflat( $scss + $pull ) );
					$scss += $pull;
				}
				if ( '' !== $view['extras'] && '' === trim( self::region_css( $src, $name ) ) ) {
					$src = self::write_custom( $src, self::class_blocks( $src )[ $name ], $view['extras'] );
				}
			}
			if ( $src !== $was ) {
				$result['source_changed'] = true;
			}
			$prev   = null === $prev ? array() : $prev;
			$remove = array_keys( array_diff_key( $prev, $scss ) );
			$want   = self::region_css( $src, $name );
			// Does the builder show what the block says?
			$differs = self::canon_css( $view['extras'] ) !== self::canon_css( '' === $want ? '' : self::builder_view( array( '_cssCustom' => $want ), $name )['extras'] );
			foreach ( $scss as $fk => $v ) {
				if ( ! isset( $view['units'][ $fk ] ) || $view['units'][ $fk ] !== $v ) {
					$differs = true;
				}
			}
			foreach ( $remove as $fk ) {
				if ( isset( $view['units'][ $fk ] ) ) {
					$differs = true;
				}
			}
			$fields = self::flat( self::settings_to_units( $settings ) );
			$new    = $settings;
			if ( $differs ) {
				// Fields: the block's values, and what only the CSS panel set stays (as a field).
				$keep = array_diff_key( $view['units'], $scss, array_flip( $remove ) );
				$set  = array_diff_assoc( $scss + $keep, $fields );
				$new  = self::apply_units( $settings, self::unflat( $set ), self::rm_list( array_intersect( $remove, array_keys( $fields ) ) ) );
				// CSS panel: only what no field holds, from the block's Custom CSS part.
				foreach ( array_keys( $new ) as $k ) {
					if ( preg_match( '/^_cssCustom(:|$)/', (string) $k ) ) {
						unset( $new[ $k ] );
					}
				}
				if ( '' !== trim( $want ) ) {
					$new['_cssCustom'] = $want;
				}
			}
			$new = self::link_palette_colors( $new );
			if ( $new !== $settings ) {
				$classes[ $i ]['settings'] = $new;
				$result['classes'][]       = array( 'id' => $classes[ $i ]['id'], 'name' => $name, 'settings' => $new );
				$changed                   = true;
			}
			if ( ! $dry ) {
				$keep_before               = isset( $state['classes'][ $name ]['before'] ) ? $state['classes'][ $name ]['before'] : $view['units'];
				$state['classes'][ $name ] = array( 'units' => $scss, 'before' => $differs ? $view['units'] : $keep_before );
				$state['custom'][ $name ]  = $want;
			}
		}
		if ( $dry ) {
			return $result;
		}
		if ( $result['source_changed'] ) {
			$opts['source'] = $src;
			Scss::save_settings( $opts );
		}
		self::$busy = true;
		if ( $changed ) {
			update_option( 'bricks_global_classes', array_values( $classes ) );
		}
		update_option( self::STATE, $state, false );
		self::$busy = false;
		return $result;
	}

	/**
	 * The units a class block sets, without its Custom CSS part and mixin output.
	 *
	 * @param string $src  Source.
	 * @param string $name Class name.
	 * @return array [ 'units' => flat units, 'error' => string|null ]
	 */
	private static function block_units( $src, $name ) {
		$blocks = self::class_blocks( $src );
		if ( ! isset( $blocks[ $name ] ) ) {
			return array( 'units' => array(), 'error' => null );
		}
		$b    = $blocks[ $name ];
		$text = substr( $src, $b['start'], $b['end'] - $b['start'] );
		$text = (string) preg_replace( '#' . preg_quote( self::CUSTOM_OPEN, '#' ) . '.*?' . preg_quote( self::CUSTOM_CLOSE, '#' ) . '#s', '', $text );
		$r    = Scss::compile( self::without_includes( $text ) );
		if ( null !== $r['error'] ) {
			return array( 'units' => array(), 'error' => $r['error']['message'] );
		}
		return array( 'units' => self::flat( self::css_to_units( $r['css'], $name ) ), 'error' => null );
	}

	/**
	 * The builder saved its classes: what changed in a class (its Style fields or
	 * its CSS panel, where the CSS panel wins) goes into that class's block.
	 *
	 * @param mixed $old Old classes.
	 * @param mixed $new New classes.
	 * @return void
	 */
	public static function on_classes_saved( $old, $new ) {
		if ( self::$busy || ! self::enabled() || ! is_array( $new ) ) {
			return;
		}
		$opts   = Scss::settings();
		$src    = (string) $opts['source'];
		$blocks = self::class_blocks( $src );
		$state  = self::state();
		$olds   = array();
		foreach ( is_array( $old ) ? $old : array() as $c ) {
			if ( ! empty( $c['name'] ) ) {
				$olds[ $c['name'] ] = isset( $c['settings'] ) ? $c['settings'] : array();
			}
		}
		$restore = array();
		$touched = false;
		// A class renamed in the builder (same id, new name): its SCSS block (and every
		// ".old-name" in the SCSS) takes the new name.
		$old_names = array();
		foreach ( is_array( $old ) ? $old : array() as $c ) {
			if ( ! empty( $c['id'] ) && ! empty( $c['name'] ) ) {
				$old_names[ $c['id'] ] = (string) $c['name'];
			}
		}
		foreach ( $new as $c ) {
			$id   = isset( $c['id'] ) ? (string) $c['id'] : '';
			$name = isset( $c['name'] ) ? (string) $c['name'] : '';
			if ( '' === $id || '' === $name || ! isset( $old_names[ $id ] ) || $old_names[ $id ] === $name ) {
				continue;
			}
			$was = $old_names[ $id ];
			if ( isset( $blocks[ $was ] ) && ! isset( $blocks[ $name ] ) && preg_match( '/^-?[_a-zA-Z][\w-]*$/', $name ) ) {
				$src = (string) preg_replace( '/(?<![\w.-])\.' . preg_quote( $was, '/' ) . '(?![\w-])/', '.' . $name, $src );
				foreach ( array( 'classes', 'custom' ) as $part ) {
					if ( isset( $state[ $part ][ $was ] ) ) {
						$state[ $part ][ $name ] = $state[ $part ][ $was ];
						unset( $state[ $part ][ $was ] );
					}
				}
				if ( isset( $olds[ $was ] ) ) {
					$olds[ $name ] = $olds[ $was ];
				}
				$blocks  = self::class_blocks( $src );
				$touched = true;
			}
		}
		foreach ( $new as $idx => $c ) {
			$name = isset( $c['name'] ) ? (string) $c['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$view  = self::builder_view( isset( $c['settings'] ) ? $c['settings'] : array(), $name );
			$known = isset( $state['classes'][ $name ] );
			if ( ! isset( $blocks[ $name ] ) ) {
				// Not in SCSS (yet, or its block was removed): add it only when the
				// builder changed it (if allowed).
				$before = self::builder_view( isset( $olds[ $name ] ) ? $olds[ $name ] : array(), $name );
				if ( empty( $opts['sync_pull'] ) || ( $view['units'] === $before['units'] && self::canon_css( $view['extras'] ) === self::canon_css( $before['extras'] ) ) || ( ! $view['units'] && '' === $view['extras'] ) ) {
					continue;
				}
				list( $src, $now ) = self::builder_into_block( $src, $name, $view, array() );
				$state['classes'][ $name ] = array( 'units' => $now, 'before' => $now );
				$state['custom'][ $name ]  = self::region_css( $src, $name );
				$blocks                    = self::class_blocks( $src );
				$touched                   = true;
				continue;
			}
			$last   = $known ? $state['classes'][ $name ]['units'] : self::builder_view( isset( $olds[ $name ] ) ? $olds[ $name ] : array(), $name )['units'];
			$before = $known && isset( $state['classes'][ $name ]['before'] ) ? $state['classes'][ $name ]['before'] : array();
			$diff   = array();
			foreach ( array_keys( $view['units'] + $last ) as $fk ) {
				if ( ( isset( $view['units'][ $fk ] ) ? $view['units'][ $fk ] : null ) !== ( isset( $last[ $fk ] ) ? $last[ $fk ] : null ) ) {
					$diff[] = $fk;
				}
			}
			// A stale copy (another builder tab, an old in-memory state) looks exactly like
			// the class did before the last SCSS push: every value that push changed is back.
			$stale = array();
			if ( $known && $diff ) {
				$pushed = array();
				foreach ( array_keys( $last + $before ) as $fk ) {
					if ( ( isset( $last[ $fk ] ) ? $last[ $fk ] : null ) !== ( isset( $before[ $fk ] ) ? $before[ $fk ] : null ) ) {
						$pushed[] = $fk;
					}
				}
				$is_stale = (bool) $pushed;
				foreach ( $pushed as $fk ) {
					if ( ( isset( $view['units'][ $fk ] ) ? $view['units'][ $fk ] : null ) !== ( isset( $before[ $fk ] ) ? $before[ $fk ] : null ) ) {
						$is_stale = false;
						break;
					}
				}
				if ( $is_stale ) {
					$stale = array_values( array_intersect( $diff, $pushed ) );
				}
			}
			if ( $stale ) {
				$restore[ $idx ] = true;
				foreach ( $stale as $fk ) {
					// Keep the block's value: the stale one does not go into the SCSS.
					if ( isset( $last[ $fk ] ) ) {
						$view['units'][ $fk ] = $last[ $fk ];
					} else {
						unset( $view['units'][ $fk ] );
					}
				}
			}
			list( $src, $now, $changed ) = self::builder_into_block( $src, $name, $view, $last );
			if ( $changed ) {
				$state['classes'][ $name ] = array( 'units' => $now, 'before' => $before );
				$state['custom'][ $name ]  = self::region_css( $src, $name );
				$blocks                    = self::class_blocks( $src );
				$touched                   = true;
			}
		}
		if ( $touched ) {
			$opts['source'] = $src;
			Scss::save_settings( $opts );
		}
		if ( $restore ) {
			// Put the SCSS values back over the stale copy (fields and CSS panel).
			update_option( self::STATE, $state, false );
			self::$busy = false;
			self::push();
		} else {
			self::$busy = true;
			update_option( self::STATE, $state, false );
			self::$busy = false;
		}
		if ( $touched ) {
			Css_Generator::get_instance()->generate();
		}
	}
}
