<?php
/**
 * Form submissions: export selected rows as CSV or JSON.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

defined( 'ABSPATH' ) || exit;

/**
 * One REST route, cells hardened against spreadsheet formula injection.
 */
class Form_Submissions_Export {

	/**
	 * Hook the route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register POST /submissions/export.
	 */
	public function routes() {
		register_rest_route(
			'tokenforge/v1',
			'/submissions/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => static function () {
					return Form_Submissions::can() && Form_Submissions::on( 'export' );
				},
			)
		);
	}

	/**
	 * Build the file.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( $req ) {
		$ids = array_values( array_filter( wp_parse_id_list( $req->get_param( 'ids' ) ) ) );
		if ( ! $ids || count( $ids ) > 1000 ) {
			return new \WP_Error( 'tokenforge_export_ids', __( 'Select between 1 and 1000 submissions.', 'tokenforge' ), array( 'status' => 400 ) );
		}
		$format = (string) $req->get_param( 'format' );
		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			return new \WP_Error( 'tokenforge_export_format', __( 'The format must be csv or json.', 'tokenforge' ), array( 'status' => 400 ) );
		}
		$rows = Form_Submissions::entries( $ids );
		$name = 'form-submissions-' . wp_date( 'Y-m-d' ) . '.' . $format;
		if ( 'json' === $format ) {
			return rest_ensure_response(
				array(
					'filename' => $name,
					'mime'     => 'application/json',
					'content'  => $this->json( $rows ),
				)
			);
		}
		return rest_ensure_response(
			array(
				'filename' => $name,
				'mime'     => 'text/csv; charset=utf-8',
				'content'  => $this->csv( $rows ),
			)
		);
	}

	/**
	 * Escape one cell: a single quote in front of anything a spreadsheet would run as a formula.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function cell( $v ): string {
		$v = (string) $v;
		if ( '' === $v ) {
			return $v;
		}
		if ( "\t" === $v[0] || "\r" === $v[0] ) {
			return "'" . $v;
		}
		$t = ltrim( $v, " \t\r\n\0\x0B" );
		if ( '' === $t ) {
			return $v;
		}
		$c = $t[0];
		if ( '=' === $c || '+' === $c || '@' === $c || ( '-' === $c && ! is_numeric( $t ) ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/**
	 * CSV with a UTF-8 BOM.
	 *
	 * @param array[] $rows Entries.
	 * @return string
	 */
	private function csv( $rows ) {
		$status = Form_Submissions::on( 'status' );
		$cols   = array(); // Field id => label, first seen.
		foreach ( $rows as $r ) {
			foreach ( $r['fields'] as $f ) {
				if ( ! isset( $cols[ $f['id'] ] ) ) {
					$cols[ $f['id'] ] = (string) $f['label'];
				}
			}
		}
		$head = array( __( 'ID', 'tokenforge' ), __( 'Date', 'tokenforge' ), __( 'Form', 'tokenforge' ), __( 'Page', 'tokenforge' ) );
		if ( $status ) {
			array_push( $head, __( 'Status', 'tokenforge' ), __( 'Read', 'tokenforge' ), __( 'Note', 'tokenforge' ) );
		}
		$head = array_merge( $head, array_values( $cols ), array( __( 'Browser', 'tokenforge' ), __( 'OS', 'tokenforge' ), __( 'IP', 'tokenforge' ), __( 'Referrer', 'tokenforge' ), __( 'User', 'tokenforge' ) ) );

		$fh  = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$put = static function ( $line ) use ( $fh ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'cell' ), $line ), ',', '"', '' ); // RFC 4180: quotes doubled, no backslash escape
		};
		$put( $head );
		foreach ( $rows as $r ) {
			$line = array( $r['id'], $r['created'], $r['form_name'], $r['post_title'] );
			if ( $status ) {
				$m      = $r['meta'];
				$line[] = $m['status'];
				$line[] = $m['read'] ? __( 'Yes', 'tokenforge' ) : __( 'No', 'tokenforge' );
				$line[] = $m['note'];
			}
			$by = array();
			foreach ( $r['fields'] as $f ) {
				$by[ $f['id'] ] = self::flat( $f['value'] );
			}
			foreach ( $cols as $id => $label ) {
				$line[] = $by[ $id ] ?? '';
			}
			array_push( $line, $r['browser'], $r['os'], $r['ip'], $r['referrer'], $r['user'] );
			$put( $line );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return "\xEF\xBB\xBF" . $out;
	}

	/**
	 * A field value as one string (files: their names).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function flat( $v ) {
		if ( ! is_array( $v ) ) {
			return (string) $v;
		}
		$p = array();
		foreach ( $v as $x ) {
			$p[] = is_array( $x ) ? (string) ( $x['name'] ?? '' ) : (string) $x;
		}
		return implode( ', ', $p );
	}

	/**
	 * Pretty JSON, fields keyed by field id, raw values.
	 *
	 * @param array[] $rows Entries.
	 * @return string
	 */
	private function json( $rows ) {
		$status = Form_Submissions::on( 'status' );
		foreach ( $rows as &$r ) {
			$f = array();
			foreach ( $r['fields'] as $x ) {
				$f[ $x['id'] ] = array(
					'label' => $x['label'],
					'type'  => $x['type'],
					'value' => $x['value'],
				);
			}
			$r['fields'] = (object) $f;
			if ( ! $status ) {
				unset( $r['meta'] );
			}
		}
		unset( $r );
		return (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
