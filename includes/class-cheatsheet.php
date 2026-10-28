<?php
/**
 * Cheatsheet: every class, variable, mixin, recipe and data attribute
 * TokenForge provides, grouped, each with a plain-language explanation, what it
 * applies (parsed from the generated stylesheet) and the text to copy.
 *
 * Used by the dashboard's Cheatsheet section (one-click copy) and by the AI
 * abilities, so an agent building pages sees the same reference a person does.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Cheatsheet data.
 */
class Cheatsheet {

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Groups, in display order: id => [ title, explanation ].
	 */
	const GROUPS = array(
		'layout'     => array( 'Layout & sections', 'Top-level sections get side gutters and padding automatically. Classes change the padding size, the content width and the content grid.' ),
		'grid'       => array( 'Grids & flex', 'Grid classes set equal or ratio columns from the grid variables. Auto grids wrap to fewer columns by themselves.' ),
		'spacing'    => array( 'Spacing', 'Padding (pad-), margin (mar-) and gap classes from the fluid space scale. "-block" is top and bottom, "-inline" is left and right, "a-to-b" shrinks from size a on wide screens to b on small ones.' ),
		'typography' => array( 'Typography', 'Heading and text sizes from the fluid type scale, plus alignment and text colours.' ),
		'colors'     => array( 'Colours & backgrounds', 'Background classes set readable text, heading, link and button colours for everything inside. Transparent variants end in -trans-{percent}.' ),
		'buttons'    => array( 'Buttons', 'Any btn--* class makes a button. Combine a colour (btn--primary), a variant (-dark, -light, btn--outline) and a size (btn--s … btn--xl).' ),
		'cards'      => array( 'Cards & forms', 'A card is a padded flex column; elements whose class contains __media or __avatar are styled inside it.' ),
		'icons'      => array( 'Icons', 'Put data-icon on an svg or i element (or use .icon). Sizes, boxed/plain styles and icon lists.' ),
		'borders'    => array( 'Borders, radius & shadows', 'Borders and dividers from the border tokens, corner radius steps and elevation shadows.' ),
		'overlays'   => array( 'Overlays, surfaces & ribbons', 'Tints and textures behind content, and corner ribbons on cards.' ),
		'motion'     => array( 'Motion & effects', 'Scroll-driven entrance and exit effects, effects that play once when visible, and hover effects.' ),
		'content'    => array( 'Content & prose', 'Long-form styling and vertical rhythm for rich text.' ),
		'utilities'  => array( 'Utilities & accessibility', 'Display, position and object-fit helpers, screen-reader text and colour-scheme switches.' ),
		'v-color'    => array( 'Variables: colours', 'Use var(--name) for every colour. Each family has shades: ultra-light, light, semi-light, base, hover, semi-dark, dark, ultra-dark.' ),
		'v-space'    => array( 'Variables: spacing', 'Fluid spacing steps. --space-* for gaps and padding inside components, --section-space-* for section padding.' ),
		'v-type'     => array( 'Variables: typography', 'Fluid text sizes (--text-*), heading sizes (--h1 … --h6) and "-to-" sizes that shrink on small screens.' ),
		'v-layout'   => array( 'Variables: layout & widths', 'Content width, gutters, grid templates and the width scale.' ),
		'v-radius'   => array( 'Variables: radius, borders & shadows', 'Corner radius steps, border shorthands and shadows.' ),
		'v-other'    => array( 'Variables: components & other', 'Button, card, icon, motion and other component variables. Set them on an element to adjust just that element.' ),
		'recipes'    => array( 'Recipes', 'Type the recipe name with a leading ? in the builder CSS editor, then pick it or finish with ; to expand it.' ),
		'attributes' => array( 'Data attributes', 'Attributes that switch features on for one element.' ),
	);

	/**
	 * All groups with their items.
	 *
	 * @return array [ [ 'id', 'title', 'desc', 'items' => [ [ 'n', 'k', 'copy', 'd', 'css' ], … ] ], … ]
	 */
	public static function data() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$items = array();
		foreach ( array_keys( self::GROUPS ) as $g ) {
			$items[ $g ] = array();
		}

		// ---- Classes ----
		$css = self::class_css();
		foreach ( Features\Builder_Enhancements::menu_classes() as $name ) {
			list( $group, $desc ) = self::describe_class( $name );
			$applied = isset( $css[ $name ] ) ? self::short( $css[ $name ] ) : '';
			if ( '' === $desc ) {
				$desc = '' !== $applied ? 'Applies ' . rtrim( self::short( $applied, 110 ), ';' ) . '.' : 'Utility class ' . $name . '.';
			}
			$items[ $group ][] = array(
				'n'    => '.' . $name,
				'k'    => 'class',
				'copy' => $name,
				'd'    => $desc,
				'css'  => $applied,
			);
		}

