<?php
/**
 * Defaults & misc toggles.
 *
 * The smaller on/off functions from ACSS's Options: reset, smooth scrolling,
 * reduce-motion, focus styles, auto object-fit, sticky, is-bg, screen-reader
 * utilities, and CSS columns. Each is an opt-in/opt-out CSS layer.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Misc default styles + utilities.
 */
class Defaults {

	const OPTION = 'tokenforge_defaults';

	/**
	 * Defaults (sensible on/off out of the box).
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'defaults', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'root_font_size'        => 100,   // % of the browser default (100% → 1rem = 16px, 62.5% → 10px)
			'output_root_font_size' => true,  // override html{font-size} (Bricks ships 62.5%), like ACSS
			'reset'         => true,
			'smooth_scroll' => true,
			'reduce_motion' => true,
			'focus_styles'  => true,
			'focus_color'   => 'var(--primary, var(--action, #2563eb))',
			'focus_width'   => '2px',
			'focus_offset'  => '2px',
			'selection_bg'  => '',   // ::selection background (empty = browser default)
			'selection_text' => '',
			'object_fit'    => false, // global img{object-fit:cover} is aggressive; off by default
			'sticky'        => true,
			'is_bg'         => true,
			'sr_only'       => true,
			'columns'       => true,
			// Reset detail: images/svg/video as blocks (a modern reset; ACSS keeps them inline).
			'media_block'   => true,
			// Scroll offset for in-page anchors: fluid margin + (optionally) the header height.
			'scroll_margin_min'    => '0',
			'scroll_margin_max'    => '0',
			'scroll_offset_header' => true,
			// Sticky elements stick below the header (or a custom offset).
			'sticky_offset_header' => true,
			'sticky_offset_custom' => '60px',
			// Push the first section below a fixed/overlay header.
			'content_offset'            => false,
			'content_offset_exclusions' => '.content-offset--off',
			'content_offset_header_not' => '[data-sticky-header="0"]',
			// Column (multi-column text) widths + rule widths.
			'col_width_s'      => '13rem',
			'col_width_m'      => '25rem',
			'col_width_l'      => '38rem',
			'col_rule_width_s' => '.5px',
			'col_rule_width_m' => '1px',
			'col_rule_width_l' => '3px',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return Modules::apply( 'defaults', wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() ) );
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
			if ( is_bool( $v ) ) {
				$clean[ $k ] = ! empty( $in[ $k ] );
			} elseif ( is_string( $v ) ) {
				// Selector fields keep ">" (child combinator).
				$strip       = in_array( $k, array( 'content_offset_exclusions', 'content_offset_header_not' ), true ) ? array( ';', '{', '}', '<' ) : array( ';', '{', '}', '<', '>' );
				$clean[ $k ] = isset( $in[ $k ] ) ? trim( str_replace( $strip, '', sanitize_text_field( $in[ $k ] ) ) ) : $v;
			}
		}
		$pct                     = isset( $in['root_font_size'] ) ? (float) $in['root_font_size'] : 100;
		$clean['root_font_size'] = ( $pct >= 50 && $pct <= 200 ) ? $pct : 100;
		update_option( self::OPTION, $clean );
	}

	/**
	 * After an Automatic.css import: adopt its root font size (a % token) so
	 * TokenForge keeps overriding Bricks the same way ACSS did.
	 *
	 * @return void
	 */
	public static function adopt_root_token() {
		foreach ( Settings::get_instance()->get_tokens() as $t ) {
			if ( 'root-font-size' === $t['name'] && preg_match( '/^\s*([0-9]*\.?[0-9]+)\s*%\s*$/', $t['value'], $m ) ) {
				$pct = (float) $m[1];
				if ( $pct >= 50 && $pct <= 200 ) {
					$s                          = self::settings();
					$s['root_font_size']        = $pct;
					$s['output_root_font_size'] = true;
					update_option( self::OPTION, $s );
				}
				return;
			}
		}
	}

	/**
	 * Pixels per rem, from the root font size setting (16 at 100%, 10 at 62.5%).
	 * Every px → rem conversion in TokenForge goes through this.
	 *
	 * @return float
	 */
	public static function root_px() {
		$s   = self::settings();
		$pct = (float) $s['root_font_size'];
		return ( $pct >= 50 && $pct <= 200 ) ? 16 * $pct / 100 : 16.0;
	}

	/**
	 * The root font-size rule (kept out of any cascade layer so it beats the
	 * 62.5% Bricks sets inside its own @layer).
	 *
	 * @return string
	 */
	public static function root_css() {
		$s = self::settings();
		if ( empty( $s['output_root_font_size'] ) ) {
			return '';
		}
		$pct = rtrim( rtrim( sprintf( '%.3f', (float) $s['root_font_size'] ), '0' ), '.' );
		// !important (as ACSS does) so it also beats an html font size set in
		// Bricks theme styles or the theme, not just Bricks' layered default.
		return ':root{--root-font-size:' . $pct . '%}html{font-size:var(--root-font-size)!important}' . "\n";
	}

