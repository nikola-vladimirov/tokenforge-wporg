<?php
/**
 * Builder-agnostic frontend + editor loader.
 *
 * Enqueues the generated stylesheet on EVERY WordPress site (not just Bricks):
 * the front end, and the Gutenberg block editor. Bricks-specific behaviour lives
 * in Platforms\Bricks and only runs when Bricks is detected.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Loads tokenforge.css wherever it is needed, on any builder/editor.
 */
class Frontend {

	/**
	 * Constructor: hook front end + block editor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend' ), 20 );
		// Inject into the block editor's CONTENT styles (scoped to the editor
		// iframe by WordPress) rather than the editor chrome, so we never style
		// wp-admin's own inputs/buttons.
		add_filter( 'block_editor_settings_all', array( $this, 'editor_styles' ) );

		// Visitor light/dark switcher (only when enabled in Colors).
		add_action( 'wp_head', array( $this, 'scheme_head' ), 1 );
		add_action( 'wp_footer', array( $this, 'scheme_footer' ) );

		// Motion effects: hide visible-effect elements before first paint, then
		// reveal/stagger them from the footer.
		add_action( 'wp_head', array( $this, 'motion_head' ), 2 );
		add_action( 'wp_footer', array( $this, 'motion_footer' ), 20 );
		add_shortcode( 'tokenforge_scheme_toggle', array( $this, 'scheme_shortcode' ) );

		// Quick link to the dashboard in the admin bar (front end + wp-admin).
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );

		// Live CSS updates in the block editor (admins only).
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_hot_reload' ) );

		// Match the editor canvas' rem base to a non-default root font size.
		add_action( 'enqueue_block_assets', array( $this, 'editor_root_font_size' ) );
	}

	/**
	 * When the root font size isn't 100%, the block editor canvas must use the
	 * same rem base or every rem token would render at the wrong size there.
	 * (enqueue_block_assets styles are loaded into the editor iframe.)
	 *
	 * @return void
	 */
	public function editor_root_font_size() {
		if ( ! is_admin() ) {
			return;
		}
		$s = Defaults::settings();
		if ( empty( $s['output_root_font_size'] ) || 100.0 === (float) $s['root_font_size'] ) {
			return;
		}
		wp_register_style( 'tokenforge-editor-root', false, array(), TOKENFORGE_VERSION );
		wp_enqueue_style( 'tokenforge-editor-root' );
		wp_add_inline_style( 'tokenforge-editor-root', 'html{font-size:' . (float) $s['root_font_size'] . '%}' );
	}

