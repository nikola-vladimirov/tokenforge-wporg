<?php
/**
 * WooCommerce: the shop in your design system.
 *
 * When WooCommerce is active, TokenForge styles its classic pages, the
 * builder's shop elements and the WooCommerce blocks with your tokens:
 * buttons, notices, fields, prices, sale badges, ratings, product grids,
 * tables, tabs and pagination. Every part has its own switch, and
 * WooCommerce's own CSS variables (--wc-*) follow your tokens too.
 *
 * Selectors follow WooCommerce's theming docs: the classes WooCommerce
 * documents for theme overrides (a.button, .woocommerce-message, .price …)
 * and, for blocks, the .wc-block-components-* component classes, never a
 * block nested in another block. Block fields keep WooCommerce's own sizes
 * (floating labels); only their colours and corners change.
 *
 * Rules start with "html" so they win over WooCommerce's and the theme's
 * rules of the same weight whatever order the stylesheets load in. With
 * "Win over element settings" on, the shop parts start with html:not(#_)
 * instead, which also beats the styles of single elements set in the builder.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce styling.
 */
class Woo {

	const OPTION = 'tokenforge_woo';

	/** Button variants a shop button can use. */
	const VARIANTS = array( 'solid', 'outline', 'dark', 'light', 'outline-dark', 'outline-light' );

