<?php
/**
 * Settings history: a snapshot of every TokenForge setting, token, colour family,
 * font and the Global SCSS before each Save, import, reset or setup, so any of
 * them can be undone.
 *
 * Storage: a small index option (tokenforge_history) plus one option per snapshot
 * (tokenforge_snap_<id>), all with autoload off, so nothing loads on visitor
 * pages. A snapshot is the Transfer export data, and a restore goes through
 * Transfer::import(), so it is sanitised exactly like an import.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots, list, diff, restore.
 */
class History {

	const INDEX  = 'tokenforge_history';
	const PREFIX = 'tokenforge_snap_';
	const KEEP   = 20;
	const BYTES  = 5242880; // 5 MB for all snapshots together

	/**
	 * The data a snapshot holds (as an export file's "data").
	 *
	 * @return array
	 */
	public static function data() {
		$e = Transfer::export();
		return $e['data'];
	}

	/**
	 * The index, newest first: [ { id, time, user, trigger, size, hash } ].
	 *
	 * @return array
	 */
	public static function index() {
		$list = get_option( self::INDEX, array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Take a snapshot of the current state (skipped when nothing changed since the last one).
	 *
	 * @param string $trigger What is about to change it: save, import, reset, setup, restore.
	 * @return int The new snapshot's id, 0 when none was taken.
	 */
	public static function snapshot( $trigger ) {
		$json = (string) wp_json_encode( self::data() );
		$hash = hash( 'sha256', $json );
		$list = self::index();
		if ( $list && isset( $list[0]['hash'] ) && $list[0]['hash'] === $hash ) {
			return 0;
		}
		// Options hold text: compressed data is stored as base64.
		$blob = function_exists( 'gzcompress' ) ? 'gz:' . base64_encode( gzcompress( $json, 6 ) ) : 'js:' . $json; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$id   = $list ? (int) $list[0]['id'] + 1 : 1;
		update_option( self::PREFIX . $id, $blob, false );
		array_unshift(
			$list,
			array(
				'id'      => $id,
				'time'    => time(),
				'user'    => get_current_user_id(),
				'cli'     => defined( 'WP_CLI' ) && WP_CLI,
				'trigger' => sanitize_key( $trigger ),
				'size'    => strlen( $blob ),
				'hash'    => $hash,
			)
		);
		// The last KEEP snapshots, BYTES at most (the newest always stays).
		$keep  = array();
		$total = 0;
		foreach ( $list as $i => $s ) {
			$total += (int) $s['size'];
			if ( 0 === $i || ( $i < self::KEEP && $total <= self::BYTES ) ) {
				$keep[] = $s;
			} else {
				delete_option( self::PREFIX . (int) $s['id'] );
			}
		}
		update_option( self::INDEX, $keep, false );
		return $id;
	}

	/**
	 * A snapshot's data.
	 *
	 * @param int $id Snapshot id.
	 * @return array|null Null when missing or damaged.
	 */
	public static function get( $id ) {
		$id = (int) $id;
		foreach ( self::index() as $s ) {
			if ( (int) $s['id'] !== $id ) {
				continue;
			}
			$blob = get_option( self::PREFIX . $id, '' );
			$json = '';
			if ( is_string( $blob ) && 0 === strpos( $blob, 'gz:' ) && function_exists( 'gzuncompress' ) ) {
				$json = (string) @gzuncompress( (string) base64_decode( substr( $blob, 3 ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			} elseif ( is_string( $blob ) && 0 === strpos( $blob, 'js:' ) ) {
				$json = substr( $blob, 3 );
			}
			if ( '' === $json || ! hash_equals( (string) $s['hash'], hash( 'sha256', $json ) ) ) {
				return null;
			}
			$data = json_decode( $json, true );
			return is_array( $data ) ? $data : null;
		}
		return null;
	}

	/**
	 * Put a snapshot back (a snapshot of the current state is taken first, so a
	 * restore can be undone too).
	 *
	 * @param int $id Snapshot id.
	 * @return bool
	 */
	public static function restore( $id ) {
		$data = self::get( $id );
		if ( null === $data ) {
			return false;
		}
		self::snapshot( 'restore' );
		Transfer::import( array( 'data' => $data ), Transfer::PARTS );
		return true;
	}

	/**
	 * The index for the dashboard: user names and the trigger, no hashes.
	 *
	 * @return array
	 */
	public static function items() {
		$out = array();
		foreach ( self::index() as $s ) {
			$u     = get_userdata( (int) $s['user'] );
			$out[] = array(
				'id'      => (int) $s['id'],
				'time'    => (int) $s['time'],
				'user'    => $u ? $u->display_name : ( empty( $s['cli'] ) ? '' : 'WP-CLI' ),
				'trigger' => (string) $s['trigger'],
				'size'    => (int) $s['size'],
			);
		}
		return $out;
	}

	/**
	 * What changed between a snapshot and now, key by key.
	 *
	 * @param int $id Snapshot id.
	 * @return array|null { changes: [ [ key, then, now ] ], total } or null when missing.
	 */
	public static function diff( $id ) {
		$then = self::get( $id );
		if ( null === $then ) {
			return null;
		}
		$a    = self::flat( self::by_name( $then ) );
		$b    = self::flat( self::by_name( json_decode( (string) wp_json_encode( self::data() ), true ) ) ); // same types as the stored copy
		$rows = array();
		foreach ( array_keys( $a + $b ) as $k ) {
			$x = array_key_exists( $k, $a ) ? $a[ $k ] : null;
			$y = array_key_exists( $k, $b ) ? $b[ $k ] : null;
			if ( $x !== $y ) {
				$rows[] = array( (string) $k, self::show( $x ), self::show( $y ) );
			}
		}
		return array( 'changes' => array_slice( $rows, 0, 300 ), 'total' => count( $rows ) );
	}

	/**
	 * Tokens keyed by name, so one added token is one change, not a shifted list.
	 *
	 * @param array $data Snapshot data.
	 * @return array
	 */
	private static function by_name( $data ) {
		if ( isset( $data['tokens'] ) && is_array( $data['tokens'] ) ) {
			$named = array();
			foreach ( $data['tokens'] as $t ) {
				if ( is_array( $t ) && isset( $t['name'] ) ) {
					$named[ (string) $t['name'] ] = array_diff_key( $t, array( 'name' => 1 ) );
				}
			}
			$data['tokens'] = $named;
		}
		return $data;
	}

	/**
	 * Nested array → { "a.b.c": scalar }.
	 *
	 * @param array  $arr Array.
	 * @param string $pre Key prefix.
	 * @return array
	 */
	private static function flat( $arr, $pre = '' ) {
		$out = array();
		foreach ( $arr as $k => $v ) {
			$key = '' === $pre ? (string) $k : $pre . '.' . $k;
			if ( is_array( $v ) && $v ) {
				$out += self::flat( $v, $key );
			} else {
				$out[ $key ] = is_array( $v ) ? '' : $v;
			}
		}
		return $out;
	}

	/**
	 * A value for the diff table.
	 *
	 * @param mixed $v Value.
	 * @return string|null Null = not set.
	 */
	private static function show( $v ) {
		if ( null === $v ) {
			return null;
		}
		if ( is_bool( $v ) ) {
			return $v ? 'on' : 'off';
		}
		$v = (string) $v;
		return mb_strlen( $v ) > 160 ? mb_substr( $v, 0, 160 ) . '...' : $v;
	}
}
