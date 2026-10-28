<?php
/**
 * Where the site uses each TokenForge data attribute (read only), like Token_Usage does for variables.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide data attribute report (R6, T4). Only attribute names and place labels leave the scanner.
 */
class Attribute_Usage {

	const OPTION = 'tokenforge_attribute_usage';

	/** Distinct names kept while scanning, and unknown names kept in the result (most used first). */
	const MAX_NAMES   = 1000;
	const MAX_UNKNOWN = 200;

	/** A name as an HTML attribute (after whitespace or a closing quote, or a builder-JSON-escaped line break or tab) or a builder "name":"..." entry. */
	const PATTERN = '/(?:\s|(?<=["\'])|\\\\[nrt]|\\\\?"name\\\\?"\s*:\s*\\\\?")(data-tf-[a-z0-9_-]{1,60}|data-scheme(?:-toggle)?)(?![a-z0-9_-])/i';

	/**
	 * The last scan, or [] when none ran yet.
	 *
	 * Shape: [ 'time' => int, 'v' => int (2 from 0.53.3; an older scan has none), 'posts' => int, 'places' => int, 'capped' => bool, 'unknownCapped' => bool,
	 *          'uses'    => [ 'data-tf-clamp' => [ 'count' => int, 'where' => string[] ] ],
	 *          'unknown' => [ 'data-tf-clmap' => [ 'count' => int, 'where' => string[], 'didYouMean' => string ] ] ].
	 * 'uses' holds the known names only (Attributes::all(), Attributes::others(), Attributes::OTHER and
	 * data-scheme / data-scheme-toggle); 'unknown' holds unrecognised data-tf-* names.
	 *
	 * @return array
	 */
	public static function last() {
		$u = get_option( self::OPTION, array() );
		return is_array( $u ) ? $u : array();
	}

