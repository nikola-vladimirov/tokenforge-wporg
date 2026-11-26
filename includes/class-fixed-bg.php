<?php
/**
 * Builder backgrounds that stay the same in dark mode.
 *
 * With dark mode on, the text colours switch to their light side. A background
 * the builder set to a colour that does not switch (a header with
 * background var(--white)) stays light, and its text would turn light on
 * light. TokenForge does not write those backgrounds, so it reads them from the
 * builder's data: global classes, the elements of every page and template,
 * components and global elements, and the custom CSS kept with them. The
 * scheme then keeps those elements in their light colours, as designed
 * (Scheme::build()).
 *
 * Each source (an option, a post) keeps its own list of selector and
 * background pairs, so a save reads only what it changed. Whether a background
 * stays the same is decided when the stylesheet is built, against the
 * tokens of that moment.
 *
 * Shortcut: only the Bricks data is read; read the other builders' data when
 * a site with dark mode needs it.
 * Shortcut: a background set for one page or one breakpoint keeps that
 * selector light on every page and at every width (harmless: the element
 * looks as in light mode there); scope the rule if a site needs it.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed builder backgrounds.
 */
class Fixed_Bg {

	const OPTION = 'tokenforge_fixed_bg';

	/** Options holding builder classes, elements or custom CSS. */
	const OPTIONS = array( 'bricks_global_classes', 'bricks_components', 'bricks_global_elements', 'bricks_global_settings' );

	/** Post meta the builder keeps a page's elements and custom CSS in. */
	const META = array( '_bricks_page_header_2', '_bricks_page_content_2', '_bricks_page_footer_2', '_bricks_page_settings' );

	/** Most selectors written (a bound on the stylesheet). */
	const MAX = 400;

	/**
	 * Sources changed in this request (source => pairs), written once at the end.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Hooks: builder saves update their source's list.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta' ), 10, 3 );
		}
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_untrash' ) );
		foreach ( self::OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'on_option' ), 10, 3 );
			add_action( 'add_option_' . $option, array( __CLASS__, 'on_option_added' ), 10, 2 );
		}
		// No list yet (dark mode just switched on, or the plugin updated): the first admin request reads the site.
		add_action( 'admin_init', array( __CLASS__, 'maybe_scan' ) );
	}

	/**
	 * Is dark mode on (the only time the list is used)?
	 *
	 * @return bool
	 */
	private static function on() {
		return 'auto' === Scheme::settings()['dark_mode'];
	}

