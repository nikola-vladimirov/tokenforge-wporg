<?php
/**
 * The block editor integration: the class field, the interface switches, the
 * canvas tools and the hidden blocks. The editor script (assets/editor/editor.js)
 * does the work in the browser; this hands it the settings, the class list and
 * the variables, and loads the canvas stylesheet into the editor canvas.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Css_Generator;
use TokenForge\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Block editor feature.
 */
class Block_Editor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'enqueue_block_assets', array( $this, 'canvas' ) );
	}

	/**
	 * A short preview per class: the declarations of its first plain rule
	 * (.name{...}) in the stylesheet, from one pass over the file.
	 *
	 * @return array name => declarations
	 */
	private static function previews() {
		$css  = Css_Generator::get_instance();
		$file = file_exists( $css->full_css_path() ) ? $css->full_css_path() : $css->css_path();
		$out  = array();
		// The scan is kept until the stylesheet is generated again (its version changes), not done on every editor load.
		$sig = TOKENFORGE_VERSION . '|' . $css->version();
		$hit = get_transient( 'tokenforge_block_previews' );
		if ( is_array( $hit ) && isset( $hit['sig'], $hit['rows'] ) && $sig === $hit['sig'] && is_array( $hit['rows'] ) ) {
			return $hit['rows'];
		}
		if ( ! is_readable( $file ) || ! preg_match_all( '/(?<![\w\-\\\\)\]:.>+~* ])\.(-?[_a-zA-Z][\w\-]*)\{([^{}]+)\}/', (string) file_get_contents( $file ), $m, PREG_SET_ORDER ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			return $out;
		}
		foreach ( $m as $x ) {
			if ( ! isset( $out[ $x[1] ] ) ) {
				$out[ $x[1] ] = strlen( $x[2] ) > 80 ? substr( $x[2], 0, 77 ) . '...' : $x[2];
			}
		}
		set_transient(
			'tokenforge_block_previews',
			array(
				'sig'  => $sig,
				'rows' => $out,
			),
			WEEK_IN_SECONDS
		);
		return $out;
	}

	/**
	 * The localized TokenForgeEditor object.
	 *
	 * @return array
	 */
	public static function config() {
		$s    = Settings::get_instance()->get_settings();
		$opts = array();
		foreach ( array(
			'classField'   => 'block_class_field',
			'compact'      => 'block_compact',
			'swapPanels'   => 'block_swap_panels',
			'resizePanels' => 'block_resize_panels',
			'openPanels'   => 'block_open_panels',
			'stylesFirst'  => 'block_styles_first',
			'listView'     => 'block_list_view',
			'inserterList' => 'block_inserter_list',
			'darkUi'       => 'block_dark_ui',
			'darkCanvas'   => 'block_dark_canvas',
			'outlines'     => 'block_outlines',
			'gridOutlines' => 'block_grid_outlines',
			'window'       => 'block_window',
		) as $js => $key ) {
			$opts[ $js ] = ! empty( $s[ $key ] );
		}
		// The window is the whole dashboard: administrators only.
		$opts['window'] = $opts['window'] && current_user_can( 'manage_options' );

		$rows = array();
		if ( $opts['classField'] ) {
			$previews = self::previews();
			foreach ( Builder_Enhancements::menu_classes() as $name ) {
				$rows[] = array( $name, isset( $previews[ $name ] ) ? $previews[ $name ] : '' );
			}
		}
		/**
		 * The classes the block editor's class field offers.
		 *
		 * @param array $rows Rows [ class name, short CSS preview ].
		 */
		$rows = apply_filters( 'tokenforge_editor_classes', $rows );

		// Text recipes typed in the canvas (?ipsum; or "?ipsum "): name => text. Empty when recipes or recipes in fields are off.
		$text = array();
		$rec  = \TokenForge\Recipes::for_editor();
		if ( $rec && ! empty( $rec['inputs'] ) ) {
			foreach ( $rec['recipes'] as $r ) {
				// Only the Text category (placeholder copy): the Code recipes are snippets for builder fields.
				if ( is_array( $r ) && isset( $r['n'], $r['code'], $r['kind'], $r['c'] ) && 'text' === $r['kind'] && 'Text' === $r['c'] ) {
					$text[ (string) $r['n'] ] = str_replace( '{{cursor}}', '', (string) $r['code'] );
				}
			}
		}

		return array(
			'opts'     => $opts,
			'classes'  => array_values( array_filter( (array) $rows, 'is_array' ) ),
			'vars'     => Builder_Enhancements::menu_variables(),
			'text'     => (object) $text,
			'restUrl'  => esc_url_raw( rest_url( \TokenForge\Rest::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl' => admin_url( 'admin.php?page=tokenforge' ),
			// Hidden from the inserter by the editor script, which also sees blocks registered only in JavaScript.
			'hide'     => array_values( array_filter( array_map( 'trim', explode( ',', (string) $s['block_hide'] ) ) ) ),
		);
	}

	/**
	 * Load the editor script and its styles (anyone who can edit posts).
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// The handle always exists, so add-ons can depend on it; the file may be missing in a broken copy.
		$has = file_exists( TOKENFORGE_DIR . 'assets/editor/editor.js' );
		wp_register_script( 'tokenforge-editor', $has ? TOKENFORGE_URL . 'assets/editor/editor.js' : false, array( 'wp-data', 'wp-hooks', 'wp-element', 'wp-components', 'wp-blocks', 'wp-i18n', 'wp-dom-ready' ), TOKENFORGE_VERSION, true );
		wp_enqueue_script( 'tokenforge-editor' );
		wp_set_script_translations( 'tokenforge-editor', 'tokenforge' );
		$config = self::config();
		wp_localize_script( 'tokenforge-editor', 'TokenForgeEditor', $config );
		if ( file_exists( TOKENFORGE_DIR . 'assets/editor/editor.css' ) ) {
			wp_enqueue_style( 'tokenforge-editor', TOKENFORGE_URL . 'assets/editor/editor.css', array(), TOKENFORGE_VERSION );
		}
		/**
		 * The TokenForge editor script is enqueued: add-ons load theirs here.
		 *
		 * @param array $opts The editor switches (TokenForgeEditor.opts).
		 */
		do_action( 'tokenforge_block_editor_enqueue', $config['opts'] );
	}

	/**
	 * The canvas tools (outlines, dark canvas) need their rules inside the editor
	 * canvas: block assets are loaded into its iframe.
	 *
	 * @return void
	 */
	public function canvas() {
		$s = Settings::get_instance()->get_settings();
		if ( ! is_admin() || ( empty( $s['block_outlines'] ) && empty( $s['block_grid_outlines'] ) && empty( $s['block_dark_canvas'] ) ) || ! file_exists( TOKENFORGE_DIR . 'assets/editor/canvas.css' ) ) {
			return;
		}
		wp_enqueue_style( 'tokenforge-editor-canvas', TOKENFORGE_URL . 'assets/editor/canvas.css', array(), TOKENFORGE_VERSION );
	}
}
