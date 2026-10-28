<?php
/**
 * Font conversion: TrueType / OpenType (.ttf, .otf) to WOFF2, or to WOFF when
 * the server can't make WOFF2.
 *
 * WOFF2 needs Brotli compression, which PHP only has with the brotli extension.
 * Without it the font becomes WOFF (zlib, available everywhere), which is still
 * much smaller than the original and works in every current browser.
 *
 * Written from the W3C specifications: WOFF 1.0 (https://www.w3.org/TR/WOFF/)
 * and WOFF2 (https://www.w3.org/TR/WOFF2/). WOFF2 files use the null transform
 * for every table (glyf/loca: transform version 3), which the spec allows.
 *
 * Also converts fonts added in the Bricks font manager: a face with only a
 * TTF/OTF file gets a WOFF2 (or WOFF) file next to it.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * SFNT → WOFF / WOFF2.
 */
class Font_Convert {

	/** WOFF2 known table tags (index = position). */
	const WOFF2_TAGS = array( 'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill' );

	/** @var bool Our own post meta write in progress. */
	private static $busy = false;

	/**
	 * Hooks (Bricks font manager).
	 */
	public function __construct() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_font_faces' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_font_faces' ), 10, 4 );
	}

	/**
	 * Can this server make WOFF2?
	 *
	 * @return bool
	 */
	public static function can_woff2() {
		return function_exists( 'brotli_compress' );
	}

	/**
	 * Read the tables of a TrueType / OpenType font.
	 *
	 * @param string $bytes Font file.
	 * @return array|null [ 'flavor' => int, 'tables' => [ tag => [ 'data' => string, 'checksum' => int ] ] ]
	 */
	public static function parse( $bytes ) {
		$len = strlen( $bytes );
		if ( $len < 12 ) {
			return null;
		}
		$head   = unpack( 'Nflavor/nnum', substr( $bytes, 0, 6 ) );
		$flavor = $head['flavor'];
		// TrueType (0x00010000 or 'true') or CFF ('OTTO'); collections are not supported.
		if ( ! in_array( $flavor, array( 0x00010000, 0x74727565, 0x4F54544F ), true ) ) {
			return null;
		}
		$num = $head['num'];
		if ( $num < 1 || $num > 200 || 12 + 16 * $num > $len ) {
			return null;
		}
		$tables = array();
		for ( $i = 0; $i < $num; $i++ ) {
			$e   = unpack( 'a4tag/Nchecksum/Noffset/Nlength', substr( $bytes, 12 + 16 * $i, 16 ) );
			$tag = $e['tag'];
			if ( $e['offset'] + $e['length'] > $len || isset( $tables[ $tag ] ) ) {
				return null;
			}
			$tables[ $tag ] = array( 'data' => substr( $bytes, $e['offset'], $e['length'] ), 'checksum' => $e['checksum'] );
		}
		return array( 'flavor' => $flavor, 'tables' => $tables );
	}

	/**
	 * Size of the uncompressed font (sfnt header, directory, 4-byte padded tables).
	 *
	 * @param array $tables Tables.
	 * @return int
	 */
	private static function sfnt_size( $tables ) {
		$size = 12 + 16 * count( $tables );
		foreach ( $tables as $t ) {
			$size += ( strlen( $t['data'] ) + 3 ) & ~3;
		}
		return $size;
	}

	/**
	 * TrueType / OpenType → WOFF 1.0.
	 *
	 * @param string $bytes Font file.
	 * @return string|null
	 */
	public static function to_woff( $bytes ) {
		$font = self::parse( $bytes );
		if ( ! $font || ! function_exists( 'gzcompress' ) ) {
			return null;
		}
		$tables = $font['tables'];
		ksort( $tables, SORT_STRING ); // ascending tag order
		$n      = count( $tables );
		$offset = 44 + 20 * $n;
		$dir    = '';
		$data   = '';
		foreach ( $tables as $tag => $t ) {
			$orig = $t['data'];
			$comp = gzcompress( $orig, 9 ); // zlib stream, as compress2()
			$use  = ( false !== $comp && strlen( $comp ) < strlen( $orig ) ) ? $comp : $orig;
			$dir .= pack( 'a4NNNN', $tag, $offset + strlen( $data ), strlen( $use ), strlen( $orig ), $t['checksum'] );
			$data .= $use . str_repeat( "\0", ( 4 - strlen( $use ) % 4 ) % 4 );
		}
		$length = 44 + strlen( $dir ) + strlen( $data );
		$header = pack( 'NNNnnNnnNNNNN', 0x774F4646, $font['flavor'], $length, $n, 0, self::sfnt_size( $tables ), 1, 0, 0, 0, 0, 0, 0 );
		return $header . $dir . $data;
	}

	/**
	 * UIntBase128 (shortest form).
	 *
	 * @param int $n Value.
	 * @return string
	 */
	private static function base128( $n ) {
		$out = chr( $n & 0x7F );
		$n >>= 7;
		while ( $n > 0 ) {
			$out = chr( 0x80 | ( $n & 0x7F ) ) . $out;
			$n >>= 7;
		}
		return $out;
	}

	/**
	 * TrueType / OpenType → WOFF2 (null transforms).
	 *
	 * @param string        $bytes      Font file.
	 * @param callable|null $compressor Brotli compressor (default: the brotli extension).
	 * @return string|null
	 */
	public static function to_woff2( $bytes, $compressor = null ) {
		$font = self::parse( $bytes );
		if ( ! $font ) {
			return null;
		}
		if ( null === $compressor ) {
			if ( ! self::can_woff2() ) {
				return null;
			}
			$compressor = function ( $raw ) {
				return brotli_compress( $raw, 11, defined( 'BROTLI_FONT' ) ? BROTLI_FONT : 2 );
			};
		}
		$tables = $font['tables'];
		ksort( $tables, SORT_STRING );
		// loca must come straight after glyf.
		if ( isset( $tables['glyf'], $tables['loca'] ) ) {
			$ordered = array();
			foreach ( $tables as $tag => $t ) {
				if ( 'loca' === $tag ) {
					continue;
				}
				$ordered[ $tag ] = $t;
				if ( 'glyf' === $tag ) {
					$ordered['loca'] = $tables['loca'];
				}
			}
			$tables = $ordered;
		} elseif ( isset( $tables['glyf'] ) xor isset( $tables['loca'] ) ) {
			return null; // broken TrueType font
		}
		$known = array_flip( self::WOFF2_TAGS );
		$dir   = '';
		$raw   = '';
		foreach ( $tables as $tag => $t ) {
			$transform = ( 'glyf' === $tag || 'loca' === $tag ) ? 3 : 0; // null transform
			if ( isset( $known[ $tag ] ) ) {
				$dir .= chr( $known[ $tag ] | ( $transform << 6 ) );
			} else {
				$dir .= chr( 63 | ( $transform << 6 ) ) . pack( 'a4', $tag );
			}
			$dir .= self::base128( strlen( $t['data'] ) );
			$raw .= $t['data'];
		}
		$compressed = call_user_func( $compressor, $raw );
		if ( ! is_string( $compressed ) || '' === $compressed ) {
			return null;
		}
		// Decoders expect the file to end on a 4-byte boundary after the compressed data.
		$pad    = ( 4 - ( 48 + strlen( $dir ) + strlen( $compressed ) ) % 4 ) % 4;
		$length = 48 + strlen( $dir ) + strlen( $compressed ) + $pad;
		$header = pack( 'NNNnnNNnnNNNNN', 0x774F4632, $font['flavor'], $length, count( $tables ), 0, self::sfnt_size( $tables ), strlen( $compressed ), 1, 0, 0, 0, 0, 0, 0 );
		return $header . $dir . $compressed . str_repeat( "\0", $pad );
	}

	/**
	 * Convert a local .ttf/.otf file; the new file is written next to it.
	 *
	 * @param string $path Font file.
	 * @return array|null [ 'path' => string, 'format' => 'woff2'|'woff' ]
	 */
	public static function convert_file( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'ttf', 'otf' ), true ) || ! is_readable( $path ) || filesize( $path ) > Fonts::MAX_UPLOAD ) {
			return null;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
		if ( false === $bytes ) {
			return null;
		}
		$out    = self::to_woff2( $bytes );
		$format = 'woff2';
		if ( null === $out ) {
			$out    = self::to_woff( $bytes );
			$format = 'woff';
		}
		if ( null === $out ) {
			return null;
		}
		$dir  = dirname( $path );
		$name = wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . '.' . $format );
		$dest = trailingslashit( $dir ) . $name;
		if ( false === file_put_contents( $dest, $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- uploads folder
			return null;
		}
		return array( 'path' => $dest, 'format' => $format );
	}

	/**
	 * Bricks font manager: when a font's faces are saved, give every face that
	 * only has a TTF/OTF file a WOFF2 (or WOFF) file too.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $post_id    Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public static function on_font_faces( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( self::$busy || 'bricks_font_faces' !== $meta_key || 'bricks_fonts' !== get_post_type( $post_id ) || ! is_array( $meta_value ) ) {
			return;
		}
		// Adding a file to the media library is an upload: only for users who may upload.
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$faces   = $meta_value;
		$changed = false;
		foreach ( $faces as $key => $face ) {
			if ( ! is_array( $face ) ) {
				continue;
			}
			$subsets = isset( $face[0] ) && is_array( $face[0] ) ? $face : array( $face );
			foreach ( $subsets as $i => $subset ) {
				if ( ! is_array( $subset ) || ! empty( $subset['woff2'] ) || ( ! self::can_woff2() && ! empty( $subset['woff'] ) ) ) {
					continue;
				}
				$source = ! empty( $subset['ttf'] ) ? (int) $subset['ttf'] : ( ! empty( $subset['otf'] ) ? (int) $subset['otf'] : 0 );
				$file   = $source ? get_attached_file( $source ) : '';
				$made   = $file ? self::convert_file( $file ) : null;
				if ( ! $made ) {
					continue;
				}
				$type = wp_check_filetype( $made['path'], array( $made['format'] => 'font/' . $made['format'] ) );
				$att  = wp_insert_attachment(
					array(
						'post_title'     => sanitize_text_field( pathinfo( $made['path'], PATHINFO_FILENAME ) ),
						'post_mime_type' => $type['type'] ? $type['type'] : 'font/' . $made['format'],
						'post_status'    => 'inherit',
					),
					$made['path'],
					$post_id
				);
				if ( ! $att || is_wp_error( $att ) ) {
					continue;
				}
				$subset[ $made['format'] ] = $att;
				$subsets[ $i ]             = $subset;
				$changed                   = true;
			}
			$faces[ $key ] = isset( $face[0] ) && is_array( $face[0] ) ? $subsets : $subsets[0];
		}
		if ( $changed ) {
			self::$busy = true;
			update_post_meta( $post_id, 'bricks_font_faces', $faces );
			self::$busy = false;
		}
	}
}
