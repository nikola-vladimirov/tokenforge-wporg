<?php
/**
 * The TokenForge window: the full dashboard in a dockable/floating window,
 * inside the Bricks builder and (for administrators) on the front end.
 *
 * Loads the same dashboard app used in wp-admin. A keyboard shortcut (Options →
 * TokenForge window) opens and closes it, in the builder also while the focus
 * is in the canvas. On the front end the window lives in a shadow root, so the
 * site's own CSS (TokenForge's included) can't restyle the dashboard.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Platforms\Bricks;
use TokenForge\Scheme;
use TokenForge\Settings;
use TokenForge\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the dashboard window.
 */
class Builder_Panel {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 12 );
	}

	/**
	 * Common window config.
	 *
	 * @param string $context builder | front.
	 * @return array
	 */
	private function config( $context ) {
		$s = Settings::get_instance()->get_settings();
		return array(
			'restUrl'  => esc_url_raw( rest_url( \TokenForge\Rest::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'groups'   => Tokens::GROUPS,
			'adminUrl' => admin_url( 'admin.php?page=tokenforge' ),
			'shortcut' => (string) $s['panel_shortcut'],
			'context'  => $context,
			'axeUrl'     => esc_url_raw( A11y::axe_url() ), // loaded on demand by a11y-loader.js, never enqueued.
			'axeVersion' => A11y::axe_version(),
			'darkMode'   => 'auto' === Scheme::settings()['dark_mode'], // the contrast fix says one colour serves both modes
			// Builder: its own corner button, unless the canvas shows one (Show in builder canvas while editing).
			'launcher' => 'builder' === $context ? empty( $s['canvas_panel'] ) : ! empty( $s['frontend_launcher'] ),
		);
	}

	/**
	 * Is the front-end window wanted on this request?
	 *
	 * @return bool
	 */
	private function wants_front() {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$s = Settings::get_instance()->get_settings();
		if ( empty( $s['frontend_panel'] ) ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		// Never inside a builder UI or its canvas/preview frame.
		foreach ( array( 'bricks', 'brickspreview', 'ct_builder', 'oxygen_iframe', 'breakdance', 'breakdance_iframe', 'fl_builder', 'elementor-preview', 'et_fb', 'vc_editable', 'etch' ) as $q ) {
			if ( null !== filter_input( INPUT_GET, $q ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Enqueue in the Bricks builder shell, or on the front end for administrators.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( Bricks::is_active() && Bricks::is_builder_context() ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// The dashboard app + its styles (shared with wp-admin), plus the window shell.
			wp_enqueue_style( 'tokenforge-admin', TOKENFORGE_URL . 'assets/admin/admin.css', array(), TOKENFORGE_VERSION );
			wp_enqueue_style( 'tokenforge-panel', TOKENFORGE_URL . 'assets/builder/panel.css', array( 'tokenforge-admin' ), TOKENFORGE_VERSION );
			wp_enqueue_script( 'tokenforge-tips', TOKENFORGE_URL . 'assets/admin/tips.js', array( 'wp-i18n' ), TOKENFORGE_VERSION, true );
			wp_enqueue_script( 'tokenforge-dashboard', TOKENFORGE_URL . 'assets/admin/admin.js', array( 'wp-i18n', 'tokenforge-tips' ), TOKENFORGE_VERSION, true );
			wp_set_script_translations( 'tokenforge-tips', 'tokenforge' );
			wp_set_script_translations( 'tokenforge-dashboard', 'tokenforge' );
			wp_enqueue_script( 'tokenforge-panel', TOKENFORGE_URL . 'assets/builder/panel.js', array( 'tokenforge-dashboard' ), TOKENFORGE_VERSION, true );
			wp_localize_script( 'tokenforge-panel', 'TokenForgePanel', $this->config( 'builder' ) );
			// The Check tab (UI for the page-check engine, which class-check.php enqueues in this same context).
			wp_enqueue_script( 'tokenforge-check-panel', TOKENFORGE_URL . 'assets/builder/check-panel.js', array( 'wp-i18n', 'tokenforge-dashboard', 'tokenforge-check-engine' ), TOKENFORGE_VERSION, true );
			wp_set_script_translations( 'tokenforge-check-panel', 'tokenforge' );
			return;
		}
		// The builder canvas: a TokenForge button that opens the builder's window.
		if ( Bricks::is_active() && Bricks::is_preview_context() ) {
			$s = Settings::get_instance()->get_settings();
			if ( ! empty( $s['canvas_panel'] ) && current_user_can( 'manage_options' ) ) {
				wp_enqueue_script( 'tokenforge-canvas-button', TOKENFORGE_URL . 'assets/builder/canvas-button.js', array(), TOKENFORGE_VERSION, true );
				wp_localize_script(
					'tokenforge-canvas-button',
					'TokenForgeCanvas',
					array(
						'title'    => __( 'Open TokenForge', 'tokenforge' ),
						'adminUrl' => admin_url( 'admin.php?page=tokenforge' ),
					)
				);
			}
			return;
		}
		if ( ! $this->wants_front() ) {
			return;
		}
		// Front end: styles load inside the window's shadow root (see panel.js), so
		// they are passed as URLs instead of being enqueued into the page.
		wp_enqueue_script( 'tokenforge-tips', TOKENFORGE_URL . 'assets/admin/tips.js', array( 'wp-i18n' ), TOKENFORGE_VERSION, true );
		wp_enqueue_script( 'tokenforge-dashboard', TOKENFORGE_URL . 'assets/admin/admin.js', array( 'wp-i18n', 'tokenforge-tips' ), TOKENFORGE_VERSION, true );
		wp_set_script_translations( 'tokenforge-tips', 'tokenforge' );
		wp_set_script_translations( 'tokenforge-dashboard', 'tokenforge' );
		wp_enqueue_script( 'tokenforge-panel', TOKENFORGE_URL . 'assets/builder/panel.js', array( 'tokenforge-dashboard' ), TOKENFORGE_VERSION, true );
		$cfg        = $this->config( 'front' );
		$cfg['css'] = array(
			add_query_arg( 'ver', TOKENFORGE_VERSION, TOKENFORGE_URL . 'assets/admin/admin.css' ),
			add_query_arg( 'ver', TOKENFORGE_VERSION, TOKENFORGE_URL . 'assets/builder/panel.css' ),
		);
		wp_localize_script( 'tokenforge-panel', 'TokenForgePanel', $cfg );
	}
}
