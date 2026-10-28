<?php
/**
 * Bricks theme style sync.
 *
 * TokenForge keeps one Bricks theme style (named after the site, applied to the
 * entire website) in step with its settings:
 *  - TokenForge → Bricks: the style's fields hold TokenForge variables
 *    (container width var(--content-width), body size var(--text-m), link colour
 *    var(--link-color) …) or, where Bricks needs a plain value (font family,
 *    weight), the TokenForge value itself;
 *  - Bricks → TokenForge: a field you change in the theme style is written into
 *    the matching TokenForge setting, and the field points at TokenForge again.
 * A copy of the style that the builder kept from before a TokenForge save is
 * recognised and never undoes that save.
 *
 * Only runs when Bricks is the active theme.
 *
 * @package TokenForge
 */

namespace TokenForge\Platforms;

use TokenForge\Components;
use TokenForge\Css_Generator;
use TokenForge\Defaults;
use TokenForge\Modules;
use TokenForge\Palette;
use TokenForge\Settings;
use TokenForge\Width;
use TokenForge\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Theme style sync.
 */
class Bricks_Theme {

	const OPTION = 'bricks_theme_styles';
	const STATE  = 'tokenforge_theme_sync'; // { style, pushed: { path => value }, prev: { path => value } }

	/** Bricks colour fields => TokenForge colour family. */
	const COLORS = array(
		'colorPrimary'   => 'primary',
		'colorSecondary' => 'secondary',
		'colorInfo'      => 'info',
		'colorSuccess'   => 'success',
		'colorWarning'   => 'warning',
		'colorDanger'    => 'danger',
	);

	/** Typography fields: Bricks property => TokenForge key suffix. */
	const TYPE_FIELDS = array(
		'font-weight'    => 'font_weight',
		'font-style'     => 'font_style',
		'line-height'    => 'line_height',
		'letter-spacing' => 'letter_spacing',
		'text-transform' => 'text_transform',
	);

	/** Button element styles (field prefix; '' = no colour class) => TokenForge family + variant. */
	const BUTTONS = array(
		''                 => array( 'primary', 'solid' ),
		'outline'          => array( 'primary', 'outline' ),
		'primary'          => array( 'primary', 'solid' ),
		'primaryOutline'   => array( 'primary', 'outline' ),
		'secondary'        => array( 'secondary', 'solid' ),
		'secondaryOutline' => array( 'secondary', 'outline' ),
		'light'            => array( 'neutral', 'light' ),
		'lightOutline'     => array( 'neutral', 'outline-light' ),
		'dark'             => array( 'neutral', 'dark' ),
		'darkOutline'      => array( 'neutral', 'outline-dark' ),
		'muted'            => array( 'neutral', 'solid' ),
		'mutedOutline'     => array( 'neutral', 'outline' ),
		'info'             => array( 'info', 'solid' ),
		'infoOutline'      => array( 'info', 'outline' ),
		'success'          => array( 'success', 'solid' ),
		'successOutline'   => array( 'success', 'outline' ),
		'warning'          => array( 'warning', 'solid' ),
		'warningOutline'   => array( 'warning', 'outline' ),
		'danger'           => array( 'danger', 'solid' ),
		'dangerOutline'    => array( 'danger', 'outline' ),
	);

	/** Button sizes => TokenForge text size. */
	const BUTTON_SIZES = array( 'Sm' => 's', 'Md' => 'm', 'Lg' => 'l', 'Xl' => 'xl' );

	/** Button typography fields => TokenForge button setting (the field holds the matching variable). */
	const BUTTON_TYPE = array(
		'font-weight'    => 'btn_font_weight',
		'line-height'    => 'btn_line_height',
		'letter-spacing' => 'btn_letter_spacing',
		'text-transform' => 'btn_text_transform',
	);

