<?php
/**
 * Plugin bootstrap.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components together.
 */
class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Platforms\Bricks */
	public $bricks;

	/**
	 * Singleton.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Admin UI + REST API (available in admin and for logged-in editors).
		if ( is_admin() ) {
			new Admin();
			new Changelog(); // "Changelog" link on the Plugins screen
		}
		new Rest();
		new Ai(); // abilities + guidance for AI agents (MCP / Bricks AI)
		new Fonts(); // self-hosted fonts: preload tags + Bricks font list
		new Woo(); // WooCommerce styling (only does something while WooCommerce is active)

		// Builder-agnostic: load the stylesheet on the front end + Gutenberg on
		// ANY WordPress site.
		new Frontend();
		new Blocks(); // Gutenberg blocks (all sites)
		new Features\A11y_Auto(); // missing accessibility attributes on the front end (every switch off by default)
		new Features\Attr_Front(); // data-tf-copy, -tooltip and -filter: the small script and the filter rules, only on pages that use them
		Class_Usage::init(); // new utility classes in saved content join the stylesheet (when unused ones are left out)
		new Features\Builder_Panel(); // the TokenForge window: Bricks builder + front end (admins)
		new Features\A11y(); // axe-core test engine: the builder loads it on demand, never a visitor page
		new Features\Check(); // page check engine in the builder + its read-only /check lookups

		add_action( 'update_option_' . Components::OPTION, array( Features\Form_Submissions::class, 'settings_saved' ), 10, 2 ); // also while Bricks is inactive

		// Bricks integration only when Bricks is the active theme.
		if ( Platforms\Bricks::is_active() ) {
			$this->bricks = new Platforms\Bricks();
			new Features\Bem_Generator();
			new Features\Builder_Enhancements();
			new Features\Form_Extras(); // steps and conditional fields in the builder's form (when switched on)
			new Features\Form_Fields(); // input looks, date rules, messages, options from content, live values
			new Features\Form_Rules(); // limits, submit rules, update meta and add to cart actions
			new Features\Form_Presets(); // form, field, style and saved presets (when switched on)
			new Features\Form_Submissions(); // the builder's submissions screen, extended (when switched on)
			new Platforms\Bricks_Theme(); // TokenForge <-> the site's Bricks theme style
			new Font_Convert(); // Bricks font manager: TTF/OTF faces get a WOFF2 (or WOFF) file
		}

		$this->maybe_upgrade();
		add_action( 'init', array( $this, 'maybe_setup' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_rebuild_for_woo' ) );
	}

	/**
	 * WooCommerce was switched on or off since the stylesheet was built: rebuild
	 * it once, so the shop styles come and go with WooCommerce.
	 *
	 * @return void
	 */
	public function maybe_rebuild_for_woo() {
		$meta = get_option( Css_Generator::OPTION_META, array() );
		if ( ! is_array( $meta ) || ! isset( $meta['version'] ) ) {
			return;
		}
		if ( ( ! empty( $meta['woo'] ) ) !== Woo::active() || ( ! empty( $meta['gs'] ) ) !== Woo::uses_global_styles() || ( ! empty( $meta['split'] ) ) !== Woo::split() ) {
			Css_Generator::get_instance()->generate();
		}
	}

	/**
	 * After a fresh install: link or create the Bricks theme style (once).
	 *
	 * @return void
	 */
	public function maybe_setup() {
		if ( ! get_option( 'tokenforge_setup_pending' ) ) {
			return;
		}
		delete_option( 'tokenforge_setup_pending' );
		if ( Platforms\Bricks::is_active() && Platforms\Bricks_Theme::enabled() ) {
			Platforms\Bricks_Theme::setup();
		}
	}

	/**
	 * When the plugin version changes (e.g. an overwrite update that doesn't fire
	 * the activation hook), rebuild the stylesheet and re-sync Bricks so the new
	 * output is applied without needing a manual Save.
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		$previous = get_option( 'tokenforge_version' );
		if ( $previous === TOKENFORGE_VERSION ) {
			return;
		}
		// < 0.25: "grid visualizer" was mis-mapped from ACSS's grid overlay (which
		// only toggles Bricks' own overlay) and outlined every container. Reset it.
		if ( $previous && version_compare( (string) $previous, '0.25.0', '<' ) ) {
			$enh = get_option( Features\Builder_Enhancements::OPTION );
			if ( is_array( $enh ) ) {
				$enh['grid_overlay']    = true;
				$enh['grid_visualizer'] = false;
				update_option( Features\Builder_Enhancements::OPTION, $enh );
			}
		}
		// Fix tokens mis-filed as colours by the pre-0.18 group heuristic.
		$settings = Settings::get_instance();
		list( $tokens, $changed ) = Tokens::repair_groups( $settings->get_tokens() );
		if ( $changed ) {
			$settings->save_tokens( $tokens );
		}
		// First run with the Width module: show the content width already in use.
		if ( false === get_option( Width::OPTION ) ) {
			Width::adopt_stored_token();
		}
		// First run with the Motion module: show the transition values in use.
		if ( false === get_option( Motion::OPTION ) ) {
			Motion::adopt_stored_tokens();
		}
		// 0.37: the colour families are made from Colors when the stylesheet is
		// built, so stored copies with the same value go (nothing on the site
		// changes). Copies with a value of their own stay; the Tokens screen
		// shows them, and Use settings hands them back to Colors.
		if ( $previous && version_compare( (string) $previous, '0.37.0', '<' ) ) {
			// Never saved: the families were read from the tokens; keep them first.
			if ( ! is_array( get_option( Palette::OPTION, null ) ) ) {
				update_option( Palette::OPTION, Palette::settings(), false );
			}
			list( $tokens, $fixed ) = Palette::without_repeats( $settings->get_tokens() );
			// Line heights and transitions filed as colours by the old name guess
			// (base-text-lh, link-transition) go where they belong.
			foreach ( $tokens as $i => $t ) {
				if ( 'color' === $t['group'] && preg_match( '/-(lh|line-height|transition|duration|timing|delay)$/', $t['name'] ) ) {
					$tokens[ $i ]['group'] = Tokens::derive_group( $t['name'] );
					$fixed++;
				}
			}
			if ( $fixed ) {
				$settings->save_tokens( $tokens );
			}
		}
		// 0.30: existing Bricks sites get linked to their theme style too (it
		// adopts the style's typography first, so nothing on the site changes).
		if ( $previous && version_compare( (string) $previous, '0.30.0', '<' ) ) {
			update_option( 'tokenforge_setup_pending', 1, false );
		}
		Css_Generator::get_instance()->rebuild();
		update_option( 'tokenforge_version', TOKENFORGE_VERSION );
	}
}
