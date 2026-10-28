<?php
/**
 * Automatic accessibility attributes (front end only, every switch off by default).
 *
 * Adds an attribute only where it is absent, on the rendered HTML of each
 * top-level builder element and each top-level block (WP_HTML_Tag_Processor,
 * WordPress 6.2+; the two items that need to read text, 6.5+). Nothing is
 * stored: switching an item off gives back the original output byte for byte.
 * An element with data-tf-a11y="off" is skipped together with everything in it (only itself when
 * its end cannot be found where the browser puts it, see scope_opener()).
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds missing accessibility attributes to the front-end output.
 */
class A11y_Auto {

	/** Id of the one hidden "(opens in a new tab)" text that new-tab links point to. */
	const NEW_TAB_ID = 'tf-a11y-new-tab';

	/** Elements without a closing tag: data-tf-a11y="off" on one skips only itself. */
	const VOID = array( 'AREA' => 1, 'BASE' => 1, 'BR' => 1, 'COL' => 1, 'EMBED' => 1, 'HR' => 1, 'IMG' => 1, 'INPUT' => 1, 'LINK' => 1, 'META' => 1, 'SOURCE' => 1, 'TRACK' => 1, 'WBR' => 1 );

	/** The processor reports these as one token (content is text, the closer is never visited). */
	const ATOMIC = array( 'IFRAME' => 1, 'NOEMBED' => 1, 'NOFRAMES' => 1, 'SCRIPT' => 1, 'STYLE' => 1, 'TEXTAREA' => 1, 'TITLE' => 1, 'XMP' => 1 );

	/** The end tag may be left out (the browser closes them itself), so what is inside one is never proven. */
	const OPTIONAL_END = array( 'P' => 1, 'LI' => 1, 'DT' => 1, 'DD' => 1, 'OPTION' => 1, 'OPTGROUP' => 1, 'TR' => 1, 'TD' => 1, 'TH' => 1, 'THEAD' => 1, 'TBODY' => 1, 'TFOOT' => 1, 'CAPTION' => 1, 'COLGROUP' => 1, 'RB' => 1, 'RT' => 1, 'RTC' => 1, 'RP' => 1, 'HTML' => 1, 'HEAD' => 1, 'BODY' => 1 );

	/** Openers that close an open p. */
	const P_CLOSERS = array( 'ADDRESS' => 1, 'ARTICLE' => 1, 'ASIDE' => 1, 'BLOCKQUOTE' => 1, 'CENTER' => 1, 'DETAILS' => 1, 'DIALOG' => 1, 'DIR' => 1, 'DIV' => 1, 'DL' => 1, 'DD' => 1, 'DT' => 1, 'FIELDSET' => 1, 'FIGCAPTION' => 1, 'FIGURE' => 1, 'FOOTER' => 1, 'FORM' => 1, 'H1' => 1, 'H2' => 1, 'H3' => 1, 'H4' => 1, 'H5' => 1, 'H6' => 1, 'HEADER' => 1, 'HGROUP' => 1, 'HR' => 1, 'LI' => 1, 'LISTING' => 1, 'MAIN' => 1, 'MENU' => 1, 'NAV' => 1, 'OL' => 1, 'P' => 1, 'PLAINTEXT' => 1, 'PRE' => 1, 'SEARCH' => 1, 'SECTION' => 1, 'SUMMARY' => 1, 'TABLE' => 1, 'UL' => 1, 'XMP' => 1 );

	/** Open elements that stop a p or a list item from being closed by an opener. */
	const WALLS = array( 'APPLET' => 1, 'BUTTON' => 1, 'CAPTION' => 1, 'HTML' => 1, 'MARQUEE' => 1, 'MATH' => 1, 'OBJECT' => 1, 'SVG' => 1, 'TABLE' => 1, 'TD' => 1, 'TEMPLATE' => 1, 'TH' => 1 );

	/** Openers the browser only nests inside one of these; without one open in the scope they close something outside it. */
	const NEEDS = array( 'LI' => array( 'UL', 'OL', 'MENU' ), 'DT' => array( 'DL' ), 'DD' => array( 'DL' ), 'TR' => array( 'TABLE', 'TBODY', 'THEAD', 'TFOOT' ), 'TD' => array( 'TR' ), 'TH' => array( 'TR' ), 'TBODY' => array( 'TABLE' ), 'THEAD' => array( 'TABLE' ), 'TFOOT' => array( 'TABLE' ), 'CAPTION' => array( 'TABLE' ), 'COLGROUP' => array( 'TABLE' ), 'OPTION' => array( 'SELECT', 'DATALIST', 'OPTGROUP' ), 'OPTGROUP' => array( 'SELECT' ), 'RB' => array( 'RUBY' ), 'RT' => array( 'RUBY', 'RTC' ), 'RTC' => array( 'RUBY' ), 'RP' => array( 'RUBY', 'RTC' ) );

	/** Tags that cannot nest (the browser closes the open one): tag => group. */
	const NO_NEST = array( 'A' => 'A', 'BUTTON' => 'BUTTON', 'NOBR' => 'NOBR', 'H1' => 'H', 'H2' => 'H', 'H3' => 'H', 'H4' => 'H', 'H5' => 'H', 'H6' => 'H' );

	/** Always focusable or operable (data-tf-decorative never hides them or what holds them). */
	const FOCUSABLE = array( 'BUTTON' => 1, 'SELECT' => 1, 'TEXTAREA' => 1, 'IFRAME' => 1, 'SUMMARY' => 1, 'EMBED' => 1, 'OBJECT' => 1 );

	/** Most icon-link title lookups per request (each is a query). */
	const MAX_LOOKUPS = 20;

	/** Input types a placeholder label makes no sense for. */
	const NO_LABEL_TYPES = array( 'hidden', 'submit', 'button', 'reset', 'image', 'checkbox', 'radio', 'file', 'range', 'color' );

	/**
	 * Per-tag rules run on every opening tag outside a skipped part: tag => methods
	 * ( $p, $n, &$st ). '*' runs on every tag. (data-tf-decorative is not a rule: it
	 * replaces values, so decorate() has its own pass.)
	 */
	const RULES = array(
		'*'        => array(),
		'IMG'      => array( 'rule_alt' ),
		'A'        => array( 'rule_current', 'rule_new_tab' ),
		'NAV'      => array( 'rule_nav' ),
		'INPUT'    => array( 'rule_field' ),
		'TEXTAREA' => array( 'rule_field' ),
	);

	/** @var array|null Switches on for this request: key => true. */
	private static $on = null;

