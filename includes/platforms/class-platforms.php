<?php
/**
 * Which page builders are on the site, and what TokenForge can do with each.
 *
 * Detection only reads what is already loaded (constants, the theme) and
 * whether a builder's main file exists. No queries.
 *
 * @package TokenForge
 */

namespace TokenForge\Platforms;

defined( 'ABSPATH' ) || exit;

/**
 * Builder registry for the dashboard.
 */
class Platforms {

	/**
	 * What each integration supports. A dashboard setting marked `needs: '<key>'`
	 * only shows when the active integration lists that key.
	 */
	const CAPS = array(
		'bricks' => array( 'tokens_native', 'canvas_css', 'class_complete', 'class_sync', 'theme_style', 'hide_elements', 'canvas_tools', 'context_menus', 'templates', 'editor_tweaks', 'form_extras' ),
	);

	/**
	 * The builders TokenForge knows, in priority order.
	 *
	 * @return array[] Each: id, label, version, active, installed, supported.
	 */
	public static function detected() {
		$bd_mode = defined( 'BREAKDANCE_MODE' ) ? (string) constant( 'BREAKDANCE_MODE' ) : '';
		$bd_ver  = defined( '__BREAKDANCE_VERSION' ) ? (string) constant( '__BREAKDANCE_VERSION' ) : '';
		$et_ver  = defined( 'ET_CORE_VERSION' ) ? (string) constant( 'ET_CORE_VERSION' ) : '';
		$plugin  = function ( $file ) {
			return file_exists( WP_PLUGIN_DIR . '/' . $file );
		};
		$rows    = array(
			array( 'bricks', 'Bricks', Bricks::is_active(), defined( 'BRICKS_VERSION' ) ? (string) constant( 'BRICKS_VERSION' ) : '', wp_get_theme( 'bricks' )->exists() ),
			// Etch has no version constant: read its plugin header.
			array( 'etch', 'Etch', defined( 'ETCH_PLUGIN_FILE' ), defined( 'ETCH_PLUGIN_FILE' ) ? (string) get_file_data( (string) constant( 'ETCH_PLUGIN_FILE' ), array( 'v' => 'Version' ) )['v'] : '', $plugin( 'etch/etch.php' ) ),
			// Part of WordPress; off only when the Classic Editor plugin is active.
			array( 'blocks', __( 'Block editor', 'tokenforge' ), ! class_exists( 'Classic_Editor' ), get_bloginfo( 'version' ), true ),
			// Oxygen 6 and Breakdance share one code base and cannot run together.
			array( 'oxygen', 'Oxygen', 'oxygen' === $bd_mode, 'oxygen' === $bd_mode ? $bd_ver : '', $plugin( 'oxygen/plugin.php' ) ),
			array( 'breakdance', 'Breakdance', 'breakdance' === $bd_mode, 'breakdance' === $bd_mode ? $bd_ver : '', $plugin( 'breakdance/plugin.php' ) ),
			// Divi 5 only (older Divi is not supported).
			array( 'divi', 'Divi', '' !== $et_ver && version_compare( $et_ver, '5.0', '>=' ), $et_ver, wp_get_theme( 'Divi' )->exists() || $plugin( 'divi-builder/divi-builder.php' ) ),
			array( 'elementor', 'Elementor', defined( 'ELEMENTOR_VERSION' ), defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '', $plugin( 'elementor/elementor.php' ) ),
		);
		$out     = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'        => $r[0],
				'label'     => $r[1],
				'active'    => (bool) $r[2],
				'version'   => $r[3],
				'installed' => $r[2] || $r[4],
				'supported' => isset( self::CAPS[ $r[0] ] ),
			);
		}
		return $out;
	}

	/**
	 * The integration that drives the Builder screen: the first active, supported one.
	 * ponytail: one screen; a screen per builder once a second integration exists.
	 *
	 * @param array[]|null $detected From detected().
	 * @return array|null
	 */
	public static function active( $detected = null ) {
		foreach ( null === $detected ? self::detected() : $detected as $p ) {
			if ( $p['active'] && $p['supported'] ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Capabilities of the active integration.
	 *
	 * @param array[]|null $detected From detected().
	 * @return string[]
	 */
	public static function caps( $detected = null ) {
		$p = self::active( $detected );
		return $p ? self::CAPS[ $p['id'] ] : array();
	}
}
