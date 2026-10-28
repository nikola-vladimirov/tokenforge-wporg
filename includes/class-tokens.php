<?php
/**
 * Token model helpers.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers for normalising and classifying tokens.
 */
class Tokens {

	const GROUPS = array( 'color', 'space', 'typography', 'breakpoint', 'radius', 'shadow', 'other' );

	/**
	 * Derive a group from a variable name.
	 *
	 * @param string $name Variable name without the leading `--`.
	 * @return string
	 */
	public static function derive_group( $name ) {
		$name = strtolower( $name );
		// Line heights and transitions, whatever they start with (base-text-lh, link-transition).
		if ( preg_match( '/-(lh|line-height)$/', $name ) ) {
			return 'typography';
		}
		if ( preg_match( '/-(transition|duration|timing|delay)$/', $name ) ) {
			return 'other';
		}
		if ( preg_match( '/^(primary|secondary|tertiary|accent|base|action|neutral|shade|success|danger|warning|info|white|black|link)(-|$)/', $name )
			|| preg_match( '/-(hex|hsl|rgb)$/', $name ) ) {
			return 'color';
		}
		// Size scales end in -s / -l too (space-s, text-l), so check them BEFORE
		// treating a single-letter -h/-s/-l/-r/-g/-b suffix as a colour channel.
		if ( preg_match( '/^(space|gap|section|gutter|content-|width|container|grid)/', $name ) ) {
			return 'space';
		}
		if ( preg_match( '/^(text|font|h[1-6]|lh|line-height|letter|weight|heading)/', $name ) ) {
			return 'typography';
		}
		if ( preg_match( '/^(breakpoint|vp)/', $name ) ) {
			return 'breakpoint';
		}
		if ( preg_match( '/(radius|rounded)/', $name ) ) {
			return 'radius';
		}
		if ( preg_match( '/(shadow|elevation)/', $name ) ) {
			return 'shadow';
		}
		// Last resort: a single-letter channel suffix on a custom colour family
		// (brand-h, brand-s …), but not on sizes like icon-size-l / border-s.
		if ( preg_match( '/-(h|s|l|r|g|b)$/', $name ) && ! preg_match( '/(size|icon|border|width|height)/', $name ) ) {
			return 'color';
		}
		return 'other';
	}

	/**
	 * Tokens saved before 0.18.0 could be filed under "color" just because
	 * their name ended in -s / -l (space-s, text-l …). Re-derive the group for
	 * exactly those: stored as color, only matched the old channel-suffix rule,
	 * and now classify as something else.
	 *
	 * @param array $tokens Normalised tokens.
	 * @return array [ tokens, changed count ]
	 */
	public static function repair_groups( $tokens ) {
		$changed = 0;
		foreach ( $tokens as $i => $t ) {
			if ( 'color' !== $t['group'] || ! preg_match( '/-(h|s|l|r|g|b)$/', $t['name'] ) ) {
				continue;
			}
			$g = self::derive_group( $t['name'] );
			if ( 'color' !== $g ) {
				$tokens[ $i ]['group'] = $g;
				$changed++;
			}
		}
		return array( $tokens, $changed );
	}

