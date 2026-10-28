<?php
/**
 * Front end for the data attributes that need a little script: data-tf-copy, data-tf-tooltip
 * (the description for screen readers) and data-tf-filter-group / -filter / -term.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Platforms\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Loads assets/front/attrs.js only on pages that use it. A11y_Auto's render handlers (top-level
 * builder elements and blocks) pass their HTML to note(), so those hooks run one callback for both;
 * this class reads only the plain post content. A page without the attributes pays one stripos per piece.
 */
class Attr_Front {

	const HANDLE = 'tokenforge-attrs';

	/**
	 * The page uses data-tf-copy, data-tf-tooltip or a filter group.
	 *
	 * @var bool
	 */
	private static $script = false;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( __CLASS__, 'boot' ) );
	}

	/**
	 * Front-end request outside every builder: hook the post content and the footer (A11y_Auto,
	 * which boots on every request this one does, hands over the builder elements and blocks).
	 *
	 * @return void
	 */
	public static function boot() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) || self::in_builder() ) {
			return; // in a builder every filter item stays visible and nothing loads (A11y_Auto may still note a piece; with no footer hook nothing loads)
		}
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
		// 19: after the builder's popups (wp_footer 10), before the footer scripts print (20).
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 19 );
	}

	/**
	 * A builder editor or canvas (Frontend::is_builder_ui(), which is private, plus the Bricks canvas).
	 *
	 * @return bool
	 */
	private static function in_builder() {
		return Bricks::is_builder_context() || Bricks::is_preview_context()
			|| ( defined( 'SHOW_CT_BUILDER' ) && SHOW_CT_BUILDER ) || null !== filter_input( INPUT_GET, 'ct_builder' )
			|| null !== filter_input( INPUT_GET, 'breakdance' ) || null !== filter_input( INPUT_GET, 'breakdance_iframe' )
			|| 'magic' === filter_input( INPUT_GET, 'etch' )
			|| null !== filter_input( INPUT_GET, 'fl_builder' ) || null !== filter_input( INPUT_GET, 'elementor-preview' );
	}

	/**
	 * Plain post content (classic content has no blocks; inside a builder tree the element reads it).
	 *
	 * @param string $html Content HTML.
	 * @return string The HTML, unchanged.
	 */
	public static function content( $html ) {
		// Inside a builder tree while it has started more often than it finished (WordPress counts every action, hooked or not).
		$in_tree = did_action( 'bricks/frontend/before_render_data' ) > did_action( 'bricks/frontend/after_render_data' );
		return $in_tree ? $html : self::note( $html );
	}

	/**
	 * Note what one piece of rendered HTML uses. Never changes it.
	 *
	 * @param string $html HTML.
	 * @return string The same HTML.
	 */
	public static function note( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'data-tf-' ) ) {
			return $html;
		}
		// The name as an attribute of an opening tag (quoted values skipped whole), so echoed visitor text such as a search does not count; a raw < ends the scan, so crafted markup stays linear; a PCRE error loads it as before.
		// An attribute follows a space, or comes right after a quoted value (class="a"data-tf-copy: a parse error browsers accept); names hold no quote, so a quote before one closes a value.
		// shortcut: markup written inside script or textarea text counts too (it only loads the small script); fine as long as nothing worse follows from a match.
		if ( 0 !== preg_match( '/<[a-z][^\s\/<>"\']*(?:(?:\s+|(?<=["\']))[^\s"\'<>=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'<>]+))?)*?(?:\s+|(?<=["\']))data-tf-(?:copy|tooltip|filter)/i', $html ) ) {
			self::$script = true;
		}
		return $html;
	}

	/**
	 * Load the script (with its strings), only when the page uses it.
	 *
	 * @return void
	 */
	public static function footer() {
		if ( self::$script ) {
			wp_enqueue_script( self::HANDLE, TOKENFORGE_URL . 'assets/front/attrs.js', array(), TOKENFORGE_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			$strings = array(
				'copied'     => __( 'Copied', 'tokenforge' ),
				'copyFailed' => __( 'Could not copy', 'tokenforge' ),
			);
			wp_add_inline_script( self::HANDLE, 'var tokenforgeAttrs = ' . wp_json_encode( $strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		}
	}
}
