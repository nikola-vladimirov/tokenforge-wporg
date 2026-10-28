<?php
/**
 * Bricks builder enhancement toggles.
 *
 * Loads UI/behaviour tweaks into the Bricks builder SHELL (opt-in): stack the
 * Add Elements panel, outline canvas containers, a grid/flex visualizer, and a
 * taller code editor. These enhance the builder interface, so unlike the design
 * stylesheet they DO load in the builder shell (their own scoped assets).
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Platforms\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Builder enhancement loader + settings.
 */
class Builder_Enhancements {

	const OPTION = 'tokenforge_enhancements';

	/**
	 * Default toggles (all off, enhancements are opt-in).
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'enhancements', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'stack_add_elements' => false,
			'pro_mode'           => false,
			'element_outlines'   => false,
			'grid_overlay'       => true,  // Bricks' own grid overlay on the selected grid element (off = hidden)
			'grid_visualizer'    => false, // outline EVERY grid/flex container in the canvas (debug aid)
			'overflow_detection' => false,
			'canvas_headings'    => false, // a heading level badge (h1 to h6) on each heading in the canvas
			'canvas_focus'       => false, // a number on each element Tab reaches, in tab order
			'template_gallery'   => false, // tidier remote-template gallery (covered thumbnails, less clutter)
			'hide_elements'      => '',    // Bricks element names to hide from Add Elements (comma separated)
			'bigger_code_editor' => false,
			'ctx_vars_menu'      => true,  // right-click a control input → insert a variable
			'ctx_class_menu'     => true,  // right-click the class selector → add utility classes
			'original_template_classes' => true, // remote templates keep their own classes (only on id/name conflicts)
			'css_group_top'      => true,  // Style tab: CSS control group first
			'css_autocomplete'   => true,  // CSS editor: complete --variables and .classes while typing
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return \TokenForge\Modules::apply( 'enhancements', wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$clean = array();
		foreach ( self::defaults() as $k => $v ) {
			if ( 'hide_elements' === $k ) {
				$names       = array_filter( array_map( 'trim', explode( ',', strtolower( isset( $in[ $k ] ) ? (string) $in[ $k ] : '' ) ) ) );
				$clean[ $k ] = implode( ', ', array_map( function ( $n ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', $n );
				}, $names ) );
			} else {
				$clean[ $k ] = ! empty( $in[ $k ] );
			}
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Bricks' own (2.4+) builder options that cover "style preview & keyboard"
	 * behaviour. We don't re-implement them, the dashboard just surfaces the
	 * switches. Bricks stores each as 'on' in bricks_global_settings (absent = off).
	 */
	const NATIVE = array(
		'builderClassPreviewOnHover'        => 'Class preview on hover (also while arrowing through the class list)',
		'builderClassExpandSelectedOnType'  => 'Keep typing to extend the selected class suggestion',
		'builderClassAutoSelectFirst'       => 'Auto-select the first class suggestion',
		'builderVariablePreviewOnHover'     => 'Variable preview on hover',
		'builderVariableSelectFirstOnEnter' => 'Enter picks the exact / first variable suggestion',
		'builderColorPreviewOnHover'        => 'Color preview on hover',
		'builderFontFamilyPreviewOnHover'   => 'Font family preview on hover',
		'builderApplyVariablesAndCalculations' => 'Expand variables and calculations on Enter (e.g. space-m → var(--space-m), maths in inputs)',
	);

	/**
	 * Current state of the native Bricks options (empty when Bricks is absent).
	 *
	 * @return array key => bool
	 */
	public static function native_settings() {
		if ( ! Bricks::is_active() ) {
			return array();
		}
		$gs  = get_option( 'bricks_global_settings', array() );
		$gs  = is_array( $gs ) ? $gs : array();
		$out = array();
		foreach ( self::NATIVE as $key => $label ) {
			$out[ $key ] = isset( $gs[ $key ] );
		}
		return $out;
	}

