<?php
/**
 * Import Automatic.css *settings* (not just its variables).
 *
 * Reads the `automatic_css_settings` option an installed (or previously
 * installed) Automatic.css left behind and maps every setting TokenForge has an
 * equivalent for onto TokenForge's own settings: root font size, viewport +
 * fluid type/heading/space scales, section spacing, content width, gutters,
 * layout, typography defaults, links, buttons, forms, cards, radius, borders,
 * shadows, icons, header height, focus, selection, colour scheme, smart
 * spacing, cascade layers, Gutenberg palette and builder options.
 *
 * Original implementation, only the setting *names* follow ACSS so an
 * existing configuration can be carried over.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * ACSS settings → TokenForge settings.
 */
class Acss_Import {

	/** @var array ACSS settings. */
	private $a = array();

	/** @var array Report: module => [ "setting ← acss-key = value" ]. */
	private $report = array();

	/**
	 * Run the import.
	 *
	 * @return array [ 'ok' => bool, 'mapped' => int, 'report' => array, 'error' => string ]
	 */
	public static function run() {
		$raw = get_option( 'automatic_css_settings' );
		if ( ! is_array( $raw ) || ! $raw ) {
			return array( 'ok' => false, 'mapped' => 0, 'report' => array(), 'error' => 'No settings to import were found on this site.' );
		}
		$self    = new self();
		$self->a = $raw;
		$self->defaults();
		$self->scales();
		$self->width();
		$self->components();
		$self->layout();
		$self->typography();
		$self->buttons();
		$self->cards();
		$self->extras();
		$self->icons_borders();
		$self->scheme();
		$self->content();
		$self->motion();
		$self->overlays();
		$self->plugin_settings();
		$self->scss();
		$self->palette();
		$self->fonts();
		if ( Platforms\Bricks::is_active() ) {
			$self->builder();
		}
		$n = 0;
		foreach ( $self->report as $rows ) {
			$n += count( $rows );
		}
		return array( 'ok' => true, 'mapped' => $n, 'report' => $self->report, 'error' => '' );
	}

	// ------------------------------------------------------------------
	// Value helpers
	// ------------------------------------------------------------------

	/**
	 * Raw ACSS value ('' when missing).
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private function raw( $k ) {
		return ( isset( $this->a[ $k ] ) && is_scalar( $this->a[ $k ] ) ) ? trim( (string) $this->a[ $k ] ) : '';
	}

	/**
	 * CSS value: "--token" becomes var(--token); unsafe characters dropped.
	 *
	 * @param string $k Key.
	 * @return string|null Null when unset.
	 */
	private function css( $k ) {
		$v = $this->raw( $k );
		if ( '' === $v ) {
			return null;
		}
		if ( preg_match( '/^--[a-z0-9\-_]+$/i', $v ) ) {
			$v = 'var(' . $v . ')';
		}
		return trim( str_replace( array( ';', '{', '}', '<', '>' ), '', $v ) );
	}

	/**
	 * Number (px values may be "30" or "30px").
	 *
	 * @param string $k Key.
	 * @return float|null
	 */
	private function num( $k ) {
		$v = $this->raw( $k );
		return preg_match( '/^-?[0-9]*\.?[0-9]+(px|%)?$/i', $v ) ? (float) $v : null;
	}

	/**
	 * Toggle ('on' / 'off').
	 *
	 * @param string $k Key.
	 * @return bool|null
	 */
	private function on( $k ) {
		$v = strtolower( $this->raw( $k ) );
		if ( 'on' === $v || 'true' === $v || '1' === $v ) {
			return true;
		}
		if ( 'off' === $v || 'false' === $v || '0' === $v ) {
			return false;
		}
		return null;
	}

	/**
	 * Selector value: like css() but keeps ">" (child combinator).
	 *
	 * @param string $k Key.
	 * @return string|null
	 */
	private function selector( $k ) {
		$v = $this->raw( $k );
		// ACSS stores lists as "sel", "sel", drop the wrapping double quotes.
		$v = preg_replace( '/"([^"]*)"/', '$1', $v );
		return '' === $v ? null : trim( str_replace( array( ';', '{', '}', '<' ), '', $v ) );
	}

	/**
	 * A length that may be stored as a bare number (px).
	 *
	 * @param string $k Key.
	 * @return string|null
	 */
	private function px( $k ) {
		$v = $this->css( $k );
		return ( null !== $v && is_numeric( $v ) ) ? $v . 'px' : $v;
	}

	/**
	 * Several toggles as one: true if any is on, false if all present are off.
	 *
	 * @param string[] $keys Keys.
	 * @return bool|null
	 */
	private function any_on( $keys ) {
		$vals = array_map( array( $this, 'on' ), $keys );
		return in_array( true, $vals, true ) ? true : ( in_array( false, $vals, true ) ? false : null );
	}

	/**
	 * Apply a map of target => value onto $settings, recording the changes.
	 *
	 * @param string $module   Report label.
	 * @param array  $settings Settings (by reference).
	 * @param array  $map      target => [ acss_key, value|null ].
	 * @return void
	 */
	private function apply( $module, &$settings, $map ) {
		foreach ( $map as $target => $pair ) {
			list( $key, $value ) = $pair;
			if ( null === $value ) {
				continue;
			}
			$settings[ $target ]        = $value;
			$shown                      = is_bool( $value ) ? ( $value ? 'on' : 'off' ) : ( is_array( $value ) ? count( $value ) . ' item(s)' : $value );
			$this->report[ $module ][] = $target . ' ← ' . $key . ' = ' . $shown;
		}
	}

	// ------------------------------------------------------------------
	// Modules
	// ------------------------------------------------------------------

	/** Root font size, defaults, focus, selection. */
	private function defaults() {
		$s   = Defaults::settings();
		$pct = $this->num( 'root-font-size' );
		$this->apply(
			'Defaults',
			$s,
			array(
				'root_font_size'        => array( 'root-font-size', ( null !== $pct && $pct >= 50 && $pct <= 200 ) ? $pct : null ),
				'output_root_font_size' => array( 'root-font-size', null !== $pct ? true : null ),
				'reset'                 => array( 'option-load-reset', $this->on( 'option-load-reset' ) ),
				'smooth_scroll'         => array( 'option-smooth-scrolling', $this->on( 'option-smooth-scrolling' ) ),
				'reduce_motion'         => array( 'option-reduce-motion', $this->on( 'option-reduce-motion' ) ),
				'focus_styles'          => array( 'option-focus-styles', $this->on( 'option-focus-styles' ) ),
				'object_fit'            => array( 'option-auto-object-fit', $this->on( 'option-auto-object-fit' ) ),
				'is_bg'                 => array( 'option-is-bg', $this->on( 'option-is-bg' ) ),
				'sticky'                => array( 'option-position', $this->on( 'option-position' ) ),
				'sr_only'               => array( 'option-accessibility-classes', $this->on( 'option-accessibility-classes' ) ),
				'focus_color'           => array( 'focus-color', $this->css( 'focus-color' ) ),
				'focus_width'           => array( 'focus-width', $this->css( 'focus-width' ) ),
				'focus_offset'          => array( 'focus-offset', $this->css( 'focus-offset' ) ),
				'selection_bg'          => array( 'selection-bg', $this->css( 'selection-bg' ) ),
				'selection_text'        => array( 'selection-text', $this->css( 'selection-text' ) ),
				// ACSS's reset leaves images inline.
				'media_block'           => array( '(the imported reset keeps media inline)', null !== $this->on( 'option-load-reset' ) ? false : null ),
				'scroll_margin_min'     => array( 'scroll-margin-min', null !== $this->num( 'scroll-margin-min' ) ? $this->num( 'scroll-margin-min' ) . 'px' : null ),
				'scroll_margin_max'     => array( 'scroll-margin-max', null !== $this->num( 'scroll-margin-max' ) ? $this->num( 'scroll-margin-max' ) . 'px' : null ),
				'scroll_offset_header'  => array( 'option-use-header-height-for-scroll-offset', $this->on( 'option-use-header-height-for-scroll-offset' ) ),
				'sticky_offset_header'  => array( 'option-sticky-offset', $this->on( 'option-sticky-offset' ) ),
				'sticky_offset_custom'  => array( 'sticky-offset-custom', $this->css( 'sticky-offset-custom' ) ),
				'content_offset'        => array( 'option-offset-page-content', $this->on( 'option-offset-page-content' ) ),
				'content_offset_exclusions' => array( 'offset-content-page-exclusions', $this->selector( 'offset-content-page-exclusions' ) ),
				'content_offset_header_not' => array( 'offset-content-header-exclusion', $this->selector( 'offset-content-header-exclusion' ) ),
				'columns'               => array( 'option-column-variables', $this->on( 'option-column-variables' ) ),
				'col_width_s'           => array( 'col-width-s', $this->css( 'col-width-s' ) ),
				'col_width_m'           => array( 'col-width-m', $this->css( 'col-width-m' ) ),
				'col_width_l'           => array( 'col-width-l', $this->css( 'col-width-l' ) ),
				'col_rule_width_s'      => array( 'col-rule-width-s', $this->css( 'col-rule-width-s' ) ),
				'col_rule_width_m'      => array( 'col-rule-width-m', $this->css( 'col-rule-width-m' ) ),
				'col_rule_width_l'      => array( 'col-rule-width-l', $this->css( 'col-rule-width-l' ) ),
			)
		);
		Defaults::save_settings( $s );
	}

