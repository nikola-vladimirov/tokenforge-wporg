<?php
/**
 * Factory settings: the setup a new site starts with and what "Reset to
 * defaults" returns to (data/defaults.json).
 *
 * Every settings module keeps its own built-in defaults; the values in the
 * data file replace them key by key (only keys the module knows, only values
 * of the same kind), so a setting added in a later version still gets its
 * built-in default. The file also holds the colour families; the starter
 * tokens are in data/default-tokens.json.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Factory settings from data/defaults.json.
 */
class Factory {

	/** The settings file, relative to the plugin folder. */
	const FILE = 'data/defaults.json';

	/** The starter tokens (read only on a fresh install or a reset). */
	const TOKENS_FILE = 'data/default-tokens.json';

	/** @var array|null Parsed settings (once per request). */
	private static $data = null;

	/**
	 * A JSON file of the plugin as an array ([] when missing or unreadable).
	 *
	 * @param string $rel Path relative to the plugin folder.
	 * @return array
	 */
	private static function read( $rel ) {
		$file = TOKENFORGE_DIR . $rel;
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
		return is_array( $json ) ? $json : array();
	}

	/**
	 * The settings file.
	 *
	 * @return array
	 */
	public static function data() {
		if ( null === self::$data ) {
			self::$data = self::read( self::FILE );
		}
		return self::$data;
	}

	/**
	 * One part of the data: a settings module, "palette", or "tokens" (from its own file).
	 *
	 * @param string $key Part.
	 * @return array|null
	 */
	public static function get( $key ) {
		if ( 'tokens' === $key ) {
			$t = self::read( self::TOKENS_FILE );
			return $t ? $t : null;
		}
		$d = self::data();
		return isset( $d[ $key ] ) && is_array( $d[ $key ] ) ? $d[ $key ] : null;
	}

	/**
	 * A module's built-in defaults with the factory values laid over them.
	 *
	 * @param string $key      Part (the module's option name without "tokenforge_").
	 * @param array  $defaults Built-in defaults.
	 * @return array
	 */
	public static function merge( $key, $defaults ) {
		$factory = self::get( $key );
		if ( ! $factory || ! is_array( $defaults ) ) {
			return $defaults;
		}
		foreach ( $defaults as $k => $builtin ) {
			if ( array_key_exists( $k, $factory ) ) {
				$v = self::same_kind( $builtin, $factory[ $k ] );
				if ( null !== $v ) {
					$defaults[ $k ] = $v;
				}
			}
		}
		return $defaults;
	}

	/**
	 * The factory value when it is the same kind as the built-in one, else null.
	 *
	 * @param mixed $builtin Built-in value.
	 * @param mixed $value   Factory value.
	 * @return mixed|null
	 */
	private static function same_kind( $builtin, $value ) {
		if ( is_bool( $builtin ) ) {
			return is_bool( $value ) ? $value : null;
		}
		if ( is_array( $builtin ) ) {
			return is_array( $value ) ? $value : null;
		}
		if ( is_int( $builtin ) ) {
			return is_numeric( $value ) ? (int) $value : null;
		}
		if ( is_float( $builtin ) ) {
			return is_numeric( $value ) ? (float) $value : null;
		}
		if ( is_string( $builtin ) ) {
			return is_string( $value ) || is_numeric( $value ) ? (string) $value : null;
		}
		return null === $builtin ? $value : null;
	}
}