	/**
	 * Write the native Bricks options, only our known keys; every other Bricks
	 * setting is preserved as-is.
	 *
	 * @param array $in key => bool.
	 * @return void
	 */
	public static function save_native( $in ) {
		if ( ! Bricks::is_active() || ! is_array( $in ) ) {
			return;
		}
		$gs      = get_option( 'bricks_global_settings', array() );
		$gs      = is_array( $gs ) ? $gs : array();
		$changed = false;
		foreach ( self::NATIVE as $key => $label ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$on = ! empty( $in[ $key ] );
			if ( $on && ! isset( $gs[ $key ] ) ) {
				$gs[ $key ] = 'on';
				$changed    = true;
			} elseif ( ! $on && isset( $gs[ $key ] ) ) {
				unset( $gs[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'bricks_global_settings', $gs );
		}
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 13 );
		$s = self::settings();
		if ( ! empty( $s['original_template_classes'] ) ) {
			add_filter( 'bricks/get_remote_templates_data', array( __NAMESPACE__ . '\Template_Classes', 'filter' ) );
		}
	}

	/**
	 * Enqueue in the Bricks builder shell only.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! Bricks::is_builder_context() ) {
			return;
		}
		wp_enqueue_style( 'tokenforge-enh', TOKENFORGE_URL . 'assets/builder/builder-enhancements.css', array(), TOKENFORGE_VERSION );
		wp_enqueue_script( 'tokenforge-enh', TOKENFORGE_URL . 'assets/builder/builder-enhancements.js', array(), TOKENFORGE_VERSION, true );
		$s = self::settings();
		wp_localize_script( 'tokenforge-enh', 'TokenForgeEnh', $s );
		// Canvas badges (heading levels, focus order): administrators only; the script idles until a switch is on.
		if ( current_user_can( 'manage_options' ) ) {
			wp_enqueue_script( 'tokenforge-canvas-badges', TOKENFORGE_URL . 'assets/builder/canvas-badges.js', array( 'tokenforge-enh' ), TOKENFORGE_VERSION, true );
		}
		$hide = array_filter( array_map( 'trim', explode( ',', (string) $s['hide_elements'] ) ) );
		if ( $hide ) {
			$sel = array();
			foreach ( $hide as $name ) {
				$sel[] = '#bricks-panel-elements .bricks-add-element[data-element-name="' . preg_replace( '/[^a-z0-9_\-]/', '', $name ) . '" i]';
			}
			wp_add_inline_style( 'tokenforge-enh', implode( ',', $sel ) . '{display:none!important}' );
		}

		$recipes = \TokenForge\Recipes::for_editor();
		if ( ! empty( $s['ctx_vars_menu'] ) || ! empty( $s['ctx_class_menu'] ) || ! empty( $s['css_autocomplete'] ) || $recipes ) {
			wp_enqueue_style( 'tokenforge-ctx', TOKENFORGE_URL . 'assets/builder/context-menu.css', array(), TOKENFORGE_VERSION );
			wp_enqueue_script( 'tokenforge-ctx', TOKENFORGE_URL . 'assets/builder/context-menu.js', array(), TOKENFORGE_VERSION, true );
			wp_localize_script(
				'tokenforge-ctx',
				'TokenForgeCtx',
				array(
					'vars_menu'  => ! empty( $s['ctx_vars_menu'] ),
					'class_menu' => ! empty( $s['ctx_class_menu'] ),
					'css_ac'     => ! empty( $s['css_autocomplete'] ),
					'recipes'    => $recipes, // null = off
					'vars'       => self::menu_variables(),
					'classes'    => self::menu_classes(),
					'restUrl'    => esc_url_raw( rest_url( \TokenForge\Rest::NS ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		// Attribute suggestions in the builder's Attributes control, and right-click > Add attribute.
		if ( ! empty( $s['ctx_vars_menu'] ) || ! empty( $s['ctx_class_menu'] ) || ! empty( $s['css_autocomplete'] ) ) {
			wp_enqueue_style( 'tokenforge-attrs', TOKENFORGE_URL . 'assets/builder/attr-autocomplete.css', array(), TOKENFORGE_VERSION );
			wp_enqueue_script( 'tokenforge-attrs', TOKENFORGE_URL . 'assets/builder/attr-autocomplete.js', array(), TOKENFORGE_VERSION, true );
			wp_localize_script( 'tokenforge-attrs', 'TokenForgeAttrs', self::attrs_data( ! empty( $s['ctx_vars_menu'] ) || ! empty( $s['ctx_class_menu'] ) ) );
		}
	}

	/**
	 * Data for the attribute suggestions: the list comes from Attributes::all() and Attributes::others().
	 *
	 * @param bool $menu Whether the right-click menu is on.
	 * @return array
	 */
	public static function attrs_data( $menu ) {
		$list = array();
		foreach ( \TokenForge\Attributes::all() as $name => $a ) {
			$list[] = array(
				'name'   => $name,
				'values' => array_map( 'strval', $a[0] ),
				'desc'   => $a[2],
			);
		}
		// The attributes other features use (scheme, forms): [ example, text, optional values ].
		foreach ( \TokenForge\Attributes::others() as $name => $a ) {
			$list[] = array(
				'name'   => $name,
				'values' => isset( $a[2] ) ? array_map( 'strval', $a[2] ) : array(),
				'desc'   => $a[1],
			);
		}
		return array(
			'attrs' => $list,
			'menu'  => (bool) $menu,
			'i18n'  => array(
				'add'        => __( 'Add attribute', 'tokenforge' ),
				'search'     => __( 'Search attributes...', 'tokenforge' ),
				'noMatch'    => __( 'No matches', 'tokenforge' ),
				/* translators: %s: an attribute name such as data-tf-clamp */
				'valueFor'   => __( 'Value for %s', 'tokenforge' ),
				'listName'   => __( 'Attribute suggestions', 'tokenforge' ),
				'listValue'  => __( 'Attribute value suggestions', 'tokenforge' ),
				/* translators: %d: number of suggestions */
				'count'      => __( '%d suggestions', 'tokenforge' ),
				/* translators: %s: the attribute that was added, e.g. data-tf-clamp="3" */
				'added'      => __( 'Added %s', 'tokenforge' ),
				'noElement'  => __( 'Select an element first.', 'tokenforge' ),
				'hintAc'     => __( 'Up/Down move, Enter pick, Esc close', 'tokenforge' ),
				'hintMenu'   => __( 'Enter pick, Esc back, Shift+right-click: browser menu', 'tokenforge' ),
			),
		);
	}

	/**
	 * Variables for the contextual menu: [ name, group, value ] for every
	 * enabled variable, minus colour channel parts (-h/-s/-l, -r/-g/-b, -rgb,
	 * -hsl) that aren't usable as values on their own.
	 *
	 * @return array
	 */
	public static function menu_variables() {
		$out = array();
		foreach ( \TokenForge\Token_Catalog::every_variable() as $t ) {
			if ( empty( $t['enabled'] ) || '' === $t['value'] ) {
				continue;
			}
			if ( 'color' === $t['group'] && preg_match( '/-(h|s|l|r|g|b|rgb|hsl)$/', $t['name'] ) ) {
				continue;
			}
			$out[] = array( $t['name'], $t['group'], $t['value'] );
		}
		return $out;
	}

	/**
	 * Every utility/component class TokenForge generates (for the class menu).
	 *
	 * @return string[]
	 */
	public static function menu_classes() {
		$names = array();
		foreach ( array( '\TokenForge\Framework', '\TokenForge\Components', '\TokenForge\Scheme', '\TokenForge\Extras', '\TokenForge\Defaults', '\TokenForge\Content', '\TokenForge\Width', '\TokenForge\Overlays', '\TokenForge\Motion' ) as $cls ) {
			$b     = call_user_func( array( $cls, 'build' ) );
			$names = array_merge( $names, $b['classes'] );
		}
		$names = array_values( array_unique( $names ) );
		sort( $names );
		return $names;
	}
}
