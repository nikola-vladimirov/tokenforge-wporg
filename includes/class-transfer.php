<?php
/**
 * Transfer: export all TokenForge settings and tokens to a JSON file and import
 * them on another site.
 *
 * Everything goes through the same sanitising as a normal Save: an imported file
 * can't store anything a user couldn't type into the dashboard.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Import / export.
 */
class Transfer {

	/** File format version. */
	const FORMAT = 1;

	/** Parts of an export. */
	const PARTS = array( 'tokens', 'palette', 'settings', 'modules', 'scss', 'recipes', 'fonts' );

	/**
	 * Settings modules: key in the file => class with settings() / save_settings().
	 *
	 * @return array
	 */
	private static function modules() {
		return array(
			'framework'    => Framework::class,
			'scales'       => Scale_Engine::class,
			'components'   => Components::class,
			'scheme'       => Scheme::class,
			'extras'       => Extras::class,
			'defaults'     => Defaults::class,
			'content'      => Content::class,
			'width'        => Width::class,
			'motion'       => Motion::class,
			'overlays'     => Overlays::class,
			'enhancements' => Features\Builder_Enhancements::class,
			'woo'          => Woo::class,
		);
	}

	/**
	 * Build the export.
	 *
	 * @return array
	 */
	public static function export() {
		return Modules::raw(
			function () {
				$settings = array( 'settings' => Settings::get_instance()->get_settings() );
				foreach ( self::modules() as $key => $class ) {
					$settings[ $key ] = $class::settings();
				}
				return array(
					'plugin'   => 'tokenforge',
					'format'   => self::FORMAT,
					'version'  => TOKENFORGE_VERSION,
					'exported' => gmdate( 'c' ),
					'site'     => home_url( '/' ),
					'data'     => array(
						'tokens'   => Settings::get_instance()->get_tokens(),
						'palette'  => Palette::settings(),
						'settings' => $settings,
						'modules'  => Modules::settings(),
						'scss'     => Scss::settings(),
						'recipes'  => Recipes::settings(),
						'fonts'    => Fonts::settings(),
					),
				);
			}
		);
	}

	/**
	 * Check a decoded export file.
	 *
	 * @param mixed $file Decoded JSON.
	 * @return string '' when fine, else the problem.
	 */
	public static function check( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['plugin'], $file['data'] ) || 'tokenforge' !== $file['plugin'] || ! is_array( $file['data'] ) ) {
			return 'This is not a TokenForge export file.';
		}
		if ( isset( $file['format'] ) && (int) $file['format'] > self::FORMAT ) {
			return 'This file was made by a newer TokenForge. Update TokenForge on this site first.';
		}
		return '';
	}

	/**
	 * Import an export file.
	 *
	 * @param array $file  Decoded JSON (checked with check()).
	 * @param array $parts Parts to import (see PARTS); others stay as they are.
	 * @return array The parts that were imported.
	 */
	public static function import( $file, $parts ) {
		$data  = $file['data'];
		$parts = array_values( array_intersect( self::PARTS, (array) $parts ) );
		$done  = array();
		foreach ( $parts as $part ) {
			if ( ! isset( $data[ $part ] ) || ! is_array( $data[ $part ] ) ) {
				continue;
			}
			$in = $data[ $part ];
			switch ( $part ) {
				case 'tokens':
					Settings::get_instance()->save_tokens( $in );
					break;
				case 'palette':
					Palette::store( $in );
					break;
				case 'settings':
					if ( isset( $in['settings'] ) && is_array( $in['settings'] ) ) {
						// Keep this site's own window shortcut etc. only if the file has none.
						Settings::get_instance()->save_settings( array_merge( Settings::get_instance()->get_settings(), $in['settings'] ) );
					}
					foreach ( self::modules() as $key => $class ) {
						if ( isset( $in[ $key ] ) && is_array( $in[ $key ] ) ) {
							$class::save_settings( array_merge( Modules::raw( array( $class, 'settings' ) ), $in[ $key ] ) );
						}
					}
					break;
				case 'modules':
					Modules::save_settings( $in );
					break;
				case 'scss':
					Scss::save_settings( array_merge( Modules::raw( array( Scss::class, 'settings' ) ), $in ) );
					break;
				case 'recipes':
					Recipes::save_settings( array_merge( Modules::raw( array( Recipes::class, 'settings' ) ), $in ) );
					break;
				case 'fonts':
					Fonts::save_settings( array_merge( Modules::raw( array( Fonts::class, 'settings' ) ), $in ) );
					break;
			}
			$done[] = $part;
		}
		// Files from before 0.37 hold every colour token; the families make those
		// now, so copies with the same value are left out.
		if ( in_array( 'tokens', $done, true ) ) {
			list( $tokens, $dropped ) = Palette::without_repeats( Settings::get_instance()->get_tokens() );
			if ( $dropped ) {
				Settings::get_instance()->save_tokens( $tokens );
			}
		}
		return $done;
	}
}
