<?php
/**
 * Content / blog-post ("prose") styling.
 *
 * Gives long-form content (post bodies written in Gutenberg or rendered by a
 * builder's post-content element) a readable vertical rhythm and styles for
 * the elements editors produce: lists, quotes, figures, code, tables, rules.
 * Every rule is wrapped in :where() so it has zero specificity, any class,
 * block style or builder setting overrides it.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Prose CSS.
 */
class Content {

	const OPTION = 'tokenforge_content';

	/**
	 * Selectors that are treated as prose automatically (builder-agnostic).
	 */
	const AUTO_SELECTORS = array( '.brxe-post-content', '.wp-block-post-content', '.entry-content' );

	/**
	 * Site-wide blockquote styling: variable suffix (--blockquote-*) => default.
	 * Stored as bq_{suffix with underscores}.
	 */
	const BLOCKQUOTE = array(
		'padding'             => 'var(--space-m)',
		'gap'                 => '1em',
		'border-width'        => '0 0 0 3px',
		'border-style'        => 'solid',
		'border-color'        => 'var(--primary, currentColor)',
		'border-radius'       => 'var(--radius, 0)',
		'background'          => 'var(--neutral-ultra-light, transparent)',
		'box-shadow'          => 'none',
		'max-width'           => '',
		'text-color'          => 'inherit',
		'text-font-family'    => 'inherit',
		'text-font-style'     => 'normal',
		'text-font-size'      => 'var(--text-l)',
		'text-font-weight'    => 'normal',
		'text-line-height'    => 'inherit',
		'text-text-align'     => 'start',
		'text-text-transform' => 'none',
		'footer-padding'      => '0',
		'footer-margin-block' => '1em 0',
		'footer-font-family'  => 'inherit',
		'footer-font-size'    => 'var(--text-s)',
		'footer-font-weight'  => 'normal',
		'footer-font-style'   => 'normal',
		'footer-line-height'  => '1.5',
		'footer-text-transform' => 'none',
		'footer-color'        => 'currentColor',
		'cite-font-family'    => 'inherit',
		'cite-font-size'      => 'inherit',
		'cite-font-weight'    => 'inherit',
		'cite-font-style'     => 'inherit',
		'cite-line-height'    => 'inherit',
		'cite-text-transform' => 'inherit',
		'cite-color'          => 'currentColor',
	);

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'content', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		$bq = array();
		foreach ( self::BLOCKQUOTE as $k => $v ) {
			$bq[ 'bq_' . str_replace( '-', '_', $k ) ] = $v;
		}
		return $bq + array(
			'prose'         => true,                       // output the .prose class
			'prose_auto'    => true,                       // also style post-content containers
			'prose_spacing' => 'var(--space-m, 1.5em)',   // gap between blocks
			'prose_width'   => '',                         // optional measure, e.g. 70ch
			'prose_accent'  => 'var(--primary, currentColor)',
			'prose_heading_spacing' => '',   // space above headings (empty = 2× / 1.5× block spacing)
			'rich_text'     => true,         // also treat Bricks Rich Text (.brxe-text) as prose
			'smart_spacing' => true,         // zero stray margins on p/h/ul/ol/li so spacing comes from gaps + prose rules
			// Flow spacing (vertical rhythm between siblings) on .smart-spacing and
			// automatically inside Bricks Rich Text / post content.
			'flow'              => true,
			'flow_auto'         => true,
			'flow_selectors'    => '',   // extra containers that get flow spacing
			'paragraph_spacing' => '1em',
			'heading_spacing'   => '1.2em',
			'flow_spacing'      => 'var(--paragraph-spacing)',
			'list_spacing'      => '',
			'list_indent_spacing' => '2em',
			'list_item_spacing' => '.5em',
			'nested_list_spacing' => '',
			'nested_list_indent_spacing' => '1em',
			'nested_list_item_spacing' => '',
			'figure_spacing'    => 'var(--paragraph-spacing)',
			'figcaption_spacing' => '.5em',
			'blockquote_spacing' => 'var(--paragraph-spacing)',
			'h2_spacing' => '', 'h3_spacing' => '', 'h4_spacing' => '', 'h5_spacing' => '', 'h6_spacing' => '',
			// Blockquotes everywhere (not only in prose).
			'blockquotes'   => true,
			'bq_auto'       => true,         // style every <blockquote> (off = only the .blockquote class)
			'bq_exclude'    => ':is([class*="-card"] blockquote, figure[class*="-card"], figure[class*="-card"] blockquote)',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return Modules::apply( 'content', wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d  = self::defaults();
		$bq = array();
		foreach ( array_keys( self::BLOCKQUOTE ) as $k ) {
			$key        = 'bq_' . str_replace( '-', '_', $k );
			$bq[ $key ] = isset( $in[ $key ] ) ? trim( preg_replace( '/[;{}<>]/', '', (string) $in[ $key ] ) ) : $d[ $key ];
		}
		update_option(
			self::OPTION,
			$bq + array(
				'blockquotes'   => ! empty( $in['blockquotes'] ),
				'bq_auto'       => ! empty( $in['bq_auto'] ),
				// A selector: keep ">" (child combinator), drop what could end a rule or tag.
				'bq_exclude'    => isset( $in['bq_exclude'] ) ? trim( preg_replace( '/[;{}<]/', '', (string) $in['bq_exclude'] ) ) : $d['bq_exclude'],
				'prose'         => ! empty( $in['prose'] ),
				'prose_auto'    => ! empty( $in['prose_auto'] ),
				'prose_spacing' => self::clean_value( isset( $in['prose_spacing'] ) ? $in['prose_spacing'] : '', $d['prose_spacing'] ),
				'prose_width'   => self::clean_value( isset( $in['prose_width'] ) ? $in['prose_width'] : '', '' ),
				'prose_accent'  => self::clean_value( isset( $in['prose_accent'] ) ? $in['prose_accent'] : '', $d['prose_accent'] ),
				'prose_heading_spacing' => self::clean_value( isset( $in['prose_heading_spacing'] ) ? $in['prose_heading_spacing'] : '', '' ),
				'rich_text'     => ! empty( $in['rich_text'] ),
				'smart_spacing' => ! empty( $in['smart_spacing'] ),
				'flow'          => ! empty( $in['flow'] ),
				'flow_auto'     => ! empty( $in['flow_auto'] ),
				'flow_selectors' => isset( $in['flow_selectors'] ) ? trim( preg_replace( '/[;{}<]/', '', (string) $in['flow_selectors'] ) ) : '',
			) + self::clean_flow( $in, $d )
		);
	}

	/** Flow-spacing value keys. */
	const FLOW_KEYS = array( 'paragraph_spacing', 'heading_spacing', 'flow_spacing', 'list_spacing', 'list_indent_spacing', 'list_item_spacing', 'nested_list_spacing', 'nested_list_indent_spacing', 'nested_list_item_spacing', 'figure_spacing', 'figcaption_spacing', 'blockquote_spacing', 'h2_spacing', 'h3_spacing', 'h4_spacing', 'h5_spacing', 'h6_spacing' );

	/**
	 * Sanitised flow-spacing values (empty allowed = not set).
	 *
	 * @param array $in Raw input.
	 * @param array $d  Defaults.
	 * @return array
	 */
	private static function clean_flow( $in, $d ) {
		$out = array();
		foreach ( self::FLOW_KEYS as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? trim( preg_replace( '/[;{}<>]/', '', (string) $in[ $k ] ) ) : $d[ $k ];
		}
		return $out;
	}

	/**
	 * Keep a CSS value safe to drop into a declaration.
	 *
	 * @param string $v        Raw value.
	 * @param string $fallback Used when empty/invalid.
	 * @return string
	 */
	private static function clean_value( $v, $fallback ) {
		$v = trim( preg_replace( '/[;{}<>]/', '', (string) $v ) );
		return '' === $v ? $fallback : $v;
	}

	/**
	 * Build CSS + class names.
	 *
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build() {
		$s = self::settings();
		$pre = array();
		if ( ! empty( $s['smart_spacing'] ) ) {
			// Zero-specificity margin reset: spacing then comes from gaps + flow rules.
			$pre[] = 'body :where(p,h1,h2,h3,h4,h5,h6,ul,ol,li){margin-block:0}';
			$pre[] = 'body :where(p:last-of-type)+:is(h1,h2,h3,h4,h5,h6){margin-block-start:0}';
		}
		$pre = array_merge( $pre, self::flow_css( $s ) );
		// Blockquotes are a site-wide component: returned separately ('base') so
		// they cascade with the base styles, not inside the prose layer.
		$quotes = self::blockquote_css( $s );
		$base   = $quotes ? "\n/* --- TokenForge blockquotes --- */\n" . implode( "\n", $quotes ) . "\n" : '';
		$qclass = empty( $s['blockquotes'] ) ? array() : array( 'blockquote' );
		if ( ! empty( $s['flow'] ) ) {
			array_push( $qclass, 'smart-spacing', 'smart-spacing--off', 'smart-spacing-normalize' );
		}
		if ( empty( $s['prose'] ) && empty( $s['prose_auto'] ) ) {
			return array( 'css' => $pre ? "\n/* --- TokenForge content --- */\n" . implode( "\n", $pre ) . "\n" : '', 'base' => $base, 'classes' => $qclass );
		}

		$sel = array();
		if ( ! empty( $s['prose'] ) ) {
			$sel[] = '.prose';
		}
		if ( ! empty( $s['prose_auto'] ) ) {
			$sel = array_merge( $sel, self::AUTO_SELECTORS );
		}
		if ( ! empty( $s['rich_text'] ) ) {
			$sel[] = '.brxe-text';
		}
		$w    = ':where(' . implode( ',', $sel ) . ')';
		$line = 'color-mix(in srgb, currentColor 18%, transparent)';
		$tint = 'color-mix(in srgb, currentColor 7%, transparent)';

		$css   = $pre;
		$hs    = '' !== $s['prose_heading_spacing'] ? $s['prose_heading_spacing'] : '';
		$css[] = $w . '{--prose-spacing:' . $s['prose_spacing'] . ';--prose-accent:' . $s['prose_accent'] . ( $hs ? ';--prose-heading-spacing:' . $hs : '' ) . '}';
		// Vertical rhythm: space between siblings, more above headings.
		$css[] = $w . '>:where(*+*){margin-block-start:var(--prose-spacing)}';
		$css[] = $w . '>:where(*){margin-block-end:0}';
		$css[] = $w . '>:where(:first-child){margin-block-start:0}';
		$css[] = $w . '>:where(*+h2,*+h3){margin-block-start:var(--prose-heading-spacing, calc(var(--prose-spacing) * 2))}';
		$css[] = $w . '>:where(*+h4,*+h5,*+h6){margin-block-start:var(--prose-heading-spacing, calc(var(--prose-spacing) * 1.5))}';
		$css[] = $w . '>:where(h2,h3,h4,h5,h6)+:where(*){margin-block-start:calc(var(--prose-spacing) * .5)}';
		if ( '' !== $s['prose_width'] ) {
			$css[] = $w . '>:where(p,ul,ol,blockquote,h1,h2,h3,h4,h5,h6,dl){max-inline-size:' . $s['prose_width'] . '}';
		}
		// Lists.
		$css[] = $w . ' :where(ul,ol){padding-inline-start:1.5em}';
		$css[] = $w . ' :where(li+li){margin-block-start:.4em}';
		$css[] = $w . ' :where(li>ul,li>ol){margin-block-start:.4em}';
		$css[] = $w . ' :where(li)::marker{color:var(--prose-accent)}';
		// Quotes (unless the site-wide blockquote styling below handles them).
		if ( empty( $s['blockquotes'] ) || empty( $s['bq_auto'] ) ) {
			$css[] = $w . ' :where(blockquote){margin-inline:0;padding-inline-start:var(--space-s, 1em);border-inline-start:4px solid var(--prose-accent);font-style:italic}';
			$css[] = $w . ' :where(blockquote cite){display:block;margin-block-start:.5em;font-size:.875em;font-style:normal;opacity:.75}';
		}
		// Media.
		$css[] = $w . ' :where(img,video){max-inline-size:100%;block-size:auto;border-radius:var(--radius, 0)}';
		$css[] = $w . ' :where(figure){margin-inline:0}';
		$css[] = $w . ' :where(figcaption){margin-block-start:.5em;font-size:var(--text-s, .875em);opacity:.75}';
		// Code.
		$css[] = $w . ' :where(:not(pre)>code,kbd){font-size:.9em;padding:.1em .35em;border-radius:4px;background:' . $tint . '}';
		$css[] = $w . ' :where(pre){overflow-x:auto;padding:var(--space-s, 1em);border-radius:var(--radius, 6px);background:' . $tint . ';font-size:.9em;line-height:1.6}';
		// Tables.
		$css[] = $w . ' :where(table){inline-size:100%;border-collapse:collapse}';
		$css[] = $w . ' :where(th,td){padding:.5em .75em;border-block-end:1px solid ' . $line . ';text-align:start;vertical-align:top}';
		$css[] = $w . ' :where(thead th){border-block-end-width:2px}';
		// Rules + marks.
		$css[] = $w . ' :where(hr){border:0;border-block-start:1px solid ' . $line . ';margin-block:calc(var(--prose-spacing) * 2)}';
		$css[] = $w . ' :where(mark){padding-inline:.15em;background:color-mix(in srgb, var(--prose-accent) 25%, transparent);color:inherit}';

		return array(
			'css'     => "\n/* --- TokenForge content (prose) --- */\n" . implode( "\n", $css ) . "\n",
			'base'    => $base,
			'classes' => array_merge( empty( $s['prose'] ) ? array() : array( 'prose' ), $qclass ),
		);
	}

