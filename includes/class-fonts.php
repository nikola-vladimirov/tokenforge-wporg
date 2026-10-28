<?php
/**
 * Self-hosted fonts: @font-face rules in the TokenForge stylesheet.
 *
 * Each font = a family name + a font file (woff2 / woff / ttf / otf, uploaded
 * through TokenForge or any URL) with an optional fallback file, weight (or a
 * range for variable fonts), style, font-display, stretch and variation
 * settings. Optionally preloaded, and listed in the Bricks font picker so the
 * family can be chosen in any typography control.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Fonts module.
 */
class Fonts {

	const OPTION = 'tokenforge_fonts';

	/** File types we accept: extension => [ CSS format, MIME for preload ]. */
	const TYPES = array(
		'woff2' => array( 'woff2', 'font/woff2' ),
		'woff'  => array( 'woff', 'font/woff' ),
		'ttf'   => array( 'truetype', 'font/ttf' ),
		'otf'   => array( 'opentype', 'font/otf' ),
	);

	/** Largest upload (bytes). */
	const MAX_UPLOAD = 10485760;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'wp_head', array( __CLASS__, 'preload' ), 2 );
		add_filter( 'bricks/builder/standard_fonts', array( __CLASS__, 'bricks_fonts' ) );
		add_filter( 'wp_theme_json_data_theme', array( __CLASS__, 'theme_json' ) );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'fonts', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		return array(
			'enabled' => true,  // output the @font-face rules
			'bricks'  => true,  // list the families in the Bricks font picker
			'blocks'  => true,  // list them in the block editor font picker too
			'fonts'   => array(), // [ { family, src, src2, weight, style, display, variable, stretch, variation, preload } ]
		);
	}

	/**
	 * One empty font.
	 *
	 * @return array
	 */
	public static function blank() {
		return array( 'family' => '', 'src' => '', 'src2' => '', 'weight' => '400', 'style' => 'normal', 'display' => 'swap', 'variable' => false, 'stretch' => '', 'variation' => '', 'preload' => false, 'unicode' => '' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		$s['fonts'] = self::clean_fonts( $s['fonts'] );
		return Modules::apply( 'fonts', $s );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		update_option(
			self::OPTION,
			array(
				'enabled' => ! empty( $in['enabled'] ),
				'bricks'  => ! empty( $in['bricks'] ),
				'blocks'  => ! empty( $in['blocks'] ),
				'fonts'   => self::convert_local( self::clean_fonts( isset( $in['fonts'] ) ? $in['fonts'] : array() ) ),
			),
			false
		);
	}

	/**
	 * Sanitise the font list (entries without a family or a file are kept, so a
	 * half-filled row survives a save, but they output nothing).
	 *
	 * @param mixed $list Raw list.
	 * @return array
	 */
	private static function clean_fonts( $list ) {
		$out = array();
		foreach ( array_slice( array_values( (array) $list ), 0, 40 ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$f      = wp_parse_args( $f, self::blank() );
			$weight = strtolower( trim( (string) $f['weight'] ) );
			$out[]  = array(
				'family'    => trim( preg_replace( '/["\';{}<>\\\\]/', '', sanitize_text_field( (string) $f['family'] ) ) ),
				'src'       => self::url( $f['src'] ),
				'src2'      => self::url( $f['src2'] ),
				'weight'    => preg_match( '/^(normal|bold|[1-9]00|[1-9]\d{0,2}(\s+[1-9]\d{0,2})?)$/', $weight ) ? $weight : '400',
				'style'     => in_array( $f['style'], array( 'normal', 'italic', 'oblique' ), true ) ? $f['style'] : 'normal',
				'display'   => in_array( $f['display'], array( 'auto', 'block', 'swap', 'fallback', 'optional' ), true ) ? $f['display'] : 'swap',
				'variable'  => ! empty( $f['variable'] ),
				'stretch'   => preg_match( '/^[a-z0-9.%\s-]*$/i', (string) $f['stretch'] ) ? trim( (string) $f['stretch'] ) : '',
				'variation' => trim( str_replace( array( ';', '{', '}', '<', '>', '\\' ), '', (string) $f['variation'] ) ),
				'preload'   => ! empty( $f['preload'] ),
				// unicode-range: U+0000-00FF, U+0131 …
				'unicode'   => preg_match( '/^[Uu+0-9A-Fa-f?,\s-]*$/', (string) $f['unicode'] ) ? trim( preg_replace( '/\s+/', ' ', (string) $f['unicode'] ) ) : '',
			);
		}
		return $out;
	}

	/**
	 * A font URL (http(s) or site-relative), or ''.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function url( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( 0 === strpos( $v, '/' ) && 0 !== strpos( $v, '//' ) ) {
			return preg_replace( '/[^A-Za-z0-9\/._~%+-]/', '', $v );
		}
		return esc_url_raw( $v, array( 'http', 'https' ) );
	}

	/**
	 * File type from a URL's extension.
	 *
	 * @param string $url URL.
	 * @return array|null [ format, mime ]
	 */
	public static function type( $url ) {
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return isset( self::TYPES[ $ext ] ) ? self::TYPES[ $ext ] : null;
	}

	/**
	 * Fonts that output something.
	 *
	 * @return array
	 */
	public static function active() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$s['fonts'],
				function ( $f ) {
					return '' !== $f['family'] && '' !== $f['src'];
				}
			)
		);
	}

	/**
	 * The @font-face rules.
	 *
	 * @return string
	 */
	public static function css() {
		$out = '';
		foreach ( self::active() as $f ) {
			$src = array();
			foreach ( array( $f['src'], $f['src2'] ) as $u ) {
				if ( '' === $u ) {
					continue;
				}
				$t     = self::type( $u );
				$src[] = 'url("' . str_replace( array( '"', ')' ), array( '%22', '%29' ), $u ) . '")' . ( $t ? ' format("' . $t[0] . '")' : '' );
			}
			$out .= '@font-face{font-family:"' . $f['family'] . '";src:' . implode( ',', $src ) . ';font-weight:' . $f['weight'] . ';font-style:' . $f['style'] . ';font-display:' . $f['display'] . ';'
				. ( $f['variable'] && '' !== $f['stretch'] ? 'font-stretch:' . $f['stretch'] . ';' : '' )
				. ( $f['variable'] && '' !== $f['variation'] ? 'font-variation-settings:' . $f['variation'] . ';' : '' )
				. ( '' !== $f['unicode'] ? 'unicode-range:' . $f['unicode'] . ';' : '' )
				. "}\n";
		}
		return '' === $out ? '' : "\n/* --- TokenForge fonts --- */\n" . $out;
	}

	/**
	 * Families to suggest in the dashboard's font fields: TokenForge fonts and the
	 * builder's custom fonts.
	 *
	 * @return string[]
	 */
	public static function choices() {
		$out = self::families();
		if ( post_type_exists( 'bricks_fonts' ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'bricks_fonts',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$t = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
				if ( '' !== trim( $t ) ) {
					$out[] = trim( $t );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Family names (for the Bricks font picker, the dashboard and the AI guide).
	 *
	 * @return array
	 */
	public static function families() {
		return array_values( array_unique( array_column( self::active(), 'family' ) ) );
	}

	/**
	 * <link rel="preload"> for fonts marked "preload" (front end).
	 *
	 * @return void
	 */
	public static function preload() {
		if ( is_admin() ) {
			return;
		}
		foreach ( self::active() as $f ) {
			if ( empty( $f['preload'] ) ) {
				continue;
			}
			$t = self::type( $f['src'] );
			printf( '<link rel="preload" href="%s" as="font" type="%s" crossorigin>' . "\n", esc_url( $f['src'] ), esc_attr( $t ? $t[1] : 'font/woff2' ) );
		}
	}

	/**
	 * Our families in the block editor's font picker (the theme's font families,
	 * WordPress 6.1+). The @font-face rules already reach the editor with the
	 * TokenForge stylesheet, so only the families are listed.
	 *
	 * @param object $theme_json WP_Theme_JSON_Data.
	 * @return object
	 */
	public static function theme_json( $theme_json ) {
		$s    = self::settings();
		$fams = self::families();
		if ( empty( $s['blocks'] ) || ! $fams || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
			return $theme_json;
		}
		$data = method_exists( $theme_json, 'get_data' ) ? (array) $theme_json->get_data() : array();
		$list = isset( $data['settings']['typography']['fontFamilies'] ) && is_array( $data['settings']['typography']['fontFamilies'] ) ? $data['settings']['typography']['fontFamilies'] : array();
		if ( isset( $list['theme'] ) && is_array( $list['theme'] ) ) {
			$list = $list['theme']; // stored by origin
		}
		$list  = array_values( array_filter( $list, 'is_array' ) );
		$slugs = array_column( $list, 'slug' );
		foreach ( $fams as $fam ) {
			$slug = sanitize_title( $fam );
			if ( '' === $slug || in_array( $slug, $slugs, true ) ) {
				continue;
			}
			$list[]  = array(
				'name'       => $fam,
				'slug'       => $slug,
				'fontFamily' => '"' . $fam . '", ' . self::fallback( $fam ),
			);
			$slugs[] = $slug;
		}
		return $theme_json->update_with(
			array(
				'version'  => 2,
				'settings' => array( 'typography' => array( 'fontFamilies' => $list ) ),
			)
		);
	}

	/**
	 * The fallback of a family: the one used with it in the TokenForge
	 * typography, else sans-serif.
	 *
	 * @param string $family Family.
	 * @return string
	 */
	private static function fallback( $family ) {
		$c = Components::settings();
		foreach ( array( 'text_font_family', 'heading_font_family', 'btn_font_family' ) as $k ) {
			$parts = array_map( 'trim', explode( ',', isset( $c[ $k ] ) ? (string) $c[ $k ] : '' ) );
			if ( count( $parts ) > 1 && strtolower( trim( $parts[0], "\"' " ) ) === strtolower( $family ) ) {
				return implode( ', ', array_slice( $parts, 1 ) );
			}
		}
		return 'sans-serif';
	}

	/**
	 * Add our families to Bricks' font list (typography controls).
	 *
	 * @param array $fonts Standard fonts.
	 * @return array
	 */
	public static function bricks_fonts( $fonts ) {
		$s = self::settings();
		if ( empty( $s['bricks'] ) ) {
			return $fonts;
		}
		return array_values( array_unique( array_merge( self::families(), (array) $fonts ) ) );
	}

	/**
	 * Store an uploaded font file in uploads/tokenforge/fonts/ after checking
	 * its extension, size and file signature.
	 *
	 * @param array $file One entry of $_FILES.
	 * @return array [ 'ok' => bool, 'url' => string, 'error' => string, 'family' => guess ]
	 */
	public static function upload( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'] ) || ! empty( $file['error'] ) ) {
			return array( 'ok' => false, 'error' => 'No file received.' );
		}
		$name = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return array( 'ok' => false, 'error' => 'Use a .woff2, .woff, .ttf or .otf file.' );
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > self::MAX_UPLOAD ) {
			return array( 'ok' => false, 'error' => 'The file must be smaller than 10 MB.' );
		}
		if ( ! self::signature_ok( (string) $file['tmp_name'], $ext ) ) {
			return array( 'ok' => false, 'error' => 'That file is not a valid ' . $ext . ' font.' );
		}
		// WordPress stores the file (same checks as its own font library), in uploads/tokenforge/fonts.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file['name'] = $name;
		add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		$done = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::upload_mimes(),
			)
		);
		remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		if ( empty( $done['file'] ) || empty( $done['url'] ) ) {
			return array( 'ok' => false, 'error' => ! empty( $done['error'] ) ? (string) $done['error'] : 'Could not store the file.' );
		}
		$dest      = (string) $done['file'];
		$name      = wp_basename( $dest );
		$up        = wp_upload_dir();
		$url       = (string) $done['url'];
		$converted = '';
		// TTF / OTF: use a WOFF2 (or WOFF) copy, much smaller; the original stays.
		if ( in_array( $ext, array( 'ttf', 'otf' ), true ) ) {
			$made = Font_Convert::convert_file( $dest );
			if ( $made ) {
				$url       = trailingslashit( $up['baseurl'] ) . 'tokenforge/fonts/' . basename( $made['path'] );
				$converted = $made['format'];
			}
		}
		return array(
			'ok'        => true,
			'url'       => $url,
			'converted' => $converted,
			'family'    => self::family_guess( $name ),
			'error'     => '',
		);
	}

	/** Where a Google Fonts download asks for the family's CSS. */
	const GOOGLE_CSS = 'https://fonts.googleapis.com/css2';

	/** The only host font files may come from. */
	const GOOGLE_FILES = 'fonts.gstatic.com';

	/**
	 * Download a Google Fonts family into uploads/tokenforge/fonts, once, when an
	 * administrator asks for it (visitors never contact Google). Every character
	 * set Google has for the family comes as its own .woff2 file with its
	 * unicode-range, so the browser only loads the ones a page needs.
	 *
	 * @param string $family  Family name ("Inter").
	 * @param string $weights Weights ("400, 700").
	 * @param bool   $italic  Italic too.
	 * @return array [ 'ok' => bool, 'fonts' => [ font entries ], 'error' => string ]
	 */
	public static function google( $family, $weights, $italic ) {
		$family = trim( preg_replace( '/\s+/', ' ', (string) $family ) );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 ]{0,59}$/', $family ) ) {
			return array( 'ok' => false, 'fonts' => array(), 'error' => 'Type a Google Fonts family name, for example Inter.' );
		}
		$ws = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $weights ) as $w ) {
			$w = (int) $w;
			if ( $w >= 100 && $w <= 900 && 0 === $w % 100 ) {
				$ws[ $w ] = $w;
			}
		}
		if ( ! $ws ) {
			$ws = array( 400 => 400, 700 => 700 );
		}
		ksort( $ws );
		$axes = array();
		foreach ( $italic ? array( 0, 1 ) : array( 0 ) as $i ) {
			foreach ( $ws as $w ) {
				$axes[] = $i . ',' . $w;
			}
		}
		$url = self::GOOGLE_CSS . '?family=' . str_replace( '%20', '+', rawurlencode( $family ) ) . ':ital,wght@' . implode( ';', $axes ) . '&display=swap';
		// A current browser's user agent: Google then answers with .woff2 files.
		$res = wp_safe_remote_get( $url, array( 'timeout' => 15, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'fonts' => array(), 'error' => 'Google Fonts could not be reached: ' . $res->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( 'ok' => false, 'fonts' => array(), 'error' => 'Google Fonts has no family called ' . $family . ' with those weights.' );
		}
		$faces = array();
		preg_match_all( '#(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i', (string) wp_remote_retrieve_body( $res ), $m, PREG_SET_ORDER );
		foreach ( $m as $f ) {
			$body = $f[2];
			if ( ! preg_match( '#url\((https://fonts\.gstatic\.com/[A-Za-z0-9/._~-]+\.woff2)\)#', $body, $u ) ) {
				continue;
			}
			$host = wp_parse_url( $u[1], PHP_URL_HOST );
			if ( self::GOOGLE_FILES !== $host ) {
				continue;
			}
			preg_match( '/font-weight:\s*(\d{3})/', $body, $w );
			preg_match( '/font-style:\s*(normal|italic)/', $body, $st );
			preg_match( '/unicode-range:\s*([U+0-9A-Fa-f?,\s-]+);/', $body, $range );
			$faces[] = array(
				'url'    => $u[1],
				'weight' => isset( $w[1] ) ? $w[1] : '400',
				'style'  => isset( $st[1] ) ? $st[1] : 'normal',
				'subset' => ! empty( $f[1] ) ? sanitize_key( $f[1] ) : '',
				'range'  => isset( $range[1] ) ? trim( preg_replace( '/\s+/', ' ', $range[1] ) ) : '',
			);
		}
		if ( ! $faces ) {
			return array( 'ok' => false, 'fonts' => array(), 'error' => 'Google Fonts sent no font files for ' . $family . '.' );
		}
		$faces = array_slice( $faces, 0, 80 );
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$slug = sanitize_title( $family );
		$out  = array();
		$fail = 0;
		add_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		foreach ( $faces as $f ) {
			$tmp = download_url( $f['url'], 20 );
			if ( is_wp_error( $tmp ) ) {
				++$fail;
				continue;
			}
			if ( ! self::signature_ok( $tmp, 'woff2' ) ) {
				wp_delete_file( $tmp );
				++$fail;
				continue;
			}
			$name = $slug . '-' . $f['weight'] . ( 'italic' === $f['style'] ? '-italic' : '' ) . ( '' !== $f['subset'] ? '-' . $f['subset'] : '' ) . '.woff2';
			$file = array( 'name' => $name, 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) filesize( $tmp ) );
			$done = wp_handle_sideload( $file, array( 'test_form' => false, 'mimes' => self::upload_mimes() ) );
			if ( empty( $done['url'] ) ) {
				wp_delete_file( $tmp );
				++$fail;
				continue;
			}
			$out[] = array_merge(
				self::blank(),
				array( 'family' => $family, 'src' => (string) $done['url'], 'weight' => (string) $f['weight'], 'style' => $f['style'], 'unicode' => $f['range'] )
			);
		}
		remove_filter( 'upload_dir', array( __CLASS__, 'upload_dir' ) );
		if ( ! $out ) {
			return array( 'ok' => false, 'fonts' => array(), 'error' => 'The font files could not be stored.' );
		}
		return array( 'ok' => true, 'fonts' => $out, 'error' => $fail ? $fail . ' file(s) could not be stored.' : '' );
	}

	/**
	 * Font uploads go to uploads/tokenforge/fonts (upload_dir filter, only while TokenForge stores a font).
	 *
	 * @param array $dirs Upload folder data.
	 * @return array
	 */
	public static function upload_dir( $dirs ) {
		$dirs['subdir'] = '/tokenforge/fonts';
		$dirs['path']   = $dirs['basedir'] . '/tokenforge/fonts';
		$dirs['url']    = $dirs['baseurl'] . '/tokenforge/fonts';
		return $dirs;
	}

	/**
	 * Allowed font types for an upload: WordPress's own list when it has one (6.5+), the same list otherwise.
	 *
	 * @return array extension => MIME
	 */
	public static function upload_mimes() {
		if ( class_exists( '\WP_Font_Utils' ) && method_exists( '\WP_Font_Utils', 'get_allowed_font_mime_types' ) ) {
			return array_intersect_key( (array) \WP_Font_Utils::get_allowed_font_mime_types(), self::TYPES );
		}
		return array(
			'otf'   => 'application/vnd.ms-opentype',
			'ttf'   => PHP_VERSION_ID >= 70400 ? 'font/sfnt' : 'application/font-sfnt',
			'woff'  => PHP_VERSION_ID >= 80112 ? 'font/woff' : 'application/font-woff',
			'woff2' => PHP_VERSION_ID >= 80112 ? 'font/woff2' : 'application/font-woff2',
		);
	}

	/**
	 * The file behind a font URL in this site's uploads folder, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function local_file( $url ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['baseurl'] );
		$url  = (string) $url;
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}
		foreach ( array( $base, set_url_scheme( $base, 'http' ), set_url_scheme( $base, 'https' ) ) as $b ) {
			if ( 0 === strpos( $url, $b ) ) {
				$path = realpath( trailingslashit( $up['basedir'] ) . rawurldecode( substr( $url, strlen( $b ) ) ) );
				$root = realpath( $up['basedir'] );
				return ( $path && $root && 0 === strpos( $path, $root ) && is_file( $path ) ) ? $path : '';
			}
		}
		return '';
	}

	/**
	 * Fonts that point at a TTF/OTF file in this site's uploads get a WOFF2 (or
	 * WOFF) copy, used from then on.
	 *
	 * @param array $fonts Clean font list.
	 * @return array
	 */
	private static function convert_local( $fonts ) {
		$up = wp_upload_dir();
		foreach ( $fonts as $i => $f ) {
			$t = self::type( $f['src'] );
			if ( ! $t || ! in_array( $t[0], array( 'truetype', 'opentype' ), true ) ) {
				continue;
			}
			$file = self::local_file( $f['src'] );
			$made = $file ? Font_Convert::convert_file( $file ) : null;
			if ( $made ) {
				$rel                 = ltrim( str_replace( '\\', '/', substr( $made['path'], strlen( realpath( $up['basedir'] ) ) ) ), '/' );
				$fonts[ $i ]['src']  = trailingslashit( $up['baseurl'] ) . $rel;
			}
		}
		return $fonts;
	}

	/**
	 * Does the file start like a font of that type?
	 *
	 * @param string $path File.
	 * @param string $ext  Extension.
	 * @return bool
	 */
	public static function signature_ok( $path, $ext ) {
		$h = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $h ) {
			return false;
		}
		$head = (string) fread( $h, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		switch ( $ext ) {
			case 'woff2':
				return 'wOF2' === $head;
			case 'woff':
				return 'wOFF' === $head;
			default: // ttf / otf: TrueType or CFF outlines, either extension.
				return in_array( $head, array( "\x00\x01\x00\x00", 'true', 'OTTO' ), true );
		}
	}

	/**
	 * "Inter-VariableFont_wght.woff2" → "Inter".
	 *
	 * @param string $file File name.
	 * @return string
	 */
	public static function family_guess( $file ) {
		$n = pathinfo( $file, PATHINFO_FILENAME );
		$n = preg_replace( '/[-_](variable|var|vf|regular|italic|bold|light|medium|semibold|black|thin|extralight|extrabold|latin|ext|subset|wght|opsz|\d+).*$/i', '', $n );
		$n = trim( preg_replace( '/([a-z])([A-Z])/', '$1 $2', str_replace( array( '-', '_' ), ' ', $n ) ) );
		return '' === $n ? 'My Font' : ucwords( $n );
	}
}
