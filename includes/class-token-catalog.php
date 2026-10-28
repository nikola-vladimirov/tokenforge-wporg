<?php
/**
 * Token catalog: every CSS variable TokenForge's settings make, with where it
 * comes from, so the Tokens screen can list them next to your own tokens.
 *
 * Three kinds:
 * - colors:  the colour families that Colors writes into the token list;
 * - setting: made on Save by Layout, Typography, Spacing, Borders, Icons and
 *            Effects, or declared by another part of the stylesheet where a
 *            token still wins (before the token list, or in a cascade layer);
 *            a token in the list with the same name replaces it;
 * - fixed:   written by another part of the stylesheet after the token list,
 *            so a token with the same name has no effect (read from the last
 *            generated stylesheet).
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * The variables the settings make.
 */
class Token_Catalog {

	/**
	 * Every generated variable: [ { name, value, group, kind, source } ] (one per name).
	 *
	 * @return array
	 */
	public static function all() {
		$out  = array();
		$seen = array();
		$add  = function ( $name, $value, $kind, $source ) use ( &$out, &$seen ) {
			$name = Tokens::sanitize_name( $name );
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				return;
			}
			$seen[ $name ] = true;
			$out[]         = array(
				'name'   => $name,
				'value'  => Tokens::sanitize_value( $value ),
				'group'  => Tokens::derive_group( $name ),
				'kind'   => $kind,
				'source' => $source,
			);
		};
		foreach ( self::colors() as $t ) {
			$add( $t['name'], $t['value'], 'colors', 'colors' );
		}
		$lists = array(
			'layout'  => Width::variables(),
			'scales'  => Scale_Engine::variables(),
			'borders' => Extras::variables(),
			'effects' => Motion::variables(),
		);
		$sheet = self::stylesheet_variables();
		foreach ( $lists as $from => $list ) {
			foreach ( $list as $v ) {
				$n = Tokens::normalize( $v );
				if ( ! $n ) {
					continue;
				}
				// Set again after the token list: that later value is the one that counts.
				if ( isset( $sheet[ $n['name'] ] ) && ! $sheet[ $n['name'] ][2] ) {
					$add( $n['name'], $sheet[ $n['name'] ][0], 'fixed', $sheet[ $n['name'] ][1] );
				} else {
					$add( $n['name'], $n['value'], 'setting', self::setting_source( $from, $n['name'] ) );
				}
			}
		}
		foreach ( $sheet as $name => $d ) {
			$add( $name, $d[0], $d[2] ? 'setting' : 'fixed', $d[1] );
		}
		return $out;
	}

	/**
	 * How often each variable is used (var(--name …)) in the generated
	 * stylesheets, the token list included: name => count. A switched-off
	 * token that is still used leaves those places without a value.
	 *
	 * @return array
	 */
	public static function usage() {
		$gen = Css_Generator::get_instance();
		$out = array();
		// With unused utility classes left out, count in the file that has them all.
		$main = file_exists( $gen->full_css_path() ) ? $gen->full_css_path() : $gen->css_path();
		foreach ( array( $main, $gen->shop_css_path() ) as $file ) {
			$css = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own generated file
			if ( '' !== $css && preg_match_all( '/var\(\s*--([a-z0-9-]+)/i', $css, $m ) ) {
				foreach ( $m[1] as $name ) {
					$name         = strtolower( $name );
					$out[ $name ] = isset( $out[ $name ] ) ? $out[ $name ] + 1 : 1;
				}
			}
		}
		return $out;
	}

	/**
	 * Let the settings decide, as Use settings on the Tokens screen does: leave
	 * out stored tokens that repeat what a setting makes (switched on), that
	 * have no effect (set again later in the stylesheet), and colour tokens the
	 * families make. Tokens with a value of their own, switched-off ones and
	 * your own tokens stay. Reads the last generated stylesheet, so generate()
	 * first.
	 *
	 * @param array $tokens     Stored tokens.
	 * @param bool  $all_colors Also colour tokens with a value of their own
	 *                          (after an import, where they are copies), and the
	 *                          typography and spacing scales: the import has just
	 *                          written their settings, and a stored token wins on
	 *                          a name clash, so ACSS's own (static) copies of the
	 *                          step variables must not stay to freeze the scale.
	 * @return array [ tokens, how many were left out ]
	 */
	public static function tidy( $tokens, $all_colors = false ) {
		$cat = array();
		foreach ( self::all() as $c ) {
			$cat[ $c['name'] ] = $c;
		}
		// The same result counts as the same value: 1px and var(--border-size) at 1px.
		$vars = array();
		foreach ( self::every_variable() as $v ) {
			if ( ! empty( $v['enabled'] ) ) {
				$vars[ $v['name'] ] = $v['value'];
			}
		}
		// Typography and spacing steps the scale engine makes (fluid, from the
		// settings the import just wrote), as names. Leave it empty when the scale
		// is switched off, then ACSS's own values are the only ones there are.
		$scale = array();
		if ( $all_colors ) {
			foreach ( Scale_Engine::variables() as $v ) {
				$norm = Tokens::normalize( $v );
				if ( $norm && in_array( $v['group'], array( 'typography', 'space' ), true ) ) {
					$scale[ $norm['name'] ] = true;
				}
			}
		}
		$out = array();
		$n   = 0;
		foreach ( $tokens as $t ) {
			$c = isset( $cat[ $t['name'] ] ) ? $cat[ $t['name'] ] : null;
			if ( $c ) {
				$same = Tokens::same_value( $t['value'], $c['value'] )
					|| Tokens::same_value( Tokens::resolve( $t['value'], $vars ), Tokens::resolve( $c['value'], $vars ) );
				$drop = 'fixed' === $c['kind']
					|| ( ! empty( $t['enabled'] ) && $same )
					|| ( $all_colors && ! empty( $t['enabled'] ) && ( 'colors' === $c['kind'] || isset( $scale[ $t['name'] ] ) ) );
				if ( $drop ) {
					$n++;
					continue;
				}
			}
			$out[] = $t;
		}
		return array( $out, $n );
	}

	/**
	 * Every variable the stylesheet sets, as tokens: the token list (your tokens
	 * and what the settings make) plus what other parts of the stylesheet set on
	 * :root, with the value that actually applies. For lists meant for people
	 * and tools (builder variables, cheatsheet, CSS checks), so they do not
	 * depend on copies of those variables being kept in the token list.
	 *
	 * @return array Normalised tokens.
	 */
	public static function every_variable() {
		$tokens = Settings::get_instance()->all_variables();
		$at     = array();
		foreach ( $tokens as $i => $t ) {
			$at[ $t['name'] ] = $i;
		}
		foreach ( self::stylesheet_variables() as $name => $d ) {
			if ( '' === $d[0] ) {
				continue;
			}
			$row = array(
				'name'    => $name,
				'value'   => Tokens::sanitize_value( $d[0] ),
				'group'   => Tokens::derive_group( $name ),
				'enabled' => true,
			);
			if ( ! isset( $at[ $name ] ) ) {
				$tokens[] = $row;
				continue;
			}
			// A switched-off token leaves the stylesheet's own value; one set after the list wins anyway.
			$t = $tokens[ $at[ $name ] ];
			if ( empty( $t['enabled'] ) || ! $d[2] ) {
				$row['group']              = $t['group'];
				$tokens[ $at[ $name ] ] = $row;
			}
		}
		return $tokens;
	}

	/**
	 * The colour tokens Colors makes now (families that are on). White and
	 * black are ordinary tokens (the starter adds them; Colors leaves them be).
	 *
	 * @return array Tokens.
	 */
	public static function colors() {
		return Palette::tokens();
	}

	/**
	 * Dashboard section a setting-made variable belongs to.
	 *
	 * @param string $from Generator.
	 * @param string $name Variable name.
	 * @return string Section id.
	 */
	private static function setting_source( $from, $name ) {
		if ( 'scales' === $from ) {
			if ( preg_match( '/^(text-|h[1-6]($|-))/', $name ) ) {
				return 'typography';
			}
			if ( preg_match( '/^(space-|section-space-)/', $name ) ) {
				return 'spacing';
			}
			return 'layout';
		}
		if ( 'borders' === $from ) {
			if ( 0 === strpos( $name, 'icon' ) ) {
				return 'icons';
			}
			return 'header-height' === $name ? 'layout' : 'borders';
		}
		return $from;
	}

	/**
	 * Variables other parts of the stylesheet declare on :root, from the last
	 * generated stylesheet: name => [ value, section, a token replaces it ].
	 *
	 * A token replaces a variable declared before the token list, or inside a
	 * cascade layer (layered rules lose to the unlayered token list). One
	 * declared after the list, unlayered, wins over a token of the same name.
	 * Values inside @media, @supports or @container are not the base value and
	 * are left out.
	 *
	 * @return array
	 */
	public static function stylesheet_variables() {
		$gen  = Css_Generator::get_instance();
		$file = file_exists( $gen->full_css_path() ) ? $gen->full_css_path() : $gen->css_path();
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own generated file
		if ( '' === $css ) {
			return array();
		}
		// Several lists can ask in one request; parse each version of the file once.
		static $memo = array();
		$key = md5( $css );
		if ( ! isset( $memo[ $key ] ) ) {
			$memo = array( $key => self::parse_variables( $css ) );
		}
		return $memo[ $key ];
	}

	/**
	 * Read the :root variables out of a TokenForge stylesheet (see stylesheet_variables()).
	 *
	 * @param string $css Stylesheet.
	 * @return array
	 */
	public static function parse_variables( $css ) {
		// The token list is the one formatted ":root {" block (the rest is minified).
		$list_at  = strpos( $css, ":root {\n" );
		$list_end = false === $list_at ? -1 : strpos( $css, "\n}\n", $list_at );
		$list_end = false === $list_end ? -1 : $list_end;
		$len      = strlen( $css );
		$stack    = array(); // [ prelude, body start ].
		$from     = 0;       // Where the current prelude starts.
		$section  = 'TokenForge';
		$out      = array();
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '/' === $ch && '*' === substr( $css, $i + 1, 1 ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$end = false === $end ? $len : $end + 2;
				if ( preg_match( '#^/\* --- (.+?) --- \*/$#', substr( $css, $i, $end - $i ), $m ) ) {
					$section = $m[1];
				}
				$i    = $end - 1;
				$from = $end;
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$end = strpos( $css, $ch, $i + 1 );
				$i   = false === $end ? $len : $end;
				continue;
			}
			if ( '{' === $ch ) {
				$stack[] = array( trim( substr( $css, $from, $i - $from ) ), $i + 1 );
				$from    = $i + 1;
				continue;
			}
			if ( ';' === $ch ) {
				$from = $i + 1;
				continue;
			}
			if ( '}' !== $ch ) {
				continue;
			}
			$from  = $i + 1;
			$block = array_pop( $stack );
			if ( ! $block || ! preg_match( '/^(?:html)?:root$/', $block[0] ) || ( $list_at >= 0 && $block[1] > $list_at && $block[1] <= $list_end + 1 ) ) {
				continue;
			}
			$layered = false;
			$skip    = false;
			foreach ( $stack as $outer ) {
				if ( 0 === strpos( $outer[0], '@layer' ) ) {
					$layered = true;
				} elseif ( '@' === substr( $outer[0], 0, 1 ) ) {
					$skip = true;
				}
			}
			if ( $skip || ! preg_match_all( '/--([a-z0-9-]+)\s*:\s*([^;]*)/i', substr( $css, $block[1], $i - $block[1] ), $decl, PREG_SET_ORDER ) ) {
				continue;
			}
			$replaceable = $layered || ( $list_at >= 0 && $block[1] < $list_at );
			foreach ( $decl as $d ) {
				$name = strtolower( $d[1] );
				// Once set after the list, unlayered, that value wins whatever follows in a layer.
				if ( isset( $out[ $name ] ) && ! $out[ $name ][2] && $replaceable ) {
					continue;
				}
				$out[ $name ] = array( trim( $d[2] ), self::section_source( $section, $name ), $replaceable );
			}
		}
		return $out;
	}

	/**
	 * Dashboard section for a variable another part of the stylesheet declares.
	 *
	 * @param string $section Stylesheet section title.
	 * @param string $name    Variable name.
	 * @return string Section id.
	 */
	private static function section_source( $section, $name ) {
		$section = strtolower( $section );
		foreach ( array( 'global scss' => 'scss', 'woocommerce' => 'woo', 'color scheme' => 'scheme', 'content' => 'content', 'blockquote' => 'content' ) as $needle => $id ) {
			if ( false !== strpos( $section, $needle ) ) {
				return $id;
			}
		}
		$by_name = array(
			'/^(root-font-size|text-|heading-|h[1-6]-|font-)/'                                            => 'typography',
			'/^(paragraph|heading-spacing|list-|nested-list|flow-|figure|figcaption|blockquote|prose)/'   => 'content',
			'/^(section-|gutter|content-|container-|grid-|scroll-margin|offset|admin-bar|boxed)/'               => 'layout',
			'/^(btn-|link-|option-)/'                                                                     => 'buttons',
			'/^card-/'                                                                                    => 'cards',
			'/^(field-|form-|input-)/'                                                                    => 'forms',
			'/^icon/'                                                                                     => 'icons',
			'/^(radius|border|divider|shadow|box-shadow|text-shadow|drop-shadow)/'                        => 'borders',
			'/^(transition|ease|motion)/'                                                                 => 'effects',
			'/^(overlay|surface)/'                                                                        => 'overlays',
		);
		foreach ( $by_name as $re => $id ) {
			if ( preg_match( $re, $name ) ) {
				return $id;
			}
		}
		return 'base';
	}
}
