<?php
/**
 * Where the site uses each variable: pages, posts, templates and the rest
 * made with the builder or the block editor, and the builder's classes, theme
 * styles, components, global elements and custom CSS. It only reads; the last
 * result is kept so the Tokens screen can show it until the next scan.
 *
 * The builder's storage keys are its own constants (see its functions.php):
 * _bricks_page_header_2, _bricks_page_content_2, _bricks_page_footer_2,
 * _bricks_page_settings (post meta), bricks_global_classes,
 * bricks_theme_styles, bricks_global_settings, bricks_components,
 * bricks_global_elements (options).
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * var(--name) uses across the site's content.
 */
class Token_Usage {

	const OPTION = 'tokenforge_token_usage';

	/** Post meta the builder keeps a page's elements and settings in. */
	const META = array( '_bricks_page_header_2', '_bricks_page_content_2', '_bricks_page_footer_2', '_bricks_page_settings' );

	/** Post types without content of their own. */
	const SKIP_TYPES = array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' );

	/** Most posts one scan reads (a very large site stops there and says so). */
	const MAX_POSTS = 5000;

	/** Places named per variable. */
	const WHERE = 5;

	/**
	 * The last scan: [ time, posts, places, capped, uses => name => [ count, where[] ] ], or [].
	 *
	 * @return array
	 */
	public static function last() {
		$u = get_option( self::OPTION, array() );
		return is_array( $u ) ? $u : array();
	}

	/**
	 * Look through the site and keep the result.
	 *
	 * @return array See last().
	 */
	public static function scan() {
		$uses   = array();
		$places = 0;
		$add    = function ( $text, $label ) use ( &$uses, &$places ) {
			if ( false === strpos( $text, 'var(' ) || ! preg_match_all( '/var\(\s*--([a-z0-9-]+)/i', $text, $m ) ) {
				return;
			}
			$places++;
			foreach ( array_count_values( array_map( 'strtolower', $m[1] ) ) as $name => $n ) {
				if ( ! isset( $uses[ $name ] ) ) {
					$uses[ $name ] = array(
						'count' => 0,
						'where' => array(),
					);
				}
				$uses[ $name ]['count'] += $n;
				if ( count( $uses[ $name ]['where'] ) < self::WHERE && ! in_array( $label, $uses[ $name ]['where'], true ) ) {
					$uses[ $name ]['where'][] = $label;
				}
			}
		};

		// The builder's site-wide data.
		$options = array(
			'bricks_global_classes'  => __( 'Global classes', 'tokenforge' ),
			'bricks_theme_styles'    => __( 'Theme styles', 'tokenforge' ),
			'bricks_global_settings' => __( 'Builder settings (custom CSS)', 'tokenforge' ),
			'bricks_components'      => __( 'Components', 'tokenforge' ),
			'bricks_global_elements' => __( 'Global elements', 'tokenforge' ),
		);
		foreach ( $options as $option => $label ) {
			$v = get_option( $option );
			if ( $v ) {
				$add( (string) wp_json_encode( $v ), $label );
			}
		}

		// Every post of every kind (drafts too): its content and the builder's data.
		list( $posts, $capped ) = self::each_post(
			self::SKIP_TYPES,
			function ( $post ) use ( $add ) {
				$text = (string) $post->post_content;
				foreach ( self::META as $key ) {
					$v = get_post_meta( $post->ID, $key, true );
					if ( $v ) {
						$text .= ' ' . wp_json_encode( $v );
					}
				}
				$add( $text, self::label( $post ) );
			}
		);

		$out = array(
			'time'   => time(),
			'posts'  => $posts,
			'places' => $places,
			'capped' => $capped,
			'uses'   => $uses,
		);
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/**
	 * Call $each( $post ) for every post of every type but $skip, drafts too,
	 * with its meta cached; stops after MAX_POSTS.
	 *
	 * @param string[] $skip Post types to leave out.
	 * @param callable $each Callback.
	 * @return array [ posts read, capped ]
	 */
	public static function each_post( $skip, $each ) {
		$types  = array_values( array_diff( get_post_types( array(), 'names' ), $skip ) );
		$posts  = 0;
		$capped = false;
		$page   = 1;
		do {
			$q = new \WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
				)
			);
			if ( ! $q->posts ) {
				break;
			}
			update_meta_cache( 'post', wp_list_pluck( $q->posts, 'ID' ) );
			foreach ( $q->posts as $post ) {
				$posts++;
				$each( $post );
			}
			// Keep one batch in memory, not every post and its meta (5000 posts of builder data is hundreds of MB).
			foreach ( wp_list_pluck( $q->posts, 'ID' ) as $id ) {
				wp_cache_delete( $id, 'posts' );
				wp_cache_delete( $id, 'post_meta' );
			}
			$page++;
			$capped = $posts >= self::MAX_POSTS;
		} while ( ! $capped && 100 === count( $q->posts ) );
		return array( $posts, $capped );
	}

	/**
	 * "Page: Home", "Template: Header" …
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function label( $post ) {
		$type  = get_post_type_object( $post->post_type );
		$kind  = $type && isset( $type->labels->singular_name ) ? $type->labels->singular_name : $post->post_type;
		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		/* translators: 1: kind of content (Page, Template …), 2: its title */
		return sprintf( __( '%1$s: %2$s', 'tokenforge' ), $kind, '' !== $title ? $title : '#' . $post->ID );
	}
}