	/**
	 * May this request read the whole site? Only once every post type is
	 * registered (after init) and never for a visitor: a signed-in editor in the
	 * admin or REST (admin-ajax.php and admin-post.php count as admin for anyone), or the command line.
	 *
	 * @return bool
	 */
	private static function may_scan() {
		return did_action( 'init' ) && ( 'cli' === PHP_SAPI || ( ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) && current_user_can( 'edit_posts' ) ) );
	}

	/**
	 * The selectors of the elements to keep light, for the stylesheet: classes
	 * and custom CSS first, then element ids (the bound cuts those first).
	 *
	 * @param array|null $ctx Scheme::dark_context().
	 * @return string[]
	 */
	public static function selectors( $ctx ) {
		if ( ! $ctx ) {
			return array();
		}
		$all = get_option( self::OPTION, null );
		if ( ! is_array( $all ) ) {
			if ( ! self::may_scan() ) {
				return array(); // maybe_scan() reads the site and rebuilds on the next admin request.
			}
			$all = self::scan();
		}
		$out = array();
		foreach ( $all as $pairs ) {
			foreach ( (array) $pairs as $p ) {
				if ( is_array( $p ) && 2 === count( $p ) && ! isset( $out[ $p[0] ] ) && Scheme::fixed_fill( $p[1], $ctx ) ) {
					$out[ $p[0] ] = true;
				}
			}
		}
		$out = array_keys( $out );
		usort(
			$out,
			function ( $a, $b ) {
				$x = (int) preg_match( '/^[#.]brxe-/', $a );
				$y = (int) preg_match( '/^[#.]brxe-/', $b );
				return $x === $y ? strcmp( $a, $b ) : $x - $y;
			}
		);
		return array_slice( $out, 0, self::MAX );
	}

	/**
	 * Read every source and keep the result.
	 *
	 * @return array source => pairs.
	 */
	public static function scan() {
		$all = array();
		foreach ( self::OPTIONS as $option ) {
			$pairs = self::pairs( get_option( $option ), 'bricks_global_classes' === $option );
			if ( $pairs ) {
				$all[ 'o:' . $option ] = $pairs;
			}
		}
		Token_Usage::each_post(
			Token_Usage::SKIP_TYPES,
			function ( $post ) use ( &$all ) {
				$pairs = self::post_pairs( $post->ID );
				if ( $pairs ) {
					$all[ 'p:' . $post->ID ] = $pairs;
				}
			}
		);
		update_option( self::OPTION, $all, false );
		return $all;
	}

	/**
	 * Admin request with dark mode on and no list: read the site, rebuild the stylesheet.
	 *
	 * @return void
	 */
	public static function maybe_scan() {
		if ( self::on() && ! is_array( get_option( self::OPTION, null ) ) && self::may_scan() ) {
			self::scan();
			Css_Generator::get_instance()->generate();
		}
	}

	/**
	 * The pairs in a post's builder data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private static function post_pairs( $post_id ) {
		$pairs = array();
		foreach ( self::META as $key ) {
			$pairs = array_merge( $pairs, self::pairs( get_post_meta( $post_id, $key, true ), false ) );
		}
		return $pairs;
	}

	/**
	 * Selector and background pairs in builder data.
	 *
	 * @param mixed $data    Option or meta value.
	 * @param bool  $classes The value is the list of global classes.
	 * @return array [ [ selector, background ], ... ]
	 */
	public static function pairs( $data, $classes ) {
		$out = array();
		if ( is_array( $data ) ) {
			self::walk( $data, $classes, $out );
		}
		return array_values( array_unique( $out, SORT_REGULAR ) );
	}

	/**
	 * Walk builder data: elements and classes (settings), custom CSS anywhere.
	 *
	 * @param array $node    Node.
	 * @param bool  $classes Global classes list.
	 * @param array $out     Pairs, added to.
	 * @return void
	 */
	private static function walk( $node, $classes, &$out ) {
		$sel = '';
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			if ( $classes && isset( $node['name'] ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', (string) $node['name'] ) ) {
				$sel = '.' . $node['name'];
			} elseif ( ! $classes && isset( $node['id'], $node['name'] ) ) {
				$css_id = isset( $node['settings']['_cssId'] ) ? (string) $node['settings']['_cssId'] : '';
				if ( '' !== $css_id ) {
					$sel = preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $css_id ) ? '#' . $css_id : '';
				} elseif ( preg_match( '/^[A-Za-z0-9]+$/', (string) $node['id'] ) ) {
					// The element's id, or its class inside a query loop.
					$sel = '#brxe-' . $node['id'] . ',.brxe-' . $node['id'];
				}
			}
			if ( '' !== $sel ) {
				foreach ( $node['settings'] as $key => $value ) {
					// The background in every breakpoint (_background:mobile_portrait), not in a state (:hover).
					if ( preg_match( '/^_background(?::([a-z0-9_-]+))?$/', (string) $key, $m ) && ! ( isset( $m[1] ) && preg_match( '/^(?:hover|active|focus|focus-visible|focus-within|visited|before|after|checked|disabled)$/', $m[1] ) )
						&& is_array( $value ) && isset( $value['color'] ) && is_array( $value['color'] ) ) {
						$c = $value['color'];
						foreach ( array( 'raw', 'rgb', 'hex', 'hsl' ) as $k ) {
							if ( isset( $c[ $k ] ) && is_string( $c[ $k ] ) && '' !== trim( $c[ $k ] ) ) {
								foreach ( explode( ',', $sel ) as $one ) {
									$out[] = array( $one, trim( $c[ $k ] ) );
								}
								break;
							}
						}
					}
				}
				if ( isset( $node['settings']['_cssCustom'] ) && is_string( $node['settings']['_cssCustom'] ) ) {
					self::css( $node['settings']['_cssCustom'], $sel, $out );
				}
			}
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				// Only the list itself holds classes; what is inside a class is not one.
				self::walk( $value, $classes && ! isset( $node['settings'] ), $out );
			} elseif ( 'customCss' === $key && is_string( $value ) ) {
				self::css( $value, '', $out ); // Page and site custom CSS.
			}
		}
	}

	/**
	 * Background colours in custom CSS (%root% is the element's own selector).
	 *
	 * @param string $css  CSS.
	 * @param string $root The element's selector ('' for page or site CSS).
	 * @param array  $out  Pairs, added to.
	 * @return void
	 */
	private static function css( $css, $root, &$out ) {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		// Print rules (a white page on paper) say nothing about the screen.
		$css = (string) preg_replace( '/@media\s+print[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '', $css );
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
			return;
		}
		foreach ( $rules as $r ) {
			if ( ! preg_match_all( '/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $r[2], $d ) ) {
				continue;
			}
			foreach ( explode( ',', trim( $r[1] ) ) as $one ) {
				$one = trim( '' !== $root ? str_replace( '%root%', explode( ',', $root )[0], $one ) : $one );
				// Plain class, id, type and combinator selectors only: no states (:hover), @rules, attribute
				// selectors or strings, so nothing can leave its rule, the :where() list or the style element.
				if ( '' === $one || ! preg_match( '/^[A-Za-z0-9_\-.#\s>+~*]+$/', $one ) ) {
					continue;
				}
				$out[] = array( $one, trim( end( $d[1] ) ) );
			}
		}
	}

	/**
	 * A post's builder data was saved or removed.
	 *
	 * @param int|int[] $meta_id Meta id(s).
	 * @param int       $post_id Post id.
	 * @param string    $key     Meta key.
	 * @return void
	 */
	public static function on_meta( $meta_id, $post_id, $key ) {
		if ( in_array( $key, self::META, true ) && ! wp_is_post_revision( $post_id ) ) {
			self::store( 'p:' . (int) $post_id, self::post_pairs( (int) $post_id ) );
		}
	}

	/**
	 * A post was deleted or trashed.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_delete( $post_id ) {
		self::store( 'p:' . (int) $post_id, array() );
	}

	/**
	 * A post came back from the trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_untrash( $post_id ) {
		self::store( 'p:' . (int) $post_id, self::post_pairs( (int) $post_id ) );
	}

	/**
	 * A builder option was saved.
	 *
	 * @param mixed  $old    Old value.
	 * @param mixed  $value  New value.
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option( $old, $value, $option ) {
		self::store( 'o:' . $option, self::pairs( $value, 'bricks_global_classes' === $option ) );
	}

	/**
	 * A builder option was added.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public static function on_option_added( $option, $value ) {
		self::on_option( null, $value, $option );
	}

	/**
	 * Keep one source's pairs for the end of the request. With dark mode off
	 * saves are not followed: the list is dropped, so switching dark mode on
	 * (also through the Colors module) reads the site again.
	 *
	 * @param string $source Source key.
	 * @param array  $pairs  Pairs.
	 * @return void
	 */
	private static function store( $source, $pairs ) {
		if ( ! self::on() ) {
			if ( null !== get_option( self::OPTION, null ) ) {
				delete_option( self::OPTION );
			}
			return;
		}
		$first                    = ! self::$pending;
		self::$pending[ $source ] = $pairs;
		if ( did_action( 'shutdown' ) ) {
			self::flush(); // A save from another shutdown handler: too late to queue.
		} elseif ( $first ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Write this request's changes once; rebuild the stylesheet when the list changed.
	 *
	 * @return void
	 */
	public static function flush() {
		$pending       = self::$pending;
		self::$pending = array();
		$all           = get_option( self::OPTION, null );
		if ( ! $pending || ! is_array( $all ) ) {
			return; // No list yet: the next build or admin request reads everything.
		}
		$was = $all;
		foreach ( $pending as $source => $pairs ) {
			if ( $pairs ) {
				$all[ $source ] = $pairs;
			} else {
				unset( $all[ $source ] );
			}
		}
		if ( $all !== $was ) {
			update_option( self::OPTION, $all, false );
			Css_Generator::get_instance()->generate();
		}
	}
}
