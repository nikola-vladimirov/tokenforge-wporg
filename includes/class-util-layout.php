<?php
/**
 * Utility classes: Flex and alignment, centring, height, containers, display and stacking, flip, sticky, aspect ratio, object position, line clamp and CSS column classes.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Flex and alignment, centring, height, containers, display and stacking, flip, sticky, aspect ratio, object position, line clamp and CSS column classes.
 */
class Util_Layout {

	/**
	 * Static classes whose base rule Framework::build() already writes: only their -at- variants are built here.
	 */
	const EXISTING = array(
		'flex'            => 'display:flex',
		'inline-flex'     => 'display:inline-flex',
		'grid'            => 'display:grid',
		'block'           => 'display:block',
		'inline-block'    => 'display:inline-block',
		'hidden'          => 'display:none',
		'flex-col'        => 'flex-direction:column',
		'flex-row'        => 'flex-direction:row',
		'flex-wrap'       => 'flex-wrap:wrap',
		'flex-nowrap'     => 'flex-wrap:nowrap',
		'items-center'    => 'align-items:center',
		'items-start'     => 'align-items:flex-start',
		'items-end'       => 'align-items:flex-end',
		'justify-center'  => 'justify-content:center',
		'justify-between' => 'justify-content:space-between',
		'justify-start'   => 'justify-content:flex-start',
		'justify-end'     => 'justify-content:flex-end',
	);

	/** Aspect ratio classes: name suffix => ratio (the data-tf-ratio values plus 2/1). */
	const RATIOS = array(
		'1-1'  => '1/1',
		'4-3'  => '4/3',
		'3-4'  => '3/4',
		'3-2'  => '3/2',
		'2-3'  => '2/3',
		'16-9' => '16/9',
		'9-16' => '9/16',
		'21-9' => '21/9',
		'2-1'  => '2/1',
	);

