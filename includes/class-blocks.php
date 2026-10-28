<?php
/**
 * Gutenberg blocks (builder-agnostic: registered on every site).
 *
 * tokenforge/scheme-toggle: the light/dark toggle as a dynamic block. Server
 * rendered so the markup matches the shortcode and the Bricks element; the
 * behaviour (remembered choice, no flash) comes from TokenForge's front-end
 * script. Colour / font size / margin come from core block supports.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Block registration + render.
 */
class Blocks {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		// Block editor colour palette from the TokenForge colours.
		add_filter( 'wp_theme_json_data_theme', array( $this, 'palette' ) );
	}

	/**
	 * Colour names offered in the block editor palette: each family plus its
	 * shades and hover (channel/utility variables are skipped).
	 */
	const PALETTE_NAME = '/^(primary|secondary|tertiary|accent|base|neutral|action|success|danger|warning|info)(-(ultra-light|light|semi-light|hover|semi-dark|dark|ultra-dark))?$|^(white|black)$/';

	/**
	 * Palette entries [ slug, name, color ] from the current variables.
	 *
	 * @return array
	 */
	public static function palette_entries() {
		$out = array();
		foreach ( Settings::get_instance()->all_variables() as $t ) {
			if ( ! empty( $t['enabled'] ) && '' !== $t['value'] && preg_match( self::PALETTE_NAME, $t['name'] ) ) {
				$out[] = array(
					'slug'  => 'tf-' . $t['name'],
					'name'  => ucwords( str_replace( '-', ' ', $t['name'] ) ),
					'color' => 'var(--' . $t['name'] . ')',
				);
			}
		}
		return $out;
	}

	/**
	 * Add to / replace the theme's block-editor colour palette.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return \WP_Theme_JSON_Data
	 */
	public function palette( $theme_json ) {
		$mode = Settings::get_instance()->get_settings()['gutenberg_palette'];
		if ( 'off' === $mode || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}
		$ours = self::palette_entries();
		if ( ! $ours ) {
			return $theme_json;
		}
		$palette = $ours;
		if ( 'add' === $mode ) {
			$data     = $theme_json->get_data();
			$existing = isset( $data['settings']['color']['palette'] ) && is_array( $data['settings']['color']['palette'] ) ? $data['settings']['color']['palette'] : array();
			if ( isset( $existing['theme'] ) ) {
				$existing = $existing['theme'];
			}
			$palette = array_merge( array_values( (array) $existing ), $ours );
		}
		$color = array( 'palette' => $palette );
		if ( 'replace' === $mode ) {
			$color['defaultPalette'] = false; // hide WordPress' default colours too
		}
		return $theme_json->update_with( array( 'version' => 2, 'settings' => array( 'color' => $color ) ) );
	}

	/**
	 * Register blocks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'tokenforge-blocks',
			TOKENFORGE_URL . 'assets/blocks/scheme-toggle.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			TOKENFORGE_VERSION,
			true
		);
		wp_localize_script(
			'tokenforge-blocks',
			'TokenForgeBlocks',
			array(
				'switcher' => Scheme::switcher_enabled(),
				'icons'    => Scheme::toggle_icons(),
			)
		);
		register_block_type(
			'tokenforge/scheme-toggle',
			array(
				'api_version'     => 3,
				'title'           => __( 'Light/Dark Toggle', 'tokenforge' ),
				'description'     => __( 'Lets visitors switch between light and dark (remembered per visitor).', 'tokenforge' ),
				'category'        => 'design',
				'icon'            => 'lightbulb',
				'editor_script'   => 'tokenforge-blocks',
				'render_callback' => array( $this, 'render_scheme_toggle' ),
				'attributes'      => array(
					'label' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'html'       => false,
					'color'      => array(
						'text'       => true,
						'background' => true,
					),
					'typography' => array( 'fontSize' => true ),
					'spacing'    => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Render the toggle (nothing when the switcher is off).
	 *
	 * @param array $atts Block attributes.
	 * @return string
	 */
	public function render_scheme_toggle( $atts ) {
		if ( ! Scheme::switcher_enabled() ) {
			return '';
		}
		$label   = ! empty( $atts['label'] ) ? $atts['label'] : __( 'Toggle dark mode', 'tokenforge' );
		$wrapper = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'scheme-toggle', 'aria-label' => $label ) )
			: 'class="scheme-toggle" aria-label="' . esc_attr( $label ) . '"';
		return '<button type="button" ' . $wrapper . '>' . Scheme::toggle_icons() . '</button>';
	}
}