	/**
	 * Sanitise a token name to a valid CSS custom-property identifier (no `--`).
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function sanitize_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = ltrim( $name, '-' );
		$name = preg_replace( '/[^a-z0-9\-]/', '-', $name );
		$name = preg_replace( '/-+/', '-', $name );
		return trim( $name, '-' );
	}

	/**
	 * Sanitise a token value. Keeps CSS-safe characters; strips braces/semicolons.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_value( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( array( '{', '}', ';', '<', '>' ), '', $value );
		return $value;
	}

	/**
	 * Do two values say the same? Spacing and rounding don't count (0.141 and
	 * 0.142, 259.95 and 260), nor two ways of writing the same thing:
	 * calc(A vw + B rem) is B rem + A vw, and a grey (oklch L 0 H) has no hue.
	 * The Tokens screen uses the same rules.
	 *
	 * @param string $a Value.
	 * @param string $b Value.
	 * @return bool
	 */
	public static function same_value( $a, $b ) {
		$a = self::value_key( $a );
		$b = self::value_key( $b );
		if ( $a === $b ) {
			return true;
		}
		$num = '/\d*\.?\d+/';
		if ( preg_replace( $num, '#', $a ) !== preg_replace( $num, '#', $b ) ) {
			return false;
		}
		preg_match_all( $num, $a, $na );
		preg_match_all( $num, $b, $nb );
		if ( count( $na[0] ) !== count( $nb[0] ) ) {
			return false;
		}
		foreach ( $na[0] as $i => $x ) {
			$p = (float) $x;
			$q = (float) $nb[0][ $i ];
			$d = abs( $p - $q );
			if ( $d > 0.0015 && $d > max( $p, $q ) * 0.002 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A value written one way (see same_value()).
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function value_key( $v ) {
		$v = trim( (string) preg_replace( '/\s+/', ' ', (string) $v ) );
		$v = (string) preg_replace( '/calc\((-?[\d.]+)vw \+ (-?[\d.]+)rem\)/', '$2rem + $1vw', $v );
		$v = (string) preg_replace( '/oklch\(([\d.]+) 0 [\d.]+/', 'oklch($1 0 0', $v );
		// Nothing but zeros (0px, 0rem, clamp(0rem, calc(0vw + 0rem), 0rem)) is 0.
		$z = (string) preg_replace( '/(?<![\d.])0(?:\.0+)?(?:px|rem|em|vw|vh|%|ch|ex)?(?![\d.a-z%])/', '0', $v );
		return preg_match( '/^(?:clamp|calc|min|max|[0\s,+*\/()-])+$/', $z ) ? '0' : $v;
	}

	/**
	 * A value with every var(--x) replaced by x's value (and x's by theirs), so
	 * two ways of getting the same result compare equal: 1px and
	 * var(--border-size) when --border-size is 1px. A variable that isn't in
	 * $vars keeps its fallback, or stays as it is.
	 *
	 * @param string $v    Value.
	 * @param array  $vars name => value.
	 * @return string
	 */
	public static function resolve( $v, $vars ) {
		$v = (string) $v;
		for ( $i = 0; $i < 12 && false !== strpos( $v, 'var(' ); $i++ ) {
			$next = (string) preg_replace_callback(
				'/var\(\s*--([a-z0-9-]+)\s*(?:,\s*([^()]*))?\)/i',
				function ( $m ) use ( $vars ) {
					$n = strtolower( $m[1] );
					if ( isset( $vars[ $n ] ) ) {
						return false !== strpos( (string) $vars[ $n ], 'var(--' . $n ) ? $m[0] : (string) $vars[ $n ];
					}
					return isset( $m[2] ) && '' !== trim( $m[2] ) ? trim( $m[2] ) : "\x01" . $n . "\x02";
				},
				$v
			);
			if ( $next === $v ) {
				break;
			}
			$v = $next;
		}
		return (string) preg_replace( '/\x01([a-z0-9-]+)\x02/', 'var(--$1)', $v );
	}

	/**
	 * A category name: one of the built-in groups or your own (lowercase letters,
	 * digits and hyphens, up to 32 characters). '' when nothing usable is left.
	 *
	 * @param mixed $group Raw category.
	 * @return string
	 */
	public static function sanitize_group( $group ) {
		$group = strtolower( trim( (string) $group ) );
		if ( in_array( $group, self::GROUPS, true ) ) {
			return $group;
		}
		$group = preg_replace( '/[^a-z0-9]+/', '-', $group );
		return substr( trim( (string) $group, '-' ), 0, 32 );
	}

	/**
	 * Normalise one token to the canonical shape.
	 *
	 * @param array $token Raw token.
	 * @return array|null
	 */
	public static function normalize( $token ) {
		$name = self::sanitize_name( isset( $token['name'] ) ? $token['name'] : '' );
		if ( '' === $name ) {
			return null;
		}
		$value = self::sanitize_value( isset( $token['value'] ) ? $token['value'] : '' );
		$group = self::sanitize_group( isset( $token['group'] ) ? $token['group'] : '' );
		if ( '' === $group ) {
			$group = self::derive_group( $name );
		}
		return array(
			'name'    => $name,
			'value'   => $value,
			'group'   => $group,
			'enabled' => ! isset( $token['enabled'] ) || (bool) $token['enabled'],
		);
	}
}
