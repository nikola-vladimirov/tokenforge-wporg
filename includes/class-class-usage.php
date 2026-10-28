<?php
/**
 * Which of the utility classes the site uses, so the stylesheet can leave out
 * the rest (Utility framework > Leave out unused utility classes).
 *
 * A scan reads what the site is made of: pages, posts, templates and every
 * other post type (drafts too) with the builder's data, the block editor's
 * content, menu item classes, widgets, components and global elements. Every
 * word in there that is the name of a utility class (or the id the builder
 * keeps for it) counts as used. Reading words rather than parsing markup
 * keeps a class whenever it is in doubt: a class named in a script or a
 * custom attribute stays too. Later saves add new classes as they appear,
 * so a class you add in the builder is in the stylesheet the moment you
 * save. The builder canvas and the block editor always get every class.
 *
 * Classes added by theme or plugin code (not stored in the content) can be
 * kept by name in the "Always keep" list.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class usage across the site.
 */
class Class_Usage {

	const OPTION = 'tokenforge_class_usage';

	/** Options holding builder elements, menus or markup that can carry classes. */
	const OPTIONS = array( 'bricks_components', 'bricks_global_elements', 'bricks_global_settings', 'widget_block', 'widget_text', 'widget_custom_html' );

	/** Post meta the builder keeps a page's elements in, and menu item classes. */
	const META = array( '_bricks_page_header_2', '_bricks_page_content_2', '_bricks_page_footer_2', '_bricks_page_settings', '_menu_item_classes' );

	/**
	 * Text queued by saves in this request (checked once, at the end).
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Hooks: new classes in saved content are added to the list.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		add_action( 'save_post', array( __CLASS__, 'on_post' ), 10, 2 );
		foreach ( self::OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'on_option' ), 10, 2 );
			add_action( 'add_option_' . $option, array( __CLASS__, 'on_option' ), 10, 2 );
		}
	}

	/**
	 * Is leaving out unused classes on, with a scan to go by?
	 *
	 * @return bool
	 */
	public static function active() {
		$fw   = Framework::settings();
		$last = self::last();
		return ! empty( $fw['prune_unused'] ) && ! empty( $last['time'] ) && empty( $last['capped'] );
	}

	/**
	 * The last scan: [ time, posts, capped, classes[] ], or [].
	 *
	 * @return array
	 */
	public static function last() {
		$u = get_option( self::OPTION, array() );
		return is_array( $u ) ? $u : array();
	}

	/**
	 * Class names to keep, or null to keep them all (switched off, never
	 * scanned, or a site too large to read in one scan).
	 *
	 * @return array|null name => true
	 */
	public static function keep_set() {
		if ( ! self::active() ) {
			return null;
		}
		$last = self::last();
		$keep = array_fill_keys( isset( $last['classes'] ) ? (array) $last['classes'] : array(), true );
		return $keep;
	}