	/**
	 * The CSS, one rule per line (Framework::kept_rules() leaves out unused ones).
	 *
	 * @param array $s Framework settings.
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build( $s ) {
		$sets    = self::sets();
		$lines   = array();
		$classes = array();
		$at      = self::EXISTING;
		foreach ( $sets as $set ) {
			foreach ( $set[2] as $name => $body ) {
				$lines[]   = ".$name{" . $body . '}';
				$classes[] = $name;
			}
			if ( $set[1] ) {
				$at += $set[2];
			}
		}

		// Newspaper columns (with the Defaults "CSS columns" switch): 2 to 4 exist already (Defaults).
		// WooCommerce puts columns-N on its product list, so skip that; :where() keeps the base rule
		// at one class, so a columns-N-at-{bp} still overrides it.
		$cols = self::columns_on();
		if ( $cols ) {
			foreach ( array( 1, 5, 6 ) as $n ) {
				$lines[]   = ".columns-$n:where(:not(.products)){column-count:$n;column-gap:var(--grid-gap)}";
				$classes[] = "columns-$n";
			}
			for ( $n = 1; $n <= 6; $n++ ) {
				$at[ "columns-$n" ] = "column-count:$n;column-gap:var(--grid-gap)";
			}
		}

		// Images and videos with a ratio class crop to fill; zero specificity so object-contain still wins.
		$lines[] = ':where(img,video):where(.aspect-' . implode( ',.aspect-', array_keys( self::RATIOS ) ) . '){object-fit:cover;max-inline-size:100%}';

		// Line clamp: the shared part once, the count per class.
		$lines[] = '.line-clamp-' . implode( ',.line-clamp-', range( 1, 6 ) ) . '{display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden}';
		for ( $n = 1; $n <= 6; $n++ ) {
			$lines[]   = ".line-clamp-$n{-webkit-line-clamp:$n;line-clamp:$n}";
			$classes[] = "line-clamp-$n";
		}
		$lines[]   = '.line-clamp-none{display:block;-webkit-line-clamp:none;line-clamp:none;overflow:visible}';
		$classes[] = 'line-clamp-none';

		// Modifiers after the base rules.
		if ( $cols ) {
			$lines[]   = '.col-rule{column-rule:var(--col-rule-width-m, 1px) solid var(--border-color, currentColor)}';
			$classes[] = 'col-rule';
			foreach ( array( 'dashed', 'dotted', 'double' ) as $style ) {
				$lines[]   = ".col-rule--$style{column-rule-style:$style}";
				$classes[] = "col-rule--$style";
			}
		}
		foreach ( array(
			's' => '.5px',
			'm' => '1px',
			'l' => '3px',
		) as $sz => $px ) {
			if ( $cols ) {
				$lines[]   = ".col-rule--$sz{column-rule-width:var(--col-rule-width-$sz, $px)}";
				$classes[] = "col-rule--$sz";
			}
			$lines[]   = ".sticky-top--$sz{position:sticky;inset-block-start:calc(var(--sticky-offset, 0px) + var(--space-$sz))}";
			$classes[] = "sticky-top--$sz";
		}

		if ( Util::responsive( $s ) ) {
			$v       = Util::at_breakpoints( $at );
			$lines   = array_merge( $lines, $v['lines'] );
			$classes = array_merge( $classes, $v['classes'] );
		}
		return array(
			'css'     => implode( "\n", $lines ),
			'classes' => $classes,
		);
	}

	/**
	 * The plain single-rule classes: array( cheatsheet group, has -at- variants, name => declarations ).
	 *
	 * @return array
	 */
	private static function sets() {
		$steps = function ( $prefix, $prop, $unit, $div ) {
			$out = array();
			for ( $i = 10; $i <= 90; $i += 10 ) {
				$out[ "$prefix-$i" ] = "$prop:" . ( $i / $div ) . $unit;
			}
			return $out;
		};
		$align = array(
			'flex-col-reverse' => 'flex-direction:column-reverse',
			'flex-row-reverse' => 'flex-direction:row-reverse',
			'flex-grow'        => 'flex-grow:1',
			'flex-shrink-0'    => 'flex-shrink:0',
			'items-baseline'   => 'align-items:baseline',
			'items-stretch'    => 'align-items:stretch',
			'justify-around'   => 'justify-content:space-around',
			'justify-evenly'   => 'justify-content:space-evenly',
			'content-between'  => 'align-content:space-between',
		);
		foreach ( array( 'start', 'center', 'end', 'stretch' ) as $v ) {
			$align[ "content-$v" ]       = "align-content:$v";
			$align[ "justify-items-$v" ] = "justify-items:$v";
			$align[ "self-$v" ]          = "align-self:$v";
			$align[ "justify-self-$v" ]  = "justify-self:$v";
		}
		$align['self-baseline'] = 'align-self:baseline';

		$height = array(
			'height-full'       => 'height:100%',
			'height-screen'     => 'height:100vh;height:100dvh',
			'max-height-full'   => 'max-height:100%',
			'max-height-screen' => 'max-height:100vh;max-height:100dvh',
		) + $steps( 'height', 'height', '%', 1 ) + $steps( 'max-height', 'max-height', '%', 1 );

		$display = array();
		foreach ( array( 'contents', 'inline', 'list-item', 'flow-root', 'table' ) as $v ) {
			$display[ "display-$v" ] = "display:$v"; // prefixed: bare names such as table or inline are common in existing markup.
		}

		$center = array(
			'center-all'    => 'display:grid;align-content:center;justify-items:center',
			'center-x'      => 'display:grid;justify-items:center',
			'center-y'      => 'display:grid;align-content:center',
			'center-top'    => 'display:grid;align-content:start;justify-items:center',
			'center-bottom' => 'display:grid;align-content:end;justify-items:center',
			'center-left'   => 'display:grid;align-content:center;justify-items:start',
			'center-right'  => 'display:grid;align-content:center;justify-items:end',
		);

		$other = array(
			'query-container'      => 'container-type:inline-size',
			'query-container-size' => 'container-type:size',
			'isolate'              => 'isolation:isolate',
			'visible'              => 'visibility:visible',
			'invisible'            => 'visibility:hidden',
			'opacity-0'            => 'opacity:0',
		) + $steps( 'opacity', 'opacity', '', 100 ) + array(
			'opacity-100' => 'opacity:1',
			'z-0'         => 'z-index:0',
		) + $steps( 'z', 'z-index', '', 1 ) + array(
			'z-top'    => 'z-index:9999',
			'z-bottom' => 'z-index:-1',
			'flip-x'   => 'scale:-1 1',
			'flip-y'   => 'scale:1 -1',
			'flip-xy'  => 'scale:-1 -1',
		);
		foreach ( self::RATIOS as $k => $r ) {
			$other[ "aspect-$k" ] = "aspect-ratio:$r";
		}
		foreach ( array( 'top', 'bottom', 'left', 'right', 'center', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ) as $p ) {
			$other[ "object-$p" ] = 'object-position:' . str_replace( '-', ' ', $p );
		}
		if ( self::columns_on() ) {
			foreach ( array( 's', 'm', 'l' ) as $sz ) {
				$other[ "col-width-$sz" ] = "column-width:var(--col-width-$sz);column-gap:var(--grid-gap)";
			}
		}

		return array(
			array( 'grid', true, $align ),
			array( 'grid', false, $center ),
			array( 'utilities', true, $display + $height ),
			array( 'utilities', false, $other ),
		);
	}

	/**
	 * Is the Defaults "CSS columns" switch on (it also writes the --col-width and --col-rule-width variables)?
	 *
	 * @return bool
	 */
	private static function columns_on() {
		$d = Defaults::settings();
		return ! empty( $d['columns'] );
	}

