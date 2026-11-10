<?php
/**
 * Utility classes: Text weight, style, case, decoration and wrapping, link, focus and marker colours, selection, list, border side, divider and edge fade classes.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Text weight, style, case, decoration and wrapping, link, focus and marker colours, selection, list, border side, divider and edge fade classes.
 */
class Util_Text {

	/**
	 * Fade edges: class suffix => gradient. One side fades from that edge; block and inline fade both ends.
	 */
	const FADES = array(
		'top'    => 'to bottom,transparent,#000 var(--fade-amount, 25%)',
		'bottom' => 'to top,transparent,#000 var(--fade-amount, 25%)',
		'left'   => 'to right,transparent,#000 var(--fade-amount, 25%)',
		'right'  => 'to left,transparent,#000 var(--fade-amount, 25%)',
		'block'  => 'to bottom,transparent,#000 var(--fade-amount, 25%),#000 calc(100% - var(--fade-amount, 25%)),transparent',
		'inline' => 'to right,transparent,#000 var(--fade-amount, 25%),#000 calc(100% - var(--fade-amount, 25%)),transparent',
	);

	/**
	 * Border sides: class suffix => border property.
	 */
	const SIDES = array(
		'top'    => 'border-top',
		'bottom' => 'border-bottom',
		'left'   => 'border-left',
		'right'  => 'border-right',
		'block'  => 'border-block',
		'inline' => 'border-inline',
	);

