<?php
/**
 * Color scheme (dark mode) + color assignments.
 *
 * Implements the modern ACSS-style workflow with native CSS: a set of context
 * variables (--bg-*, --text-*) and assignment classes (.bg--*, .text--*) where
 * setting a background automatically sets a readable text/heading color. When
 * dark mode is enabled the context variables are wrapped in light-dark() and
 * :root gets `color-scheme: light dark`, so everything flips natively.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Scheme + assignments CSS.
 */
class Scheme {

	const OPTION = 'tokenforge_scheme';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'scheme', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'assignments'                => true,   // output .bg--* / .text--* + context vars.
			'dark_mode'                  => 'off',  // 'off' | 'auto', generate dark values (light-dark())
			'default_scheme'             => 'auto', // with dark mode: 'light' | 'dark' | 'auto' (follow the visitor's OS).
			'switcher'                   => false,  // visitor light/dark toggle (.scheme-toggle / shortcode)
			// Background relationships: what each .bg--* sets inside it.
			'ctx_ultra_light_text'       => 'var(--text-dark)',
			'ctx_light_text'             => 'var(--text-dark)',
			'ctx_dark_text'              => 'var(--text-light)',
			'ctx_ultra_dark_text'        => 'var(--text-light)',
			'ctx_ultra_light_heading'    => 'var(--text-dark)',
			'ctx_light_heading'          => 'var(--text-dark)',
			'ctx_dark_heading'           => 'var(--text-light)',
			'ctx_ultra_dark_heading'     => 'var(--text-light)',
			'ctx_ultra_light_button'     => '', // a button family (e.g. primary) for btn--* inside.
			'ctx_light_button'           => '',
			'ctx_dark_button'            => '',
			'ctx_ultra_dark_button'      => '',
			'ctx_ultra_light_icon'       => '', // icon colour scheme: light | dark.
			'ctx_light_icon'             => '',
			'ctx_dark_icon'              => '',
			'ctx_ultra_dark_icon'        => '',
			'ctx_ultra_light_link'       => '',
			'ctx_light_link'             => '',
			'ctx_dark_link'              => '',
			'ctx_ultra_dark_link'        => '',
			'ctx_ultra_light_link_hover' => '',
			'ctx_light_link_hover'       => '',
			'ctx_dark_link_hover'        => '',
			'ctx_ultra_dark_link_hover'  => '',
			'ctx_ultra_light_focus'      => '',
			'ctx_light_focus'            => '',
			'ctx_dark_focus'             => '',
			'ctx_ultra_dark_focus'       => '',
			// Context colours (the light-scheme values; dark mode swaps the pairs).
			'bg_ultra_light'             => 'var(--base-ultra-light, #f7f8fa)',
			'bg_light'                   => 'var(--base-light, #e9edf2)',
			'bg_dark'                    => 'var(--base-dark, #1a2433)',
			'bg_ultra_dark'              => 'var(--base-ultra-dark, #0a0f18)',
			'text_dark'                  => 'var(--base, #10151c)',
			'text_dark_muted'            => 'var(--base-semi-dark, #47505c)',
			'text_light'                 => 'var(--base-ultra-light, #f7f8fa)',
			'text_light_muted'           => 'var(--base-light, #c9d2dd)',
			// Always render these selectors in one scheme (one per line / comma separated).
			'force_dark'                 => '',
			'force_light'                => '',
		);
	}

	/**
	 * Keys that hold CSS values (sanitised as text).
	 */
	const VALUE_KEYS = array( 'bg_ultra_light', 'bg_light', 'bg_dark', 'bg_ultra_dark', 'text_dark', 'text_dark_muted', 'text_light', 'text_light_muted', 'force_dark', 'force_light' );

	/** Each context variable and the one it turns into in dark mode. */
	const PAIRS = array(
		'bg-ultra-light'   => 'bg-ultra-dark',
		'bg-light'         => 'bg-dark',
		'bg-dark'          => 'bg-light',
		'bg-ultra-dark'    => 'bg-ultra-light',
		'text-dark'        => 'text-light',
		'text-dark-muted'  => 'text-light-muted',
		'text-light'       => 'text-dark',
		'text-light-muted' => 'text-dark-muted',
	);

	/** Background contexts (.bg--*) → the default readable text. */
	const CONTEXTS = array( 'ultra-light', 'light', 'dark', 'ultra-dark' );

	/** Per-context relationship settings (ctx_{context}_{what}). */
	const CTX_PROPS = array( 'text', 'heading', 'button', 'icon', 'link', 'link_hover', 'focus' );

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return Modules::apply( 'scheme', wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		update_option(
			self::OPTION,
			array(
				'assignments'    => ! empty( $in['assignments'] ),
				'dark_mode'      => ( isset( $in['dark_mode'] ) && 'auto' === $in['dark_mode'] ) ? 'auto' : 'off',
				'default_scheme' => ( isset( $in['default_scheme'] ) && in_array( $in['default_scheme'], array( 'light', 'dark' ), true ) ) ? $in['default_scheme'] : 'auto',
				'switcher'       => ! empty( $in['switcher'] ),
			) + self::clean_values( $in ) + self::clean_ctx( $in )
		);
	}

	/**
	 * Sanitised background-relationship settings.
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	private static function clean_ctx( $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( self::CONTEXTS as $c ) {
			foreach ( self::CTX_PROPS as $p ) {
				$k = 'ctx_' . str_replace( '-', '_', $c ) . '_' . $p;
				$v = isset( $in[ $k ] ) ? trim( (string) $in[ $k ] ) : $d[ $k ];
				if ( 'button' === $p ) {
					$v = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $v ) ); // a family name.
				} elseif ( 'icon' === $p ) {
					$v = in_array( $v, array( 'light', 'dark' ), true ) ? $v : '';
				} else {
					$v = trim( str_replace( array( ';', '{', '}', '<', '>' ), '', sanitize_text_field( $v ) ) );
				}
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Sanitised colour/selector values from input (defaults for missing ones).
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	private static function clean_values( $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( self::VALUE_KEYS as $k ) {
			$v         = isset( $in[ $k ] ) ? (string) $in[ $k ] : $d[ $k ];
			$out[ $k ] = trim( str_replace( array( ';', '{', '}', '<', '>' ), '', sanitize_textarea_field( $v ) ) );
		}
		return $out;
	}

	/**
	 * Build the scheme + assignment CSS and class names.
	 *
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build() {
		$s = self::settings();
		// The context variables below are always written, the Colors module off
		// included (that only switches off dark mode and the assignments): buttons,
		// cards, forms and the shop use them.
		$dark    = 'auto' === $s['dark_mode'];
		$css     = array();
		$classes = array();

		// Helper: a value that flips between light + dark when dark mode is on (wrap()).
		$ctx = self::dark_context();
		$ld  = function ( $light, $darkval ) use ( $ctx ) {
			return self::wrap( $light, $darkval, $ctx );
		};

		// Forced schemes for specific selectors.
		foreach ( array(
			'dark'  => $s['force_dark'],
			'light' => $s['force_light'],
		) as $scheme => $sel ) {
			// One per line or comma separated (commas inside :is()/:not() kept); unquote.
			$list = array_filter( array_map( 'trim', preg_split( '/[\r\n]+|,(?![^(]*\))/', str_replace( '"', '', (string) $sel ) ) ) );
			if ( $list ) {
				$css[] = implode( ',', $list ) . '{color-scheme:' . $scheme . '}';
			}
		}

		if ( $dark ) {
			// The site's default scheme: fixed light/dark, or following the visitor's OS.
			$css[]     = ':root{color-scheme:' . ( 'auto' === $s['default_scheme'] ? 'light dark' : $s['default_scheme'] ) . '}';
			$css[]     = '.scheme--light{color-scheme:light}';
			$css[]     = '.scheme--dark{color-scheme:dark}';
			$classes[] = 'scheme--light';
			$classes[] = 'scheme--dark';

			if ( ! empty( $s['switcher'] ) ) {
				// A visitor choice (stored by the toggle script) overrides the OS preference.
				$css[] = ':root[data-scheme="light"]{color-scheme:light}:root[data-scheme="dark"]{color-scheme:dark}';
				$css[] = '.scheme-toggle{display:inline-grid;place-items:center;inline-size:2.5em;block-size:2.5em;padding:0;border:1px solid color-mix(in srgb, currentColor 25%, transparent);border-radius:999px;background:transparent;color:inherit;cursor:pointer}';
				$css[] = '.scheme-toggle svg{inline-size:1.25em;block-size:1.25em;grid-area:1/1}';
				// Show the moon while light is in effect, the sun while dark is.
				$css[]     = '.scheme-toggle .tf-sun{display:none}';
				$css[]     = ':root[data-scheme="dark"] .scheme-toggle .tf-sun{display:block}:root[data-scheme="dark"] .scheme-toggle .tf-moon{display:none}';
				$css[]     = '@media (prefers-color-scheme:dark){:root:not([data-scheme="light"]) .scheme-toggle .tf-sun{display:block}:root:not([data-scheme="light"]) .scheme-toggle .tf-moon{display:none}}';
				$classes[] = 'scheme-toggle';
			}

			// Builder backgrounds that stay the same (a header set to var(--white)): the element keeps its
			// light colours. Text it inherits was already switched by its parent, so while the page is dark
			// it is read again there. No specificity: the builder's own text colour wins.
			$pins = Fixed_Bg::selectors( $ctx );
			if ( $pins ) {
				$is    = ':is(' . implode( ',', $pins ) . ')';
				$text  = '{color:var(--text-color, CanvasText)}';
				$css[] = ':where(' . implode( ',', $pins ) . '){color-scheme:light}';
				if ( ! empty( $s['switcher'] ) ) {
					$css[] = ':where(:root[data-scheme="dark"] ' . $is . ')' . $text;
				}
				if ( 'dark' === $s['default_scheme'] ) {
					$css[] = ':where(:root:not([data-scheme="light"]) ' . $is . ')' . $text;
				} elseif ( 'auto' === $s['default_scheme'] ) {
					$css[] = '@media (prefers-color-scheme:dark){:where(:root:not([data-scheme="light"]) ' . $is . ')' . $text . '}';
				}
				// Inside a part that is always dark (.scheme--dark, the Always dark selectors), on any page.
				$always = array_filter( array_map( 'trim', preg_split( '/[\r\n]+|,(?![^(]*\))/', str_replace( '"', '', (string) $s['force_dark'] ) ) ) );
				$css[]  = ':where(:is(' . implode( ',', array_merge( array( '.scheme--dark' ), $always ) ) . ') ' . $is . ')' . $text;
			}
		}

		// Context variables, always: buttons, cards, forms and the shop use them,
		// so switching the assignments off must not leave them without a value.
		// In dark mode each flips to its opposite surface/text.
		$css[] = ':root{'
			. '--bg-ultra-light:' . $ld( $s['bg_ultra_light'], $s['bg_ultra_dark'] ) . ';'
			. '--bg-light:' . $ld( $s['bg_light'], $s['bg_dark'] ) . ';'
			. '--bg-dark:' . $ld( $s['bg_dark'], $s['bg_light'] ) . ';'
			. '--bg-ultra-dark:' . $ld( $s['bg_ultra_dark'], $s['bg_ultra_light'] ) . ';'
			. '--text-dark:' . $ld( $s['text_dark'], $s['text_light'] ) . ';'
			. '--text-dark-muted:' . $ld( $s['text_dark_muted'], $s['text_light_muted'] ) . ';'
			. '--text-light:' . $ld( $s['text_light'], $s['text_dark'] ) . ';'
			. '--text-light-muted:' . $ld( $s['text_light_muted'], $s['text_dark_muted'] ) . ';'
			. '--bg:var(--bg-ultra-light);--text:var(--text-dark)'
			. '}';

		if ( ! empty( $s['assignments'] ) ) {
			// Background assignments, set the surface + readable text, headings,
			// icons, links and focus colour inside it (all overridable variables).
			$rel   = array();
			$links = $dark ? self::dark_context_links( $ctx ) : array();
			foreach ( self::CONTEXTS as $surface ) {
				$key   = 'ctx_' . str_replace( '-', '_', $surface ) . '_';
				$light = in_array( $surface, array( 'ultra-light', 'light' ), true );
				$text  = '' !== $s[ $key . 'text' ] ? $s[ $key . 'text' ] : ( $light ? 'var(--text-dark)' : 'var(--text-light)' );
				$head  = '' !== $s[ $key . 'heading' ] ? $s[ $key . 'heading' ] : $text;
				$muted = $light ? 'var(--text-dark-muted)' : 'var(--text-light-muted)';
				$rel[] = "--bg-$surface-text:$text;--bg-$surface-heading:$head" . ( '' !== $s[ $key . 'button' ] ? ";--bg-$surface-button:" . $s[ $key . 'button' ] : '' ) . ( '' !== $s[ $key . 'icon' ] ? ";--bg-$surface-icon:" . $s[ $key . 'icon' ] : '' );
				$extra = '';
				$reset = array();
				if ( '' !== $s[ $key . 'icon' ] ) {
					$extra .= ';--icon-scheme:var(--bg-' . $surface . '-icon)';
				}
				foreach ( array(
					'link'       => '--link-color',
					'link_hover' => '--link-color-hover',
					'focus'      => '--focus-color',
				) as $p => $var ) {
					if ( '' !== $s[ $key . $p ] ) {
						$extra .= ";$var:" . $s[ $key . $p ];
					} elseif ( isset( $links[ $p ] ) && ! $light ) {
						// Light in dark mode: a link takes the light side of the site colour (light mode reads the site value).
						$extra  .= ";$var:" . $links[ $p ][1];
						$reset[] = $var . ':' . $links[ $p ][0];
					}
				}
				if ( $reset ) {
					// Areas inside it with a scheme of their own get the site link colour back.
					$css[] = ':where(.bg--' . $surface . ') :where(' . implode( ',', self::dark_islands( $s ) ) . '){' . implode( ';', $reset ) . '}';
				}
				$css[]     = ".bg--$surface{background-color:var(--bg-$surface);--bg:var(--bg-$surface);--text:var(--bg-$surface-text);--text-muted:$muted;--relative-heading-color:var(--bg-$surface-heading)$extra}";
				$css[]     = ":where(.bg--$surface){--relative-text-color:var(--bg-$surface-text);color:var(--relative-text-color, inherit)}";
				$css[]     = ".bg--$surface :where(h1,h2,h3,h4,h5,h6){color:var(--relative-heading-color, inherit)}";
				$classes[] = "bg--$surface";
			}
			$css[] = ':root{' . implode( ';', $rel ) . '}';

			// Text assignments.
			foreach ( array( 'light', 'dark', 'light-muted', 'dark-muted' ) as $t ) {
				$css[]     = ".text--$t{color:var(--text-$t)}";
				$classes[] = "text--$t";
			}
		}

		return array(
			'css'     => "\n/* --- TokenForge color scheme + assignments --- */\n" . implode( "\n", $css ) . "\n",
			'classes' => $classes,
		);
	}

	/**
	 * Parts inside a dark context (.bg--dark, .bg--ultra-dark) that keep their own
	 * scheme: a light context, a dark scheme area, a dark form or card, the Always
	 * dark selectors. In dark mode the dark context turns light and its links and
	 * outline buttons take the light side of their colour; inside these they keep
	 * the side their own scheme gives, as before.
	 * shortcut: card_selectors added in the settings are not listed (only .card); add them if a site uses dark cards that way.
	 *
	 * @param array $s Scheme settings.
	 * @return string[] Selectors.
	 */
	public static function dark_islands( $s ) {
		$out = array( '.bg--light', '.bg--ultra-light', '.scheme--dark', '.form--dark', '.card[class*="--dark"]:not(.bg--dark,.bg--ultra-dark)' );
		if ( 'dark' === Components::settings()['card_scheme'] ) {
			$out[] = '.card';
		}
		$always = array_filter( array_map( 'trim', preg_split( '/[\r\n]+|,(?![^(]*\))/', str_replace( '"', '', (string) $s['force_dark'] ) ) ) );
		return array_merge( $out, $always );
	}

	/**
	 * A colour's light side written out as #rrggbb, '' when it is not one plain,
	 * opaque colour (light-dark() values give their light side).
	 *
	 * @param string     $v   Colour.
	 * @param array|null $ctx dark_context().
	 * @return string
	 */
	public static function light_hex( $v, $ctx ) {
		$v   = (string) $v;
		$rgb = $ctx && '' !== $v && false === strpbrk( $v, ';{}<>' ) ? Contrast::parse( $v, $ctx['vars'], false, 0, true ) : null;
		return $rgb && $rgb[3] >= 1 ? sprintf( '#%02x%02x%02x', round( $rgb[0] * 255 ), round( $rgb[1] * 255 ), round( $rgb[2] * 255 ) ) : '';
	}

	/**
	 * The site link colours for the dark contexts in dark mode: [ site value,
	 * light-dark( site value, its light side ) ] per setting, so light mode reads
	 * the site value as before. Empty when TokenForge does not colour links or a
	 * colour is not one plain opaque colour.
	 *
	 * @param array|null $ctx dark_context().
	 * @return array link|link_hover => [ site, dark context ]
	 */
	public static function dark_context_links( $ctx ) {
		$c = Components::settings();
		if ( ! $ctx || empty( $c['base_links'] ) ) {
			return array();
		}
		$out = array();
		foreach ( array(
			'link'       => 'link_color',
			'link_hover' => 'link_color_hover',
		) as $k => $setting ) {
			$hex = self::light_hex( $c[ $setting ], $ctx );
			if ( '' !== $hex ) {
				$out[ $k ] = array( $c[ $setting ], 'light-dark(' . $c[ $setting ] . ', ' . $hex . ')' );
			}
		}
		return $out;
	}

	/**
	 * What the dark mode checks read: the switched-on tokens (name => value) and
	 * whether any colour token switches on its own (light-dark()). Null with dark
	 * mode off. Read once per build and passed on.
	 *
	 * @return array|null { vars: array, flips: bool }
	 */
	public static function dark_context() {
		static $memo = array( '', null );
		if ( 'auto' !== self::settings()['dark_mode'] ) {
			return null;
		}
		// Read once per request while the tokens stay the same (the editor, REST and the builder sync build live).
		$key = md5( (string) wp_json_encode( array( get_option( Settings::OPTION_TOKENS ), get_option( Palette::OPTION ) ) ) );
		if ( $key === $memo[0] ) {
			return $memo[1];
		}
		$ctx = array(
			'vars'  => array(),
			'flips' => false,
		);
		foreach ( Settings::get_instance()->all_variables() as $t ) {
			if ( ! empty( $t['enabled'] ) ) {
				$ctx['vars'][ $t['name'] ] = (string) $t['value'];
				$ctx['flips']              = $ctx['flips'] || ( 'color' === $t['group'] && false !== strpos( $t['value'], 'light-dark(' ) );
			}
		}
		$memo = array( $key, $ctx );
		return $ctx;
	}

	/**
	 * Is a background one solid colour that stays the same in dark mode (a
	 * builder's header set to var(--white))? Its text has to stay light-side.
	 * See-through colours (transparent, alpha, a mix with transparent) and
	 * variables the tokens do not hold are not: what is behind them shows.
	 *
	 * @param string $v   CSS value (!important allowed).
	 * @param array  $ctx dark_context().
	 * @return bool
	 */
	public static function fixed_fill( $v, $ctx ) {
		$v = trim( (string) preg_replace( '/!\s*important\s*$/i', '', (string) $v ) );
		if ( ! $ctx || '' === $v || ! self::is_color( $v, $ctx, false ) || self::switches( $v, $ctx ) ) {
			return false;
		}
		// A colour the contrast engine can read (var() chains followed), with nothing behind it showing.
		$c = Contrast::parse( $v, $ctx['vars'], false, 0, true );
		return $c && $c[3] >= 1;
	}

	/**
	 * A scheme colour for dark mode: light-dark( value, its pair ), unless it
	 * switches on its own (its var() chain reaches a light-dark() colour
	 * token): wrapping again would flip twice and cancel out. Decided per value,
	 * so a fixed text colour still flips beside a switching palette. Only two
	 * plain colours are wrapped: light-dark() takes nothing else.
	 *
	 * @param string     $light   Light side (the setting).
	 * @param string     $darkval Dark side (its paired setting).
	 * @param array|null $ctx     dark_context().
	 * @return string
	 */
	private static function wrap( $light, $darkval, $ctx ) {
		return $ctx && ! self::switches( $light, $ctx ) && self::is_color( $light, $ctx, true ) && self::is_color( $darkval, $ctx, true ) ? "light-dark($light, $darkval)" : $light;
	}

	/**
	 * A page background (body, a boxed page's device, cards) for dark mode: a
	 * fixed plain colour gets a dark side when that makes the least readable
	 * colour on it (its text, headings, links) more readable in dark mode,
	 * measured as build() writes the colours. Light mode is the setting as it
	 * is. One that already switches is left alone; when a colour cannot be
	 * measured, it flips only if every colour on it switches.
	 *
	 * @param string     $v       Background ('' = not set).
	 * @param string[]   $on      The colours drawn on it, text first ('' = not set: the
	 *                            browser's text, which switches; an unset heading or link follows the text).
	 * @param string     $darkval Its dark side (transparent: the page surface shows).
	 * @param array|null $ctx     dark_context().
	 * @return string
	 */
	public static function page_bg( $v, $on, $darkval, $ctx ) {
		if ( ! $ctx || '' === (string) $v || self::switches( $v, $ctx ) || ! self::is_color( $v, $ctx, false ) ) {
			return $v;
		}
		$s    = self::settings();
		$vars = $ctx['vars'];
		foreach ( self::PAIRS as $n => $opp ) {
			$vars[ $n ] = self::wrap( $s[ str_replace( '-', '_', $n ) ], $s[ str_replace( '-', '_', $opp ) ], $ctx );
		}
		$vars += array(
			'bg'   => 'var(--bg-ultra-light)',
			'text' => 'var(--text-dark)',
		);
		$bg    = array( Contrast::parse( $v, $vars, true, 0, true ), Contrast::parse( 'transparent' === $darkval ? 'var(--bg-ultra-light)' : $darkval, $vars, true, 0, true ) );
		$low   = $bg[0] && $bg[1] ? array( 21, 21 ) : null;
		foreach ( null === $low ? array() : array_values( $on ) as $i => $c ) {
			$c = '' === (string) $c ? ( 0 === $i ? 'light-dark(#000, #fff)' : '' ) : $c;
			if ( '' === $c ) {
				continue;
			}
			$fg = Contrast::parse( $c, $vars, true, 0, true );
			if ( ! $fg ) {
				$low = null;
				break;
			}
			$low = array( min( $low[0], Contrast::ratio( $fg, $bg[0] ) ), min( $low[1], Contrast::ratio( $fg, $bg[1] ) ) );
		}
		if ( null === $low || $low[0] === $low[1] ) {
			// Not measurable, or nothing to tell them apart: flip when every colour on it switches.
			foreach ( $on as $c ) {
				if ( '' !== (string) $c && ! self::switches( $c, $ctx ) ) {
					return $v;
				}
			}
			return "light-dark($v, $darkval)";
		}
		return $low[1] > $low[0] ? "light-dark($v, $darkval)" : $v;
	}

	/**
	 * Is a value one plain colour (all light-dark() takes)? var() is followed
	 * through the tokens; one they do not hold gives its fallback, else $unknown.
	 * Gradients, images, keywords such as inherit, and !important are not.
	 *
	 * @param string $v       CSS value.
	 * @param array  $ctx     dark_context().
	 * @param bool   $unknown Answer for a variable the tokens do not hold.
	 * @param int    $depth   Recursion guard.
	 * @return bool
	 */
	private static function is_color( $v, $ctx, $unknown, $depth = 0 ) {
		$v = trim( (string) $v );
		if ( $depth > 12 || false !== strpos( $v, '!' ) ) {
			return false;
		}
		if ( preg_match( '/^var\(\s*--([a-z0-9-]+)\s*(?:,(.*))?\)$/is', $v, $m ) ) {
			$n = strtolower( $m[1] );
			if ( isset( $ctx['vars'][ $n ] ) ) {
				return self::is_color( $ctx['vars'][ $n ], $ctx, $unknown, $depth + 1 );
			}
			return isset( $m[2] ) ? self::is_color( $m[2], $ctx, $unknown, $depth + 1 ) : $unknown;
		}
		// A hex colour, one colour function (balanced brackets, nothing after it) or a colour name.
		return (bool) preg_match( '/^(?:#[0-9a-f]{3,8}|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)(\((?:[^()]++|(?1))*\))|(?!(?:none|auto|inherit|initial|unset|revert|transparent|currentcolor)$)[a-z]+)$/i', $v );
	}

	/**
	 * Does a value switch with the scheme on its own: a light-dark() in it or in a
	 * token it reads through var()? The context variables count as switching:
	 * with dark mode on build() makes every one of them switch. A variable the
	 * tokens do not hold (a builder global, an SCSS override) switches when the
	 * palette does, as the old palette-wide rule had it.
	 *
	 * Shortcut: a var() fallback is read even when its variable is set, so a set
	 * fixed variable with a light-dark() fallback counts as switching (left as
	 * before); parse fallbacks if a site ever needs that.
	 *
	 * @param string $v    CSS value.
	 * @param array  $ctx  dark_context().
	 * @param array  $seen Names already followed (each is read once, loops end).
	 * @return bool
	 */
	public static function switches( $v, $ctx, &$seen = array() ) {
		if ( false !== stripos( $v, 'light-dark(' ) ) {
			return true;
		}
		if ( ! preg_match_all( '/var\(\s*--([a-z0-9-]+)/i', $v, $m ) ) {
			return false;
		}
		foreach ( $m[1] as $n ) {
			$n = strtolower( $n );
			if ( isset( $seen[ $n ] ) ) {
				continue;
			}
			$seen[ $n ] = true;
			if ( preg_match( '/^(?:bg|text|bg-(?:ultra-)?(?:light|dark)|text-(?:light|dark)(?:-muted)?)$/', $n ) || ( isset( $ctx['vars'][ $n ] ) ? self::switches( $ctx['vars'][ $n ], $ctx, $seen ) : $ctx['flips'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is the visitor light/dark switcher live? Needs dark mode on.
	 *
	 * @return bool
	 */
	public static function switcher_enabled() {
		$s = self::settings();
		return 'auto' === $s['dark_mode'] && ! empty( $s['switcher'] );
	}

	/**
	 * Markup for a toggle button (used by the shortcode).
	 *
	 * @param string $css_class Extra classes.
	 * @return string
	 */
	public static function toggle_markup( $css_class = '' ) {
		$cls = trim( 'scheme-toggle ' . $css_class );
		return '<button type="button" class="' . esc_attr( $cls ) . '" aria-label="' . esc_attr__( 'Toggle dark mode', 'tokenforge' ) . '">' . self::toggle_icons() . '</button>';
	}

	/**
	 * The moon + sun icons (CSS shows the one for the scheme you'd switch to).
	 *
	 * @return string
	 */
	public static function toggle_icons() {
		$moon = '<svg class="tf-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
		$sun  = '<svg class="tf-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
		return $moon . $sun;
	}
}