		// ---- Variables ----
		foreach ( Token_Catalog::every_variable() as $t ) {
			if ( empty( $t['enabled'] ) || '' === (string) $t['value'] ) {
				continue;
			}
			if ( preg_match( '/-(h|s|l|r|g|b|rgb|hsl|hex)$/', $t['name'] ) && 'color' === $t['group'] ) {
				continue; // colour channels are building blocks, not values to use
			}
			list( $group, $desc ) = self::describe_var( $t['name'], $t['group'] );
			if ( '' === $desc ) {
				$desc = 'Token ' . str_replace( '-', ' ', $t['name'] ) . '.';
			}
			$items[ $group ][] = array(
				'n'    => '--' . $t['name'],
				'k'    => 'var',
				'copy' => 'var(--' . $t['name'] . ')',
				'd'    => $desc,
				'css'  => self::short( $t['value'] ),
			);
		}

		// ---- Recipes ----
		$rs = Recipes::settings();
		if ( ! empty( $rs['enabled'] ) ) {
			foreach ( Recipes::all() as $r ) {
				$items['recipes'][] = array(
					'n'    => '?' . $r['n'],
					'k'    => 'recipe',
					'copy' => '?' . $r['n'],
					'd'    => self::recipe_desc( $r ),
					'css'  => self::short( str_replace( '{{cursor}}', '', (string) $r['code'] ), 160 ),
				);
			}
		}

		// ---- Data attributes ----
		$ex = Extras::settings();
		if ( ! empty( $ex['enable_icon'] ) ) {
			$items['attributes'][] = array( 'n' => 'data-icon', 'k' => 'attr', 'copy' => 'data-icon', 'd' => 'Makes an svg or i element (or a link wrapping one) an icon: size, colour, box and hover colours from the icon settings.', 'css' => '' );
			$items['attributes'][] = array( 'n' => 'data-icon-size', 'k' => 'attr', 'copy' => 'data-icon-size="m"', 'd' => 'Icon size: xs, s, m, l, xl or 2xl.', 'css' => '' );
			$items['attributes'][] = array( 'n' => 'data-icon-style', 'k' => 'attr', 'copy' => 'data-icon-style="boxed"', 'd' => 'boxed puts the icon in a padded box, plain removes the box.', 'css' => '' );
			$items['attributes'][] = array( 'n' => 'data-icon-list', 'k' => 'attr', 'copy' => 'data-icon-list', 'd' => 'On a list: every item gets a small icon (same as .icon-list).', 'css' => '' );
			$items['attributes'][] = array( 'n' => 'data-icon-hover', 'k' => 'attr', 'copy' => 'data-icon-hover', 'd' => 'On a parent: hovering it switches the icon inside to its hover colours.', 'css' => '' );
		}
		if ( ! empty( $ex['ribbons'] ) ) {
			$items['attributes'][] = array( 'n' => 'data-ribbon-position', 'k' => 'attr', 'copy' => 'data-ribbon-position="top-right"', 'd' => 'On a .ribbon: which corner it sits in (top-right or top-left).', 'css' => '' );
		}
		if ( ! empty( $ex['inverted_radius'] ) ) {
			$items['attributes'][] = array( 'n' => 'data-inverted-radius-1-position', 'k' => 'attr', 'copy' => 'data-inverted-radius-1-position="bottom-left"', 'd' => 'Where the first concave corner is drawn: top-left, top-right, bottom-left or bottom-right.', 'css' => '' );
			$items['attributes'][] = array( 'n' => 'data-inverted-radius-1-slice', 'k' => 'attr', 'copy' => 'data-inverted-radius-1-slice="top-left"', 'd' => 'Which quarter of the curve is shown. Use -2- attributes for a second corner.', 'css' => '' );
		}

		foreach ( Attributes::others() as $n => $a ) {
			$items['attributes'][] = array( 'n' => $n, 'k' => 'attr', 'copy' => $a[0], 'd' => $a[1], 'css' => '' );
		}
		foreach ( Attributes::all() as $n => $a ) {
			$items['attributes'][] = array( 'n' => $n, 'k' => 'attr', 'copy' => $a[1], 'd' => $a[2] . ( $a[0] ? ' Values: ' . implode( ', ', $a[0] ) . '.' : '' ), 'css' => '' );
		}
		if ( Modules::on( 'builder' ) ) {
			$items['utilities'][] = array( 'n' => 'Heading levels (builder canvas)', 'k' => 'tip', 'copy' => 'Heading levels', 'd' => 'Options, Canvas, Heading levels: an h1 to h6 badge on every heading in the canvas; a skipped level or a second h1 is red and ends in !. Only you see it.', 'css' => '' );
			$items['utilities'][] = array( 'n' => 'Focus order (builder canvas)', 'k' => 'tip', 'copy' => 'Focus order', 'd' => 'Options, Canvas, Focus order: numbers every link, button and field in the order Tab visits them. Only you see it.', 'css' => '' );
		}
		foreach ( Features\A11y_Auto::cheatsheet() as $g => $list ) {
			$items[ $g ] = array_merge( $items[ $g ], $list );
		}

