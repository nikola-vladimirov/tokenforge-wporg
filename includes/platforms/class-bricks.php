<?php
/**
 * Bricks builder integration.
 *
 * Original implementation. Mirrors the *documented, public* Bricks option
 * structures (bricks_global_variables, bricks_color_palette) rather than any
 * third-party plugin's code.
 *
 * @package TokenForge
 */

namespace TokenForge\Platforms;

use TokenForge\Settings;
use TokenForge\Css_Generator;
use TokenForge\Token_Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs tokens into Bricks and enqueues the generated stylesheet.
 */
class Bricks {

	const VAR_ID_PREFIX = 'tokenforge_';
	const CATEGORY_ID   = 'tokenforge';

	/**
	 * Constructor: register hooks.
	 */
	public function __construct() {
		// Frontend/editor CSS enqueuing is handled builder-agnostically by
		// TokenForge\Frontend. This class only handles Bricks-specific syncing.
		add_action( 'tokenforge_tokens_saved', array( $this, 'sync' ) );
		// Native elements (after Bricks has loaded its element base class).
		add_action( 'init', array( $this, 'register_elements' ), 11 );
	}

	/**
	 * Register TokenForge's Bricks elements.
	 *
	 * @return void
	 */
	public function register_elements() {
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Element' ) ) {
			return;
		}
		\Bricks\Elements::register_element( TOKENFORGE_DIR . 'includes/platforms/bricks-element-scheme-toggle.php', 'tokenforge-scheme-toggle', __NAMESPACE__ . '\Element_Scheme_Toggle' );
	}

	/**
	 * Is Bricks the active theme (or parent theme)?
	 *
	 * @return bool
	 */
	public static function is_active() {
		// Most reliable: Bricks defines this constant when its theme is active.
		if ( defined( 'BRICKS_VERSION' ) ) {
			return true;
		}
		// Fallbacks: parent theme named "Bricks", or the template folder slug "bricks"
		// (covers Bricks child themes, where get('Template') returns the lowercase slug).
		$theme = wp_get_theme();
		return 'Bricks' === $theme->get( 'Name' ) || 'bricks' === strtolower( (string) $theme->get( 'Template' ) );
	}

	/**
	 * True inside the Bricks builder shell (not the preview iframe).
	 *
	 * @return bool
	 */
	public static function is_builder_context() {
		return 'run' === filter_input( INPUT_GET, 'bricks' ) && null === filter_input( INPUT_GET, 'brickspreview' );
	}

	/**
	 * True inside the Bricks preview iframe.
	 *
	 * @return bool
	 */
	public static function is_preview_context() {
		return null !== filter_input( INPUT_GET, 'brickspreview' );
	}

	/**
	 * Push tokens into Bricks' global variables + colour palette.
	 *
	 * @return void
	 */
	public function sync() {
		$settings = Settings::get_instance()->get_settings();
		$tokens   = Token_Catalog::every_variable();
		$prune_v  = ! empty( $settings['prune_variables'] );
		if ( ! empty( $settings['sync_bricks_vars'] ) ) {
			$this->sync_variables( self::for_variable_list( $tokens, $settings ), $settings['category_name'], $prune_v );
		} elseif ( $prune_v ) {
			$this->sync_variables( array(), $settings['category_name'], true );
		}
		if ( ! empty( $settings['sync_bricks_colors'] ) ) {
			$this->sync_colors( $tokens, $settings['category_name'] );
		} elseif ( $prune_v ) {
			$this->remove_palette();
		}
		$fw    = \TokenForge\Framework::settings();
		$names = array();
		if ( ! empty( $fw['register_with_bricks'] ) ) {
			$built      = \TokenForge\Framework::build();
			$components = \TokenForge\Components::build();
			$scheme     = \TokenForge\Scheme::build();
			$extras     = \TokenForge\Extras::build();
			$defaults   = \TokenForge\Defaults::build();
			$content    = \TokenForge\Content::build();
			$width      = \TokenForge\Width::build();
			$overlays   = \TokenForge\Overlays::build();
			$motion     = \TokenForge\Motion::build();
			$names      = array_merge( $built['classes'], $components['classes'], $scheme['classes'], $extras['classes'], $defaults['classes'], $content['classes'], $width['classes'], $overlays['classes'], $motion['classes'] );
		}
		$this->sync_classes( array_values( array_unique( $names ) ), $settings['category_name'], ! empty( $settings['prune_classes'] ) );
	}

	/**
	 * Is this Bricks entry one TokenForge generated (vs. the user's own or a
	 * BEM class, whose ids come from the builder)?
	 *
	 * @param array $entry Class/variable entry.
	 * @return bool
	 */
	private static function is_ours( $entry ) {
		return isset( $entry['id'] ) && 0 === strpos( (string) $entry['id'], self::VAR_ID_PREFIX )
			&& isset( $entry['category'] ) && self::CATEGORY_ID === $entry['category'];
	}

	/**
	 * Register generated utility class names into Bricks global classes (locked)
	 * so they appear in the builder's class autocomplete under our category.
	 * With $prune, generated classes that are no longer output ("deactivated")
	 * are removed, unless the user has since styled them in Bricks.
	 *
	 * @param array  $names         Utility class names (no leading dot).
	 * @param string $category_name Category label.
	 * @param bool   $prune         Remove stale generated classes.
	 * @return void
	 */
	private function sync_classes( $names, $category_name, $prune = false ) {
		if ( empty( $names ) && ! $prune ) {
			return;
		}
		$classes = (array) get_option( 'bricks_global_classes', array() );
		$locked  = (array) get_option( 'bricks_global_classes_locked', array() );

		if ( $prune ) {
			$keep    = array_flip( $names );
			$classes = array_values(
				array_filter(
					$classes,
					function ( $c ) use ( $keep ) {
						return ! self::is_ours( $c ) || isset( $keep[ $c['name'] ] ) || ! empty( $c['settings'] );
					}
				)
			);
		}

		if ( ! empty( $names ) ) {
			$cats = (array) get_option( 'bricks_global_classes_categories', array() );
			if ( false === array_search( self::CATEGORY_ID, array_column( $cats, 'id' ), true ) ) {
				$cats[] = array( 'id' => self::CATEGORY_ID, 'name' => $category_name );
				update_option( 'bricks_global_classes_categories', $cats );
			}
		}

		$existing = array_column( $classes, 'name' );
		foreach ( $names as $name ) {
			$id = self::VAR_ID_PREFIX . $name;
			if ( ! in_array( $name, $existing, true ) ) {
				$classes[]  = array(
					'id'       => $id,
					'name'     => $name,
					'settings' => array(),
					'category' => self::CATEGORY_ID,
				);
				$existing[] = $name;
			}
			if ( ! in_array( $id, $locked, true ) ) {
				$locked[] = $id;
			}
		}

		// Only keep our lock entries for classes that still exist.
		$ids    = array_flip( array_column( $classes, 'id' ) );
		$locked = array_filter(
			$locked,
			function ( $id ) use ( $ids ) {
				return 0 !== strpos( (string) $id, self::VAR_ID_PREFIX ) || isset( $ids[ $id ] );
			}
		);

		update_option( 'bricks_global_classes', array_values( $classes ), false );
		update_option( 'bricks_global_classes_locked', array_values( $locked ), false );
	}

	/**
	 * Remove the TokenForge colour palette from Bricks.
	 *
	 * @return void
	 */
	private function remove_palette() {
		$palette = (array) get_option( 'bricks_color_palette', array() );
		$kept    = array_values(
			array_filter(
				$palette,
				function ( $p ) {
					return ! isset( $p['id'] ) || self::CATEGORY_ID !== $p['id'];
				}
			)
		);
		if ( count( $kept ) !== count( $palette ) ) {
			update_option( 'bricks_color_palette', $kept );
		}
	}
	/**
	 * The variables the builder's variable list gets, as chosen under Options,
	 * Builder sync. Each of these can be left out: the transparent steps
	 * (-trans-10 … -90), the parts the colours are built from (-hex, -rgb,
	 * -hsl, -r, -g, -b, -h, -s, -l, -ref), the dark mode values (-alt) of the
	 * colour families, and the variables of buttons, cards, blockquotes and the
	 * shop. Everything else (colours, scheme colours, sizes, spacing, layout,
	 * your own tokens) is always there. Left-out variables still work in CSS.
	 *
	 * @param array $tokens   Tokens (Token_Catalog::every_variable()).
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	public static function for_variable_list( $tokens, $settings ) {
		$made = array();
		foreach ( \TokenForge\Palette::tokens() as $t ) {
			$made[ $t['name'] ] = true;
		}
		$parts = array();
		foreach ( Token_Catalog::all() as $c ) {
			if ( in_array( $c['source'], array( 'buttons', 'cards', 'content', 'woo', 'forms' ), true ) && 'colors' !== $c['kind'] ) {
				$parts[ $c['name'] ] = true;
			}
		}
		$own = array();
		foreach ( Settings::get_instance()->get_tokens() as $t ) {
			$own[ $t['name'] ] = true;
		}
		$out = array();
		foreach ( $tokens as $t ) {
			$n = $t['name'];
			if ( empty( $settings['vars_trans'] ) && preg_match( '/-trans-\d+$/', $n ) ) {
				continue;
			}
			if ( isset( $made[ $n ] ) && empty( $settings['vars_channels'] ) && preg_match( '/-(ref|hex|hsl|rgb|r|g|b|h|s|l)$/', $n ) ) {
				continue;
			}
			if ( isset( $made[ $n ] ) && empty( $settings['vars_alt'] ) && preg_match( '/-alt$/', $n ) ) {
				continue;
			}
			if ( isset( $parts[ $n ] ) && ! isset( $own[ $n ] ) && empty( $settings['vars_parts'] ) ) {
				continue;
			}
			$out[] = $t;
		}
		return $out;
	}

	/**
	 * Register each enabled token as a Bricks global variable (appears in the
	 * builder's variable picker / autocomplete). Ours are namespaced by an id
	 * prefix so we can update them without touching the user's own variables.
	 *
	 * @param array  $tokens        Normalised tokens.
	 * @param string $category_name Category label shown in Bricks.
	 * @param bool   $prune         Remove synced variables whose token is gone/disabled.
	 * @return void
	 */
	private function sync_variables( $tokens, $category_name, $prune = false ) {
		$vars = (array) get_option( 'bricks_global_variables', array() );
		$cats = (array) get_option( 'bricks_global_variables_categories', array() );

		// Ensure our category exists.
		if ( false === array_search( self::CATEGORY_ID, array_column( $cats, 'id' ), true ) ) {
			$cats[] = array(
				'id'   => self::CATEGORY_ID,
				'name' => $category_name,
			);
			update_option( 'bricks_global_variables_categories', $cats );
		}

		if ( $prune ) {
			$live = array();
			foreach ( $tokens as $t ) {
				if ( ! empty( $t['enabled'] ) ) {
					$live[ self::VAR_ID_PREFIX . $t['name'] ] = true;
				}
			}
			$vars = array_values(
				array_filter(
					$vars,
					function ( $v ) use ( $live ) {
						return ! self::is_ours( $v ) || isset( $live[ $v['id'] ] );
					}
				)
			);
		}

		// Index existing entries by id for upsert.
		$by_id = array();
		foreach ( $vars as $i => $v ) {
			if ( isset( $v['id'] ) ) {
				$by_id[ $v['id'] ] = $i;
			}
		}

		foreach ( $tokens as $t ) {
			if ( empty( $t['enabled'] ) ) {
				continue;
			}
			$id    = self::VAR_ID_PREFIX . $t['name'];
			$entry = array(
				'id'       => $id,
				'name'     => $t['name'],
				'value'    => $t['value'],
				'category' => self::CATEGORY_ID,
			);
			if ( isset( $by_id[ $id ] ) ) {
				$vars[ $by_id[ $id ] ] = $entry;
			} else {
				$vars[] = $entry;
			}
		}

		update_option( 'bricks_global_variables', array_values( $vars ) );
	}

	/**
	 * The colours for the builder's colour palette, laid out the way its picker
	 * groups them: each colour family is a main colour (var(--primary)) followed
	 * by its hover colour, with its shades under it in the picker's light, dark
	 * and transparent groups. Then white and black (with their transparent
	 * steps), the background and text colours, and your own colour tokens.
	 *
	 * Every entry points at the TokenForge variable ("raw") and carries the very
	 * value TokenForge gives that variable ("light", which the picker shows and
	 * the builder writes as --name: value). Both definitions are the same, so the
	 * builder's copy can never override TokenForge's, dark mode included.
	 *
	 * @param array $tokens Tokens (Token_Catalog::every_variable()).
	 * @return array Palette colours.
	 */
	public static function palette_colors( $tokens ) {
		$val = array();
		foreach ( $tokens as $t ) {
			if ( ! empty( $t['enabled'] ) && isset( $t['name'] ) && '' !== trim( (string) $t['value'] ) ) {
				$val[ $t['name'] ] = trim( (string) $t['value'] );
			}
		}
		$out  = array();
		$used = array();
		$add  = function ( $name, $extra = array() ) use ( &$out, &$used, $val ) {
			if ( ! isset( $val[ $name ] ) || isset( $used[ $name ] ) ) {
				return false;
			}
			$used[ $name ] = true;
			$out[]         = array_merge(
				array(
					'id'    => self::VAR_ID_PREFIX . $name,
					'name'  => $name,
					'raw'   => 'var(--' . $name . ')',
					'light' => $val[ $name ],
				),
				$extra
			);
			return true;
		};
		$shades = function ( $root, $steps ) use ( $add ) {
			foreach ( $steps as $type => $names ) {
				foreach ( array_values( $names ) as $i => $n ) {
					$add( $n, array( 'parent' => self::VAR_ID_PREFIX . $root, 'type' => $type, 'index' => $i ) );
				}
			}
		};
		$trans = function ( $name ) {
			$list = array();
			for ( $n = 10; $n <= 90; $n += 10 ) {
				$list[] = $name . '-trans-' . $n;
			}
			return $list;
		};
		$pal = \TokenForge\Palette::settings();
		foreach ( array_keys( (array) $pal['families'] ) as $fam ) {
			if ( ! $add( $fam ) ) {
				continue; // family off or without a colour
			}
			$add( $fam . '-hover' );
			$shades(
				$fam,
				array(
					'light'       => array( "$fam-ultra-light", "$fam-light", "$fam-semi-light" ),
					'dark'        => array( "$fam-semi-dark", "$fam-dark", "$fam-ultra-dark" ),
					'transparent' => $trans( $fam ),
				)
			);
		}
		foreach ( array( 'white', 'black' ) as $n ) {
			if ( $add( $n ) ) {
				$shades( $n, array( 'transparent' => $trans( $n ) ) );
			}
		}
		foreach ( array( 'bg-ultra-light', 'bg-light', 'bg-dark', 'bg-ultra-dark', 'text-dark', 'text-dark-muted', 'text-light', 'text-light-muted' ) as $n ) {
			$add( $n );
		}
		// Your own colour tokens (not the steps and channel values made above).
		foreach ( Settings::get_instance()->get_tokens() as $t ) {
			if ( ! empty( $t['enabled'] ) && ! preg_match( '/-trans-\d+$/', $t['name'] ) && \TokenForge\Framework::is_color_value( $t, $val ) ) {
				$add( $t['name'] );
			}
		}
		return $out;
	}

	/**
	 * Fill the TokenForge colour palette in the builder (see palette_colors()).
	 *
	 * @param array  $tokens        Normalised tokens.
	 * @param string $category_name Palette label.
	 * @return void
	 */
	private function sync_colors( $tokens, $category_name ) {
		$palette = (array) get_option( 'bricks_color_palette', array() );

		$key = array_search( self::CATEGORY_ID, array_column( $palette, 'id' ), true );
		if ( false === $key ) {
			$palette[] = array(
				'id'     => self::CATEGORY_ID,
				'name'   => $category_name,
				'colors' => array(),
			);
			$key = array_key_last( $palette );
		}

		$colors                    = self::palette_colors( $tokens );
		$palette[ $key ]['name']   = $category_name;
		$palette[ $key ]['colors'] = $colors;

		if ( $palette !== (array) get_option( 'bricks_color_palette', array() ) ) {
			update_option( 'bricks_color_palette', array_values( $palette ) );
		}
	}
}