	/**
	 * The CSS, one rule per line (Framework::kept_rules() leaves out unused ones).
	 *
	 * @param array $s Framework settings.
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build( $s ) {
		$rules = array();
		for ( $w = 100; $w <= 900; $w += 100 ) {
			$rules[ "font-$w" ] = "font-weight:$w";
		}
		$rules += array(
			'bold'              => 'font-weight:700',
			'italic'            => 'font-style:italic',
			'not-italic'        => 'font-style:normal',
			'uppercase'         => 'text-transform:uppercase',
			'lowercase'         => 'text-transform:lowercase',
			'capitalize'        => 'text-transform:capitalize',
			'normal-case'       => 'text-transform:none',
			'underline'         => 'text-decoration-line:underline',
			'underline--wavy'   => 'text-decoration-line:underline;text-decoration-style:wavy',
			'underline--dotted' => 'text-decoration-line:underline;text-decoration-style:dotted',
			'underline--dashed' => 'text-decoration-line:underline;text-decoration-style:dashed',
			'underline--double' => 'text-decoration-line:underline;text-decoration-style:double',
			'overline'          => 'text-decoration-line:overline',
			'line-through'      => 'text-decoration-line:line-through',
			'no-underline'      => 'text-decoration-line:none',
			'text-justify'      => 'text-align:justify',
			'text-balance'      => 'text-wrap:balance',
			'text-pretty'       => 'text-wrap:pretty',
			'text-wrap'         => 'text-wrap:wrap',
		);
		$css    = array();
		foreach ( $rules as $name => $body ) {
			$css[] = ".$name{" . $body . '}';
		}
		$classes = array_keys( $rules );
		$css[]   = '.list-none,.list-none>ul,.list-none>ol{list-style:none;padding-inline-start:0;margin-block:0}';

		// Focus ring on the parent while a child has keyboard focus (the child's own ring is hidden).
		$css[] = '.focus-parent:focus-within,.focus-parent--outline:focus-within{outline:var(--focus-width, 2px) solid var(--focus-color);outline-offset:var(--focus-offset, 2px)}';
		$css[] = '.focus-parent--shadow:focus-within{box-shadow:0 0 0 var(--focus-width, 2px) var(--focus-color)}';
		$css[] = '.focus-parent:focus-within:not(:has(:focus-visible)),.focus-parent--outline:focus-within:not(:has(:focus-visible)){outline:none}';
		$css[] = '.focus-parent--shadow:focus-within:not(:has(:focus-visible)){box-shadow:none}';
		$css[] = '.focus-parent a:focus,.focus-parent button:focus,.focus-parent--outline a:focus,.focus-parent--outline button:focus,.focus-parent--shadow a:focus,.focus-parent--shadow button:focus{outline:none!important;box-shadow:none!important}'; // links and buttons only: form fields keep their own ring.
		$css[] = '.selection--alt ::selection,.selection--alt::selection{background-color:var(--selection-bg-alt, var(--primary-ultra-dark, #111));color:var(--selection-text-alt, var(--primary-ultra-light, #fff))}';

		foreach ( self::FADES as $side => $g ) {
			$css[] = ".fade-$side{-webkit-mask-image:linear-gradient($g);mask-image:linear-gradient($g)}";
		}

		// Dividers between children, above or below one element (variables from the border settings).
		$line  = 'content:"";position:absolute;inset-inline-start:0;inline-size:var(--divider-inline-size, 100%);border-block-end:var(--divider)';
		$space = 'position:relative;overflow:visible;margin-block-%s:calc(var(--divider-gap) * 2)';
		$css[] = '.divider--all{row-gap:0!important}';
		$css[] = '.divider--all>:not(:last-child){' . sprintf( $space, 'end' ) . '}';
		$css[] = '.divider--all>:not(:last-child)::after{' . $line . ';inset-block-end:calc(-1 * var(--divider-gap) - var(--divider-size, 1px))}';
		$css[] = ':has(>.divider--top){gap:0!important}'; // one class per line, so the purge can drop it.
		$css[] = ':has(>.divider--bottom){gap:0!important}';
		$css[] = '.divider--top:not(:first-child){' . sprintf( $space, 'start' ) . '}';
		$css[] = '.divider--top:not(:first-child)::before{' . $line . ';inset-block-start:calc(-1 * var(--divider-gap) - var(--divider-size, 1px))}';
		$css[] = '.divider--bottom:not(:last-child){' . sprintf( $space, 'end' ) . '}';
		$css[] = '.divider--bottom:not(:last-child)::after{' . $line . ';inset-block-end:calc(-1 * var(--divider-gap) - var(--divider-size, 1px))}';

		array_push( $classes, 'list-none', 'focus-parent', 'focus-parent--outline', 'focus-parent--shadow', 'selection--alt', 'divider--all', 'divider--top', 'divider--bottom' );
		foreach ( array_keys( self::FADES ) as $side ) {
			$classes[] = "fade-$side";
		}

		// The other border sides and a light or dark shade per side; --border* come from the border settings.
		$extras = Extras::settings();
		if ( ! empty( $extras['enable_border'] ) ) {
			foreach ( self::SIDES as $side => $prop ) {
				if ( 'top' !== $side && 'bottom' !== $side ) {
					$css[]     = ".border-$side{" . $prop . ':var(--border)}';
					$classes[] = "border-$side";
				}
				$css[] = ".border-$side--light{" . $prop . ':var(--border-light)}';
				$css[] = ".border-$side--dark{" . $prop . ':var(--border-dark)}';
				array_push( $classes, "border-$side--light", "border-$side--dark" );
			}
		}

		// Colour classes per colour token, like Framework::build() does for text-/bg-/border-.
		if ( ! empty( $s['utilities_color'] ) && ! empty( $s['utilities_color_more'] ) ) {
			$tokens = Settings::get_instance()->all_variables();
			$vars   = array();
			foreach ( $tokens as $t ) {
				if ( ! empty( $t['enabled'] ) ) {
					$vars[ $t['name'] ] = $t['value'];
				}
			}
			foreach ( $tokens as $t ) {
				// Transparent steps make no sense as a link, focus or bullet colour, and would add many lines.
				if ( empty( $t['enabled'] ) || preg_match( '/-trans-\d+$/', $t['name'] ) || ! Framework::is_color_value( $t, $vars ) ) {
					continue;
				}
				$n     = $t['name'];
				$css[] = ".link-$n{--link-color:var(--$n)}";
				$css[] = ".link-hover-$n{--link-color-hover:var(--$n)}";
				$css[] = ".focus-$n{--focus-color:var(--$n)}";
				$css[] = ".marker-$n ::marker,.marker-$n::marker{color:var(--$n)}";
				$css[] = ".col-rule-$n{column-rule-color:var(--$n)}";
				array_push( $classes, "link-$n", "link-hover-$n", "focus-$n", "marker-$n", "col-rule-$n" );
			}
		}

		if ( Util::responsive( $s ) ) {
			$at      = Util::at_breakpoints(
				array(
					'text-center'  => 'text-align:center',
					'text-left'    => 'text-align:left',
					'text-right'   => 'text-align:right',
					'text-justify' => 'text-align:justify',
				)
			);
			$css     = array_merge( $css, $at['lines'] );
			$classes = array_merge( $classes, $at['classes'] );
		}

		return array(
			'css'     => implode( "\n", $css ),
			'classes' => $classes,
		);
	}

	/**
	 * Cheatsheet rules: array( regex, group, description or callback ), checked before the built-in ones.
	 *
	 * @return array
	 */
	public static function cheatsheet() {
		$bp = '(-at-(?:xxl|xl|l|m|s|xs))?';
		return array(
			array(
				'/^font-([1-9])00$/',
				'typography',
				function ( $m ) {
					return 'Font weight ' . $m[1] . '00.'; },
			),
			array( '/^(bold|italic|not-italic|uppercase|lowercase|capitalize|normal-case|overline|line-through|no-underline|text-balance|text-pretty|text-wrap)$/', 'typography', null ),
			array(
				'/^underline(--(wavy|dotted|dashed|double))?$/',
				'typography',
				function ( $m ) {
					return empty( $m[2] ) ? 'Underlined text.' : 'A ' . $m[2] . ' underline.'; },
			),
			array(
				"/^text-(center|left|right|justify)$bp\$/",
				'typography',
				function ( $m ) {
					return 'Text align ' . $m[1] . ( empty( $m[2] ) ? '' : ' at the ' . substr( $m[2], 4 ) . ' breakpoint and below' ) . '.'; },
			),
			array( '/^list-none$/', 'utilities', 'A list without bullets or indent (on the list, or on a wrapper of one). Add role="list" to the list: Safari stops announcing a list with no bullets.' ),
			array( '/^focus-parent(--outline)?$/', 'utilities', 'The focus ring shows on this element (as an outline) while a link or button inside it has keyboard focus, for example a card around one link.' ),
			array( '/^focus-parent--shadow$/', 'utilities', 'Like focus-parent, with the ring drawn as a box shadow (it follows the corner radius).' ),
			array( '/^selection--alt$/', 'utilities', 'Selected text inside gets the alternative colours: var(--selection-bg-alt) and var(--selection-text-alt), by default the primary ultra dark and ultra light shades.' ),
			array(
				'/^fade-(top|bottom|left|right|block|inline)$/',
				'utilities',
				function ( $m ) {
					return 'Fades the ' . ( in_array( $m[1], array( 'block', 'inline' ), true ) ? ( 'block' === $m[1] ? 'top and bottom edges' : 'left and right edges' ) : $m[1] . ' edge' ) . ' out with a mask. Set --fade-amount (default 25%) to change the fade length.'; },
			),
			array( '/^divider--all$/', 'borders', 'A divider line between every child (from the divider variables); the gap between rows becomes the divider spacing.' ),
			array(
				'/^divider--(top|bottom)$/',
				'borders',
				function ( $m ) {
					return 'A divider line ' . ( 'top' === $m[1] ? 'above this element (not on the first one)' : 'below this element (not on the last one)' ) . '; the parent gap becomes the divider spacing.'; },
			),
			array(
				'/^border-(top|bottom|left|right|block|inline)(--(light|dark))?$/',
				'borders',
				function ( $m ) {
					$side = array(
						'block'  => 'top and bottom',
						'inline' => 'left and right',
					);
					$side = isset( $side[ $m[1] ] ) ? $side[ $m[1] ] : $m[1];
					return empty( $m[3] ) ? 'Border on the ' . $side . ' (var(--border)).' : 'Border on the ' . $side . ' for ' . ( 'light' === $m[3] ? 'dark' : 'light' ) . ' backgrounds (var(--border-' . $m[3] . ')).'; },
			),
			array(
				'/^link-hover-(.+)$/',
				'colors',
				function ( $m ) {
					return 'Link hover colour var(--' . $m[1] . ') for links on or inside this element.'; },
			),
			array(
				'/^link-(.+)$/',
				'colors',
				function ( $m ) {
					return 'Link colour var(--' . $m[1] . ') for links on or inside this element (the hover colour stays; add link-hover-*).'; },
			),
			array(
				'/^focus-(.+)$/',
				'colors',
				function ( $m ) {
					return 'Focus ring colour var(--' . $m[1] . ') on or inside this element.'; },
			),
			array(
				'/^marker-(.+)$/',
				'colors',
				function ( $m ) {
					return 'List bullet and number colour var(--' . $m[1] . ').'; },
			),
			array(
				'/^col-rule-([a-z][a-z0-9-]*)$/',
				'colors',
				function ( $m ) {
					return 'With col-rule: line colour var(--' . $m[1] . ').'; },
			),
		);
	}
}