	/** Viewports + fluid scales + overrides. */
	private function scales() {
		$s  = Scale_Engine::settings();
		$ov = array();
		foreach ( $this->a as $k => $v ) {
			if ( is_scalar( $v ) && preg_match( '/^(h[1-6]|text-(?:xs|s|m|l|xl|xxl))-(min|max)$/', $k ) && is_numeric( trim( (string) $v ) ) ) {
				$ov[] = $k . '=' . (float) $v;
			}
		}
		$this->apply(
			'Scales',
			$s,
			array(
				'min_vw'            => array( 'vp-min', $this->num( 'vp-min' ) ),
				'max_vw'            => array( 'vp-max', $this->num( 'vp-max' ) ),
				'text_base_min'     => array( 'base-text-mob', $this->num( 'base-text-mob' ) ),
				'text_base_max'     => array( 'base-text-desk', $this->num( 'base-text-desk' ) ),
				'text_ratio_min'    => array( 'mob-text-scale', $this->num( 'mob-text-scale' ) ),
				'text_ratio_max'    => array( 'text-scale', $this->num( 'text-scale' ) ),
				'heading_base_min'  => array( 'base-heading-mob', $this->num( 'base-heading-mob' ) ),
				'heading_base_max'  => array( 'base-heading-desk', $this->num( 'base-heading-desk' ) ),
				'heading_ratio_min' => array( 'mob-heading-scale', $this->num( 'mob-heading-scale' ) ),
				'heading_ratio_max' => array( 'heading-scale', $this->num( 'heading-scale' ) ),
				'space_base_min'    => array( 'base-space-min', $this->num( 'base-space-min' ) ),
				'space_base_max'    => array( 'base-space', $this->num( 'base-space' ) ),
				'space_ratio_min'   => array( 'mob-space-scale', $this->num( 'mob-space-scale' ) ),
				'space_ratio'       => array( 'space-scale', $this->num( 'space-scale' ) ),
				'section_mult_min'  => array( 'mob-space-adjust-section', $this->num( 'mob-space-adjust-section' ) ),
				'section_mult_max'  => array( 'space-adjust-section', $this->num( 'space-adjust-section' ) ),
				'overrides'         => array( 'h*/text-*-min|max', $ov ? implode( "\n", $ov ) : null ),
				'auto_grid'         => array( 'option-auto-grid-variables', $this->on( 'option-auto-grid-variables' ) ),
				'auto_grid_aggressiveness' => array( 'auto-grid-aggressiveness', $this->num( 'auto-grid-aggressiveness' ) ),
				'auto_grid_flow'    => array( 'auto-grid-flow-option', in_array( $this->raw( 'auto-grid-flow-option' ), array( 'auto-fit', 'auto-fill' ), true ) ? $this->raw( 'auto-grid-flow-option' ) : null ),
			)
		);
		Scale_Engine::save_settings( $s );
	}

	/** Content width + width scale. */
	private function width() {
		$s = Width::settings();
		$this->apply(
			'Content width',
			$s,
			array(
				'content_width' => array( 'vp-max', $this->num( 'vp-max' ) ),
				'variables'     => array( 'option-width-variables', $this->on( 'option-width-variables' ) ),
				'utilities'     => array( 'option-width', $this->on( 'option-width' ) ),
			)
		);
		Width::save_settings( $s );
		// A stored content-width token (from a variables import) would override the setting.
		if ( Width::sync_stored_token( -1 ) ) {
			$this->report['Content width'][] = 'stored token content-width updated to ' . Width::content_width_value();
		}
	}

