<?php
/**
 * Admin settings page.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and enqueues the token-editor app.
 */
class Admin {

	const SLUG = 'tokenforge';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'TokenForge', 'tokenforge' ),
			__( 'TokenForge', 'tokenforge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			TOKENFORGE_URL . 'assets/admin/tokenforge-icon.svg',
			59
		);
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'tokenforge-admin',
			TOKENFORGE_URL . 'assets/admin/admin.css',
			array(),
			TOKENFORGE_VERSION
		);
		// CodeMirror (SCSS mode) for the Global SCSS editor; the dashboard falls back
		// to a plain editor when the user turned syntax highlighting off.
		$deps = array( 'wp-api-fetch', 'wp-i18n' );
		$code = function_exists( 'wp_enqueue_code_editor' ) ? wp_enqueue_code_editor( array( 'type' => 'text/x-scss', 'codemirror' => array( 'indentUnit' => 4, 'tabSize' => 4, 'indentWithTabs' => true ) ) ) : false;
		if ( false !== $code ) {
			$deps[] = 'code-editor';
		}
		wp_enqueue_script( 'tokenforge-tips', TOKENFORGE_URL . 'assets/admin/tips.js', array( 'wp-i18n' ), TOKENFORGE_VERSION, true );
		wp_enqueue_script( 'tokenforge-scss-outline', TOKENFORGE_URL . 'assets/admin/scss-outline.js', array(), TOKENFORGE_VERSION, true );
		wp_enqueue_script( 'tokenforge-scss-editor', TOKENFORGE_URL . 'assets/admin/scss-editor.js', array( 'wp-i18n', 'tokenforge-scss-outline' ), TOKENFORGE_VERSION, true );
		wp_set_script_translations( 'tokenforge-scss-editor', 'tokenforge' );
		$deps[] = 'tokenforge-tips';
		$deps[] = 'tokenforge-scss-editor';
		wp_enqueue_script(
			'tokenforge-admin',
			TOKENFORGE_URL . 'assets/admin/admin.js',
			$deps,
			TOKENFORGE_VERSION,
			true
		);
		// The dashboard's text in the site's language (translations from translate.wordpress.org).
		wp_set_script_translations( 'tokenforge-tips', 'tokenforge' );
		wp_set_script_translations( 'tokenforge-admin', 'tokenforge' );
		wp_localize_script(
			'tokenforge-admin',
			'TokenForgeData',
			array(
				'restUrl' => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'groups'  => Tokens::GROUPS,
				'codeEditor' => $code ? $code : null,
			)
		);
	}

	/**
	 * Render the app root.
	 *
	 * @return void
	 */
	public function render() {
		echo '<div class="wrap"><h1>' . esc_html__( 'TokenForge', 'tokenforge' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Design tokens, scales and utility classes. Change what you like, then Save to rebuild the stylesheet.', 'tokenforge' ) . '</p>';
		echo '<div id="tokenforge-app"></div></div>';
	}
}
