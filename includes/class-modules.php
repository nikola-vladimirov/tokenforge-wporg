<?php
/**
 * Modules: each dashboard section (typography, buttons, effects …) can be
 * switched off as a whole.
 *
 * A module that is off keeps its settings. While TokenForge builds its output
 * (stylesheet, scripts, builder tools) the module's switches are read as off, so
 * it adds nothing. Its variables stay, because other parts use them. Switch the
 * module on again and everything comes back as it was.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Module switches.
 */
class Modules {

	const OPTION = 'tokenforge_modules';

	/**
	 * Module => [ settings module => [ key => value while off ] ].
	 * The keys are the section's own on/off switches.
	 */
	const MAP = array(
		'colors'     => array(
			'scheme'    => array( 'assignments' => false, 'switcher' => false, 'dark_mode' => 'off' ),
			'framework' => array( 'utilities_color' => false ),
		),
		'typography' => array(
			'components' => array( 'base_root' => false, 'text_size_classes' => false ),
			'fonts'      => array( 'enabled' => false ),
			'framework'  => array( 'utilities_typography' => false ),
		),
		'spacing'    => array(
			'components' => array( 'auto_container_gap' => false, 'auto_content_gap' => false, 'auto_grid_gap' => false ),
			'framework'  => array( 'utilities_spacing' => false ),
		),
		'layout'     => array(
			'components' => array( 'base_layout' => false, 'container_width' => false, 'section_padding' => false, 'content_grid' => false ),
			'defaults'   => array( 'sticky' => false, 'content_offset' => false, 'smooth_scroll' => false, 'scroll_offset_header' => false ),
			'framework'  => array( 'utilities_layout' => false ),
		),
		'borders'    => array(
			'extras' => array( 'enable_radius' => false, 'auto_radius' => false, 'enable_border' => false, 'inverted_radius' => false, 'enable_shadow' => false, 'enable_text_shadow' => false, 'enable_drop_shadow' => false ),
		),
		'buttons'    => array(
			'components' => array( 'base_buttons' => false, 'base_links' => false, 'ext_links' => false, 'bricks_button_styles' => false, 'control_bricks_buttons' => false ),
		),
		'cards'      => array(
			'components' => array( 'base_cards' => false ),
			'extras'     => array( 'ribbons' => false ),
		),
		'icons'      => array(
			'extras' => array( 'enable_icon' => false ),
		),
		'forms'      => array(
			'components' => array( 'base_forms' => false ),
		),
		'content'    => array(
			'content' => array( 'prose' => false, 'prose_auto' => false, 'rich_text' => false, 'flow' => false, 'flow_auto' => false, 'smart_spacing' => false, 'blockquotes' => false, 'bq_auto' => false ),
		),
		'overlays'   => array(
			'overlays' => array( 'basic' => false, 'overlays' => array(), 'surfaces' => array() ),
		),
		'effects'    => array(
			'motion' => array( 'enter' => false, 'exit' => false, 'visible' => false, 'hover' => false, 'border_fx' => false, 'transition_class' => false ),
		),
		'base'       => array(
			'defaults' => array( 'reset' => false, 'focus_styles' => false, 'object_fit' => false, 'is_bg' => false, 'sr_only' => false, 'columns' => false, 'media_block' => false, 'reduce_motion' => false ),
		),
		'scss'       => array(
			'scss' => array( 'enabled' => false, 'sync' => false ),
		),
		'recipes'    => array(
			'recipes' => array( 'enabled' => false ),
		),
		'woo'        => array(
			'woo' => array( 'enabled' => false ),
		),
		// Builder off: no TokenForge tools; grid_overlay stays true, which leaves Bricks' own grid overlay as Bricks ships it.
		'builder'    => array(
			'enhancements' => array( 'stack_add_elements' => false, 'pro_mode' => false, 'element_outlines' => false, 'grid_overlay' => true, 'grid_visualizer' => false, 'overflow_detection' => false, 'canvas_headings' => false, 'canvas_focus' => false, 'template_gallery' => false, 'bigger_code_editor' => false, 'ctx_vars_menu' => false, 'ctx_class_menu' => false, 'original_template_classes' => false, 'css_group_top' => false, 'css_autocomplete' => false ),
		),
	);

	/** @var bool Read settings as stored (dashboard, export), not as the output sees them. */
	private static $raw = false;


	/**
	 * Module ids.
	 *
	 * @return array
	 */
	public static function ids() {
		return array_keys( self::MAP );
	}

	/**
	 * Defaults: every module on.
	 *
	 * @return array id => true
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'modules', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array_fill_keys( self::ids(), true );
	}

	/**
	 * Settings.
	 *
	 * @return array id => bool
	 */
	public static function settings() {
		$s   = get_option( self::OPTION, array() );
		$s   = is_array( $s ) ? $s : array();
		$out = array();
		foreach ( self::defaults() as $id => $on ) {
			$out[ $id ] = array_key_exists( $id, $s ) ? (bool) $s[ $id ] : $on;
		}
		return $out;
	}

	/**
	 * Save settings.
	 *
	 * @param array $in id => bool.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$clean = array();
		foreach ( self::ids() as $id ) {
			$clean[ $id ] = ! isset( $in[ $id ] ) || ! empty( $in[ $id ] );
		}
		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Is a module on?
	 *
	 * @param string $id Module.
	 * @return bool
	 */
	public static function on( $id ) {
		return ! in_array( $id, self::off(), true );
	}

	/**
	 * Modules that are off.
	 *
	 * @return array
	 */
	private static function off() {
		// Read every time (get_option is cached): a reset or an import in the same
		// request must be seen by the CSS that is built right after it.
		return array_keys( array_filter( self::settings(), function ( $on ) {
			return ! $on;
		} ) );
	}

	/**
	 * A module's settings as the output sees them: switches of modules that are
	 * off read as off. Called at the end of every module's settings().
	 *
	 * @param string $mod      Settings module (components, extras …).
	 * @param array  $settings Stored settings.
	 * @return array
	 */
	public static function apply( $mod, $settings ) {
		if ( self::$raw || ! is_array( $settings ) ) {
			return $settings;
		}
		foreach ( self::off() as $id ) {
			if ( empty( self::MAP[ $id ][ $mod ] ) ) {
				continue;
			}
			foreach ( self::MAP[ $id ][ $mod ] as $k => $v ) {
				if ( array_key_exists( $k, $settings ) ) {
					$settings[ $k ] = $v;
				}
			}
		}
		return $settings;
	}

	/**
	 * Run a callback that must see the stored settings (dashboard state, export).
	 *
	 * @param callable $fn Callback.
	 * @return mixed Its return value.
	 */
	public static function raw( $fn ) {
		$was       = self::$raw;
		self::$raw = true;
		try {
			return $fn();
		} finally {
			self::$raw = $was;
		}
	}
}