	/** Layout, typography, links, buttons, forms, cards. */
	private function components() {
		$s        = Components::settings();
		$bodydefs = array( $this->on( 'option-load-default-body-styles' ), $this->on( 'option-load-default-heading-styles' ), $this->on( 'option-load-default-text-styles' ) );
		$base     = in_array( true, $bodydefs, true ) ? true : ( in_array( false, $bodydefs, true ) ? false : null );
		$bpad     = $this->css( 'f-field-block-padding' );
		$ipad     = $this->css( 'f-field-inline-padding' );
		$px       = function ( $v ) {
			return null === $v ? null : ( is_numeric( $v ) ? $v . 'px' : $v );
		};
		$this->apply(
			'Base styles',
			$s,
			array(
				'gutter_min'             => array( 'gutter-min', $this->num( 'gutter-min' ) ),
				'gutter_max'             => array( 'gutter-max', $this->num( 'gutter-max' ) ),
				'layout_type'            => array( 'option-boxed-layout', null === $this->on( 'option-boxed-layout' ) ? null : ( $this->on( 'option-boxed-layout' ) ? 'boxed' : 'full' ) ),
				'boxed_width'            => array( 'body-max-width', Width::to_px( (string) $this->raw( 'body-max-width' ) ) ),
				'section_padding'        => array( 'option-section-padding', $this->on( 'option-section-padding' ) ),
				'section_padding_block'  => array( 'section-padding-block', $this->css( 'section-padding-block' ) ),
				'auto_container_gap'     => array( 'option-container-gap', $this->on( 'option-container-gap' ) ),
				'auto_content_gap'       => array( 'option-content-gap', $this->on( 'option-content-gap' ) ),
				'container_gap'          => array( 'contextual-container-gap', $this->css( 'contextual-container-gap' ) ),
				'content_gap'            => array( 'contextual-content-gap', $this->css( 'contextual-content-gap' ) ),
				'base_root'              => array( 'option-load-default-*-styles', $base ),
				'body_bg'                => array( 'body-bg-color', $this->css( 'body-bg-color' ) ),
				'text_color'             => array( 'body-color', $this->css( 'body-color' ) ),
				'text_font_family'       => array( 'text-font-family', $this->css( 'text-font-family' ) ),
				'text_font_weight'       => array( 'text-font-weight', $this->css( 'text-font-weight' ) ),
				'text_line_height'       => array( 'base-text-lh', $this->css( 'base-text-lh' ) ),
				'text_wrap'              => array( 'text-wrap', $this->css( 'text-wrap' ) ),
				'heading_color'          => array( 'heading-color', $this->css( 'heading-color' ) ),
				'heading_font_family'    => array( 'heading-font-family', $this->css( 'heading-font-family' ) ),
				'heading_font_weight'    => array( 'heading-weight', $this->css( 'heading-weight' ) ),
				'heading_line_height'    => array( 'base-heading-lh', $this->css( 'base-heading-lh' ) ),
				'heading_letter_spacing' => array( 'heading-letter-spacing', $this->css( 'heading-letter-spacing' ) ),
				'heading_text_wrap'      => array( 'heading-text-wrap', $this->css( 'heading-text-wrap' ) ),
				'base_links'             => array( 'option-load-default-link-styles', $this->on( 'option-load-default-link-styles' ) ),
				'link_color'             => array( 'link-color', $this->css( 'link-color' ) ),
				'link_color_hover'       => array( 'link-color-hover', $this->css( 'link-color-hover' ) ),
				'ext_links'              => array( 'option-indicate-external-links', $this->on( 'option-indicate-external-links' ) ),
				'ext_indicator'          => array( 'external-link-indicator', $this->css( 'external-link-indicator' ) ),
				'ext_label'              => array( 'external-link-indicator-accessibility-text', $this->css( 'external-link-indicator-accessibility-text' ) ),
				'ext_position'           => array( 'external-link-indicator-position', in_array( $this->raw( 'external-link-indicator-position' ), array( 'start', 'end' ), true ) ? $this->raw( 'external-link-indicator-position' ) : null ),
				'ext_gap'                => array( 'external-link-indicator-gap', $this->css( 'external-link-indicator-gap' ) ),
				'ext_size'               => array( 'external-link-indicator-size', $this->css( 'external-link-indicator-size' ) ),
				'ext_weight'             => array( 'external-link-indicator-weight', $this->css( 'external-link-indicator-weight' ) ),
				'ext_align'              => array( 'external-link-indicator-alignment', $this->css( 'external-link-indicator-alignment' ) ),
				'ext_offset'             => array( 'external-link-indicator-offset', $this->css( 'external-link-indicator-offset' ) ),
				'ext_color'              => array( 'external-link-indicator-color', $this->css( 'external-link-indicator-color' ) ),
				'ext_color_hover'        => array( 'external-link-indicator-color-hover', $this->css( 'external-link-indicator-color-hover' ) ),
				'ext_not'                => array( 'external-link-indicator-not', $this->selector( 'external-link-indicator-not' ) ),
				'base_buttons'           => array( 'option-buttons', $this->on( 'option-buttons' ) ),
				'control_bricks_buttons' => array( '(the imported setup leaves other buttons alone)', false ),
				'bricks_button_styles'   => array( 'option-buttons-styles-enable', $this->on( 'option-buttons-styles-enable' ) ),
				'btn_padding_block'      => array( 'btn-padding-block', $this->css( 'btn-padding-block' ) ),
				'btn_padding_inline'     => array( 'btn-padding-inline', $this->css( 'btn-padding-inline' ) ),
				'btn_radius'             => array( 'btn-border-radius', $this->css( 'btn-border-radius' ) ),
				'btn_font_weight'        => array( 'btn-font-weight', $this->css( 'btn-font-weight' ) ),
				'btn_font_size'          => array( 'btn-font-size', $this->css( 'btn-font-size' ) ),
				'btn_border_width'       => array( 'btn-border-width', $this->css( 'btn-border-width' ) ),
				'btn_min_width'          => array( 'btn-min-width', $px( $this->css( 'btn-min-width' ) ) ),
				'btn_line_height'        => array( 'btn-line-height', null !== $this->css( 'btn-line-height' ) ? $this->css( 'btn-line-height' ) : '1' ), // empty = ACSS default 1
				'btn_text_transform'     => array( 'btn-text-transform', $this->css( 'btn-text-transform' ) ),
				'btn_letter_spacing'     => array( 'btn-letter-spacing', $this->css( 'btn-letter-spacing' ) ),
				// ACSS 4 styles WS Form only, never native/Bricks fields.
				'base_forms'             => array( '(the imported setup leaves native fields alone)', false ),
				'field_radius'           => array( 'f-field-border-radius', $this->css( 'f-field-border-radius' ) ),
				'field_padding'          => array( 'f-field-block/inline-padding', ( null !== $bpad && null !== $ipad ) ? $bpad . ' ' . $ipad : null ),
				'field_border'           => array( 'f-field-border-color', $this->css( 'f-field-border-color' ) ),
				'base_cards'             => array( 'option-cards', $this->on( 'option-cards' ) ),
				'card_background'        => array( 'card-background', $this->css( 'card-background' ) ),
				'card_padding'           => array( 'card-padding', $this->css( 'card-padding' ) ),
				'card_gap'               => array( 'card-gap', $this->css( 'card-gap' ) ),
				'card_radius'            => array( 'card-radius', $this->css( 'card-radius' ) ),
				// Empty in ACSS = no shadow (clear an older TokenForge default).
				'card_shadow'            => array( 'card-shadow', isset( $this->a['card-shadow'] ) ? (string) $this->css( 'card-shadow' ) : null ),
			)
		);
		Components::save_settings( $s );
	}

	/** Boxed body, content grid, grid gaps. */
	private function layout() {
		$s = Components::settings();
		$this->apply(
			'Layout',
			$s,
			array(
				'body_shadow'             => array( 'body-box-shadow', $this->css( 'body-box-shadow' ) ),
				'body_border_width'       => array( 'body-border-width', $this->px( 'body-border-width' ) ),
				'body_border_style'       => array( 'body-border-style', $this->css( 'body-border-style' ) ),
				'body_border_color'       => array( 'body-border-color', $this->css( 'body-border-color' ) ),
				'body_border_radius'      => array( 'body-border-radius', $this->px( 'body-border-radius' ) ),
				'body_margin_top'         => array( 'body-margin-top', $this->px( 'body-margin-top' ) ),
				'device_bg'               => array( 'device-bg-color', $this->css( 'device-bg-color' ) ),
				// ACSS leaves Bricks containers to Bricks' theme style.
				'container_width'         => array( '(the imported setup leaves .brxe-container alone)', false ),
				'content_grid'            => array( 'option-content-grid', $this->on( 'option-content-grid' ) ),
				'content_grid_sections'   => array( 'option-content-grid-sections', $this->on( 'option-content-grid-sections' ) ),
				'feature_width'           => array( 'content-feature', $this->px( 'content-feature' ) ),
				'feature_max_width'       => array( 'content-feature-max', $this->px( 'content-feature-max' ) ),
				'content_grid_exclusions' => array( 'content-grid-exclusions', $this->selector( 'content-grid-exclusions' ) ),
				'auto_grid_gap'           => array( 'option-grid-gap', $this->on( 'option-grid-gap' ) ),
				'grid_gap_value'          => array( 'contextual-grid-gap', $this->css( 'contextual-grid-gap' ) ),
				'section_padding'         => array( 'option-load-default-section-styles', null === $this->on( 'option-section-padding' ) ? $this->on( 'option-load-default-section-styles' ) : null ),
			)
		);
		Components::save_settings( $s );
	}

