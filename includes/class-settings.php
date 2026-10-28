<?php
/**
 * Token + settings storage.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's tokens and options.
 */
class Settings {

	const OPTION_TOKENS   = 'tokenforge_tokens';
	const OPTION_SETTINGS = 'tokenforge_settings';

	/** @var Settings|null */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default plugin options.
	 *
	 * @return array
	 */
	public function default_settings() {
		return Factory::merge( 'settings', $this->builtin_settings() );
	}

	/**
	 * Built-in plugin settings (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public function builtin_settings() {
		return array(
			'sync_bricks_vars'    => true,
			'vars_trans'          => true,  // builder variables: transparent steps (-trans-10 … -90)
			'vars_channels'       => false, // builder variables: colour channel values (-hex, -rgb, -h …, -ref)
			'vars_alt'            => false, // builder variables: dark mode values (-alt)
			'vars_parts'          => false, // builder variables: buttons, cards, blockquotes, shop
			'bricks_theme_sync'   => true,  // keep a Bricks theme style in step with TokenForge (both ways)
			'ui_theme'            => 'bricks', // dashboard look: bricks | flat | light
			'sync_bricks_colors'  => true,
			'category_name'       => 'TokenForge',
			'enqueue_in_builder'  => true,
			'admin_bar_link'      => true,
			'prune_classes'       => true,  // drop generated Bricks classes that are no longer output
			'prune_variables'     => true,  // drop synced Bricks variables/colors for removed tokens
			'inline_css'          => false, // print the stylesheet inline instead of linking the file
			'layer_defaults'      => false, // @layer the reset/defaults
			'layer_base'          => false, // @layer the base element styles
			'layer_content'       => false, // @layer the prose styles
			'layer_buttons'       => false, // @layer buttons + the external-link indicator
			'layer_cards'         => false, // @layer cards
			'layer_icons'         => false, // @layer the icon system
			'hot_reload'          => true,  // live-update the CSS in open tabs (admins only)
			'gutenberg_palette'   => 'off', // 'off' | 'add' (to the theme palette) | 'replace'
			'panel_shortcut'      => 'alt+shift+t', // opens/closes the TokenForge window (builder + front end)
			'frontend_panel'      => true,  // the window on the site's pages too (administrators only; never in the builder)
			'frontend_launcher'   => false, // a floating TokenForge button on the front end
			'canvas_panel'        => true,  // a TokenForge button in the builder canvas while editing (opens the window)
			'scss_dock_shortcut'  => 'alt+shift+s', // opens/closes the Global SCSS dock in the Bricks builder
			'scss_dock_tab'       => true,  // a small SCSS tab at the bottom of the canvas
			'ai_abilities'        => true,  // tokenforge/* abilities (MCP tools) for AI agents
			'ai_guidance'         => true,  // tell agents to use TokenForge (MCP instructions, Bricks orientation + design context)
		) + Features\A11y_Auto::defaults(); // automatic accessibility attributes, all off
	}

	/**
	 * Get plugin options merged with defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->default_settings() );
	}

	/**
	 * Save plugin options.
	 *
	 * @param array $settings Options.
	 * @return void
	 */
	public function save_settings( $settings ) {
		$defaults = $this->default_settings();
		$clean    = array(
			'sync_bricks_vars'   => ! empty( $settings['sync_bricks_vars'] ),
			'vars_trans'         => ! empty( $settings['vars_trans'] ),
			'vars_channels'      => ! empty( $settings['vars_channels'] ),
			'vars_alt'           => ! empty( $settings['vars_alt'] ),
			'vars_parts'         => ! empty( $settings['vars_parts'] ),
			'bricks_theme_sync'  => ! empty( $settings['bricks_theme_sync'] ),
			'ui_theme'           => ( isset( $settings['ui_theme'] ) && in_array( $settings['ui_theme'], array( 'flat', 'light' ), true ) ) ? $settings['ui_theme'] : 'bricks',
			'sync_bricks_colors' => ! empty( $settings['sync_bricks_colors'] ),
			'enqueue_in_builder' => ! empty( $settings['enqueue_in_builder'] ),
			'admin_bar_link'     => ! empty( $settings['admin_bar_link'] ),
			'prune_classes'      => ! empty( $settings['prune_classes'] ),
			'prune_variables'    => ! empty( $settings['prune_variables'] ),
			'inline_css'         => ! empty( $settings['inline_css'] ),
			'layer_defaults'     => ! empty( $settings['layer_defaults'] ),
			'layer_base'         => ! empty( $settings['layer_base'] ),
			'layer_content'      => ! empty( $settings['layer_content'] ),
			'layer_buttons'      => ! empty( $settings['layer_buttons'] ),
			'layer_cards'        => ! empty( $settings['layer_cards'] ),
			'layer_icons'        => ! empty( $settings['layer_icons'] ),
			'hot_reload'         => ! empty( $settings['hot_reload'] ),
			'gutenberg_palette'  => ( isset( $settings['gutenberg_palette'] ) && in_array( $settings['gutenberg_palette'], array( 'add', 'replace' ), true ) ) ? $settings['gutenberg_palette'] : 'off',
			'category_name'      => isset( $settings['category_name'] ) ? sanitize_text_field( $settings['category_name'] ) : $defaults['category_name'],
			'panel_shortcut'     => self::shortcut( isset( $settings['panel_shortcut'] ) ? $settings['panel_shortcut'] : $defaults['panel_shortcut'] ),
			'frontend_panel'     => ! empty( $settings['frontend_panel'] ),
			'frontend_launcher'  => ! empty( $settings['frontend_launcher'] ),
			'canvas_panel'       => ! empty( $settings['canvas_panel'] ),
			'scss_dock_shortcut' => self::shortcut( isset( $settings['scss_dock_shortcut'] ) ? $settings['scss_dock_shortcut'] : $defaults['scss_dock_shortcut'] ),
			'scss_dock_tab'      => ! empty( $settings['scss_dock_tab'] ),
			'ai_abilities'       => ! empty( $settings['ai_abilities'] ),
			'ai_guidance'        => ! empty( $settings['ai_guidance'] ),
		) + Features\A11y_Auto::clean( $settings );
		update_option( self::OPTION_SETTINGS, $clean );
	}

