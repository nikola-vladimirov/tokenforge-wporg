<?php
/**
 * SCSS compiler: compiles SCSS with TokenForge's mixin library. The only SCSS
 * added to the stylesheet comes from the tokenforge_global_scss filter (code),
 * there is no screen to type it in.
 *
 * The library (assets/scss/_library.scss plus parts generated from the
 * settings: breakpoints, button styles, heading/text styles, cards, surfaces) is
 * available without an @import. Compiled by a bundled, namespaced copy of
 * scssphp (MIT). A compile error never breaks the site: the last good CSS stays
 * in place.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * SCSS compiler and breakpoints.
 */
class Scss {

	const OPTION = 'tokenforge_scss';       // breakpoints
	const BUILD  = 'tokenforge_scss_build'; // last compile: hash, css, error, time

	/** Breakpoint names, smallest first. */
	const BREAKPOINTS = array( 'xs', 's', 'm', 'l', 'xl', 'xxl' );

	/** @var bool */
	private static $autoload = false;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'scss', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			// Breakpoints (px) for breakpoint()/breakpoint-up(), the media-query recipes
			// and $breakpoints. "Below" queries use max-width: value.
			'bp_xs'   => 478,
			'bp_s'    => 767,
			'bp_m'    => 991,
			'bp_l'    => 1279,
			'bp_xl'   => 1439,
			'bp_xxl'  => 1919,
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d     = self::defaults();
		$clean = array();
		foreach ( self::BREAKPOINTS as $bp ) {
			$v                    = isset( $in[ "bp_$bp" ] ) ? (int) $in[ "bp_$bp" ] : $d[ "bp_$bp" ];
			$clean[ "bp_$bp" ]    = ( $v >= 100 && $v <= 5000 ) ? $v : $d[ "bp_$bp" ];
		}
		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Breakpoints as name => px.
	 *
	 * @return array
	 */
	public static function breakpoints() {
		$s   = self::settings();
		$out = array();
		foreach ( self::BREAKPOINTS as $bp ) {
			$out[ $bp ] = (int) $s[ "bp_$bp" ];
		}
		return $out;
	}