	/** Text/heading details, per-heading + per-size overrides, text classes, links. */
	private function typography() {
		$s   = Components::settings();
		$map = array(
			'text_font_style'        => array( 'text-font-style', $this->css( 'text-font-style' ) ),
			'text_letter_spacing'    => array( 'text-letter-spacing', $this->css( 'text-letter-spacing' ) ),
			'text_transform'         => array( 'text-transform', $this->css( 'text-transform' ) ),
			'heading_font_style'     => array( 'heading-font-style', $this->css( 'heading-font-style' ) ),
			'heading_text_transform' => array( 'heading-text-transform', $this->css( 'heading-text-transform' ) ),
			'text_size_classes'      => array( 'option-text-size-classes', $this->on( 'option-text-size-classes' ) ),
			'link_weight'            => array( 'link-weight', $this->css( 'link-weight' ) ),
			'link_decoration'        => array( 'link-decoration', $this->css( 'link-decoration' ) ),
			'link_decoration_hover'  => array( 'link-decoration-hover', $this->css( 'link-decoration-hover' ) ),
			'link_decoration_color'  => array( 'link-decoration-color', $this->css( 'link-decoration-color' ) ),
			'link_decoration_color_hover' => array( 'link-decoration-color-hover', $this->css( 'link-decoration-color-hover' ) ),
			'link_decoration_thickness'   => array( 'link-decoration-thickness', $this->css( 'link-decoration-thickness' ) ),
			'link_underline_offset'  => array( 'link-underline-offset', $this->css( 'link-underline-offset' ) ),
			'link_transition'        => array( 'link-transition', $this->css( 'link-transition' ) ),
		);
		foreach ( array_keys( Components::TYPE_PROPS ) as $p ) {
			$id = str_replace( '_', '-', $p );
			for ( $i = 1; $i <= 6; $i++ ) {
				$map[ "h{$i}_$p" ] = array( "h$i-$id", $this->css( "h$i-$id" ) );
			}
			if ( 'font_family' !== $p && 'color' !== $p ) {
				foreach ( Scale_Engine::STEPS as $sz ) {
					$map[ "text_{$sz}_$p" ] = array( "text-$sz-$id", $this->css( "text-$sz-$id" ) );
				}
			}
		}
		$this->apply( 'Typography', $s, $map );
		Components::save_settings( $s );
	}

	/** Button families, per-family colour overrides and button details. */
	private function buttons() {
		$s        = Components::settings();
		$families = array();
		$seen     = false;
		$outline  = array();
		$shades   = array();
		foreach ( Components::FAMILIES as $f ) {
			$on = $this->on( "option-$f-btn" );
			if ( null !== $on ) {
				$seen = true;
			}
			if ( true === $on ) {
				$families[] = $f;
			}
			$outline[] = "option-$f-btn-outline";
			$shades[]  = "option-$f-btn-shades";
		}
		// Only what differs from TokenForge's defaults is stored as an override.
		$keys = array(
			'solid'         => array( 'bg' => 'btn-%s-bg', 'text' => 'btn-%s-text', 'border' => 'btn-%s-border-color', 'hover' => 'btn-%s-hover', 'hover_text' => 'btn-%s-hover-text', 'hover_border' => 'btn-%s-hover-border-color', 'focus' => 'btn-%s-focus-color' ),
			'dark'          => array( 'bg' => 'btn-%s-dark-bg', 'text' => 'btn-%s-dark-text', 'border' => 'btn-%s-dark-border-color', 'hover' => 'btn-%s-dark-hover', 'hover_text' => 'btn-%s-dark-hover-text', 'hover_border' => 'btn-%s-dark-hover-border-color', 'focus' => 'btn-%s-dark-focus-color' ),
			'light'         => array( 'bg' => 'btn-%s-light-bg', 'text' => 'btn-%s-light-text', 'border' => 'btn-%s-light-border-color', 'hover' => 'btn-%s-light-hover', 'hover_text' => 'btn-%s-light-hover-text', 'hover_border' => 'btn-%s-light-hover-border-color', 'focus' => 'btn-%s-light-focus-color' ),
			'outline'       => array( 'bg' => 'btn-%s-outline-background', 'hover' => 'btn-%s-outline-background-hover', 'border' => 'btn-%s-outline-border-color', 'hover_border' => 'btn-%s-outline-border-hover', 'focus' => 'btn-%s-outline-focus-color', 'text' => '%s-outline-btn-text', 'hover_text' => '%s-outline-hover-text' ),
			'outline-dark'  => array( 'bg' => 'btn-%s-outline-dark-background', 'hover' => 'btn-%s-outline-dark-background-hover', 'border' => 'btn-%s-outline-dark-border-color', 'hover_border' => 'btn-%s-outline-dark-border-hover', 'focus' => 'btn-%s-outline-dark-focus-color', 'text' => '%s-outline-dark-btn-text', 'hover_text' => '%s-outline-dark-hover-text' ),
			'outline-light' => array( 'bg' => 'btn-%s-outline-light-background', 'hover' => 'btn-%s-outline-light-background-hover', 'border' => 'btn-%s-outline-light-border-color', 'hover_border' => 'btn-%s-outline-light-border-hover', 'focus' => 'btn-%s-outline-light-focus-color', 'text' => '%s-outline-light-btn-text', 'hover_text' => '%s-outline-light-hover-text' ),
		);
		$norm      = function ( $v ) {
			return preg_replace( '/\s+/', '', strtolower( (string) $v ) );
		};
		$overrides = array();
		foreach ( Components::FAMILIES as $f ) {
			foreach ( $keys as $variant => $props ) {
				$def = Components::btn_default( $f, $variant );
				foreach ( $props as $p => $pattern ) {
					$v = $this->css( sprintf( $pattern, $f ) );
					if ( null !== $v && $norm( $v ) !== $norm( $def[ $p ] ) ) {
						$overrides[ $f ][ $variant ][ $p ] = $v;
					}
				}
			}
		}
		$n = 0;
		foreach ( $overrides as $variants ) {
			foreach ( $variants as $props ) {
				$n += count( $props );
			}
		}
		$bp = $this->num( 'option-btn-full-width-mobile-breakpoint' );
		$this->apply(
			'Buttons',
			$s,
			array(
				'btn_families'        => array( 'option-{family}-btn', $seen ? implode( ',', $families ) : null ),
				'btn_outline'         => array( 'option-{family}-btn-outline', $this->any_on( $outline ) ),
				'btn_shades'          => array( 'option-{family}-btn-shades', $this->any_on( $shades ) ),
				'btn_overrides'       => array( 'btn-{family}-* (' . $n . ' differ from the defaults)', $overrides ),
				'btn_font_family'     => array( 'btn-font-family', $this->css( 'btn-font-family' ) ),
				'btn_font_style'      => array( 'btn-font-style', $this->css( 'btn-font-style' ) ),
				'btn_text_align'      => array( 'btn-text-align', $this->css( 'btn-text-align' ) ),
				'btn_justify'         => array( 'btn-justify-content', $this->css( 'btn-justify-content' ) ),
				'btn_width'           => array( 'btn-width', $this->css( 'btn-width' ) ),
				'btn_border_style'    => array( 'btn-border-style', $this->css( 'btn-border-style' ) ),
				'btn_text_decoration' => array( 'btn-text-decoration', $this->css( 'btn-text-decoration' ) ),
				'btn_text_decoration_hover' => array( 'btn-text-decoration-hover', $this->css( 'btn-text-decoration-hover' ) ),
				'btn_transition'      => array( 'btn-transition', $this->css( 'btn-transition' ) ),
				'btn_gap'             => array( '(no button gap in the imported setup)', '' ),
				'btn_mobile_full'     => array( 'option-btn-full-width-on-mobile', $this->on( 'option-btn-full-width-on-mobile' ) ),
				'btn_mobile_bp'       => array( 'option-btn-full-width-mobile-breakpoint', null !== $bp && $bp > 0 ? $bp : null ),
			)
		);
		// A list value in the report reads better as a count.
		Components::save_settings( $s );
	}