	/**
	 * A keyboard shortcut like "alt+shift+t" / "ctrl+alt+f2" ('' = none).
	 *
	 * @param string $in Raw shortcut.
	 * @return string
	 */
	public static function shortcut( $in ) {
		$parts = array_filter( array_map( 'trim', explode( '+', strtolower( (string) $in ) ) ), 'strlen' );
		$mods  = array();
		$key   = '';
		foreach ( $parts as $p ) {
			$p = str_replace( array( 'control', 'cmd', 'command', 'option' ), array( 'ctrl', 'meta', 'meta', 'alt' ), $p );
			if ( in_array( $p, array( 'ctrl', 'alt', 'shift', 'meta' ), true ) ) {
				$mods[ $p ] = true;
			} elseif ( preg_match( '/^([a-z0-9]|f[1-9]|f1[0-2]|[`\-=\[\];\',.\/\\\\])$/', $p ) ) {
				$key = $p;
			}
		}
		if ( '' === $key || ( ! preg_match( '/^f\d/', $key ) && ! isset( $mods['ctrl'] ) && ! isset( $mods['alt'] ) && ! isset( $mods['meta'] ) ) ) {
			return ''; // a bare (or shift+) letter would fire while typing
		}
		$out = array();
		foreach ( array( 'ctrl', 'alt', 'shift', 'meta' ) as $mod ) {
			if ( isset( $mods[ $mod ] ) ) {
				$out[] = $mod;
			}
		}
		$out[] = $key;
		return implode( '+', $out );
	}

	/**
	 * Get all stored tokens (normalised).
	 *
	 * @return array
	 */
	public function get_tokens() {
		$raw = get_option( self::OPTION_TOKENS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $t ) {
			$n = Tokens::normalize( $t );
			if ( $n ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * All variables the site should output: the colour families (Colors), the
	 * stored tokens, then what the other settings make (width, typography and
	 * spacing scales, borders, icons, motion). A stored token wins on a name
	 * clash.
	 *
	 * @return array
	 */
	public function all_variables() {
		$stored = $this->get_tokens();
		$names  = array();
		foreach ( $stored as $t ) {
			$names[ $t['name'] ] = true;
		}
		$colors = array();
		foreach ( Palette::tokens() as $t ) {
			if ( ! isset( $names[ $t['name'] ] ) ) {
				$colors[]            = $t;
				$names[ $t['name'] ] = true;
			}
		}
		$tokens    = array_merge( $colors, $stored );
		$generated = array_merge( Width::variables(), Scale_Engine::variables(), Extras::variables(), Motion::variables() );
		foreach ( $generated as $v ) {
			$n = Tokens::normalize( $v );
			if ( $n && ! isset( $names[ $n['name'] ] ) ) {
				$tokens[]            = $n;
				$names[ $n['name'] ] = true;
			}
		}
		return $tokens;
	}

	/**
	 * Replace all tokens.
	 *
	 * @param array $tokens List of raw tokens.
	 * @return array The normalised, de-duplicated tokens that were stored.
	 */
	public function save_tokens( $tokens ) {
		$clean = array();
		$seen  = array();
		foreach ( (array) $tokens as $t ) {
			$n = Tokens::normalize( $t );
			if ( ! $n || isset( $seen[ $n['name'] ] ) ) {
				continue;
			}
			$seen[ $n['name'] ] = true;
			$clean[]            = $n;
		}
		update_option( self::OPTION_TOKENS, $clean, false );
		return $clean;
	}
}