	/**
	 * Flow spacing: margins between siblings (paragraphs, headings, lists,
	 * figures, quotes) inside .smart-spacing and, automatically, in Bricks Rich
	 * Text and non-Bricks post content. Every distance is a variable.
	 *
	 * @param array $s Settings.
	 * @return string[]
	 */
	private static function flow_css( $s ) {
		if ( empty( $s['flow'] ) ) {
			return array();
		}
		$vars = array();
		foreach ( self::FLOW_KEYS as $k ) {
			if ( '' !== (string) $s[ $k ] ) {
				$vars[] = '--' . str_replace( '_', '-', $k ) . ':' . $s[ $k ];
			}
		}
		$targets = array( '.smart-spacing' );
		if ( ! empty( $s['flow_auto'] ) ) {
			$targets[] = '.brxe-text';
			$targets[] = '.brxe-post-content:where(:not([data-source="bricks"]))';
		}
		foreach ( array_filter( array_map( 'trim', preg_split( '/,(?![^(]*\))/', str_replace( '"', "'", (string) $s['flow_selectors'] ) ) ) ) as $extra ) {
			$targets[] = $extra;
		}
		$f   = 'var(--flow-spacing, initial)';
		$css = array( ':root{' . implode( ';', $vars ) . '}' );
		$css[] = '.smart-spacing-normalize :where(p,h1,h2,h3,h4,h5,h6,ul,ol,li),.smart-spacing--off :where(p,h1,h2,h3,h4,h5,h6,ul,ol,li){margin-block:0}';
		foreach ( $targets as $t ) {
			$css[] = "$t{row-gap:0!important}";
			$css[] = "$t>*+*{margin-block:$f 0}";
			$css[] = "$t>*+:where(h1,h2,h3,h4,h5,h6){margin-block:var(--heading-spacing, $f) 0}";
			foreach ( array( 2, 3, 4, 5, 6 ) as $h ) {
				$css[] = "$t>:where(*+h$h){margin-block:var(--h$h-spacing, var(--heading-spacing)) 0}";
			}
			$css[] = "$t>*+p{margin-block:var(--paragraph-spacing, $f) 0}";
			$css[] = "$t>:is(*+ul,*+ol){margin-block:var(--list-spacing, $f)}";
			$css[] = "$t>:is(ul,ol){padding-inline-start:var(--list-indent-spacing, $f)}";
			$css[] = "$t>:is(ul,ol) *+li{margin-block:var(--list-item-spacing, $f) 0}";
			$css[] = "$t>:is(ul,ol) :is(ul,ol){margin-block:var(--nested-list-spacing, var(--list-item-spacing, initial));padding-inline-start:var(--nested-list-indent-spacing, initial)}";
			$css[] = "$t>:is(ul,ol) :is(ul,ol) li{margin-block-start:var(--nested-list-item-spacing, var(--list-item-spacing, initial))}";
			$css[] = "$t>:is(*+figure,*+picture){margin-block:var(--figure-spacing, $f)}";
			$css[] = "$t figcaption{margin-block:var(--figcaption-spacing, $f)}";
			$css[] = "$t>*+blockquote:where(:not(figure>blockquote)){margin-block:var(--blockquote-spacing, $f)}";
			$css[] = "$t>blockquote *+*{margin-block:var(--paragraph-spacing, $f) 0}";
			// After a figure/quote/list (which carry their own margins) don't double up.
			$css[] = "$t>:is(figure,blockquote,ul,ol):where(:not(:first-child))+*{--paragraph-spacing:0;--flow-spacing:0}";
		}
		return $css;
	}

