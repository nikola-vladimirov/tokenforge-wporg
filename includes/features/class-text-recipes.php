<?php
/**
 * Text recipes (?ipsum and the rest) in the other builders' editors.
 *
 * The builder (Bricks) and the block editor load their own recipe code; this adds
 * assets/builder/text-recipes.js to Etch, Breakdance, Oxygen, Elementor and Divi (its top window;
 * the app frame is reached from there). Builders finds the editor and prints the queue where needed.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Recipes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the text recipes script in each builder's editor.
 */
class Text_Recipes {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( Builders::HOOK, array( $this, 'enqueue' ) );
	}

	/**
	 * Queues the script in a builder's editor (Builders already checked the user can edit).
	 *
	 * @param string $builder The page builder.
	 * @return void
	 */
	public function enqueue( $builder ) {
		// Bricks: the builder tools carry their own recipe code.
		if ( 'bricks' !== $builder ) {
			Recipes::enqueue_text( true );
		}
	}
}