	/** @var array What was added and what was left alone in this request. */
	private static $stats = array(
		'added' => array(),
		'left'  => array(),
	);

	/** @var int Block nesting while rendering (only top-level blocks are read). */
	private static $depth = 0;

	/** @var int Builder render_data() nesting: an inner tree (post content, template, popup) is read with the outer one. */
	private static $trees = 0;

	/** @var bool A link points to the hidden new-tab text, so print it. */
	private static $new_tab = false;

	/** @var array Per-request lookups (menu names, alt texts, linked titles). */
	private static $memo = array();

	/**
	 * Hooks: everything starts on template_redirect, which admin, REST and AJAX
	 * requests never reach.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( __CLASS__, 'boot' ) );
	}

	/**
	 * The items: key => [ attribute added, what it does (Cheatsheet, B1 panel), is a guess, needs WordPress ].
	 *
	 * @return array
	 */
	public static function items() {
		return array(
			'a11y_icons'           => array( 'aria-hidden="true"', 'An icon inside a link or button that already has text is hidden from screen readers, so the text is read once.', false, '6.5' ),
			'a11y_alt'             => array( 'alt', 'An image with no alt attribute at all gets the alt text from the media library. alt="" (decorative) is never touched.', false, '6.2' ),
			'a11y_nav_label'       => array( 'aria-label', 'A navigation menu without a label gets the menu name, so several menus can be told apart.', false, '6.2' ),
			'a11y_current'         => array( 'aria-current="page"', 'The menu link to the page being shown is marked as the current page.', false, '6.2' ),
			'a11y_new_tab'         => array( 'aria-describedby', 'A link that opens a new tab is announced with "(opens in a new tab)" from one hidden text on the page.', false, '6.2' ),
			'a11y_guess_field'     => array( 'aria-label', 'Guess: a form field with only a placeholder (no label) gets the placeholder as its label.', true, '6.2' ),
			'a11y_guess_icon_link' => array( 'aria-label', 'Guess: a link with only an icon gets its title, or the title of the page it links to, as its label.', true, '6.5' ),
		);
	}