	/**
	 * Site-wide blockquote styling: the quote box (zero specificity), its text,
	 * the footer/figcaption attribution and <cite> (element specificity, so they
	 * beat the zero-specificity paragraph defaults). Works for a bare
	 * <blockquote>, a <figure> wrapping one (Gutenberg's quote/pullquote) and
	 * the .blockquote class.
	 *
	 * @param array $s Settings.
	 * @return string[]
	 */
	private static function blockquote_css( $s ) {
		if ( empty( $s['blockquotes'] ) ) {
			return array();
		}
		$vars = array();
		foreach ( array_keys( self::BLOCKQUOTE ) as $k ) {
			$v = $s[ 'bq_' . str_replace( '-', '_', $k ) ];
			if ( '' !== (string) $v ) {
				$vars[] = "--blockquote-$k:$v";
			}
		}
		$targets = empty( $s['bq_auto'] ) ? '.blockquote' : 'blockquote:where(:not(figure>blockquote)),figure:has(>blockquote),.blockquote';
		$ex      = trim( (string) $s['bq_exclude'] );
		$q       = ':where(' . $targets . ')' . ( '' !== $ex ? ':where(:not(' . str_replace( '"', "'", $ex ) . '))' : '' );
		$css     = array();
		$css[]   = ':root{' . implode( ';', $vars ) . '}';
		$css[]   = $q . '{display:flex;flex-direction:column;gap:var(--blockquote-gap);inline-size:100%;max-inline-size:var(--blockquote-max-width, none);margin-inline:0;padding:var(--blockquote-padding);'
			. 'border-width:var(--blockquote-border-width);border-style:var(--blockquote-border-style);border-color:var(--blockquote-border-color);border-radius:var(--blockquote-border-radius);'
			. 'background:var(--blockquote-background);box-shadow:var(--blockquote-box-shadow)}';
		$css[]   = $q . ' p{color:var(--blockquote-text-color);font-family:var(--blockquote-text-font-family);font-style:var(--blockquote-text-font-style);font-size:var(--blockquote-text-font-size);'
			. 'font-weight:var(--blockquote-text-font-weight);line-height:var(--blockquote-text-line-height);text-align:var(--blockquote-text-text-align);text-transform:var(--blockquote-text-text-transform);text-wrap:pretty}';
		$css[]   = $q . ' :is(footer,figcaption){display:flex;align-items:center;gap:.5em;padding:var(--blockquote-footer-padding);margin-block:var(--blockquote-footer-margin-block);'
			. 'font-family:var(--blockquote-footer-font-family);font-size:var(--blockquote-footer-font-size);font-weight:var(--blockquote-footer-font-weight);font-style:var(--blockquote-footer-font-style);'
			. 'line-height:var(--blockquote-footer-line-height);text-transform:var(--blockquote-footer-text-transform);color:var(--blockquote-footer-color)}';
		$css[]   = $q . ' cite{font-family:var(--blockquote-cite-font-family);font-size:var(--blockquote-cite-font-size);font-weight:var(--blockquote-cite-font-weight);font-style:var(--blockquote-cite-font-style);'
			. 'line-height:var(--blockquote-cite-line-height);text-transform:var(--blockquote-cite-text-transform);color:var(--blockquote-cite-color)}';
		// A <blockquote> inside a styled <figure> is only the text container.
		if ( ! empty( $s['bq_auto'] ) ) {
			$css[] = ':where(figure:has(>blockquote)>blockquote){margin:0;padding:0;border:0;background:none}';
		}
		return $css;
	}
}