	/** Classic shop wrappers (on body for shop pages, or a div around shortcodes). */
	const WRAP = ':is(.woocommerce,.woocommerce-page)';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_enqueue_styles', array( __CLASS__, 'enqueue_styles' ), 20 );
		add_filter( 'single_product_archive_thumbnail_size', array( __CLASS__, 'list_image_size' ), 20 );
		add_filter( 'wp_theme_json_data_theme', array( __CLASS__, 'theme_json' ) );
	}

	/**
	 * Block themes: WooCommerce's product price, sale badge and rating blocks take
	 * their look from global styles (WooCommerce's own advice for block themes), so
	 * TokenForge hands its values to the theme's global styles. They then show in
	 * the Site Editor's Styles, where they can be changed, and TokenForge's CSS
	 * leaves those properties of those blocks alone.
	 *
	 * @return bool
	 */
	public static function uses_global_styles() {
		$s   = self::settings();
		$use = ! empty( $s['enabled'] ) && ! empty( $s['blocks'] ) && ! empty( $s['global_styles'] ) && self::active();
		/**
		 * Hand the WooCommerce block styles to global styles? True with a block theme.
		 *
		 * @param bool $block_theme Whether the active theme is a block theme.
		 */
		return $use && (bool) apply_filters( 'tokenforge_woo_global_styles', function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );
	}

	/**
	 * Add the block styles to the theme's global styles (theme.json).
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Theme data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function theme_json( $theme_json ) {
		if ( ! self::uses_global_styles() || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}
		$blocks = self::global_styles();
		if ( ! $blocks ) {
			return $theme_json;
		}
		return $theme_json->update_with(
			array(
				'version' => 2,
				'styles'  => array( 'blocks' => $blocks ),
			)
		);
	}

	/**
	 * The block styles (theme.json "styles.blocks") from the WooCommerce settings.
	 *
	 * @return array block name => styles
	 */
	public static function global_styles() {
		$s      = self::settings();
		$blocks = array();
		if ( ! empty( $s['prices'] ) ) {
			$blocks['woocommerce/product-price'] = array( 'typography' => array( 'fontWeight' => (string) $s['price_weight'] ) );
			if ( '' !== $s['price_color'] ) {
				$blocks['woocommerce/product-price']['color'] = array( 'text' => $s['price_color'] );
			}
		}
		if ( ! empty( $s['badges'] ) ) {
			$blocks['woocommerce/product-sale-badge'] = array(
				'color'      => array( 'background' => $s['badge_background'], 'text' => $s['badge_color'] ),
				'border'     => array( 'radius' => $s['badge_radius'], 'width' => '0px' ),
				'typography' => array( 'fontSize' => 'max(var(--text-xs, .75rem), .75rem)', 'fontWeight' => '600', 'lineHeight' => '1.2', 'letterSpacing' => '.04em', 'textTransform' => 'uppercase' ),
			);
		}
		if ( ! empty( $s['ratings'] ) ) {
			foreach ( array( 'woocommerce/product-rating', 'woocommerce/product-rating-stars' ) as $block ) {
				$blocks[ $block ] = array( 'color' => array( 'text' => $s['star_color'] ) );
			}
		}
		// Empty settings leave the theme's own value.
		return array_map( function ( $styles ) {
			return array_map( function ( $group ) {
				return array_filter( $group, function ( $v ) {
					return '' !== trim( (string) $v );
				} );
			}, $styles );
		}, $blocks );
	}

	/**
	 * Product lists (shop, related products, the builder's product lists) show
	 * WooCommerce's thumbnail, which WooCommerce crops to its thumbnail shape on
	 * the server. With "Whole pictures" they show the uncropped single product
	 * size instead (woocommerce_single: WooCommerce never crops it).
	 *
	 * @param string|array $size Image size WooCommerce is about to use.
	 * @return string|array
	 */
	public static function list_image_size( $size ) {
		$s = self::settings();
		return ( ! empty( $s['enabled'] ) && 'whole' === $s['image_source'] && 'woocommerce_thumbnail' === $size ) ? 'woocommerce_single' : $size;
	}

	/**
	 * Is WooCommerce active? (WooCommerce fires woocommerce_loaded once it is.)
	 *
	 * @return bool
	 */
	public static function active() {
		return did_action( 'woocommerce_loaded' ) > 0 || class_exists( 'WooCommerce', false );
	}

	/**
	 * WooCommerce version, or ''.
	 *
	 * @return string
	 */
	public static function version() {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	/**
	 * Optionally drop WooCommerce's own stylesheets.
	 *
	 * @param array $styles Stylesheets WooCommerce is about to load.
	 * @return array
	 */
	public static function enqueue_styles( $styles ) {
		$s = self::settings();
		return ( ! empty( $s['enabled'] ) && ! empty( $s['remove_css'] ) ) ? array() : $styles;
	}

	/**
	 * Defaults: on, in the style of the rest of the site.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'woo', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'enabled'          => true,
			'remove_css'       => false,
			'buttons'          => true,
			'btn_main_family'  => 'primary',  // Add to cart on a product, Proceed to checkout, Place order
			'btn_main_variant' => 'solid',
			'btn_family'       => 'primary',  // every other shop button
			'btn_variant'      => 'outline',
			'prices'           => true,
			'price_color'      => '',          // empty = the text colour
			'price_weight'     => '600',
			'sale_color'       => 'var(--danger)',
			'old_price_color'  => 'var(--text-dark-muted)',
			'badges'           => true,
			'badge_background' => 'var(--danger)',
			'badge_color'      => 'var(--white, #fff)',
			'badge_radius'     => 'var(--radius)',
			'ratings'          => true,
			'star_color'       => 'var(--warning)',
			'notices'          => true,
			'notice_radius'    => 'var(--radius)',
			'forms'            => true,
			'qty_width'        => '4.5em',
			'grid'             => true,
			'grid_gap'         => 'var(--grid-gap, var(--space-m))',
			'columns_s'        => 2,           // at the "s" breakpoint (the breakpoints)
			'columns_xs'       => 1,           // at the "xs" breakpoint
			'cards'            => false,       // products as cards (the Cards settings)
			'tables'           => true,
			'tabs'             => true,
			'pagination'       => true,
			'blocks'           => true,        // also the Cart, Checkout and product blocks
			'pages'            => true,        // shop page layouts: cart, checkout, account, product, product lists
			'override'         => true,        // shop styles win over element settings made in the builder
			'surface'          => 'var(--neutral-ultra-light)', // panel background
			'line_color'       => 'var(--neutral-trans-10)',    // hairline borders
			'panel_radius'     => 'var(--radius-l, var(--radius))',
			'control_height'   => '2.5rem',    // quantity boxes, coupon fields and the buttons beside them
			'field_gap'        => 'var(--space-s, 1rem)', // space between form fields
			'section_space'    => 'var(--section-space-s)', // space between the sections of shop pages; empty = the theme's
			'image_fit'        => 'contain',   // product images in lists: contain | cover
			'image_ratio'      => '1',         // shape of product images in lists
			'image_background' => '',          // behind product images; empty = the panel background
			'save_color'       => 'var(--success-dark, var(--success))', // "Save 24,00" on a cart line
			'save_background'  => 'var(--success-ultra-light)',
			'new_badge_background' => 'var(--text-dark)',
			'load'             => 'auto',      // shop page styles: auto (only where the shop appears) | all (every page)
			'image_source'     => 'crop',      // product pictures in lists: crop (WooCommerce's thumbnails) | whole (uncropped)
			'filters'          => true,        // product filters (builder filters, WooCommerce widgets and blocks)
			'global_styles'    => true,        // block themes: WooCommerce blocks through global styles too
			// Look and spacing of every shop part (empty = this default).
			'text_color'       => 'var(--text-dark, #111827)',
			'muted_color'      => 'var(--text-dark-muted, #6b7280)',
			'accent'           => 'var(--primary)',            // active steps, focus, chosen options
			'accent_soft'      => 'var(--primary-ultra-light, #eef2ff)',
			'border_strong'    => 'var(--neutral-trans-20, rgba(0,0,0,.2))', // field and box borders
			'radius'           => 'var(--radius, 6px)',        // fields, images and small boxes
			'panel_padding'    => 'var(--space-m, 1.5rem)',
			'gap'              => 'var(--space-s, 1rem)',
			'gap_small'        => 'var(--space-xs, .75rem)',
			// Fields on shop pages (empty = as in Forms).
			'field_height'       => '',
			'field_padding'      => '',
			'field_font_size'    => '',
			'field_background'   => '',
			'field_text_color'   => '',
			'field_border_color' => '',
			'field_border_width' => '',
			'field_radius'       => '',
			'field_focus_color'  => '',
			'label_color'        => '',
			'label_size'         => '',
			// Notices (empty = the colour family of each kind).
			'notice_padding'      => '',
			'notice_border_width' => '',
			'notice_font_size'    => '',
			'notice_icon'         => true,
			'notice_success_background' => '',
			'notice_success_text'       => '',
			'notice_success_border'     => '',
			'notice_info_background'    => '',
			'notice_info_text'          => '',
			'notice_info_border'        => '',
			'notice_error_background'   => '',
			'notice_error_text'         => '',
			'notice_error_border'       => '',
			'notice_warning_background' => '',
			'notice_warning_text'       => '',
			'notice_warning_border'     => '',
		);
	}

	/** Where the shop page styles load. */
	const LOADS = array( 'auto', 'all' );

	/** Which picture product lists show. */
	const IMAGE_SOURCES = array( 'crop', 'whole' );

	/** Shapes a product image can take in lists. */
	const RATIOS = array( '1', '4/5', '3/4', '2/3', '4/3', '3/2', '16/9', 'auto' );

	/** Selector prefix: "html:not(#_)" when shop styles win over element settings, else "html". */
	private static $pre = 'html';

	/**
	 * Settings (module switch applied).
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return Modules::apply( 'woo', wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw settings.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $k => $def ) {
			if ( is_bool( $def ) ) {
				$out[ $k ] = ! empty( $in[ $k ] );
			} elseif ( is_int( $def ) ) {
				$out[ $k ] = isset( $in[ $k ] ) ? max( 1, min( 6, (int) $in[ $k ] ) ) : $def;
			} elseif ( in_array( $k, array( 'btn_main_family', 'btn_family' ), true ) ) {
				$v         = isset( $in[ $k ] ) ? strtolower( (string) $in[ $k ] ) : '';
				$out[ $k ] = in_array( $v, Components::FAMILIES, true ) ? $v : $def;
			} elseif ( 'image_fit' === $k ) {
				$out[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], array( 'contain', 'cover' ), true ) ? $in[ $k ] : $def;
			} elseif ( 'load' === $k ) {
				$out[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], self::LOADS, true ) ? $in[ $k ] : $def;
			} elseif ( 'image_source' === $k ) {
				$out[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], self::IMAGE_SOURCES, true ) ? $in[ $k ] : $def;
			} elseif ( 'image_ratio' === $k ) {
				$out[ $k ] = isset( $in[ $k ] ) && in_array( (string) $in[ $k ], self::RATIOS, true ) ? (string) $in[ $k ] : $def;
			} elseif ( in_array( $k, array( 'btn_main_variant', 'btn_variant' ), true ) ) {
				$v         = isset( $in[ $k ] ) ? (string) $in[ $k ] : '';
				$out[ $k ] = in_array( $v, self::VARIANTS, true ) ? $v : $def;
			} else {
				// A CSS value: drop what could end the declaration or a tag.
				$out[ $k ] = isset( $in[ $k ] ) ? trim( preg_replace( '/[;{}<>]/', '', (string) $in[ $k ] ) ) : $def;
			}
		}
		update_option( self::OPTION, $out, false );
	}

	/**
	 * A selector for each wrapper: html .woocommerce X, html .woocommerce-page X.
	 *
	 * @param string[] $inner Inner selectors.
	 * @return string[]
	 */
	private static function in_shop( $inner ) {
		return array_map( function ( $x ) {
			return self::$pre . ' ' . self::WRAP . ' ' . $x;
		}, $inner );
	}

	/**
	 * Prefix selectors that start with "html " with the current prefix.
	 *
	 * @param string[] $list Selectors.
	 * @return string[]
	 */
	private static function pre( $list ) {
		return array_map( function ( $x ) {
			return 0 === strpos( $x, 'html ' ) ? self::$pre . substr( $x, 4 ) : $x;
		}, $list );
	}

	/**
	 * Append a pseudo-class to every selector of a list.
	 *
	 * @param string[] $list   Selectors.
	 * @param string   $suffix e.g. ":hover".
	 * @return string
	 */
	private static function each( $list, $suffix ) {
		return implode( ',', array_map( function ( $x ) use ( $suffix ) {
			return $x . $suffix;
		}, $list ) );
	}

	/**
	 * Custom properties of a button family + variant.
	 *
	 * @param string $family  Family.
	 * @param string $variant Variant.
	 * @return string
	 */
	private static function btn_vars( $family, $variant ) {
		$out = array();
		foreach ( Components::btn_var_map( Components::settings(), $family, $variant ) as $n => $v ) {
			$out[] = "--$n:$v";
		}
		return implode( ';', $out );
	}


	/**
	 * Selectors of the shop stylesheet's placeholders (assets/css/woo-shop.css).
	 *
	 * @return array placeholder => selector
	 */
	private static function placeholders() {
		$v2  = ':where(.brxe-woocommerce-cart-v2,.brxe-woocommerce-checkout-v2,.brxe-woocommerce-account-page-v2)';
		$bp  = Scss::settings();
		$bps = isset( $bp['bp_s'] ) ? (int) $bp['bp_s'] : 767;
		return array(
			'{S}'      => self::$pre,
			'{B}'      => 'html',
			'{V2}'     => $v2,
			'{LINES}'  => ':where(.brxe-woocommerce-cart-v2,.brxe-woocommerce-checkout-v2,.brxe-woocommerce-account-page-v2,.brxe-woocommerce-dynamic-fragment)',
			'{FRAG}'   => '.brxe-woocommerce-dynamic-fragment',
			'{SHOP}'   => ':where(.brxe-woocommerce-cart-v2,.brxe-woocommerce-checkout-v2,.brxe-woocommerce-account-page-v2,.woocommerce,.woocommerce-page)',
			'{CARD}'   => ':is(ul.products li.product,.brxe-block:has(> .brxe-product-price):has(> .brxe-image),.brxe-div:has(> .brxe-product-price):has(> .brxe-image))',
			'{ROW}'    => '.brxe-div:has(> :is(.brxe-div,.brxe-block) > .brxe-image)',
			'{FIELDS}' => ':is(.woocommerce-address-fields__field-wrapper,.woocommerce-billing-fields__field-wrapper,.woocommerce-shipping-fields__field-wrapper,.woocommerce-additional-fields__field-wrapper,.brxe-woocommerce-checkout-billing-address,.brxe-woocommerce-checkout-shipping-address,.brxe-woocommerce-checkout-account-fields,form.woocommerce-EditAccountForm,' . $v2 . ' :is(.brxe-div,.brxe-block):has(> .brxe-woocommerce-form-field))',
			'{TOT}'    => '.brxe-div:has(> .brxe-text-basic:first-child + .brxe-text-basic:last-child):not(:has(> .brxe-text-basic:first-child > .amount))',
			'{BP_S}'   => (string) max( 320, $bps ),
		);
	}

	/**
	 * Give selectors that start with "html " the current prefix (strong or not).
	 *
	 * @param string $css CSS whose selectors start with "html ".
	 * @return string
	 */
	private static function strong( $css ) {
		if ( 'html' === self::$pre ) {
			return $css;
		}
		return (string) preg_replace( '/(^|[{},])html /m', '$1' . self::$pre . ' ', $css );
	}

	/**
	 * The main stylesheet's part (empty when WooCommerce isn't active or the module is off).
	 *
	 * @return string
	 */
	public static function build() {
		return self::both()['main'];
	}

	/**
	 * Can the shop pages sheet hold the product-only parts (buttons, prices, badges,
	 * lists, product page)? Only where page_has_shop() can see shop content: with
	 * the builders it reads (Bricks, the block editor and Etch, classic themes),
	 * not with Oxygen, Breakdance, Divi or Elementor, whose product widgets it
	 * can't find. There those parts stay in the main stylesheet.
	 *
	 * @return bool
	 */
	public static function split() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['pages'] ) || ! self::active() ) {
			return false;
		}
		foreach ( Platforms\Platforms::detected() as $b ) {
			if ( $b['active'] && in_array( $b['id'], array( 'oxygen', 'breakdance', 'divi', 'elementor' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The CSS for both sheets: { main, shop } (shop holds the product-only parts when split()).
	 *
	 * @return array
	 */
	private static function both() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || ! self::active() ) {
			return array( 'main' => '', 'shop' => '' );
		}
		self::$pre = ! empty( $s['override'] ) ? 'html:not(#_)' : 'html';
		$c         = Components::settings();
		$blocks    = ! empty( $s['blocks'] );
		$gs        = self::uses_global_styles(); // block themes: some block styles live in global styles
		$css       = array();
		// Product-only parts, soft and strong: the shop sheet's when split, else these sheets'.
		$shop      = array();
		$shop_s    = array();

		// ---- Variables: ours, and WooCommerce's own (--wc-*) from your tokens ----
		$root = array(
			'--woo-price-weight:' . $s['price_weight'],
			'--woo-sale-color:' . $s['sale_color'],
			'--woo-old-price-color:' . $s['old_price_color'],
			'--woo-badge-background:' . $s['badge_background'],
			'--woo-badge-color:' . $s['badge_color'],
			'--woo-badge-radius:' . $s['badge_radius'],
			'--woo-new-badge-bg:' . $s['new_badge_background'],
			'--woo-star-color:' . $s['star_color'],
			'--woo-notice-radius:' . $s['notice_radius'],
			'--woo-qty-width:' . $s['qty_width'],
			'--woo-grid-gap:' . $s['grid_gap'],
			'--woo-surface:' . $s['surface'],
			'--woo-line:' . $s['line_color'],
			'--woo-panel-radius:' . $s['panel_radius'],
			'--woo-control-h:' . $s['control_height'],
			'--woo-field-gap:' . $s['field_gap'],
			'--woo-section-space:' . $s['section_space'],
			'--woo-image-fit:' . $s['image_fit'],
			'--woo-image-ratio:' . ( 'auto' === $s['image_ratio'] ? '1' : $s['image_ratio'] ),
			'--woo-image-bg:' . ( '' !== $s['image_background'] ? $s['image_background'] : 'var(--woo-surface)' ),
			'--woo-save-color:' . $s['save_color'],
			'--woo-save-bg:' . $s['save_background'],
			'--woo-line-strong:' . self::val( $s, 'border_strong' ),
			'--woo-radius:' . self::val( $s, 'radius' ),
			'--woo-muted:' . self::val( $s, 'muted_color' ),
			'--woo-text:' . self::val( $s, 'text_color' ),
			'--woo-pad:' . self::val( $s, 'panel_padding' ),
			'--woo-gap:' . self::val( $s, 'gap' ),
			'--woo-gap-s:' . self::val( $s, 'gap_small' ),
			'--woo-accent:' . self::val( $s, 'accent' ),
			'--woo-accent-soft:' . self::val( $s, 'accent_soft' ),
			'--woo-accent-ring:color-mix(in srgb, var(--woo-accent) 20%, transparent)',
		);
		if ( '' !== $s['price_color'] ) {
			$root[] = '--woo-price-color:' . $s['price_color'];
		}
		$root = array_filter( $root, function ( $d ) {
			return ':' !== substr( $d, -1 );
		} );
		$css[] = ':root{' . implode( ';', $root ) . '}';
		$css[] = 'html:root{--wc-primary:var(--primary);--wc-primary-text:var(--white, #fff);--wc-secondary:var(--neutral-ultra-light, #f3f4f6);--wc-secondary-text:var(--text-dark, #1f2937);'
			. '--wc-highlight:var(--woo-sale-color);--wc-highligh-text:var(--white, #fff);--wc-content-bg:var(--body-bg-color, var(--white, #fff));--wc-subtext:var(--text-dark-muted, #6b7280);'
			. '--wc-green:var(--success);--wc-red:var(--danger);--wc-orange:var(--warning);--wc-blue:var(--info);--wc-destructive:var(--danger);--wc-card-border-radius:var(--card-radius, var(--radius));'
			. '--wc-form-border-color:' . $c['field_border'] . ';--wc-form-border-radius:' . $c['field_radius'] . ';--wc-form-border-width:1px}';

		// Parts below are "strong": with Win over element settings on, they also beat
		// the builder's element settings (their selectors get html:not(#_) in front).
		$strong = array();

		// ---- Buttons: those a header cart shows (mini cart, fragments, the block
		// mini cart's buttons) on every page, the rest with the product-only parts ----
		if ( ! empty( $s['buttons'] ) ) {
			$look     = Components::btn_look( $c ) . ';cursor:pointer;text-decoration:none';
			$reg      = self::pre( array( 'html .woocommerce-mini-cart__buttons .button', 'html .brxe-woocommerce-dynamic-fragment .bricks-button' ) );
			$main     = self::pre( array( 'html .woocommerce-mini-cart__buttons .button.checkout', 'html .brxe-woocommerce-dynamic-fragment :is(.brxe-block,.brxe-div) > .bricks-button:last-child' ) );
			$reg_shop = array_merge(
				self::in_shop( array( 'a.button', 'button.button', 'input.button', '#respond input#submit' ) ),
				self::pre( array( 'html a.button.add_to_cart_button', 'html :is([class*="brxe-woocommerce"],[class*="brxe-product"]) .button', 'html a.added_to_cart', 'html :is(.brxe-woocommerce-cart-v2,.brxe-woocommerce-checkout-v2,.brxe-woocommerce-account-page-v2) .bricks-button' ) )
			);
			$main_shop = array_merge(
				self::in_shop( array( 'a.button.alt', 'button.button.alt', 'input.button.alt', 'button.woocommerce-Button[name]', 'button.woocommerce-form-login__submit', 'button.woocommerce-form-register__submit', '.woocommerce-ResetPassword button[type="submit"]' ) ),
				self::pre( array( 'html .single_add_to_cart_button.button.button', 'html a.checkout-button.button.button', 'html button#place_order', 'html .brx-checkout-step > .brxe-div:last-child > .bricks-button:last-child', 'html .cart_totals > .bricks-button' ) )
			);
			// The builder's checkout: Back is an ordinary button, Next / Place order the main one.
			$reg_shop[] = self::$pre . ' .brx-checkout-step > .brxe-div:last-child > .bricks-button:not(:last-child)';
			if ( $blocks ) {
				$reg       = array_merge( $reg, self::pre( array( 'html .wc-block-components-button:not(.is-link)', 'html .wc-block-components-button:not(.is-link):is(.outlined,.is-style-outline)' ) ) );
				$reg_shop  = array_merge( $reg_shop, self::pre( array( 'html .wc-block-components-product-button .wp-block-button__link', 'html .wc-block-grid__product-add-to-cart .wp-block-button__link' ) ) );
				// Main: proceed, place order, and the Browse store / Start shopping button of an empty cart or checkout.
				$main      = array_merge( $main, self::pre( array( 'html .wp-block-woocommerce-empty-mini-cart-contents-block .wp-block-button__link' ) ) );
				$main_shop = array_merge( $main_shop, self::pre( array( 'html .wc-block-components-button:not(.is-link):is(.wc-block-cart__submit-button,.wc-block-components-checkout-place-order-button)', 'html :is(.wc-block-checkout-empty,.wp-block-woocommerce-empty-cart-block) .wp-block-button__link' ) ) );
			}
			$buttons = function ( $reg, $main ) use ( $s, $look ) {
				$all = array_merge( $reg, $main );
				return array(
					implode( ',', $reg ) . '{' . self::btn_vars( $s['btn_family'], $s['btn_variant'] ) . ';' . $look . '}',
					// The look again: WooCommerce's own .alt rule outweighs the shared one.
					implode( ',', $main ) . '{' . self::btn_vars( $s['btn_main_family'], $s['btn_main_variant'] ) . ';' . $look . '}',
					'@media (hover:hover) and (pointer:fine){' . self::each( $all, ':hover' ) . '{background:var(--btn-background-hover);color:var(--btn-text-color-hover);border-color:var(--btn-border-color-hover);text-decoration:none}}',
					self::each( $all, ':focus-visible' ) . '{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:var(--focus-offset, 2px);box-shadow:none}',
					self::each( $all, ':is(:disabled,.disabled,[aria-disabled="true"])' ) . '{background:var(--btn-background);color:var(--btn-text-color);border-color:var(--btn-border-color);opacity:.5;cursor:not-allowed}',
				);
			};
			$css  = array_merge( $css, $buttons( $reg, $main ) );
			$shop = array_merge( $shop, $buttons( $reg_shop, $main_shop ) );
			// The builder's checkout wizard colours Place order with var(--bricks-bg-dark): point it at the main button colour.
			$shop[] = 'html button#place_order{--bricks-bg-dark:var(--btn-background)}';
			// Room for WooCommerce's loading spinner.
			$shop[] = self::each( self::in_shop( array( 'a.button', 'button.button' ) ), '.loading' ) . '{padding-inline-end:2.618em}';
			// View cart (after an add to cart): a button, centred like the others.
			$shop[] = self::$pre . ' a.added_to_cart{display:inline-flex;align-items:center;justify-content:center}';
		}

		// ---- Prices + stock ----
		if ( ! empty( $s['prices'] ) ) {
			$price = array_merge(
				self::in_shop( array( 'div.product :is(p.price,span.price)', 'ul.products li.product .price' ) ),
				self::pre( array( 'html .brxe-product-price .price', 'html .product .price' ) )
			);
			$prices = function ( $base, $price ) {
				return array(
					implode( ',', $base ) . '{color:var(--woo-price-color, inherit);font-weight:var(--woo-price-weight)}',
					self::each( $price, ' del' ) . '{color:var(--woo-old-price-color);opacity:1;font-weight:400}',
					self::each( $price, ' ins' ) . '{color:var(--woo-sale-color);text-decoration:none;font-weight:var(--woo-price-weight)}',
				);
			};
			$shop_s = array_merge( $shop_s, $prices( $price, $price ) );
			if ( $blocks ) {
				// The block mini cart lists prices too: these stay on every page.
				$price = self::pre( array( 'html .wc-block-components-product-price', 'html .wc-block-grid__product-price' ) );
				// With global styles the product price block keeps the colour and weight set there.
				$base   = self::pre( array( $gs ? 'html .wc-block-components-product-price:not(:where(.wp-block-woocommerce-product-price *))' : 'html .wc-block-components-product-price', 'html .wc-block-grid__product-price' ) );
				$strong = array_merge( $strong, $prices( $base, $price ) );
			}
			$shop_s[] = 'html ' . self::WRAP . ' :is(div.product,.woocommerce-variation-availability,.brxe-product-stock) .stock{color:var(--success)}';
			$shop_s[] = 'html ' . self::WRAP . ' :is(div.product,.woocommerce-variation-availability,.brxe-product-stock) .stock.out-of-stock{color:var(--danger)}';
			$shop_s[] = 'html ' . self::WRAP . ' :is(div.product,.woocommerce-variation-availability,.brxe-product-stock) .stock.available-on-backorder{color:var(--warning-dark, var(--warning))}';
		}

		// ---- Sale badge ----
		if ( ! empty( $s['badges'] ) ) {
			$badge = array( 'html ' . self::WRAP . ' span.onsale', 'html ' . self::WRAP . ' .products .badge.onsale', 'html .onsale' );
			if ( $blocks ) {
				$badge = array_merge( $badge, array( $gs ? 'html .wc-block-components-product-sale-badge:not(:where(.wp-block-woocommerce-product-sale-badge *))' : 'html .wc-block-components-product-sale-badge', 'html .wc-block-grid .wc-block-grid__product-onsale' ) );
				if ( $gs ) {
					// Colours, corners and type from global styles; the size of the badge from here.
					$shop_s[] = 'html .wp-block-woocommerce-product-sale-badge .wc-block-components-product-sale-badge{min-width:0;min-height:0;padding:.35em .75em}';
				}
			}
			$shop_s[] = implode( ',', $badge ) . '{background:var(--woo-badge-background);color:var(--woo-badge-color);border:0;border-radius:var(--woo-badge-radius);min-width:0;min-height:0;'
				. 'padding:.35em .75em;font-size:max(var(--text-xs, .75rem), .75rem);font-weight:600;line-height:1.2;letter-spacing:.04em;text-transform:uppercase}';
		}

		// ---- Ratings ----
		if ( ! empty( $s['ratings'] ) ) {
			$stars = array( 'html ' . self::WRAP . ' .star-rating', 'html .star-rating' );
			if ( $blocks && ! $gs ) {
				$stars = array_merge( $stars, array( 'html .wc-block-components-product-rating-stars__stars', 'html .wc-block-components-product-rating__stars' ) );
			}
			$shop_s[] = implode( ',', $stars ) . '{color:var(--woo-star-color)}';
		}

		// ---- Notices ----
		if ( ! empty( $s['notices'] ) ) {
			// Spacing too: TokenForge's list reset would otherwise empty the error list (a ul).
			// WooCommerce's own CSS leaves room for its icon; the builder shows none.
			$bricks = Platforms\Bricks::is_active();
			$pad    = '' !== $s['notice_padding'] ? $s['notice_padding'] : ( $bricks ? 'var(--space-s, 15px)' : '1em 2em 1em 3.5em' );
			$space  = 'padding:' . $pad . ';margin:' . ( $bricks ? '0 0 var(--space-s, 15px)' : '0 0 2em' ) . ( '' !== $s['notice_font_size'] ? ';font-size:' . $s['notice_font_size'] : '' );
			$width  = '' !== $s['notice_border_width'] ? $s['notice_border_width'] : '4px';
			// A kind's colours: yours, else its colour family.
			$look = function ( $kind, $fam ) use ( $s ) {
				$v = function ( $part, $def ) use ( $s, $kind ) {
					return '' !== $s[ "notice_{$kind}_{$part}" ] ? $s[ "notice_{$kind}_{$part}" ] : $def;
				};
				return array( $v( 'background', "var(--$fam-ultra-light)" ), $v( 'text', "var(--$fam-ultra-dark, var(--text-dark))" ), $v( 'border', "var(--$fam)" ) );
			};
			foreach ( array( 'message' => array( 'success', 'success' ), 'info' => array( 'info', 'info' ), 'error' => array( 'error', 'danger' ) ) as $type => $kf ) {
				list( $bg, $text, $line ) = $look( $kf[0], $kf[1] );
				$fam      = $kf[1];
				$sel      = "html .woocommerce-$type,html :is(.woocommerce-notices-wrapper,.woocommerce-NoticeGroup) .woocommerce-$type";
				$strong[] = $sel . "{--bricks-woo-notice-$fam:$text;--bricks-bg-$fam:$bg;"
					. "background-color:$bg;color:$text;border:0;border-inline-start:$width solid $line;border-radius:var(--woo-notice-radius);list-style:none;$space}";
				$strong[] = "html .woocommerce-$type::before" . ( empty( $s['notice_icon'] ) ? '{display:none}' : "{color:$line}" );
			}
			$strong[] = 'html .woocommerce-store-notice,html p.demo_store{background-color:var(--primary);color:var(--white, #fff)}';
			if ( $blocks ) {
				$strong[] = 'html .wc-block-components-notice-banner{border-radius:var(--woo-notice-radius)'
					. ( '' !== $s['notice_padding'] ? ';padding:' . $s['notice_padding'] : '' )
					. ( '' !== $s['notice_border_width'] ? ';border-width:' . $s['notice_border_width'] : '' )
					. ( '' !== $s['notice_font_size'] ? ';font-size:' . $s['notice_font_size'] : '' ) . '}';
				foreach ( array( 'success' => 'success', 'info' => 'info', 'error' => 'danger', 'warning' => 'warning' ) as $type => $fam ) {
					list( $bg, $text, $line ) = $look( $type, $fam );
					$strong[] = "html .wc-block-components-notice-banner.is-$type{background-color:$bg;border-color:$line;color:$text}";
					$strong[] = "html .wc-block-components-notice-banner.is-$type>svg" . ( empty( $s['notice_icon'] ) ? '{display:none}' : "{background-color:$line}" );
				}
			}
		}

		// ---- Fields ----
		if ( ! empty( $s['forms'] ) ) {
			// TokenForge's field rule gives inputs the full width: the quantity box keeps a small one
			// (not with the builder, whose quantity box has its own minus and plus buttons).
			if ( ! Platforms\Bricks::is_active() ) {
				$strong[] = 'html .quantity input.qty:not([type="submit"]):not([type="button"]):not([type="checkbox"]){inline-size:var(--woo-qty-width);width:var(--woo-qty-width);text-align:center;padding-inline:.25em}';
			}
			$strong[] = 'html .select2-container .select2-selection{border-color:var(--wc-form-border-color);border-radius:var(--wc-form-border-radius);background-color:var(--field-bg, #fff)}';
			if ( $blocks ) {
				$field    = 'html .wc-block-components-text-input input:not([type="checkbox"]):not([type="radio"])';
				$strong[] = $field . ',html .wc-block-components-textarea,html .wc-blocks-components-select .wc-blocks-components-select__select{border-color:var(--wc-form-border-color);border-radius:var(--wc-form-border-radius);background-color:var(--field-bg, #fff)}';
				$strong[] = $field . ':focus,html .wc-block-components-textarea:focus,html .wc-blocks-components-select .wc-blocks-components-select__select:focus{border-color:var(--focus-color, var(--primary))}';
				$strong[] = 'html .wc-block-components-quantity-selector{border-color:var(--wc-form-border-color);border-radius:var(--wc-form-border-radius)}';
			}
		}

		// ---- Product grid (stays soft: the builder's own column settings keep working) ----
		if ( ! empty( $s['grid'] ) ) {
			$ul    = 'html ' . self::WRAP . ' ul.products';
			$shop[] = $ul . '{display:grid;grid-template-columns:repeat(var(--woo-columns, 4),minmax(0,1fr));gap:var(--woo-grid-gap);margin-inline:0;padding:0}';
			$shop[] = $ul . '::before,' . $ul . '::after{content:none;display:none}';
			$shop[] = $ul . ' li.product.product{float:none;width:auto;inline-size:auto;margin:0}';
			// Product names and prices in the text colour, not the link colour.
			$shop[] = $ul . ' li.product a:is(.woocommerce-loop-product__link,.woocommerce-LoopProduct-link){color:inherit}';
			for ( $n = 1; $n <= 6; $n++ ) {
				$shop[] = "$ul.columns-$n,html " . self::WRAP . ".columns-$n ul.products{--woo-columns:$n}";
			}
			$bp = Scss::settings();
			foreach ( array( 's' => (int) $s['columns_s'], 'xs' => (int) $s['columns_xs'] ) as $size => $cols ) {
				$sel = array();
				for ( $n = $cols + 1; $n <= 6; $n++ ) {
					$sel[] = "$ul.columns-$n";
					$sel[] = 'html ' . self::WRAP . ".columns-$n ul.products";
				}
				if ( 4 > $cols ) {
					$sel[] = $ul . ':not([class*="columns-"])';
				}
				if ( $sel ) {
					$shop[] = '@media (max-width:' . ( (int) $bp[ 'bp_' . $size ] ) . 'px){' . implode( ',', $sel ) . "{--woo-columns:$cols}}";
				}
			}
			$shop[] = 'html .brxe-woocommerce-products .products,html :is(.related,.upsells,.cross-sells) .products{gap:var(--woo-grid-gap)}';
		}

		// ---- Products as cards ----
		if ( ! empty( $s['cards'] ) ) {
			$card = array( 'html ' . self::WRAP . ' ul.products li.product.product', 'html .brxe-block:has(> .brxe-product-price):has(> .brxe-image)', 'html .brxe-div:has(> .brxe-product-price):has(> .brxe-image)' );
			if ( $blocks ) {
				$card = array_merge( $card, array( 'html .wc-block-grid__product', 'html .wc-block-product' ) );
			}
			$shop_s[] = implode( ',', $card ) . '{display:flex;flex-direction:column;background:var(--card-background, var(--neutral-ultra-light, #f7f7f8));padding:var(--card-padding, var(--space-m));'
				. 'border:var(--card-border-width, 0) var(--card-border-style, solid) var(--card-border-color, transparent);border-radius:var(--card-radius, var(--radius));box-shadow:var(--card-shadow, none)}';
			// Badges sit on the image, inside the card padding; the image on the page background.
			$shop_s[] = implode( ',', $card ) . '{--woo-card-inset:var(--card-padding, var(--space-m));--woo-image-bg:' . ( '' !== $s['image_background'] ? $s['image_background'] : 'var(--body-bg-color, var(--white, #fff))' ) . '}';
			$shop_s[] = 'html ' . self::WRAP . ' ul.products li.product.product .button{margin-block-start:auto}';
		}

		// ---- Tables ----
		if ( ! empty( $s['tables'] ) ) {
			$t        = 'html ' . self::WRAP . ' table.shop_table';
			$shop_s[] = $t . '{border:1px solid var(--woo-line);border-radius:var(--woo-radius);border-collapse:separate;border-spacing:0;overflow:hidden}';
			$shop_s[] = $t . ' :is(th,td){border-color:var(--woo-line);padding:var(--space-xs, .6em) var(--space-s, .9em)}';
			$shop_s[] = $t . ' thead th{color:var(--woo-muted);font-size:max(var(--text-xs, .75rem), .75rem);font-weight:600;letter-spacing:.06em;text-transform:uppercase}';
			$shop_s[] = $t . ' tfoot :is(th,td){font-weight:600}';
		}

		// ---- Product tabs ----
		if ( ! empty( $s['tabs'] ) ) {
			$t        = 'html ' . self::WRAP . ' :is(div.product,.brxe-product-tabs) .woocommerce-tabs ul.tabs';
			$shop_s[] = $t . '{display:flex;flex-wrap:wrap;gap:0 1.75rem;margin:0 0 var(--space-m, 1.5rem);padding:0;border-bottom:1px solid var(--woo-line);list-style:none;overflow:visible}';
			$shop_s[] = $t . '::before,' . $t . '::after{display:none}';
			$shop_s[] = $t . ' li{margin:0;padding:0;border:0;border-radius:0;background:none}';
			$shop_s[] = $t . ' li::before,' . $t . ' li::after{display:none}';
			$shop_s[] = $t . ' li a{display:block;margin-bottom:-1px;padding:.8rem 0;border-bottom:2px solid transparent;color:var(--woo-muted);font-weight:600;text-decoration:none;transition:color .15s,border-color .15s}';
			$shop_s[] = $t . ' li a:hover{color:var(--woo-text)}';
			$shop_s[] = $t . ' li.active a{color:var(--woo-text);border-bottom-color:var(--primary)}';
		}

		// ---- Pagination ----
		if ( ! empty( $s['pagination'] ) ) {
			$p        = 'html ' . self::WRAP . ' nav.woocommerce-pagination ul';
			$shop_s[] = $p . '{display:flex;flex-wrap:wrap;justify-content:center;gap:.35em;border:0;margin:0}';
			$shop_s[] = $p . ' li{border:0;float:none;overflow:visible}';
			$shop_s[] = $p . ' li :is(a,span){display:block;min-inline-size:2.5em;padding:.5em .8em;border:1px solid var(--woo-line-strong);border-radius:var(--radius);color:var(--woo-text);line-height:1;text-align:center;text-decoration:none}';
			$shop_s[] = $p . ' li span.current{background:var(--primary);border-color:var(--primary);color:var(--white, #fff)}';
			$shop_s[] = '@media (hover:hover) and (pointer:fine){' . $p . ' li a:hover{background:var(--woo-surface);color:var(--woo-text)}}';
			$shop_s[] = $p . ' li a:focus-visible{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:2px}';
		}

		// ---- Cart and Checkout blocks: the sticky submit bar, the quantity badge ----
		$tail = array();
		if ( $blocks ) {
			$bg     = 'var(--body-bg-color, var(--white, #fff))';
			// Only the bar that sticks to the bottom on phones has a background of its own.
			$tail[] = 'html .wc-block-cart__submit-container--sticky{background-color:' . $bg . '}';
			// Proceed to checkout fills the totals panel, as WooCommerce lays it out.
			$tail[] = self::$pre . ' .wc-block-cart .wc-block-cart__submit-button.wc-block-components-button:not(.is-link){inline-size:100%;width:100%}';
			// The count on an order summary picture: a dark pill with light text, like the builder's cart.
			$tail[] = 'html .wc-block-components-order-summary .wc-block-components-order-summary-item__quantity{background-color:var(--woo-text);border-color:var(--woo-text);box-shadow:0 0 0 2px var(--woo-surface);color:var(--white, #fff);font-weight:700}';
			$tail[] = 'html .wc-block-components-order-summary .wc-block-components-order-summary-item__quantity span{color:var(--white, #fff)}';
		}

		$split = self::split();
		if ( ! $split ) {
			$css    = array_merge( $css, $shop );
			$strong = array_merge( $strong, $shop_s );
		}
		if ( $strong ) {
			$css[] = self::strong( implode( "\n", $strong ) );
		}

		// ---- Shop pages: the parts a header cart can show on any page (line items,
		// quantity, totals, mini cart, dynamic fragments). The rest is build_shop(). ----
		$all = ! empty( $s['pages'] ) ? self::sheet( $s, true ) : '';
		$css[] = $all;
		if ( ! $split ) {
			return array( 'main' => "\n/* --- WooCommerce --- */\n" . implode( "\n", array_merge( $css, $tail ) ) . "\n", 'shop' => '', 'all' => $all );
		}
		if ( $shop_s ) {
			$shop[] = self::strong( implode( "\n", $shop_s ) );
		}
		// That part again after the moved rules: on shop pages it must still come after
		// them, as in one sheet (the cart totals button: the button look, then full width).
		// ponytail: repeats about 16 KB on shop pages only; split the part by what it overrides if that matters.
		$shop[] = $all;
		return array(
			'main' => "\n/* --- WooCommerce --- */\n" . implode( "\n", $css ) . "\n",
			'shop' => implode( "\n", array_merge( $shop, $tail ) ),
			'all'  => $all,
		);
	}

	/**
	 * The shop pages sheet: product lists, product page, cart, checkout, account,
	 * product filters. Written to its own file, which loads only where the shop
	 * appears (see shop_styles_needed()), or on every page when "Load shop page
	 * styles" is set to every page.
	 *
	 * @return string '' when WooCommerce isn't active or the part is off.
	 */
	public static function build_shop() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['pages'] ) || ! self::active() ) {
			return '';
		}
		$css       = array( self::both()['shop'] ); // the product-only parts (when split())
		self::$pre = ! empty( $s['override'] ) ? 'html:not(#_)' : 'html';
		// Sections of shop pages: even space between them, none around sections with
		// nothing to show (an empty notice area, hooks without output). Margins the
		// shop templates add go, so the space is the same everywhere.
		if ( '' !== trim( $s['section_space'] ) ) {
			$sec   = 'html body:is(.woocommerce,.woocommerce-page) #brx-content > .brxe-section';
			$css[] = $sec . '{margin-block:0;padding-block:calc(var(--woo-section-space) / 2)}';
			$css[] = $sec . ':first-child{padding-block-start:var(--woo-section-space)}';
			$css[] = $sec . ':last-child{padding-block-end:var(--woo-section-space)}';
			$css[] = 'html body:is(.woocommerce,.woocommerce-page) .brxe-section:not(:has(:not(.brxe-container,.brxe-block,.brxe-div,:empty),img,svg,video,iframe,canvas,picture,input,select,textarea,hr)){margin-block:0;padding-block:0}';
		}
		$css[] = self::sheet( $s, false );
		if ( ! empty( $s['forms'] ) ) {
			$css[] = self::field_css( $s );
		}
		// Product images in their own shape (ratio "auto"): the picture sets the height.
		if ( 'auto' === $s['image_ratio'] ) {
			$card  = self::placeholders()['{CARD}'];
			$css[] = self::$pre . ' ' . $card . ' > .brxe-image{aspect-ratio:auto}' . self::$pre . ' ' . $card . ' > .brxe-image img{position:static;height:auto}' . self::$pre . ' ' . $card . ' :is(img.attachment-woocommerce_thumbnail,a.woocommerce-LoopProduct-link > img){aspect-ratio:auto}';
		}
		return "\n/* --- WooCommerce shop pages --- */\n" . implode( "\n", $css ) . "\n";
	}

	/**
	 * A setting, or its default when left empty.
	 *
	 * @param array  $s Settings.
	 * @param string $k Key.
	 * @return string
	 */
	private static function val( $s, $k ) {
		return '' !== trim( (string) $s[ $k ] ) ? $s[ $k ] : self::builtin_defaults()[ $k ];
	}

	/**
	 * Fields on shop pages (checkout, account, coupon): the values set in the
	 * WooCommerce section, over the Forms section. The builder's floating labels
	 * keep their own padding; their height follows "Field height".
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function field_css( $s ) {
		$p     = self::$pre;
		$shop  = self::placeholders()['{SHOP}'];
		$input = ":is(input.input-text,select,textarea)";
		$all   = array( "$p $shop :is(.form-row,.brxe-woocommerce-form-field) $input", "$p .select2-container .select2-selection" );
		if ( ! empty( $s['blocks'] ) ) {
			$all[] = "$p :is(.wc-block-components-text-input input,.wc-block-components-textarea,.wc-blocks-components-select__select)";
		}
		$out  = array();
		$decl = '';
		foreach ( array( 'field_background' => 'background-color', 'field_text_color' => 'color', 'field_border_color' => 'border-color', 'field_border_width' => 'border-width', 'field_radius' => 'border-radius', 'field_font_size' => 'font-size' ) as $k => $prop ) {
			$decl .= '' !== $s[ $k ] ? "$prop:{$s[$k]};" : '';
		}
		if ( '' !== $decl ) {
			$out[] = implode( ',', $all ) . '{' . $decl . '}';
		}
		// Padding and height: fields with their label above (floating labels need their own padding).
		$plain = "$p $shop :is(.form-row,.brxe-woocommerce-form-field):not(:where(.bricks-floating-label *)) $input";
		$decl  = ( '' !== $s['field_padding'] ? "padding:{$s['field_padding']};" : '' ) . ( '' !== $s['field_height'] ? "min-height:{$s['field_height']};" : '' );
		if ( '' !== $decl ) {
			$out[] = $plain . '{' . $decl . '}';
		}
		if ( '' !== $s['field_height'] ) {
			$out[] = "$p .bricks-floating-label .brxe-woocommerce-form-field{--brx-field-height:{$s['field_height']}}";
		}
		if ( '' !== $s['field_focus_color'] ) {
			$focus = array_map( function ( $x ) {
				return $x . ':focus';
			}, $all );
			$focus[] = "$p .select2-container--focus .select2-selection";
			$out[]   = implode( ',', $focus ) . "{border-color:{$s['field_focus_color']};box-shadow:0 0 0 3px color-mix(in srgb, {$s['field_focus_color']} 25%, transparent);outline:0}";
		}
		if ( '' !== $s['label_color'] ) {
			$out[] = "$p $shop .form-row > label:not(.checkbox,.woocommerce-form__label-for-checkbox),$p .bricks-floating-label .brxe-woocommerce-form-field > label,$p .wc-block-components-text-input label{color:{$s['label_color']}}";
		}
		if ( '' !== $s['label_size'] ) {
			$out[] = "$p $shop .form-row:not(:where(.bricks-floating-label *)) > label:not(.checkbox,.woocommerce-form__label-for-checkbox,.screen-reader-text){font-size:{$s['label_size']}}";
		}
		return implode( "\n", $out );
	}

	/**
	 * assets/css/woo-shop.css with its placeholders filled in and the blocks of
	 * parts that are off taken out.
	 *
	 * @param array $s   Settings.
	 * @param bool  $all True: only the blocks marked @all (they load on every
	 *                   page); false: everything else.
	 * @return string
	 */
	private static function sheet( $s, $all ) {
		$file = TOKENFORGE_DIR . 'assets/css/woo-shop.css';
		$src  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
		$keep = array();
		$rest = (string) preg_replace_callback(
			'#/\*\[@all\]\*/(.*?)/\*\[/@all\]\*/#s',
			function ( $m ) use ( &$keep ) {
				$keep[] = $m[1];
				return '';
			},
			$src
		);
		$page = $all ? implode( "\n", $keep ) : $rest;
		// Blocks marked /*[part]*/ … /*[/part]*/ belong to a part: they go when that part is off.
		// A part can sit inside another one (filter blocks inside filters), hence the loop.
		do {
			$before = $page;
			$page   = (string) preg_replace_callback(
				'#/\*\[([a-z_]+)\]\*/(.*?)/\*\[/\1\]\*/#s',
				function ( $m ) use ( $s ) {
					return ! empty( $s[ $m[1] ] ) ? $m[2] : '';
				},
				$page
			);
		} while ( $page !== $before );
		$page = trim( (string) preg_replace( '#/\*.*?\*/#s', '', $page ) );
		$page = (string) preg_replace( "/\n{2,}/", "\n", $page );
		return strtr( $page, self::placeholders() );
	}

	/**
	 * Does this page need the shop pages sheet? Shop, product, cart, checkout and
	 * account pages do, and so does any page showing WooCommerce blocks or
	 * shortcodes, or the builder's shop elements or product loops (in the page,
	 * its header, footer or popups, templates and components inside them).
	 * A header mini cart alone doesn't: its styles are in the main stylesheet.
	 *
	 * @return bool
	 */
	public static function shop_styles_needed() {
		$s    = self::settings();
		$need = 'all' === $s['load'] || self::page_has_shop();
		/**
		 * Load the shop pages sheet on this page?
		 *
		 * @param bool $need True when the page shows shop content.
		 */
		return (bool) apply_filters( 'tokenforge_woo_shop_styles', $need );
	}

	/**
	 * Shop content on the current page?
	 *
	 * @return bool
	 */
	private static function page_has_shop() {
		if ( is_admin() || ( Platforms\Bricks::is_active() && Platforms\Bricks::is_preview_context() ) ) {
			return true; // editing: anything can be added
		}
		foreach ( array( 'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page' ) as $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				return true;
			}
		}
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && self::text_has_shop( $post->post_content ) ) {
			return true;
		}
		// Block themes: the template of this page (set before the page head is printed).
		if ( ! empty( $GLOBALS['_wp_current_template_content'] ) && self::text_has_shop( $GLOBALS['_wp_current_template_content'] ) ) {
			return true;
		}
		return Platforms\Bricks::is_active() && self::builder_has_shop();
	}

	/**
	 * WooCommerce blocks (other than the mini cart and account link) or shortcodes in a text?
	 *
	 * @param mixed $text Post content, template or shortcode.
	 * @return bool
	 */
	private static function text_has_shop( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return false;
		}
		return (bool) preg_match( '#<!-- wp:woocommerce/(?!mini-cart|customer-account)|\[(?:products|product|product_page|product_category|product_categories|add_to_cart|sale_products|best_selling_products|recent_products|featured_products|top_rated_products|product_attribute|related_products|shop_messages|woocommerce_[a-z_]+)[\s\]]#', $text );
	}

	/**
	 * The builder's shop elements or product loops on this page (its content,
	 * header, footer and popups)?
	 *
	 * @return bool
	 */
	private static function builder_has_shop() {
		if ( ! class_exists( '\Bricks\Database' ) || ! method_exists( '\Bricks\Database', 'get_template_data' ) ) {
			return false;
		}
		$lists = array();
		foreach ( array( 'header', 'content', 'footer' ) as $area ) {
			$lists[] = \Bricks\Database::get_template_data( $area );
		}
		$active = is_array( \Bricks\Database::$active_templates ) ? \Bricks\Database::$active_templates : array();
		foreach ( isset( $active['popup'] ) ? (array) $active['popup'] : array() as $id ) {
			$lists[] = self::builder_data( $id );
		}
		foreach ( $lists as $elements ) {
			if ( is_array( $elements ) && self::elements_have_shop( $elements, 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The builder's elements of a template or page.
	 *
	 * @param int $id Post id.
	 * @return array
	 */
	private static function builder_data( $id ) {
		$data = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? get_post_meta( (int) $id, BRICKS_DB_PAGE_CONTENT, true ) : array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Shop elements in a list of the builder's elements? Looks into templates and
	 * components placed in the list (three levels deep).
	 *
	 * @param array $elements Elements.
	 * @param int   $depth    Nesting level.
	 * @return bool
	 */
	private static function elements_have_shop( $elements, $depth ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$name = isset( $el['name'] ) ? (string) $el['name'] : '';
			$set  = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			if ( 0 === strpos( $name, 'product-' ) || ( 0 === strpos( $name, 'woocommerce-' ) && ! in_array( $name, array( 'woocommerce-mini-cart', 'woocommerce-dynamic-fragment' ), true ) ) ) {
				return true;
			}
			// A query loop of products.
			if ( ! empty( $set['hasLoop'] ) && isset( $set['query']['post_type'] ) && array_intersect( array( 'product', 'product_variation' ), (array) $set['query']['post_type'] ) ) {
				return true;
			}
			if ( 'shortcode' === $name && isset( $set['shortcode'] ) && self::text_has_shop( $set['shortcode'] ) ) {
				return true;
			}
			if ( $depth >= 3 ) {
				continue;
			}
			if ( 'template' === $name && ! empty( $set['template'] ) && self::elements_have_shop( self::builder_data( $set['template'] ), $depth + 1 ) ) {
				return true;
			}
			if ( ! empty( $el['cid'] ) && class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'get_component_by_cid' ) ) {
				$component = \Bricks\Helpers::get_component_by_cid( $el['cid'] );
				if ( is_array( $component ) && ! empty( $component['elements'] ) && is_array( $component['elements'] ) && self::elements_have_shop( $component['elements'], $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
