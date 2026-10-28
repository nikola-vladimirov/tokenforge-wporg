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
			'assignments' => true,   // output .bg--* / .text--* + context vars
			'dark_mode'   => 'off',  // 'off' | 'auto', generate dark values (light-dark())
			'default_scheme' => 'auto', // with dark mode: 'light' | 'dark' | 'auto' (follow the visitor's OS)
			'switcher'    => false,  // visitor light/dark toggle (.scheme-toggle / shortcode)
			// Background relationships: what each .bg--* sets inside it.
			'ctx_ultra_light_text'    => 'var(--text-dark)',
			'ctx_light_text'          => 'var(--text-dark)',
			'ctx_dark_text'           => 'var(--text-light)',
			'ctx_ultra_dark_text'     => 'var(--text-light)',
			'ctx_ultra_light_heading' => 'var(--text-dark)',
			'ctx_light_heading'       => 'var(--text-dark)',
			'ctx_dark_heading'        => 'var(--text-light)',
			'ctx_ultra_dark_heading'  => 'var(--text-light)',
			'ctx_ultra_light_button'  => '', // a button family (e.g. primary) for btn--* inside
			'ctx_light_button'        => '',
			'ctx_dark_button'         => '',
			'ctx_ultra_dark_button'   => '',
			'ctx_ultra_light_icon'    => '', // icon colour scheme: light | dark
			'ctx_light_icon'          => '',
			'ctx_dark_icon'           => '',
			'ctx_ultra_dark_icon'     => '',
			'ctx_ultra_light_link'    => '',
			'ctx_light_link'          => '',
			'ctx_dark_link'           => '',
			'ctx_ultra_dark_link'     => '',
			'ctx_ultra_light_link_hover' => '',
			'ctx_light_link_hover'       => '',
			'ctx_dark_link_hover'        => '',
			'ctx_ultra_dark_link_hover'  => '',
			'ctx_ultra_light_focus'   => '',
			'ctx_light_focus'         => '',
			'ctx_dark_focus'          => '',
			'ctx_ultra_dark_focus'    => '',
			// Context colours (the light-scheme values; dark mode swaps the pairs).
			'bg_ultra_light'   => 'var(--base-ultra-light, #f7f8fa)',
			'bg_light'         => 'var(--base-light, #e9edf2)',
			'bg_dark'          => 'var(--base-dark, #1a2433)',
			'bg_ultra_dark'    => 'var(--base-ultra-dark, #0a0f18)',
			'text_dark'        => 'var(--base, #10151c)',
			'text_dark_muted'  => 'var(--base-semi-dark, #47505c)',
			'text_light'       => 'var(--base-ultra-light, #f7f8fa)',
			'text_light_muted' => 'var(--base-light, #c9d2dd)',
			// Always render these selectors in one scheme (one per line / comma separated).
			'force_dark'       => '',
			'force_light'      => '',
		);
	}

	/**
	 * Keys that hold CSS values (sanitised as text).
	 */
	const VALUE_KEYS = array( 'bg_ultra_light', 'bg_light', 'bg_dark', 'bg_ultra_dark', 'text_dark', 'text_dark_muted', 'text_light', 'text_light_muted', 'force_dark', 'force_light' );

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
				'assignments' => ! empty( $in['assignments'] ),
				'dark_mode'   => ( isset( $in['dark_mode'] ) && 'auto' === $in['dark_mode'] ) ? 'auto' : 'off',
				'default_scheme' => ( isset( $in['default_scheme'] ) && in_array( $in['default_scheme'], array( 'light', 'dark' ), true ) ) ? $in['default_scheme'] : 'auto',
				'switcher'    => ! empty( $in['switcher'] ),
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
					$v = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $v ) ); // a family name
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

		// Helper: a value that flips between light + dark when dark mode is on -
		// unless the palette already flips itself (light-dark() colour tokens, e.g.
		// imported from ACSS): wrapping again would flip twice and cancel out.
		$flip = $dark && ! self::palette_flips();
		$ld   = function ( $light, $darkval ) use ( $flip ) {
			return $flip ? "light-dark($light, $darkval)" : $light;
		};

		// Forced schemes for specific selectors.
		foreach ( array( 'dark' => $s['force_dark'], 'light' => $s['force_light'] ) as $scheme => $sel ) {
			// One per line or comma separated (commas inside :is()/:not() kept); unquote.
			$list = array_filter( array_map( 'trim', preg_split( '/[\r\n]+|,(?![^(]*\))/', str_replace( '"', '', (string) $sel ) ) ) );
			if ( $list ) {
				$css[] = implode( ',', $list ) . '{color-scheme:' . $scheme . '}';
			}
		}

		if ( $dark ) {
			// The site's default scheme: fixed light/dark, or following the visitor's OS.
			$css[] = ':root{color-scheme:' . ( 'auto' === $s['default_scheme'] ? 'light dark' : $s['default_scheme'] ) . '}';
			$css[] = '.scheme--light{color-scheme:light}';
			$css[] = '.scheme--dark{color-scheme:dark}';
			$classes[] = 'scheme--light';
			$classes[] = 'scheme--dark';

			if ( ! empty( $s['switcher'] ) ) {
				// A visitor choice (stored by the toggle script) overrides the OS preference.
				$css[] = ':root[data-scheme="light"]{color-scheme:light}:root[data-scheme="dark"]{color-scheme:dark}';
				$css[] = '.scheme-toggle{display:inline-grid;place-items:center;inline-size:2.5em;block-size:2.5em;padding:0;border:1px solid color-mix(in srgb, currentColor 25%, transparent);border-radius:999px;background:transparent;color:inherit;cursor:pointer}';
				$css[] = '.scheme-toggle svg{inline-size:1.25em;block-size:1.25em;grid-area:1/1}';
				// Show the moon while light is in effect, the sun while dark is.
				$css[] = '.scheme-toggle .tf-sun{display:none}';
				$css[] = ':root[data-scheme="dark"] .scheme-toggle .tf-sun{display:block}:root[data-scheme="dark"] .scheme-toggle .tf-moon{display:none}';
				$css[] = '@media (prefers-color-scheme:dark){:root:not([data-scheme="light"]) .scheme-toggle .tf-sun{display:block}:root:not([data-scheme="light"]) .scheme-toggle .tf-moon{display:none}}';
				$classes[] = 'scheme-toggle';
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
			$rel = array();
			foreach ( self::CONTEXTS as $surface ) {
				$key   = 'ctx_' . str_replace( '-', '_', $surface ) . '_';
				$light = in_array( $surface, array( 'ultra-light', 'light' ), true );
				$text  = '' !== $s[ $key . 'text' ] ? $s[ $key . 'text' ] : ( $light ? 'var(--text-dark)' : 'var(--text-light)' );
				$head  = '' !== $s[ $key . 'heading' ] ? $s[ $key . 'heading' ] : $text;
				$muted = $light ? 'var(--text-dark-muted)' : 'var(--text-light-muted)';
				$rel[] = "--bg-$surface-text:$text;--bg-$surface-heading:$head" . ( '' !== $s[ $key . 'button' ] ? ";--bg-$surface-button:" . $s[ $key . 'button' ] : '' ) . ( '' !== $s[ $key . 'icon' ] ? ";--bg-$surface-icon:" . $s[ $key . 'icon' ] : '' );
				$extra = '';
				if ( '' !== $s[ $key . 'icon' ] ) {
					$extra .= ';--icon-scheme:var(--bg-' . $surface . '-icon)';
				}
				foreach ( array( 'link' => '--link-color', 'link_hover' => '--link-color-hover', 'focus' => '--focus-color' ) as $p => $var ) {
					if ( '' !== $s[ $key . $p ] ) {
						$extra .= ";$var:" . $s[ $key . $p ];
					}
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
	 * Do the colour tokens switch with the scheme on their own (light-dark())?
	 *
	 * @return bool
	 */
	public static function palette_flips() {
		foreach ( Settings::get_instance()->all_variables() as $t ) {
			if ( ! empty( $t['enabled'] ) && 'color' === $t['group'] && false !== strpos( $t['value'], 'light-dark(' ) ) {
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
	 * @param string $class Extra classes.
	 * @return string
	 */
	public static function toggle_markup( $class = '' ) {
		$cls = trim( 'scheme-toggle ' . $class );
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