	/**
	 * Is a class kept? Matches the scan, then the "Always keep" names
	 * (a name ending in * keeps every class that starts with it).
	 *
	 * @param string $name Class name.
	 * @param array  $keep keep_set().
	 * @return bool
	 */
	public static function kept( $name, $keep ) {
		if ( isset( $keep[ $name ] ) ) {
			return true;
		}
		foreach ( self::always() as $rule ) {
			if ( '*' === substr( $rule, -1 ) ? 0 === strpos( $name, substr( $rule, 0, -1 ) ) : $rule === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The "Always keep" list: class names (or prefixes ending in *).
	 *
	 * @return string[]
	 */
	public static function always() {
		static $cache = null;
		$fw   = Framework::settings();
		$text = isset( $fw['prune_keep'] ) ? (string) $fw['prune_keep'] : '';
		if ( null !== $cache && $cache[0] === $text ) {
			return $cache[1];
		}
		$rules = array();
		foreach ( preg_split( '/[\s,]+/', $text ) as $r ) {
			$r = ltrim( trim( $r ), '.' );
			if ( '' !== $r && preg_match( '/^[A-Za-z0-9_-]+\*?$/', $r ) ) {
				$rules[] = $r;
			}
		}
		$cache = array( $text, $rules );
		return $rules;
	}

	/**
	 * For the dashboard: when the last scan ran and what it found.
	 *
	 * @return array
	 */
	public static function summary() {
		$last = self::last();
		$fw   = Framework::settings();
		return array(
			'on'      => ! empty( $fw['prune_unused'] ),
			'active'  => self::active(),
			'time'    => isset( $last['time'] ) ? (int) $last['time'] : 0,
			'posts'   => isset( $last['posts'] ) ? (int) $last['posts'] : 0,
			'capped'  => ! empty( $last['capped'] ),
			'used'    => isset( $last['classes'] ) ? count( (array) $last['classes'] ) : 0,
			'total'   => isset( $last['total'] ) ? (int) $last['total'] : 0,
			'full_kb' => isset( $last['full_kb'] ) ? (float) $last['full_kb'] : 0,
			'kept_kb' => isset( $last['kept_kb'] ) ? (float) $last['kept_kb'] : 0,
		);
	}

	/**
	 * Every utility class the framework can make: name => true, plus the ids
	 * the builder keeps classes under (id => name).
	 *
	 * @return array [ names, ids ]
	 */
	private static function lookup() {
		$built = Framework::build( true );
		$names = array_fill_keys( $built['classes'], true );
		$ids   = array();
		foreach ( (array) get_option( 'bricks_global_classes', array() ) as $c ) {
			if ( is_array( $c ) && isset( $c['id'], $c['name'] ) && isset( $names[ $c['name'] ] ) ) {
				$ids[ (string) $c['id'] ] = (string) $c['name'];
			}
		}
		return array( $names, $ids );
	}

	/**
	 * The utility classes named in a value (a string or nested arrays).
	 *
	 * @param mixed $value Value.
	 * @param array $names lookup() names.
	 * @param array $ids   lookup() ids.
	 * @param array $found name => true, added to.
	 * @return void
	 */
	private static function collect( $value, $names, $ids, &$found ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				self::collect( $v, $names, $ids, $found );
			}
			return;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return;
		}
		if ( ! preg_match_all( '/[A-Za-z0-9_-]+/', $value, $m ) ) {
			return;
		}
		foreach ( array_unique( $m[0] ) as $word ) {
			if ( isset( $names[ $word ] ) ) {
				$found[ $word ] = true;
			} elseif ( isset( $ids[ $word ] ) ) {
				$found[ $ids[ $word ] ] = true;
			}
		}
	}

	/**
	 * Read the whole site and keep the result.
	 *
	 * @return array See last().
	 */
	public static function scan() {
		list( $names, $ids ) = self::lookup();
		$found = array();

		foreach ( self::OPTIONS as $option ) {
			$v = get_option( $option );
			if ( $v ) {
				self::collect( $v, $names, $ids, $found );
			}
		}

		// Every post of every kind (drafts too, menu items for their classes): content and builder data.
		list( $posts, $capped ) = Token_Usage::each_post(
			array_diff( Token_Usage::SKIP_TYPES, array( 'nav_menu_item' ) ),
			function ( $post ) use ( $names, $ids, &$found ) {
				self::collect( (string) $post->post_content, $names, $ids, $found );
				foreach ( self::META as $key ) {
					self::collect( get_post_meta( $post->ID, $key, true ), $names, $ids, $found );
				}
			}
		);

		$classes = array_keys( $found );
		sort( $classes );
		$out = array(
			'time'    => time(),
			'posts'   => $posts,
			'capped'  => $capped,
			'classes' => $classes,
			'total'   => count( $names ),
		);
		update_option( self::OPTION, $out, false );
		self::measure();
		return self::last();
	}

	/**
	 * Note the size of the utility part with and without the unused classes.
	 *
	 * @return void
	 */
	public static function measure() {
		$last = self::last();
		if ( empty( $last['time'] ) ) {
			return;
		}
		$full = Framework::build( true );
		$kept = self::active() ? Framework::build() : $full;
		$last['full_kb'] = round( strlen( $full['css'] ) / 1024, 1 );
		$last['kept_kb'] = round( strlen( $kept['css'] ) / 1024, 1 );
		update_option( self::OPTION, $last, false );
	}

	/**
	 * A post's builder data or menu item classes were saved.
	 *
	 * @param int    $meta_id Meta id.
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return void
	 */
	public static function on_meta( $meta_id, $post_id, $key, $value ) {
		if ( in_array( $key, self::META, true ) ) {
			self::queue( $value );
		}
	}

	/**
	 * A post was saved (the block editor's content).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_post( $post_id, $post ) {
		if ( $post instanceof \WP_Post && ! wp_is_post_revision( $post_id ) && '' !== (string) $post->post_content ) {
			self::queue( (string) $post->post_content );
		}
	}

	/**
	 * Components, global elements, widgets or the builder's settings were saved.
	 *
	 * @param mixed $old   Old value (or the option name, when added).
	 * @param mixed $value New value.
	 * @return void
	 */
	public static function on_option( $old, $value ) {
		self::queue( $value );
	}

	/**
	 * Keep a saved value to check at the end of the request.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	private static function queue( $value ) {
		if ( ! self::active() ) {
			return;
		}
		if ( ! self::$queue ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
		self::$queue[] = $value;
	}

	/**
	 * Add classes that appeared in this request's saves, and rebuild the
	 * stylesheet when there are any.
	 *
	 * @return void
	 */
	public static function flush() {
		$queue       = self::$queue;
		self::$queue = array();
		if ( ! $queue || ! self::active() ) {
			return;
		}
		list( $names, $ids ) = self::lookup();
		$found = array();
		self::collect( $queue, $names, $ids, $found );
		$last  = self::last();
		$have  = array_fill_keys( isset( $last['classes'] ) ? (array) $last['classes'] : array(), true );
		$new   = array_diff_key( $found, $have );
		$attrs = Attribute_Usage::join( $queue ); // a data attribute used for the first time gets its rules back too
		if ( ! $new && ! $attrs ) {
			return;
		}
		if ( $new ) {
			$classes = array_keys( $have + $new );
			sort( $classes );
			$last['classes'] = $classes;
			update_option( self::OPTION, $last, false );
		}
		Css_Generator::get_instance()->generate();
		self::measure();
	}
}