	/**
	 * Register the autoloader of the bundled scssphp (namespaced to TokenForge so it
	 * never clashes with another plugin's copy).
	 *
	 * @return void
	 */
	private static function autoload() {
		if ( self::$autoload ) {
			return;
		}
		self::$autoload = true;
		spl_autoload_register(
			function ( $class ) {
				$prefix = 'TokenForge\\Vendor\\ScssPhp\\ScssPhp\\';
				if ( 0 === strpos( $class, $prefix ) ) {
					$file = TOKENFORGE_DIR . 'vendor/scssphp/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( file_exists( $file ) ) {
						require $file;
					}
				}
			}
		);
	}

	/**
	 * A PHP string as an SCSS quoted string.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function q( $v ) {
		return '"' . str_replace( array( '\\', '"', "\n", "\r", '#{' ), array( '\\\\', '\\"', ' ', ' ', '#\\{' ), (string) $v ) . '"';
	}

	/**
	 * CSS declarations safe to paste into generated SCSS (no interpolation/variables).
	 *
	 * @param string $css Declarations.
	 * @return string
	 */
	private static function decls( $css ) {
		return self::pretty( str_replace( array( '#{', '$' ), array( '#\\{', '\\$' ), (string) $css ) );
	}

	/**
	 * One declaration / brace per line (outside quotes and parentheses). Besides
	 * readability, scssphp's parser can fail on long one-line blocks.
	 *
	 * @param string $css Minified CSS.
	 * @return string
	 */
	private static function pretty( $css ) {
		$out   = '';
		$depth = 0;   // parentheses / brackets
		$level = 0;   // braces
		$quote = '';
		$len   = strlen( $css );
		$nl    = function ( $lvl ) {
			return "\n\t" . str_repeat( "\t", max( 0, $lvl ) );
		};
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '' !== $quote ) {
				$out .= $ch;
				if ( $ch === $quote && '\\' !== $css[ $i - 1 ] ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$out  .= $ch;
			} elseif ( '(' === $ch || '[' === $ch ) {
				$depth++;
				$out .= $ch;
			} elseif ( ')' === $ch || ']' === $ch ) {
				$depth--;
				$out .= $ch;
			} elseif ( 0 === $depth && '{' === $ch ) {
				$level++;
				$out .= ' {' . $nl( $level );
			} elseif ( 0 === $depth && ';' === $ch ) {
				$out .= ';' . $nl( $level );
			} elseif ( 0 === $depth && '}' === $ch ) {
				$level--;
				$out  = rtrim( $out, "\t\n " ) . ';' . $nl( $level ) . '}' . $nl( $level );
			} else {
				$out .= $ch;
			}
		}
		return trim( str_replace( ';;', ';', $out ) );
	}

	/**
	 * The SCSS placed before your code: variables, the hand-written library and
	 * mixins generated from the current settings.
	 *
	 * @return string
	 */
	public static function library() {
		$sc   = Scale_Engine::settings();
		$root = Defaults::settings();
		$ov   = Overlays::settings();
		$ex   = Extras::settings();
		$out  = array( '// ---- TokenForge: variables from your settings' );

		$out[] = '$vp-min: ' . (float) $sc['min_vw'] . 'px;';
		$out[] = '$vp-max: ' . (float) $sc['max_vw'] . 'px;';
		$out[] = '$root-font-size: ' . (float) $root['root_font_size'] . '%;';
		$map   = array();
		foreach ( self::breakpoints() as $bp => $px ) {
			$out[] = '$breakpoint-' . $bp . ': ' . $px . 'px;';
			$map[] = $bp . ': ' . $px . 'px';
		}
		$out[] = '$breakpoints: (' . implode( ', ', $map ) . ');';
		$out[] = '$tf-auto-grid-flow: ' . ( 'auto-fill' === $sc['auto_grid_flow'] ? 'auto-fill' : 'auto-fit' ) . ';';
		$out[] = '$tf-auto-grid-aggressiveness: ' . (float) $sc['auto_grid_aggressiveness'] . ';';
		$out[] = '$tf-overlay-color: unquote(' . self::q( '' !== (string) $ov['basic_color'] ? $ov['basic_color'] : 'color-mix(in oklch, var(--black, #000) 60%, transparent)' ) . ');';
		$out[] = '$tf-overlay-pseudo: ' . ( 'after' === $ov['basic_pseudo'] ? 'after' : 'before' ) . ';';
		$out[] = '$tf-icon-style: ' . ( 'boxed' === $ex['icon_default_style'] ? 'boxed' : 'plain' ) . ';';

		$file = TOKENFORGE_DIR . 'assets/scss/_library.scss';
		$out[] = file_exists( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

		// ---- Buttons: btn($style, $props) with every family / variant.
		$styles = array();
		foreach ( Components::btn_style_map() as $key => $vars ) {
			$pairs = array();
			foreach ( $vars as $n => $v ) {
				$pairs[] = $n . ': ' . self::q( $v );
			}
			$styles[] = self::q( $key ) . ': (' . implode( ', ', $pairs ) . ')';
		}
		$out[] = '// ---- TokenForge: generated from your settings';
		$out[] = '$tf-btn-styles: (' . implode( ",\n\t", $styles ) . ');';
		$out[] = '@mixin btn-default {' . "\n\t" . self::decls( Components::btn_look() ) . ";\n\tcursor: pointer;\n"
			. "\t@media (hover: hover) and (pointer: fine) {\n\t\t&:hover { background: var(--btn-background-hover); color: var(--btn-text-color-hover); border-color: var(--btn-border-color-hover); text-decoration: var(--btn-text-decoration-hover); }\n\t}\n"
			. "\t&:where(:focus-visible) { outline-style: solid; outline-color: var(--focus-color); outline-width: var(--focus-width, 2px); outline-offset: var(--focus-offset, 2px); }\n}";
		$out[] = "@mixin btn(\$style: primary, \$props: yes) {\n"
			. "\t@if \$props and \$props != no and \$props != \"no\" { @include btn-default; }\n"
			. "\t\$key: \"#{\$style}\";\n"
			. "\t@if not map-has-key(\$tf-btn-styles, \$key) { @error \"btn(): unknown style `#{\$style}`, e.g. primary, primary-dark, primary-light, \\\"primary.btn--outline\\\".\"; }\n"
			. "\t@each \$prop, \$value in map-get(\$tf-btn-styles, \$key) { --#{\$prop}: #{\$value}; }\n}";

		// ---- Typography: heading-style(h1…h6) and text-style(xs…xxl).
		$h = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$h[] = ( 1 === $i ? '@if' : '} @else if' ) . " \$l == \"h$i\" {\n\t\t" . self::decls( Components::heading_decls( $i ) ) . "\n\t";
		}
		$out[] = "@mixin heading-style(\$level: h2) {\n\t\$l: \"#{\$level}\";\n\t@if str-index(\$l, \"h\") != 1 { \$l: \"h#{\$l}\"; }\n\t"
			. implode( '', $h ) . "} @else { @error \"heading-style(): use h1 … h6 (got `#{\$level}`).\"; }\n}";
		$t = array();
		foreach ( Scale_Engine::STEPS as $n => $sz ) {
			$t[] = ( 0 === $n ? '@if' : '} @else if' ) . " \$l == \"$sz\" {\n\t\t" . self::decls( Components::text_decls( $sz ) ) . "\n\t";
		}
		$out[] = "@mixin text-style(\$level: m) {\n\t\$l: \"#{\$level}\";\n\t" . implode( '', $t ) . "} @else { @error \"text-style(): use " . implode( ', ', Scale_Engine::STEPS ) . " (got `#{\$level}`).\"; }\n}";

		// ---- Cards: card($style: null, $output: all).
		$cs   = Components::settings();
		$rule = Components::card_rules( $cs, '&', '&' );
		$main = array_shift( $rule );
		$main = preg_replace( '/^&\{(.*)\}$/s', '$1', $main );
		$out[] = "@mixin card(\$style: null, \$output: all) {\n"
			. "\t@if \$output == all {\n\t\t" . self::decls( $main ) . "\n\t}\n"
			. "\t@if \$style == light or \$style == Light { color-scheme: light; }\n"
			. "\t@else if \$style == dark or \$style == Dark { color-scheme: dark; }\n"
			. "\t@else if \$style == inherit { color-scheme: inherit; }\n"
			. "\t@if \$output == all {\n\t\t" . implode( "\n\t\t", array_map( array( __CLASS__, 'decls' ), $rule ) ) . "\n\t}\n}";

		// ---- Surfaces: texture(n) / surface(n) and texture-overlay(n).
		$surf = Overlays::surface_declarations();
		$tex  = array();
		$tov  = array();
		foreach ( $surf as $n => $decl ) {
			$d     = self::decls( $decl );
			$tex[] = ( $tex ? '} @else if' : '@if' ) . " \$number == $n {\n\t\t$d;\n\t";
			$tov[] = ( $tov ? '} @else if' : '@if' ) . " \$number == $n {\n\t\t&::before { content: \"\"; position: absolute; inset: 0; z-index: -1; pointer-events: none; $d; }\n\t";
		}
		$none  = '@error "texture(): there is no surface #{$number} (Surfaces & Overlays → Surfaces).";';
		$out[] = "@mixin texture(\$number) {\n\t" . ( $tex ? implode( '', $tex ) . "} @else { $none }" : $none ) . "\n}";
		$out[] = "@mixin surface(\$name: 1) {\n\t@include texture(\$name);\n}";
		$out[] = "@mixin texture-overlay(\$number) {\n\tposition: relative;\n\tisolation: isolate;\n\t" . ( $tov ? implode( '', $tov ) . "} @else { $none }" : $none ) . "\n}";

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Compile SCSS (with the library in front).
	 *
	 * @param string $source Your SCSS.
	 * @return array [ 'css' => string, 'error' => null|array{message,line,column} ]
	 */
	public static function compile( $source ) {
		$source = (string) $source;
		if ( '' === trim( $source ) ) {
			return array( 'css' => '', 'error' => null );
		}
		self::autoload();
		$lib    = self::library();
		$offset = substr_count( $lib, "\n" ); // library ends with "\n": your line 1 = line $offset + 1
		try {
			$c = new \TokenForge\Vendor\ScssPhp\ScssPhp\Compiler();
			$c->setLogger( new \TokenForge\Vendor\ScssPhp\ScssPhp\Logger\QuietLogger() );
			$c->setOutputStyle( \TokenForge\Vendor\ScssPhp\ScssPhp\OutputStyle::EXPANDED );
			$c->setCharset( false );
			$c->setImportPaths( array() ); // no file imports (url()/.css @imports still pass through)
			$css = $c->compileString( $lib . $source )->getCss();
			// Never let a stylesheet close an inline <style> element.
			$css = str_ireplace( '</style', '<\/style', (string) $css );
			$out = array( 'css' => trim( $css ), 'error' => null );
		} catch ( \Throwable $e ) {
			$out = array( 'css' => '', 'error' => self::error_info( $e->getMessage(), $offset ) );
		}
		return $out;
	}

	/**
	 * A compiler message → a short message + the line/column in YOUR code.
	 *
	 * @param string $msg    Raw message.
	 * @param int    $offset Lines of library before your code.
	 * @return array
	 */
	public static function error_info( $msg, $offset ) {
		$line = 0;
		$col  = 0;
		$lib  = 0;
		if ( preg_match_all( '/(?:on line|line:)\s*(\d+)(?:,\s*(?:at\s+)?column:?\s*(\d+))?/i', $msg, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$l = (int) $x[1];
				if ( $l > $offset ) {
					$line = $l - $offset;
					$col  = isset( $x[2] ) ? (int) $x[2] : 0;
					break;
				}
				if ( ! $lib ) {
					$lib = $l;
				}
			}
		}
		$first = strtok( str_replace( "\r", '', $msg ), "\n" );
		$text  = preg_replace(
			array( '/^File \(unknown file\) on line \d+ ERROR:\s*/i', '/\s*\((?:stdin|unknown file)\) on line \d+(?:, at column \d+)?/i', '/:?\s*line:\s*\d+,\s*column:\s*\d+\s*$/i', '/^(["\x27])(.*)\1$/s' ),
			array( '', '', '', '$2' ),
			trim( (string) $first )
		);
		if ( ! $line && $lib ) {
			$text .= ' (inside the TokenForge library)';
		}
		return array( 'message' => trim( $text ), 'line' => $line, 'column' => $col );
	}

	/**
	 * The compiled CSS for the stylesheet: cached by a hash of library + source, so
	 * it only recompiles when either changes. On an error the last good CSS stays.
	 *
	 * @return string
	 */
	public static function css() {
		/**
		 * SCSS to add to the end of the stylesheet. Return a string of SCSS: it is
		 * compiled with the TokenForge library (mixins, functions, breakpoints). Runs
		 * on each stylesheet build; an empty string adds nothing.
		 *
		 * @param string $scss SCSS source, empty by default.
		 */
		$source = apply_filters( 'tokenforge_global_scss', '' );
		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			return '';
		}
		$hash  = md5( TOKENFORGE_VERSION . self::library() . "\n" . $source );
		$build = get_option( self::BUILD, array() );
		$build = is_array( $build ) ? $build : array();
		if ( isset( $build['hash'] ) && $build['hash'] === $hash ) {
			return isset( $build['css'] ) ? (string) $build['css'] : '';
		}
		$r     = self::compile( $source );
		$build = array(
			'hash'  => $hash,
			'css'   => null === $r['error'] ? $r['css'] : ( isset( $build['css'] ) ? (string) $build['css'] : '' ),
			'error' => $r['error'],
			'time'  => time(),
		);
		update_option( self::BUILD, $build, false );
		return $build['css'];
	}
}
