<?php
/**
 * Page check in the builder: the server lookups the check engine
 * (assets/builder/check-engine.js) needs, in one read-only request, and the
 * engine's enqueue. The checks themselves run in the builder; nothing is saved.
 *
 * @package TokenForge
 */

namespace TokenForge\Features;

use TokenForge\Attributes;
use TokenForge\Contrast;
use TokenForge\Platforms\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * POST tokenforge/v1/check and the builder script.
 */
class Check {

	/** Most entries read from one list per request. */
	const MAX = 200;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 13 );
	}

	/**
	 * The route (administrators; the REST nonce is checked by WordPress for cookie auth).
	 */
	public function routes() {
		register_rest_route(
			\TokenForge\Rest::NS,
			'/check',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => array( $this, 'lookup' ),
			)
		);
	}

	/**
	 * Media library alt texts, the nearest passing token per contrast failure and
	 * unknown data-tf-* names. Reads only.
	 *
	 * @param \WP_REST_Request $req { images: int[], contrast: [ { fg, bg, min } ], measure: [ { fg, bg, min, dark } ], dark: bool, attrs: string[] }.
	 * @return array { alts: { id: alt }, contrast: [ { value, ratio } | null ], measure: [ { fg, bg, ratio, pass } | null ], attrs: [ { attribute, didYouMean } ] }
	 */
	public function lookup( $req ) {
		return self::answer( (array) $req->get_json_params() );
	}

	/**
	 * The lookup itself (separate so the harness can call it without REST).
	 *
	 * @param array $in Request body.
	 * @return array
	 */
	public static function answer( array $in ) {
		$alts = array();
		foreach ( array_slice( (array) ( $in['images'] ?? array() ), 0, self::MAX ) as $id ) {
			$id = absint( $id );
			if ( $id && 'attachment' === get_post_type( $id ) ) {
				$alts[ $id ] = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
			}
		}

		$dark = ! empty( $in['dark'] );
		$con  = array();
		$ctx  = null; // the variable table, built once for all pairs.
		foreach ( array_slice( (array) ( $in['contrast'] ?? array() ), 0, self::MAX ) as $c ) {
			$c   = (array) $c;
			$fg  = self::color( $c['fg'] ?? '' );
			$bg  = self::color( $c['bg'] ?? '' );
			$min = min( 7.0, max( 3.0, (float) ( $c['min'] ?? Contrast::TEXT ) ) );
			$fix = ( '' !== $fg && '' !== $bg ) ? Contrast::nearest( $fg, $bg, $min, $dark, $ctx ) : null;
			$con[] = $fix ? array( 'value' => $fix['value'], 'ratio' => $fix['modes'][0]['ratio'] ) : null;
		}

		// A colour the person chose, measured on its background: [ { fg, bg, min, dark } ] in, [ pair | null ] out.
		$measured = array();
		$vars     = null;
		foreach ( array_slice( (array) ( $in['measure'] ?? array() ), 0, self::MAX ) as $c ) {
			$c   = (array) $c;
			$fg  = self::color( $c['fg'] ?? '' );
			$bg  = self::color( $c['bg'] ?? '' );
			$min = min( 7.0, max( 3.0, (float) ( $c['min'] ?? Contrast::TEXT ) ) );
			if ( '' === $fg || '' === $bg ) {
				$measured[] = null;
				continue;
			}
			$vars       = null === $vars ? ( is_array( $ctx ) ? $ctx[0] : Contrast::variables() ) : $vars;
			$measured[] = Contrast::pair( $fg, $bg, $min, ! empty( $c['dark'] ), $vars );
		}

		$names = array();
		foreach ( array_slice( (array) ( $in['attrs'] ?? array() ), 0, self::MAX ) as $n ) {
			if ( is_scalar( $n ) && strlen( (string) $n ) <= 64 ) {
				$names[] = sanitize_key( (string) $n );
			}
		}

		return array(
			'alts'     => (object) $alts,
			'contrast' => $con,
			'measure'  => $measured,
			'attrs'    => Attributes::check_names( $names ),
		);
	}

	/**
	 * A colour as the contrast engine reads it: var(--name), #hex or a colour
	 * function without nesting; '' for anything else.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function color( $v ) {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$v = trim( (string) $v );
		if ( strlen( $v ) > 80 ) {
			return '';
		}
		return preg_match( '/^(var\(--[a-z0-9-]+(?:\s*,\s*(?:var\(--[a-z0-9-]+\s*,\s*[#0-9a-z]+\)|[#0-9a-z]+))?\s*\)|#[0-9a-f]{3,8}|(rgba?|hsla?|oklch)\([0-9a-z.,%\s\/-]*\))$/i', $v ) ? $v : '';
	}

	/**
	 * The engine script, in the builder (administrators) only, next to the axe loader.
	 */
	public function enqueue() {
		if ( Bricks::is_active() && Bricks::is_builder_context() && current_user_can( 'manage_options' ) ) {
			wp_enqueue_script( 'tokenforge-check-engine', TOKENFORGE_URL . 'assets/builder/check-engine.js', array( 'wp-i18n', 'tokenforge-a11y-loader', 'tokenforge-panel' ), TOKENFORGE_VERSION, true );
			wp_set_script_translations( 'tokenforge-check-engine', 'tokenforge' );
		}
	}
}