	/** Cards. */
	private function cards() {
		$s   = Components::settings();
		$map = array(
			'card_selectors'   => array( 'card-selectors', $this->selector( 'card-selectors' ) ),
			'card_scheme'      => array( '(imported cards are light)', null !== $this->on( 'option-cards' ) ? 'light' : null ),
			'card_boxed_icons' => array( 'option-card-boxed-icons', $this->on( 'option-card-boxed-icons' ) ),
			'card_concentric'  => array( 'option-card-concentric-radius', $this->on( 'option-card-concentric-radius' ) ),
			'card_min_radius'  => array( 'card-min-radius', $this->px( 'card-min-radius' ) ),
			'card_button_family' => array( 'card-button-style', '' !== $this->raw( 'card-button-style' ) ? preg_replace( '/[^a-z0-9\-]/', '', strtolower( $this->raw( 'card-button-style' ) ) ) : null ),
			'card_link_color_hover' => array( 'card-link-hover-color', $this->css( 'card-link-hover-color' ) ),
			'card_media_aspect'     => array( 'card-media-aspect-ratio', $this->css( 'card-media-aspect-ratio' ) ),
			'card_media_fit'        => array( 'card-media-object-fit', $this->css( 'card-media-object-fit' ) ),
			'card_avatar_aspect'    => array( 'card-avatar-aspect-ratio', $this->css( 'card-avatar-aspect-ratio' ) ),
			'card_button_size'      => array( 'card-button-font-size', $this->css( 'card-button-font-size' ) ),
		);
		foreach ( array( 'text-color', 'text-size', 'border-width', 'border-style', 'border-color', 'heading-size', 'heading-color', 'link-color', 'media-radius', 'avatar-size', 'avatar-radius', 'avatar-border-width', 'avatar-border-style', 'avatar-border-color', 'icon-size', 'icon-background', 'icon-color', 'icon-padding', 'icon-radius', 'icon-border-width', 'icon-border-style', 'icon-border-color' ) as $k ) {
			$map[ 'card_' . str_replace( '-', '_', $k ) ] = array( "card-$k", $this->css( "card-$k" ) );
		}
		$this->apply( 'Cards', $s, $map );
		Components::save_settings( $s );
	}

	/** Full radius scale, borders + dividers, icon system, inverted radius. */
	private function icons_borders() {
		$s     = Extras::settings();
		$r     = Width::to_px( (string) $this->raw( 'base-radius' ) );
		$scale = $this->num( 'radius-scale' );
		$sc    = ( null !== $scale && $scale > 0 ) ? $scale : 1.5;
		$rad   = function ( $f ) use ( $r ) {
			return null === $r ? null : round( $r * $f, 4 ) . 'px';
		};
		$map = array(
			'radius_xs'           => array( 'base-radius ÷ radius-scale²', $rad( 1 / ( $sc * $sc ) ) ),
			'radius_xxl'          => array( 'base-radius × radius-scale³', $rad( $sc * $sc * $sc ) ),
			'border_color_light'  => array( 'border-color-light', $this->css( 'border-color-light' ) ),
			'divider_size'        => array( 'divider-size', $this->px( 'divider-size' ) ),
			'divider_style'       => array( 'divider-style', $this->css( 'divider-style' ) ),
			'divider_color_dark'  => array( 'divider-color-dark', $this->css( 'divider-color-dark' ) ),
			'divider_color_light' => array( 'divider-color-light', $this->css( 'divider-color-light' ) ),
			'divider_gap'         => array( 'divider-gap', $this->css( 'divider-gap' ) ),
			'enable_icon'         => array( 'option-icons', $this->on( 'option-icons' ) ),
			'icon_padding'        => array( 'icon-padding', $this->css( 'icon-padding' ) ),
			'icon_color'          => array( 'icon-color', $this->css( 'icon-color' ) ),
			'icon_color_hover'    => array( 'icon-color-hover', $this->css( 'icon-color-hover' ) ),
			'icon_background'     => array( 'icon-background', $this->css( 'icon-background' ) ),
			'icon_background_hover' => array( 'icon-background-hover', $this->css( 'icon-background-hover' ) ),
			'icon_border_width'   => array( 'icon-border-width', $this->css( 'icon-border-width' ) ),
			'icon_border_style'   => array( 'icon-border-style', $this->css( 'icon-border-style' ) ),
			'icon_border_color'   => array( 'icon-border-color', $this->css( 'icon-border-color' ) ),
			'icon_border_color_hover' => array( 'icon-border-color-hover', $this->css( 'icon-border-color-hover' ) ),
			'icon_radius'         => array( 'icon-radius', $this->css( 'icon-radius' ) ),
			'icon_scheme'         => array( 'icon-default-scheme', in_array( $this->raw( 'icon-default-scheme' ), array( 'light', 'dark' ), true ) ? $this->raw( 'icon-default-scheme' ) : ( '' !== $this->raw( 'icon-default-scheme' ) ? '' : null ) ),
			'icon_default_style'  => array( 'icon-default-style', in_array( $this->raw( 'icon-default-style' ), array( 'plain', 'boxed' ), true ) ? $this->raw( 'icon-default-style' ) : null ),
			'icon_list_gap'       => array( 'icon-list-gap', $this->css( 'icon-list-gap' ) ),
			'icon_list_icon_size' => array( 'icon-list-icon-size', $this->css( 'icon-list-icon-size' ) ),
			'boxed_list_icons'    => array( 'option-boxed-list-icons', $this->on( 'option-boxed-list-icons' ) ),
			'inverted_radius'     => array( 'option-inverted-radius-framework', $this->on( 'option-inverted-radius-framework' ) ),
			'ribbons'             => array( 'option-corner-ribbons', $this->on( 'option-corner-ribbons' ) ),
		);
		foreach ( Extras::ICON_SIZES as $sz ) {
			$map[ 'icon_' . $sz ]         = array( "icon-size-$sz", $this->px( "icon-size-$sz" ) );
			$map[ 'icon_padding_' . $sz ] = array( "icon-padding-$sz", $this->css( "icon-padding-$sz" ) );
		}
		$this->apply( 'Effects', $s, $map );
		Extras::save_settings( $s );
	}

	/** Custom fonts font-1 … font-5 → self-hosted fonts (added after yours, same family + file skipped). */
	private function fonts() {
		$s    = Fonts::settings();
		$have = array();
		foreach ( $s['fonts'] as $f ) {
			$have[ $f['family'] . '|' . $f['src'] ] = true;
		}
		$n = 0;
		for ( $i = 1; $i <= 5; $i++ ) {
			$fam = $this->raw( "font-$i-family-name" );
			$src = $this->raw( "font-$i-src" );
			if ( '' === $fam || '' === $src || isset( $have[ $fam . '|' . $src ] ) ) {
				continue;
			}
			$s['fonts'][] = array(
				'family'    => $fam,
				'src'       => $src,
				'src2'      => $this->raw( "font-$i-src-fallback" ),
				'weight'    => '' !== $this->raw( "font-$i-weight" ) ? $this->raw( "font-$i-weight" ) : '400',
				'style'     => '' !== $this->raw( "font-$i-style" ) ? $this->raw( "font-$i-style" ) : 'normal',
				'display'   => '' !== $this->raw( "font-$i-display" ) ? $this->raw( "font-$i-display" ) : 'swap',
				'variable'  => 'variable' === strtolower( $this->raw( "font-$i-type" ) ),
				'stretch'   => $this->raw( "font-$i-stretch" ),
				'variation' => $this->raw( "font-$i-variation-settings" ),
				'preload'   => false,
			);
			$this->report['Fonts'][] = "fonts ← font-$i ($fam)";
			$n++;
		}
		if ( $n ) {
			Fonts::save_settings( $s );
		}
	}

	/** Palette options + each family's base colour (for regenerating shades later). */
	private function palette() {
		foreach ( Palette::import_acss( $this->a ) as $row ) {
			$this->report['Palette'][] = $row;
		}
	}

	/** Custom SCSS (added below your own Global SCSS, never replacing it). */
	private function scss() {
		if ( Scss::import_acss( $this->a ) ) {
			$this->report['Global SCSS'][] = 'source ← automatic-custom-css.scss / custom-global-css';
		}
	}

