<?php
/**
 * Shared helpers for the utility class families (Util_Grid, Util_Layout, Util_Text).
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Breakpoint variants and the family list.
 */
class Util {

	/**
	 * The utility families after the grids: class => the framework setting that switches it on.
	 * Each has build( $s ) returning [ 'css' => one rule per line, 'classes' => names ] and
	 * cheatsheet() returning describe_class() rules.
	 *
	 * @return array
	 */
	public static function families() {
		return array(
			'TokenForge\\Util_Grid'   => 'utilities_layout',
			'TokenForge\\Util_Layout' => 'utilities_layout',
			'TokenForge\\Util_Text'   => 'utilities_typography',
		);
	}

	/**
	 * Breakpoint variants "{class}-at-{bp}": the rule applies at and below the breakpoint
	 * (max-width). Largest breakpoint first, so a smaller one wins on a small screen.
	 * One rule per line, so Framework::kept_rules() can leave out unused ones.
	 *
	 * @param array  $map    class name => declarations ("grid-column:span 2").
	 * @param string $suffix Selector after the class, such as ">*".
	 * @return array [ 'lines' => string[], 'classes' => string[] ]
	 */
	public static function at_breakpoints( $map, $suffix = '' ) {
		$lines   = array();
		$classes = array();
		$bps     = Scss::breakpoints();
		arsort( $bps );
		foreach ( $bps as $bp => $px ) {
			if ( $px <= 0 ) {
				continue;
			}
			foreach ( $map as $name => $body ) {
				$lines[]   = "@media (max-width:{$px}px){.$name-at-$bp$suffix{" . $body . '}}';
				$classes[] = "$name-at-$bp";
			}
		}
		return array(
			'lines'   => $lines,
			'classes' => $classes,
		);
	}

	/**
	 * Are breakpoint variants on?
	 *
	 * @param array $s Framework settings.
	 * @return bool
	 */
	public static function responsive( $s ) {
		return ! empty( $s['utilities_responsive'] );
	}
}
