<?php
/**
 * Utility classes: Grid placement, breakpoint grids, stacking and ratio auto grids, masonry and order classes.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Grid placement, breakpoint grids, stacking and ratio auto grids, masonry and order classes.
 */
class Util_Grid {

	/**
	 * Column ratios, the same as the grid-{a}-{b} classes in Scale_Engine::grid_css().
	 */
	const RATIOS = array( '1-2', '2-1', '1-3', '3-1', '2-3', '3-2' );

	/**
	 * The CSS, one rule per line (Framework::kept_rules() leaves out unused ones).
	 *
	 * @param array $s Framework settings.
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build( $s ) {
		$base = array(); // class name => declarations, emitted as ".name{...}".
		for ( $i = 1; $i <= 12; $i++ ) {
			$base[ "col-span-$i" ] = "grid-column-end:span $i";
		}
		$base['col-span-all'] = 'grid-column:1/-1';
		for ( $i = 1; $i <= 12; $i++ ) {
			$base[ "col-start-$i" ] = "grid-column-start:$i";
		}
		for ( $i = 1; $i <= 13; $i++ ) {
			$base[ "col-end-$i" ] = "grid-column-end:$i";
		}
		$base['col-end-last'] = 'grid-column-end:-1';
		for ( $i = 1; $i <= 6; $i++ ) {
			$base[ "row-span-$i" ] = "grid-row-end:span $i";
		}
		for ( $i = 1; $i <= 6; $i++ ) {
			$base[ "row-start-$i" ] = "grid-row-start:$i";
		}
		for ( $i = 1; $i <= 7; $i++ ) {
			$base[ "row-end-$i" ] = "grid-row-end:$i";
		}
		$placement = $base;
		$order     = array(
			'order-first' => 'order:-1',
			'order-last'  => 'order:999',
			'order-none'  => 'order:0',
		);
		$masonry   = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$masonry[ "masonry-$i" ] = "columns:$i";
		}

		$css     = array();
		$classes = array();
		foreach ( $placement as $name => $body ) {
			$css[]     = ".$name{" . $body . '}';
			$classes[] = $name;
		}
		// Alternate rows on three tracks: 1 + 2 tracks, then 2 + 1. A breakpoint grid sets the span to 1.
		$css[]     = '.grid-alternate{--grid-alternate-span:2;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--grid-gap)}';
		$css[]     = '.grid-alternate>:where(:nth-child(4n+2)){grid-column-end:span var(--grid-alternate-span)}';
		$css[]     = '.grid-alternate>:where(:nth-child(4n+3)){grid-column-end:span var(--grid-alternate-span)}';
		$classes[] = 'grid-alternate';
		// Ratio auto grids: a wrapping flex row, so the pair stacks as one when the columns would get
		// narrower than aggressiveness x their full-width size (the grid--auto-N formula).
		$se = Scale_Engine::settings();
		if ( ! empty( $se['enabled'] ) && ! empty( $se['auto_grid'] ) ) {
			$sels = array();
			foreach ( self::RATIOS as $r ) {
				$sels[]    = ".grid--auto-$r";
				$classes[] = "grid--auto-$r";
			}
			$css[] = implode( ',', $sels ) . '{display:flex;flex-direction:row;flex-wrap:wrap;gap:var(--grid-gap);inline-size:100%}';
			foreach ( self::RATIOS as $r ) {
				list( $a, $b ) = explode( '-', $r );
				$css[]         = ".grid--auto-$r>:where(:nth-child(odd)){flex:$a 1 " . self::ratio_basis( $a, $a + $b ) . '}';
				$css[]         = ".grid--auto-$r>:where(:nth-child(even)){flex:$b 1 " . self::ratio_basis( $b, $a + $b ) . '}';
			}
		}
		foreach ( $masonry as $name => $body ) {
			$css[]     = ".$name{display:block;" . $body . ';column-gap:var(--grid-gap)}';
			$classes[] = $name;
		}
		$sels = array();
		foreach ( array_keys( $masonry ) as $name ) {
			$sels[] = ".$name>*";
		}
		$css[] = implode( ',', $sels ) . '{break-inside:avoid;margin-block-end:var(--grid-gap)}';
		foreach ( $order as $name => $body ) {
			$css[]     = ".$name{" . $body . '}';
			$classes[] = $name;
		}

		if ( Util::responsive( $s ) ) {
			// Column count, ratio and stacking in one map, so the smallest breakpoint wins between them.
			$grids = array();
			for ( $i = 1; $i <= 12; $i++ ) {
				$grids[ "grid-$i" ] = "grid-template-columns:repeat($i,minmax(0,1fr));--grid-alternate-span:1";
			}
			foreach ( self::RATIOS as $r ) {
				$grids[ "grid-$r" ] = 'grid-template-columns:' . str_replace( '-', 'fr ', $r ) . 'fr;--grid-alternate-span:1';
			}
			$grids['grid-stack'] = 'grid-template-columns:minmax(0,1fr)';
			// Stacking also clears the children's column placement (a span would add columns) and a flex basis.
			$stack = array( 'grid-stack' => 'grid-column:auto;flex-basis:100%' );
			foreach ( array( array( $grids, '' ), array( $placement, '' ), array( $order, '' ), array( $masonry, '' ), array( $stack, '>*' ) ) as $set ) {
				$at      = Util::at_breakpoints( $set[0], $set[1] );
				$css     = array_merge( $css, $at['lines'] );
				$classes = array_merge( $classes, $at['classes'] );
			}
		}

		return array(
			'css'     => "\n/* --- TokenForge grid utilities --- */\n" . implode( "\n", $css ) . "\n",
			'classes' => array_values( array_unique( $classes ) ),
		);
	}

	/**
	 * Flex basis of one ratio column: never wider than the row, at least its share of the row
	 * (1px slack so rounding never wraps a pair), and aggressiveness x its share of --content-width.
	 *
	 * @param int $part  The column's part of the ratio.
	 * @param int $total Both parts.
	 * @return string
	 */
	private static function ratio_basis( $part, $total ) {
		$ideal = "calc((var(--content-width) - var(--grid-gap)) * $part / $total * var(--auto-grid-aggressiveness))";
		$floor = "calc((100% - var(--grid-gap)) * $part / $total - 1px)";
		return "min(100%,max($ideal,$floor))";
	}

	/**
	 * Cheatsheet rules: array( regex, group, description or callback ), checked before the built-in ones.
	 *
	 * @return array
	 */
	public static function cheatsheet() {
		$at   = '(?:-at-(xs|s|m|l|xl|xxl))?';
		$tail = function ( $m, $i ) {
			return empty( $m[ $i ] ) ? '.' : ' at the ' . $m[ $i ] . ' breakpoint and below.';
		};
		return array(
			array(
				"/^(col|row)-span-(\d+|all)$at\$/",
				'grid',
				function ( $m ) use ( $tail ) {
					$what = 'col' === $m[1] ? 'columns' : 'rows';
					return 'all' === $m[2] ? 'On a grid child: spans every column' . $tail( $m, 3 ) : 'On a grid child: spans ' . $m[2] . ' ' . $what . $tail( $m, 3 );
				},
			),
			array(
				"/^(col|row)-(start|end)-(\d+|last)$at\$/",
				'grid',
				function ( $m ) use ( $tail ) {
					$axis = 'col' === $m[1] ? 'column' : 'row';
					$line = 'last' === $m[3] ? "the last $axis line" : "$axis line " . $m[3];
					return 'On a grid child: ' . $m[2] . 's at ' . $line . $tail( $m, 4 );
				},
			),
			array(
				'/^grid-(\d+)-at-(xs|s|m|l|xl|xxl)$/',
				'grid',
				function ( $m ) use ( $tail ) {
					return 'With a grid class: ' . $m[1] . ' equal columns' . $tail( $m, 2 );
				},
			),
			array(
				'/^grid-(\d)-(\d)-at-(xs|s|m|l|xl|xxl)$/',
				'grid',
				function ( $m ) use ( $tail ) {
					return 'With a grid class: two columns in a ' . $m[1] . ':' . $m[2] . ' ratio' . $tail( $m, 3 );
				},
			),
			array(
				'/^grid-stack-at-(xs|s|m|l|xl|xxl)$/',
				'grid',
				function ( $m ) use ( $tail ) {
					return 'On a grid or flex grid: one column, children full width' . $tail( $m, 1 );
				},
			),
			array( '/^grid-alternate$/', 'grid', 'Two columns whose ratio alternates by row: 1:2, then 2:1. A breakpoint grid class (grid-1-at-s, grid-stack-at-s) turns it off.' ),
			array(
				'/^grid--auto-(\d)-(\d)$/',
				'grid',
				function ( $m ) {
					return 'Two columns in a ' . $m[1] . ':' . $m[2] . ' ratio that stack as one when space runs out (a wrapping flex row).';
				},
			),
			array(
				"/^masonry-(\d)$at\$/",
				'grid',
				function ( $m ) use ( $tail ) {
					return 'Masonry: children flow top to bottom in ' . $m[1] . ' column' . ( '1' === $m[1] ? '' : 's' ) . $tail( $m, 2 );
				},
			),
			array(
				"/^order-(first|last|none)$at\$/",
				'grid',
				function ( $m ) use ( $tail ) {
					$what = array(
						'first' => 'Moves this flex or grid child first',
						'last'  => 'Moves this flex or grid child last',
						'none'  => 'Back to source order for this flex or grid child',
					);
					return $what[ $m[1] ] . $tail( $m, 2 );
				},
			),
		);
	}
}
