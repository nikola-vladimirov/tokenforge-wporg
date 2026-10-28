<?php
/**
 * Base-styles / components engine.
 *
 * Generates the base CSS that applies the design tokens to native HTML and to
 * builder output (Bricks .brxe-*, Gutenberg blocks): layout (gutters, content
 * grid, boxed body, section spacing, automatic gaps), body + heading
 * typography with per-level/per-size overrides, text-size classes, links, the
 * external-link indicator, buttons (btn--* families with per-family overrides
 * and background-aware variants), form fields and cards. Most element rules
 * are zero-specificity (:where) so any class or element setting overrides them.
 *
 * build() returns the base CSS plus separate button and card parts, so each can
 * go in its own cascade layer. Original implementation; class and variable
 * names follow Automatic.css so existing markup keeps working.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Base styles + component classes.
 */
class Components {

	const OPTION = 'tokenforge_components';

	/** Color families the button variants are generated for. */
	const FAMILIES = array( 'primary', 'secondary', 'tertiary', 'accent', 'base', 'neutral', 'action', 'success', 'danger', 'warning', 'info' );

	/** Top-level sections (any builder that outputs <section>, plus .section). */
	const SECTION = ':where(section:not(section section), .section)';

	/** Per-heading / per-text-size override properties: setting suffix => CSS property. */
	const TYPE_PROPS = array(
		'color'          => 'color',
		'font_family'    => 'font-family',
		'font_style'     => 'font-style',
		'font_weight'    => 'font-weight',
		'letter_spacing' => 'letter-spacing',
		'line_height'    => 'line-height',
		'max_width'      => 'max-width',
		'text_transform' => 'text-transform',
	);

