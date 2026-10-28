<?php
/**
 * Global SCSS: your own SCSS, compiled with TokenForge's mixin library and added
 * to the end of the stylesheet (unlayered, so it wins over everything generated).
 *
 * The library (assets/scss/_library.scss plus parts generated from your
 * settings: breakpoints, button styles, heading/text styles, cards, surfaces) is
 * available without an @import. Compiled by a bundled, namespaced copy of
 * scssphp (MIT). A compile error never breaks the site: the last good CSS stays
 * in place and the error (with the line in YOUR code) is shown in the dashboard.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Global SCSS module.
 */
class Scss {

	const OPTION = 'tokenforge_scss';       // settings + source
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
			'enabled' => true,
			'source'  => '',
			// Two-way sync with Bricks global classes (Bricks only).
			'sync'        => true,  // class blocks <-> the class's Style fields and Custom CSS
			'sync_pull'   => true,  // a class edited in the builder that isn't in the SCSS yet gets a block
			'sync_create' => false, // a class block without a Bricks class creates one
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
		return Modules::apply( 'scss', wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() ) );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d     = self::defaults();
		$clean = array(
			'enabled' => ! empty( $in['enabled'] ),
			'source'  => isset( $in['source'] ) ? str_replace( "\r\n", "\n", (string) $in['source'] ) : '',
			'sync'        => ! empty( $in['sync'] ),
			'sync_pull'   => ! empty( $in['sync_pull'] ),
			'sync_create' => ! empty( $in['sync_create'] ),
		);
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
	 * Remove lines that only made sense in Automatic.css ("@import automatic-imports").
	 *
	 * @param string $source SCSS.
	 * @return string
	 */
	private static function clean_source( $source ) {
		return preg_replace( '/^[ \t]*@import\s+[\'"]automatic-imports[\'"]\s*;[ \t]*$/m', '', (string) $source );
	}

	/**
	 * Compile SCSS (with the library in front).
	 *
	 * @param string $source Your SCSS.
	 * @return array [ 'css' => string, 'error' => null|array{message,line,column} ]
	 */
	public static function compile( $source ) {
		$source = self::clean_source( $source );
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
		$s = self::settings();
		if ( empty( $s['enabled'] ) || '' === trim( self::clean_source( $s['source'] ) ) ) {
			return '';
		}
		$hash  = md5( TOKENFORGE_VERSION . self::library() . "\n" . $s['source'] );
		$build = get_option( self::BUILD, array() );
		$build = is_array( $build ) ? $build : array();
		if ( isset( $build['hash'] ) && $build['hash'] === $hash ) {
			return isset( $build['css'] ) ? (string) $build['css'] : '';
		}
		$r     = self::compile( $s['source'] );
		$build = array(
			'hash'  => $hash,
			'css'   => null === $r['error'] ? $r['css'] : ( isset( $build['css'] ) ? (string) $build['css'] : '' ),
			'error' => $r['error'],
			'time'  => time(),
		);
		update_option( self::BUILD, $build, false );
		return $build['css'];
	}

	/**
	 * Last compile status (for the dashboard).
	 *
	 * @return array [ 'error' => null|array, 'time' => int ]
	 */
	public static function status() {
		$b = get_option( self::BUILD, array() );
		return array(
			'error' => is_array( $b ) && ! empty( $b['error'] ) ? $b['error'] : null,
			'time'  => is_array( $b ) && isset( $b['time'] ) ? (int) $b['time'] : 0,
		);
	}

	/**
	 * Mixins and functions for the editor's autocomplete and the reference list.
	 *
	 * @return array [ [ name, signature, kind, description ], … ]
	 */
	public static function reference() {
		return array(
			array( 'breakpoint', '@include breakpoint(m) { … }', 'mixin', 'Screens at or below a breakpoint (xs, s, m, l, xl, xxl or a length).' ),
			array( 'breakpoint-up', '@include breakpoint-up(m) { … }', 'mixin', 'Screens at or above a breakpoint.' ),
			array( 'btn', '@include btn(primary);', 'mixin', 'A button: primary, primary-dark, primary-light, "primary.btn--outline" … ($props: no = only the colours).' ),
			array( 'btn-default', '@include btn-default;', 'mixin', 'The shared button declarations without colours.' ),
			array( 'card', '@include card(light);', 'mixin', 'The card styles ($style: light | dark | inherit; $output: all | no).' ),
			array( 'card-container', '@include card-container("inline-size > 767px") { … }', 'mixin', 'Container query on the nearest card container.' ),
			array( 'container', '@include container("min-width: 30rem") { … }', 'mixin', 'Any container query ($name optional).' ),
			array( 'center', '@include center(left);', 'mixin', 'Centre as a column: all | left | right | top | bottom; $output: full | core | tokens.' ),
			array( 'content-grid', '@include content-grid;', 'mixin', 'Content grid with named lines (content, feature, feature-max, full).' ),
			array( 'auto-grid', '@include auto-grid(4);', 'mixin', 'Responsive grid: $column-count, $min, $flow, $force-even-column-count.' ),
			array( 'grid', '@include grid(3);', 'mixin', 'A grid from --grid-N (or explicit tracks), $rows, $gap.' ),
			array( 'clickable-parent', '@include clickable-parent;', 'mixin', 'On the link/button: its click area covers the positioned card.' ),
			array( 'focus-parent', '@include focus-parent(shadow);', 'mixin', 'Focus ring on the card when a child has keyboard focus (shadow | outline).' ),
			array( 'border', '@include border(light, block-start, var(--radius-l));', 'mixin', 'Border from the tokens: main | light | dark; side; radius (no = none).' ),
			array( 'fade', '@include fade(block, 25%);', 'mixin', 'Mask fade: block | inline | top | right | bottom | left.' ),
			array( 'line-clamp', '@include line-clamp(3);', 'mixin', 'Cut text after N lines.' ),
			array( 'heading-style', '@include heading-style(h2);', 'mixin', 'The global heading styles of a level.' ),
			array( 'text-style', '@include text-style(l);', 'mixin', 'The body text styles at a size.' ),
			array( 'icon', '@include icon(dark, box);', 'mixin', 'Icon styling ($scheme: light | dark; $box-style: box | plain).' ),
			array( 'overlay', '@include overlay(rgb(0 0 0 / .5));', 'mixin', 'A tint under the content ($overlay-opacity, $overlay-bg-image).' ),
			array( 'texture', '@include texture(1);', 'mixin', 'Surface N as the background.' ),
			array( 'texture-overlay', '@include texture-overlay(1);', 'mixin', 'Surface N on a pseudo-element under the content.' ),
			array( 'delay-children', '@include delay-children(6, 100ms);', 'mixin', 'Stagger the animation delay of the first N children.' ),
			array( 'keyframes', '@include keyframes(name) { from {…} to {…} }', 'mixin', '@keyframes from a mixin.' ),
			array( 'fluid', 'fluid(16, 24)', 'function', 'Fluid clamp() between two px values across the viewport range.' ),
			array( 'to-rem', 'to-rem(24px)', 'function', 'px → rem at your root font size (unitless: adds rem).' ),
			array( 'ctr', 'ctr(24)', 'function', 'Pixels to rem.' ),
			array( 'strip-unit', 'strip-unit(16px)', 'function', 'The number without its unit.' ),
		);
	}

	/**
	 * Adopt Automatic.css's custom SCSS (its Custom SCSS file, or the older
	 * custom-global-css setting). Non-destructive: added below your own code.
	 *
	 * @param array $acss ACSS settings.
	 * @return bool Whether anything was imported.
	 */
	public static function import_acss( $acss ) {
		$up   = wp_get_upload_dir();
		$file = trailingslashit( $up['basedir'] ) . 'automatic-css/automatic-custom-css.scss';
		$code = '';
		if ( file_exists( $file ) ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$code = self::clean_source( $code );
			$code = preg_replace( '#^[ \t]*//[ \t]*(Load SCSS Powers|Add Your Code Below)[ \t]*$#mi', '', $code );
		}
		if ( '' === trim( $code ) && ! empty( $acss['custom-global-css'] ) && is_string( $acss['custom-global-css'] ) ) {
			$code = $acss['custom-global-css'];
		}
		$code = trim( str_replace( "\r\n", "\n", (string) $code ) );
		if ( '' === $code ) {
			return false;
		}
		$s = self::settings();
		if ( false !== strpos( $s['source'], $code ) ) {
			return false; // already there
		}
		$s['source'] = '' === trim( $s['source'] ) ? $code . "\n" : rtrim( $s['source'] ) . "\n\n/* Imported custom CSS */\n" . $code . "\n";
		self::save_settings( $s );
		return true;
	}
}
