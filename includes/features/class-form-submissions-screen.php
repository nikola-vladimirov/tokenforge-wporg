<?php
/**
 * Form submissions: extensions of the builder's own "Form submissions" screen.
 *
 * Nothing here replaces or hides that screen. This class loads a script and a
 * stylesheet on it (search bar, detail dialog, read/unread, status, notes,
 * column choice, bulk buttons, export buttons), adds the unread badge to its
 * menu entry and, when switched on, a top-level menu item that points at it.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Only constructed in wp-admin, and only when the master switch is on.
 */
class Form_Submissions_Screen {

	/** The builder's page slug (kept: its own links and scripts use it). */
	const PAGE = 'bricks-form-submissions';

	/** Hook suffixes of that page (submenu of the builder menu, or top level for users without manage_options). */
	const HOOKS = array( 'bricks_page_bricks-form-submissions', 'toplevel_page_bricks-form-submissions' );

	/** Ajax action that saves the column choice. */
	const AJAX = 'tokenforge_subs_columns';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 99 );
		// ponytail: admin-ajax, not REST. This class is only built in wp-admin, so a REST route registered here would never exist on a REST request.
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'save_columns' ) );
	}

	// ------------------------------------------------------------------
	// Assets
	// ------------------------------------------------------------------

	/**
	 * Script, style and config, on the builder's submissions screen only.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, self::HOOKS, true ) || ! Form_Submissions::can() ) {
			return;
		}
		Form_Submissions::purge_orphans(); // the builder deletes rows without a hook; drop our status/note rows for them
		wp_enqueue_style( 'tokenforge-admin', TOKENFORGE_URL . 'assets/admin/admin.css', array(), TOKENFORGE_VERSION );
		wp_enqueue_style( 'tokenforge-submissions', TOKENFORGE_URL . 'assets/admin/submissions.css', array( 'tokenforge-admin' ), TOKENFORGE_VERSION );
		wp_enqueue_script( 'tokenforge-submissions', TOKENFORGE_URL . 'assets/admin/submissions.js', array( 'wp-i18n' ), TOKENFORGE_VERSION, true );
		wp_set_script_translations( 'tokenforge-submissions', 'tokenforge' );

		$form_id = isset( $_GET['form_id'] ) ? sanitize_key( wp_unslash( $_GET['form_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only screen parameter.
		$saved   = Form_Submissions::on( 'columns' ) ? $this->saved_columns( $form_id ) : array();
		$theme   = '';
		$all     = Settings::get_instance()->get_settings();
		if ( isset( $all['ui_theme'] ) ) {
			$theme = (string) $all['ui_theme'];
		}
		$config = array(
			'restUrl'    => esc_url_raw( rest_url( 'tokenforge/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'colsNonce'  => wp_create_nonce( self::AJAX ),
			'colsAction' => self::AJAX,
			'switches'   => array(
				'status'  => Form_Submissions::on( 'status' ),
				'columns' => Form_Submissions::on( 'columns' ),
				'edit'    => Form_Submissions::on( 'edit' ) && Form_Submissions::can_delete(),
				'export'  => Form_Submissions::on( 'export' ),
			),
			'canDelete'  => Form_Submissions::can_delete(),
			'uiTheme'    => in_array( $theme, array( 'flat', 'light' ), true ) ? $theme : 'bricks',
			'formId'     => $form_id,
			'unread'     => Form_Submissions::on( 'status' ) ? Form_Submissions::unread_count() : 0,
			'columns'    => $saved,
		);
		wp_add_inline_script( 'tokenforge-submissions', 'window.tfSubs = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	// ------------------------------------------------------------------
	// Column choice (per user, per form)
	// ------------------------------------------------------------------

	/**
	 * The current user's saved column list for a form.
	 *
	 * @param string $form_id Form ID.
	 * @return string[] Column keys in order; a leading "-" marks a hidden one. Empty = the builder's default.
	 */
	private function saved_columns( $form_id ) {
		if ( '' === $form_id ) {
			return array();
		}
		$all = get_user_meta( get_current_user_id(), Form_Submissions::COLS, true );
		return ( is_array( $all ) && isset( $all[ $form_id ] ) && is_array( $all[ $form_id ] ) ) ? array_values( $all[ $form_id ] ) : array();
	}

	/**
	 * Ajax: save (or, with an empty list, reset) the column choice of one form.
	 * POST nonce, form_id, columns (JSON list of keys; "-key" = hidden).
	 *
	 * @return void
	 */
	public function save_columns() {
		check_ajax_referer( self::AJAX, 'nonce' );
		if ( ! Form_Submissions::can() || ! Form_Submissions::on( 'columns' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'tokenforge' ) ), 403 );
		}
		$form_id = isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : '';
		if ( '' === $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Open one form first.', 'tokenforge' ) ), 400 );
		}
		$raw  = isset( $_POST['columns'] ) ? json_decode( (string) wp_unslash( $_POST['columns'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded, then every key sanitized below.
		$list = array();
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, 100 ) : array() as $k ) {
			$k    = (string) $k;
			$hide = 0 === strpos( $k, '-' );
			$k    = sanitize_key( $hide ? substr( $k, 1 ) : $k );
			if ( '' !== $k && ! in_array( $k, $list, true ) && ! in_array( '-' . $k, $list, true ) ) {
				$list[] = ( $hide ? '-' : '' ) . $k;
			}
		}
		$uid = get_current_user_id();
		$all = get_user_meta( $uid, Form_Submissions::COLS, true );
		$all = is_array( $all ) ? $all : array();
		if ( $list ) {
			$all[ $form_id ] = $list;
		} else {
			unset( $all[ $form_id ] );
		}
		update_user_meta( $uid, Form_Submissions::COLS, $all );
		wp_send_json_success( array( 'columns' => $list ) );
	}

	// ------------------------------------------------------------------
	// Menu: unread badge, optional top-level item
	// ------------------------------------------------------------------

	/**
	 * Whether the top-level item is wanted and the builder did not already make one.
	 *
	 * @return bool
	 */
	private function wants_top_item() {
		global $menu, $submenu;
		if ( ! Form_Submissions::on( 'top_menu' ) || ! Form_Submissions::can() ) {
			return false;
		}
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && self::PAGE === $item[2] ) {
				return false; // users without manage_options already get a top-level entry
			}
		}
		// No page without the builder's "Save form submissions" setting.
		foreach ( isset( $submenu['bricks'] ) ? (array) $submenu['bricks'] : array() as $item ) {
			if ( isset( $item[2] ) && self::PAGE === $item[2] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The slug of our own top-level item. The "admin.php?page=" form has no
	 * callback of its own: it is a plain link to the builder's page.
	 *
	 * @return string
	 */
	private function top_slug() {
		return 'admin.php?page=' . self::PAGE;
	}

	/**
	 * Top-level item and unread badge (priority 99: after the builder's own menu).
	 * The capability is plain 'read': can() already checks the role setting and the builder's own access. The builder's own menu stays highlighted on the page (checked on tokenforge.test).
	 *
	 * @return void
	 */
	public function menu() {
		global $menu, $submenu;
		if ( ! Form_Submissions::can() ) {
			return;
		}
		$title = __( 'Form submissions', 'tokenforge' );
		if ( $this->wants_top_item() ) {
			add_menu_page( $title, $title, 'read', $this->top_slug(), '', 'dashicons-feedback', 26 );
		}
		if ( ! Form_Submissions::on( 'status' ) ) {
			return;
		}
		$n = Form_Submissions::unread_count();
		if ( $n < 1 ) {
			return;
		}
		$badge = sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
			(int) $n,
			esc_html( number_format_i18n( $n ) )
		);
		// The builder's own entry (submenu, or top level for users without manage_options) and ours.
		$slugs = array( self::PAGE, $this->top_slug() );
		foreach ( (array) $menu as $i => $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], $slugs, true ) ) {
				$menu[ $i ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the documented way to badge a menu item.
			}
		}
		if ( isset( $submenu['bricks'] ) ) {
			foreach ( $submenu['bricks'] as $i => $item ) {
				if ( isset( $item[2] ) && self::PAGE === $item[2] ) {
					$submenu['bricks'][ $i ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				}
			}
		}
	}
}