		$out = array();
		foreach ( self::GROUPS as $id => $g ) {
			if ( empty( $items[ $id ] ) ) {
				continue;
			}
			$out[] = array( 'id' => $id, 'title' => $g[0], 'desc' => $g[1], 'items' => $items[ $id ] );
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * Shorten long CSS for display.
	 *
	 * @param string $css CSS.
	 * @param int    $max Max length.
	 * @return string
	 */
	private static function short( $css, $max = 220 ) {
		$css = trim( preg_replace( '/\s+/', ' ', (string) $css ) );
		return strlen( $css ) > $max ? substr( $css, 0, $max - 1 ) . '…' : $css;
	}

	/**
	 * Declarations of every rule whose selector (or one selector of a list) is
	 * exactly one class, from the generated stylesheet.
	 *
	 * @return array class => "prop:value;…" (media-query rules marked)
	 */
	public static function class_css() {
		$css = Css_Generator::get_instance()->build_css( false, true );
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$map = array();
		self::walk( $css, '', $map );
		return $map;
	}

	/**
	 * Walk CSS rules (recursing into at-rules).
	 *
	 * @param string $css  CSS.
	 * @param string $ctx  At-rule context, e.g. "@media (…)".
	 * @param array  $map  Result (by reference).
	 * @return void
	 */
	private static function walk( $css, $ctx, &$map ) {
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) {
				break;
			}
			$sel   = trim( substr( $css, $i, $open - $i ) );
			$depth = 1;
			$j     = $open + 1;
			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					$depth++;
				} elseif ( '}' === $css[ $j ] ) {
					$depth--;
				}
				$j++;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			$i    = $j;
			if ( '' === $sel ) {
				continue;
			}
			if ( '@' === $sel[0] ) {
				if ( 0 === strpos( $sel, '@media' ) || 0 === strpos( $sel, '@layer' ) || 0 === strpos( $sel, '@supports' ) ) {
					self::walk( $body, 0 === strpos( $sel, '@layer' ) ? $ctx : trim( $sel ), $map );
				}
				continue;
			}
			foreach ( self::split( $sel ) as $one ) {
				if ( preg_match( '/^\.([a-z0-9][a-z0-9_\-]*)$/i', trim( $one ), $m ) ) {
					$decl = trim( $body );
					if ( '' === $decl ) {
						continue;
					}
					$decl = '' !== $ctx ? $ctx . ' { ' . $decl . ' }' : $decl;
					$map[ $m[1] ] = isset( $map[ $m[1] ] ) ? $map[ $m[1] ] . '; ' . $decl : $decl;
				}
			}
		}
	}

	/**
	 * Split a selector list at top-level commas.
	 *
	 * @param string $sel Selector list.
	 * @return string[]
	 */
	private static function split( $sel ) {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $sel );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $sel[ $i ];
			if ( '(' === $ch || '[' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch || ']' === $ch ) {
				$depth--;
			}
			if ( ',' === $ch && 0 === $depth ) {
				$out[] = $cur;
				$cur   = '';
			} else {
				$cur .= $ch;
			}
		}
		$out[] = $cur;
		return $out;
	}

	/**
	 * Group + explanation of a class name.
	 *
	 * @param string $n Class name.
	 * @return array [ group, description ]
	 */
	public static function describe_class( $n ) {
		$sz   = '(xxl|xl|l|m|s|xs)';
		$fam  = '([a-z]+)';
		$fluid = function ( $a, $b ) {
			return "fluid: $a on wide screens, shrinking to $b on small screens";
		};
		$rules = array(
			// Layout.
			array( "/^section--$sz\$/", 'layout', function ( $m ) { return 'Section padding ' . $m[1] . ': top and bottom var(--section-space-' . $m[1] . '), plus the side gutters.'; } ),
			array( "/^section--$sz-to-$sz\$/", 'layout', function ( $m ) use ( $fluid ) { return 'Section padding, ' . $fluid( $m[1], $m[2] ) . '.'; } ),
			array( '/^section--none$/', 'layout', 'A section with no top or bottom padding (the side gutters stay).' ),
			array( "/^header--$sz\$/", 'layout', function ( $m ) { return 'Header spacing ' . $m[1] . ': top and bottom var(--space-' . $m[1] . ') with side gutters.'; } ),
			array( '/^container$/', 'layout', 'Centres its content at the content width (var(--content-width)).' ),
			array( '/^content-width$/', 'layout', 'Full width up to the content width, centred.' ),
			array( '/^content-width--safe$/', 'layout', 'Like content-width, but keeps the side gutter on small screens.' ),
			array( '/^content-grid$/', 'layout', 'A content grid: children sit in the centred content column and can break out with the content--* classes.' ),
			array( '/^content-grid--off$/', 'layout', 'Opts a section out of the automatic content grid.' ),
			array( '/^content--feature$/', 'layout', 'In a content grid: spans the "feature" column, a bit wider than the content.' ),
			array( '/^content--feature-max$/', 'layout', 'In a content grid: spans the "feature-max" column, wider than feature.' ),
			array( '/^content--full$/', 'layout', 'In a content grid: spans the full width, edge to edge.' ),
			array( '/^content--full-safe$/', 'layout', 'In a content grid: full width, but keeps the side gutter as padding.' ),
			array( '/^content-offset--off$/', 'layout', 'Stops the automatic offset below a fixed header on this element.' ),
			array( '/^(container|content|grid)-gap$/', 'spacing', function ( $m ) { return 'Gap of var(--' . $m[1] . '-gap) between the children.'; } ),
			array( '/^gap--none$/', 'spacing', 'Removes the gap between children.' ),
			array( '/^width--(\d+)$/', 'layout', function ( $m ) { return 'Width ' . $m[1] . '% of the content width.'; } ),
			array( "/^width--$sz\$/", 'layout', function ( $m ) { return 'Width step ' . $m[1] . ' of the width scale.'; } ),
			array( '/^width(--|-)(.+)$/', 'layout', function ( $m ) { return 'Width: ' . $m[2] . '.'; } ),
			// Grids.
			array( '/^grid-(\d+)$/', 'grid', function ( $m ) { return $m[1] . ' equal columns (var(--grid-' . $m[1] . ')).'; } ),
			array( '/^grid-(\d)-(\d)$/', 'grid', function ( $m ) { return 'Two columns in a ' . $m[1] . ':' . $m[2] . ' ratio.'; } ),
			array( '/^grid-auto-(\d+)$/', 'grid', function ( $m ) { return 'Up to ' . $m[1] . ' columns that wrap to fewer on their own as space runs out.'; } ),
			array( '/^grid--auto-(\d+)$/', 'grid', function ( $m ) { return 'Up to ' . $m[1] . ' columns that wrap to fewer on their own as space runs out (var(--grid-auto-' . $m[1] . ') as a class).'; } ),
			array( '/^grid--auto-(fill|fit)$/', 'grid', function ( $m ) { return 'With a grid--auto-* class: lay the columns out with auto-' . $m[1] . ' on this grid.'; } ),
			array( '/^grid--auto-rows$/', 'grid', 'Rows share the height evenly, each at least as tall as its content.' ),
			array( '/^(auto-grid|grid-auto)$/', 'grid', 'A grid whose columns wrap automatically (set --column-count on the element).' ),
			array( '/^variable-grid$/', 'grid', 'A grid whose column count comes from a variable you can change per breakpoint.' ),
			array( '/^grid$/', 'grid', 'display: grid.' ),
			array( '/^(flex|flex-row|flex-col|flex-wrap|flex-nowrap|items-.+|justify-.+|block|inline-block|inline-flex)$/', 'grid', null ),
			array( '/^columns-(\d+)$/', 'grid', function ( $m ) { return 'Text flows in ' . $m[1] . ' newspaper-style columns.'; } ),
			// Spacing.
			array( '/^(padding|pad)--(.+)$/', 'spacing', function ( $m ) { return 'Padding of var(--space-' . $m[2] . ').'; } ),
			array( '/^margin-(top|right|bottom|left|block|inline)--(.+)$/', 'spacing', function ( $m ) { return 'Margin ' . $m[1] . ' of var(--space-' . $m[2] . ').'; } ),
			array( '/^(gap|row-gap|col-gap)--(.+)$/', 'spacing', function ( $m ) { return array( 'gap' => 'Gap', 'row-gap' => 'Row gap', 'col-gap' => 'Column gap' )[ $m[1] ] . ' of var(--space-' . $m[2] . ').'; } ),
			// "-space-" classes are made from the section spacing tokens (--section-space-*).
			array( "/^(pad|mar|gap)(-block|-inline)?-space-$sz\$/", 'spacing', function ( $m ) { return self::space_word( $m[1] ) . self::axis_word( $m[2] ) . ' of var(--section-space-' . $m[3] . ') (section spacing).'; } ),
			array( "/^(pad|mar|gap)(-block|-inline)?-space-$sz-to-$sz\$/", 'spacing', function ( $m ) { return self::space_word( $m[1] ) . self::axis_word( $m[2] ) . ' of section spacing, fluid from ' . $m[3] . ' on wide screens to ' . $m[4] . ' on small screens.'; } ),
			array( "/^(pad|mar|gap)(-block|-inline)?-$sz\$/", 'spacing', function ( $m ) { return self::space_word( $m[1] ) . self::axis_word( $m[2] ) . ' of var(--space-' . $m[3] . ').'; } ),
			array( "/^(pad|mar|gap)(-block|-inline)?-$sz-to-$sz\$/", 'spacing', function ( $m ) { return self::space_word( $m[1] ) . self::axis_word( $m[2] ) . ', fluid from ' . $m[3] . ' on wide screens to ' . $m[4] . ' on small screens.'; } ),
			array( '/^(pad|mar|gap)(-block|-inline)?-(.+)$/', 'spacing', function ( $m ) { return self::space_word( $m[1] ) . self::axis_word( $m[2] ) . ' of var(--' . $m[3] . ').'; } ),
			// Typography.
			array( '/^h([1-6])$/', 'typography', function ( $m ) { return 'Looks like an h' . $m[1] . ' (size and heading styles) on any element; the tag stays the same.'; } ),
			array( '/^h([1-6])-to-h([1-6])$/', 'typography', function ( $m ) use ( $fluid ) { return 'Heading size, ' . $fluid( 'h' . $m[1], 'h' . $m[2] ) . '.'; } ),
			array( "/^text--$sz\$/", 'typography', function ( $m ) { return 'Body text at size ' . $m[1] . ' (var(--text-' . $m[1] . ')) with the body text styles.'; } ),
			array( "/^text-$sz\$/", 'typography', function ( $m ) { return 'Font size var(--text-' . $m[1] . ').'; } ),
			array( "/^text-$sz-to-$sz\$/", 'typography', function ( $m ) use ( $fluid ) { return 'Font size, ' . $fluid( $m[1], $m[2] ) . '.'; } ),
			array( '/^text--(dark|light|dark-muted|light-muted)$/', 'colors', function ( $m ) { return 'Text colour var(--text-' . $m[1] . ').'; } ),
			array( '/^text-(center|left|right)$/', 'typography', function ( $m ) { return 'Aligns text ' . $m[1] . '.'; } ),
			array( '/^text-shadow-?-?(\d)$/', 'borders', function ( $m ) { return 'Text shadow level ' . $m[1] . ' (var(--text-shadow-' . $m[1] . ')).'; } ),
			array( '/^(h[1-6]|text-.+)-(max-width|line-height)$/', 'typography', null ),
			array( '/^smart-spacing(.*)$/', 'content', function ( $m ) { return '' === $m[1] ? 'Vertical rhythm: consistent space between paragraphs, headings, lists and figures inside.' : ( '--off' === $m[1] ? 'Turns the vertical rhythm off inside this element.' : 'Resets default margins so only the rhythm spaces the children.' ); } ),
			array( '/^(prose)$/', 'content', 'Long-form styling for lists, figures, code, tables and rules inside.' ),
			array( '/^blockquote$/', 'content', 'Quote styling from the blockquote settings.' ),
			// Colours.
			array( '/^bg--(ultra-light|light|dark|ultra-dark)$/', 'colors', function ( $m ) { return 'Background var(--bg-' . $m[1] . ') with readable text, heading, link and button colours inside.'; } ),
			array( '/^(bg|text|border)-(.+)-trans-(\d+)$/', 'colors', function ( $m ) { return array( 'bg' => 'Background', 'text' => 'Text', 'border' => 'Border' )[ $m[1] ] . ' colour ' . $m[2] . ' at ' . $m[3] . '% opacity.'; } ),
			array( '/^(bg|text|border)--?(.+)$/', 'colors', null ),
			array( '/^scheme--(light|dark)$/', 'utilities', function ( $m ) { return 'Forces the ' . $m[1] . ' colour scheme on this element and everything inside.'; } ),
			array( '/^scheme-toggle$/', 'utilities', 'Turns any button into the visitor light/dark switch.' ),
			// Buttons.
			array( '/^btn$/', 'buttons', 'A button in the default family.' ),
			array( "/^btn--$sz\$/", 'buttons', function ( $m ) { return 'Button size ' . $m[1] . ' (font size var(--text-' . $m[1] . '); padding scales with it).'; } ),
			array( '/^btn--outline$/', 'buttons', 'Outline variant: add it next to a colour class, for example btn--primary btn--outline.' ),
			array( '/^btn--none$/', 'buttons', 'Removes the button background and border.' ),
			array( "/^btn--$fam--outline\$/", 'buttons', function ( $m ) { return 'Outline button in the ' . $m[1] . ' colour.'; } ),
			array( "/^btn--$fam-(dark|light)\$/", 'buttons', function ( $m ) { return 'Button in the ' . $m[1] . ' colour, ' . $m[2] . ' variant.'; } ),
			array( "/^btn--$fam\$/", 'buttons', function ( $m ) { return 'Button in the ' . $m[1] . ' colour.'; } ),
			array( '/^unrelate$/', 'buttons', 'Keeps this button’s own colours inside a .bg--* section.' ),
			array( '/^skip-link$/', 'utilities', 'A "skip to content" link that appears on keyboard focus.' ),
			// Cards & forms.
			array( '/^card$/', 'cards', 'A card: padded flex column with background, radius, border and shadow from the card settings.' ),
			array( '/^form--(light|dark)$/', 'cards', function ( $m ) { return 'Form fields in ' . $m[1] . ' colours.'; } ),
			array( '/^form--columns$/', 'cards', 'On the builder’s form: fields in two columns (text areas, choices and the button across both), one column on small screens.' ),
			array( '/^form--inline$/', 'cards', 'On the builder’s form: fields and the button in one row that wraps, for a newsletter or a search.' ),
			array( '/^form--compact$/', 'cards', 'On the builder’s form: smaller fields and less space between them.' ),
			array( '/^form--card$/', 'cards', 'On the builder’s form: the form in a padded panel with a border.' ),
			// Icons.
			array( '/^icon$/', 'icons', 'A wrapper that styles the icon inside it (size, colour, box).' ),
			array( '/^icon--(2xl|xxl|xl|l|m|s|xs)$/', 'icons', function ( $m ) { return 'Icon size ' . $m[1] . ' (var(--icon-size-' . $m[1] . ')).'; } ),
			array( '/^icon--boxed$/', 'icons', 'Puts the icon in a padded box.' ),
			array( '/^icon--plain$/', 'icons', 'Icon without a box.' ),
			array( '/^icon-list$/', 'icons', 'A list where every item gets a small icon.' ),
			// Borders, radius, shadows.
			array( '/^border$/', 'borders', 'Border from the border tokens (var(--border)).' ),
			array( '/^border--(light|dark)$/', 'borders', function ( $m ) { return 'Border for ' . ( 'light' === $m[1] ? 'dark' : 'light' ) . ' backgrounds (var(--border-' . $m[1] . ')).'; } ),
			array( '/^border-(top|bottom)$/', 'borders', function ( $m ) { return 'Border on the ' . $m[1] . ' only.'; } ),
			array( '/^divider(--(light|dark))?$/', 'borders', function ( $m ) { return empty( $m[2] ) ? 'A horizontal divider line with space above and below.' : 'Divider for ' . ( 'light' === $m[2] ? 'dark' : 'light' ) . ' backgrounds.'; } ),
			array( "/^radius-$sz\$/", 'borders', function ( $m ) { return 'Corner radius ' . $m[1] . ' (var(--radius-' . $m[1] . ')).'; } ),
			array( '/^(radius|rounded)--(.+)$/', 'borders', function ( $m ) { return 'Corner radius ' . $m[2] . ' (var(--radius-' . $m[2] . ')).'; } ),
			array( '/^(radius-(full|circle)|rounded(-full)?)$/', 'borders', null ),
			array( '/^shadow-(\d)$/', 'borders', function ( $m ) { return 'Box shadow level ' . $m[1] . ' (var(--shadow-' . $m[1] . ')). Higher = more elevation.'; } ),
			array( '/^shadow-none$/', 'borders', 'Removes the box shadow.' ),
			array( '/^drop-shadow--(\d)$/', 'borders', function ( $m ) { return 'Drop shadow level ' . $m[1] . ': follows the shape of transparent images and icons.'; } ),
			// Overlays, surfaces, ribbons.
			array( '/^overlay$/', 'overlays', 'A colour tint between the background and the content (change it with --overlay-color).' ),
			array( '/^overlay-(.+)$/', 'overlays', function ( $m ) { return 'Custom overlay "' . $m[1] . '" from Surfaces & Overlays.'; } ),
			array( '/^surface-(.+)$/', 'overlays', function ( $m ) { return 'Background texture "' . $m[1] . '" from Surfaces & Overlays.'; } ),
			array( '/^ribbon$/', 'overlays', 'A corner ribbon band. Put it on a direct child of the card; the card clips it.' ),
			array( '/^ribbon--(top-left|top-right)$/', 'overlays', function ( $m ) { return 'Places the ribbon in the ' . str_replace( '-', ' ', $m[1] ) . ' corner.'; } ),
			// Motion.
			array( '/^on-enter--stagger$/', 'motion', 'Staggers the entrance of the children (each starts a little later).' ),
			array( '/^on-(enter|exit|visible)-all--(.+)$/', 'motion', function ( $m ) { return 'Every child gets the ' . $m[2] . ' ' . self::fx_word( $m[1] ) . ', one after another.'; } ),
			array( '/^on-(enter|exit|visible)--(.+)$/', 'motion', function ( $m ) { return ucfirst( $m[2] ) . ' ' . self::fx_word( $m[1] ) . '.'; } ),
			array( '/^on-hover--(.+)$/', 'motion', function ( $m ) { return 'Hover effect: ' . str_replace( '-', ' ', $m[1] ) . '.'; } ),
			array( '/^transition$/', 'motion', 'Applies var(--transition) so property changes animate.' ),
			// Utilities.
			array( '/^(hidden-accessible|sr-only|visually-hidden)$/', 'utilities', 'Hides the element visually but keeps it for screen readers.' ),
			array( '/^is-clickable$/', 'utilities', 'On a card: the link in its heading covers the whole card, so the whole card is clickable and a screen reader still hears one link. Keep one link in the heading; the keyboard focus ring shows just inside the card edge. Text in the card cannot be selected with the mouse, and a second link or button needs .is-above.' ),
			array( '/^is-above$/', 'utilities', 'On a second link or a button inside an .is-clickable card: lifts it above the card link so it stays separately clickable.' ),
			array( '/^is-bg$/', 'utilities', 'Makes this image or video the background of its parent (fills it, sits behind the content).' ),
			array( '/^object-(cover|contain)$/', 'utilities', function ( $m ) { return 'Image fit: ' . $m[1] . '.'; } ),
			array( '/^sticky$/', 'utilities', 'Sticks to the top while scrolling (below the header when that option is on).' ),
			array( '/^(hidden|relative|absolute)$/', 'utilities', null ),
		);
		foreach ( $rules as $r ) {
			if ( preg_match( $r[0], $n, $m ) ) {
				$d = is_callable( $r[2] ) ? call_user_func( $r[2], $m ) : ( null === $r[2] ? '' : $r[2] );
				return array( $r[1], $d );
			}
		}
		return array( 'utilities', '' );
	}

	/**
	 * Words for spacing classes.
	 *
	 * @param string $k pad|mar|gap.
	 * @return string
	 */
	private static function space_word( $k ) {
		return array( 'pad' => 'Padding', 'mar' => 'Margin', 'gap' => 'Gap' )[ $k ];
	}

	/**
	 * Axis words.
	 *
	 * @param string $a "-block" | "-inline" | "".
	 * @return string
	 */
	private static function axis_word( $a ) {
		return '-block' === $a ? ' top and bottom' : ( '-inline' === $a ? ' left and right' : '' );
	}

	/**
	 * Effect words.
	 *
	 * @param string $k enter|exit|visible.
	 * @return string
	 */
	private static function fx_word( $k ) {
		return array( 'enter' => 'entrance while scrolling into view', 'exit' => 'exit while scrolling out of view', 'visible' => 'effect, played once when it becomes visible' )[ $k ];
	}

	/**
	 * Group + explanation of a variable.
	 *
	 * @param string $n     Name (without --).
	 * @param string $group Token group.
	 * @return array [ group, description ]
	 */
	public static function describe_var( $n, $group ) {
		$sz = '(xxl|xl|l|m|s|xs)';
		if ( preg_match( "/^space-$sz\$/", $n, $m ) ) {
			return array( 'v-space', 'Fluid spacing step ' . $m[1] . ' for gaps and padding.' );
		}
		if ( preg_match( "/^section-space-$sz\$/", $n, $m ) ) {
			return array( 'v-space', 'Fluid section padding step ' . $m[1] . '.' );
		}
		if ( preg_match( "/^(section-space|space)-$sz-to-$sz\$/", $n, $m ) ) {
			return array( 'v-space', ( 'space' === $m[1] ? 'Spacing' : 'Section padding' ) . ': ' . $m[2] . ' on wide screens, shrinking to ' . $m[3] . ' on small screens.' );
		}
		if ( preg_match( "/^text-$sz\$/", $n, $m ) ) {
			return array( 'v-type', 'Fluid text size ' . $m[1] . ( 'm' === $m[1] ? ' (body text).' : '.' ) );
		}
		if ( preg_match( "/^text-$sz-to-$sz\$/", $n, $m ) ) {
			return array( 'v-type', 'Text size ' . $m[1] . ' on wide screens, shrinking to ' . $m[2] . ' on small screens.' );
		}
		if ( preg_match( '/^h([1-6])$/', $n, $m ) ) {
			return array( 'v-type', 'Fluid heading size h' . $m[1] . '.' );
		}
		if ( preg_match( '/^h([1-6])-to-h([1-6])$/', $n, $m ) ) {
			return array( 'v-type', 'Heading size h' . $m[1] . ' on wide screens, shrinking to h' . $m[2] . ' on small screens.' );
		}
		if ( preg_match( '/^(text|heading|h[1-6])-(font-family|font-weight|line-height|text-wrap|color|letter-spacing|max-width)$/', $n, $m ) ) {
			return array( 'v-type', ucfirst( str_replace( '-', ' ', $m[2] ) ) . ' of ' . ( 'text' === $m[1] ? 'body text' : ( 'heading' === $m[1] ? 'headings' : $m[1] ) ) . '.' );
		}
		if ( preg_match( '/^(bg|text)-(ultra-light|light|dark|ultra-dark|dark-muted|light-muted)$/', $n, $m ) ) {
			return array( 'v-color', ( 'bg' === $m[1] ? 'Background' : 'Text' ) . ' colour ' . $m[2] . '.' );
		}
		if ( preg_match( '/^([a-z]+)(-(ultra-light|light|semi-light|hover|semi-dark|dark|ultra-dark))?$/', $n, $m ) && 'color' === $group ) {
			return array( 'v-color', ucfirst( $m[1] ) . ( ! empty( $m[3] ) ? ' colour, ' . $m[3] . ' shade.' : ' colour (base shade).' ) );
		}
		if ( preg_match( '/^([a-z]+(?:-[a-z]+)*)-trans-(\d+)$/', $n, $m ) ) {
			return array( 'v-color', ucfirst( str_replace( '-', ' ', $m[1] ) ) . ' at ' . $m[2] . '% opacity.' );
		}
		if ( preg_match( "/^radius(-$sz|-circle|-50|-none|-full)?\$/", $n, $m ) ) {
			return array( 'v-radius', empty( $m[1] ) ? 'The default corner radius.' : 'Corner radius ' . ltrim( $m[1], '-' ) . '.' );
		}
		if ( preg_match( '/^(shadow|text-shadow|drop-shadow)-(\d)$/', $n, $m ) ) {
			return array( 'v-radius', ucfirst( str_replace( '-', ' ', $m[1] ) ) . ' level ' . $m[2] . '.' );
		}
		if ( preg_match( '/^(border|divider)/', $n ) ) {
			return array( 'v-radius', 'Border / divider token.' );
		}
		if ( preg_match( '/^(content-width|content-width-safe|gutter|width-.+|grid-.+|content-gap|container-gap|grid-gap|header-height|feature-.+|body-max-width|boxed-width|col-.+|section-padding-block)$/', $n ) ) {
			$known = array( 'content-width' => 'Maximum content width.', 'content-width-safe' => 'Content width minus the gutters (never touches the edges).', 'gutter' => 'Side gutter between content and the screen edge.', 'content-gap' => 'Gap inside containers and blocks.', 'container-gap' => 'Gap between a section’s children.', 'grid-gap' => 'Gap between grid items.', 'header-height' => 'Height of the sticky header.' );
			if ( isset( $known[ $n ] ) ) {
				return array( 'v-layout', $known[ $n ] );
			}
			if ( preg_match( '/^grid-(\d+)$/', $n, $m ) ) {
				return array( 'v-layout', 'Grid template with ' . $m[1] . ' equal columns (for grid-template-columns).' );
			}
			if ( preg_match( '/^grid-auto-(\d+)$/', $n, $m ) ) {
				return array( 'v-layout', 'Grid template: up to ' . $m[1] . ' columns that wrap on their own.' );
			}
			if ( preg_match( '/^grid-(\d)-(\d)$/', $n, $m ) ) {
				return array( 'v-layout', 'Grid template: two columns in a ' . $m[1] . ':' . $m[2] . ' ratio.' );
			}
			if ( preg_match( '/^width-(\d+)$/', $n, $m ) ) {
				return array( 'v-layout', $m[1] . '% of the content width.' );
			}
			return array( 'v-layout', '' );
		}
		if ( preg_match( '/^(ease-(.+))$/', $n, $m ) ) {
			return array( 'v-other', 'Easing curve "' . $m[2] . '" for transitions and animations.' );
		}
		if ( preg_match( '/^(btn|card|icon|focus|transition|link|overlay|surface|ribbon|blockquote|stagger|external-link)/', $n, $m ) ) {
			return array( 'v-other', ucfirst( str_replace( '-', ' ', $m[1] ) ) . ' setting: ' . str_replace( '-', ' ', $n ) . '.' );
		}
		$g = array( 'color' => 'v-color', 'space' => 'v-space', 'typography' => 'v-type', 'radius' => 'v-radius', 'shadow' => 'v-radius', 'breakpoint' => 'v-layout' );
		return array( isset( $g[ $group ] ) ? $g[ $group ] : 'v-other', '' );
	}

	/**
	 * A recipe's explanation: its first comment line, else its category.
	 *
	 * @param array $r Recipe.
	 * @return string
	 */
	private static function recipe_desc( $r ) {
		if ( preg_match( '#/\*\s*(.+?)\s*\*/#s', (string) $r['code'], $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', $m[1] ) ) . '.';
		}
		if ( $r['inline'] ) {
			return 'Value recipe (' . $r['c'] . '): expands to ' . self::short( str_replace( '{{cursor}}', '', (string) $r['code'] ), 80 );
		}
		return $r['c'] . ' snippet.';
	}

	/**
	 * Flat search over every item (for the AI ability).
	 *
	 * @param string $q     Query (words; all must match name or description).
	 * @param string $kind  '' or class|var|mixin|function|recipe|attr.
	 * @param int    $limit Max results.
	 * @return array
	 */
	public static function search( $q, $kind = '', $limit = 40 ) {
		$words = array_filter( preg_split( '/\s+/', strtolower( trim( (string) $q ) ) ) );
		$out   = array();
		foreach ( self::data() as $g ) {
			foreach ( $g['items'] as $it ) {
				if ( '' !== $kind && $it['k'] !== $kind ) {
					continue;
				}
				$hay = strtolower( $it['n'] . ' ' . $it['d'] . ' ' . $g['title'] );
				$ok  = true;
				foreach ( $words as $w ) {
					if ( false === strpos( $hay, ltrim( $w, '.' ) ) ) {
						$ok = false;
						break;
					}
				}
				if ( $ok ) {
					$score = 0;
					foreach ( $words as $w ) {
						$score += false !== strpos( strtolower( $it['n'] ), ltrim( $w, '.' ) ) ? 2 : 0;
					}
					$out[] = array( 'score' => $score, 'item' => $it + array( 'group' => $g['title'] ) );
				}
			}
		}
		usort( $out, function ( $a, $b ) { return $b['score'] - $a['score']; } );
		return array_column( array_slice( $out, 0, max( 1, (int) $limit ) ), 'item' );
	}
}