	/** Radius, borders, shadows, icons, header height. */
	private function extras() {
		$s     = Extras::settings();
		$r     = Width::to_px( (string) $this->raw( 'base-radius' ) );
		$scale = $this->num( 'radius-scale' );
		$rad   = function ( $f ) use ( $r ) {
			return null === $r ? null : round( $r * $f, 2 ) . 'px';
		};
		$sc    = ( null !== $scale && $scale > 0 ) ? $scale : 1.5;
		$map   = array(
			'radius_m'              => array( 'base-radius', $rad( 1 ) ),
			'radius_s'              => array( 'base-radius ÷ radius-scale', $rad( 1 / $sc ) ),
			'radius_l'              => array( 'base-radius × radius-scale', $rad( $sc ) ),
			'radius_xl'             => array( 'base-radius × radius-scale²', $rad( $sc * $sc ) ),
			'border_size'           => array( 'border-size', $this->css( 'border-size' ) ),
			'border_style'          => array( 'border-style', $this->css( 'border-style' ) ),
			'border_color'          => array( 'border-color-dark', $this->css( 'border-color-dark' ) ),
			'header_height'         => array( 'header-height-max', null !== $this->num( 'header-height-max' ) ? $this->num( 'header-height-max' ) . 'px' : null ),
			'header_height_min'     => array( 'header-height-min', null !== $this->num( 'header-height-min' ) ? $this->num( 'header-height-min' ) . 'px' : null ),
			'icon_s'                => array( 'icon-size-s', $this->css( 'icon-size-s' ) ),
			'icon_m'                => array( 'icon-size-m', $this->css( 'icon-size-m' ) ),
			'icon_l'                => array( 'icon-size-l', $this->css( 'icon-size-l' ) ),
			'auto_radius'           => array( 'option-auto-radius', $this->on( 'option-auto-radius' ) ),
			'auto_radius_selectors' => array( 'auto-radius-selectors', $this->css( 'auto-radius-selectors' ) ),
		);
		for ( $i = 1; $i <= 5; $i++ ) {
			$map[ 'shadow_' . $i ] = array( "box-shadow-$i-value", $this->css( "box-shadow-$i-value" ) );
		}
		for ( $i = 1; $i <= 3; $i++ ) {
			$map[ 'text_shadow_' . $i ] = array( "text-shadow-$i-value", $this->css( "text-shadow-$i-value" ) );
			$map[ 'drop_shadow_' . $i ] = array( "drop-shadow-$i-value", $this->css( "drop-shadow-$i-value" ) );
		}
		$map['enable_text_shadow'] = array( 'option-text-shadow-variables', $this->on( 'option-text-shadow-variables' ) );
		$map['enable_drop_shadow'] = array( 'option-drop-shadow-variables', $this->on( 'option-drop-shadow-variables' ) );
		$this->apply( 'Effects', $s, $map );
		Extras::save_settings( $s );
	}

	/** Dark mode, context colours, forced schemes. */
	private function scheme() {
		$s    = Scheme::settings();
		$auto = $this->on( 'auto-color-scheme' );
		$map  = array(
			'dark_mode'   => array( 'auto-color-scheme', null === $auto ? null : ( $auto ? 'auto' : 'off' ) ),
			'assignments' => array( 'option-contextual-color-classes', $this->on( 'option-contextual-color-classes' ) ),
			'force_dark'  => array( 'color-scheme-force-dark-selectors', $this->css( 'color-scheme-force-dark-selectors' ) ),
			'force_light' => array( 'color-scheme-force-light-selectors', $this->css( 'color-scheme-force-light-selectors' ) ),
		);
		foreach ( array( 'bg-ultra-light', 'bg-light', 'bg-dark', 'bg-ultra-dark', 'text-dark', 'text-dark-muted', 'text-light', 'text-light-muted' ) as $k ) {
			$map[ str_replace( '-', '_', $k ) ] = array( $k, $this->css( $k ) );
		}
		$ws = strtolower( $this->raw( 'website-color-scheme' ) );
		$map['default_scheme'] = array( 'website-color-scheme', in_array( $ws, array( 'light', 'dark', 'auto' ), true ) ? $ws : null );
		// What each .bg--* sets inside it.
		foreach ( Scheme::CONTEXTS as $ctx ) {
			$k = 'ctx_' . str_replace( '-', '_', $ctx ) . '_';
			$map[ $k . 'text' ]       = array( "bg-$ctx-text", $this->css( "bg-$ctx-text" ) );
			$map[ $k . 'heading' ]    = array( "bg-$ctx-heading", $this->css( "bg-$ctx-heading" ) );
			$map[ $k . 'button' ]     = array( "bg-$ctx-button", '' !== $this->raw( "bg-$ctx-button" ) ? $this->raw( "bg-$ctx-button" ) : null );
			$map[ $k . 'icon' ]       = array( "bg-$ctx-icon", in_array( $this->raw( "bg-$ctx-icon" ), array( 'light', 'dark' ), true ) ? $this->raw( "bg-$ctx-icon" ) : null );
			$map[ $k . 'link' ]       = array( "bg-$ctx-link", $this->css( "bg-$ctx-link" ) );
			$map[ $k . 'link_hover' ] = array( "bg-$ctx-link-hover", $this->css( "bg-$ctx-link-hover" ) );
			$map[ $k . 'focus' ]      = array( "bg-$ctx-focus-color", $this->css( "bg-$ctx-focus-color" ) );
		}
		$this->apply( 'Colors', $s, $map );
		Scheme::save_settings( $s );
	}

	/** Smart spacing → content/prose; blockquotes. */
	private function content() {
		$s   = Content::settings();
		$map = array(
			'smart_spacing'         => array( 'option-smart-spacing', $this->on( 'option-smart-spacing' ) ),
			'flow'                  => array( 'option-smart-spacing', $this->on( 'option-smart-spacing' ) ),
			'flow_selectors'        => array( 'smart-spacing-selectors', $this->selector( 'smart-spacing-selectors' ) ),
			// ACSS has no prose layer: don't double-style Rich Text / post content.
			'prose_auto'            => array( '(no automatic prose in the imported setup)', false ),
			'rich_text'             => array( '(no automatic prose in the imported setup)', false ),
			'blockquotes'           => array( 'option-blockquote-styling', $this->on( 'option-blockquote-styling' ) ),
			'bq_auto'               => array( 'option-auto-style-blockquotes', $this->on( 'option-auto-style-blockquotes' ) ),
			'bq_exclude'            => array( 'blockquote-selector-exclusions', $this->selector( 'blockquote-selector-exclusions' ) ),
		);
		// ACSS setting ids differ from their variable names for three of these.
		$ids = array( 'border-radius' => 'blockquote-radius', 'text-text-align' => 'blockquote-text-align', 'text-text-transform' => 'blockquote-text-transform' );
		foreach ( array_keys( Content::BLOCKQUOTE ) as $k ) {
			$id = isset( $ids[ $k ] ) ? $ids[ $k ] : 'blockquote-' . $k;
			$map[ 'bq_' . str_replace( '-', '_', $k ) ] = array( $id, $this->css( $id ) );
		}
		foreach ( Content::FLOW_KEYS as $k ) {
			$id          = str_replace( '_', '-', $k );
			$map[ $k ]   = array( $id, $this->css( $id ) );
		}
		$this->apply( 'Content', $s, $map );
		Content::save_settings( $s );
	}