	/**
	 * Look through the site (same sources as Token_Usage, plus widgets) and keep the result. Same shape as last().
	 * A name counts as an HTML attribute (after whitespace, or after a builder-JSON-escaped line break or
	 * tab, a backslash and n, r or t) or a builder "name":"..." entry; a [data-tf-x] selector in CSS is
	 * not counted. Text in pages counts too (prose that names an attribute is a use).
	 *
	 * @return array
	 */
	public static function scan() {
		$known  = self::known();
		$found  = array();
		$places = 0;
		$add    = function ( $text, $label ) use ( &$found, &$places ) {
			if ( false === stripos( $text, 'data-tf-' ) && false === stripos( $text, 'data-scheme' ) ) {
				return;
			}
			if ( ! preg_match_all( self::PATTERN, $text, $m ) ) {
				return;
			}
			$places++;
			foreach ( array_count_values( array_map( 'strtolower', $m[1] ) ) as $name => $n ) {
				if ( ! isset( $found[ $name ] ) ) {
					if ( count( $found ) >= self::MAX_NAMES ) {
						continue; // made-up names in prose must not grow the scan without end
					}
					$found[ $name ] = array(
						'count' => 0,
						'where' => array(),
					);
				}
				$found[ $name ]['count'] += $n;
				if ( count( $found[ $name ]['where'] ) < Token_Usage::WHERE && ! in_array( $label, $found[ $name ]['where'], true ) ) {
					$found[ $name ]['where'][] = $label;
				}
			}
		};

		$options = array(
			'bricks_global_classes'  => __( 'Global classes', 'tokenforge' ),
			'bricks_theme_styles'    => __( 'Theme styles', 'tokenforge' ),
			'bricks_global_settings' => __( 'Builder settings (custom CSS)', 'tokenforge' ),
			'bricks_components'      => __( 'Components', 'tokenforge' ),
			'bricks_global_elements' => __( 'Global elements', 'tokenforge' ),
			// Widgets too (the class scan reads them): the attribute rules a widget uses must survive "Leave out unused".
			'widget_block'           => __( 'Widgets', 'tokenforge' ),
			'widget_text'            => __( 'Widgets', 'tokenforge' ),
			'widget_custom_html'     => __( 'Widgets', 'tokenforge' ),
		);
		foreach ( $options as $option => $label ) {
			$v = get_option( $option );
			if ( $v ) {
				$add( (string) wp_json_encode( $v ), $label );
			}
		}

		list( $posts, $capped ) = Token_Usage::each_post(
			Token_Usage::SKIP_TYPES,
			function ( $post ) use ( $add ) {
				$text = (string) $post->post_content;
				foreach ( Token_Usage::META as $key ) {
					$v = get_post_meta( $post->ID, $key, true );
					if ( $v ) {
						$text .= ' ' . wp_json_encode( $v );
					}
				}
				$add( $text, self::label( $post ) );
			}
		);

		$uses = array();
		$unk  = array();
		foreach ( $found as $name => $row ) {
			if ( isset( $known[ $name ] ) ) {
				$uses[ $name ] = $row;
			} else {
				$row['didYouMean'] = '';
				$unk[ $name ]      = $row;
			}
		}
		$unk_capped = count( $unk ) > self::MAX_UNKNOWN;
		if ( $unk_capped ) {
			uasort(
				$unk,
				function ( $a, $b ) {
					return $b['count'] <=> $a['count'];
				}
			);
			$unk = array_slice( $unk, 0, self::MAX_UNKNOWN, true );
		}
		foreach ( Attributes::check_names( array_keys( $unk ) ) as $c ) {
			$unk[ $c['attribute'] ]['didYouMean'] = $c['didYouMean'];
		}

		$out = array(
			'time'    => time(),
			'v'       => 2, // 2: reads widgets and joins saved content, so Attributes::build() may prune by it (an older scan lacks this)
			'posts'   => $posts,
			'places'  => $places,
			'capped'  => $capped,
			'uses'    => $uses,
			'unknown' => $unk,
			'unknownCapped' => $unk_capped,
		);
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/**
	 * Saved content (Class_Usage::flush(), while unused classes are left out) uses a known name the last scan did not find:
	 * add it, so its CSS rules come back before the next scan.
	 *
	 * @param mixed $value Saved values (post content, builder data, widgets).
	 * @return bool True when a name was added.
	 */
	public static function join( $value ) {
		$last = self::last();
		$text = (string) wp_json_encode( $value );
		if ( empty( $last['time'] ) || ( false === stripos( $text, 'data-tf-' ) && false === stripos( $text, 'data-scheme' ) ) || ! preg_match_all( self::PATTERN, $text, $m ) ) {
			return false; // no scan (nothing is left out), or no name in the text
		}
		$known = self::known();
		$new   = false;
		foreach ( array_count_values( array_map( 'strtolower', $m[1] ) ) as $name => $n ) {
			if ( isset( $known[ $name ] ) && ! isset( $last['uses'][ $name ] ) ) {
				$last['uses'][ $name ] = array(
					'count' => $n,
					'where' => array(),
				);
				$new = true;
			}
		}
		if ( $new ) {
			update_option( self::OPTION, $last, false );
		}
		return $new;
	}

	/**
	 * The names the scan reports as known: lowercase name => index.
	 *
	 * @return array
	 */
	private static function known() {
		return array_flip( array_map( 'strtolower', array_merge( array_keys( Attributes::all() ), array_keys( Attributes::others() ), Attributes::OTHER, array( 'data-scheme', 'data-scheme-toggle' ) ) ) );
	}

	/**
	 * "Page: Home", "Template: Header" (same wording as Token_Usage, whose helper is private).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function label( $post ) {
		$type  = get_post_type_object( $post->post_type );
		$kind  = $type && isset( $type->labels->singular_name ) ? $type->labels->singular_name : $post->post_type;
		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		/* translators: 1: kind of content (Page, Template ...), 2: its title */
		return sprintf( __( '%1$s: %2$s', 'tokenforge' ), $kind, '' !== $title ? $title : '#' . $post->ID );
	}
}
