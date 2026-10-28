<?php
/**
 * BEM class generator for the Bricks builder.
 *
 * Injects a small helper panel into the builder shell that builds
 * block__element--modifier class strings, copies them, and can register them
 * as Bricks global classes so they appear in the class autocomplete.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Platforms\Bricks;
use TokenForge\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the builder-side BEM tool.
 */
class Bem_Generator {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 12 );
	}

	/**
	 * Enqueue the BEM tool only inside the Bricks builder shell.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! Bricks::is_builder_context() ) {
			return;
		}
		wp_enqueue_style(
			'tokenforge-bem',
			TOKENFORGE_URL . 'assets/builder/bem-generator.css',
			array(),
			TOKENFORGE_VERSION
		);
		wp_enqueue_script(
			'tokenforge-bem',
			TOKENFORGE_URL . 'assets/builder/bem-generator.js',
			array(),
			TOKENFORGE_VERSION,
			true
		);
		wp_localize_script(
			'tokenforge-bem',
			'TokenForgeBem',
			array(
				'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
		// Ensure the localized object exists even if another handle owns it.
		wp_add_inline_script( 'tokenforge-bem', 'window.TokenForgeBem = window.TokenForgeBem || {};', 'before' );
	}
}