	/** Button variants and the properties each one sets. */
	const BTN_VARIANTS = array( 'solid', 'dark', 'light', 'outline', 'outline-dark', 'outline-light' );
	const BTN_PROPS    = array( 'bg', 'text', 'border', 'hover', 'hover_text', 'hover_border', 'focus' );

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'components', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		$d = array(
			// ---- Layout ----
			'base_layout'           => true,   // section gutters + layout
			'layout_type'           => 'full', // 'full' | 'boxed' (the whole page is boxed and centred)
			'gutter_min'            => 20,     // side gutter px @ min viewport
			'gutter_max'            => 60,     // side gutter px @ max viewport
			'boxed_width'           => 1400,   // px, boxed page width
			'container_width'       => true,   // Bricks containers (.brxe-container) take the content width
			'body_shadow'           => '0 0 80px 0 var(--neutral-trans-20, rgb(0 0 0 / .1))',
			'body_border_width'     => '0',
			'body_border_style'     => 'none',
			'body_border_color'     => 'transparent',
			'body_border_radius'    => '0',
			'body_margin_top'       => '0',
			'device_bg'             => 'var(--white, #fff)', // behind a boxed page
			'section_padding'       => true,   // default top/bottom padding on sections
			'section_padding_block' => 'var(--section-space-m)',
			'auto_container_gap'    => true,   // gap between a section's children
			'auto_content_gap'      => true,   // gap inside containers/blocks
			'auto_grid_gap'         => true,   // gap on grid--* elements
			'container_gap'         => 'var(--space-xl)',
			'content_gap'           => 'var(--space-m)',
			'grid_gap_value'        => '',     // --grid-gap override (empty = the scale's grid gap)
			// Content grid: named columns content / feature / feature-max / full.
			'content_grid'          => true,   // the .content-grid class
			'content_grid_sections' => false,  // every top-level section is a content grid
			'feature_width'         => '50px',
			'feature_max_width'     => '100px',
			'content_grid_exclusions' => '.content-grid--off, .pswp__scroll-wrap',
			// ---- Typography ----
			'base_root'             => true,   // body + heading defaults
			'body_bg'               => '',     // e.g. var(--white)
			'text_color'            => '',     // e.g. var(--text-dark)
			'text_font_family'      => '',
			'text_font_weight'      => '',
			'text_font_style'       => '',
			'text_letter_spacing'   => '',
			'text_transform'        => '',
			'text_line_height'      => 'calc(6px + 2ex)',
			'text_wrap'             => 'pretty',
			'heading_color'         => '',
			'heading_font_family'   => '',
			'heading_font_weight'   => '',
			'heading_font_style'    => '',
			'heading_text_transform' => '',
			'heading_line_height'   => 'calc(4px + 2ex)',
			'heading_letter_spacing' => '',
			'heading_text_wrap'     => 'balance',
			'text_size_classes'     => true,   // .text--xs … .text--xxl
			// ---- Links ----
			'base_links'            => true,
			'link_color'            => 'var(--primary, var(--action, currentColor))',
			'link_color_hover'      => 'var(--primary-hover, var(--action-hover, currentColor))',
			'link_decoration'       => '',
			'link_decoration_hover' => '',
			'link_decoration_color' => '',
			'link_decoration_color_hover' => '',
			'link_decoration_thickness'   => '',
			'link_underline_offset' => '',
			'link_weight'           => '',
			'link_transition'       => '',
			// ---- External-link indicator (links opening in a new tab) ----
			'ext_links'             => false,
			'ext_indicator'         => '\2197\FE0E', // ↗ (text presentation)
			'ext_label'             => 'Link to external site', // read by screen readers instead of the glyph
			'ext_position'          => 'end',        // end | start
			'ext_gap'               => '.25em',
			'ext_size'              => '.9em',
			'ext_weight'            => 'inherit',
			'ext_align'             => 'center',     // start | center | end | baseline | auto
			'ext_offset'            => '0 0',        // translate
			'ext_color'             => 'currentColor',
			'ext_color_hover'       => 'currentColor',
			'ext_not'               => ':has(img, > figure, > picture, > svg)', // links to leave alone
			// ---- Buttons ----
			'base_buttons'          => true,   // any btn--* class, and .btn
			'control_bricks_buttons' => false, // restyle EVERY Bricks/Gutenberg/native button (ACSS doesn't)
			'bricks_button_styles'  => true,   // Bricks button "Style" presets (Primary, Secondary…) → TokenForge families
			'btn_family'            => 'primary', // colour family of the plain .btn class
			'btn_families'          => implode( ',', self::FAMILIES ), // families that get btn--{family}
			'btn_outline'           => true,   // .btn--{family}.btn--outline
			'btn_shades'            => true,   // .btn--{family}-dark / -light
			'btn_overrides'         => array(), // family => variant => prop => CSS value
			'btn_padding_block'     => '0.75em',
			'btn_padding_inline'    => '1.5em',
			'btn_radius'            => '0.375rem',
			'btn_font_weight'       => '600',
			'btn_font_size'         => '',
			'btn_font_family'       => 'inherit',
			'btn_font_style'        => 'normal',
			'btn_border_width'      => '1px',
			'btn_border_style'      => 'solid',
			'btn_min_width'         => '',
			'btn_width'             => '',
			'btn_line_height'       => '1.1',
			'btn_text_transform'    => 'none',
			'btn_letter_spacing'    => '0',
			'btn_text_align'        => 'center',
			'btn_justify'           => 'center',
			'btn_text_decoration'   => 'none',
			'btn_text_decoration_hover' => 'none',
			'btn_transition'        => '',
			'btn_gap'               => '.5em',
			'btn_mobile_full'       => false,  // full-width buttons on small screens
			'btn_mobile_bp'         => 480,    // px
			// ---- Forms ----
			'base_forms'            => true,
			'field_radius'          => '0.375rem',
			'field_padding'         => '0.6em 0.9em',
			'field_border'          => 'var(--base-ultra-light, #d9dee5)',
			'form_gap'              => 'var(--space-s, 1rem)', // between the fields of a form with .form--light / .form--dark
			'form_btn_family'       => 'primary', // their submit button
			'form_btn_variant'      => 'solid',
			'form_extras'           => true,  // steps and conditional fields in the builder's form
			'form_layouts'          => true,  // .form--columns, .form--inline, .form--compact, .form--card
			'form_fields'           => true,  // slider, star rating, range, input mask, character counter
			'form_dates'            => true,  // date rules
			'form_messages'         => true,  // error messages per rule
			'form_options'          => true,  // choice options from content
			'form_live'             => true,  // live value tag
			'form_limits'           => true,  // limits and submit rules
			'form_actions'          => true,  // update meta and add to cart actions
			'form_presets'          => true,  // form, field and saved presets in the builder
			'form_styles'           => true,  // style presets
			// ---- Form submissions (the builder's own screen, extended) ----
			'subs_extend'           => true,  // master switch: everything below
			'subs_status'           => true,  // read/unread, new count in the menu, statuses, notes
			'subs_columns'          => true,  // choose and reorder columns
			'subs_edit'             => true,  // edit an entry
			'subs_export'           => true,  // export selected rows as CSV or JSON
			'subs_top_menu'         => false, // the screen as a top-level menu item
			'subs_role'             => 'administrator', // minimum role: administrator | editor | author | contributor
			'subs_cleanup'          => false, // delete old submissions automatically
			'subs_days'             => '0',   // after this many days, all forms (0 = only forms with their own number)
			// ---- Cards ----
			'base_cards'            => true,
			'card_selectors'        => '',     // extra selectors styled as cards
			'card_scheme'           => '',     // light | dark: fix the colour scheme inside cards
			'card_background'       => 'var(--white, #fff)',
			'card_text_color'       => '',
			'card_text_size'        => '',
			'card_line_height'      => '',
			'card_padding'          => 'var(--space-m)',
			'card_gap'              => 'calc(var(--content-gap, 1rem) / 2)',
			'card_radius'           => 'var(--radius, .5rem)',
			'card_border_width'     => '1px',
			'card_border_style'     => 'solid',
			'card_border_color'     => 'var(--border-color-dark, #e5e7eb)',
			'card_shadow'           => '',
			'card_heading_size'     => '',
			'card_heading_color'    => '',
			'card_link_color'       => '',
			'card_link_color_hover' => '',
			'card_media_aspect'     => '16/9',
			'card_media_fit'        => 'cover',
			'card_media_radius'     => '',
			'card_avatar_size'      => '48px',
			'card_avatar_radius'    => '50vw',
			'card_avatar_border_width' => '0',
			'card_avatar_border_style' => 'solid',
			'card_avatar_border_color' => 'transparent',
			'card_avatar_aspect'    => '1',
			'card_icon_size'        => '48px',
			'card_icon_background'  => '',
			'card_icon_color'       => '',
			'card_icon_padding'     => '',
			'card_icon_radius'      => '',
			'card_icon_border_width' => '',
			'card_icon_border_style' => '',
			'card_icon_border_color' => '',
			'card_boxed_icons'      => true,
			'card_button_size'      => 'var(--text-s)',
			'card_button_family'    => '',    // buttons inside cards use this family
			'card_concentric'       => false, // inner radius = card radius − padding
			'card_min_radius'       => '4px',
		);
		// Per-heading (h1…h6) and per-text-size overrides, empty = inherit the defaults.
		for ( $i = 1; $i <= 6; $i++ ) {
			foreach ( array_keys( self::TYPE_PROPS ) as $p ) {
				$d[ "h{$i}_$p" ] = '';
			}
		}
		foreach ( Scale_Engine::STEPS as $sz ) {
			foreach ( array_keys( self::TYPE_PROPS ) as $p ) {
				if ( 'font_family' !== $p && 'color' !== $p ) {
					$d[ "text_{$sz}_$p" ] = '';
				}
			}
		}
		return $d;
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( ! is_array( $s['btn_overrides'] ) ) {
			$s['btn_overrides'] = array();
		}
		return Modules::apply( 'components', $s );
	}

	/**
	 * A CSS value safe to drop into a declaration.
	 *
	 * @param mixed $v Raw.
	 * @return string
	 */
	private static function clean( $v ) {
		return trim( str_replace( array( ';', '{', '}', '<', '>' ), '', sanitize_text_field( (string) $v ) ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d     = self::defaults();
		$clean = array();
		foreach ( $d as $k => $v ) {
			if ( is_bool( $v ) ) {
				$clean[ $k ] = ! empty( $in[ $k ] );
			} elseif ( 'form_btn_family' === $k ) {
				$clean[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], self::FAMILIES, true ) ? $in[ $k ] : $v;
			} elseif ( 'form_btn_variant' === $k ) {
				$clean[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], self::BTN_VARIANTS, true ) ? $in[ $k ] : $v;
			} elseif ( 'btn_overrides' === $k ) {
				$clean[ $k ] = self::clean_overrides( isset( $in[ $k ] ) ? $in[ $k ] : array() );
			} elseif ( isset( $in[ $k ] ) && in_array( $k, array( 'ext_not', 'card_selectors', 'content_grid_exclusions' ), true ) ) {
				// Selectors: keep ">" (child combinator).
				$clean[ $k ] = trim( str_replace( array( ';', '{', '}', '<' ), '', sanitize_text_field( $in[ $k ] ) ) );
			} elseif ( isset( $in[ $k ] ) ) {
				$clean[ $k ] = self::clean( $in[ $k ] );
			} else {
				$clean[ $k ] = $v;
			}
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Button overrides: family => variant => prop => value (only known keys).
	 *
	 * @param mixed $in Raw.
	 * @return array
	 */
	private static function clean_overrides( $in ) {
		$out = array();
		foreach ( is_array( $in ) ? $in : array() as $fam => $variants ) {
			$fam = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $fam ) );
			if ( '' === $fam || ! is_array( $variants ) ) {
				continue;
			}
			foreach ( $variants as $variant => $props ) {
				if ( ! in_array( $variant, self::BTN_VARIANTS, true ) || ! is_array( $props ) ) {
					continue;
				}
				foreach ( $props as $p => $val ) {
					$val = self::clean( $val );
					if ( in_array( $p, self::BTN_PROPS, true ) && '' !== $val ) {
						$out[ $fam ][ $variant ][ $p ] = $val;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * "prop:value;" when the value isn't empty.
	 *
	 * @param string $prop  Property.
	 * @param string $value Value.
	 * @return string
	 */
	private static function decl( $prop, $value ) {
		return '' === (string) $value ? '' : $prop . ':' . $value . ';';
	}

	/**
	 * The default colour mapping of a button variant for a family.
	 *
	 * @param string $f       Family.
	 * @param string $variant Variant.
	 * @return array prop => value
	 */
	public static function btn_default( $f, $variant ) {
		$v = function ( $shade ) use ( $f ) {
			return 'var(--' . $f . ( '' === $shade ? '' : '-' . $shade ) . ')';
		};
		switch ( $variant ) {
			case 'dark':
				return array( 'bg' => $v( 'dark' ), 'text' => $v( 'ultra-light' ), 'border' => $v( 'dark' ), 'hover' => $v( 'ultra-dark' ), 'hover_text' => $v( 'ultra-light' ), 'hover_border' => $v( 'ultra-dark' ), 'focus' => $v( 'semi-dark' ) );
			case 'light':
				return array( 'bg' => $v( 'ultra-light' ), 'text' => $v( '' ), 'border' => $v( 'ultra-light' ), 'hover' => $v( 'light' ), 'hover_text' => $v( 'ultra-dark' ), 'hover_border' => $v( 'light' ), 'focus' => $v( 'light' ) );
			case 'outline':
				return array( 'bg' => 'transparent', 'text' => $v( '' ), 'border' => $v( '' ), 'hover' => $v( 'hover' ), 'hover_text' => $v( 'ultra-light' ), 'hover_border' => $v( 'hover' ), 'focus' => $v( 'light' ) );
			case 'outline-dark':
				return array( 'bg' => 'transparent', 'text' => $v( 'dark' ), 'border' => $v( 'dark' ), 'hover' => $v( 'dark' ), 'hover_text' => $v( 'ultra-light' ), 'hover_border' => $v( 'dark' ), 'focus' => $v( 'light' ) );
			case 'outline-light':
				return array( 'bg' => 'transparent', 'text' => $v( 'light' ), 'border' => $v( 'light' ), 'hover' => $v( 'light' ), 'hover_text' => $v( 'dark' ), 'hover_border' => $v( 'light' ), 'focus' => $v( 'light' ) );
			default:
				return array( 'bg' => $v( '' ), 'text' => $v( 'ultra-light' ), 'border' => $v( '' ), 'hover' => $v( 'hover' ), 'hover_text' => $v( 'ultra-light' ), 'hover_border' => $v( 'hover' ), 'focus' => $v( 'light' ) );
		}
	}

	/**
	 * Custom properties for a button variant (defaults + overrides).
	 *
	 * @param array  $s       Settings.
	 * @param string $f       Family.
	 * @param string $variant Variant.
	 * @return string
	 */
	private static function btn_vars( $s, $f, $variant ) {
		$out = array();
		foreach ( self::btn_var_map( $s, $f, $variant ) as $n => $v ) {
			$out[] = "--$n:$v";
		}
		return implode( ';', $out );
	}

	/**
	 * The custom properties of a button variant as name (without --) => value.
	 *
	 * @param array  $s       Settings.
	 * @param string $f       Family.
	 * @param string $variant Variant.
	 * @return array
	 */
	public static function btn_var_map( $s, $f, $variant ) {
		$m = self::btn_default( $f, $variant );
		if ( isset( $s['btn_overrides'][ $f ][ $variant ] ) ) {
			$m = array_merge( $m, $s['btn_overrides'][ $f ][ $variant ] );
		}
		$out = array(
			'btn-background'         => $m['bg'],
			'btn-background-hover'   => $m['hover'],
			'btn-text-color'         => $m['text'],
			'btn-text-color-hover'   => $m['hover_text'],
			'btn-border-color'       => $m['border'],
			'btn-border-color-hover' => $m['hover_border'],
			'focus-color'            => $m['focus'],
		);
		if ( 0 === strpos( $variant, 'outline' ) ) {
			$out['btn-border-width'] = '2px';
		}
		return $out;
	}

	/**
	 * Every button style a mixin can ask for: "primary", "primary-dark",
	 * "primary-light", "primary.btn--outline" (also "primary--outline"),
	 * "primary-dark.btn--outline", "primary-light.btn--outline" … for all families.
	 *
	 * @return array style => [ var => value ]
	 */
	public static function btn_style_map() {
		$s   = self::settings();
		$out = array();
		foreach ( self::FAMILIES as $f ) {
			$out[ $f ]                         = self::btn_var_map( $s, $f, 'solid' );
			$out[ "$f-dark" ]                  = self::btn_var_map( $s, $f, 'dark' );
			$out[ "$f-light" ]                 = self::btn_var_map( $s, $f, 'light' );
			$out[ "$f.btn--outline" ]          = self::btn_var_map( $s, $f, 'outline' );
			$out[ "$f--outline" ]              = $out[ "$f.btn--outline" ];
			$out[ "$f-dark.btn--outline" ]     = self::btn_var_map( $s, $f, 'outline-dark' );
			$out[ "$f-light.btn--outline" ]    = self::btn_var_map( $s, $f, 'outline-light' );
		}
		return $out;
	}

	/**
	 * The declarations every button shares (also the skip link and the btn() mixin).
	 *
	 * @param array|null $s Settings.
	 * @return string
	 */
	public static function btn_look( $s = null ) {
		$s = null === $s ? self::settings() : $s;
		return 'display:var(--btn-display, inline-flex);align-items:var(--btn-align-items, center);justify-content:var(--btn-justify-content, center);' . ( '' !== $s['btn_gap'] ? 'gap:var(--btn-gap);' : '' ) . 'text-align:var(--btn-text-align, center);'
			. 'padding-block:var(--btn-padding-block);padding-inline:var(--btn-padding-inline);inline-size:var(--btn-width, auto);min-inline-size:var(--btn-min-width);'
			. 'background:var(--btn-background);color:var(--btn-text-color);font-family:var(--btn-font-family);font-size:var(--btn-font-size, var(--text-m));font-weight:var(--btn-font-weight);font-style:var(--btn-font-style);'
			. 'line-height:var(--btn-line-height);text-transform:var(--btn-text-transform);letter-spacing:var(--btn-letter-spacing);text-decoration:var(--btn-text-decoration);'
			. 'border-width:var(--btn-border-width);border-style:var(--btn-border-style);border-color:var(--btn-border-color);border-radius:var(--btn-border-radius);'
			. 'transition:var(--btn-transition, var(--transition, background-color .15s, color .15s, border-color .15s))';
	}

	/**
	 * Declarations of a heading level (heading-style() mixin): the shared heading
	 * styles, the level's size and its own overrides.
	 *
	 * @param int $lvl 1 to 6.
	 * @return string
	 */
	public static function heading_decls( $lvl ) {
		$s    = self::settings();
		$lvl  = max( 1, min( 6, (int) $lvl ) );
		$step = array( 1 => 'xxl', 2 => 'xl', 3 => 'l', 4 => 'm', 5 => 's', 6 => 'xs' );
		$use  = function ( $prop, $key, $var ) use ( $s ) {
			return '' === (string) $s[ $key ] ? '' : "$prop:var($var);";
		};
		$out = $use( 'font-family', 'heading_font_family', '--heading-font-family' )
			. $use( 'font-weight', 'heading_font_weight', '--heading-font-weight' )
			. self::decl( 'font-style', $s['heading_font_style'] )
			. self::decl( 'text-transform', $s['heading_text_transform'] )
			. $use( 'color', 'heading_color', '--heading-color' )
			. $use( 'line-height', 'heading_line_height', '--heading-line-height' )
			. self::decl( 'letter-spacing', $s['heading_letter_spacing'] )
			. $use( 'text-wrap', 'heading_text_wrap', '--heading-text-wrap' )
			. "font-size:var(--h$lvl, var(--text-{$step[ $lvl ]}));";
		foreach ( self::TYPE_PROPS as $p => $prop ) {
			if ( '' !== $s[ "h{$lvl}_$p" ] ) {
				$out .= "$prop:var(--h$lvl-" . str_replace( '_', '-', $p ) . ');';
			}
		}
		return $out;
	}

	/**
	 * Declarations of a text size (text-style() mixin): body text styles at that size.
	 *
	 * @param string $sz xs…xxl.
	 * @return string
	 */
	public static function text_decls( $sz ) {
		$s   = self::settings();
		$sz  = in_array( $sz, Scale_Engine::STEPS, true ) ? $sz : 'm';
		$use = function ( $prop, $key, $var ) use ( $s ) {
			return '' === (string) $s[ $key ] ? '' : "$prop:var($var);";
		};
		$out = $use( 'font-family', 'text_font_family', '--text-font-family' ) . $use( 'font-weight', 'text_font_weight', '--text-font-weight' )
			. $use( 'line-height', 'text_line_height', '--text-line-height' ) . $use( 'text-wrap', 'text_wrap', '--text-text-wrap' )
			. "font-size:var(--text-$sz);";
		foreach ( self::TYPE_PROPS as $p => $prop ) {
			if ( isset( $s[ "text_{$sz}_$p" ] ) && '' !== $s[ "text_{$sz}_$p" ] ) {
				$out .= ( 'max_width' === $p ? 'max-width' : $prop ) . ":var(--text-$sz-" . str_replace( '_', '-', $p ) . ');';
			}
		}
		return $out;
	}

	/**
	 * Enabled button families.
	 *
	 * @param array $s Settings.
	 * @return string[]
	 */
	public static function btn_families( $s ) {
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $s['btn_families'] ) ) ) );
		return array_values( array_filter( array_map( function ( $f ) {
			return preg_replace( '/[^a-z0-9\-]/', '', $f );
		}, $list ) ) );
	}

	/**
	 * Build the base-styles CSS, the button and card parts, and the class names.
	 *
	 * @param bool $for_editor Block editor copy: no boxed page, and heading max
	 *                         widths skip the editor's blocks (title included).
	 * @return array [ 'css' => string, 'buttons' => string, 'cards' => string, 'classes' => string[] ]
	 */
	public static function build( $for_editor = false ) {
		$s       = self::settings();
		$css     = array();
		$btn     = array();
		$cards   = array();
		$classes = array();
		$sec     = self::SECTION;
		$root    = array();

		// ---- Layout ----
		if ( ! empty( $s['base_layout'] ) ) {
			$vw     = Scale_Engine::settings();
			$gutter = Scale_Engine::fluid( (float) $s['gutter_min'], (float) $s['gutter_max'], (float) $vw['min_vw'], (float) $vw['max_vw'] );
			$root[] = '--gutter:' . $gutter . ';--section-padding-block:' . $s['section_padding_block'] . ';--container-gap:' . $s['container_gap'] . ';--content-gap:' . $s['content_gap']
				. ( '' !== $s['grid_gap_value'] ? ';--grid-gap:' . $s['grid_gap_value'] : '' );
			$css[]  = ':where(.brxe-section,.section){padding-inline:var(--gutter)}';
			$css[]  = ( ! empty( $s['container_width'] ) ? '.container,.brxe-container,' : '.container,' ) . '.content-width{inline-size:100%;max-inline-size:min(var(--content-width), 100%);margin-inline:auto}';
			$css[]  = '.content-width--safe{inline-size:100%;max-inline-size:var(--content-width-safe, var(--content-width));margin-inline:auto}';
			array_push( $classes, 'container', 'content-width', 'content-width--safe' );
			// Top-level sections: column flow, gutters, default block padding.
			$css[] = $sec . '{display:flex;flex-direction:column;padding-inline:var(--gutter)' . ( ! empty( $s['section_padding'] ) ? ';padding-block:var(--section-padding-block)' : '' ) . '}';
			if ( ! empty( $s['auto_container_gap'] ) ) {
				$css[] = $sec . '{gap:var(--container-gap)}';
			}
			if ( ! empty( $s['auto_content_gap'] ) ) {
				$css[] = $sec . '>:where(div,ul,ol),:where(.brxe-block),:where(.brxe-div){gap:var(--content-gap)}';
			}
			if ( ! empty( $s['auto_grid_gap'] ) ) {
				$css[] = 'section>div:where([class*="grid--"]),:where([class*="grid--"]){gap:var(--grid-gap)}';
			}
			// Section spacing classes: sizes, desktop→mobile transitions, none; header spacing.
			$steps = Scale_Engine::STEPS;
			foreach ( $steps as $i => $step ) {
				$css[]     = ".section--$step{padding-block:var(--section-space-$step);padding-inline:var(--gutter)}";
				$classes[] = "section--$step";
				for ( $j = $i - 1; $j >= 0; $j-- ) {
					$to        = $steps[ $j ];
					$css[]     = ".section--$step-to-$to{padding-block:var(--section-space-$step-to-$to);padding-inline:var(--gutter)}";
					$classes[] = "section--$step-to-$to";
				}
				$css[]     = ".header--$step{padding-block:var(--space-$step);padding-inline:var(--gutter)}";
				$classes[] = "header--$step";
			}
			$css[] = '.section--none{padding-block:0;padding-inline:var(--gutter)}';
			$classes[] = 'section--none';
			// Gap utilities (also expose --row-gap / --col-gap for grids).
			foreach ( array( 'none' => '0', 'container' => 'var(--container-gap)', 'content' => 'var(--content-gap)', 'grid' => 'var(--grid-gap)' ) as $g => $val ) {
				$cls       = 'none' === $g ? 'gap--none' : "$g-gap";
				$css[]     = ".$cls{--row-gap:$val;--col-gap:$val;gap:$val}";
				$classes[] = $cls;
			}
			// Boxed layout: the whole page is a centred box on a device background.
			if ( 'boxed' === $s['layout_type'] && ! $for_editor ) {
				$bw     = round( ( (float) $s['boxed_width'] ) / Defaults::root_px(), 4 );
				$root[] = '--boxed-width:' . $bw . 'rem;--body-max-width:var(--boxed-width)';
				$css[]  = 'html{background-color:var(--device-bg-color, ' . $s['device_bg'] . ')}';
				$css[]  = 'body{inline-size:100%;max-inline-size:var(--body-max-width);margin-inline:auto!important;margin-block-start:' . ( '' !== $s['body_margin_top'] ? $s['body_margin_top'] : '0' ) . '!important;'
					. self::decl( 'box-shadow', $s['body_shadow'] ) . self::decl( 'border-width', $s['body_border_width'] ) . self::decl( 'border-style', $s['body_border_style'] )
					. self::decl( 'border-color', $s['body_border_color'] ) . self::decl( 'border-radius', $s['body_border_radius'] ) . '}';
				$css[]  = 'header.sticky>*,#brx-header.sticky.sticky>*{inline-size:var(--boxed-width);margin-inline:auto}';
			}
			// Content grid: content column centred, with feature / feature-max / full
			// columns to break out into. Used by .content-grid and (optionally) sections.
			if ( ! empty( $s['content_grid'] ) || ! empty( $s['content_grid_sections'] ) ) {
				$root[]  = '--feature-width:' . $s['feature_width'] . ';--feature-max-width:' . $s['feature_max_width'];
				$breaks  = '.content--feature,.content--feature-max,.content--full,.content--full-safe';
				$grid    = '--full:minmax(var(--gutter), 1fr);padding-inline:0;column-gap:0!important;display:grid!important;'
					. 'grid-template-columns:[full-start] var(--full) [feature-max-start] minmax(0, var(--feature-max-width, 100px)) [feature-start] minmax(0, var(--feature-width, 50px))'
					. ' [content-start] min(var(--content-width), 100% - var(--gutter) * 2) [content-end] minmax(0, var(--feature-width, 50px)) [feature-end] minmax(0, var(--feature-max-width, 100px)) [feature-max-end] var(--full) [full-end]';
				$targets = array();
				if ( ! empty( $s['content_grid'] ) ) {
					$targets[] = ':where(.content-grid)';
					$classes[] = 'content-grid';
				}
				if ( ! empty( $s['content_grid_sections'] ) ) {
					$ex        = trim( str_replace( '"', "'", (string) $s['content_grid_exclusions'] ) );
					$targets[] = ':where(section:not(section section))' . ( '' !== $ex ? ':where(:not(' . $ex . '))' : '' );
					if ( '' !== $ex ) {
						$css[] = ':where(section:not(section section)):where(' . $ex . '){padding-inline:var(--gutter)}';
					}
				}
				foreach ( $targets as $t ) {
					$css[] = $t . '{' . $grid . '}';
					$css[] = $t . '>:not(' . $breaks . '){inline-size:100%;max-inline-size:100%}';
					$css[] = $t . '>:where(:not(' . $breaks . ')){grid-column:content}';
				}
				$css[] = '.content--feature{grid-column:feature}.content--feature-max{grid-column:feature-max}.content--full,.content--full-safe{grid-column:full}.content--full-safe{padding-inline:var(--gutter)}';
				$css[] = ':is(' . $breaks . '):not(.content-grid--off :is(' . $breaks . ')){inline-size:100%!important;max-inline-size:100%}';
				$css[] = ':is(' . $breaks . ') :is(figure img,picture img){inline-size:100%}';
				$css[] = '.content-grid:is([class*="padding--"],[class*="section--"],[class*="header--"]){padding-inline:0}';
				if ( ! empty( $s['content_grid_sections'] ) ) {
					// Spacing classes set the block padding only, the grid owns the sides.
					$css[] = ':where(section:not(section section))' . ( '' !== $ex ? ':where(:not(' . $ex . '))' : '' ) . ':is([class*="section--"],[class*="header--"]){padding-inline:0}';
				}
				$css[] = '.content-grid>.is-bg,:where(section:not(section section))>.is-bg{grid-column:full}';
				array_push( $classes, 'content--feature', 'content--feature-max', 'content--full', 'content--full-safe', 'content-grid--off' );
			}
		}

		// ---- Typography: body + headings ----
		if ( ! empty( $s['base_root'] ) ) {
			$vars = array(
				'--text-font-family'    => $s['text_font_family'],
				'--text-font-weight'    => $s['text_font_weight'],
				'--text-color'          => $s['text_color'],
				'--text-line-height'    => $s['text_line_height'],
				'--text-text-wrap'      => $s['text_wrap'],
				'--body-bg-color'       => $s['body_bg'],
				'--heading-font-family' => $s['heading_font_family'],
				'--heading-font-weight' => $s['heading_font_weight'],
				'--heading-line-height' => $s['heading_line_height'],
				'--heading-text-wrap'   => $s['heading_text_wrap'],
				'--heading-color'       => $s['heading_color'],
			);
			foreach ( $vars as $n => $v ) {
				if ( '' !== (string) $v ) {
					$root[] = "$n:$v";
				}
			}
			$use = function ( $prop, $key, $var ) use ( $s ) {
				return '' === (string) $s[ $key ] ? '' : "$prop:var($var);";
			};
			$css[] = ':where(body){font-size:var(--text-m);overflow-x:clip;'
				. $use( 'font-family', 'text_font_family', '--text-font-family' )
				. $use( 'font-weight', 'text_font_weight', '--text-font-weight' )
				. self::decl( 'font-style', $s['text_font_style'] )
				. self::decl( 'letter-spacing', $s['text_letter_spacing'] )
				. self::decl( 'text-transform', $s['text_transform'] )
				. $use( 'color', 'text_color', '--text-color' )
				. $use( 'background-color', 'body_bg', '--body-bg-color' )
				. $use( 'line-height', 'text_line_height', '--text-line-height' )
				. $use( 'text-wrap', 'text_wrap', '--text-text-wrap' ) . '}';
			if ( '' !== $s['text_line_height'] ) {
				// Paragraphs compute their own line height (so ex/em follow their size).
				$css[] = ':where(p){line-height:var(--text-line-height, calc(6px + 2ex))}';
			}
			// Headings + .h1….h6: element specificity (beats builder theme styles like
			// Bricks' h1…h6{font-family}); margins are left to smart spacing.
			$hsel  = 'h1,h2,h3,h4,h5,h6,:where(.h1,.h2,.h3,.h4,.h5,.h6,.brxe-heading)';
			$css[] = $hsel . '{'
				. $use( 'font-family', 'heading_font_family', '--heading-font-family' )
				. $use( 'font-weight', 'heading_font_weight', '--heading-font-weight' )
				. self::decl( 'font-style', $s['heading_font_style'] )
				. self::decl( 'text-transform', $s['heading_text_transform'] )
				. $use( 'color', 'heading_color', '--heading-color' )
				. $use( 'line-height', 'heading_line_height', '--heading-line-height' )
				. self::decl( 'letter-spacing', $s['heading_letter_spacing'] )
				. $use( 'text-wrap', 'heading_text_wrap', '--heading-text-wrap' ) . '}';
			$fallback = array( 1 => 'xxl', 2 => 'xl', 3 => 'l', 4 => 'm', 5 => 's', 6 => 'xs' );
			foreach ( $fallback as $lvl => $step ) {
				$own = '';
				$max = '';
				foreach ( self::TYPE_PROPS as $p => $prop ) {
					$val = $s[ "h{$lvl}_$p" ];
					if ( '' !== $val ) {
						$root[] = "--h$lvl-" . str_replace( '_', '-', $p ) . ":$val";
						if ( $for_editor && 'max_width' === $p ) {
							$max = "$prop:var(--h$lvl-max-width);";
						} else {
							$own .= "$prop:var(--h$lvl-" . str_replace( '_', '-', $p ) . ');';
						}
					}
				}
				$css[]     = "h$lvl,.h$lvl{font-size:var(--h$lvl, var(--text-$step));$own}";
				if ( '' !== $max ) {
					// Block editor: the title and heading blocks keep the editor's own width.
					$css[] = ':is(h' . $lvl . ',.h' . $lvl . '):not(.wp-block){' . $max . '}';
				}
				$classes[] = "h$lvl";
			}
			// Text-size classes (.text--m …): body text styles at a scale size.
			if ( ! empty( $s['text_size_classes'] ) ) {
				$all   = array();
				foreach ( Scale_Engine::STEPS as $sz ) {
					$all[] = ".text--$sz";
				}
				$css[] = ':is(' . implode( ',', $all ) . '){' . $use( 'font-family', 'text_font_family', '--text-font-family' ) . $use( 'font-weight', 'text_font_weight', '--text-font-weight' )
					. $use( 'line-height', 'text_line_height', '--text-line-height' ) . $use( 'text-wrap', 'text_wrap', '--text-text-wrap' ) . '}';
				foreach ( array_reverse( Scale_Engine::STEPS ) as $sz ) {
					$own = '';
					$max = '';
					foreach ( self::TYPE_PROPS as $p => $prop ) {
						if ( ! isset( $s[ "text_{$sz}_$p" ] ) || '' === $s[ "text_{$sz}_$p" ] ) {
							continue;
						}
						$var    = "--text-$sz-" . str_replace( '_', '-', $p );
						$root[] = "$var:" . $s[ "text_{$sz}_$p" ];
						if ( 'max_width' === $p ) {
							$max = "max-width:var($var)";
						} else {
							$own .= "$prop:var($var);";
						}
					}
					$css[]     = ".text--$sz{font-size:var(--text-$sz);$own}";
					if ( '' !== $max ) {
						// Only text elements get the measure (not containers).
						$css[] = ".text--$sz:is(p,li,blockquote,h1,h2,h3,h4,h5,h6,span,strong,cite){" . $max . '}';
					}
					$classes[] = "text--$sz";
				}
			}
		}

		// ---- Links (buttons excluded) ----
		if ( ! empty( $s['base_links'] ) ) {
			$root[] = '--link-color:' . $s['link_color'] . ';--link-color-hover:' . $s['link_color_hover'];
			foreach ( array( 'link_decoration' => '--link-decoration', 'link_decoration_hover' => '--link-decoration-hover', 'link_weight' => '--link-weight', 'link_underline_offset' => '--link-underline-offset', 'link_transition' => '--link-transition' ) as $k => $var ) {
				if ( '' !== $s[ $k ] ) {
					$root[] = "$var:" . $s[ $k ];
				}
			}
			$a     = 'body a:where(:not([class*="btn--"],.btn,.bricks-button))';
			$css[] = $a . '{color:var(--link-color);'
				. ( '' !== $s['link_weight'] ? 'font-weight:var(--link-weight);' : '' )
				. ( '' !== $s['link_decoration'] ? 'text-decoration:var(--link-decoration);' : '' )
				. self::decl( 'text-decoration-color', $s['link_decoration_color'] )
				. self::decl( 'text-decoration-thickness', $s['link_decoration_thickness'] )
				. ( '' !== $s['link_underline_offset'] ? 'text-underline-offset:var(--link-underline-offset);' : '' )
				. 'transition:var(--link-transition, var(--transition, color .2s))}';
			$css[] = '@media (hover:hover) and (pointer:fine){' . $a . ':hover{color:var(--link-color-hover);'
				. ( '' !== $s['link_decoration_hover'] ? 'text-decoration:var(--link-decoration-hover);' : '' )
				. self::decl( 'text-decoration-color', $s['link_decoration_color_hover'] ) . '}}';
		}

		// ---- External-link indicator: a glyph after (or before) new-tab links,
		// announced to screen readers as the label instead of the glyph. ----
		if ( ! empty( $s['ext_links'] ) && '' !== trim( (string) $s['ext_indicator'] ) ) {
			$str    = function ( $v ) {
				return str_replace( array( '"', "\n", "\r" ), '', (string) $v );
			};
			$start  = 'start' === $s['ext_position'];
			$pseudo = $start ? '::before' : '::after';
			$not    = trim( (string) $s['ext_not'] );
			$sel    = 'a[target="_blank"]' . ( '' !== $not ? ':where(:not(' . str_replace( '"', "'", $not ) . '))' : '' );
			$label  = trim( str_replace( '\\', '', $str( $s['ext_label'] ) ) );
			$valign = array( 'start' => 'text-top', 'center' => 'middle', 'end' => 'text-bottom' );
			$root[] = '--external-link-indicator-gap:' . $s['ext_gap'] . ';--external-link-indicator-size:' . $s['ext_size'] . ';--external-link-indicator-weight:' . $s['ext_weight']
				. ';--external-link-indicator-offset:' . $s['ext_offset'] . ';--external-link-indicator-color:' . $s['ext_color'] . ';--external-link-indicator-color-hover:' . $s['ext_color_hover']
				. ';--external-link-indicator-alignment:' . $s['ext_align'];
			$btn[]  = $sel . $pseudo . '{content:"' . $str( $s['ext_indicator'] ) . '"' . ( '' !== $label ? ' / "' . $label . '"' : '' )
				. ';display:inline-block;font-size:var(--external-link-indicator-size);font-weight:var(--external-link-indicator-weight);line-height:1'
				. ';translate:var(--external-link-indicator-offset);color:var(--external-link-indicator-color);align-self:var(--external-link-indicator-alignment)'
				. ';vertical-align:' . ( isset( $valign[ $s['ext_align'] ] ) ? $valign[ $s['ext_align'] ] : 'baseline' )
				. ';margin-inline-' . ( $start ? 'end' : 'start' ) . ':var(--external-link-indicator-gap);text-decoration:none}';
			$btn[]  = $sel . ':hover' . $pseudo . '{color:var(--external-link-indicator-color-hover)}';
		}

		// ---- Buttons ----
		if ( ! empty( $s['base_buttons'] ) ) {
			$bv = array(
				'--btn-padding-block'  => $s['btn_padding_block'],
				'--btn-padding-inline' => $s['btn_padding_inline'],
				'--btn-border-radius'  => $s['btn_radius'],
				'--btn-radius'         => '' !== $s['btn_radius'] ? 'var(--btn-border-radius)' : '',
				'--btn-font-weight'    => $s['btn_font_weight'],
				'--btn-font-size'      => $s['btn_font_size'],
				'--btn-font-family'    => $s['btn_font_family'],
				'--btn-font-style'     => $s['btn_font_style'],
				'--btn-border-width'   => '' === $s['btn_border_width'] ? '1px' : $s['btn_border_width'],
				'--btn-border-style'   => '' === $s['btn_border_style'] ? 'solid' : $s['btn_border_style'],
				'--btn-line-height'    => '' === $s['btn_line_height'] ? '1.1' : $s['btn_line_height'],
				'--btn-min-width'      => $s['btn_min_width'],
				'--btn-width'          => $s['btn_width'],
				'--btn-text-transform' => $s['btn_text_transform'],
				'--btn-letter-spacing' => $s['btn_letter_spacing'],
				'--btn-text-align'     => $s['btn_text_align'],
				'--btn-justify-content' => $s['btn_justify'],
				'--btn-text-decoration' => $s['btn_text_decoration'],
				'--btn-text-decoration-hover' => $s['btn_text_decoration_hover'],
				'--btn-transition'     => $s['btn_transition'],
				'--btn-gap'            => $s['btn_gap'],
			);
			foreach ( $bv as $n => $v ) {
				if ( '' !== (string) $v ) {
					$root[] = "$n:$v";
				}
			}
			// Base treatment: any btn--* class (except btn--none), like ACSS, plus
			// the .btn class and Gutenberg button blocks given a btn--* class.
			$sel = array( '.btn', '[class*="btn--"]:where(:not(.btn--none,.wp-block-button))', '[class*="btn--"].wp-block-button>.wp-block-button__link' );
			if ( ! empty( $s['control_bricks_buttons'] ) ) {
				// Opt-in: every Bricks / Gutenberg / native button (zero specificity).
				$sel[] = ':where(.brxe-button.bricks-button,.wp-block-button__link,button:not([class]),input[type="submit"])';
			}
			$list  = implode( ',', $sel );
			// The shared button declarations (also used by the skip link).
			$look  = self::btn_look( $s );
			$btn[] = $list . '{' . $look . ';cursor:pointer}';
			// Skip link: a primary button parked above the viewport until focused.
			$btn[] = '.skip-link{position:absolute!important;inset-block-start:1rem;inset-inline-start:1rem;transform:translateY(-250%);--btn-min-width:fit-content;z-index:100;' . $look . ';' . self::btn_vars( $s, 'primary', 'solid' ) . '}';
			$btn[] = '@media (hover:hover) and (pointer:fine){.skip-link:hover{background:var(--btn-background-hover);color:var(--btn-text-color-hover);border-color:var(--btn-border-color-hover);text-decoration:var(--btn-text-decoration-hover)}}';
			$btn[] = '.skip-link:focus-visible{transform:translateY(0);outline-style:solid;outline-color:var(--focus-color);outline-width:var(--focus-width, 2px);outline-offset:var(--focus-offset, 2px)}';
			$hov   = implode( ',', array_map( function ( $x ) {
				return $x . ':hover';
			}, $sel ) );
			$btn[] = '@media (hover:hover) and (pointer:fine){' . $hov . '{background:var(--btn-background-hover);color:var(--btn-text-color-hover);border-color:var(--btn-border-color-hover);text-decoration:var(--btn-text-decoration-hover)}}';
			$btn[] = implode( ',', array_map( function ( $x ) {
				return $x . ':where(:focus-visible)';
			}, $sel ) ) . '{outline-style:solid;outline-color:var(--focus-color);outline-width:var(--focus-width, 2px);outline-offset:var(--focus-offset, 2px)}';
			if ( ! empty( $s['btn_mobile_full'] ) ) {
				$bp    = round( max( 200, (float) $s['btn_mobile_bp'] ) / 16, 4 );
				$btn[] = '@media (max-width:' . $bp . 'rem){' . $list . '{--btn-width:100%;--btn-min-width:100%}}';
			}
			// .btn = the default family; btn--{family} + -dark/-light + outline.
			$def   = preg_replace( '/[^a-z0-9-]/', '', (string) $s['btn_family'] );
			$btn[] = '.btn{' . self::btn_vars( $s, '' === $def ? 'primary' : $def, 'solid' ) . '}';
			$classes[] = 'btn';
			foreach ( self::btn_families( $s ) as $fam ) {
				$btn[]     = ".btn--$fam{" . self::btn_vars( $s, $fam, 'solid' ) . '}';
				$classes[] = "btn--$fam";
				if ( ! empty( $s['btn_shades'] ) ) {
					$btn[] = ".btn--$fam-dark{" . self::btn_vars( $s, $fam, 'dark' ) . '}';
					$btn[] = ".btn--$fam-light{" . self::btn_vars( $s, $fam, 'light' ) . '}';
					array_push( $classes, "btn--$fam-dark", "btn--$fam-light" );
				}
				if ( ! empty( $s['btn_outline'] ) ) {
					$btn[] = ".btn--$fam.btn--outline,.btn--$fam--outline{" . self::btn_vars( $s, $fam, 'outline' ) . '}';
					$classes[] = "btn--$fam--outline";
					if ( ! empty( $s['btn_shades'] ) ) {
						$btn[] = ".btn--$fam-dark.btn--outline{" . self::btn_vars( $s, $fam, 'outline-dark' ) . '}';
						$btn[] = ".btn--$fam-light.btn--outline{" . self::btn_vars( $s, $fam, 'outline-light' ) . '}';
					}
				}
			}
			// A bare .btn--outline (family outline rules above are more specific).
			$btn[] = '.btn--outline{--btn-background:transparent;--btn-text-color:currentColor;--btn-border-color:currentColor;--btn-border-width:2px}';
			$btn[] = '.btn--none{background:none;border:none}';
			array_push( $classes, 'btn--outline', 'btn--none', 'unrelate' );
			// Buttons inside a background context use that context's family.
			$sch = Scheme::settings();
			if ( ! empty( $sch['assignments'] ) ) {
				foreach ( Scheme::CONTEXTS as $ctx ) {
					$fam = $sch[ 'ctx_' . str_replace( '-', '_', $ctx ) . '_button' ];
					if ( '' !== $fam ) {
						$btn[] = ".bg--$ctx [class*=\"btn--\"]:not(.unrelate){" . self::btn_vars( $s, $fam, 'solid' ) . '}';
						$btn[] = ".bg--$ctx [class*=\"btn--\"]:not(.unrelate).btn--outline{" . self::btn_vars( $s, $fam, 'outline' ) . '}';
					}
				}
			}
			// Bricks button "Style" presets → TokenForge button families (solid + outline).
			if ( ! empty( $s['bricks_button_styles'] ) ) {
				$bricks = array();
				foreach ( array( 'primary', 'secondary', 'info', 'success', 'warning', 'danger' ) as $fam ) {
					$bricks[] = ".brxe-button.bricks-button.bricks-background-$fam";
					$bricks[] = ".brxe-button.bricks-button.outline.bricks-color-$fam";
					$btn[]    = ":where(.brxe-button.bricks-button.bricks-background-$fam){" . self::btn_vars( $s, $fam, 'solid' ) . '}';
					$btn[]    = ":where(.brxe-button.bricks-button.outline.bricks-color-$fam){" . self::btn_vars( $s, $fam, 'outline' ) . '}';
				}
				// …and a framework family picked in Bricks' Style dropdown, which Bricks
				// outputs as .bricks-background-btn--{family}.
				foreach ( self::FAMILIES as $fam ) {
					$bricks[] = ".brxe-button.bricks-button.bricks-background-btn--$fam";
					$btn[]    = ":where(.brxe-button.bricks-button.bricks-background-btn--$fam){" . self::btn_vars( $s, $fam, 'solid' ) . '}';
				}
				$bsel  = implode( ',', $bricks );
				$btn[] = ':where(' . $bsel . '){display:inline-flex;align-items:center;justify-content:center;' . ( '' !== $s['btn_gap'] ? 'gap:var(--btn-gap);' : '' ) . 'padding:var(--btn-padding-block) var(--btn-padding-inline);border-radius:var(--btn-border-radius);font-weight:var(--btn-font-weight);line-height:var(--btn-line-height);'
					. 'font-size:var(--btn-font-size, inherit);min-inline-size:var(--btn-min-width);background:var(--btn-background);color:var(--btn-text-color);border:var(--btn-border-width) var(--btn-border-style, solid) var(--btn-border-color, var(--btn-background))}';
				$btn[] = ':where(' . str_replace( ',', ':hover,', $bsel ) . ':hover){background:var(--btn-background-hover);color:var(--btn-text-color-hover);border-color:var(--btn-border-color-hover, var(--btn-background-hover))}';
			}
			// Sizes (padding is em-based, so it scales with the text size).
			foreach ( Scale_Engine::STEPS as $sz ) {
				$btn[]     = ".btn--$sz{--btn-font-size:var(--text-$sz)}";
				$classes[] = "btn--$sz";
			}
		}

		// ---- Forms: native + Bricks fields ----
		if ( ! empty( $s['base_forms'] ) ) {
			$field_sel = 'input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),textarea,select,.brxe-form input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),.brxe-form textarea,.brxe-form select';
			if ( Woo::active() ) {
				// WooCommerce block fields and the builder's shop fields have floating
				// labels sized by their own CSS: leave them alone (the WooCommerce
				// section only recolours them).
				// No commas inside: the :focus rule below splits this list on commas.
				$field_sel = 'input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="reset"]):not([type="image"]):not([type="hidden"]):not(:where(.wc-block-components-text-input input)):not(:where(.wc-block-components-quantity-selector input)):not(:where(.bricks-floating-label input)),'
					. 'textarea:not(:where(.wc-block-components-textarea)):not(:where(.bricks-floating-label textarea)),select:not(:where(.wc-blocks-components-select__select)):not(:where(.bricks-floating-label select)),.brxe-form input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="reset"]):not([type="image"]):not([type="hidden"]),.brxe-form textarea,.brxe-form select';
			}
			$css[] = $field_sel . '{'
				. 'width:100%;padding:' . $s['field_padding'] . ';'
				. 'border:1px solid ' . $s['field_border'] . ';'
				. 'border-radius:' . $s['field_radius'] . ';'
				. 'background:var(--field-bg, #fff);color:var(--field-color, var(--base, #1a1a1a));'
				. 'font-size:var(--text-m);line-height:1.4'
				. '}';
			$css[] = str_replace( ',', ':focus,', $field_sel ) . ':focus{outline:var(--focus-width, 2px) solid var(--focus-color, var(--action, #2563eb));outline-offset:1px;border-color:var(--focus-color, var(--action, #2563eb))}';
			$css[] = 'label{display:inline-block;margin-bottom:.35em;font-weight:500}';
			// Labels the builder hides on purpose (a range slider has its values shown instead) stay hidden.
			$css[] = '.double-slider-wrap label{display:none}';
			$css[] = '.form--light{--field-bg:#fff;--field-color:var(--base, #1a1a1a);color-scheme:light}';
			$css[] = '.form--dark{--field-bg:var(--base-dark, #1a1a1a);--field-color:#fff;color-scheme:dark}';
			// The builder's form with a form class (on the form or around it): even spacing,
			// calm labels, messages in the status colours and the submit button in your button style.
			// Settings made on the form element in the builder still win.
			$fc    = ':is(.form--light,.form--dark)';
			$form  = ':is(.brxe-form:is(.form--light,.form--dark),:is(.form--light,.form--dark) .brxe-form)';
			$css[] = $form . '{row-gap:' . $s['form_gap'] . '}';
			$css[] = $form . ' .form-group{padding:0}';
			$css[] = $form . ' :is(.form-group > label,.form-group > .label){margin:0 0 .4em;color:inherit;font-size:var(--text-s, .875rem);font-weight:600;letter-spacing:normal;text-transform:none}';
			$css[] = $form . ' .options-wrapper{display:grid;gap:.4em}';
			$css[] = $form . ' .options-wrapper :is(input[type="checkbox"],input[type="radio"]){accent-color:var(--primary)}';
			$css[] = $form . ' .submit-button-wrapper{width:auto;margin-top:.25em}';
			$css[] = $form . ' .message{border-radius:' . $s['field_radius'] . ';background-color:var(--info-ultra-light);color:var(--info-ultra-dark, var(--text-dark))}';
			$css[] = $form . ' .message.success{background-color:var(--success-ultra-light);color:var(--success-ultra-dark, var(--text-dark))}';
			$css[] = $form . ' :is(.message.error,.form-group-error-message){background-color:var(--danger-ultra-light);color:var(--danger-ultra-dark, var(--text-dark))}';
			if ( ! empty( $s['base_buttons'] ) ) {
				$sub   = $fc . ' :is(:is(button,input)[type="submit"],.tf-form-next)'; // Next in a form with steps looks like the submit button
				$css[] = $sub . '{' . self::btn_vars( $s, $s['form_btn_family'], $s['form_btn_variant'] ) . ';' . self::btn_look( $s ) . ';cursor:pointer}';
				$css[] = '@media (hover:hover) and (pointer:fine){' . $sub . ':hover{background:var(--btn-background-hover);color:var(--btn-text-color-hover);border-color:var(--btn-border-color-hover);text-decoration:var(--btn-text-decoration-hover)}}';
				$css[] = $sub . ':focus-visible{outline-style:solid;outline-color:var(--focus-color);outline-width:var(--focus-width, 2px);outline-offset:var(--focus-offset, 2px)}';
				$css[] = $sub . ':disabled{opacity:.5;cursor:not-allowed}';
			}
			array_push( $classes, 'form--light', 'form--dark' );

			// Ready layouts for the builder's form: put the class on the form element.
			// Field widths set on the form in the builder still apply.
			if ( ! empty( $s['form_layouts'] ) ) {
				$bp    = Scss::settings();
				$bps   = isset( $bp['bp_s'] ) ? max( 320, (int) $bp['bp_s'] ) : 767;
				$full  = ':is(.submit-button-wrapper,.captcha,.message,.tf-form-nav,.tf-form-progress,.tf-form-summary,.tf-form-restored)';
				$css[] = '.brxe-form.form--columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:var(--space-s, 1rem);align-items:start}';
				$css[] = '.brxe-form.form--columns > *{grid-column:1/-1;width:auto}';
				$css[] = '.brxe-form.form--columns > .form-group:not(' . $full . '):not(:has(textarea,.options-wrapper,.choose-files)){grid-column:auto}';
				$css[] = '@media (max-width:' . $bps . 'px){.brxe-form.form--columns > .form-group{grid-column:1/-1}}';
				$css[] = '.brxe-form.form--inline{flex-direction:row;flex-wrap:wrap;align-items:flex-end;column-gap:var(--space-xs, .75rem)}';
				$css[] = '.brxe-form.form--inline > .form-group{flex:1 1 12rem;width:auto}';
				$css[] = '.brxe-form.form--inline > .submit-button-wrapper{flex:0 0 auto;width:auto}';
				$css[] = '.brxe-form.form--inline > :is(.message,.captcha){flex-basis:100%}';
				$css[] = '.brxe-form.form--compact{row-gap:var(--space-xs, .5rem);font-size:var(--text-s, .875rem)}';
				$css[] = '.brxe-form.form--compact > .form-group{padding-bottom:var(--space-xs, .5rem)}';
				$css[] = '.brxe-form.form--compact :is(input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]),select,textarea){padding:.45em .7em;font-size:var(--text-s, .875rem)}';
				$css[] = '.brxe-form.form--card{padding:var(--space-l, 2rem);border:1px solid var(--neutral-trans-10, rgba(0,0,0,.1));border-radius:var(--radius-l, var(--radius, 8px));background:var(--neutral-ultra-light, #f7f7f8)}';
				array_push( $classes, 'form--columns', 'form--inline', 'form--compact', 'form--card' );
			}

			// Steps and conditional fields (the builder's form, see Features\Form_Extras).
			if ( ! empty( $s['form_extras'] ) ) {
				$css[] = '.brxe-form :is(.tf-step-hidden,.tf-cond-hidden){display:none !important}';
				// A calculated field is a result, not something to type in.
				$css[] = '.brxe-form [data-tf-calc] :is(input,textarea)[readonly]{cursor:default;font-weight:600;font-variant-numeric:tabular-nums}';
				// "Show the result: as plain text": the input is kept for sending, out of sight.
				$css[] = '.brxe-form .tf-calc-input{position:absolute !important;width:1px !important;height:1px !important;padding:0 !important;margin:-1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;white-space:nowrap !important;border:0 !important}';
				$css[] = '.brxe-form .tf-calc-text{display:block;font-size:var(--text-l, 1.25rem);font-weight:700;font-variant-numeric:tabular-nums;line-height:1.3}';
				$css[] = '.brxe-form > .tf-form-progress{flex:0 0 100%;width:100%;margin:0 0 var(--space-s, 1rem)}';
				$css[] = '.tf-form-progress__steps{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;margin:0;padding:0;list-style:none}';
				$css[] = '.tf-form-progress__step{display:inline-flex;align-items:center;gap:.5rem;margin:0;padding:0;color:var(--text-dark-muted, #6b7280);font-size:var(--text-s, .875rem);font-weight:600;line-height:1.3}';
				$css[] = '.tf-form-progress__num{display:inline-grid;place-items:center;flex:none;width:1.8em;height:1.8em;border:1px solid currentColor;border-radius:50%;font-size:.9em;line-height:1}';
				$css[] = '.tf-form-progress__step.is-current{color:var(--text-dark, #111)}';
				$css[] = '.tf-form-progress__step.is-current .tf-form-progress__num{border-color:var(--primary);background:var(--primary);color:var(--white, #fff)}';
				$css[] = '.tf-form-progress__step.is-done{color:var(--primary);cursor:pointer}';
				$css[] = '.tf-form-progress__step.is-done .tf-form-progress__num{border-color:transparent;background:var(--primary-ultra-light, #eef2ff)}';
				$css[] = '.tf-form-progress__step.is-done:focus-visible{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px;border-radius:var(--radius, 4px)}';
				$css[] = '.tf-form-progress--bar{display:grid;gap:.4rem}';
				$css[] = ':is(.tf-form-progress__text,.tf-form-progress--count){color:var(--text-dark-muted, #6b7280);font-size:var(--text-s, .875rem);font-weight:600}';
				$css[] = '.tf-form-progress__bar{height:.5rem;overflow:hidden;border-radius:999px;background:var(--neutral-trans-10, rgba(0,0,0,.08))}';
				$css[] = '.tf-form-progress__bar > span{display:block;height:100%;border-radius:inherit;background:var(--primary);transition:width .3s}';
				$css[] = '.brxe-form > .tf-form-nav{flex-direction:row;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem}';
				$css[] = '.brxe-form > .tf-form-nav.is-first{justify-content:flex-end}';
				// Last step: Back sits in the row of the submit button.
				$css[] = '.brxe-form > .tf-form-nav.is-last{flex:0 0 auto;width:auto;padding-inline-end:.75rem}';
				$css[] = '.brxe-form > .tf-form-nav.is-last + .submit-button-wrapper{flex:1 1 auto;width:auto}';
				$css[] = '.brxe-form > .tf-form-nav [hidden]{display:none}';
				$css[] = '.tf-form-back{display:inline-flex;align-items:center;justify-content:center;padding:var(--btn-padding-block, .75em) var(--btn-padding-inline, 1.5em);border:1px solid currentColor;border-radius:var(--btn-border-radius, var(--radius, 6px));background:transparent;color:inherit;font:inherit;font-weight:600;line-height:1.2;cursor:pointer}';
				$css[] = '@media (hover:hover) and (pointer:fine){.tf-form-back:hover{background:var(--neutral-trans-10, rgba(0,0,0,.06))}}';
				$css[] = '.tf-form-back:focus-visible{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px}';
				// The summary step (every answer, with Edit) and the note about saved answers.
				$css[] = '.brxe-form > .tf-form-summary{flex:0 0 100%;width:100%}';
				$css[] = '.tf-form-summary__list{display:grid;margin:0;padding:0}';
				$css[] = '.tf-form-summary__row{display:grid;grid-template-columns:minmax(8rem,1fr) minmax(0,2fr) auto;gap:.25rem 1rem;align-items:baseline;padding:.65rem 0;border-bottom:1px solid var(--neutral-trans-10, rgba(0,0,0,.1))}';
				$css[] = '.tf-form-summary__row > dt{margin:0;font-weight:600}';
				$css[] = '.tf-form-summary__row > dd{margin:0;overflow-wrap:anywhere;white-space:pre-line}';
				$css[] = '.tf-form-summary__row > dd.is-empty{color:var(--text-dark-muted, #6b7280);font-style:italic}';
				$css[] = '@media (max-width:' . ( isset( $bps ) ? $bps : 767 ) . 'px){.tf-form-summary__row{grid-template-columns:minmax(0,1fr) auto}.tf-form-summary__row > dd{grid-column:1/2;grid-row:2}}';
				$css[] = ':is(.tf-form-summary__edit,.tf-form-restart){padding:0;border:0;background:none;color:var(--primary);font:inherit;font-size:var(--text-s, .875rem);text-decoration:underline;text-underline-offset:.2em;cursor:pointer}';
				$css[] = ':is(.tf-form-summary__edit,.tf-form-restart):focus-visible{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px;border-radius:2px}';
				$css[] = '.brxe-form > .tf-form-restored{flex:0 0 100%;width:100%;flex-direction:row;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.5rem 1rem;margin:0 0 var(--space-s, 1rem);padding:.75rem 1rem;border-radius:var(--radius, 6px);background:var(--primary-ultra-light, #eef2ff);font-size:var(--text-s, .875rem)}';
				$css[] = '@media (prefers-reduced-motion:reduce){.tf-form-progress__bar > span{transition:none}}';
			}

			// form_fields
			if ( ! empty( $s['form_fields'] ) ) {
				$css[] = '.brxe-form .tf-sr-input{position:absolute !important;width:1px !important;height:1px !important;padding:0 !important;margin:-1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;white-space:nowrap !important;border:0 !important}';
				$css[] = '.brxe-form .tf-look-value{font-size:var(--text-s, .875rem);font-weight:700;font-variant-numeric:tabular-nums}';
				$css[] = '.brxe-form input[type="range"]{width:100%;accent-color:var(--primary)}';
				$css[] = '.brxe-form .tf-range{position:relative;display:grid;gap:.4rem;width:100%}';
				$css[] = '.brxe-form .tf-range__track{position:relative;height:1.5rem}';
				$css[] = '.brxe-form .tf-range__track::before{content:"";position:absolute;inset:50% 0 auto;height:.25rem;margin-top:-.125rem;border-radius:999px;background:var(--neutral-trans-10, rgba(0,0,0,.08))}';
				$css[] = '.brxe-form .tf-range input[type="range"]{position:absolute;inset:0;width:100%;height:100%;margin:0;background:none;pointer-events:none;-webkit-appearance:none;appearance:none}';
				$css[] = '.brxe-form .tf-range input[type="range"]::-webkit-slider-runnable-track{background:none;pointer-events:none}';
				$css[] = '.brxe-form .tf-range input[type="range"]::-moz-range-track{background:none;pointer-events:none}';
				$css[] = '.brxe-form .tf-range input[type="range"]::-webkit-slider-thumb{pointer-events:auto;cursor:pointer;-webkit-appearance:none;appearance:none;width:1.1rem;height:1.1rem;border:2px solid var(--white, #fff);border-radius:50%;background:var(--primary);box-shadow:0 0 0 1px var(--primary)}';
				$css[] = '.brxe-form .tf-range input[type="range"]::-moz-range-thumb{pointer-events:auto;cursor:pointer;width:1.1rem;height:1.1rem;border:2px solid var(--white, #fff);border-radius:50%;background:var(--primary);box-shadow:0 0 0 1px var(--primary)}';
				$css[] = '.brxe-form .tf-range input[type="range"]:focus-visible::-webkit-slider-thumb{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px}';
				$css[] = '.brxe-form .tf-range input[type="range"]:focus-visible::-moz-range-thumb{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px}';
				$css[] = '.brxe-form .tf-range output{font-size:var(--text-s, .875rem);font-weight:700;font-variant-numeric:tabular-nums}';
				$css[] = '.brxe-form .tf-rating{display:inline-flex;flex-wrap:nowrap;align-items:center}';
				$css[] = '.brxe-form .tf-rating > input{position:absolute !important;width:1px !important;height:1px !important;padding:0 !important;margin:-1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;white-space:nowrap !important;border:0 !important}';
				$css[] = '.brxe-form .tf-rating__star{margin:0;padding:0 .05em;font-size:1.6em;line-height:1;cursor:pointer;color:var(--neutral-trans-20, #d1d5db)}';
				$css[] = '.brxe-form .tf-rating__star::before{content:"\\2605"}';
				$css[] = '.brxe-form .tf-rating > input:checked + .tf-rating__star,.brxe-form .tf-rating__star:has(~ input:checked){color:var(--warning, #f59e0b)}';
				$css[] = '.brxe-form .tf-rating:has(.tf-rating__star:hover) .tf-rating__star{color:var(--neutral-trans-20, #d1d5db)}';
				$css[] = '.brxe-form .tf-rating:has(.tf-rating__star:hover) .tf-rating__star:hover,.brxe-form .tf-rating:has(.tf-rating__star:hover) .tf-rating__star:has(~ .tf-rating__star:hover){color:var(--warning, #f59e0b)}';
				$css[] = '.brxe-form .tf-rating > input:focus-visible + .tf-rating__star{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px;border-radius:var(--radius, 4px)}';
				$css[] = '.brxe-form .tf-counter{display:block;margin-top:.25rem;text-align:right;color:var(--text-dark-muted, #6b7280);font-size:var(--text-s, .875rem)}';
			}

			// form_limits
			if ( ! empty( $s['form_limits'] ) ) {
				$css[] = '.tf-form-closed{padding:var(--space-s, 1rem);border:1px solid var(--neutral-trans-10, rgba(0,0,0,.1));border-radius:var(--radius, 6px);background:var(--neutral-ultra-light, #f7f7f8)}';
				$css[] = '.brxe-form .tf-submit-off{display:none !important}';
			}

			// form_styles
			if ( ! empty( $s['form_styles'] ) ) {
				$css[] = '.brxe-form.form--underline :is(input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="range"]):not([data-tf-ui]),select,textarea){border-width:0 0 1px;border-radius:0;background:transparent;padding-inline:0}';
				$css[] = '.brxe-form.form--underline :is(input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="range"]):not([data-tf-ui]),select,textarea):focus{box-shadow:none;border-bottom-color:var(--primary)}';
				$css[] = '.brxe-form.form--rounded :is(input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="range"]):not([data-tf-ui]),select,textarea){border-radius:999px;padding-inline:1.2em}';
				$css[] = '.brxe-form.form--rounded textarea{border-radius:1rem}';
				$css[] = '.brxe-form.form--rounded :is(button[type="submit"],input[type="submit"]){border-radius:999px}';
				$css[] = '.brxe-form.form--soft :is(input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="range"]):not([data-tf-ui]),select,textarea){border-color:transparent;background:var(--neutral-trans-5, rgba(0,0,0,.04))}';
				$css[] = '.brxe-form.form--soft :is(input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="range"]):not([data-tf-ui]),select,textarea):focus{border-color:var(--primary)}';
				array_push( $classes, 'form--underline', 'form--rounded', 'form--soft' );
			}
		}

		// ---- Cards ----
		if ( ! empty( $s['base_cards'] ) ) {
			$cv = array(
				'--card-background'   => $s['card_background'],
				'--card-text-color'   => $s['card_text_color'],
				'--card-text-size'    => $s['card_text_size'],
				'--card-line-height'  => $s['card_line_height'],
				'--card-padding'      => $s['card_padding'],
				'--card-gap'          => $s['card_gap'],
				'--card-radius'       => $s['card_radius'],
				'--card-border-width' => $s['card_border_width'],
				'--card-border-style' => $s['card_border_style'],
				'--card-border-color' => $s['card_border_color'],
				'--card-shadow'       => '' !== $s['card_shadow'] ? $s['card_shadow'] : 'none', // always set: beats a stale stored token
				'--card-heading-size' => $s['card_heading_size'],
				'--card-heading-color' => $s['card_heading_color'],
				'--card-link-color'   => $s['card_link_color'],
				'--card-link-color-hover' => $s['card_link_color_hover'],
				'--card-media-aspect-ratio' => $s['card_media_aspect'],
				'--card-media-object-fit'   => $s['card_media_fit'],
				'--card-media-radius'       => $s['card_media_radius'],
				'--card-avatar-size'        => $s['card_avatar_size'],
				'--card-avatar-radius'      => $s['card_avatar_radius'],
				'--card-avatar-border-width' => $s['card_avatar_border_width'],
				'--card-avatar-border-style' => $s['card_avatar_border_style'],
				'--card-avatar-border-color' => $s['card_avatar_border_color'],
				'--card-avatar-aspect-ratio' => $s['card_avatar_aspect'],
				'--card-icon-size'          => $s['card_icon_size'],
				'--card-icon-background'    => $s['card_icon_background'],
				'--card-icon-color'         => $s['card_icon_color'],
				'--card-icon-padding'       => $s['card_icon_padding'],
				'--card-icon-radius'        => $s['card_icon_radius'],
				'--card-icon-border-width'  => $s['card_icon_border_width'],
				'--card-icon-border-style'  => $s['card_icon_border_style'],
				'--card-icon-border-color'  => $s['card_icon_border_color'],
				'--card-button-font-size'   => $s['card_button_size'],
				'--card-min-radius'         => $s['card_min_radius'],
			);
			if ( ! empty( $s['card_concentric'] ) ) {
				// Inner corners follow the card: radius − padding (never below the minimum).
				$inner                     = 'max(var(--card-min-radius, 4px), calc(var(--card-radius, 0px) - var(--card-padding, 0px)))';
				$cv['--card-media-radius'] = $inner;
				$cv['--card-icon-radius']  = $inner;
			}
			foreach ( $cv as $n => $v ) {
				if ( '' !== (string) $v ) {
					$root[] = "$n:$v";
				}
			}
			$extra = trim( str_replace( '"', "'", (string) $s['card_selectors'] ) );
			$card  = '.card' . ( '' !== $extra ? ',' . $extra : '' );
			$cards = array_merge( $cards, self::card_rules( $s, $card, ':is(' . $card . ')' ) );
			$classes[] = 'card';
		}

		if ( $root ) {
			array_unshift( $css, ':root{' . implode( ';', $root ) . '}' );
		}

		return array(
			'css'     => "\n/* --- TokenForge base styles --- */\n" . implode( "\n", $css ) . "\n",
			'buttons' => $btn ? "\n/* --- TokenForge buttons & links --- */\n" . implode( "\n", $btn ) . "\n" : '',
			'cards'   => $cards ? "\n/* --- TokenForge cards --- */\n" . implode( "\n", $cards ) . "\n" : '',
			'classes' => array_values( array_unique( $classes ) ),
		);
	}

	/**
	 * The card rules for a selector (the .card class, or "&" in the card() mixin).
	 *
	 * @param array  $s    Settings.
	 * @param string $card Selector list of the card itself.
	 * @param string $c    The same as one compound (for descendant rules).
	 * @return string[]
	 */
	public static function card_rules( $s, $card, $c ) {
		$cards   = array();
		$cards[] = $card . '{' . self::decl( 'color-scheme', $s['card_scheme'] ) . 'display:var(--card-display, flex)!important;flex-direction:var(--card-flex-direction, column);position:relative;overflow:var(--card-overflow, clip);background-clip:padding-box;'
			. 'transition:var(--card-transition, var(--transition, none));font-size:var(--card-text-size, inherit);line-height:var(--card-line-height, inherit);'
			. 'border-width:var(--card-border-width, 0);border-style:var(--card-border-style, solid);border-color:var(--card-border-color, transparent);border-radius:var(--card-radius, 0);'
			. 'padding:var(--card-padding, var(--space-m));gap:var(--card-gap, var(--content-gap));background:var(--card-background, transparent);color:var(--card-text-color, inherit);box-shadow:var(--card-shadow, none)}';
		$cards[] = $c . ' :where([class*="__media"]:not([class*="wrapper"])){border-radius:var(--card-media-radius, var(--radius, 0));aspect-ratio:var(--card-media-aspect-ratio, 16/9);object-fit:var(--card-media-object-fit, cover);overflow:clip;inline-size:100%}';
		$cards[] = $c . ' :where([class*="__media"]:not([class*="wrapper"])) img{object-fit:var(--card-media-object-fit, cover)}';
		$cards[] = $c . ' :where(img[class*="__avatar"]:not([class*="wrapper"]),[class*="__avatar"] img){border-radius:var(--card-avatar-radius, 50vw);border-width:var(--card-avatar-border-width, 0);border-style:var(--card-avatar-border-style, solid);border-color:var(--card-avatar-border-color, transparent);'
			. 'inline-size:var(--card-avatar-size, 48px);block-size:var(--card-avatar-size, 48px);aspect-ratio:var(--card-avatar-aspect-ratio, 1);object-fit:cover;align-self:center;overflow:clip}';
		$cards[] = $c . ' :is(h1,h2,h3,h4,h5,h6){font-size:var(--card-heading-size, var(--h3));color:var(--card-heading-color, inherit)}';
		$cards[] = $c . ' a:where(:not(:is(h1,h2,h3,h4,h5,h6) a,[class*="btn--"],[data-icon])){--link-color:var(--card-link-color, var(--primary));--link-color-hover:var(--card-link-color-hover, var(--primary-hover))}';
		$cards[] = $c . ' :where([data-icon]){--icon-size:var(--card-icon-size, 48px);--icon-color:var(--card-icon-color, var(--icon-color, currentColor));'
			. '--icon-padding:var(--card-icon-padding, var(--icon-padding, 12px));--icon-border-width:var(--card-icon-border-width, var(--icon-border-width, 0));--icon-border-style:var(--card-icon-border-style, var(--icon-border-style, solid));'
			. '--icon-radius:var(--card-icon-radius, var(--icon-radius, var(--radius)));--icon-background:var(--card-icon-background, var(--icon-background, var(--neutral-ultra-light)));--icon-border-color:var(--card-icon-border-color, var(--icon-border-color, transparent))'
			. ( ! empty( $s['card_boxed_icons'] ) ? ';padding:var(--icon-padding);border-width:var(--icon-border-width);border-style:var(--icon-border-style);border-radius:var(--icon-radius);background:var(--icon-background);border-color:var(--icon-border-color)' : '' ) . '}';
		$cfam    = preg_replace( '/[^a-z0-9-]/', '', (string) $s['card_button_family'] );
		$cards[] = $c . ' [class*="btn--"]{--btn-font-size:var(--card-button-font-size, var(--text-s))' . ( '' !== $cfam ? ';' . self::btn_vars( $s, $cfam, 'solid' ) : '' ) . '}';
		$cards[] = $c . ':where([class*="--light"]){color-scheme:light}' . $c . ':where([class*="--dark"]){color-scheme:dark}';
		return $cards;
	}
}