	/**
	 * Should this user get CSS hot reload?
	 *
	 * @return bool
	 */
	private function hot_reload_enabled() {
		$s = Settings::get_instance()->get_settings();
		return ! empty( $s['hot_reload'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Enqueue the hot-reload client.
	 *
	 * @param string $mode 'front' or 'editor'.
	 * @param array  $deps Script deps.
	 * @param bool   $full The page has the stylesheet with every utility class.
	 * @return void
	 */
	private function enqueue_hot_reload( $mode, $deps = array(), $full = false ) {
		$css = Css_Generator::get_instance();
		wp_enqueue_script( 'tokenforge-hot', TOKENFORGE_URL . 'assets/hot-reload.js', $deps, TOKENFORGE_VERSION, true );
		wp_localize_script(
			'tokenforge-hot',
			'TokenForgeHot',
			array(
				'mode'     => $mode,
				'ver'      => $css->version(),
				'url'      => $full ? $css->full_css_url() : $css->css_url(),
				'shopUrl'  => $css->shop_css_url(),
				'restUrl'  => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'interval' => 15000, // fallback poll only: a dashboard save reaches this browser at once by the signals
			)
		);
	}

	/**
	 * Hot reload inside the block editor.
	 *
	 * @return void
	 */
	public function editor_hot_reload() {
		if ( $this->hot_reload_enabled() && file_exists( Css_Generator::get_instance()->css_path() ) ) {
			$this->enqueue_hot_reload( 'editor', array( 'wp-data', 'wp-block-editor' ) );
		}
	}

	/**
	 * Apply a stored scheme choice before first paint (avoids a light flash).
	 *
	 * @return void
	 */
	public function scheme_head() {
		if ( ! Scheme::switcher_enabled() || $this->is_builder_ui() ) {
			return;
		}
		wp_print_inline_script_tag( 'try{var s=localStorage.getItem("tf-scheme");if(s==="light"||s==="dark"){document.documentElement.setAttribute("data-scheme",s)}}catch(e){}' );
	}

	/**
	 * Should this request run the motion scripts? Not in builder UIs, not in
	 * the Bricks canvas when effects are disabled in builders (or the
	 * stylesheet isn't loaded there), and only when the stylesheet exists.
	 *
	 * @return bool
	 */
	private function motion_enabled() {
		if ( is_admin() || ! Motion::needs_script() || $this->is_builder_ui() || ! file_exists( Css_Generator::get_instance()->css_path() ) ) {
			return false;
		}
		if ( Platforms\Bricks::is_active() && Platforms\Bricks::is_preview_context() ) {
			$m = Motion::settings();
			$s = Settings::get_instance()->get_settings();
			return empty( $m['disable_in_builder'] ) && ! empty( $s['enqueue_in_builder'] );
		}
		return true;
	}

	/**
	 * Motion head script (before first paint).
	 *
	 * @return void
	 */
	public function motion_head() {
		if ( $this->motion_enabled() ) {
			$js = Motion::head_script();
			if ( '' !== $js ) {
				wp_print_inline_script_tag( $js, array( 'id' => 'tokenforge-motion-head' ) );
			}
		}
	}

	/**
	 * Motion footer script.
	 *
	 * @return void
	 */
	public function motion_footer() {
		if ( $this->motion_enabled() ) {
			wp_print_inline_script_tag( Motion::script(), array( 'id' => 'tokenforge-motion' ) );
		}
	}

	/**
	 * Delegated click handler for any .scheme-toggle / [data-scheme-toggle].
	 *
	 * @return void
	 */
	public function scheme_footer() {
		if ( ! Scheme::switcher_enabled() || $this->is_builder_ui() ) {
			return;
		}
		$js = '(function(){var r=document.documentElement,mq=window.matchMedia("(prefers-color-scheme: dark)");'
			. 'function cur(){var a=r.getAttribute("data-scheme");return a||(mq.matches?"dark":"light")}'
			. 'function sync(){var d=cur()==="dark";document.querySelectorAll(".scheme-toggle,[data-scheme-toggle]").forEach(function(b){b.setAttribute("aria-pressed",d?"true":"false")})}'
			. 'document.addEventListener("click",function(e){var b=e.target.closest(".scheme-toggle,[data-scheme-toggle]");if(!b){return}e.preventDefault();'
			. 'var n=cur()==="dark"?"light":"dark";r.setAttribute("data-scheme",n);try{localStorage.setItem("tf-scheme",n)}catch(er){}sync()});'
			. 'if(mq.addEventListener){mq.addEventListener("change",sync)}sync()})();';
		wp_print_inline_script_tag( $js );
	}

	/**
	 * [tokenforge_scheme_toggle class="..."]: a light/dark toggle button.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function scheme_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'class' => '' ), $atts, 'tokenforge_scheme_toggle' );
		if ( ! Scheme::switcher_enabled() ) {
			return '';
		}
		return Scheme::toggle_markup( sanitize_text_field( $atts['class'] ) );
	}

	/**
	 * Add a TokenForge node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Settings::get_instance()->get_settings();
		if ( empty( $s['admin_bar_link'] ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'tokenforge',
				'title' => '<span class="ab-icon dashicons dashicons-art" style="top:2px"></span>' . esc_html__( 'TokenForge', 'tokenforge' ),
				'href'  => admin_url( 'admin.php?page=tokenforge' ),
			)
		);
	}

	/**
	 * Enqueue the stylesheet on the front end (all sites, all builders).
	 *
	 * @return void
	 */
	public function frontend() {
		$css = Css_Generator::get_instance();
		if ( ! file_exists( $css->css_path() ) ) {
			return;
		}

		// NEVER load inside a builder's editor UI (its chrome uses native
		// inputs/buttons/headings that our base styles would restyle). The
		// canvas/preview iframe and the real front end still get the stylesheet.
		if ( $this->is_builder_ui() ) {
			return;
		}

		$bricks   = Platforms\Bricks::is_active();
		$settings = Settings::get_instance()->get_settings();

		// Bricks canvas (preview iframe) is opt-out via Settings.
		if ( $bricks && Platforms\Bricks::is_preview_context() && empty( $settings['enqueue_in_builder'] ) ) {
			return;
		}

		$deps = ( $bricks && wp_style_is( 'bricks-frontend', 'registered' ) ) ? array( 'bricks-frontend' ) : array();

		// Bricks canvas: keep scroll effects still while editing (separate handle
		// so a hot reload of the main stylesheet doesn't drop it).
		if ( $bricks && Platforms\Bricks::is_preview_context() ) {
			$m     = Motion::settings();
			$still = empty( $m['disable_in_builder'] ) ? '' : Motion::still_css();
			if ( '' !== $still ) {
				wp_register_style( 'tokenforge-motion-still', false, array(), TOKENFORGE_VERSION );
				wp_enqueue_style( 'tokenforge-motion-still' );
				wp_add_inline_style( 'tokenforge-motion-still', $still );
			}
		}

		// Unused utility classes left out: the builder canvas and the Customizer
		// preview get every class (a class you add shows at once), as a file.
		$full = ( ( $bricks && Platforms\Bricks::is_preview_context() ) || is_customize_preview() )
			&& Class_Usage::active() && file_exists( $css->full_css_path() );

		// Inline mode: no extra request / render-blocking file.
		$inline = ! $full && ! empty( $settings['inline_css'] ) && $this->inline_style( Css_Generator::HANDLE, $css->css_path(), $deps, $css->version() );
		if ( ! $inline ) {
			wp_enqueue_style( Css_Generator::HANDLE, remove_query_arg( 'ver', $full ? $css->full_css_url() : $css->css_url() ), $deps, $css->version() );
		}

		// WooCommerce shop pages: their own sheet, only where the shop appears.
		if ( file_exists( $css->shop_css_path() ) && Woo::active() && Woo::shop_styles_needed() ) {
			$shop_inline = ! empty( $settings['inline_css'] ) && $this->inline_style( self::SHOP_HANDLE, $css->shop_css_path(), array( Css_Generator::HANDLE ), $css->version() );
			if ( ! $shop_inline ) {
				wp_enqueue_style( self::SHOP_HANDLE, remove_query_arg( 'ver', $css->shop_css_url() ), array( Css_Generator::HANDLE ), $css->version() );
			}
		}

		if ( $this->hot_reload_enabled() ) {
			$this->enqueue_hot_reload( 'front', array(), $full );
		}
	}

	/** Handle of the WooCommerce shop pages sheet. */
	const SHOP_HANDLE = 'tokenforge-shop';

	/**
	 * Print a generated file as an inline style (no extra request).
	 *
	 * @param string $handle  Style handle.
	 * @param string $path    File.
	 * @param array  $deps    Dependencies.
	 * @param string $version Version.
	 * @return bool False when the file can't be read.
	 */
	private function inline_style( $handle, $path, $deps, $version ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents ) {
			return false;
		}
		wp_register_style( $handle, false, $deps, $version );
		wp_enqueue_style( $handle );
		// Never let a stylesheet close the inline <style> element it is printed in.
		wp_add_inline_style( $handle, str_ireplace( '</style', '<\/style', $contents ) );
		return true;
	}

	/**
	 * Are we rendering a page-builder's editor INTERFACE (not its canvas/preview
	 * and not the front end)? We must not style those UIs.
	 *
	 * @return bool
	 */
	private function is_builder_ui() {
		// Bricks builder shell (?bricks=run without ?brickspreview).
		if ( Platforms\Bricks::is_active() && Platforms\Bricks::is_builder_context() ) {
			return true;
		}
		// Oxygen builder.
		if ( ( defined( 'SHOW_CT_BUILDER' ) && SHOW_CT_BUILDER ) || null !== filter_input( INPUT_GET, 'ct_builder' ) ) {
			return true;
		}
		// Breakdance builder.
		if ( null !== filter_input( INPUT_GET, 'breakdance' ) || null !== filter_input( INPUT_GET, 'breakdance_iframe' ) ) {
			return true;
		}
		// Etch builder: ?etch=magic on the front page (its editor runs in the page itself).
		if ( 'magic' === filter_input( INPUT_GET, 'etch' ) ) {
			return true;
		}
		// Beaver Builder editor / Elementor preview.
		if ( null !== filter_input( INPUT_GET, 'fl_builder' ) || null !== filter_input( INPUT_GET, 'elementor-preview' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Add the generated stylesheet to the block editor's CONTENT styles. WordPress
	 * scopes these to the editor content area (and injects them into the editor
	 * iframe), so tokens/utilities/base styles preview on the content without
	 * touching the editor's own UI.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public function editor_styles( $settings ) {
		$css = Css_Generator::get_instance();
		if ( ! file_exists( $css->css_path() ) ) {
			return $settings;
		}
		// The editor scopes content styles by prefixing selectors, which doesn't
		// reliably handle @layer, so give it a layer-free build when layers are on.
		$contents = $css->editor_css();
		if ( '' === $contents ) {
			return $settings;
		}
		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = array();
		}
		$settings['styles'][] = array( 'css' => $contents );
		return $settings;
	}
}
