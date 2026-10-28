<?php
/**
 * Starter: the design system a fresh install begins with, and "reset to
 * defaults".
 *
 * A new site gets the factory setup from data/defaults.json (see Factory):
 * its tokens, colour families and every setting. Without that file it gets
 * TokenForge's own colour families (generated like any family: shades, hover,
 * dark mode, transparent steps), white and black. No import needed. The same
 * data is used when you reset tokens or settings from the dashboard.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Fresh-install defaults and resets.
 */
class Starter {

	/** Base colour of each family on a fresh install. */
	const FAMILIES = array(
		'primary'   => '#2b59c3',
		'secondary' => '#17a398',
		'tertiary'  => '#8b5cf6',
		'accent'    => '#f59e0b',
		'base'      => '#1f2937',
		'neutral'   => '#000000',
		'action'    => '#2b59c3',
		'success'   => '#16a34a',
		'danger'    => '#dc2626',
		'warning'   => '#d97706',
		'info'      => '#0284c7',
	);

	/** Option names that hold settings (not content): reset by "settings". */
	const SETTING_OPTIONS = array( 'settings', 'framework', 'scales', 'components', 'scheme', 'extras', 'defaults', 'content', 'width', 'motion', 'overlays', 'enhancements', 'woo', 'modules' );

	/** What a reset can cover. */
	const PARTS = array( 'settings', 'tokens', 'fonts' );

	/**
	 * Palette settings for a fresh install.
	 *
	 * @return array
	 */
	public static function palette() {
		$p       = Palette::defaults();
		$factory = Factory::get( 'palette' );
		if ( $factory && ! empty( $factory['families'] ) && is_array( $factory['families'] ) ) {
			return $p; // the factory colour families (data/defaults.json), already in the defaults
		}
		foreach ( self::FAMILIES as $name => $hex ) {
			$p['families'][ $name ] = array( 'hex' => $hex, 'alt' => '', 'on' => true );
		}
		return $p;
	}

	/**
	 * Tokens for a fresh install. The colour families are not tokens (Colors
	 * makes them from the palette); white and black, with their transparent
	 * steps, are.
	 *
	 * @return array
	 */
	public static function tokens() {
		// The factory tokens (data/defaults.json) when the plugin ships them.
		$factory = Factory::get( 'tokens' );
		if ( $factory ) {
			return array_values(
				array_filter(
					$factory,
					function ( $t ) {
						return is_array( $t ) && isset( $t['name'], $t['value'] );
					}
				)
			);
		}
		$tokens = array();
		foreach ( array( 'white' => array( 255, 255, 255 ), 'black' => array( 0, 0, 0 ) ) as $name => $rgb ) {
			$tokens[] = array( 'name' => $name, 'value' => 'white' === $name ? '#ffffff' : '#000000', 'group' => 'color', 'enabled' => true );
			for ( $i = 1; $i <= 9; $i++ ) {
				$tokens[] = array( 'name' => "$name-trans-" . ( $i * 10 ), 'value' => sprintf( 'rgba(%d, %d, %d, 0.%d)', $rgb[0], $rgb[1], $rgb[2], $i ), 'group' => 'color', 'enabled' => true );
			}
		}
		return $tokens;
	}

	/**
	 * First run: fill an empty site with the starter tokens and palette. Returns
	 * true when it did something (a real fresh install).
	 *
	 * @return bool
	 */
	public static function install() {
		$settings = Settings::get_instance();
		$existing = get_option( Settings::OPTION_TOKENS, null );
		// A site that has tokens, or colour families, is set up (a list with no
		// tokens left in it is fine: Colors and the settings make the rest).
		if ( ( is_array( $existing ) && count( $existing ) > 0 ) || ( is_array( $existing ) && is_array( get_option( Palette::OPTION, null ) ) ) ) {
			return false;
		}
		$settings->save_tokens( self::tokens() );
		update_option( Palette::OPTION, self::palette(), false );
		return true;
	}

	/**
	 * Reset parts of TokenForge to its defaults.
	 *
	 * settings: every switch and value (your fonts and the tokens
	 * stay); tokens: the starter tokens and palette; fonts: empty the font
	 * list (uploaded files stay in uploads/tokenforge/fonts).
	 *
	 * @param array $parts Parts (see PARTS).
	 * @return array The parts that were reset.
	 */
	public static function reset( $parts ) {
		$parts = array_values( array_intersect( self::PARTS, (array) $parts ) );
		if ( in_array( 'settings', $parts, true ) ) {
			foreach ( self::SETTING_OPTIONS as $o ) {
				delete_option( 'tokenforge_' . $o );
			}
			// Content that lives next to settings stays.
			Scss::save_settings( Scss::defaults() ); // the breakpoints
			Recipes::save_settings( Recipes::defaults() );
			$fonts = Modules::raw( array( Fonts::class, 'settings' ) );
			Fonts::save_settings( array_merge( Fonts::defaults(), array( 'fonts' => $fonts['fonts'] ) ) );
			$pal = Palette::settings();
			Palette::store( array_merge( Palette::defaults(), array( 'families' => $pal['families'] ) ) );
		}
		if ( in_array( 'tokens', $parts, true ) ) {
			update_option( Palette::OPTION, self::palette(), false );
			Settings::get_instance()->save_tokens( self::tokens() );
			delete_option( 'tokenforge_acss_names' );
		}
		if ( in_array( 'fonts', $parts, true ) ) {
			$fonts = Modules::raw( array( Fonts::class, 'settings' ) );
			Fonts::save_settings( array_merge( $fonts, array( 'fonts' => array() ) ) );
		}
		return $parts;
	}
}