	/**
	 * Built-in settings (every switch off) plus a read-only note of the switches
	 * this WordPress cannot run: key => reason (empty on 6.5+, never saved).
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array_fill_keys( array_keys( self::items() ), false );
		$na  = array();
		$why = self::unavailable();
		if ( '' !== $why ) {
			foreach ( self::items() as $k => $it ) {
				if ( 'all' === $why || '6.5' === $it[3] ) {
					/* translators: %s: a WordPress version number. */
					$na[ $k ] = sprintf( __( 'Needs WordPress %s or later.', 'tokenforge' ), $it[3] );
				}
			}
		}
		$out['a11y_auto_na'] = $na;
		return $out;
	}

	/**
	 * The switches, cleaned for saving.
	 *
	 * @param array $in Raw settings.
	 * @return array
	 */
	public static function clean( $in ) {
		$out = array();
		foreach ( array_keys( self::items() ) as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] );
		}
		return $out;
	}

	/**
	 * What this WordPress lacks: '' (all fine), 'text' (below 6.5: no text tokens) or 'all' (below 6.2).
	 *
	 * @return string
	 */
	public static function unavailable() {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return 'all';
		}
		return method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ? '' : 'text';
	}

	/**
	 * Switches that are on and can run here: key => true.
	 *
	 * @param bool $fresh Read the settings again.
	 * @return array
	 */
	public static function switches( $fresh = false ) {
		if ( null === self::$on || $fresh ) {
			$s   = Settings::get_instance()->get_settings();
			$why = self::unavailable();
			$on  = array();
			foreach ( self::items() as $k => $it ) {
				if ( ! empty( $s[ $k ] ) && 'all' !== $why && ( '' === $why || '6.5' !== $it[3] ) ) {
					$on[ $k ] = true;
				}
			}
			self::$on = $on;
		}
		return self::$on;
	}

	/**
	 * What this request added (item => count) and left alone (alt, nav, field, icon_link => count).
	 *
	 * @return array
	 */
	public static function added_summary() {
		return self::$stats;
	}

	/**
	 * Cheatsheet entries for the switches that are on (and data-tf-a11y when any is).
	 *
	 * @return array group => [ items ]
	 */
	public static function cheatsheet() {
		$on = self::switches();
		if ( ! $on ) {
			return array();
		}
		$out = array( 'attributes' => array( array( 'n' => 'data-tf-a11y', 'k' => 'attr', 'copy' => 'data-tf-a11y="off"', 'd' => 'Set to off on an element: the automatic accessibility attributes leave it and everything inside it exactly as you built it.', 'css' => '' ) ) );
		foreach ( self::items() as $k => $it ) {
			if ( isset( $on[ $k ] ) ) {
				$out['utilities'][] = array( 'n' => $it[0] . ' (automatic)', 'k' => 'attr', 'copy' => 'data-tf-a11y="off"', 'd' => $it[1] . ' Added on the page only where it is missing; anything you set is never changed. Copy data-tf-a11y="off" to opt an element out.', 'css' => '' );
			}
		}
		return $out;
	}

	/**
	 * Front-end request with at least one switch on: hook the element and block filters.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) ) {
			return;
		}
		// Always hooked, because data-tf-decorative needs no switch (unused, each call is one stripos). The same handlers feed Attr_Front::note(), so these hooks run one callback each.
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'bricks_element' ), 20, 2 );
		add_filter( 'render_block_data', array( __CLASS__, 'block_in' ), 10, 1 );
		add_filter( 'render_block', array( __CLASS__, 'block_out' ), 20, 1 );
		add_action( 'bricks/frontend/before_render_data', array( __CLASS__, 'tree_in' ), 10, 0 );
		add_action( 'bricks/frontend/after_render_data', array( __CLASS__, 'tree_out' ), 10, 0 );
		if ( self::switches() ) {
			add_action( 'wp_footer', array( __CLASS__, 'footer' ) );
		}
	}

	/**
	 * Builder element HTML: only top-level elements, whose HTML holds every child,
	 * so each part of the page is read once.
	 *
	 * @param string $html    Element HTML.
	 * @param object $element Element instance.
	 * @return string
	 */
	public static function bricks_element( $html, $element = null ) {
		if ( self::$trees > 1 || ( is_object( $element ) && isset( $element->element ) && ! empty( $element->element['parent'] ) ) ) {
			return $html;
		}
		return self::process( self::decorate( Attr_Front::note( $html ) ) );
	}

	/**
	 * A builder tree starts rendering.
	 *
	 * @return void
	 */
	public static function tree_in() {
		++self::$trees;
	}

	/**
	 * A builder tree finished.
	 *
	 * @return void
	 */
	public static function tree_out() {
		self::$trees = max( 0, self::$trees - 1 );
	}

	/**
	 * A block starts rendering.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	public static function block_in( $block ) {
		++self::$depth;
		return $block;
	}

	/**
	 * A block finished: read it when it is a top-level one (its HTML holds its inner blocks) and
	 * not inside a builder tree (the builder's top-level element reads it).
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public static function block_out( $html ) {
		self::$depth = max( 0, self::$depth - 1 );
		return 0 === self::$depth && 0 === self::$trees ? self::process( self::decorate( Attr_Front::note( $html ) ) ) : $html;
	}

	/**
	 * The one hidden text new-tab links point to (only when a link uses it).
	 *
	 * @return void
	 */
	public static function footer() {
		if ( self::$new_tab ) {
			echo '<span id="' . esc_attr( self::NEW_TAB_ID ) . '" class="sr-only" hidden>' . esc_html__( '(opens in a new tab)', 'tokenforge' ) . '</span>';
		}
	}

	/**
	 * Add the missing attributes to one piece of HTML. Unchanged input comes back
	 * as the same string.
	 *
	 * @param string     $html HTML.
	 * @param array|null $on   Switches (key => true); null = this site's.
	 * @return string
	 */
	public static function process( $html, $on = null ) {
		$on = null === $on ? self::switches() : $on;
		if ( ! $on || ! is_string( $html ) || '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) || ! self::worth_reading( $html, $on ) ) {
			return $html;
		}
		$st = self::plan( $html, $on );
		foreach ( array( 'added', 'left' ) as $g ) {
			foreach ( $st[ $g ] as $k => $c ) {
				self::$stats[ $g ][ $k ] = ( isset( self::$stats[ $g ][ $k ] ) ? self::$stats[ $g ][ $k ] : 0 ) + $c;
			}
		}
		if ( ! $st['plan'] ) {
			return $html;
		}
		// The processor walks every queued update at each tag, so apply them in batches
		// (applying mid-walk keeps the cursor right from 6.2.1).
		$batch = isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '6.2.1', '>=' );
		$queue = 0;
		$p     = new \WP_HTML_Tag_Processor( $html );
		$n     = 0;
		while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( $p->is_tag_closer() ) {
				continue;
			}
			++$n;
			if ( isset( $st['plan'][ $n ] ) ) {
				foreach ( $st['plan'][ $n ] as $k => $v ) {
					if ( null === $p->get_attribute( $k ) ) { // never replace what is there
						$p->set_attribute( $k, $v );
						++$queue;
					}
				}
				if ( $batch && $queue >= 16 ) {
					$p->get_updated_html();
					$queue = 0;
				}
			}
		}
		return $p->get_updated_html();
	}

	/**
	 * data-tf-decorative: the element is only decoration. It gets aria-hidden="true" (replacing
	 * another value: the author said decorative); an img gets alt=""; an svg also focusable="false".
	 * role is never touched. Images inside get alt="" only when the element's own closing tag is found
	 * where the browser closes it (see scope_opener()); otherwise only the element itself is changed and
	 * nothing after it. Something focusable or operable on it or inside it (link, button, field,
	 * iframe, summary, media controls, tabindex, contenteditable) is a mistake: the element is left
	 * exactly as built and counted (left.decorative_interactive) instead of being hidden silently; when
	 * its end is not proven, anything focusable after it in the fragment counts as inside. A mark inside
	 * a link or button that has no other name (attribute, text, image alt) is left too
	 * (left.decorative_name). It wins over data-tf-a11y="off" (that only stops the automatic
	 * attributes). Costs one stripos when the attribute is not in the HTML; the walk stops once every
	 * mark is settled.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function decorate( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'data-tf-decorative' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		// Pass 1: plan by opening-tag number. Text (6.5+) only tells whether a link keeps a name.
		$text  = method_exists( 'WP_HTML_Tag_Processor', 'next_token' );
		$todo  = substr_count( strtolower( $html ), 'data-tf-decorative' );
		$sc    = self::scope_new();
		$own   = array(); // mark => its own attributes
		$inner = array(); // mark => images inside it
		$left  = array(); // mark => why it is left as built
		$ctl   = array(); // open links and buttons: [ tag, has a name besides the marks, marks inside ]
		$held  = 0;       // controls holding marks
		$n     = 0;
		$k     = 0;
		$p     = new \WP_HTML_Tag_Processor( $html );
		while ( $text ? $p->next_token() : $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( $text && '#tag' !== $p->get_token_type() ) {
				if ( $ctl && ! $sc['live'] && ! $sc['broken'] && '#text' === $p->get_token_type() && '' !== trim( $p->get_modifiable_text(), " \t\n\r\f\v\0\xC2\xA0" ) ) {
					$ctl[ count( $ctl ) - 1 ][1] = true;
				}
				continue;
			}
			++$k;
			$tag = $p->get_tag();
			if ( $p->is_tag_closer() ) {
				self::scope_closer( $sc, $tag, $k );
				if ( $ctl && end( $ctl )[0] === $tag ) {
					self::control_end( array_pop( $ctl ), $left, $held );
				}
			} else {
				++$n;
				$mark = $todo > 0 && null !== $p->get_attribute( 'data-tf-decorative' );
				if ( $mark ) {
					--$todo;
					if ( $ctl ) {
						$held                           += $ctl[ count( $ctl ) - 1 ][2] ? 0 : 1;
						$ctl[ count( $ctl ) - 1 ][2][] = $n;
					}
				}
				self::scope_opener( $sc, $p, $tag, $n, $k, $mark );
				if ( $mark ) {
					$own[ $n ] = array();
					if ( 'true' !== $p->get_attribute( 'aria-hidden' ) ) {
						$own[ $n ]['aria-hidden'] = 'true';
					}
					if ( 'IMG' === $tag && '' !== $p->get_attribute( 'alt' ) ) {
						$own[ $n ]['alt'] = '';
					}
					if ( 'SVG' === $tag && 'false' !== $p->get_attribute( 'focusable' ) ) {
						$own[ $n ]['focusable'] = 'false';
					}
				}
				if ( ( $mark || $sc['live'] || $sc['broken'] ) && self::focusable( $p, $tag ) ) {
					foreach ( $sc['live'] as $s ) {
						$left[ $s[0] ] = 'interactive';
					}
					foreach ( $sc['broken'] as $m ) {
						$left[ $m ] = 'interactive';
					}
					$sc['broken'] = array();
					if ( $mark ) {
						$left[ $n ] = 'interactive';
					}
				}
				if ( 'IMG' === $tag ) {
					$alt = $p->get_attribute( 'alt' );
					foreach ( $sc['live'] as $s ) {
						if ( '' !== $alt ) {
							$inner[ $s[0] ][] = $n;
						}
					}
					if ( $ctl && ! $mark && ! $sc['live'] && ! $sc['broken'] && is_string( $alt ) && '' !== trim( $alt ) ) {
						$ctl[ count( $ctl ) - 1 ][1] = true;
					}
				}
				if ( ( 'A' === $tag && null !== $p->get_attribute( 'href' ) ) || 'BUTTON' === $tag ) {
					if ( $ctl && end( $ctl )[0] === $tag ) {
						self::control_end( array_pop( $ctl ), $left, $held ); // they do not nest: the parser closes the open one
					}
					$named = false;
					foreach ( array( 'aria-label', 'aria-labelledby', 'title' ) as $a ) {
						$v     = $p->get_attribute( $a );
						$named = $named || ( is_string( $v ) && '' !== trim( $v ) );
					}
					$ctl[] = array( $tag, $named, array() );
				}
			}
			if ( ! $todo && ! $sc['live'] && ! $sc['broken'] && ! $held ) {
				break; // every mark settled: the rest of the fragment cannot change anything
			}
		}
		self::scope_break( $sc ); // still open at the end of the fragment: not proven
		while ( $ctl ) {
			self::control_end( array_pop( $ctl ), $left, $held );
		}
		$plan = array();
		foreach ( $own as $m => $attrs ) {
			if ( isset( $left[ $m ] ) ) {
				self::count( self::$stats, 'left', 'interactive' === $left[ $m ] ? 'decorative_interactive' : 'decorative_name' );
				continue;
			}
			if ( $attrs ) {
				$plan[ $m ] = isset( $plan[ $m ] ) ? array_merge( $plan[ $m ], $attrs ) : $attrs;
			}
			if ( isset( $sc['end'][ $m ], $inner[ $m ] ) ) {
				foreach ( $inner[ $m ] as $i ) {
					$plan[ $i ]['alt'] = '';
				}
			}
		}
		if ( ! $plan ) {
			return $html;
		}
		// Pass 2: set them (batched like process(): the processor walks every queued update at each tag).
		$batch = isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '6.2.1', '>=' );
		$queue = 0;
		$last  = max( array_keys( $plan ) );
		$n     = 0;
		$p     = new \WP_HTML_Tag_Processor( $html );
		while ( $n < $last && $p->next_tag() ) {
			if ( isset( $plan[ ++$n ] ) ) {
				foreach ( $plan[ $n ] as $k => $v ) {
					$p->set_attribute( $k, $v );
					++$queue;
				}
				if ( $batch && $queue >= 16 ) {
					$p->get_updated_html();
					$queue = 0;
				}
			}
		}
		return $p->get_updated_html();
	}

	/**
	 * A link or button closed: marks inside one with no other name are left as built.
	 *
	 * @param array $c    Control (see decorate()).
	 * @param array $left Marks left as built.
	 * @param int   $held Controls holding marks.
	 * @return void
	 */
	private static function control_end( $c, &$left, &$held ) {
		if ( ! $c[2] ) {
			return;
		}
		--$held;
		foreach ( $c[1] ? array() : $c[2] as $m ) {
			if ( ! isset( $left[ $m ] ) ) {
				$left[ $m ] = 'name';
			}
		}
	}

	/**
	 * Can this tag take focus or be operated?
	 *
	 * @param \WP_HTML_Tag_Processor $p   Processor.
	 * @param string                 $tag Tag name.
	 * @return bool
	 */
	private static function focusable( $p, $tag ) {
		if ( null !== $p->get_attribute( 'tabindex' ) || isset( self::FOCUSABLE[ $tag ] ) ) {
			return true;
		}
		$edit = $p->get_attribute( 'contenteditable' );
		if ( null !== $edit && 'false' !== strtolower( trim( (string) $edit ) ) ) {
			return true;
		}
		if ( 'A' === $tag || 'AREA' === $tag ) {
			return null !== $p->get_attribute( 'href' );
		}
		if ( 'VIDEO' === $tag || 'AUDIO' === $tag ) {
			return null !== $p->get_attribute( 'controls' );
		}
		return 'INPUT' === $tag && 'hidden' !== strtolower( (string) $p->get_attribute( 'type' ) );
	}

	/**
	 * Scope state for marked elements (data-tf-decorative, data-tf-a11y="off").
	 *
	 * @return array open: tags opened since the outermost open mark; live: open marks [ n, index in open, can be proven ];
	 *               end: proven mark n => tag-token number of its closer; reach: the same for an element with an
	 *               optional end tag (the browser may end it earlier); broken: marks whose end is unknown;
	 *               fg: index of the first svg or math in open; pin: a p may be open around the next mark.
	 */
	private static function scope_new() {
		return array(
			'open'   => array(),
			'live'   => array(),
			'end'    => array(),
			'reach'  => array(),
			'broken' => array(),
			'fg'     => null,
			'pin'    => false,
		);
	}

	/**
	 * An opening tag. What is inside a mark is proven only when the tree the browser builds agrees:
	 * an opener that makes the browser close an element the scope cannot see (a list item or table
	 * cell without its container in the scope, a heading in a heading, a link in a link, a block while
	 * a p around the mark is open) breaks every open scope.
	 *
	 * @param array                  $sc   Scope state.
	 * @param \WP_HTML_Tag_Processor $p    Processor.
	 * @param string                 $tag  Tag name.
	 * @param int                    $n    Opening-tag number.
	 * @param int                    $k    Tag-token number (openers and closers).
	 * @param bool                   $mark The tag carries the mark.
	 * @return void
	 */
	private static function scope_opener( &$sc, $p, $tag, $n, $k, $mark ) {
		if ( $sc['live'] ) {
			$close = null;
			if ( isset( self::P_CLOSERS[ $tag ] ) ) {
				$close = self::last_open( $sc, array( 'P' ), self::WALLS );
			}
			if ( null === $close && ( 'LI' === $tag || 'DT' === $tag || 'DD' === $tag ) ) {
				$close = self::last_open( $sc, 'LI' === $tag ? array( 'LI' ) : array( 'DT', 'DD' ), null );
			}
			if ( null !== $close ) {
				self::pop_to( $sc, $close, $k - 1 ); // the browser closes it here
			}
		}
		if ( $sc['live'] ) {
			$group  = isset( self::NO_NEST[ $tag ] ) ? self::NO_NEST[ $tag ] : null;
			$nested = false;
			foreach ( $sc['open'] as $o ) {
				$nested = $nested || ( null !== $group && isset( self::NO_NEST[ $o ] ) && $group === self::NO_NEST[ $o ] );
			}
			if ( $nested || ( isset( self::NEEDS[ $tag ] ) && ! array_intersect( self::NEEDS[ $tag ], $sc['open'] ) ) || ( $sc['pin'] && isset( self::P_CLOSERS[ $tag ] ) && ! in_array( 'P', $sc['open'], true ) ) ) {
				self::scope_break( $sc );
			}
		}
		if ( ! $sc['live'] && ! $mark ) {
			if ( 'P' === $tag ) {
				$sc['pin'] = true;
			} elseif ( isset( self::P_CLOSERS[ $tag ] ) ) {
				$sc['pin'] = false;
			}
			return;
		}
		if ( ! $sc['live'] ) {
			$sc['pin'] = $sc['pin'] && ! isset( self::P_CLOSERS[ $tag ] );
		}
		// A self-closing flag only ends an element in svg or math; on 6.2 (no flag) such a tag stays open and the scope is not proven.
		$leaf = method_exists( $p, 'has_self_closing_flag' ) && $p->has_self_closing_flag() && ( null !== $sc['fg'] || 'SVG' === $tag || 'MATH' === $tag );
		if ( $leaf || isset( self::VOID[ $tag ] ) || isset( self::ATOMIC[ $tag ] ) ) {
			return; // nothing can be inside it
		}
		$i              = count( $sc['open'] );
		$sc['open'][]   = $tag;
		if ( null === $sc['fg'] && ( 'SVG' === $tag || 'MATH' === $tag ) ) {
			$sc['fg'] = $i;
		}
		if ( $mark ) {
			$sc['live'][] = array( $n, $i, ! isset( self::OPTIONAL_END[ $tag ] ) );
		}
	}

	/**
	 * A closing tag: it must close an element open in the scope, with only elements the browser
	 * closes on its own (optional end tags, svg or math children) above it; anything else breaks every open scope.
	 *
	 * @param array  $sc  Scope state.
	 * @param string $tag Tag name.
	 * @param int    $k   Tag-token number.
	 * @return void
	 */
	private static function scope_closer( &$sc, $tag, $k ) {
		if ( ! $sc['live'] ) {
			if ( 'P' === $tag ) {
				$sc['pin'] = false;
			}
			return;
		}
		for ( $i = count( $sc['open'] ) - 1; $i >= 0 && $sc['open'][ $i ] !== $tag; $i-- ) {
			if ( ! isset( self::OPTIONAL_END[ $sc['open'][ $i ] ] ) && ( null === $sc['fg'] || $i <= $sc['fg'] ) ) {
				$i = -1;
				break;
			}
		}
		if ( $i < 0 ) {
			self::scope_break( $sc );
			return;
		}
		self::pop_to( $sc, $i + 1, $k - 1 );
		self::pop_to( $sc, $i, $k );
	}

	/**
	 * Index of the last open element named in $tags, or null (also when one of $walls is above it).
	 *
	 * @param array      $sc    Scope state.
	 * @param array      $tags  Tag names.
	 * @param array|null $walls Tags that hide it, or null: anything but optional end tags, div, address and p hides it.
	 * @return int|null
	 */
	private static function last_open( $sc, $tags, $walls ) {
		for ( $i = count( $sc['open'] ) - 1; $i >= 0; $i-- ) {
			$o = $sc['open'][ $i ];
			if ( in_array( $o, $tags, true ) ) {
				return $i;
			}
			if ( null === $walls ? ! isset( self::OPTIONAL_END[ $o ] ) && 'DIV' !== $o && 'ADDRESS' !== $o : isset( $walls[ $o ] ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Close the open elements from index $i up; a mark closed here is proven when it can be.
	 *
	 * @param array $sc Scope state.
	 * @param int   $i  Index in open.
	 * @param int   $k  Tag-token number of the last token inside.
	 * @return void
	 */
	private static function pop_to( &$sc, $i, $k ) {
		while ( count( $sc['open'] ) > $i ) {
			$j = count( $sc['open'] ) - 1;
			array_pop( $sc['open'] );
			if ( null !== $sc['fg'] && $j <= $sc['fg'] ) {
				$sc['fg'] = null;
			}
			$s = end( $sc['live'] );
			if ( $s && $s[1] === $j ) {
				array_pop( $sc['live'] );
				// An element with an optional end tag may end earlier in the browser, never later: a bound, not a proof.
				$sc[ $s[2] ? 'end' : 'reach' ][ $s[0] ] = $k;
			}
		}
	}

	/**
	 * The tree is not what the scope thinks (or the fragment ended): every open mark keeps only itself.
	 *
	 * @param array $sc Scope state.
	 * @return void
	 */
	private static function scope_break( &$sc ) {
		foreach ( $sc['live'] as $s ) {
			$sc['broken'][] = $s[0];
		}
		$sc['live'] = array();
		$sc['open'] = array();
		$sc['fg']   = null;
		$sc['pin']  = true; // unknown from here: assume a p may be open
	}

	/**
	 * data-tf-a11y="off" elements: opening-tag number => tag-token number of the closer when what is
	 * inside is proven (or bounded: skipping too little only adds a missing attribute), or 0 (skip
	 * only the element itself).
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private static function off_scopes( $html ) {
		$todo = substr_count( strtolower( $html ), 'data-tf-a11y' );
		$sc   = self::scope_new();
		$offs = array();
		$n    = 0;
		$k    = 0;
		$p    = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			++$k;
			if ( $p->is_tag_closer() ) {
				self::scope_closer( $sc, $p->get_tag(), $k );
			} else {
				++$n;
				$flag = $todo > 0 ? $p->get_attribute( 'data-tf-a11y' ) : null;
				$todo = null === $flag ? $todo : $todo - 1;
				$mark = is_string( $flag ) && 'off' === strtolower( trim( $flag ) );
				if ( $mark ) {
					$offs[ $n ] = 0;
				}
				self::scope_opener( $sc, $p, $p->get_tag(), $n, $k, $mark );
			}
			if ( ! $todo && ! $sc['live'] ) {
				break;
			}
		}
		foreach ( $sc['end'] + $sc['reach'] as $m => $end ) {
			if ( isset( $offs[ $m ] ) ) {
				$offs[ $m ] = $end;
			}
		}
		return $offs;
	}

	/**
	 * Cheap test before parsing: does the HTML hold a tag an active item looks at?
	 *
	 * @param string $html HTML.
	 * @param array  $on   Switches.
	 * @return bool
	 */
	private static function worth_reading( $html, $on ) {
		$need = array(
			'a11y_icons'           => array( '<a', '<button' ),
			'a11y_guess_icon_link' => array( '<a' ),
			'a11y_alt'             => array( '<img' ),
			'a11y_nav_label'       => array( '<nav' ),
			'a11y_current'         => array( '<nav' ),
			'a11y_new_tab'         => array( '_blank' ),
			'a11y_guess_field'     => array( 'placeholder' ),
		);
		foreach ( $on as $k => $x ) {
			foreach ( isset( $need[ $k ] ) ? $need[ $k ] : array() as $s ) {
				if ( false !== stripos( $html, $s ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * First pass: walk the tags (and text on 6.5+) and decide what to add, by the
	 * opening-tag number the second pass counts the same way.
	 *
	 * @param string $html HTML.
	 * @param array  $on   Switches.
	 * @return array [ 'plan' => [ n => [ attr => value ] ], 'added' => [], 'left' => [] ]
	 */
	private static function plan( $html, $on ) {
		$text = method_exists( 'WP_HTML_Tag_Processor', 'next_token' );
		if ( ! $text ) {
			unset( $on['a11y_icons'], $on['a11y_guess_icon_link'] ); // they need to read text (6.5+)
		}
		$p    = new \WP_HTML_Tag_Processor( $html );
		$st   = array(
			'on'     => $on,
			'plan'   => array(),
			'added'  => array(),
			'left'   => array(),
			'nav'    => 0,       // open <nav> depth
			'wait'   => null,    // [ nav number, nav depth ] waiting for its menu name
			'label'  => 0,       // open <label> depth
			'fors'   => array(), // label for="" ids in this HTML
			'fields' => array(), // placeholder-only fields: [ n, id, placeholder ]
		);
		$n    = 0;
		$k    = 0;       // tag-token number (openers and closers)
		$skip = 0;       // inside a data-tf-a11y="off" element up to this tag token
		$ctl  = array(); // open links and buttons
		$svg  = 0;
		$ic   = null;    // index of the open <i> icon in the top control
		$sv   = null;    // index of the open svg icon in the top control
		$ah   = null;    // [ tag, depth ] inside an aria-hidden="true" element within a control
		$offs = false !== stripos( $html, 'data-tf-a11y' ) ? self::off_scopes( $html ) : array(); // read only when it is there
		while ( $text ? $p->next_token() : $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( $text && '#tag' !== $p->get_token_type() ) {
				if ( $ctl && ! $svg && ! $ah && '#text' === $p->get_token_type() && '' !== trim( $p->get_modifiable_text(), " \t\n\r\f\v\0\xC2\xA0" ) ) {
					$top = count( $ctl ) - 1;
					$ctl[ $top ]['text'] = true;
					if ( null !== $ic ) {
						$ctl[ $top ]['icons'][ $ic ][1] = false; // an <i> with text is italic text, not an icon
						$ctl[ $top ]['icons'][ $ic ][2] = false;
					}
				}
				continue;
			}
			$tag    = $p->get_tag();
			$closer = $p->is_tag_closer();
			++$k;
			$n += $closer ? 0 : 1;
			if ( $k <= $skip ) {
				continue;
			}
			if ( $closer ) {
				if ( $ah && $tag === $ah[0] && 0 === --$ah[1] ) {
					$ah = null;
				}
				if ( ( 'A' === $tag || 'BUTTON' === $tag ) && $ctl && end( $ctl )['tag'] === $tag ) {
					self::finish( array_pop( $ctl ), $st );
					$ic = null;
					$sv = null;
					$ah = null;
				} elseif ( 'SVG' === $tag ) {
					$svg = max( 0, $svg - 1 );
					$sv  = $svg ? $sv : null;
				} elseif ( 'I' === $tag ) {
					$ic = null;
				} elseif ( 'LABEL' === $tag ) {
					$st['label'] = max( 0, $st['label'] - 1 );
				} elseif ( 'NAV' === $tag ) {
					if ( $st['wait'] && $st['wait'][1] === $st['nav'] ) {
						self::count( $st, 'left', 'nav' );
						$st['wait'] = null;
					}
					$st['nav'] = max( 0, $st['nav'] - 1 );
				}
				continue;
			}
			if ( isset( $offs[ $n ] ) ) {
				$skip = $offs[ $n ]; // 0: only the element itself (what is inside it is not proven)
				continue;
			}
			if ( $ctl && ! isset( self::VOID[ $tag ] ) && ! isset( self::ATOMIC[ $tag ] ) ) {
				if ( $ah && $tag === $ah[0] ) {
					++$ah[1];
				} elseif ( ! $ah && 'true' === strtolower( trim( (string) $p->get_attribute( 'aria-hidden' ) ) ) && ! ( method_exists( $p, 'has_self_closing_flag' ) && $p->has_self_closing_flag() ) ) {
					$ah = array( $tag, 1 ); // its text is not part of the control's name
				}
			}
			foreach ( array( '*', $tag ) as $key ) {
				foreach ( isset( self::RULES[ $key ] ) ? self::RULES[ $key ] : array() as $rule ) {
					self::$rule( $p, $n, $st );
				}
			}
			// Structure: links and buttons, icons inside them, svg, nav, label.
			if ( 'A' === $tag || 'BUTTON' === $tag ) {
				if ( 'A' === $tag && $ctl && 'A' === end( $ctl )['tag'] ) {
					self::finish( array_pop( $ctl ), $st ); // links do not nest: the parser closes the open one
				}
				$ctl[] = array(
					'tag'   => $tag,
					'n'     => $n,
					'text'  => false,
					'named' => null !== $p->get_attribute( 'aria-label' ) || null !== $p->get_attribute( 'aria-labelledby' ),
					'icons' => array(), // [ n, can hide, is icon ]
					'title' => $p->get_attribute( 'title' ),
					'href'  => $p->get_attribute( 'href' ),
				);
				$ic    = null;
			} elseif ( ( 'SVG' === $tag || 'I' === $tag ) && $ctl && ! $svg ) {
				$top    = count( $ctl ) - 1;
				$named  = null !== $p->get_attribute( 'aria-label' ) || null !== $p->get_attribute( 'aria-labelledby' );
				$hide   = ! $named && null === $p->get_attribute( 'aria-hidden' ) && null === $p->get_attribute( 'role' );
				$is_ico = 'SVG' === $tag || is_string( $p->get_attribute( 'class' ) );
				if ( $named ) {
					$ctl[ $top ]['named'] = true;
				}
				$ctl[ $top ]['icons'][] = array( $n, $hide && $is_ico, $is_ico );
				if ( 'I' === $tag ) {
					$ic = count( $ctl[ $top ]['icons'] ) - 1;
				} else {
					$sv = count( $ctl[ $top ]['icons'] ) - 1;
				}
			} elseif ( 'TITLE' === $tag && $svg && $ctl ) {
				$top                  = count( $ctl ) - 1;
				$ctl[ $top ]['named'] = true; // an svg <title> names it, so the svg is never hidden
				if ( null !== $sv && isset( $ctl[ $top ]['icons'][ $sv ] ) ) {
					$ctl[ $top ]['icons'][ $sv ][1] = false;
				}
			} elseif ( 'IMG' === $tag && $ctl && ! $ah ) {
				$alt = isset( $st['plan'][ $n ]['alt'] ) ? $st['plan'][ $n ]['alt'] : $p->get_attribute( 'alt' );
				if ( is_string( $alt ) && '' !== trim( $alt ) ) {
					$ctl[ count( $ctl ) - 1 ]['text'] = true;
				}
			}
			if ( 'SVG' === $tag && ! ( method_exists( $p, 'has_self_closing_flag' ) && $p->has_self_closing_flag() ) ) {
				++$svg;
			} elseif ( 'NAV' === $tag ) {
				++$st['nav'];
			} elseif ( 'LABEL' === $tag ) {
				++$st['label'];
				$for = $p->get_attribute( 'for' );
				if ( is_string( $for ) && '' !== $for ) {
					$st['fors'][ $for ] = true;
				}
			} elseif ( 'UL' === $tag && $st['wait'] ) {
				$id   = $p->get_attribute( 'id' );
				$name = is_string( $id ) && 0 === strpos( $id, 'menu-' ) ? self::menu_name( substr( $id, 5 ) ) : '';
				if ( '' !== $name ) {
					self::add( $st, $st['wait'][0], 'aria-label', $name, 'a11y_nav_label' );
					$st['wait'] = null;
				}
			}
		}
		if ( $st['wait'] ) {
			self::count( $st, 'left', 'nav' );
		}
		// Placeholder-only fields: no label wraps them and no label points at them.
		foreach ( $st['fields'] as $f ) {
			if ( '' === $f[1] || ! isset( $st['fors'][ $f[1] ] ) ) {
				self::add( $st, $f[0], 'aria-label', $f[2], 'a11y_guess_field' );
			}
		}
		return $st;
	}

	/**
	 * A link or button closed: hide its icons when it has text, or name an icon-only link.
	 *
	 * @param array $c  The control.
	 * @param array $st State.
	 * @return void
	 */
	private static function finish( $c, &$st ) {
		$icons = array_filter(
			$c['icons'],
			function ( $i ) {
				return $i[2];
			}
		);
		if ( ! $icons ) {
			return;
		}
		if ( $c['text'] ) {
			if ( isset( $st['on']['a11y_icons'] ) ) {
				foreach ( $icons as $i ) {
					if ( $i[1] ) {
						self::add( $st, $i[0], 'aria-hidden', 'true', 'a11y_icons' );
					}
				}
			}
			return;
		}
		if ( 'A' !== $c['tag'] || $c['named'] || ! isset( $st['on']['a11y_guess_icon_link'] ) ) {
			return;
		}
		$label = is_string( $c['title'] ) ? self::plain( $c['title'] ) : '';
		if ( '' === $label && is_string( $c['href'] ) ) {
			$label = self::linked_title( $c['href'] );
		}
		if ( '' !== $label ) {
			self::add( $st, $c['n'], 'aria-label', $label, 'a11y_guess_icon_link' );
		} else {
			self::count( $st, 'left', 'icon_link' );
		}
	}

	/**
	 * Rule: an image with no alt at all gets the media library alt text.
	 *
	 * @param \WP_HTML_Tag_Processor $p  Processor.
	 * @param int                    $n  Opening-tag number.
	 * @param array                  $st State.
	 * @return void
	 */
	private static function rule_alt( $p, $n, &$st ) {
		if ( ! isset( $st['on']['a11y_alt'] ) || null !== $p->get_attribute( 'alt' ) ) {
			return;
		}
		$class = $p->get_attribute( 'class' );
		$alt   = is_string( $class ) && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $m ) ? self::attachment_alt( (int) $m[1] ) : '';
		if ( '' !== $alt ) {
			self::add( $st, $n, 'alt', $alt, 'a11y_alt' );
		} else {
			self::count( $st, 'left', 'alt' );
		}
	}

	/**
	 * Rule: inside a nav, the link to the page being shown gets aria-current="page".
	 *
	 * @param \WP_HTML_Tag_Processor $p  Processor.
	 * @param int                    $n  Opening-tag number.
	 * @param array                  $st State.
	 * @return void
	 */
	private static function rule_current( $p, $n, &$st ) {
		if ( ! isset( $st['on']['a11y_current'] ) || ! $st['nav'] || null !== $p->get_attribute( 'aria-current' ) ) {
			return;
		}
		$href = $p->get_attribute( 'href' );
		$here = self::here();
		// A link with a fragment goes to a part of a page (a section, a table of contents), not to the page.
		if ( is_string( $href ) && false === strpos( $href, '#' ) && '' !== $here && self::path_of( $href ) === $here ) {
			self::add( $st, $n, 'aria-current', 'page', 'a11y_current' );
		}
	}

	/**
	 * Rule: a link that opens a new tab points at the hidden "(opens in a new tab)" text.
	 *
	 * @param \WP_HTML_Tag_Processor $p  Processor.
	 * @param int                    $n  Opening-tag number.
	 * @param array                  $st State.
	 * @return void
	 */
	private static function rule_new_tab( $p, $n, &$st ) {
		if ( ! isset( $st['on']['a11y_new_tab'] ) ) {
			return;
		}
		// aria-describedby would silence an existing description (aria-description, title); appending to one would read two notes.
		foreach ( array( 'aria-describedby', 'aria-description', 'title' ) as $a ) {
			if ( null !== $p->get_attribute( $a ) ) {
				return;
			}
		}
		$target = $p->get_attribute( 'target' );
		if ( is_string( $target ) && '_blank' === strtolower( trim( $target ) ) ) {
			self::add( $st, $n, 'aria-describedby', self::NEW_TAB_ID, 'a11y_new_tab' );
			self::$new_tab = true;
		}
	}

	/**
	 * Rule: a nav without a label waits for its menu (the first <ul id="menu-..."> inside).
	 *
	 * @param \WP_HTML_Tag_Processor $p  Processor.
	 * @param int                    $n  Opening-tag number.
	 * @param array                  $st State.
	 * @return void
	 */
	private static function rule_nav( $p, $n, &$st ) {
		if ( ! isset( $st['on']['a11y_nav_label'] ) || null !== $p->get_attribute( 'aria-label' ) || null !== $p->get_attribute( 'aria-labelledby' ) ) {
			return;
		}
		if ( $st['wait'] ) {
			self::count( $st, 'left', 'nav' );
		}
		$st['wait'] = array( $n, $st['nav'] + 1 );
	}

	/**
	 * Rule (guess): a field with only a placeholder.
	 *
	 * @param \WP_HTML_Tag_Processor $p  Processor.
	 * @param int                    $n  Opening-tag number.
	 * @param array                  $st State.
	 * @return void
	 */
	private static function rule_field( $p, $n, &$st ) {
		if ( ! isset( $st['on']['a11y_guess_field'] ) || $st['label'] ) {
			return;
		}
		foreach ( array( 'aria-label', 'aria-labelledby', 'title' ) as $a ) {
			if ( null !== $p->get_attribute( $a ) ) {
				return;
			}
		}
		$type = $p->get_attribute( 'type' );
		$ph   = $p->get_attribute( 'placeholder' );
		$ph   = is_string( $ph ) ? self::plain( $ph ) : '';
		if ( '' === $ph || ( 'INPUT' === $p->get_tag() && is_string( $type ) && in_array( strtolower( trim( $type ) ), self::NO_LABEL_TYPES, true ) ) ) {
			return;
		}
		$id             = $p->get_attribute( 'id' );
		$st['fields'][] = array( $n, is_string( $id ) ? $id : '', $ph );
	}

	/**
	 * Plan one attribute.
	 *
	 * @param array  $st   State.
	 * @param int    $n    Opening-tag number.
	 * @param string $attr Attribute.
	 * @param string $val  Value.
	 * @param string $item Item key (for the report).
	 * @return void
	 */
	private static function add( &$st, $n, $attr, $val, $item ) {
		$st['plan'][ $n ][ $attr ] = $val;
		self::count( $st, 'added', $item );
	}

	/**
	 * Count one thing for the report.
	 *
	 * @param array  $st  State.
	 * @param string $g   added | left.
	 * @param string $key Key.
	 * @return void
	 */
	private static function count( &$st, $g, $key ) {
		$st[ $g ][ $key ] = ( isset( $st[ $g ][ $key ] ) ? $st[ $g ][ $key ] : 0 ) + 1;
	}

	/**
	 * Stored text as plain words (stored names and titles may hold entities and tags).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function plain( $s ) {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Media library alt text of an attachment this viewer may see ('' for any other post, or one
	 * attached to a draft or private post the viewer cannot read, as in the media REST route).
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function attachment_alt( $id ) {
		$key = 'alt' . $id;
		if ( ! isset( self::$memo[ $key ] ) ) {
			$post               = $id > 0 ? get_post( $id ) : null;
			$seen               = $post && 'attachment' === $post->post_type && ( 'publish' === get_post_status( $post ) || current_user_can( 'read_post', $id ) );
			self::$memo[ $key ] = $seen ? self::plain( get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
		}
		return self::$memo[ $key ];
	}

	/**
	 * Name of the menu a wp_nav_menu() list belongs to (its id is menu-{slug}, then -2, -3 on repeats).
	 *
	 * @param string $slug Slug from the id.
	 * @return string
	 */
	private static function menu_name( $slug ) {
		$key = 'menu' . $slug;
		if ( ! isset( self::$memo[ $key ] ) ) {
			$menu = wp_get_nav_menu_object( $slug );
			if ( ! $menu && preg_match( '/^(.+)-\d+$/', $slug, $m ) ) {
				$menu = wp_get_nav_menu_object( $m[1] );
			}
			self::$memo[ $key ] = $menu && isset( $menu->name ) ? self::plain( $menu->name ) : '';
		}
		return self::$memo[ $key ];
	}

	/**
	 * Title of the published page a same-site link points to.
	 *
	 * @param string $href Link.
	 * @return string
	 */
	private static function linked_title( $href ) {
		$key = 'title' . strtok( $href, '#' );
		if ( ! isset( self::$memo[ $key ] ) ) {
			$title = '';
			$used  = isset( self::$memo['lookups'] ) ? self::$memo['lookups'] : 0;
			if ( $used >= self::MAX_LOOKUPS ) {
				return ''; // each lookup is a query: past the cap the link is counted as left alone
			}
			if ( '' !== self::path_of( $href ) ) {
				self::$memo['lookups'] = $used + 1;
				$id = url_to_postid( $href );
				if ( $id && 'publish' === get_post_status( $id ) ) { // never show a private title
					$title = self::plain( get_post_field( 'post_title', $id, 'raw' ) );
				}
			}
			self::$memo[ $key ] = $title;
		}
		return self::$memo[ $key ];
	}

	/**
	 * Path of the page being shown ('' when unknown), compared with link paths.
	 *
	 * @return string
	 */
	private static function here() {
		if ( ! isset( self::$memo['here'] ) ) {
			$req                  = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : null;
			self::$memo['here'] = null === $req ? '' : self::path_of( home_url( $req ) );
		}
		return self::$memo['here'];
	}

	/**
	 * A same-site link as a lower-case path ('/', '/about'), or '' for another
	 * site, a relative path, a query or a bare fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path_of( $url ) {
		$url = trim( $url );
		if ( '' === $url || '#' === $url[0] ) {
			return '';
		}
		$u = wp_parse_url( $url );
		if ( ! is_array( $u ) || isset( $u['query'] ) ) {
			return '';
		}
		if ( isset( $u['host'] ) ) {
			if ( ! isset( self::$memo['host'] ) ) {
				$home               = wp_parse_url( home_url() );
				self::$memo['host'] = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
			}
			if ( '' === self::$memo['host'] || strtolower( $u['host'] ) !== self::$memo['host'] ) {
				return '';
			}
		} elseif ( ! isset( $u['path'] ) || '/' !== substr( $u['path'], 0, 1 ) ) {
			return '';
		}
		return '/' . strtolower( trim( rawurldecode( isset( $u['path'] ) ? $u['path'] : '' ), '/' ) );
	}
}
