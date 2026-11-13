<?php
/**
 * The shared layer for every page builder's editor.
 *
 * Finds the open editor (and its canvas), loads the TokenForge window and tools where
 * Platforms::CAPS lists the builder, and fires `tokenforge_builder_enqueue` for add-ons.
 * Each builder loads scripts differently (checked in their code, see
 * tokenforge-tools/notes/builder-layer-plan.md):
 * - Bricks, Etch and Divi: the normal WordPress queue on their editor page (Divi: the top window, where its
 *   settings panels are; its app frame is the canvas alone);
 * - Elementor: its editor resets the queue, so its own hook;
 * - Breakdance and Oxygen: their editor page prints its own HTML, so what was queued here is printed in its footer.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Class_Usage;
use TokenForge\Css_Generator;
use TokenForge\Platforms\Bricks;
use TokenForge\Platforms\Platforms;

defined( 'ABSPATH' ) || exit;

/**
 * Loads TokenForge and its add-ons in each builder's editor.
 */
class Builders {

	const HOOK = 'tokenforge_builder_enqueue';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'front' ), 14 ); // after the window (12) and the Bricks tools (13).
		add_action(
			'elementor/editor/after_enqueue_scripts',
			function () {
				self::load( 'elementor' );
			}
		);
		add_action( 'breakdance_builder_footer', array( $this, 'footer' ) );
		add_filter( 'etch/canvas/additional_stylesheets', array( $this, 'etch_canvas' ) );
	}

	/**
	 * Is Divi's visual builder open, and is this its app frame (the canvas) rather than the top window (the settings panels)?
	 *
	 * @return bool|null Null: not Divi's builder. True: the app frame. False: the top window.
	 */
	public static function divi_app() {
		// Divi 5 only, as in Platforms::detected(): Divi 4's builder is another app.
		if ( ! function_exists( 'et_core_is_fb_enabled' ) || ! defined( 'ET_CORE_VERSION' ) || version_compare( (string) constant( 'ET_CORE_VERSION' ), '5.0', '<' ) || ! et_core_is_fb_enabled() ) {
			return null;
		}
		return null !== filter_input( INPUT_GET, 'app_window' );
	}

	/**
	 * The editors that use the normal WordPress queue: Bricks, Etch (?etch=magic) and Divi.
	 *
	 * @return void
	 */
	public function front() {
		$divi = self::divi_app();
		if ( Bricks::is_active() && Bricks::is_builder_context() ) {
			self::load( 'bricks' );
		} elseif ( false === $divi ) {
			// The top window only: the app frame is a canvas (is_canvas() gives it the stylesheet).
			self::load( 'divi' );
		} elseif ( defined( 'ETCH_PLUGIN_FILE' ) && 'magic' === filter_input( INPUT_GET, 'etch' ) ) {
			self::load( 'etch' );
		}
	}

	/**
	 * Breakdance and Oxygen: their editor page skips the WordPress queue, so print what this request added to it.
	 *
	 * @return void
	 */
	public function footer() {
		$styles  = wp_styles()->queue;
		$scripts = wp_scripts()->queue;
		self::load( defined( 'BREAKDANCE_MODE' ) ? (string) constant( 'BREAKDANCE_MODE' ) : 'breakdance' );
		// Only what this request added, and only when there is something: an empty list still fires the wp_print_* actions.
		$styles = array_diff( wp_styles()->queue, $styles );
		if ( $styles ) {
			wp_print_styles( $styles );
		}
		$scripts = array_diff( wp_scripts()->queue, $scripts );
		if ( $scripts ) {
			wp_print_scripts( $scripts );
		}
	}

	/**
	 * Loads the window and the tools where the builder has an integration, then lets add-ons load theirs.
	 *
	 * @param string $builder bricks | etch | oxygen | breakdance | elementor | divi.
	 * @return void
	 */
	private static function load( $builder ) {
		// Oxygen's and Breakdance's loader has no login check of its own.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// Bricks: Builder_Panel and Builder_Enhancements load themselves there.
		if ( 'bricks' !== $builder && isset( Platforms::CAPS[ $builder ] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				Builder_Panel::window( 'builder', $builder );
			}
			Builder_Enhancements::tools( $builder );
			// Divi's panels and its own right-click menu sit far above everything (up to 9999999): the menus go over them.
			if ( 'divi' === $builder && wp_style_is( 'tokenforge-ctx', 'enqueued' ) ) {
				wp_add_inline_style( 'tokenforge-ctx', '#tf-ctx,#tf-ac{z-index:10000000}' );
			}
		}
		/**
		 * Fires in the editor page of every supported page builder, for users who can edit posts,
		 * after TokenForge's own assets (the window, tokenforge-panel, for administrators; the tools, tokenforge-builder, outside Bricks).
		 * Add-ons load their scripts and styles here with wp_enqueue_script() and wp_enqueue_style();
		 * where the builder's editor skips the WordPress queue (Oxygen, Breakdance), TokenForge prints them.
		 * Divi: fires in the top window, where the settings panels are; the canvas is the same-origin frame #et-vb-app-frame.
		 * The block editor has its own action, tokenforge_block_editor_enqueue( $opts ).
		 *
		 * @param string $builder bricks | etch | oxygen | breakdance | elementor | divi.
		 */
		do_action( 'tokenforge_builder_enqueue', $builder ); // Literal for Plugin Check; same as self::HOOK.
	}

	/**
	 * Etch draws its canvas itself (no request to hook), so hand it the stylesheet: with every
	 * utility class while unused ones are left out, so a class you add shows at once.
	 *
	 * @param array $sheets Each: id, url.
	 * @return array
	 */
	public function etch_canvas( $sheets ) {
		$css = Css_Generator::get_instance();
		if ( current_user_can( 'edit_posts' ) && file_exists( $css->css_path() ) ) {
			$full     = Class_Usage::active() && file_exists( $css->full_css_path() );
			$sheets   = (array) $sheets;
			$sheets[] = array(
				'id'  => 'tokenforge-css',
				// Absolute and ending .css?ver=N: Etch drops anything else.
				'url' => $full ? $css->full_css_url() : $css->css_url(),
			);
		}
		return $sheets;
	}

	/**
	 * Is this request a builder's canvas that renders like the front end (Breakdance and Oxygen,
	 * the Elementor preview, Divi's app frame)? Bricks has its own check; Etch's canvas is no request.
	 *
	 * @return bool
	 */
	public static function is_canvas() {
		return current_user_can( 'edit_posts' ) && (
			( defined( 'BREAKDANCE_MODE' ) && null !== filter_input( INPUT_GET, 'breakdance_iframe' ) )
			|| ( defined( 'ELEMENTOR_VERSION' ) && null !== filter_input( INPUT_GET, 'elementor-preview' ) )
			|| true === self::divi_app()
		);
	}
}