	/**
	 * Cheatsheet rules: array( regex, group, description or callback ), checked before the built-in ones.
	 *
	 * @return array
	 */
	public static function cheatsheet() {
		$text = array(
			'center-all'           => 'Centres its children both ways (a grid; each child keeps its own width).',
			'center-x'             => 'Centres its children left to right (a grid).',
			'center-y'             => 'Centres its children top to bottom when it is taller than they are (a grid).',
			'center-top'           => 'Children centred left to right, at the top.',
			'center-bottom'        => 'Children centred left to right, at the bottom.',
			'center-left'          => 'Children centred top to bottom, on the left.',
			'center-right'         => 'Children centred top to bottom, on the right.',
			'height-screen'        => 'Full screen height (100dvh, 100vh in older browsers).',
			'max-height-screen'    => 'At most the screen height (100dvh, 100vh in older browsers).',
			'query-container'      => 'Makes this element a container for container queries, by its width.',
			'query-container-size' => 'A container for container queries by width and height (it needs a set height).',
			'isolate'              => 'Starts a new stacking layer, so z-index inside it stays inside it.',
			'invisible'            => 'Hidden but still takes its space (visibility: hidden).',
			'flip-x'               => 'Mirrors the element left to right.',
			'flip-y'               => 'Mirrors the element top to bottom.',
			'flip-xy'              => 'Mirrors the element both ways.',
			'flex-grow'            => 'Grows to take the free space in a flex row or column.',
			'flex-shrink-0'        => 'Never shrinks in a flex row or column.',
			'z-top'                => 'On top of everything (z-index 9999).',
			'z-bottom'             => 'Behind its siblings (z-index -1).',
			'line-clamp-none'      => 'Removes a line clamp.',
			'col-rule'             => 'A line between newspaper columns (var(--col-rule-width-m), the border colour).',
		);
		$css  = self::EXISTING;
		$grid = array();
		foreach ( self::sets() as $set ) {
			$css += $set[2];
			if ( 'grid' === $set[0] ) {
				$grid += $set[2];
			}
		}
		$say  = function ( $name ) use ( $text, $css ) {
			if ( isset( $text[ $name ] ) ) {
				return $text[ $name ];
			}
			return isset( $css[ $name ] ) ? 'Sets ' . str_replace( array( ':', ';' ), array( ': ', '; ' ), $css[ $name ] ) . '.' : '';
		};
		$alt  = function ( $names ) {
			return implode( '|', array_map( 'preg_quote', array_keys( $names ) ) );
		};
		$bp   = '(xxl|xl|l|m|s|xs)';
		$base = $css;
		for ( $n = 1; $n <= 6; $n++ ) {
			$base[ "columns-$n" ] = "column-count:$n";
		}
		return array(
			array(
				'/^(' . $alt( $base ) . ")-at-$bp\$/",
				'grid',
				function ( $m ) use ( $say ) {
					$d = 0 === strpos( $m[1], 'columns-' ) ? 'Text flows in ' . substr( $m[1], 8 ) . ' newspaper-style columns.' : $say( $m[1] );
					return rtrim( $d, '.' ) . ', at the ' . $m[2] . ' breakpoint and below.';
				},
			),
			array(
				'/^(' . $alt( $grid ) . ')$/',
				'grid',
				function ( $m ) use ( $say ) {
					return $say( $m[1] );
				},
			),
			array(
				'/^aspect-(\d+)-(\d+)$/',
				'utilities',
				function ( $m ) {
					return 'A fixed ' . $m[1] . ':' . $m[2] . ' shape; an image or video in it crops to fill.';
				},
			),
			array(
				'/^object-(top|bottom|left|right|center|top-left|top-right|bottom-left|bottom-right)$/',
				'utilities',
				function ( $m ) {
					return 'Which part of a cropped image or video stays in view: ' . str_replace( '-', ' ', $m[1] ) . '.';
				},
			),
			array(
				'/^col-width-(s|m|l)$/',
				'grid',
				function ( $m ) {
					return 'Newspaper columns at least var(--col-width-' . $m[1] . ') wide, as many as fit.';
				},
			),
			array(
				'/^col-rule--(dashed|dotted|double|s|m|l)$/',
				'grid',
				function ( $m ) {
					return 1 === strlen( $m[1] ) ? 'With col-rule: line width var(--col-rule-width-' . $m[1] . ').' : 'With col-rule: a ' . $m[1] . ' line.';
				},
			),
			array(
				'/^sticky-top--(s|m|l)$/',
				'utilities',
				function ( $m ) {
					return 'Sticks while scrolling, var(--space-' . $m[1] . ') below the sticky offset (below the header when that option is on).';
				},
			),
			array(
				'/^line-clamp-([1-6])$/',
				'utilities',
				function ( $m ) {
					return 'Cuts the text after ' . $m[1] . ' line' . ( '1' === $m[1] ? '' : 's' ) . ' with an ellipsis.';
				},
			),
			array(
				'/^(' . $alt( array_diff_key( $css, self::EXISTING ) ) . '|line-clamp-none|col-rule)$/',
				'utilities',
				function ( $m ) use ( $say ) {
					return $say( $m[1] );
				},
			),
		);
	}
}
