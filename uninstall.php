<?php
/**
 * Uninstall cleanup.
 *
 * Removes TokenForge options, the generated stylesheet, and the namespaced
 * entries TokenForge added to Bricks globals (identified by the tokenforge_
 * id prefix / tokenforge category), leaving the user's own Bricks data intact.
 *
 * @package TokenForge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything TokenForge stored on the current site (on a network, once per site).
 *
 * @return void
 */
function tokenforge_uninstall_site() {
	global $wpdb;
	// 1) Delete our own options.
	delete_option( 'tokenforge_tokens' );
	delete_option( 'tokenforge_settings' );
	delete_option( 'tokenforge_css_meta' );
	delete_option( 'tokenforge_acss_names' );
	foreach ( array( 'framework', 'scales', 'components', 'scheme', 'extras', 'defaults', 'content', 'width', 'motion', 'overlays', 'enhancements', 'scss', 'scss_build', 'scss_sync', 'recipes', 'palette', 'fonts', 'woo', 'modules', 'theme_sync', 'setup_pending', 'version', 'token_usage', 'class_usage', 'attribute_usage', 'builder_settings_backup', 'setup_wizard', 'history', 'form_sends', 'form_presets', 'subs_db', 'subs_base', 'subs_ack', 'subs_last' ) as $tf_opt ) {
		delete_option( 'tokenforge_' . $tf_opt );
	}
	// Form submissions (Features\Form_Submissions): our status/notes table and auto-delete.
	// The builder's own submissions table stays.
	wp_clear_scheduled_hook( 'tokenforge_subs_cleanup' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tokenforge_submissions" ); // phpcs:ignore WordPress.DB
	// Settings history snapshots (tokenforge_snap_<id>).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'tokenforge_snap_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// 2) Delete generated files.
	$up  = wp_get_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'tokenforge';
	if ( is_dir( $dir ) ) {
		foreach ( array( 'tokenforge.css', 'tokenforge-full.css', 'tokenforge-shop.css', 'index.php' ) as $f ) {
			$p = trailingslashit( $dir ) . $f;
			if ( file_exists( $p ) ) {
				wp_delete_file( $p );
			}
		}
		// Uploaded font files (tokenforge/fonts) stay: other CSS may still use them.
		// The folder itself goes only when it is empty.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem && ! $wp_filesystem->dirlist( $dir ) ) {
			$wp_filesystem->rmdir( $dir );
		}
	}

	// 3) Strip our namespaced entries from Bricks globals.
	$prefix = 'tokenforge_';

	// Global variables. (No default on get_option(): an option that was never there stays absent, so a network does not gain empty ones on sites without a builder.)
	$vars = get_option( 'bricks_global_variables' );
	if ( is_array( $vars ) ) {
		$vars = array_values(
			array_filter(
				$vars,
				function ( $v ) use ( $prefix ) {
					return empty( $v['id'] ) || 0 !== strpos( $v['id'], $prefix );
				}
			)
		);
		update_option( 'bricks_global_variables', $vars );
	}

	// Variable categories.
	$vcats = get_option( 'bricks_global_variables_categories' );
	if ( is_array( $vcats ) ) {
		$vcats = array_values(
			array_filter(
				$vcats,
				function ( $c ) {
					return empty( $c['id'] ) || 'tokenforge' !== $c['id'];
				}
			)
		);
		update_option( 'bricks_global_variables_categories', $vcats );
	}

	// Color palette.
	$palette = get_option( 'bricks_color_palette' );
	if ( is_array( $palette ) ) {
		$palette = array_values(
			array_filter(
				$palette,
				function ( $p ) {
					return empty( $p['id'] ) || 'tokenforge' !== $p['id'];
				}
			)
		);
		update_option( 'bricks_color_palette', $palette );
	}

	// Global classes we added (category tokenforge) + their category.
	$classes = get_option( 'bricks_global_classes' );
	if ( is_array( $classes ) ) {
		$classes = array_values(
			array_filter(
				$classes,
				function ( $c ) {
					return empty( $c['category'] ) || 'tokenforge' !== $c['category'];
				}
			)
		);
		update_option( 'bricks_global_classes', $classes );
	}
	$ccats = get_option( 'bricks_global_classes_categories' );
	if ( is_array( $ccats ) ) {
		$ccats = array_values(
			array_filter(
				$ccats,
				function ( $c ) {
					return empty( $c['id'] ) || 'tokenforge' !== $c['id'];
				}
			)
		);
		update_option( 'bricks_global_classes_categories', $ccats );
	}
}

/**
 * Remove everything TokenForge stored: every site of a network, or the one site.
 *
 * @return void
 */
function tokenforge_uninstall() {
	if ( is_multisite() ) {
		// shortcut: one request loops all sites, fine up to a few thousand; use WP-CLI or batches beyond that
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $tf_site ) {
			switch_to_blog( $tf_site );
			tokenforge_uninstall_site();
			restore_current_blog();
		}
	} else {
		tokenforge_uninstall_site();
	}
	// User meta is network wide, so once: form sends per user (Features\Form_Rules), submission column choices.
	delete_metadata( 'user', 0, 'tokenforge_form_sent', '', true );
	delete_metadata( 'user', 0, 'tokenforge_subs_cols', '', true );
}

tokenforge_uninstall();
