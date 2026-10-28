<?php
/**
 * Accessibility test engine (axe-core): where the bundled file is and how the builder
 * loads it. The file is never enqueued; the builder script injects it only when a
 * check is run, so no visitor page loads it.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Platforms\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the axe-core URL and the builder loader.
 */
class A11y {

	const AXE_VERSION = '4.14.0';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 13 );
	}

	/**
	 * URL of the bundled axe.min.js (versioned so a new axe is not cached).
	 *
	 * @return string
	 */
	public static function axe_url() {
		return add_query_arg( 'ver', self::AXE_VERSION, TOKENFORGE_URL . 'assets/vendor/axe-core/axe.min.js' );
	}

	/**
	 * Bundled axe-core version.
	 *
	 * @return string
	 */
	public static function axe_version() {
		return self::AXE_VERSION;
	}

	/**
	 * The loader script, in the builder (administrators) only. It does not load axe.
	 */
	public function enqueue() {
		if ( Bricks::is_active() && Bricks::is_builder_context() && current_user_can( 'manage_options' ) ) {
			wp_enqueue_script( 'tokenforge-a11y-loader', TOKENFORGE_URL . 'assets/builder/a11y-loader.js', array(), TOKENFORGE_VERSION, true );
		}
	}
}