	/** Transitions, easing presets and effects. */
	private function motion() {
		$old = Motion::settings();
		$s   = $old;
		$fx  = function ( $family, $extra = array() ) {
			$keys = array();
			foreach ( array_merge( array( 'fade', 'float', 'sink', 'slide', 'grow', 'shrink', 'blur' ), $extra ) as $f ) {
				$keys[] = "option-$family-$f";
			}
			return $keys;
		};
		$easings = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$name  = $this->raw( "ease-$i-name" );
			$value = $this->css( "ease-$i-value" );
			if ( '' !== $name && null !== $value ) {
				$easings[] = $name . '=' . $value;
			}
		}
		$c   = function ( $k ) {
			return $this->css( $k );
		};
		$map = array(
			'transitions'         => array( 'option-transition-variables', $this->on( 'option-transition-variables' ) ),
			'transition_duration' => array( 'transition-duration', $c( 'transition-duration' ) ),
			'transition_timing'   => array( 'transition-timing', $c( 'transition-timing' ) ),
			'transition_delay'    => array( 'transition-delay', $c( 'transition-delay' ) ),
			'transition_class'    => array( 'option-transition-classes', $this->on( 'option-transition-classes' ) ),
			'easing'              => array( 'option-easing-presets', $this->on( 'option-easing-presets' ) ),
			'easings'             => array( 'ease-N-name/value', $easings ? implode( "\n", $easings ) : null ),
			'enter'               => array( 'option-enter-*', $this->any_on( $fx( 'enter', array( 'parallax' ) ) ) ),
			'enter_range_start'   => array( 'animate-range-start', $c( 'animate-range-start' ) ),
			'enter_range_end'     => array( 'animate-range-end', $c( 'animate-range-end' ) ),
			'enter_stagger'       => array( 'enter-stagger-interval', $c( 'enter-stagger-interval' ) ),
			'enter_opacity'       => array( 'enter-opacity-start', $c( 'enter-opacity-start' ) ),
			'enter_float'         => array( 'enter-float-distance', $c( 'enter-float-distance' ) ),
			'enter_sink'          => array( 'enter-sink-distance', $c( 'enter-sink-distance' ) ),
			'enter_slide'         => array( 'enter-slide-distance', $c( 'enter-slide-distance' ) ),
			'enter_grow'          => array( 'enter-scale-start', $c( 'enter-scale-start' ) ),
			'enter_shrink'        => array( 'enter-scale-shrink', $c( 'enter-scale-shrink' ) ),
			'enter_blur'          => array( 'enter-blur-amount', $c( 'enter-blur-amount' ) ),
			'enter_parallax'      => array( 'enter-parallax-distance', $c( 'enter-parallax-distance' ) ),
			'fallback_duration'   => array( 'scroll-animation-duration', $c( 'scroll-animation-duration' ) ),
			'fallback_timing'     => array( 'scroll-animation-timing', $c( 'scroll-animation-timing' ) ),
			'fallback_delay'      => array( 'scroll-animation-delay', $c( 'scroll-animation-delay' ) ),
			'exit'                => array( 'option-exit-*', $this->any_on( $fx( 'exit' ) ) ),
			'exit_range_start'    => array( 'exit-range-start', $c( 'exit-range-start' ) ),
			'exit_range_end'      => array( 'exit-range-end', $c( 'exit-range-end' ) ),
			'exit_stagger'        => array( 'exit-stagger-interval', $c( 'exit-stagger-interval' ) ),
			'exit_opacity'        => array( 'exit-opacity-target', $c( 'exit-opacity-target' ) ),
			'exit_float'          => array( 'exit-float-distance', $c( 'exit-float-distance' ) ),
			'exit_sink'           => array( 'exit-sink-distance', $c( 'exit-sink-distance' ) ),
			'exit_slide'          => array( 'exit-slide-distance', $c( 'exit-slide-distance' ) ),
			'exit_grow'           => array( 'exit-scale-grow', $c( 'exit-scale-grow' ) ),
			'exit_shrink'         => array( 'exit-scale-amount', $c( 'exit-scale-amount' ) ),
			'exit_blur'           => array( 'exit-blur-amount', $c( 'exit-blur-amount' ) ),
			'visible'             => array( 'option-visible-*', $this->any_on( $fx( 'visible' ) ) ),
			'visible_duration'    => array( 'visible-duration', $c( 'visible-duration' ) ),
			'visible_timing'      => array( 'visible-timing', $c( 'visible-timing' ) ),
			'visible_delay'       => array( 'visible-delay', $c( 'visible-delay' ) ),
			'visible_threshold'   => array( 'visible-threshold', $c( 'visible-threshold' ) ),
			'visible_stagger'     => array( 'visible-stagger-interval', $c( 'visible-stagger-interval' ) ),
			'visible_opacity'     => array( 'visible-opacity-start', $c( 'visible-opacity-start' ) ),
			'visible_float'       => array( 'visible-float-distance', $c( 'visible-float-distance' ) ),
			'visible_sink'        => array( 'visible-sink-distance', $c( 'visible-sink-distance' ) ),
			'visible_slide'       => array( 'visible-slide-distance', $c( 'visible-slide-distance' ) ),
			'visible_grow'        => array( 'visible-scale-start', $c( 'visible-scale-start' ) ),
			'visible_shrink'      => array( 'visible-scale-shrink', $c( 'visible-scale-shrink' ) ),
			'visible_blur'        => array( 'visible-blur-amount', $c( 'visible-blur-amount' ) ),
			'hover'               => array( 'option-hover-*', $this->any_on( array( 'option-hover-grow', 'option-hover-shrink', 'option-hover-float', 'option-hover-sink', 'option-hover-slide-right', 'option-hover-slide-left', 'option-hover-shadow', 'option-hover-glow', 'option-hover-fade', 'option-hover-brighten' ) ) ),
			'hover_duration'      => array( 'hover-duration', $c( 'hover-duration' ) ),
			'hover_timing'        => array( 'hover-timing', $c( 'hover-timing' ) ),
			'hover_grow'          => array( 'hover-grow-amount', $c( 'hover-grow-amount' ) ),
			'hover_shrink'        => array( 'hover-shrink-amount', $c( 'hover-shrink-amount' ) ),
			'hover_float'         => array( 'hover-float-amount', $c( 'hover-float-amount' ) ),
			'hover_sink'          => array( 'hover-sink-amount', $c( 'hover-sink-amount' ) ),
			'hover_slide_right'   => array( 'hover-slide-right-amount', $c( 'hover-slide-right-amount' ) ),
			'hover_slide_left'    => array( 'hover-slide-left-amount', $c( 'hover-slide-left-amount' ) ),
			'hover_shadow'        => array( 'hover-shadow', $c( 'hover-shadow' ) ),
			'hover_glow_color'    => array( 'hover-glow-color', $c( 'hover-glow-color' ) ),
			'hover_glow_spread'   => array( 'hover-glow-spread', $c( 'hover-glow-spread' ) ),
			'hover_glow_opacity'  => array( 'hover-glow-opacity', $c( 'hover-glow-opacity' ) ),
			'border_fx'           => array( 'option-border-ripple/outline/underline', $this->any_on( array( 'option-border-ripple', 'option-border-outline', 'option-border-underline' ) ) ),
			'disable_in_builder'  => array( 'option-disable-effects-in-builder', $this->on( 'option-disable-effects-in-builder' ) ),
		);
		foreach ( array( 'ripple' => array( 'color', 'width', 'duration', 'distance' ), 'outline' => array( 'color', 'width', 'duration' ), 'underline' => array( 'color', 'width', 'duration' ) ) as $fx_name => $props ) {
			foreach ( $props as $p ) {
				$map[ $fx_name . '_' . $p ] = array( "border-$fx_name-$p", $c( "border-$fx_name-$p" ) );
			}
		}
		$this->apply( 'Motion', $s, $map );
		Motion::save_settings( $s );
		if ( Motion::sync_stored_tokens( $old ) ) {
			$this->report['Motion'][] = 'stored --transition*/--ease-* tokens updated to match';
		}
	}

	/** Basic + custom overlays, surfaces. */
	private function overlays() {
		$s = Overlays::settings();
		$pseudo = function ( $k ) {
			$v = $this->raw( $k );
			return in_array( $v, array( 'before', 'after' ), true ) ? $v : null;
		};
		$this->apply(
			'Overlays',
			$s,
			array(
				'basic'         => array( 'option-basic-overlays', $this->on( 'option-basic-overlays' ) ),
				'basic_pseudo'  => array( 'basic-overlay-pseudo-element', $pseudo( 'basic-overlay-pseudo-element' ) ),
				'custom_pseudo' => array( 'custom-overlay-pseudo-element', $pseudo( 'custom-overlay-pseudo-element' ) ),
			)
		);
		$v = function ( $k ) {
			$c = $this->css( $k );
			return null === $c ? '' : $c;
		};
		// Numbered presets keep their numbers (.overlay-3 stays .overlay-3), so
		// the list runs up to the last one that actually paints something.
		foreach ( array( 'overlay' => 'overlays', 'surface' => 'surfaces' ) as $kind => $key ) {
			$list = array();
			$last = 0;
			for ( $i = 1; $i <= Overlays::MAX; $i++ ) {
				if ( ! isset( $this->a[ "$kind-$i-name" ] ) ) {
					break;
				}
				$p    = "$kind-$i-";
				$name = Overlays::slug( $this->raw( $p . 'name' ) );
				$name = ( (string) $i === $name ) ? '' : $name;
				if ( 'overlay' === $kind ) {
					$type = $this->raw( $p . 'background-type' );
					$item = array(
						'name'       => $name,
						'color'      => $v( $p . 'bg-color' ),
						'type'       => in_array( $type, array( 'gradient', 'image' ), true ) ? $type : 'none',
						'gradient'   => $v( $p . 'background' ),
						'image'      => $this->raw( $p . 'background-image' ),
						'size'       => $v( $p . 'background-size' ),
						'position'   => $v( $p . 'background-position' ),
						'attachment' => $v( $p . 'background-attachment' ),
						'repeat'     => $v( $p . 'background-repeat' ),
						'blur'       => $v( $p . 'background-blur' ),
						'opacity'    => $v( $p . 'opacity' ),
						'blend'      => $v( $p . 'blend-mode' ),
						'radius'     => $v( $p . 'border-radius' ),
						'inset'      => $v( $p . 'inset' ),
						'animation'  => $v( $p . 'animation' ),
						'layer'      => $v( $p . 'overlay' ),
					);
					$live = '' !== $item['color'] || '' !== $item['layer'] || '' !== $item['blur'] || ( 'none' !== $item['type'] && ( '' !== $item['gradient'] || '' !== $item['image'] ) );
				} else {
					$rel  = $this->raw( $p . 'color-relationship' );
					$item = array(
						'name'         => $name,
						'color'        => $v( $p . 'bg-color' ),
						'asset'        => $this->raw( $p . 'asset' ),
						'asset_is_url' => false !== $this->on( $p . 'asset-is-url' ),
						'size'         => $v( $p . 'size' ),
						'position'     => $v( $p . 'position' ),
						'attachment'   => $v( $p . 'attachment' ),
						'repeat'       => $v( $p . 'repeat' ),
						'relationship' => isset( Overlays::RELATIONSHIPS[ $rel ] ) ? $rel : 'none',
						'animation'    => $v( $p . 'animation' ),
						'layer'        => $v( $p . 'overlay' ),
					);
					// A surface is only output when it has a texture (as in ACSS);
					// a placeholder keeping the numbering must not paint its colour.
					$live = '' !== $item['asset'] || '' !== $item['animation'];
					if ( ! $live ) {
						$item['color'] = '';
						$item['layer'] = '';
					}
				}
				$list[] = $item;
				if ( $live ) {
					$last                       = $i;
					$this->report['Overlays'][] = "$kind-$i ← $kind-$i-*" . ( '' !== $name ? " (.$kind-$name)" : '' );
				}
			}
			$s[ $key ] = array_slice( $list, 0, $last );
		}
		Overlays::save_settings( $s );
	}

	/** Cascade layers, inline CSS, admin bar, Gutenberg palette. */
	private function plugin_settings() {
		$settings = Settings::get_instance();
		$s        = $settings->get_settings();
		$util     = $this->any_on( array( 'option-layer-defaults', 'option-layer-utilities' ) );
		$gen      = $this->on( 'option-gutenberg-color-palette-generate' );
		$rep      = $this->on( 'option-gutenberg-color-palette-replace' );
		$this->apply(
			'Settings',
			$s,
			array(
				// ACSS layers only buttons ("anchors"), cards, icons and utilities -
				// body/heading/link/section defaults stay unlayered.
				'layer_defaults'    => array( 'option-layer-defaults/utilities', $util ),
				'layer_base'        => array( '(the imported setup keeps base typography unlayered)', false ),
				'layer_buttons'     => array( 'option-layer-anchors', $this->on( 'option-layer-anchors' ) ),
				'layer_cards'       => array( 'option-layer-cards', $this->on( 'option-layer-cards' ) ),
				'layer_icons'       => array( 'option-layer-icons', $this->on( 'option-layer-icons' ) ),
				'inline_css'        => array( 'option-inline-tokens', $this->on( 'option-inline-tokens' ) ),
				'admin_bar_link'    => array( 'admin-bar-enabled', $this->on( 'admin-bar-enabled' ) ),
				'gutenberg_palette' => array( 'option-gutenberg-color-palette-generate/replace', null === $gen ? null : ( $gen ? ( $rep ? 'replace' : 'add' ) : 'off' ) ),
			)
		);
		$settings->save_settings( $s );
	}

	/** Bricks builder options (TokenForge + native Bricks equivalents). */
	private function builder() {
		$s    = Features\Builder_Enhancements::settings();
		$ctx  = $this->on( 'option-contextual-menus-enable' );
		$css  = $this->on( 'option-bricks-css-improved' );
		$this->apply(
			'Builder',
			$s,
			array(
				'stack_add_elements'        => array( 'option-bricks-stack-elements', $this->on( 'option-bricks-stack-elements' ) ),
				'pro_mode'                  => array( 'option-bricks-pro-mode', $this->on( 'option-bricks-pro-mode' ) ),
				// ACSS's option shows/hides Bricks' own grid overlay (not a container visualizer).
				'grid_overlay'              => array( 'option-bricks-grid-overlay', $this->on( 'option-bricks-grid-overlay' ) ),
				'template_gallery'          => array( 'option-bricks-template-gallery-enhancements', $this->on( 'option-bricks-template-gallery-enhancements' ) ),
				'overflow_detection'        => array( 'option-overflow-detection', $this->on( 'option-overflow-detection' ) ),
				'original_template_classes' => array( 'option-fix-bricks-template-ids-enable', $this->on( 'option-fix-bricks-template-ids-enable' ) ),
				'css_group_top'             => array( 'option-bricks-css-first', $this->on( 'option-bricks-css-first' ) ),
				'css_autocomplete'          => array( 'option-bricks-css-improved', $css ),
				'bigger_code_editor'        => array( 'option-bricks-css-improved', $css ),
				'ctx_vars_menu'             => array( 'option-contextual-menus-enable', $ctx ),
				'ctx_class_menu'            => array( 'option-contextual-menus-enable', $ctx ),
			)
		);
		Features\Builder_Enhancements::save_settings( $s );

		// Native Bricks 2.4 options that replace ACSS builder features.
		$native = array();
		$kb     = $this->on( 'option-keyboard-nav-hover-preview-enable' );
		if ( null !== $kb ) {
			$native['builderClassPreviewOnHover']       = $kb;
			$native['builderClassExpandSelectedOnType'] = $kb;
			$this->report['Bricks (native)'][]          = 'class preview + keyboard ← option-keyboard-nav-hover-preview-enable = ' . ( $kb ? 'on' : 'off' );
		}
		$iv = $this->on( 'option-builder-input-validation' );
		if ( null !== $iv ) {
			$native['builderApplyVariablesAndCalculations'] = $iv;
			$this->report['Bricks (native)'][]              = 'variable expansion ← option-builder-input-validation = ' . ( $iv ? 'on' : 'off' );
		}
		if ( $native ) {
			Features\Builder_Enhancements::save_native( $native );
		}
	}
}