	/** @var bool Our own write in progress. */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'tokenforge_tokens_saved', array( __CLASS__, 'push' ), 30 );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'on_saved' ), 20, 2 );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'on_added' ), 20, 2 );
	}

	/**
	 * Is the sync on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = Settings::get_instance()->get_settings();
		return ! empty( $s['bricks_theme_sync'] ) && Bricks::is_active();
	}

	/**
	 * Sync state.
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array_merge( array( 'style' => '', 'pushed' => array(), 'prev' => array() ), $s );
	}

	/**
	 * The linked style id, when it still exists.
	 *
	 * @param array|null $all Theme styles.
	 * @return string
	 */
	public static function style_id( $all = null ) {
		$all = null === $all ? get_option( self::OPTION, array() ) : $all;
		$id  = self::state()['style'];
		return ( '' !== $id && is_array( $all ) && isset( $all[ $id ] ) ) ? $id : '';
	}

	/**
	 * Does a style apply to the entire website?
	 *
	 * @param array $style Style.
	 * @return bool
	 */
	private static function is_sitewide( $style ) {
		$conds = isset( $style['settings']['conditions']['conditions'] ) ? $style['settings']['conditions']['conditions'] : array();
		foreach ( (array) $conds as $c ) {
			if ( is_array( $c ) && isset( $c['main'] ) && 'any' === $c['main'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Link TokenForge to a theme style: the one already applied to the entire
	 * website if there is exactly one, else a new style named after the site.
	 * A new style also gets the recommended element defaults.
	 *
	 * @return array [ 'id' => string, 'created' => bool ]
	 */
	public static function setup() {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = self::style_id( $all );
		if ( '' !== $id ) {
			self::push();
			return array( 'id' => $id, 'created' => false );
		}
		$sitewide = array_keys( array_filter( $all, array( __CLASS__, 'is_sitewide' ) ) );
		$created  = false;
		if ( 1 === count( $sitewide ) ) {
			$id = $sitewide[0];
		} else {
			$label = trim( wp_strip_all_tags( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );
			$label = '' === $label ? 'TokenForge' : $label;
			$base  = sanitize_key( str_replace( ' ', '', strtolower( $label ) ) );
			$base  = '' === $base ? 'tokenforge' : $base;
			$id    = $base;
			for ( $i = 2; isset( $all[ $id ] ); $i++ ) {
				$id = $base . $i;
			}
			$all[ $id ] = array(
				'label'    => $label,
				'settings' => array(
					'_custom'    => true,
					'conditions' => array( 'conditions' => array( array( 'id' => substr( md5( $id ), 0, 6 ), 'main' => 'any' ) ) ),
					// Element defaults that suit a token-based setup.
					'heading'    => array( 'tag' => 'h2' ),
					'text-basic' => array( 'tag' => 'p' ),
					'image'      => array( 'caption' => 'none' ),
					'code'       => array( 'prettify' => 'tomorrow-night' ),
				),
			);
			$created = true;
			self::write( $all );
		}
		$state           = self::state();
		$state['style']  = $id;
		$state['pushed'] = array();
		$state['prev']   = array();
		update_option( self::STATE, $state, false );
		// An existing style is what the site shows today: TokenForge takes over its
		// typography, background and link colours first (colours stay TokenForge's),
		// then the fields point at TokenForge.
		$changes = array();
		if ( ! $created ) {
			$settings = isset( $all[ $id ]['settings'] ) && is_array( $all[ $id ]['settings'] ) ? $all[ $id ]['settings'] : array();
			foreach ( array_keys( self::desired() ) as $path ) {
				$v = self::get( $settings, $path );
				if ( null === $v || 0 === strpos( $path, 'colors.' ) || ( is_string( $v ) && 0 === strpos( $v, 'var(' ) ) ) {
					continue;
				}
				foreach ( self::pull( $path, $v, $settings ) as $mod => $vals ) {
					$changes[ $mod ] = array_replace_recursive( isset( $changes[ $mod ] ) ? $changes[ $mod ] : array(), $vals );
				}
			}
		}
		if ( $changes ) {
			self::apply( $changes ); // saves, rebuilds, pushes
		} else {
			self::push();
		}
		return array( 'id' => $id, 'created' => $created, 'adopted' => array_keys( $changes ) );
	}

	/**
	 * Write the theme styles (Bricks rebuilds its theme style CSS file on save).
	 *
	 * @param array $all Theme styles.
	 * @return void
	 */
	private static function write( $all ) {
		self::$busy = true;
		update_option( self::OPTION, $all );
		self::$busy = false;
	}

	// ---- Mapping ---------------------------------------------------------------

	/**
	 * "Manrope, sans-serif" → [ 'Manrope', 'sans-serif' ].
	 *
	 * @param string $stack Font stack.
	 * @return array
	 */
	private static function split_stack( $stack ) {
		$parts = array_map( 'trim', explode( ',', (string) $stack ) );
		$first = trim( (string) array_shift( $parts ), " \"'" );
		return array( $first, implode( ', ', array_filter( $parts, 'strlen' ) ) );
	}

	/**
	 * A Bricks font value as a family name (custom_font_12 → the font's title).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function font_name( $v ) {
		$v = is_string( $v ) ? trim( $v ) : '';
		if ( 0 === strpos( $v, 'custom_font_' ) ) {
			$title = get_the_title( (int) substr( $v, 12 ) );
			return is_string( $title ) ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : '';
		}
		return $v;
	}

	/**
	 * A Bricks colour value as CSS ({raw} / {hex} / {rgb}), or ''.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function color_css( $v ) {
		if ( ! is_array( $v ) ) {
			return '';
		}
		foreach ( array( 'raw', 'hex', 'rgb', 'hsl' ) as $k ) {
			if ( ! empty( $v[ $k ] ) && is_string( $v[ $k ] ) ) {
				return trim( $v[ $k ] );
			}
		}
		return '';
	}

	/**
	 * What TokenForge wants in the theme style: path => value (null = leave the
	 * field empty).
	 *
	 * @return array
	 */
	public static function desired() {
		$c   = Components::settings();
		$d   = Defaults::settings();
		$out = array();
		$out['typography.typographyHtml'] = ! empty( $d['output_root_font_size'] ) ? 'var(--root-font-size)' : null;
		$out['container.width']           = 'var(--content-width)';
		foreach ( array( 'Body' => 'text', 'Headings' => 'heading' ) as $group => $pre ) {
			$base                 = "typography.typography$group.";
			list( $fam, $fall )   = self::split_stack( $c[ $pre . '_font_family' ] );
			$out[ $base . 'font-family' ] = '' !== $fam ? $fam : null;
			$out[ $base . 'fallback' ]    = '' !== $fam && '' !== $fall ? $fall : null;
			foreach ( self::TYPE_FIELDS as $prop => $suffix ) {
				$v                    = isset( $c[ $pre . '_' . $suffix ] ) ? trim( (string) $c[ $pre . '_' . $suffix ] ) : '';
				$out[ $base . $prop ] = '' !== $v ? $v : null;
			}
			$out[ $base . 'color' ] = '' !== trim( (string) $c[ $pre . '_color' ] ) ? array( 'raw' => 'var(--' . $pre . '-color)' ) : null;
		}
		$out['typography.typographyBody.font-size'] = 'var(--text-m)';
		for ( $i = 1; $i <= 6; $i++ ) {
			$out[ "typography.typographyHeadingH$i.font-size" ] = "var(--h$i)";
		}
		$links                                    = ! empty( $c['base_links'] );
		$out['links.typography.color']            = $links ? array( 'raw' => 'var(--link-color)' ) : null;
		$out['links.typography:hover.color']      = $links ? array( 'raw' => 'var(--link-color-hover)' ) : null;
		$out['general.siteBackground.color']      = '' !== trim( (string) $c['body_bg'] ) ? array( 'raw' => 'var(--body-bg-color)' ) : null;
		$out['typography.focusOutline']           = ! empty( $d['focus_styles'] ) ? 'var(--focus-width) solid var(--focus-color)' : null;
		$pad                                      = ! empty( $c['base_layout'] ) && ! empty( $c['section_padding'] );
		$out['section.padding.top']               = $pad ? 'var(--section-padding-block)' : null;
		$out['section.padding.bottom']            = $pad ? 'var(--section-padding-block)' : null;
		$fams = Palette::settings()['families'];
		foreach ( self::COLORS as $field => $fam ) {
			$out[ "colors.$field" ] = ! empty( $fams[ $fam ]['on'] ) ? array( 'raw' => "var(--$fam)" ) : null;
		}
		return array_merge( $out, self::desired_buttons( $c, $fams ), self::desired_forms( $c, $fams ), self::desired_shop( $c, $fams ) );
	}

	/**
	 * A theme style field key: prefix + part ("primary" + "Background"), or the part alone.
	 *
	 * @param string $prefix Style prefix ('' for the plain button).
	 * @param string $part   "Background", "Typography" or "Border".
	 * @return string
	 */
	private static function key( $prefix, $part ) {
		return '' === $prefix ? lcfirst( $part ) : $prefix . $part;
	}

	/**
	 * Four sides with one value.
	 *
	 * @param string $v Value.
	 * @return array
	 */
	private static function sides( $v ) {
		return array( 'top' => $v, 'right' => $v, 'bottom' => $v, 'left' => $v );
	}

	/**
	 * Button element: every style (colours, hover colours) and size, the shared
	 * typography, border and padding, all pointing at the TokenForge button variables.
	 *
	 * @param array $c    Components settings.
	 * @param array $fams Colour families.
	 * @return array
	 */
	private static function desired_buttons( $c, $fams ) {
		$out = array();
		$on  = ! empty( $c['base_buttons'] );
		foreach ( self::BUTTONS as $prefix => $fv ) {
			$map = $on && ! empty( $fams[ $fv[0] ]['on'] ) ? Components::btn_var_map( $c, $fv[0], $fv[1] ) : array();
			// The TokenForge button variable first: a btn--* class on the button still decides.
			$get = function ( $k ) use ( $map ) {
				return isset( $map[ $k ] ) ? array( 'raw' => 'var(--' . $k . ', ' . $map[ $k ] . ')' ) : null;
			};
			$bg = self::key( $prefix, 'Background' );
			$ty = self::key( $prefix, 'Typography' );
			$bo = self::key( $prefix, 'Border' );
			$out[ "button.$bg" ]             = $get( 'btn-background' );
			$out[ "button.$bg:hover" ]       = $get( 'btn-background-hover' );
			$out[ "button.$ty.color" ]       = $get( 'btn-text-color' );
			$out[ "button.$ty:hover.color" ] = $get( 'btn-text-color-hover' );
			$out[ "button.$bo.color" ]       = $get( 'btn-border-color' );
			$out[ "button.$bo:hover.color" ] = $get( 'btn-border-color-hover' );
		}
		foreach ( self::BUTTON_TYPE as $prop => $key ) {
			$out[ "button.typography.$prop" ] = $on && '' !== trim( (string) $c[ $key ] ) ? 'var(--' . str_replace( '_', '-', $key ) . ')' : null;
		}
		$out['button.border.width']        = $on ? self::sides( 'var(--btn-border-width)' ) : null;
		$out['button.border.style']        = $on ? 'var(--btn-border-style, solid)' : null;
		$out['button.border.radius']       = $on && '' !== trim( (string) $c['btn_radius'] ) ? self::sides( 'var(--btn-border-radius)' ) : null;
		$pad                               = $on ? array( 'top' => 'var(--btn-padding-block)', 'right' => 'var(--btn-padding-inline)', 'bottom' => 'var(--btn-padding-block)', 'left' => 'var(--btn-padding-inline)' ) : null;
		$out['button.sizeDefaultPadding']  = $pad;
		foreach ( self::BUTTON_SIZES as $size => $text ) {
			$out[ "button.size{$size}Padding" ]              = $pad;
			$out[ "button.size{$size}Typography.font-size" ] = $on ? "var(--text-$text)" : null;
		}
		return $out;
	}

	/**
	 * Form element: fields, labels, the space between fields and the submit button.
	 *
	 * @param array $c    Components settings.
	 * @param array $fams Colour families.
	 * @return array
	 */
	private static function desired_forms( $c, $fams ) {
		$on  = ! empty( $c['base_forms'] );
		$pad = preg_split( '/\s+/', trim( (string) $c['field_padding'] ) );
		$pv  = isset( $pad[0] ) && '' !== $pad[0] ? $pad[0] : '0.6em';
		$ph  = isset( $pad[1] ) ? $pad[1] : $pv;
		$out = array(
			'form.fieldBackgroundColor'     => $on ? array( 'raw' => 'var(--field-bg, #fff)' ) : null,
			'form.fieldTypography.color'    => $on ? array( 'raw' => 'var(--field-color, var(--base, #1a1a1a))' ) : null,
			'form.fieldTypography.font-size' => $on ? 'var(--text-m)' : null,
			'form.fieldBorder'              => $on ? array(
				'width'  => self::sides( '1px' ),
				'style'  => 'solid',
				'color'  => array( 'raw' => (string) $c['field_border'] ),
				'radius' => self::sides( (string) $c['field_radius'] ),
			) : null,
			'form.fieldPadding'             => $on ? array( 'top' => $pv, 'right' => $ph, 'bottom' => $pv, 'left' => $ph ) : null,
			'form.fieldMargin'              => $on ? array( 'bottom' => (string) $c['form_gap'] ) : null,
			'form.labelTypography.font-size' => $on ? 'var(--text-s)' : null,
			'form.labelTypography.text-transform' => $on ? 'none' : null,
			'form.labelTypography.letter-spacing' => $on ? 'normal' : null,
		);
		$btn = $on && ! empty( $c['base_buttons'] ) && ! empty( $fams[ $c['form_btn_family'] ]['on'] ) ? Components::btn_var_map( $c, $c['form_btn_family'], $c['form_btn_variant'] ) : array();
		$out['form.submitButtonBackgroundColor'] = $btn ? array( 'raw' => 'var(--btn-background, ' . $btn['btn-background'] . ')' ) : null;
		$out['form.submitButtonTypography.color'] = $btn ? array( 'raw' => 'var(--btn-text-color, ' . $btn['btn-text-color'] . ')' ) : null;
		$out['form.submitButtonBorder']          = $btn ? array(
			'width'  => self::sides( 'var(--btn-border-width)' ),
			'style'  => 'solid',
			'color'  => array( 'raw' => $btn['btn-border-color'] ),
			'radius' => self::sides( 'var(--btn-border-radius)' ),
		) : null;
		$out['form.submitButtonPadding']         = $btn ? array( 'top' => 'var(--btn-padding-block)', 'right' => 'var(--btn-padding-inline)', 'bottom' => 'var(--btn-padding-block)', 'left' => 'var(--btn-padding-inline)' ) : null;
		return $out;
	}

	/**
	 * The shop groups (WooCommerce button, WooCommerce notices) from the
	 * WooCommerce settings. One way: change them in TokenForge.
	 *
	 * @param array $c    Components settings.
	 * @param array $fams Colour families.
	 * @return array
	 */
	private static function desired_shop( $c, $fams ) {
		$w   = Woo::active() ? Woo::settings() : array();
		$on  = ! empty( $w['enabled'] );
		$btn = $on && ! empty( $w['buttons'] ) && ! empty( $fams[ $w['btn_family'] ]['on'] ) ? Components::btn_var_map( $c, $w['btn_family'], $w['btn_variant'] ) : array();
		$out = array(
			'woocommerce-button.background'       => $btn ? array( 'raw' => $btn['btn-background'] ) : null,
			'woocommerce-button.typography.color' => $btn ? array( 'raw' => $btn['btn-text-color'] ) : null,
			'woocommerce-button.border'           => $btn ? array(
				'width'  => self::sides( 'var(--btn-border-width)' ),
				'style'  => 'solid',
				'color'  => array( 'raw' => $btn['btn-border-color'] ),
				'radius' => self::sides( 'var(--btn-border-radius)' ),
			) : null,
			'woocommerce-button.padding'          => $btn ? array( 'top' => 'var(--btn-padding-block)', 'right' => 'var(--btn-padding-inline)', 'bottom' => 'var(--btn-padding-block)', 'left' => 'var(--btn-padding-inline)' ) : null,
		);
		$notices = $on && ! empty( $w['notices'] );
		foreach ( array( 'success' => 'success', 'error' => 'danger', 'notice' => 'info' ) as $type => $fam ) {
			$out[ "woocommerce-notice.{$type}BackgroundColor" ] = $notices ? array( 'raw' => "var(--$fam-ultra-light)" ) : null;
			$out[ "woocommerce-notice.{$type}Typography.color" ] = $notices ? array( 'raw' => "var(--$fam-ultra-dark, var(--text-dark))" ) : null;
			$out[ "woocommerce-notice.{$type}Border" ]          = $notices ? array(
				'width'  => array( 'left' => '4px' ),
				'style'  => 'solid',
				'color'  => array( 'raw' => "var(--$fam)" ),
				'radius' => self::sides( 'var(--woo-notice-radius)' ),
			) : null;
			$out[ "woocommerce-notice.{$type}Padding" ]         = $notices ? self::sides( 'var(--space-s, 15px)' ) : null;
		}
		return $out;
	}

	/**
	 * Turn a theme style value into TokenForge settings: path, new value, the
	 * whole style settings → [ module => [ key => value ] ] (or array() when the
	 * field has no TokenForge counterpart).
	 *
	 * @param string $path     Field path.
	 * @param mixed  $v        New value (null = cleared).
	 * @param array  $settings Whole style settings.
	 * @return array
	 */
	public static function pull( $path, $v, $settings ) {
		if ( 'typography.typographyHtml' === $path ) {
			if ( is_string( $v ) && preg_match( '/^([\d.]+)%$/', trim( $v ), $m ) ) {
				return array( 'defaults' => array( 'root_font_size' => (float) $m[1], 'output_root_font_size' => true ) );
			}
			return array();
		}
		if ( 'container.width' === $path ) {
			if ( is_numeric( $v ) || ( is_string( $v ) && preg_match( '/^([\d.]+)(px|rem)?$/', trim( $v ), $m ) ) ) {
				$n  = is_numeric( $v ) ? (float) $v : (float) $m[1];
				$px = ( isset( $m[2] ) && 'rem' === $m[2] ) ? $n * 16 : $n;
				return $px > 0 ? array( 'width' => array( 'content_width' => round( $px ) ) ) : array();
			}
			return array();
		}
		if ( preg_match( '/^typography\.typography(Body|Headings)\.(.+)$/', $path, $m ) ) {
			$pre  = 'Body' === $m[1] ? 'text' : 'heading';
			$prop = $m[2];
			if ( 'font-family' === $prop || 'fallback' === $prop ) {
				$base = "typography.typography{$m[1]}.";
				$fam  = self::font_name( self::get( $settings, $base . 'font-family' ) );
				$fall = self::get( $settings, $base . 'fallback' );
				$fall = is_string( $fall ) ? trim( $fall ) : '';
				if ( '' === $fall ) {
					// No fallback in Bricks: keep the one TokenForge had.
					$now  = Modules::raw( array( Components::class, 'settings' ) );
					$fall = self::split_stack( $now[ $pre . '_font_family' ] )[1];
				}
				return array( 'components' => array( $pre . '_font_family' => '' === $fam ? '' : $fam . ( '' !== $fall ? ', ' . $fall : '' ) ) );
			}
			if ( 'color' === $prop ) {
				return array( 'components' => array( $pre . '_color' => self::color_css( $v ) ) );
			}
			if ( isset( self::TYPE_FIELDS[ $prop ] ) ) {
				return array( 'components' => array( $pre . '_' . self::TYPE_FIELDS[ $prop ] => is_scalar( $v ) ? trim( (string) $v ) : '' ) );
			}
			return array();
		}
		if ( 'links.typography.color' === $path ) {
			$css = self::color_css( $v );
			return '' !== $css ? array( 'components' => array( 'link_color' => $css ) ) : array();
		}
		if ( 'links.typography:hover.color' === $path ) {
			$css = self::color_css( $v );
			return '' !== $css ? array( 'components' => array( 'link_color_hover' => $css ) ) : array();
		}
		if ( 'general.siteBackground.color' === $path ) {
			return array( 'components' => array( 'body_bg' => self::color_css( $v ) ) );
		}
		if ( preg_match( '/^colors\.(color[A-Za-z]+)$/', $path, $m ) && isset( self::COLORS[ $m[1] ] ) ) {
			$css = self::color_css( $v );
			// A plain colour becomes the family's new base colour (shades regenerate).
			if ( preg_match( '/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $css ) ) {
				return array( 'palette' => array( self::COLORS[ $m[1] ] => $css ) );
			}
			return array();
		}
		// Button colours: the colour of that button style (TokenForge button overrides).
		if ( preg_match( '/^button\.([a-zA-Z]*?)(Background|Typography|Border|background|typography|border)(:hover)?(\.color)?$/', $path, $m ) ) {
			$part  = ucfirst( $m[2] );
			$color = isset( $m[4] ) && '.color' === $m[4];
			if ( ! isset( self::BUTTONS[ $m[1] ] ) || ( 'Background' === $part ) === $color ) {
				return array();
			}
			$prop = array( 'Background' => 'bg', 'Typography' => 'text', 'Border' => 'border' )[ $part ];
			if ( ! empty( $m[3] ) ) {
				$prop = 'bg' === $prop ? 'hover' : 'hover_' . $prop;
			}
			list( $fam, $variant ) = self::BUTTONS[ $m[1] ];
			return array( 'components' => array( 'btn_overrides' => array( $fam => array( $variant => array( $prop => self::color_css( $v ) ) ) ) ) );
		}
		if ( preg_match( '/^button\.typography\.(.+)$/', $path, $m ) && isset( self::BUTTON_TYPE[ $m[1] ] ) ) {
			return is_scalar( $v ) && '' !== trim( (string) $v ) ? array( 'components' => array( self::BUTTON_TYPE[ $m[1] ] => trim( (string) $v ) ) ) : array();
		}
		if ( 'button.sizeDefaultPadding' === $path ) {
			$out = array();
			if ( is_array( $v ) && ! empty( $v['top'] ) ) {
				$out['btn_padding_block'] = (string) $v['top'];
			}
			if ( is_array( $v ) && ! empty( $v['right'] ) ) {
				$out['btn_padding_inline'] = (string) $v['right'];
			}
			return $out ? array( 'components' => $out ) : array();
		}
		if ( 'button.border.radius' === $path || 'button.border.width' === $path ) {
			$side = is_array( $v ) && isset( $v['top'] ) ? trim( (string) $v['top'] ) : '';
			return '' !== $side ? array( 'components' => array( 'button.border.radius' === $path ? 'btn_radius' : 'btn_border_width' => $side ) ) : array();
		}
		// Form fields.
		if ( 'form.fieldBorder' === $path && is_array( $v ) ) {
			$out = array();
			$col = self::color_css( isset( $v['color'] ) ? $v['color'] : null );
			if ( '' !== $col ) {
				$out['field_border'] = $col;
			}
			if ( isset( $v['radius']['top'] ) && '' !== trim( (string) $v['radius']['top'] ) ) {
				$out['field_radius'] = trim( (string) $v['radius']['top'] );
			}
			return $out ? array( 'components' => $out ) : array();
		}
		if ( 'form.fieldPadding' === $path && is_array( $v ) && ! empty( $v['top'] ) ) {
			$h = ! empty( $v['right'] ) ? (string) $v['right'] : (string) $v['top'];
			return array( 'components' => array( 'field_padding' => trim( $v['top'] . ' ' . $h ) ) );
		}
		if ( 'form.fieldMargin' === $path && is_array( $v ) && isset( $v['bottom'] ) && '' !== trim( (string) $v['bottom'] ) ) {
			return array( 'components' => array( 'form_gap' => trim( (string) $v['bottom'] ) ) );
		}
		return array();
	}

	/**
	 * Does a value (or anything inside it) point at a CSS variable?
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function is_var( $v ) {
		if ( is_string( $v ) ) {
			return 0 === strpos( trim( $v ), 'var(' );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( self::is_var( $x ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Value at a dotted path ("typography.typographyBody.font-family").
	 *
	 * @param array  $a    Array.
	 * @param string $path Path.
	 * @return mixed|null
	 */
	public static function get( $a, $path ) {
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $a ) || ! array_key_exists( $k, $a ) ) {
				return null;
			}
			$a = $a[ $k ];
		}
		return $a;
	}

	/**
	 * Set (or with null: remove) the value at a dotted path; empty parents are
	 * removed too.
	 *
	 * @param array  $a    Array.
	 * @param string $path Path.
	 * @param mixed  $v    Value.
	 * @return array
	 */
	public static function set( $a, $path, $v ) {
		$keys = explode( '.', $path );
		$k    = array_shift( $keys );
		if ( ! $keys ) {
			if ( null === $v ) {
				unset( $a[ $k ] );
			} else {
				$a[ $k ] = $v;
			}
			return $a;
		}
		$child = isset( $a[ $k ] ) && is_array( $a[ $k ] ) ? $a[ $k ] : array();
		$child = self::set( $child, implode( '.', $keys ), $v );
		if ( array() === $child ) {
			unset( $a[ $k ] );
		} else {
			$a[ $k ] = $child;
		}
		return $a;
	}

	/**
	 * Same value (arrays compared as JSON)?
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	// ---- Sync --------------------------------------------------------------------

	/**
	 * TokenForge → theme style.
	 *
	 * @return array|null The style's new settings (for the builder), or null.
	 */
	public static function push() {
		if ( self::$busy || ! self::enabled() ) {
			return null;
		}
		$all = get_option( self::OPTION, array() );
		$id  = self::style_id( $all );
		if ( '' === $id ) {
			return null;
		}
		$settings = isset( $all[ $id ]['settings'] ) && is_array( $all[ $id ]['settings'] ) ? $all[ $id ]['settings'] : array();
		$state    = self::state();
		$pushed   = array();
		$changed  = false;
		$desired  = self::desired();
		// A field TokenForge starts to look after keeps what the site shows: its
		// current value goes into TokenForge first (colours stay TokenForge's).
		$adopt = array();
		foreach ( $desired as $path => $v ) {
			if ( null === $v || 0 === strpos( $path, 'colors.' ) || array_key_exists( $path, $state['pushed'] ) || array_key_exists( $path, $state['prev'] ) ) {
				continue;
			}
			$cur = self::get( $settings, $path );
			if ( null === $cur || self::same( $cur, $v ) || self::is_var( $cur ) ) {
				continue;
			}
			foreach ( self::pull( $path, $cur, $settings ) as $mod => $vals ) {
				$adopt[ $mod ] = array_replace_recursive( isset( $adopt[ $mod ] ) ? $adopt[ $mod ] : array(), $vals );
			}
		}
		if ( $adopt ) {
			self::store( $adopt );
			Css_Generator::get_instance()->generate();
			$desired = self::desired();
		}
		foreach ( $desired as $path => $v ) {
			$cur = self::get( $settings, $path );
			// A Bricks custom font with the same family name stays as picked.
			if ( is_string( $v ) && preg_match( '/\.font-family$/', $path ) && strtolower( self::font_name( $cur ) ) === strtolower( $v ) ) {
				$v = $cur;
			}
			if ( null === $v ) {
				// A plain value (font, weight …) TokenForge has no opinion on any more:
				// the theme style keeps it (TokenForge just stops owning the field).
				if ( preg_match( '/\.(font-family|fallback|font-weight|font-style|line-height|letter-spacing|text-transform)$/', $path ) ) {
					continue;
				}
				// A TokenForge variable that no longer applies: clear it, but only
				// where TokenForge itself wrote it.
				if ( null !== $cur && isset( $state['pushed'][ $path ] ) && self::same( $cur, $state['pushed'][ $path ] ) ) {
					$state['prev'][ $path ] = $cur;
					$settings               = self::set( $settings, $path, null );
					$changed                = true;
				}
				continue;
			}
			if ( ! self::same( $cur, $v ) ) {
				$state['prev'][ $path ] = $cur;
				$settings               = self::set( $settings, $path, $v );
				$changed                = true;
			}
			$pushed[ $path ] = $v;
		}
		$state['pushed'] = $pushed;
		update_option( self::STATE, $state, false );
		if ( $changed ) {
			$all[ $id ]['settings'] = $settings;
			self::write( $all );
		}
		return $settings;
	}

	/**
	 * Theme styles created for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public static function on_added( $option, $value ) {
		self::on_saved( array(), $value );
	}

	/**
	 * Theme style → TokenForge (the builder or Bricks saved the theme styles).
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 * @return void
	 */
	public static function on_saved( $old, $new ) {
		if ( self::$busy || ! self::enabled() || ! is_array( $new ) ) {
			return;
		}
		$id = self::style_id( $new );
		if ( '' === $id ) {
			return;
		}
		$settings = isset( $new[ $id ]['settings'] ) && is_array( $new[ $id ]['settings'] ) ? $new[ $id ]['settings'] : array();
		$state    = self::state();
		$changes  = array();
		$stale    = false;
		foreach ( array_keys( self::desired() ) as $path ) {
			$v      = self::get( $settings, $path );
			$pushed = array_key_exists( $path, $state['pushed'] ) ? $state['pushed'][ $path ] : null;
			if ( self::same( $v, $pushed ) ) {
				continue;
			}
			// The builder saved the copy it had before our last push: not an edit.
			if ( array_key_exists( $path, $state['prev'] ) && self::same( $v, $state['prev'][ $path ] ) ) {
				$stale = true;
				continue;
			}
			foreach ( self::pull( $path, $v, $settings ) as $mod => $vals ) {
				$changes[ $mod ] = array_replace_recursive( isset( $changes[ $mod ] ) ? $changes[ $mod ] : array(), $vals );
			}
		}
		if ( $changes ) {
			self::apply( $changes );
			return; // apply() saves, rebuilds and pushes back (fields point at TokenForge again)
		}
		if ( $stale ) {
			self::push();
		}
	}

	/**
	 * Store changes that came from the theme style.
	 *
	 * @param array $changes [ module => [ key => value ] ].
	 * @return void
	 */
	private static function apply( $changes ) {
		self::store( $changes );
		Css_Generator::get_instance()->rebuild();
	}

	/**
	 * Save changes into the TokenForge settings (no rebuild).
	 *
	 * @param array $changes [ module => [ key => value ] ].
	 * @return void
	 */
	private static function store( $changes ) {
		$classes = array(
			'components' => Components::class,
			'defaults'   => Defaults::class,
			'width'      => Width::class,
		);
		foreach ( $changes as $mod => $vals ) {
			if ( 'palette' === $mod ) {
				$p = Palette::settings();
				foreach ( $vals as $fam => $hex ) {
					if ( isset( $p['families'][ $fam ] ) ) {
						$p['families'][ $fam ]['hex'] = $hex;
					}
				}
				Palette::save_settings( $p );
				continue;
			}
			if ( isset( $classes[ $mod ] ) ) {
				$class = $classes[ $mod ];
				$class::save_settings( array_replace_recursive( Modules::raw( array( $class, 'settings' ) ), $vals ) );
			}
		}
	}
}
