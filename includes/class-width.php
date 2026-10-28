<?php
/**
 * Content width + width scale.
 *
 * One setting, the site's content width, drives everything else:
 *   --content-width        the max width of page content
 *   --content-width-safe   the same, but never closer to the viewport edge than
 *                          the side gutter (safe for full-bleed sections)
 *   --width-10 … --width-90, --width-xs … --width-xxl
 *                          fractions of the content width
 * plus matching utilities (.width--*, .content-width, .content-width--safe).
 * Change the content width once and every dependent width follows.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Width variables + utilities.
 */
class Width {

	const OPTION = 'tokenforge_width';

	/** Percentage steps (of the content width). */
	const PERCENT = array( 10, 20, 30, 40, 50, 60, 70, 80, 90 );

	/** T-shirt steps → fraction of the content width. */
	const STEPS = array( 'xs' => 0.1, 's' => 0.2, 'm' => 0.4, 'l' => 0.6, 'xl' => 0.8, 'xxl' => 0.9 );

	/**
	 * Defaults. The content width falls back to the value older versions kept
	 * in the scales option, so upgrades keep the same width.
	 *
	 * @return array
	 */
	public static function defaults() {
		return \TokenForge\Factory::merge( 'width', self::builtin_defaults() );
	}

	/**
	 * Built-in defaults (the factory settings in data/defaults.json are laid over them).
	 *
	 * @return array
	 */
	public static function builtin_defaults() {
		$scales = get_option( Scale_Engine::OPTION, array() );
		$legacy = ( is_array( $scales ) && isset( $scales['content_width'] ) && is_numeric( $scales['content_width'] ) ) ? 0 + $scales['content_width'] : 1366;
		return array(
			'content_width' => $legacy, // px
			'variables'     => true,    // --width-* scale
			'utilities'     => true,    // .width--* classes
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Raw input.
	 * @return void
	 */
	public static function save_settings( $in ) {
		$d  = self::defaults();
		$cw = ( isset( $in['content_width'] ) && is_numeric( $in['content_width'] ) && $in['content_width'] > 0 ) ? 0 + $in['content_width'] : $d['content_width'];
		update_option(
			self::OPTION,
			array(
				'content_width' => min( 10000, $cw ),
				'variables'     => ! empty( $in['variables'] ),
				'utilities'     => ! empty( $in['utilities'] ),
			)
		);
	}

	/**
	 * Content width as a CSS length (rem).
	 *
	 * @param float|null $px Pixels (defaults to the saved setting).
	 * @return string
	 */
	public static function content_width_value( $px = null ) {
		if ( null === $px ) {
			$s  = self::settings();
			$px = $s['content_width'];
		}
		return rtrim( rtrim( sprintf( '%.4f', $px / Defaults::root_px() ), '0' ), '.' ) . 'rem';
	}

	/**
	 * Parse a CSS length (px or rem) to px. Returns null for anything else.
	 *
	 * @param string $value CSS value.
	 * @return float|null
	 */
	public static function to_px( $value ) {
		if ( preg_match( '/^\s*([0-9]*\.?[0-9]+)\s*(px|rem)\s*$/i', (string) $value, $m ) ) {
			return 'rem' === strtolower( $m[2] ) ? round( $m[1] * Defaults::root_px(), 2 ) : 0 + $m[1];
		}
		return null;
	}

	/**
	 * Generated variables as [{name, value, group}].
	 *
	 * @return array
	 */
	public static function variables() {
		$s   = self::settings();
		$out = array(
			array( 'name' => 'content-width', 'value' => self::content_width_value() ),
			array( 'name' => 'content-width-safe', 'value' => 'min(var(--content-width), calc(100% - var(--gutter, var(--content-gutter, 1.5rem)) * 2))' ),
		);
		if ( ! empty( $s['variables'] ) ) {
			foreach ( self::PERCENT as $p ) {
				$out[] = array( 'name' => 'width-' . $p, 'value' => 'calc(var(--content-width) * ' . ( $p / 100 ) . ')' );
			}
			foreach ( self::STEPS as $step => $f ) {
				$out[] = array( 'name' => 'width-' . $step, 'value' => 'calc(var(--content-width) * ' . $f . ')' );
			}
			$out[] = array( 'name' => 'width-full', 'value' => '100%' );
		}
		foreach ( $out as &$t ) {
			$t['group']   = 'space';
			$t['enabled'] = true;
		}
		return $out;
	}

	/**
	 * Utility CSS + class names.
	 *
	 * @return array [ 'css' => string, 'classes' => string[] ]
	 */
	public static function build() {
		$s = self::settings();
		if ( empty( $s['utilities'] ) ) {
			return array( 'css' => '', 'classes' => array() );
		}
		$css     = array();
		$classes = array();
		// Name → fraction. (Built as pairs: array_merge() would renumber 10…90.)
		$steps = array();
		foreach ( self::PERCENT as $p ) {
			$steps[] = array( $p, $p / 100 );
		}
		foreach ( self::STEPS as $name => $f ) {
			$steps[] = array( $name, $f );
		}
		// max-inline-size (not width) so these never overflow a small screen.
		foreach ( $steps as $pair ) {
			list( $name, $f ) = $pair;
			$css[]     = ".width--$name{inline-size:100%;max-inline-size:calc(var(--content-width) * $f)}";
			$classes[] = "width--$name";
		}
		$css[] = '.width--full{inline-size:100%;max-inline-size:100%}';
		$css[] = '.width--auto{inline-size:auto!important;max-inline-size:100%}';
		foreach ( array( 'min-content', 'max-content', 'fit-content' ) as $k ) {
			$css[]     = ".width--$k{inline-size:$k;max-inline-size:100%}";
			$classes[] = "width--$k";
		}
		$classes[] = 'width--full';
		$classes[] = 'width--auto';
		// Sized figures: let the image fill the figure.
		$css[] = 'figure[class*="width--"] img{inline-size:100%}';
		$css[]     = '.content-width{inline-size:100%;max-inline-size:min(var(--content-width), 100%);margin-inline:auto}';
		$css[]     = '.content-width--safe{inline-size:100%;max-inline-size:var(--content-width-safe);margin-inline:auto}';
		$classes[] = 'content-width';
		$classes[] = 'content-width--safe';

		return array(
			'css'     => "\n/* --- TokenForge width --- */\n" . implode( "\n", $css ) . "\n",
			'classes' => $classes,
		);
	}

	/**
	 * Stored tokens beat generated ones, so an imported/edited `content-width`
	 * token would silently ignore the setting. When the setting changes, carry
	 * the new value into that stored token.
	 *
	 * @param float $old_px Content width before this save.
	 * @return bool Whether a stored token was updated.
	 */
	public static function sync_stored_token( $old_px ) {
		$s = self::settings();
		if ( (float) $s['content_width'] === (float) $old_px ) {
			return false;
		}
		$settings = Settings::get_instance();
		$tokens   = $settings->get_tokens();
		foreach ( $tokens as $i => $t ) {
			if ( 'content-width' === $t['name'] ) {
				$tokens[ $i ]['value'] = self::content_width_value();
				$settings->save_tokens( $tokens );
				return true;
			}
		}
		return false;
	}

	/**
	 * After an import: adopt a stored `content-width` token (px/rem) as the
	 * setting so the dashboard shows the width actually in use.
	 *
	 * @return void
	 */
	public static function adopt_stored_token() {
		foreach ( Settings::get_instance()->get_tokens() as $t ) {
			if ( 'content-width' === $t['name'] ) {
				$px = self::to_px( $t['value'] );
				if ( $px ) {
					$s                  = self::settings();
					$s['content_width'] = $px;
					update_option( self::OPTION, $s );
				}
				return;
			}
		}
	}
}
