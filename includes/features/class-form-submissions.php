<?php
/**
 * Form submissions: storage, access and auto-delete beside the builder's own
 * submissions table.
 *
 * The builder keeps every saved submission in {prefix}bricks_form_submissions
 * and shows them on its own Form submissions screen; that table and screen stay
 * as they are. TokenForge adds a small table keyed by the builder's submission
 * ID (status new/done/spam, a note, a read flag), a minimum role for every
 * TokenForge route, REST routes for the screen (Form_Submissions_Screen) and
 * the export (Form_Submissions_Export), and auto-delete after N days (site-wide
 * and per form) that never deletes before someone has seen its dry run.
 *
 * Switches (Components): subs_extend (all of it), subs_status, subs_columns,
 * subs_edit, subs_export, subs_top_menu, subs_role, subs_cleanup, subs_days.
 * Contract for the screen and the export: tokenforge-tools/notes/RF2-A.md.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Storage, access and auto-delete for form submissions.
 */
class Form_Submissions {

	/** Our table, without the prefix. */
	const TABLE = 'tokenforge_submissions';

	/** Option: schema version of our table. */
	const DB_OPTION = 'tokenforge_subs_db';

	/** Schema version. */
	const DB_VERSION = '2';

	/** Option: newest builder created_at (UTC) when TokenForge started tracking (those count as read). */
	const BASE = 'tokenforge_subs_base';

	/** Option: hash of the auto-delete rules someone saw in a dry run. */
	const ACK = 'tokenforge_subs_ack';

	/** Option: result of the last auto-delete run. */
	const LAST = 'tokenforge_subs_last';

	/** Cron hook for auto-delete. */
	const CRON = 'tokenforge_subs_cleanup';

	/** User meta: the screen's column choice per form (written by the screen). */
	const COLS = 'tokenforge_subs_cols';

	/** Statuses. */
	const STATUSES = array( 'new', 'done', 'spam' );

	/** Minimum role => the capability that stands for it. */
	const ROLES = array(
		'administrator' => 'manage_options',
		'editor'        => 'edit_others_posts',
		'author'        => 'publish_posts',
		'contributor'   => 'edit_posts',
	);

	/** Rows deleted per query, and queries per rule per run. */
	const BATCH = 500;
	const BATCHES = 20;

	/**
	 * Settings (Components).
	 *
	 * @var array
	 */
	private static $s = null;

