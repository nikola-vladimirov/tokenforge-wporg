<?php
/**
 * WP-CLI commands: `wp tokenforge ...`.
 *
 * Loaded only when WP_CLI is defined. Every command calls the code the REST
 * routes call (Rest handlers, Transfer, History, Contrast, Css_Generator), so
 * the dashboard and the command line cannot drift apart. A shell user is
 * trusted like an administrator; use --user=<id> to have snapshots record who ran them.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Manage TokenForge from the command line.
 */
class Cli {

	/** Largest import file read (bytes). */
	const MAX_FILE = 2097152;

	/**
	 * Register the command.
	 *
	 * @return void
	 */
	public static function register() {
		\WP_CLI::add_command( 'tokenforge', self::class );
	}

	/**
	 * The REST handler object, without registering its routes a second time.
	 *
	 * @return Rest
	 */
	private static function rest() {
		return ( new \ReflectionClass( Rest::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * A REST request with a JSON body.
	 *
	 * @param array $body Body.
	 * @param array $args URL parameters.
	 * @return \WP_REST_Request
	 */
	private static function request( $body, $args = array() ) {
		$req = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/cli' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
		foreach ( $args as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	/**
	 * Rebuild the stylesheets.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge regenerate
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function regenerate( $args, $assoc ) {
		$d = self::rest()->regenerate()->get_data();
		if ( empty( $d['ok'] ) ) {
			\WP_CLI::error( 'The stylesheet could not be written. Check that the uploads folder is writable.' );
		}
		\WP_CLI::success( 'Stylesheet rebuilt: ' . $d['css_url'] );
	}

	/**
	 * Export every setting, token, colour family, font and the Global SCSS as JSON.
	 *
	 * Prints the JSON. To keep it in a file, redirect the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge export > tokenforge-export.json
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function export( $args, $assoc ) {
		\WP_CLI::line( (string) wp_json_encode( Transfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Import an export file. A snapshot is taken first (see `history`).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : An export file (JSON, 2 MB at most).
	 *
	 * [--parts=<list>]
	 * : Comma list of: tokens, palette, settings, modules, scss, recipes, fonts. Default all.
	 *
	 * [--dry-run]
	 * : Show which parts would change and write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge import tokenforge-export.json --dry-run
	 *     wp tokenforge import tokenforge-export.json --parts=tokens,palette
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function import( $args, $assoc ) {
		$path = $args[0];
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( "Cannot read $path." );
		}
		if ( filesize( $path ) > self::MAX_FILE ) {
			\WP_CLI::error( 'The file is larger than 2 MB.' );
		}
		$file  = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$error = Transfer::check( $file );
		if ( '' !== $error ) {
			\WP_CLI::error( $error );
		}
		$parts = Transfer::PARTS;
		if ( isset( $assoc['parts'] ) ) {
			$parts = array_values( array_intersect( Transfer::PARTS, array_map( 'trim', explode( ',', (string) $assoc['parts'] ) ) ) );
			if ( ! $parts ) {
				\WP_CLI::error( 'No known part in --parts. Choose from: ' . implode( ', ', Transfer::PARTS ) );
			}
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false ) ) {
			$now = json_decode( (string) wp_json_encode( History::data() ), true );
			// Run the real (sanitising) import with every TokenForge option write held in memory, then compare.
			$held = array();
			$read = function ( $pre, $option ) use ( &$held ) {
				return array_key_exists( $option, $held ) ? $held[ $option ] : $pre;
			};
			$hold = function ( $value, $option, $old ) use ( &$held, $read ) {
				if ( 0 !== strpos( $option, 'tokenforge_' ) ) {
					return $value;
				}
				$held[ $option ] = $value;
				add_filter( 'pre_option_' . $option, $read, PHP_INT_MAX, 2 );
				return $old; // unchanged, so update_option writes nothing
			};
			add_filter( 'pre_update_option', $hold, PHP_INT_MIN, 3 );
			Transfer::import( $file, $parts );
			$after = json_decode( (string) wp_json_encode( History::data() ), true );
			remove_filter( 'pre_update_option', $hold, PHP_INT_MIN );
			foreach ( array_keys( $held ) as $option ) {
				remove_filter( 'pre_option_' . $option, $read, PHP_INT_MAX );
			}
			$rows  = array();
			foreach ( $parts as $p ) {
				$in     = isset( $file['data'][ $p ] ) && is_array( $file['data'][ $p ] );
				$rows[] = array(
					'part'   => $p,
					'result' => ! $in ? 'not in file, stays as it is' : ( ( $after[ $p ] ?? null ) == ( $now[ $p ] ?? null ) ? 'same as now' : 'would change' ), // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- key order may differ
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'part', 'result' ) );
			\WP_CLI::success( 'Dry run: nothing was written. Each part was compared after the same checks the import makes.' );
			return;
		}
		$d = self::rest()->import_file( self::request( array( 'file' => $file, 'parts' => $parts ) ) )->get_data();
		if ( empty( $d['ok'] ) ) {
			\WP_CLI::error( $d['error'] ?? 'The import failed.' );
		}
		\WP_CLI::success( 'Imported: ' . implode( ', ', $d['imported'] ) . '. Stylesheet rebuilt.' );
	}

	/**
	 * Take a snapshot of the current settings now.
	 *
	 * ## OPTIONS
	 *
	 * [--note=<text>]
	 * : A short label, kept in the snapshot's trigger (letters, digits and dashes).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge snapshot --note=before-release
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function snapshot( $args, $assoc ) {
		$note = isset( $assoc['note'] ) ? substr( sanitize_title( (string) $assoc['note'] ), 0, 40 ) : '';
		$id   = History::snapshot( '' === $note ? 'cli' : 'cli-' . $note );
		if ( ! $id ) {
			\WP_CLI::success( 'Nothing changed since the last snapshot, so no new one was taken.' );
			return;
		}
		\WP_CLI::success( "Snapshot $id taken." );
	}

	/**
	 * List the snapshots, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml or count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge history
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function history( $args, $assoc ) {
		$rows = array();
		foreach ( History::items() as $s ) {
			$rows[] = array(
				'id'      => $s['id'],
				'time'    => gmdate( 'Y-m-d H:i:s', $s['time'] ) . ' UTC',
				'user'    => $s['user'],
				'trigger' => $s['trigger'],
				'size'    => $s['size'],
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'time', 'user', 'trigger', 'size' ) );
	}

	/**
	 * Put a snapshot back. The current state is snapshotted first, so a restore can be undone.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : A snapshot id from `history`.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge restore 3 --yes
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function restore( $args, $assoc ) {
		$id = (int) $args[0];
		if ( $id < 1 ) {
			\WP_CLI::error( 'Give a snapshot id (see `wp tokenforge history`).' );
		}
		\WP_CLI::confirm( "Restore snapshot $id? The current state is snapshotted first.", $assoc );
		$d = self::rest()->restore( self::request( array(), array( 'id' => $id ) ) )->get_data();
		if ( empty( $d['ok'] ) ) {
			\WP_CLI::error( $d['error'] ?? 'The restore failed.' );
		}
		\WP_CLI::success( "Snapshot $id restored. Stylesheet rebuilt." );
	}

	/**
	 * Show the version, stylesheet files, detected builders and update access. Writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge status
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc ) {
		$css   = Css_Generator::get_instance();
		$files = array(
			'main' => $css->css_path(),
			'full' => $css->full_css_path(),
			'shop' => $css->shop_css_path(),
		);
		$rows  = array(
			array( 'item' => 'version', 'value' => TOKENFORGE_VERSION ),
		);
		foreach ( $files as $k => $f ) {
			$rows[] = array( 'item' => "stylesheet $k", 'value' => file_exists( $f ) ? size_format( (int) filesize( $f ), 1 ) . ' (' . $f . ')' : 'not written' );
		}
		$builders = array();
		foreach ( Platforms\Platforms::detected() as $p ) {
			if ( $p['active'] ) {
				$builders[] = $p['label'] . ( '' !== $p['version'] ? ' ' . $p['version'] : '' );
			}
		}
		$rows[] = array( 'item' => 'active builders', 'value' => $builders ? implode( ', ', $builders ) : 'none' );
		$rows[] = array( 'item' => 'github token', 'value' => defined( 'TOKENFORGE_GITHUB_TOKEN' ) && TOKENFORGE_GITHUB_TOKEN ? 'set' : 'not set' );
		$rows[] = array( 'item' => 'snapshots', 'value' => (string) count( History::index() ) );
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'item', 'value' ) );
	}

	/**
	 * Check text contrast. Exit code 1 when a pair fails, so it can gate a release.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : List passing pairs too.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenforge contrast
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments.
	 * @return void
	 */
	public function contrast( $args, $assoc ) {
		$r    = Contrast::report();
		$all  = \WP_CLI\Utils\get_flag_value( $assoc, 'all', false );
		$rows = array();
		foreach ( $r['rows'] as $p ) {
			if ( $p['pass'] && ! $all ) {
				continue;
			}
			$rows[] = array(
				'where'  => $p['where'],
				'what'   => $p['what'],
				'needs'  => $p['min'],
				'light'  => $p['modes'][0]['ratio'],
				'dark'   => isset( $p['modes'][1] ) ? $p['modes'][1]['ratio'] : '',
				'pass'   => $p['pass'] ? 'yes' : 'no',
				'fix'    => ! empty( $p['fix']['value'] ) ? $p['fix']['value'] : '',
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'where', 'what', 'needs', 'light', 'dark', 'pass', 'fix' ) );
		}
		if ( $r['skipped'] ) {
			\WP_CLI::warning( count( $r['skipped'] ) . ' pair(s) skipped (not a plain colour): ' . implode( '; ', $r['skipped'] ) );
		}
		if ( $r['fail'] ) {
			\WP_CLI::error( $r['fail'] . ' pair(s) fail the contrast check.' );
		}
		\WP_CLI::success( 'Every checked pair passes.' );
	}
}
