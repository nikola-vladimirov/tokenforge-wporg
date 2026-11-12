<?php
/**
 * Gutenberg blocks (builder-agnostic: registered on every site).
 *
 * The tokenforge/scheme-toggle block: the light/dark toggle as a dynamic block. Server
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
		add_action( 'init', array( __CLASS__, 'register_patterns' ) );
		add_action( 'init', array( __CLASS__, 'register_button_styles' ) );
		// Block editor presets (colours, sizes, spacing, fonts, shadows) from the TokenForge tokens.
		add_filter( 'wp_theme_json_data_theme', array( $this, 'theme_json' ) );
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
	 * A readable name for a preset: "text-xl" is "Text XL".
	 *
	 * @param string $name Variable name.
	 * @return string
	 */
	private static function preset_label( $name ) {
		$name = preg_replace_callback(
			'/-(xs|s|m|l|xl|xxl)$/',
			function ( $m ) {
				return ' ' . strtoupper( $m[1] );
			},
			$name
		);
		return ucwords( str_replace( '-', ' ', $name ) );
	}

	/**
	 * The block editor presets TokenForge offers, in theme.json shape. Every
	 * value is the variable itself, so the stylesheet stays the one source.
	 *
	 * @param string $mode 'replace' (plain slugs) or 'add' (tf- slugs, beside the theme's).
	 * @return array color, font-size, font-family, spacing, shadow => presets
	 */
	public static function presets( $mode ) {
		$pre  = 'add' === $mode ? 'tf-' : '';
		$out  = array_fill_keys( array( 'color', 'font-size', 'font-family', 'spacing', 'shadow' ), array() );
		$size = '(xs|s|m|l|xl|xxl)';
		// Replace mode: a slug the theme uses for another value stays the theme's (mapped to the nearest token), so ours takes tf-.
		$taken = 'replace' === $mode ? Block_Legacy::theme_presets() : array();
		foreach ( Settings::get_instance()->all_variables() as $t ) {
			if ( empty( $t['enabled'] ) || '' === $t['value'] ) {
				continue;
			}
			$n    = $t['name'];
			$v    = 'var(--' . $n . ')';
			$type = preg_match( self::PALETTE_NAME, $n ) ? 'color' : ( preg_match( '/^(text-' . $size . '|h[1-6])$/', $n ) ? 'font-size' : ( preg_match( '/^(section-)?space-' . $size . '$/', $n ) ? 'spacing' : 'shadow' ) );
			$own  = isset( $taken[ $type ][ $n ] ) && in_array( $taken[ $type ][ $n ], array( $t['value'], $v ), true );
			$row  = array(
				'slug' => ( isset( $taken[ $type ][ $n ] ) && ! $own ? 'tf-' : $pre ) . $n,
				'name' => self::preset_label( $n ),
			);
			if ( preg_match( self::PALETTE_NAME, $n ) ) {
				$out['color'][] = $row + array( 'color' => $v );
			} elseif ( preg_match( '/^(text-' . $size . '|h[1-6])$/', $n ) ) {
				// Not fluid for WordPress: the variable already is.
				$out['font-size'][] = $row + array(
					'size'  => $v,
					'fluid' => false,
				);
			} elseif ( preg_match( '/^(section-)?space-' . $size . '$/', $n ) ) {
				$out['spacing'][] = $row + array( 'size' => $v );
			} elseif ( preg_match( '/^shadow-[1-5]$/', $n ) ) {
				$out['shadow'][] = $row + array( 'shadow' => $v );
			}
		}
		$c = Components::settings();
		foreach ( array(
			'text'    => __( 'Text', 'tokenforge' ),
			'heading' => __( 'Heading', 'tokenforge' ),
		) as $k => $label ) {
			if ( ! empty( $c['base_root'] ) && '' !== (string) $c[ $k . '_font_family' ] ) {
				$out['font-family'][] = array(
					'slug'       => $pre . $k,
					'name'       => $label,
					'fontFamily' => 'var(--' . $k . '-font-family)',
				);
			}
		}
		return $out;
	}

	/**
	 * The slugs behind presets(): type => slugs. In replace mode the theme's font
	 * families stay listed (their font files load through them), so they count too.
	 *
	 * @param string $mode 'replace' or 'add'.
	 * @return array
	 */
	public static function preset_slugs( $mode ) {
		$out = array();
		foreach ( self::presets( $mode ) as $type => $list ) {
			$out[ $type ] = array_column( $list, 'slug' );
		}
		if ( 'replace' === $mode ) {
			$out['font-family'] = array_merge( $out['font-family'], array_map( 'strval', array_keys( Block_Legacy::theme_presets()['font-family'] ) ) );
		}
		return $out;
	}

	/**
	 * Add the presets to the theme's (sit beside) or put them in their place
	 * (replace, which also hides the WordPress defaults). Font families are always
	 * added: the list holds the theme's and the self-hosted ones (Fonts::theme_json()).
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return \WP_Theme_JSON_Data
	 */
	public function theme_json( $theme_json ) {
		$mode = Settings::get_instance()->get_settings()['gutenberg_palette'];
		if ( ! in_array( $mode, array( 'add', 'replace' ), true ) || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}
		$data     = (array) $theme_json->get_data();
		$settings = array();
		$defaults = array(
			'color'       => 'defaultPalette',
			'font-size'   => 'defaultFontSizes',
			'spacing'     => 'defaultSpacingSizes',
			'shadow'      => 'defaultPresets',
			'font-family' => '', // WordPress has no default font families to hide.
		);
		foreach ( self::presets( $mode ) as $type => $ours ) {
			if ( ! $ours ) {
				continue;
			}
			list( $a, $b ) = Block_Legacy::TYPES[ $type ][0];
			if ( 'add' === $mode || 'font-family' === $type ) {
				$existing = isset( $data['settings'][ $a ][ $b ] ) && is_array( $data['settings'][ $a ][ $b ] ) ? $data['settings'][ $a ][ $b ] : array();
				if ( isset( $existing['theme'] ) ) {
					$existing = $existing['theme']; // stored by origin.
				}
				$slugs = array_column( $ours, 'slug' );
				$ours  = array_merge(
					array_values(
						array_filter(
							(array) $existing,
							function ( $p ) use ( $slugs ) {
								return is_array( $p ) && ! ( isset( $p['slug'] ) && in_array( $p['slug'], $slugs, true ) );
							}
						)
					),
					$ours
				);
			} elseif ( '' !== $defaults[ $type ] ) {
				$settings[ $a ][ $defaults[ $type ] ] = false;
				if ( 'spacing' === $type ) {
					$settings[ $a ]['spacingScale'] = array( 'steps' => 0 ); // a theme's generated scale would sit beside ours.
				}
			}
			$settings[ $a ][ $b ] = $ours;
		}
		if ( ! $settings ) {
			return $theme_json;
		}
		return $theme_json->update_with(
			array(
				// The current schema: an older version number would switch the default lists off in "add" mode as well.
				'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => $settings,
			)
		);
	}

	/**
	 * The TokenForge block patterns (includes/patterns/<name>.php, each returns
	 * the pattern's properties).
	 *
	 * @return void
	 */
	public static function register_patterns() {
		if ( ! function_exists( 'register_block_pattern' ) || empty( Settings::get_instance()->get_settings()['block_patterns'] ) ) {
			return;
		}
		register_block_pattern_category( 'tokenforge', array( 'label' => 'TokenForge' ) );
		foreach ( array( 'hero', 'cards', 'pricing', 'faq', 'contact' ) as $name ) {
			register_block_pattern( 'tokenforge/' . $name, array( 'categories' => array( 'tokenforge' ) ) + require TOKENFORGE_DIR . 'includes/patterns/' . $name . '.php' );
		}
	}

	/**
	 * A Button block style per button family (tf-primary, ...), with the family's
	 * button colours.
	 *
	 * @return void
	 */
	public static function register_button_styles() {
		if ( ! function_exists( 'register_block_style' ) || empty( Settings::get_instance()->get_settings()['block_button_styles'] ) ) {
			return;
		}
		$c = Components::settings();
		foreach ( Components::btn_families( $c ) as $f ) {
			$vars = '';
			foreach ( Components::btn_var_map( $c, $f, '' ) as $n => $v ) {
				$vars .= '--' . $n . ':' . $v . ';';
			}
			$sel = '.wp-block-button.is-style-tf-' . $f . '>.wp-block-button__link';
			$css = $sel . '{' . $vars . 'background:var(--btn-background);color:var(--btn-text-color);border:var(--btn-border-width,1px) solid var(--btn-border-color)}'
				. $sel . ':hover{background:var(--btn-background-hover);color:var(--btn-text-color-hover);border-color:var(--btn-border-color-hover)}';
			register_block_style(
				'core/button',
				array(
					'name'         => 'tf-' . $f,
					'label'        => self::preset_label( $f ),
					'inline_style' => str_ireplace( '</style', '<\/style', $css ),
				)
			);
		}
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
			? get_block_wrapper_attributes(
				array(
					'class'      => 'scheme-toggle',
					'aria-label' => $label,
				)
			)
			: 'class="scheme-toggle" aria-label="' . esc_attr( $label ) . '"';
		return '<button type="button" ' . $wrapper . '>' . Scheme::toggle_icons() . '</button>';
	}
}