	/**
	 * Hooks when switched on.
	 */
	public function __construct() {
		$s = self::settings();
		if ( empty( $s['subs_extend'] ) ) {
			if ( is_admin() && wp_next_scheduled( self::CRON ) ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return;
		}
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		if ( is_admin() && get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			add_action( 'admin_init', array( __CLASS__, 'install' ) );
		}
		if ( ! empty( $s['subs_cleanup'] ) ) {
			add_filter( 'bricks/elements/form/controls', array( $this, 'controls' ), 13 );
			add_action( self::CRON, array( __CLASS__, 'cron' ) );
			if ( is_admin() && ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
			}
		} elseif ( is_admin() && wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
		if ( is_admin() && class_exists( __NAMESPACE__ . '\\Form_Submissions_Screen' ) ) {
			new Form_Submissions_Screen();
		}
		if ( ! empty( $s['subs_export'] ) && class_exists( __NAMESPACE__ . '\\Form_Submissions_Export' ) ) {
			new Form_Submissions_Export();
		}
	}

	/**
	 * Switching auto-delete off forgets the confirmed rules, so switching it on
	 * again waits for a new dry run.
	 *
	 * @param mixed $old Old Components settings.
	 * @param mixed $new New Components settings.
	 * @return void
	 */
	public static function settings_saved( $old, $new ) {
		if ( empty( $new['subs_extend'] ) || empty( $new['subs_cleanup'] ) ) {
			delete_option( self::ACK );
		}
	}

	/**
	 * Settings, read once per request.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null === self::$s ) {
			self::$s = Components::settings();
		}
		return self::$s;
	}

	/**
	 * Whether a part is switched on (always needs subs_extend).
	 *
	 * @param string $part status | columns | edit | export | top_menu | cleanup.
	 * @return bool
	 */
	public static function on( $part ) {
		$s = self::settings();
		return ! empty( $s['subs_extend'] ) && ! empty( $s[ 'subs_' . $part ] );
	}

	// ------------------------------------------------------------------
	// Access
	// ------------------------------------------------------------------

	/**
	 * Capability of the minimum role setting (unknown values mean administrators).
	 *
	 * @return string
	 */
	public static function role_cap() {
		$s    = self::settings();
		$role = isset( $s['subs_role'] ) ? (string) $s['subs_role'] : '';
		return isset( self::ROLES[ $role ] ) ? self::ROLES[ $role ] : self::ROLES['administrator'];
	}

	/**
	 * The current user may use TokenForge's submission features: the minimum
	 * role from the setting AND the builder's own access to submissions.
	 *
	 * @return bool
	 */
	public static function can() {
		if ( ! is_user_logged_in() || ! current_user_can( self::role_cap() ) ) {
			return false;
		}
		if ( class_exists( '\Bricks\Capabilities' ) && method_exists( '\Bricks\Capabilities', 'current_user_can_form_submission_access' ) ) {
			return (bool) \Bricks\Capabilities::current_user_can_form_submission_access();
		}
		return current_user_can( 'bricks_form_submission_access' );
	}

	/**
	 * Deleting needs what the builder needs for it too (manage_options).
	 *
	 * @return bool
	 */
	public static function can_delete() {
		return self::can() && current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Tables
	// ------------------------------------------------------------------

	/**
	 * Our table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * The builder's submissions table name.
	 *
	 * @return string
	 */
	public static function bricks_table() {
		global $wpdb;
		return $wpdb->prefix . 'bricks_form_submissions';
	}

	/**
	 * Whether the builder's table exists (it does once "Save form submissions" was switched on).
	 *
	 * @return bool
	 */
	public static function ready() {
		static $ready = null;
		if ( null === $ready ) {
			global $wpdb;
			$t     = self::bricks_table();
			$ready = $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $ready;
	}

	/**
	 * Create or upgrade our table; remember where tracking starts.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  submission_id bigint(20) unsigned NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'new',
  is_read tinyint(1) NOT NULL DEFAULT 0,
  note text NULL,
  updated_at datetime NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NULL,
  PRIMARY KEY  (submission_id),
  KEY status (status)
) " . $wpdb->get_charset_collate() . ';'
		);
		$b = self::bricks_table();
		if ( self::ready() ) {
			// Our rows from schema 1 learn their builder row's created_at (a reused ID never matches it).
			$wpdb->query( "UPDATE {$t} t JOIN {$b} b ON b.id = t.submission_id SET t.created_at = b.created_at WHERE t.created_at IS NULL" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
		}
		if ( ! preg_match( '/^\d{4}-\d\d-\d\d /', (string) get_option( self::BASE, '' ) ) ) {
			// Submissions saved before TokenForge tracked them count as read, so the badge starts at 0.
			// A time, not an ID: the builder's Reset table starts IDs at 1 again.
			$max = self::ready() ? (string) $wpdb->get_var( "SELECT MAX(created_at) FROM {$b}" ) : ''; // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
			update_option( self::BASE, '' !== $max ? $max : gmdate( 'Y-m-d H:i:s' ), false );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Drop rows whose builder submission is gone (the builder fires no hook on delete).
	 *
	 * @return int Rows removed.
	 */
	public static function purge_orphans() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = self::table();
		$b = self::bricks_table();
		return (int) $wpdb->query( "DELETE t FROM {$t} t LEFT JOIN {$b} b ON b.id = t.submission_id AND b.created_at = t.created_at WHERE b.id IS NULL" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
	}

	// ------------------------------------------------------------------
	// Status, read flag, note
	// ------------------------------------------------------------------

	/**
	 * Meta for builder submission IDs. IDs without a row of ours: status new,
	 * read when saved before tracking started. A row of ours counts only while
	 * its created_at matches the builder's (the builder reuses IDs after a reset).
	 *
	 * @param int[] $ids Builder submission IDs.
	 * @return array id => array( status, read, note, updated_at, updated_by )
	 */
	public static function meta( $ids ) {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) ), 0, 1000 );
		if ( ! $ids ) {
			return array();
		}
		$base = (string) get_option( self::BASE, '' );
		$made = self::created( $ids );
		$out  = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'status'     => 'new',
				'read'       => ! isset( $made[ $id ] ) || $made[ $id ] <= $base,
				'note'       => '',
				'updated_at' => '',
				'updated_by' => 0,
			);
		}
		$in   = implode( ',', $ids ); // absint above.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE submission_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
		foreach ( (array) $rows as $r ) {
			$id = (int) $r['submission_id'];
			if ( ! isset( $made[ $id ] ) || (string) $r['created_at'] !== $made[ $id ] ) {
				continue; // left over from an older submission with the same ID
			}
			$out[ $id ] = array(
				'status'     => in_array( $r['status'], self::STATUSES, true ) ? $r['status'] : 'new',
				'read'       => (bool) $r['is_read'],
				'note'       => (string) $r['note'],
				'updated_at' => (string) $r['updated_at'],
				'updated_by' => (int) $r['updated_by'],
			);
		}
		return $out;
	}

	/**
	 * Change status, read flag and/or note of submissions that exist in the builder's table.
	 *
	 * @param int[] $ids    Builder submission IDs.
	 * @param array $fields Any of status (new|done|spam), read (bool), note (string).
	 * @return int Rows written.
	 */
	public static function set_meta( $ids, $fields ) {
		global $wpdb;
		$made = self::created( $ids );
		$ids  = array_keys( $made );
		if ( ! $ids ) {
			return 0;
		}
		$old = self::meta( $ids );
		$n   = 0;
		foreach ( $ids as $id ) {
			$was = $old[ $id ];
			$row = $was;
			if ( isset( $fields['status'] ) && in_array( $fields['status'], self::STATUSES, true ) ) {
				$row['status'] = $fields['status'];
			}
			if ( isset( $fields['read'] ) ) {
				$row['read'] = (bool) $fields['read'];
			}
			if ( isset( $fields['note'] ) ) {
				$row['note'] = self::clean_note( $fields['note'] );
			}
			// Opening an entry (read flag only) keeps who last changed status or note; no fields = an edit.
			$changed = ! $fields || $row['status'] !== $was['status'] || $row['note'] !== $was['note'];
			$n      += (int) $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'submission_id' => $id,
					'status'        => $row['status'],
					'is_read'       => $row['read'] ? 1 : 0,
					'note'          => $row['note'],
					'updated_at'    => $changed ? current_time( 'mysql', true ) : ( $was['updated_at'] ? $was['updated_at'] : null ),
					'updated_by'    => $changed ? get_current_user_id() : $was['updated_by'],
					'created_at'    => $made[ $id ],
				),
				array( '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
			) > 0 ? 1 : 0;
		}
		return $n;
	}

	/**
	 * A note: plain text, line breaks kept, at most 5000 characters.
	 *
	 * @param mixed $v Raw.
	 * @return string
	 */
	public static function clean_note( $v ) {
		return mb_substr( sanitize_textarea_field( (string) $v ), 0, 5000 );
	}

	/**
	 * Unread submissions (not spam) saved after tracking started.
	 * ponytail: one COUNT on every admin page for users who can; cache it if a site saves thousands a day.
	 *
	 * @return int
	 */
	public static function unread_count() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = self::table();
		$b = self::bricks_table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$b} b LEFT JOIN {$t} t ON t.submission_id = b.id AND t.created_at = b.created_at WHERE ( t.submission_id IS NULL AND b.created_at > %s ) OR ( t.is_read = 0 AND t.status <> 'spam' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) get_option( self::BASE, '' )
			)
		);
	}

	/**
	 * Of the given IDs, the ones in the builder's table.
	 *
	 * @param int[] $ids IDs.
	 * @return int[]
	 */
	public static function existing( $ids ) {
		return array_keys( self::created( $ids ) );
	}

	/**
	 * Of the given IDs (at most 1000), the ones in the builder's table, with their created_at.
	 *
	 * @param int[] $ids IDs.
	 * @return array id => created_at
	 */
	private static function created( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( ! $ids || ! self::ready() ) {
			return array();
		}
		$in  = implode( ',', array_slice( $ids, 0, 1000 ) ); // absint above.
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, created_at FROM ' . self::bricks_table() . " WHERE id IN ({$in})", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
			$out[ (int) $r['id'] ] = (string) $r['created_at'];
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Entries
	// ------------------------------------------------------------------

	/**
	 * Fields of a form as the builder saved them: id => array( label, type ).
	 *
	 * @param int    $post_id Post the form was sent from.
	 * @param string $form_id Form element ID.
	 * @return array
	 */
	public static function form_fields( $post_id, $form_id ) {
		static $memo = array();
		$key = $post_id . '|' . $form_id;
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		$out = array();
		$set = self::form_settings( $post_id, $form_id );
		foreach ( isset( $set['fields'] ) && is_array( $set['fields'] ) ? $set['fields'] : array() as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$out[ (string) $f['id'] ] = array(
					'label' => isset( $f['label'] ) && '' !== $f['label'] ? wp_strip_all_tags( (string) $f['label'] ) : (string) $f['id'],
					'type'  => isset( $f['type'] ) ? (string) $f['type'] : 'text',
				);
			}
		}
		$memo[ $key ] = $out;
		return $out;
	}

	/**
	 * The form element's settings (the builder's own lookup: page, template, global or component).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $form_id Form element ID.
	 * @return array
	 */
	public static function form_settings( $post_id, $form_id ) {
		if ( ! class_exists( '\Bricks\Integrations\Form\Submission_Database' ) || ! method_exists( '\Bricks\Integrations\Form\Submission_Database', 'get_form_settings' ) ) {
			return array();
		}
		$set = \Bricks\Integrations\Form\Submission_Database::get_form_settings( (int) $post_id, (string) $form_id );
		return is_array( $set ) ? $set : array();
	}

	/**
	 * One submission with its fields in form order and our meta.
	 *
	 * @param int $id Builder submission ID.
	 * @return array|null
	 */
	public static function entry( $id ) {
		$list = self::entries( array( (int) $id ) );
		return $list ? $list[0] : null;
	}

	/**
	 * Submissions by ID, newest first.
	 *
	 * @param int[] $ids IDs (at most 1000).
	 * @return array[] Each: id, form_id, form_name, post_id, post_title, created_at (UTC),
	 *                 created (site time, formatted), user_id, user, browser, os, ip, referrer,
	 *                 fields: list of array( id, label, type, value ), meta.
	 */
	public static function entries( $ids ) {
		global $wpdb;
		$ids = self::existing( $ids );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::bricks_table() . " WHERE id IN ({$in}) ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
		$meta = self::meta( $ids );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::shape( $r, $meta[ (int) $r['id'] ] );
		}
		return $out;
	}

	/**
	 * A builder row in the shape the routes return.
	 *
	 * @param array $r    Row of the builder's table.
	 * @param array $meta Our meta for it.
	 * @return array
	 */
	private static function shape( $r, $meta ) {
		$data   = json_decode( (string) $r['form_data'], true );
		$data   = is_array( $data ) ? $data : array();
		$known  = self::form_fields( (int) $r['post_id'], (string) $r['form_id'] );
		$fields = array();
		foreach ( array_unique( array_merge( array_keys( $known ), array_keys( $data ) ) ) as $fid ) {
			if ( ! isset( $data[ $fid ] ) ) {
				continue; // a field added to the form after this send
			}
			$type     = isset( $data[ $fid ]['type'] ) ? (string) $data[ $fid ]['type'] : ( isset( $known[ $fid ] ) ? $known[ $fid ]['type'] : 'text' );
			$value    = isset( $data[ $fid ]['value'] ) ? $data[ $fid ]['value'] : '';
			$fields[] = array(
				'id'    => (string) $fid,
				'label' => isset( $known[ $fid ] ) ? $known[ $fid ]['label'] : (string) $fid,
				'type'  => $type,
				'value' => 'file' === $type ? self::file_names( $value ) : ( 'password' === $type ? '' : $value ),
			);
		}
		$user = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : false;
		$ts   = strtotime( $r['created_at'] . ' UTC' );
		return array(
			'id'         => (int) $r['id'],
			'form_id'    => (string) $r['form_id'],
			'form_name'  => self::form_name( (string) $r['form_id'] ),
			'post_id'    => (int) $r['post_id'],
			'post_title' => $r['post_id'] ? html_entity_decode( get_the_title( (int) $r['post_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
			'created_at' => (string) $r['created_at'],
			'created'    => $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '',
			'user_id'    => (int) $r['user_id'],
			'user'       => $user ? $user->display_name : '',
			'browser'    => (string) $r['browser'],
			'os'         => (string) $r['os'],
			'ip'         => (string) $r['ip'],
			'referrer'   => (string) $r['referrer'],
			'fields'     => $fields,
			'meta'       => $meta,
		);
	}

	/**
	 * File field: the uploaded file names and links (never server paths).
	 *
	 * @param mixed $v Stored value.
	 * @return array[] Each: name, url.
	 */
	private static function file_names( $v ) {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $f ) {
			if ( is_array( $f ) ) {
				$out[] = array(
					'name' => isset( $f['name'] ) ? sanitize_file_name( (string) $f['name'] ) : '',
					'url'  => ! empty( $f['location'] ) && isset( $f['url'] ) ? esc_url_raw( (string) $f['url'] ) : '',
				);
			}
		}
		return $out;
	}

	/**
	 * The form's name as the builder shows it.
	 *
	 * @param string $form_id Form ID.
	 * @return string
	 */
	public static function form_name( $form_id ) {
		static $memo = array();
		if ( ! isset( $memo[ $form_id ] ) ) {
			$memo[ $form_id ] = class_exists( '\Bricks\Integrations\Form\Submission_Database' ) && method_exists( '\Bricks\Integrations\Form\Submission_Database', 'get_form_name_by_id' )
				? wp_strip_all_tags( (string) \Bricks\Integrations\Form\Submission_Database::get_form_name_by_id( $form_id ) )
				: '';
		}
		return $memo[ $form_id ];
	}

	/**
	 * Search submissions: one form or all, text in any value, status, read.
	 *
	 * @param array $args form_id, s, status (new|done|spam), read ('' | '1' | '0'), page, per_page (1..100).
	 * @return array total, page, per_page, items (entries()).
	 */
	public static function search( $args ) {
		global $wpdb;
		$per  = max( 1, min( 100, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$none = array( 'total' => 0, 'page' => $page, 'per_page' => $per, 'items' => array() );
		if ( ! self::ready() ) {
			return $none;
		}
		$b     = self::bricks_table();
		$t     = self::table();
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['form_id'] ) ) {
			$where[] = 'b.form_id = %s';
			$vals[]  = substr( sanitize_key( $args['form_id'] ), 0, 32 );
		}
		if ( isset( $args['s'] ) && '' !== trim( (string) $args['s'] ) ) {
			// The builder stores JSON, so search the JSON-encoded form of the words (accents and quotes match).
			$s       = trim( (string) wp_json_encode( sanitize_text_field( (string) $args['s'] ) ), '"' );
			$where[] = 'b.form_data LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( $s ) . '%';
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'new' === $status ? "( t.status IS NULL OR t.status = 'new' )" : 't.status = %s';
			if ( 'new' !== $status ) {
				$vals[] = $status;
			}
		}
		$read = isset( $args['read'] ) ? (string) $args['read'] : '';
		if ( '1' === $read || '0' === $read ) {
			$base    = (string) get_option( self::BASE, '' );
			$where[] = '1' === $read
				? '( t.is_read = 1 OR ( t.submission_id IS NULL AND b.created_at <= %s ) )'
				: '( t.is_read = 0 OR ( t.submission_id IS NULL AND b.created_at > %s ) )';
			$vals[]  = $base;
		}
		$sql_where = implode( ' AND ', $where );
		$from      = "{$b} b LEFT JOIN {$t} t ON t.submission_id = b.id AND t.created_at = b.created_at";
		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$sql_where}";
		$ids_sql   = "SELECT b.id FROM {$from} WHERE {$sql_where} ORDER BY b.id DESC LIMIT %d OFFSET %d";
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, values prepared
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( $ids_sql, array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ) );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'items'    => self::entries( $ids ),
		);
	}

	/**
	 * Change field values of one submission. Only fields it already has; file
	 * and password fields stay as they are; values pass the builder's own
	 * sanitizing for their type.
	 *
	 * @param int   $id     Builder submission ID.
	 * @param array $values field id => new value (string, or list of strings for checkboxes).
	 * @return true|\WP_Error
	 */
	public static function update_entry( $id, $values ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! self::existing( array( $id ) ) ) {
			return new \WP_Error( 'tokenforge_no_submission', __( 'That submission does not exist.', 'tokenforge' ), array( 'status' => 404 ) );
		}
		$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT form_data FROM ' . self::bricks_table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'tokenforge_bad_submission', __( 'That submission cannot be read.', 'tokenforge' ), array( 'status' => 500 ) );
		}
		$changed = array();
		foreach ( (array) $values as $fid => $v ) {
			$fid = (string) $fid;
			if ( ! isset( $data[ $fid ] ) || ! is_array( $data[ $fid ] ) ) {
				continue;
			}
			$type = isset( $data[ $fid ]['type'] ) ? (string) $data[ $fid ]['type'] : 'text';
			if ( in_array( $type, array( 'file', 'password' ), true ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$v = array_values( array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
			} elseif ( is_scalar( $v ) ) {
				$v = (string) $v;
			} else {
				continue;
			}
			if ( is_array( $v ) && ! in_array( $type, array( 'checkbox', 'select' ), true ) ) {
				$v = implode( ', ', $v );
			}
			$changed[ $fid ] = array( 'type' => $type, 'value' => $v );
		}
		if ( ! $changed ) {
			return true;
		}
		$changed = self::sanitize_values( $changed );
		foreach ( $changed as $fid => $field ) {
			$data[ $fid ]['value'] = $field['value'];
		}
		$ok = $wpdb->update( self::bricks_table(), array( 'form_data' => wp_json_encode( $data ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) {
			return new \WP_Error( 'tokenforge_save_failed', __( 'The submission could not be saved.', 'tokenforge' ), array( 'status' => 500 ) );
		}
		self::set_meta( array( $id ), array() ); // records who changed it and when
		return true;
	}

	/**
	 * The builder's sanitizing per field type (ours, the same rules, when it is missing).
	 *
	 * @param array $data field id => array( type, value ).
	 * @return array
	 */
	public static function sanitize_values( $data ) {
		if ( class_exists( '\Bricks\Integrations\Form\Submission_Database' ) && method_exists( '\Bricks\Integrations\Form\Submission_Database', 'sanitize_form_data' ) ) {
			return \Bricks\Integrations\Form\Submission_Database::sanitize_form_data( $data );
		}
		foreach ( $data as $fid => $f ) {
			$v = $f['value'];
			switch ( $f['type'] ) {
				case 'email':
					$v = sanitize_email( trim( $v ) );
					break;
				case 'url':
					$v = esc_url_raw( trim( $v ) );
					break;
				case 'number':
					$v = is_numeric( $v ) ? +$v : '';
					break;
				case 'textarea':
					$v = wp_kses_post( trim( $v ) );
					break;
				default:
					$v = is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
			}
			$data[ $fid ]['value'] = $v;
		}
		return $data;
	}

	// ------------------------------------------------------------------
	// Auto-delete
	// ------------------------------------------------------------------

	/**
	 * Per-form number on the builder's form, in its Save submission group.
	 *
	 * @param array $controls Form controls.
	 * @return array
	 */
	public function controls( $controls ) {
		$controls['tfSubsDays'] = array(
			'tab'         => 'content',
			'group'       => 'save-submission',
			'label'       => esc_html__( 'Delete saved submissions after (days)', 'tokenforge' ),
			'type'        => 'number',
			'min'         => 0,
			'placeholder' => esc_html__( 'Site setting', 'tokenforge' ),
			'description' => esc_html__( 'Empty: the number in TokenForge > Form submissions. 0: keep this form\'s submissions. Nothing is deleted until a dry run of the new rules was looked at in TokenForge.', 'tokenforge' ),
		);
		return $controls;
	}

	/**
	 * A form's own number of days, or null when it uses the site-wide one.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $form_id Form ID.
	 * @return int|null
	 */
	private static function own_days( $post_id, $form_id ) {
		$set = self::form_settings( $post_id, $form_id );
		if ( isset( $set['tfSubsDays'] ) && '' !== $set['tfSubsDays'] && is_numeric( $set['tfSubsDays'] ) ) {
			return max( 0, min( 36500, (int) $set['tfSubsDays'] ) );
		}
		return null;
	}

	/**
	 * Site-wide days.
	 *
	 * @return int
	 */
	private static function site_days() {
		$s = self::settings();
		return max( 0, min( 36500, (int) ( isset( $s['subs_days'] ) ? $s['subs_days'] : 0 ) ) );
	}

	/**
	 * The auto-delete rules in force: one per form (and post) that has saved
	 * submissions and a number of days above 0.
	 *
	 * @param bool $keep Also list forms whose own number is 0 (keep).
	 * @return array[] Each: form_id, post_id, days, own (the form's own number). Sorted, so the hash is stable.
	 */
	public static function rules( $keep = false ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$site  = self::site_days();
		$pairs = $wpdb->get_results( 'SELECT DISTINCT form_id, post_id FROM ' . self::bricks_table() . ' ORDER BY form_id, post_id', ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
		$rules = array();
		foreach ( (array) $pairs as $p ) {
			$own  = self::own_days( (int) $p['post_id'], (string) $p['form_id'] );
			$days = null === $own ? $site : $own;
			if ( $days > 0 || ( $keep && null !== $own ) ) {
				$rules[] = array(
					'form_id' => (string) $p['form_id'],
					'post_id' => (int) $p['post_id'],
					'days'    => $days,
					'own'     => null !== $own,
				);
			}
		}
		return $rules;
	}

	/**
	 * Whether a rule's setting was confirmed after a dry run. The confirmation
	 * covers the settings (site-wide days, each form's own number), not which
	 * forms have rows today, so a form emptied by auto-delete or a new form
	 * that uses the site-wide days does not stop the daily run.
	 *
	 * @param array $r One of rules().
	 * @return bool
	 */
	private static function confirmed( $r ) {
		$ack = get_option( self::ACK );
		if ( ! is_array( $ack ) || ! isset( $ack['site'], $ack['own'] ) ) {
			return false;
		}
		if ( $r['own'] ) {
			return in_array( $r['form_id'] . '|' . $r['post_id'] . '|' . $r['days'], (array) $ack['own'], true );
		}
		// A form that had its own number (0 included) and lost it waits: the site days were never shown for it.
		$pinned = isset( $ack['pinned'] ) ? (array) $ack['pinned'] : array();
		return (int) $ack['site'] === (int) $r['days'] && ! in_array( $r['form_id'] . '|' . $r['post_id'], $pinned, true );
	}

	/**
	 * Hash of a rule set.
	 *
	 * @param array $rules rules().
	 * @return string
	 */
	public static function rules_hash( $rules ) {
		return md5( (string) wp_json_encode( $rules ) );
	}

	/**
	 * Dry run: what auto-delete would remove now. Deletes nothing.
	 *
	 * @return array hash, approved (this rule set was seen and confirmed), total,
	 *               items (form_id, form_name, post_id, post_title, days, count, oldest), last.
	 */
	public static function dry_run() {
		global $wpdb;
		$now   = time();
		$rules = self::rules();
		$items = array();
		$total = 0;
		foreach ( $rules as $r ) {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
				$wpdb->prepare(
					'SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM ' . self::bricks_table() . ' WHERE form_id = %s AND post_id = %d AND created_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$r['form_id'],
					$r['post_id'],
					self::cutoff( $r['days'], $now )
				),
				ARRAY_A
			);
			$n      = $row ? (int) $row['n'] : 0;
			$total += $n;
			$items[] = array(
				'form_id'    => $r['form_id'],
				'form_name'  => self::form_name( $r['form_id'] ),
				'post_id'    => $r['post_id'],
				'post_title' => $r['post_id'] ? html_entity_decode( get_the_title( $r['post_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
				'days'       => $r['days'],
				'count'      => $n,
				'oldest'     => $row && $row['oldest'] ? (string) $row['oldest'] : '',
			);
		}
		$hash = self::rules_hash( $rules );
		return array(
			'hash'     => $hash,
			'time'     => $now,
			'approved' => (bool) $rules && count( array_filter( $rules, array( __CLASS__, 'confirmed' ) ) ) === count( $rules ),
			'total'    => $total,
			'items'    => $items,
			'last'     => get_option( self::LAST, null ),
		);
	}

	/**
	 * Created before this time (UTC, the builder's created_at) is older than N days.
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private static function cutoff( $days, $now = 0 ) {
		return gmdate( 'Y-m-d H:i:s', ( $now ? $now : time() ) - (int) $days * DAY_IN_SECONDS );
	}

	/**
	 * Confirm a rule set from its dry run and delete now. Refused when the
	 * rules changed since that dry run.
	 *
	 * @param string $hash Hash shown with the dry run.
	 * @param int    $time Time of that dry run: rows that crossed the cutoff since stay (never later than now).
	 * @return array|\WP_Error deleted, hash.
	 */
	public static function confirm( $hash, $time = 0 ) {
		$rules = self::rules();
		if ( ! $rules ) {
			return new \WP_Error( 'tokenforge_no_rules', __( 'No form has a number of days set, so nothing is deleted.', 'tokenforge' ), array( 'status' => 400 ) );
		}
		$now = self::rules_hash( $rules );
		if ( ! hash_equals( $now, (string) $hash ) ) {
			return new \WP_Error( 'tokenforge_rules_changed', __( 'The rules changed since that dry run. Look at the new one first.', 'tokenforge' ), array( 'status' => 409 ) );
		}
		$own    = array();
		$pinned = array();
		foreach ( self::rules( true ) as $r ) {
			if ( $r['own'] ) {
				$pinned[] = $r['form_id'] . '|' . $r['post_id'];
				if ( $r['days'] > 0 ) {
					$own[] = $r['form_id'] . '|' . $r['post_id'] . '|' . $r['days'];
				}
			}
		}
		update_option( self::ACK, array( 'site' => self::site_days(), 'own' => $own, 'pinned' => $pinned ), false );
		$n = self::delete_old( $rules, $time > 0 ? min( (int) $time, time() ) : 0 );
		update_option( self::LAST, array( 'time' => time(), 'deleted' => $n, 'waiting' => false ), false );
		return array(
			'deleted' => $n,
			'hash'    => $now,
		);
	}

	/**
	 * Daily run: deletes only under rules someone confirmed after a dry run;
	 * a changed or new number waits for the next dry run.
	 *
	 * @return void
	 */
	public static function cron() {
		if ( ! self::on( 'cleanup' ) ) {
			return;
		}
		$rules = self::rules();
		if ( ! $rules ) {
			return;
		}
		$ok = array_values( array_filter( $rules, array( __CLASS__, 'confirmed' ) ) );
		$n  = $ok ? self::delete_old( $ok ) : 0;
		update_option( self::LAST, array( 'time' => time(), 'deleted' => $n, 'waiting' => count( $ok ) < count( $rules ) ), false );
	}

	/**
	 * Delete rows older than each rule's days, in batches, then our orphans.
	 *
	 * @param array $rules rules().
	 * @return int Rows deleted from the builder's table.
	 */
	private static function delete_old( $rules, $now = 0 ) {
		global $wpdb;
		$n = 0;
		foreach ( $rules as $r ) {
			$cut = self::cutoff( $r['days'], $now );
			for ( $i = 0; $i < self::BATCHES; $i++ ) {
				$d  = (int) $wpdb->query( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from the prefix, IDs through absint
					$wpdb->prepare(
						'DELETE FROM ' . self::bricks_table() . ' WHERE form_id = %s AND post_id = %d AND created_at < %s ORDER BY id LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$r['form_id'],
						$r['post_id'],
						$cut,
						self::BATCH
					)
				);
				$n += $d;
				if ( $d < self::BATCH ) {
					break;
				}
			}
		}
		self::purge_orphans();
		return $n;
	}

	// ------------------------------------------------------------------
	// REST
	// ------------------------------------------------------------------

	/**
	 * Routes under tokenforge/v1/submissions. Every one checks can() (minimum
	 * role + the builder's own access); auto-delete also needs manage_options.
	 *
	 * @return void
	 */
	public function routes() {
		$ns     = 'tokenforge/v1';
		$can    = array( __CLASS__, 'can' );
		$status = function () {
			return self::can() && self::on( 'status' );
		};
		$edit   = function () {
			return self::can_delete() && self::on( 'edit' ); // overwrites the visitor's answers, like deleting
		};
		$clean  = function () {
			return self::can_delete() && self::on( 'cleanup' );
		};
		$id_arg = array(
			'id' => array(
				'validate_callback' => function ( $v ) {
					return is_numeric( $v ) && (int) $v > 0;
				},
			),
		);
		register_rest_route(
			$ns,
			'/submissions',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'args'                => array(
					'form_id'  => array( 'type' => 'string' ),
					's'        => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string', 'enum' => array_merge( array( '' ), self::STATUSES ) ),
					'read'     => array( 'type' => 'string', 'enum' => array( '', '0', '1' ) ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'callback'            => function ( $req ) {
					return rest_ensure_response( self::search( $req->get_params() ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/submissions/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'args'                => $id_arg,
					'callback'            => function ( $req ) {
						$e = self::entry( (int) $req['id'] );
						return $e ? rest_ensure_response( $e ) : new \WP_Error( 'tokenforge_no_submission', __( 'That submission does not exist.', 'tokenforge' ), array( 'status' => 404 ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $edit,
					'args'                => $id_arg,
					'callback'            => function ( $req ) {
						$values = $req->get_param( 'values' );
						$r      = self::update_entry( (int) $req['id'], is_array( $values ) ? $values : array() );
						return is_wp_error( $r ) ? $r : rest_ensure_response( self::entry( (int) $req['id'] ) );
					},
				),
			)
		);
		register_rest_route(
			$ns,
			'/submissions/meta',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => function ( $req ) {
						return rest_ensure_response( (object) self::meta( wp_parse_id_list( (string) $req->get_param( 'ids' ) ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $status,
					'callback'            => function ( $req ) {
						$ids    = wp_parse_id_list( $req->get_param( 'ids' ) );
						$fields = array();
						foreach ( array( 'status', 'read', 'note' ) as $k ) {
							if ( null !== $req->get_param( $k ) ) {
								$fields[ $k ] = 'read' === $k ? rest_sanitize_boolean( $req->get_param( $k ) ) : $req->get_param( $k );
							}
						}
						if ( isset( $fields['status'] ) && ! in_array( $fields['status'], self::STATUSES, true ) ) {
							return new \WP_Error( 'tokenforge_bad_status', __( 'Unknown status.', 'tokenforge' ), array( 'status' => 400 ) );
						}
						if ( count( $ids ) > 1 && isset( $fields['note'] ) ) {
							unset( $fields['note'] ); // a note belongs to one submission
						}
						self::set_meta( $ids, $fields );
						return rest_ensure_response(
							array(
								'meta'   => (object) self::meta( $ids ),
								'unread' => self::unread_count(),
							)
						);
					},
				),
			)
		);
		register_rest_route(
			$ns,
			'/submissions/cleanup',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $clean,
					'callback'            => function () {
						return rest_ensure_response( self::dry_run() );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $clean,
					'callback'            => function ( $req ) {
						$r = self::confirm( (string) $req->get_param( 'hash' ), (int) $req->get_param( 'time' ) );
						return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
					},
				),
			)
		);
	}
}