	/**
	 * Build CSS + class names.
	 *
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build() {
		$s       = self::settings();
		$css     = array();
		$reset   = array(); // never layered (as ACSS): box-sizing etc. must beat layered components
		$classes = array();
		$vars    = array();

		if ( $s['reset'] ) {
			$reset[] = ':root{interpolate-size:allow-keywords}';
			$reset[] = '*,*::before,*::after{box-sizing:border-box}';
			$reset[] = 'body{margin:0;min-block-size:100svh}';
			$reset[] = 'input,button,textarea,select{font:inherit}';
			// Plain buttons take the button typography (not nav toggles or btn--* buttons).
			$reset[] = 'button:not(nav button,[class*="btn--"]){line-height:var(--btn-line-height);letter-spacing:var(--btn-letter-spacing);text-transform:var(--btn-text-transform);text-decoration:var(--btn-text-decoration);text-align:var(--btn-text-align);font-weight:var(--btn-font-weight);font-style:var(--btn-font-style);font-family:var(--btn-font-family)}';
			$reset[] = ':where(dd){margin-inline-start:0}';
			if ( $s['media_block'] ) {
				$reset[] = 'img,picture,svg,video{display:block;max-width:100%}';
			}
			$reset[] = 'ul[class],ol[class]{margin:0;padding:0}';
		}

		// Anchor offsets: fluid scroll margin (+ header height) on every [id].
		$vw   = Scale_Engine::settings();
		$smin = Width::to_px( (string) $s['scroll_margin_min'] );
		$smax = Width::to_px( (string) $s['scroll_margin_max'] );
		$vars['scroll-margin'] = ( null !== $smin && null !== $smax ) ? Scale_Engine::fluid( $smin, $smax, (float) $vw['min_vw'], (float) $vw['max_vw'] ) : '0px';
		$vars['offset']        = $s['scroll_offset_header'] ? 'calc(var(--scroll-margin) + var(--header-height, 0px))' : 'var(--scroll-margin)';
		$css[]                 = '[id]{scroll-margin-top:var(--offset, 0)}';

		if ( $s['smooth_scroll'] ) {
			// Jumping (not gliding) while keyboard-navigating keeps focus visible.
			$css[] = 'html{scroll-behavior:smooth}html:has(:focus-visible){scroll-behavior:auto}';
		}
		if ( $s['reduce_motion'] ) {
			$css[] = '@media(prefers-reduced-motion:reduce){html:focus-within{scroll-behavior:auto}*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}';
		}
		if ( $s['focus_styles'] ) {
			$vars['focus-color']  = $s['focus_color'];
			$vars['focus-width']  = $s['focus_width'];
			$vars['focus-offset'] = $s['focus_offset'];
			$ring                 = 'outline-style:solid;outline-color:var(--focus-color);outline-width:var(--focus-width);outline-offset:var(--focus-offset)';
			$css[]                = ':focus{outline:none;box-shadow:none}';
			$css[]                = ':focus-visible{' . $ring . '}';
			$css[]                = '@supports not selector(:focus-visible){:focus{' . $ring . '}}';
		}
		// Skip link: styled as a button by Components; without buttons, a plain fallback.
		$comp = Components::settings();
		if ( empty( $comp['base_buttons'] ) ) {
			$css[] = '.skip-link{position:absolute!important;inset-block-start:1rem;inset-inline-start:1rem;z-index:100;transform:translateY(-250%);padding:.75em 1.5em;background:var(--primary, #10151c);color:var(--primary-ultra-light, #fff);text-decoration:none}';
			$css[] = '.skip-link:focus-visible{transform:translateY(0)}';
		}
		$classes[] = 'skip-link';

		if ( '' !== $s['selection_bg'] || '' !== $s['selection_text'] ) {
			$css[] = '::selection{' . ( '' !== $s['selection_bg'] ? 'background-color:' . $s['selection_bg'] . ';' : '' ) . ( '' !== $s['selection_text'] ? 'color:' . $s['selection_text'] . ';' : '' ) . '}';
		}
		if ( $s['object_fit'] ) {
			$css[] = 'img{object-fit:var(--object-fit, cover);object-position:var(--object-position, 50% 50%)}';
		}
		// Object-fit utilities always available.
		$css[]     = '.object-cover{object-fit:cover}';
		$css[]     = '.object-contain{object-fit:contain}';
		$classes[] = 'object-cover';
		$classes[] = 'object-contain';

		// Clickable card: the heading link stretched over the card; zero specificity except the focus ring swap.
		$hl        = 'is-clickable :is(h1,h2,h3,h4,h5,h6) a';
		$css[]     = ':where(.is-clickable){position:relative}';
		$css[]     = ':where(.' . $hl . '){position:static}';
		$css[]     = ':where(.' . $hl . ')::after{content:"";position:absolute;inset:0;border-radius:var(--card-radius, var(--radius, 0))}';
		$css[]     = ':where(.is-clickable .is-above){position:relative;z-index:1}';
		$css[]     = '.' . $hl . ':focus-visible{outline:none}';
		$css[]     = '.' . $hl . ':focus-visible::after{outline:var(--focus-width, 2px) solid var(--focus-color, currentColor);outline-offset:calc(-1 * var(--focus-width, 2px))}';
		// In the builder canvas the stretched link would block selecting the card's elements.
		$css[]     = '.bricks-area .' . $hl . '::after{content:none}';
		array_push( $classes, 'is-clickable', 'is-above' );

		if ( $s['is_bg'] ) {
			// A background layer (image, video, figure…) behind its parent's content.
			$css[]     = ':has(>.is-bg){position:relative;isolation:isolate}';
			$css[]     = '.is-bg{position:var(--bg-position, absolute)!important;inset:var(--bg-inset, 0);inline-size:var(--bg-width, 100%);max-inline-size:100%;block-size:var(--bg-height, 100%);border-radius:var(--bg-radius, 0);object-fit:var(--bg-object-fit, cover);object-position:var(--bg-object-position, center);z-index:var(--bg-z-index, -2);pointer-events:none}';
			$css[]     = '.is-bg>img{inline-size:100%;block-size:100%;object-fit:var(--bg-object-fit, cover);object-position:var(--bg-object-position, center);border-radius:var(--bg-radius, 0)}';
			$classes[] = 'is-bg';
		}
		if ( $s['sticky'] ) {
			$vars['sticky-offset-custom'] = $s['sticky_offset_custom'];
			$vars['sticky-offset']        = $s['sticky_offset_header'] ? 'var(--header-height, var(--sticky-offset-custom, 0))' : 'var(--sticky-offset-custom, 0)';
			$css[]                        = '.sticky{position:sticky;inset-block-start:var(--sticky-offset, 0)}';
			$classes[]                    = 'sticky';
		}
		if ( $s['content_offset'] ) {
			// The page's first element starts below a fixed/overlay header.
			$not   = trim( str_replace( '"', "'", (string) $s['content_offset_exclusions'] ) );
			$hnot  = trim( str_replace( '"', "'", (string) $s['content_offset_header_not'] ) );
			$body  = 'body' . ( '' !== $not ? ':not(' . $not . ')' : '' ) . ( '' !== $hnot ? ':not(:has(header:is(' . $hnot . ')))' : '' );
			$css[] = $body . ' main:not(.panel-content)>*:first-child>*:first-child:not(:is(.bricks-shape-divider,.bricks-background-video-wrapper)),main>*:first-child>.bricks-shape-divider~.brxe-container{margin-block-start:var(--header-height)}';
			$classes[] = 'content-offset--off';
		}
		if ( $s['sr_only'] ) {
			$css[] = '.sr-only,.visually-hidden,.hidden-accessible{position:absolute!important;inline-size:1px!important;block-size:1px!important;padding:0;margin:-1px;overflow:hidden;clip-path:inset(100%);white-space:nowrap;border:0}';
			// In the Bricks canvas show a small marker so the hidden element can be found.
			$css[] = '.bricks-area :is(.sr-only,.visually-hidden,.hidden-accessible){position:relative!important;inline-size:2ch!important;block-size:2ch!important;margin:0;line-height:1;visibility:hidden}';
			$css[] = '.bricks-area :is(.sr-only,.visually-hidden,.hidden-accessible)::before{content:"A";position:absolute;inset:0 auto auto 0;display:grid;place-items:center;inline-size:20px;block-size:20px;border-radius:50%;background:#111;color:#f5f5f5;font-size:max(60%, 13px);visibility:visible}';
			array_push( $classes, 'sr-only', 'visually-hidden', 'hidden-accessible' );
		}
		if ( $s['columns'] ) {
			foreach ( array( 's', 'm', 'l' ) as $sz ) {
				$vars[ "col-width-$sz" ]      = $s[ "col_width_$sz" ];
				$vars[ "col-rule-width-$sz" ] = $s[ "col_rule_width_$sz" ];
			}
			foreach ( array( 2, 3, 4 ) as $n ) {
				$css[]     = ".columns-$n{column-count:$n;column-gap:var(--grid-gap)}";
				$classes[] = "columns-$n";
			}
		}

		$decl = array();
		foreach ( $vars as $k => $v ) {
			if ( '' !== (string) $v ) {
				$decl[] = "--$k:$v";
			}
		}
		array_unshift( $css, ':root{' . implode( ';', $decl ) . '}' );

		return array(
			'reset'   => $reset ? "\n/* --- TokenForge reset --- */\n" . implode( "\n", $reset ) . "\n" : '',
			'css'     => "\n/* --- TokenForge defaults & misc --- */\n" . implode( "\n", $css ) . "\n",
			'classes' => $classes,
		);
	}
}
