<?php
/**
 * REST API for the admin app.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers /wp-json/tokenforge/v1 routes.
 */
class Rest {

	const NS = 'tokenforge/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Permission callback: editors with manage_options.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function routes() {
		$perm = array( $this, 'can_manage' );
		// Font files land in uploads: also needs the right to upload files.
		$font_perm = function () {
			return current_user_can( 'manage_options' ) && current_user_can( 'upload_files' );
		};

		register_rest_route(
			self::NS,
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'get_state' ),
			)
		);

		register_rest_route(
			self::NS,
			'/token-uses',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'token_uses' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attribute-uses',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'attribute_uses' ),
			)
		);

		register_rest_route(
			self::NS,
			'/setup',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $perm,
					'callback'            => function () {
						return rest_ensure_response( Setup_Wizard::state() );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => array( $this, 'setup' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/setup-undo',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => function () {
					$ok = Setup_Wizard::undo();
					return rest_ensure_response( array( 'ok' => $ok, 'state' => Setup_Wizard::state() ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/class-uses',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => function () {
					$r = Class_Usage::scan();
					Attribute_Usage::scan(); // the same switch leaves out the rules of unused data attributes, so the stylesheet below goes by both scans
					$css_ok = Css_Generator::get_instance()->generate();
					return rest_ensure_response(
						array(
							'ok'      => true,
							'css_ok'  => (bool) $css_ok,
							'attribute_usage' => Attribute_Usage::last(),
							'usage'   => Class_Usage::summary(),
							'css_ver' => Css_Generator::get_instance()->version(),
							'css_url' => Css_Generator::get_instance()->css_url(),
							'found'   => count( $r['classes'] ),
						)
					);
				},
			)
		);

		register_rest_route(
			self::NS,
			'/save',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'save' ),
			)
		);

		register_rest_route(
			self::NS,
			'/regenerate',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'regenerate' ),
			)
		);

		register_rest_route(
			self::NS,
			'/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => function () {
					return rest_ensure_response( Transfer::export() );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'import_file' ),
			)
		);

		// Design token files (DTCG): export, preview an import, import.
		register_rest_route( self::NS, '/token-files', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( Token_Files::class, 'rest_export' ) ) );
		register_rest_route( self::NS, '/token-files/(?:preview|import)', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( Token_Files::class, 'rest_import' ) ) );

		register_rest_route(
			self::NS,
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'reset' ),
			)
		);

		register_rest_route(
			self::NS,
			'/history',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => function () {
					return rest_ensure_response( array( 'items' => History::items() ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/history/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => function ( $req ) {
					$d = History::diff( (int) $req['id'] );
					return rest_ensure_response( null === $d ? array( 'ok' => false, 'error' => 'This snapshot is gone or damaged.' ) : array_merge( array( 'ok' => true ), $d ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/history/(?P<id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'restore' ),
			)
		);

		register_rest_route(
			self::NS,
			'/bricks-setup',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'bricks_setup' ),
			)
		);

		register_rest_route(
			self::NS,
			'/import-seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'import_seed' ),
			)
		);

		register_rest_route(
			self::NS,
			'/register-classes',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'register_classes' ),
			)
		);

		register_rest_route(
			self::NS,
			'/add-color',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'add_color' ),
			)
		);

		register_rest_route(
			self::NS,
			'/font-upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => $font_perm,
				'callback'            => function ( $req ) {
					$files = $req->get_file_params();
					return rest_ensure_response( Fonts::upload( isset( $files['file'] ) ? $files['file'] : null ) );
				},
			)
		);

		// Download a Google Fonts family to self-host it (only when an administrator asks).
		register_rest_route(
			self::NS,
			'/font-google',
			array(
				'methods'             => 'POST',
				'permission_callback' => $font_perm,
				'args'                => array(
					'family'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'weights' => array( 'type' => 'string', 'default' => '400,700', 'sanitize_callback' => 'sanitize_text_field' ),
					'italic'  => array( 'type' => 'boolean', 'default' => false ),
				),
				'callback'            => function ( $req ) {
					return rest_ensure_response( Fonts::google( (string) $req->get_param( 'family' ), (string) $req->get_param( 'weights' ), (bool) $req->get_param( 'italic' ) ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/remove-color',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'remove_color' ),
			)
		);

		register_rest_route(
			self::NS,
			'/css-version',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'css_version' ),
			)
		);

		register_rest_route(
			self::NS,
			'/editor-css',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'editor_css' ),
			)
		);

		register_rest_route(
			self::NS,
			'/menu-data',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'menu_data' ),
			)
		);

		register_rest_route(
			self::NS,
			'/scss',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'scss_get' ),
			)
		);

		register_rest_route(
			self::NS,
			'/scss-save',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'scss_save' ),
			)
		);

		register_rest_route(
			self::NS,
			'/scss-live',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'scss_live' ),
			)
		);

		register_rest_route(
			self::NS,
			'/scss-rev',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => function () {
					return rest_ensure_response( array( 'rev' => md5( (string) Scss::settings()['source'] ) ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/cheatsheet',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'cheatsheet' ),
			)
		);

		register_rest_route(
			self::NS,
			'/scss-compile',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'scss_compile' ),
			)
		);

		register_rest_route(
			self::NS,
			'/import-acss',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'import_acss' ),
			)
		);
	}

	/**
	 * Everything TokenForge provides, grouped, for the Cheatsheet section.
	 *
	 * @return \WP_REST_Response
	 */
	public function cheatsheet() {
		return rest_ensure_response( array( 'groups' => Cheatsheet::data() ) );
	}

	/**
	 * Global SCSS for the builder dock: source, revision, status and what the
	 * editor suggests (mixins, recipes).
	 *
	 * @return \WP_REST_Response
	 */
	public function scss_get() {
		$s = Scss::settings();
		$r = Recipes::settings();
		return rest_ensure_response(
			array(
				'source'     => (string) $s['source'],
				'rev'        => md5( (string) $s['source'] ),
				'enabled'    => ! empty( $s['enabled'] ),
				'sync'       => ! empty( $s['sync'] ),
				'status'     => Scss::status(),
				'ref'        => Scss::reference(),
				'recipes'    => Recipes::all(),
				'recipes_on' => ! empty( $r['enabled'] ),
			)
		);
	}

	/**
	 * Save only the Global SCSS source (builder dock): sync the Bricks classes,
	 * rebuild the stylesheet. Refuses when the source changed since the dock
	 * loaded it (revision), unless { force: true }.
	 *
	 * @param \WP_REST_Request $req Request with { source, rev, force }.
	 * @return \WP_REST_Response
	 */
	public function scss_save( $req ) {
		$body = $req->get_json_params();
		if ( ! isset( $body['source'] ) || ! is_string( $body['source'] ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'No source.' ) );
		}
		$src = str_replace( "\r\n", "\n", $body['source'] );
		// Stored values, not the ones a switched-off module reads as (those would be saved as off).
		$cur = Modules::raw( array( Scss::class, 'settings' ) );
		$rev = md5( (string) $cur['source'] );
		if ( empty( $body['force'] ) && isset( $body['rev'] ) && (string) $body['rev'] !== $rev && $src !== (string) $cur['source'] ) {
			return rest_ensure_response( array( 'ok' => false, 'conflict' => true, 'source' => (string) $cur['source'], 'rev' => $rev ) );
		}
		Scss::save_settings( array_merge( $cur, array( 'source' => $src ) ) );
		$sync = Features\Scss_Sync::push();
		Css_Generator::get_instance()->generate();
		$now = (string) Scss::settings()['source'];
		return rest_ensure_response(
			array(
				'ok'          => true,
				'rev'         => md5( $now ),
				'source'      => $now !== $src ? $now : null, // the sync added fields from Bricks
				'status'      => Scss::status(),
				'css_ver'     => Css_Generator::get_instance()->version(),
				'css_url'     => Css_Generator::get_instance()->css_url(),
				'synced'      => $sync['classes'],
				'sync_errors' => $sync['errors'],
			)
		);
	}

	/**
	 * Live sync in the builder, nothing stored (the dock and the builder save as usual):
	 * - mode "push": the SCSS in the dock → the settings the builder classes should
	 *   show now (only the classes that differ come back);
	 * - mode "pull": classes changed in the builder (not saved yet) → the SCSS for
	 *   the dock (null when nothing changes).
	 *
	 * @param \WP_REST_Request $req Request with { mode, source, classes: [ { name, settings, old? } ] }.
	 * @return \WP_REST_Response
	 */
	public function scss_live( $req ) {
		$body = $req->get_json_params();
		if ( ! Features\Scss_Sync::enabled() || ! isset( $body['source'] ) || ! is_string( $body['source'] ) ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}
		$src  = str_replace( "\r\n", "\n", $body['source'] );
		$list = array();
		foreach ( isset( $body['classes'] ) && is_array( $body['classes'] ) ? array_slice( $body['classes'], 0, 300 ) : array() as $c ) {
			if ( is_array( $c ) && isset( $c['name'] ) && is_string( $c['name'] ) ) {
				$list[] = array(
					'name'     => $c['name'],
					'settings' => isset( $c['settings'] ) && is_array( $c['settings'] ) ? $c['settings'] : array(),
					'old'      => isset( $c['old'] ) && is_array( $c['old'] ) ? $c['old'] : array(),
				);
			}
		}
		if ( isset( $body['mode'] ) && 'pull' === $body['mode'] ) {
			$out = Features\Scss_Sync::live_pull( $src, $list );
			return rest_ensure_response( array( 'ok' => true, 'source' => $out !== $src ? $out : null ) );
		}
		$r = Features\Scss_Sync::push( array( 'source' => $src, 'classes' => $list, 'dry' => true ) );
		return rest_ensure_response( array( 'ok' => true, 'classes' => $r['classes'], 'errors' => $r['errors'] ) );
	}

	/**
	 * Compile SCSS without saving (live check in the editor).
	 *
	 * @param \WP_REST_Request $req Request with { source }.
	 * @return \WP_REST_Response
	 */
	public function scss_compile( $req ) {
		$body = $req->get_json_params();
		$r    = Scss::compile( isset( $body['source'] ) ? (string) $body['source'] : '' );
		return rest_ensure_response( array( 'ok' => null === $r['error'], 'css' => $r['css'], 'error' => $r['error'] ) );
	}

	/**
	 * Current stylesheet version + URL (hot-reload polling).
	 *
	 * @return \WP_REST_Response
	 */
	public function css_version() {
		$css = Css_Generator::get_instance();
		return rest_ensure_response( array( 'ver' => $css->version(), 'url' => $css->css_url() ) );
	}

	/**
	 * CSS for the block editor (hot reload in Gutenberg).
	 *
	 * @return \WP_REST_Response
	 */
	public function editor_css() {
		$css = Css_Generator::get_instance();
		return rest_ensure_response( array( 'ver' => $css->version(), 'css' => $css->editor_css() ) );
	}

	/**
	 * Fresh data for the builder's contextual menus (after a save).
	 *
	 * @return \WP_REST_Response
	 */
	public function menu_data() {
		$classes = Features\Builder_Enhancements::menu_classes();
		// The site's own classes (Bricks global classes), for suggestions in the SCSS editor.
		$site = array();
		$gc   = get_option( 'bricks_global_classes', array() );
		if ( is_array( $gc ) ) {
			foreach ( $gc as $c ) {
				if ( is_array( $c ) && ! empty( $c['name'] ) && is_string( $c['name'] ) ) {
					$site[] = $c['name'];
				}
			}
		}
		return rest_ensure_response(
			array(
				'vars'         => Features\Builder_Enhancements::menu_variables(),
				'classes'      => $classes,
				'site_classes' => array_values( array_diff( array_unique( $site ), $classes ) ),
			)
		);
	}

	/**
	 * Look through the site for var(--…) uses (read only) and keep the result.
	 *
	 * @return \WP_REST_Response
	 */
	public function token_uses() {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'usage' => Token_Usage::scan(),
			)
		);
	}

	/**
	 * Look through the site for TokenForge data attributes (read only) and keep the result.
	 *
	 * @return WP_REST_Response
	 */
	public function attribute_uses() {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'usage' => Attribute_Usage::scan(),
			)
		);
	}

	/**
	 * Run the setup wizard: the chosen groups of builder settings, and (optional)
	 * TokenForge's connection to the builder.
	 *
	 * @param \WP_REST_Request $req Request with { groups: [ id ], connect: bool }.
	 * @return \WP_REST_Response
	 */
	public function setup( $req ) {
		if ( ! Setup_Wizard::available() ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => __( 'The builder is not active.', 'tokenforge' ) ) );
		}
		$body   = $req->get_json_params();
		$groups = isset( $body['groups'] ) && is_array( $body['groups'] ) ? array_map( 'sanitize_key', $body['groups'] ) : array();
		$connect = isset( $body['connect'] ) && is_array( $body['connect'] ) ? array_map( 'sanitize_key', $body['connect'] ) : array();
		History::snapshot( 'setup' );
		$r       = Setup_Wizard::apply( $groups, $connect );
		return rest_ensure_response(
			array(
				'ok'      => true,
				'changed' => $r['changed'],
				'state'   => Setup_Wizard::state(),
				'css_ver' => Css_Generator::get_instance()->version(),
				'css_url' => Css_Generator::get_instance()->css_url(),
			)
		);
	}

	/**
	 * Import every configured variable from an installed Automatic.css by parsing
	 * its generated variables stylesheet in uploads/automatic-css/. Imported
	 * values are merged into TokenForge tokens (same-named tokens are overwritten).
	 *
	 * @return \WP_REST_Response
	 */
	public function import_acss() {
		History::snapshot( 'import' );
		$up   = wp_get_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'automatic-css';
		$file = '';
		foreach ( array( 'automatic-variables.css', 'automatic.css' ) as $name ) {
			if ( file_exists( trailingslashit( $dir ) . $name ) ) {
				$file = trailingslashit( $dir ) . $name;
				break;
			}
		}
		if ( '' === $file ) {
			// No generated CSS (e.g. ACSS removed), the saved settings may still be there.
			$settings_import = Acss_Import::run();
			if ( ! $settings_import['ok'] ) {
				return rest_ensure_response( array( 'ok' => false, 'error' => 'Nothing to import: no generated variables or saved settings were found on this site.' ) );
			}
			Css_Generator::get_instance()->rebuild();
			return rest_ensure_response( array( 'ok' => true, 'settings' => $settings_import, 'source' => 'settings only', 'imported' => 0, 'total' => count( Settings::get_instance()->get_tokens() ) ) );
		}

		$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $css ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Could not read the variables file.' ) );
		}

		// Pull custom-property declarations (skip anything not a real token).
		preg_match_all( '/--([a-z0-9\-]+)\s*:\s*([^;{}]+);/i', $css, $matches, PREG_SET_ORDER );
		$imported = array();
		$seen     = array();
		foreach ( $matches as $m ) {
			$name = strtolower( $m[1] );
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$imported[]    = array(
				'name'    => $name,
				'value'   => trim( $m[2] ),
				'group'   => Tokens::derive_group( $name ),
				'enabled' => true,
			);
		}
		if ( empty( $imported ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'No variables found in the variables file.' ) );
		}

		// Merge: imported values overwrite same-named existing tokens; keep the rest.
		$settings = Settings::get_instance();
		$byName   = array();
		foreach ( $settings->get_tokens() as $t ) {
			$byName[ $t['name'] ] = $t;
		}
		foreach ( $imported as $t ) {
			$byName[ $t['name'] ] = $t;
		}
		// Drop variables an EARLIER ACSS import brought in that ACSS no longer
		// outputs (your own tokens are never touched, only recorded names).
		$current = array_column( $imported, 'name' );
		$before  = get_option( 'tokenforge_acss_names', array() );
		$stale   = array_diff( is_array( $before ) ? $before : array(), $current );
		foreach ( $stale as $n ) {
			unset( $byName[ $n ] );
		}
		update_option( 'tokenforge_acss_names', $current, false );
		$stored = $settings->save_tokens( array_values( $byName ) );
		Defaults::adopt_root_token();   // ACSS's root font size first, it sets the rem base
		Width::adopt_stored_token();

		// Then the ACSS *settings* (typography, spacing, layout, buttons, …), which
		// are more precise than values recovered from variables.
		$settings_import = Acss_Import::run();

		// Colors and the other settings now make most of what came in: keep only
		// the variables they don't make, or make differently, so the settings stay
		// in charge (the stylesheet first, the tidy reads it).
		Css_Generator::get_instance()->generate();
		list( $kept, $tidied ) = Token_Catalog::tidy( $settings->get_tokens(), true );
		if ( $tidied ) {
			$stored = $settings->save_tokens( $kept );
			Css_Generator::get_instance()->generate();
		}
		do_action( 'tokenforge_tokens_saved' );

		return rest_ensure_response(
			array(
				'ok'       => true,
				'settings' => $settings_import,
				'source'   => basename( $file ),
				'imported' => count( $imported ),
				'removed'  => count( $stale ),
				'tidied'   => $tidied, // made by the settings, so not kept as tokens
				'total'    => count( $stored ),
			)
		);
	}

	/**
	 * Generate a full color family (shades + channels) from a base hex and merge
	 * it into the stored tokens.
	 *
	 * @param \WP_REST_Request $req Request with { name, hex }.
	 * @return \WP_REST_Response
	 */
	public function add_color( $req ) {
		$body = $req->get_json_params();
		$name = Tokens::sanitize_name( isset( $body['name'] ) ? $body['name'] : '' );
		$hex  = isset( $body['hex'] ) ? $body['hex'] : '';
		if ( '' === $name || null === Color_Engine::hex_to_rgb( $hex ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Provide a name and a valid hex color.' ) );
		}
		$added = Palette::add( $name, $hex, isset( $body['alt'] ) ? (string) $body['alt'] : '' );
		Css_Generator::get_instance()->rebuild();
		return rest_ensure_response( array( 'ok' => $added > 0, 'added' => $added, 'total' => count( Settings::get_instance()->get_tokens() ) ) );
	}

	/**
	 * Remove one of your own colour families (standard ones can only be switched off).
	 *
	 * @param \WP_REST_Request $req Request with { name }.
	 * @return \WP_REST_Response
	 */
	public function remove_color( $req ) {
		$body = $req->get_json_params();
		if ( ! Palette::remove( isset( $body['name'] ) ? (string) $body['name'] : '' ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Only your own families can be removed; switch a standard family off instead.' ) );
		}
		Css_Generator::get_instance()->rebuild();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Register BEM class names into Bricks global classes (for autocomplete).
	 *
	 * @param \WP_REST_Request $req Request with { classes: string[] }.
	 * @return \WP_REST_Response
	 */
	public function register_classes( $req ) {
		$body   = $req->get_json_params();
		$cat_id = 'tokenforge';
		$added  = array();

		// Two input shapes:
		//  - items: [{id, name}]  (BEM tool, ids must match the builder store)
		//  - classes: [name, …]   (auto-generated ids)
		$pairs = array();
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			foreach ( $body['items'] as $it ) {
				$name = preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $it['name'] ?? '' ) );
				$id   = preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $it['id'] ?? '' ) );
				if ( '' !== $name ) {
					$pairs[ $name ] = '' !== $id ? $id : uniqid( 'tf_' );
				}
			}
		}
		if ( isset( $body['classes'] ) && is_array( $body['classes'] ) ) {
			foreach ( $body['classes'] as $n ) {
				$name = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $n );
				if ( '' !== $name && ! isset( $pairs[ $name ] ) ) {
					$pairs[ $name ] = uniqid( 'tf_' );
				}
			}
		}
		if ( empty( $pairs ) ) {
			return rest_ensure_response( array( 'ok' => false, 'added' => array() ) );
		}

		$cats = (array) get_option( 'bricks_global_classes_categories', array() );
		if ( false === array_search( $cat_id, array_column( $cats, 'id' ), true ) ) {
			$cats[] = array( 'id' => $cat_id, 'name' => 'TokenForge' );
			update_option( 'bricks_global_classes_categories', $cats );
		}

		$classes       = (array) get_option( 'bricks_global_classes', array() );
		$existing_names = array_column( $classes, 'name' );
		foreach ( $pairs as $name => $id ) {
			if ( in_array( $name, $existing_names, true ) ) {
				continue;
			}
			$classes[] = array(
				'id'       => $id,
				'name'     => $name,
				'settings' => array(),
				'category' => $cat_id,
			);
			$added[] = $name;
		}
		update_option( 'bricks_global_classes', array_values( $classes ), false );

		return rest_ensure_response( array( 'ok' => true, 'added' => $added ) );
	}

	/**
	 * Current tokens + settings + generated file info.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_state() {
		$fw = Framework::build(); // as the output sees it (module switches applied)
		return rest_ensure_response(
			Modules::raw(
				function () use ( $fw ) {
					return $this->build_state( $fw );
				}
			)
		);
	}

	/**
	 * The dashboard state (values as stored).
	 *
	 * @param array $fw Framework build.
	 * @return array
	 */
	private function build_state( $fw ) {
		$settings = Settings::get_instance();
		$css      = Css_Generator::get_instance();
		$platforms = Platforms\Platforms::detected();
		$tokens    = $settings->get_tokens();
		return array(
				'tokens'      => $tokens,
				'tokens_rev'  => self::tokens_rev( $tokens ), // Save sends it back: a window opened before a builder edit can't drop the new tokens
				'settings'    => $settings->get_settings(),
				'framework'   => Framework::settings(),
				'scales'      => Scale_Engine::settings(),
				'components'  => Components::settings(),
				'scheme'      => Scheme::settings(),
				'extras'      => Extras::settings(),
				'defaults'    => Defaults::settings(),
				'content'     => Content::settings(),
				'width'       => Width::settings(),
				'motion'      => Motion::settings(),
				'overlays'    => Overlays::settings(),
				'woo'         => Woo::settings(),
				'woo_info'    => array( 'active' => Woo::active(), 'version' => Woo::version() ),
				'fonts'       => Fonts::settings(),
				'font_choices' => Fonts::choices(),
				'palette'     => Palette::settings(),
				'palette_rev' => Palette::rev(), // as tokens_rev: Save sends it back so a stale window can't drop a family made meanwhile
				'scss'        => Scss::settings(),
				'scss_status' => Scss::status(),
				'scss_rev'    => md5( (string) Scss::settings()['source'] ),
				'scss_ref'    => Scss::reference(),
				'recipes'     => Recipes::settings(),
				'recipe_list' => Recipes::all(),
				'enhancements' => Features\Builder_Enhancements::settings(),
				'bricks_native' => Features\Builder_Enhancements::native_settings(),
				'bricks_native_labels' => Features\Builder_Enhancements::NATIVE,
				'util_count'  => count( $fw['classes'] ),
				// Defaults per module: placeholders + "reset to default" in the dashboard.
				'defaults_map' => array(
					'settings'     => $settings->default_settings(),
					'scales'       => Scale_Engine::defaults(),
					'components'   => Components::defaults(),
					'scheme'       => Scheme::defaults(),
					'extras'       => Extras::defaults(),
					'defaults'     => Defaults::defaults(),
					'content'      => Content::defaults(),
					'width'        => Width::defaults(),
					'motion'       => Motion::defaults(),
					'enhancements' => Features\Builder_Enhancements::defaults(),
					'palette'      => Palette::defaults(),
					'fonts'        => Fonts::defaults(),
					'scss'         => Scss::defaults(),
					'recipes'      => Recipes::defaults(),
					'woo'          => Woo::defaults(),
				'modules'      => Modules::defaults(),
				),
				'families'     => Components::FAMILIES,
				'groups'      => Tokens::GROUPS,
				'token_catalog' => Token_Catalog::all(), // what the settings make (the Tokens screen lists them)
				'token_usage'   => Token_Catalog::usage(), // how often each variable is used in the stylesheet
				'site_usage'    => Token_Usage::last(), // where the site uses each variable (last scan)
				'attribute_usage' => Attribute_Usage::last(), // where the site uses each data attribute (last scan)
				'attribute_list' => Attributes::list(), // the names the Data attributes screen lists
				'class_usage'   => Class_Usage::summary(), // which utility classes the site uses (last scan)
				'setup'         => Platforms\Bricks::is_active() ? Setup_Wizard::state() : null,
				'css_url'     => $css->css_url(),
				'css_ver'     => $css->version(),
				'css_exists'  => file_exists( $css->css_path() ),
				'bricks'      => Platforms\Bricks::is_active(),
				'platforms'   => $platforms, // builders on the site (Builder screen / Builder integrations)
				'caps'        => Platforms\Platforms::caps( $platforms ), // what the active one supports (dashboard needs: '<cap>')
				'modules'     => Modules::settings(),
				'theme'       => self::theme_info(),
				'history'     => History::items(),
				'contrast'    => Contrast::report(),
		);
	}

	/**
	 * A fingerprint of a token list (same list, same fingerprint).
	 *
	 * @param array $list Normalised tokens.
	 * @return string
	 */
	private static function tokens_rev( $list ) {
		return md5( (string) wp_json_encode( $list ) );
	}

	/**
	 * The linked Bricks theme style (for the dashboard).
	 *
	 * @return array
	 */
	private static function theme_info() {
		if ( ! Platforms\Bricks::is_active() ) {
			return array( 'bricks' => false );
		}
		$all = get_option( Platforms\Bricks_Theme::OPTION, array() );
		$id  = Platforms\Bricks_Theme::style_id( $all );
		return array(
			'bricks'  => true,
			'enabled' => Platforms\Bricks_Theme::enabled(),
			'style'   => $id,
			'label'   => '' !== $id && isset( $all[ $id ]['label'] ) ? (string) $all[ $id ]['label'] : '',
		);
	}

	/**
	 * The linked theme style's settings after a save (so the builder can show them).
	 *
	 * @return array|null
	 */
	private static function theme_style() {
		if ( ! Platforms\Bricks::is_active() ) {
			return null;
		}
		$all = get_option( Platforms\Bricks_Theme::OPTION, array() );
		$id  = Platforms\Bricks_Theme::style_id( $all );
		return '' === $id ? null : array( 'id' => $id, 'settings' => isset( $all[ $id ]['settings'] ) ? $all[ $id ]['settings'] : array() );
	}

	/**
	 * Save tokens + settings, regenerate CSS, sync Bricks.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function save( $req ) {
		$settings = Settings::get_instance();
		$body     = $req->get_json_params();
		History::snapshot( 'save' );
		$old_w    = Width::settings();
		$old_m    = Motion::settings();

		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$settings->save_settings( $body['settings'] );
		}
		if ( isset( $body['framework'] ) && is_array( $body['framework'] ) ) {
			// No scan here (up to 5000 posts): the stylesheet keeps every class until the dashboard asks for one (/class-uses).
			Framework::save_settings( $body['framework'] );
		}
		if ( isset( $body['scales'] ) && is_array( $body['scales'] ) ) {
			Scale_Engine::save_settings( $body['scales'] );
		}
		if ( isset( $body['components'] ) && is_array( $body['components'] ) ) {
			Components::save_settings( $body['components'] );
		}
		if ( isset( $body['scheme'] ) && is_array( $body['scheme'] ) ) {
			Scheme::save_settings( $body['scheme'] );
		}
		if ( isset( $body['extras'] ) && is_array( $body['extras'] ) ) {
			Extras::save_settings( $body['extras'] );
		}
		if ( isset( $body['defaults'] ) && is_array( $body['defaults'] ) ) {
			Defaults::save_settings( $body['defaults'] );
		}
		if ( isset( $body['width'] ) && is_array( $body['width'] ) ) {
			Width::save_settings( $body['width'] );
		}
		if ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			Content::save_settings( $body['content'] );
		}
		if ( isset( $body['motion'] ) && is_array( $body['motion'] ) ) {
			Motion::save_settings( $body['motion'] );
		}
		if ( isset( $body['overlays'] ) && is_array( $body['overlays'] ) ) {
			Overlays::save_settings( $body['overlays'] );
		}
		if ( isset( $body['woo'] ) && is_array( $body['woo'] ) ) {
			Woo::save_settings( $body['woo'] );
		}
		if ( isset( $body['fonts'] ) && is_array( $body['fonts'] ) ) {
			Fonts::save_settings( $body['fonts'] );
		}
		if ( isset( $body['modules'] ) && is_array( $body['modules'] ) ) {
			Modules::save_settings( $body['modules'] );
		}
		if ( isset( $body['enhancements'] ) && is_array( $body['enhancements'] ) ) {
			Features\Builder_Enhancements::save_settings( $body['enhancements'] );
		}
		$scss_conflict = false;
		if ( isset( $body['scss'] ) && is_array( $body['scss'] ) ) {
			$cur = Modules::raw( array( Scss::class, 'settings' ) );
			if ( isset( $body['scss_rev'], $body['scss']['source'] ) && md5( (string) $cur['source'] ) !== (string) $body['scss_rev'] && (string) $body['scss']['source'] !== (string) $cur['source'] ) {
				// The SCSS changed since the dashboard loaded it: keep the newer source.
				$scss_conflict            = true;
				$body['scss']['source'] = $cur['source'];
			}
			// Switches this window did not touch keep their stored value, so a window
			// opened earlier can't undo a change made in the dock (the builder sync switch).
			if ( isset( $body['scss_base'] ) && is_array( $body['scss_base'] ) ) {
				foreach ( $body['scss'] as $k => $v ) {
					if ( 'source' !== $k && array_key_exists( $k, $body['scss_base'] ) && array_key_exists( $k, $cur ) && ! is_array( $v ) && (string) $v === (string) $body['scss_base'][ $k ] ) {
						$body['scss'][ $k ] = $cur[ $k ];
					}
				}
			}
			Scss::save_settings( $body['scss'] );
		}
		$sync = Features\Scss_Sync::push();
		if ( isset( $body['recipes'] ) && is_array( $body['recipes'] ) ) {
			Recipes::save_settings( $body['recipes'] );
		}
		if ( isset( $body['bricks_native'] ) && is_array( $body['bricks_native'] ) ) {
			Features\Builder_Enhancements::save_native( $body['bricks_native'] );
		}
		$stored          = array();
		$tokens_conflict = false;
		// Taken before the tokens are written: with no stored palette the families are read from the tokens.
		$palette_have = isset( $body['palette'], $body['palette_rev'] ) ? Palette::rev() : '';
		if ( isset( $body['tokens'] ) && is_array( $body['tokens'] ) ) {
			$have   = $settings->get_tokens();
			$posted = array_values( array_filter( array_map( array( Tokens::class, 'normalize' ), $body['tokens'] ) ) );
			if ( ! empty( $body['tokens_rev'] ) && is_string( $body['tokens_rev'] ) && self::tokens_rev( $have ) !== (string) $body['tokens_rev'] && self::tokens_rev( $posted ) !== self::tokens_rev( $have ) ) {
				// The tokens changed (builder, another window) since the dashboard loaded them: keep the stored ones.
				$tokens_conflict = true;
				$stored          = $have;
			} else {
				$stored = $settings->save_tokens( $body['tokens'] );
			}
		}
		// Palette after the tokens: changed families are regenerated on top of them.
		$palette          = array();
		$palette_conflict = false;
		if ( isset( $body['palette'] ) && is_array( $body['palette'] ) ) {
			if ( '' !== $palette_have && is_string( $body['palette_rev'] ) && '' !== $body['palette_rev'] && $palette_have !== $body['palette_rev'] && Palette::rev( $body['palette'] ) !== $palette_have ) {
				// The families changed (builder, another window) since the dashboard loaded them: keep the stored ones.
				$palette_conflict = true;
			} else {
				$palette = Palette::save_settings( $body['palette'] );
				if ( $palette ) {
					$stored = $settings->get_tokens();
				}
			}
		}
		// A changed content width must also reach a stored content-width token.
		if ( Width::sync_stored_token( $old_w['content_width'] ) ) {
			$stored = $settings->get_tokens();
		}
		// Likewise changed transition/easing settings → stored --transition*/--ease-* tokens.
		if ( Motion::sync_stored_tokens( $old_m ) ) {
			$stored = $settings->get_tokens();
		}

		$css_ok = Css_Generator::get_instance()->generate();
		Class_Usage::measure(); // the sizes shown next to "Leave out unused utility classes"

		// Let platform integrations (Bricks) re-sync their globals.
		do_action( 'tokenforge_tokens_saved' );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'count'   => count( $stored ),
				'css_url' => Css_Generator::get_instance()->css_url(),
				'css_ver' => Css_Generator::get_instance()->version(),
				'scss'    => Scss::status(),
				'scss_conflict' => $scss_conflict,
				'tokens_conflict' => $tokens_conflict,
				'palette_conflict' => $palette_conflict,
				'css_ok'  => (bool) $css_ok,
				'palette' => $palette,
				'theme_style' => self::theme_style(),
				'synced'  => $sync['classes'],
				'sync_errors' => $sync['errors'],
			)
		);
	}

	/**
	 * Regenerate the stylesheet only.
	 *
	 * @return \WP_REST_Response
	 */
	public function regenerate() {
		$ok = Css_Generator::get_instance()->rebuild();
		return rest_ensure_response(
			array(
				'ok'      => (bool) $ok,
				'css_url' => Css_Generator::get_instance()->css_url(),
				'css_ver' => Css_Generator::get_instance()->version(),
			)
		);
	}

	/**
	 * Replace the tokens with the starter tokens (older dashboards call this).
	 *
	 * @return \WP_REST_Response
	 */
	public function import_seed() {
		History::snapshot( 'reset' );
		Starter::reset( array( 'tokens' ) );
		Css_Generator::get_instance()->rebuild();
		return rest_ensure_response(
			array(
				'ok'    => true,
				'count' => count( Settings::get_instance()->get_tokens() ),
			)
		);
	}

	/**
	 * Parts named in a request (only known ones).
	 *
	 * @param mixed $in    Raw list.
	 * @param array $known Allowed parts.
	 * @return array
	 */
	private static function parts( $in, $known ) {
		$in = is_array( $in ) ? array_map( 'strval', $in ) : array();
		return array_values( array_intersect( $known, $in ) );
	}

	/**
	 * Import an export file (JSON object in the body: { file, parts }).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function import_file( $req ) {
		$body  = $req->get_json_params();
		$file  = isset( $body['file'] ) ? $body['file'] : null;
		$error = Transfer::check( $file );
		if ( '' !== $error ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => $error ) );
		}
		$parts = self::parts( isset( $body['parts'] ) ? $body['parts'] : Transfer::PARTS, Transfer::PARTS );
		History::snapshot( 'import' );
		$done  = Transfer::import( $file, $parts );
		Css_Generator::get_instance()->rebuild();
		return rest_ensure_response( array( 'ok' => true, 'imported' => $done ) );
	}

	/**
	 * Reset parts to their defaults ({ parts }).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function reset( $req ) {
		$body  = $req->get_json_params();
		$parts = self::parts( isset( $body['parts'] ) ? $body['parts'] : array(), Starter::PARTS );
		if ( ! $parts ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Choose what to reset.' ) );
		}
		History::snapshot( 'reset' );
		$done = Starter::reset( $parts );
		Css_Generator::get_instance()->rebuild();
		return rest_ensure_response( array( 'ok' => true, 'reset' => $done ) );
	}

	/**
	 * Put a history snapshot back (as an import of every part).
	 *
	 * @param \WP_REST_Request $req Request with the id in the route.
	 * @return \WP_REST_Response
	 */
	public function restore( $req ) {
		if ( ! History::restore( (int) $req['id'] ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'This snapshot is gone or damaged.' ) );
		}
		Features\Scss_Sync::push(); // Global SCSS wins: the builder classes follow the restored code
		Css_Generator::get_instance()->rebuild();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Link (or create) the Bricks theme style.
	 *
	 * @return \WP_REST_Response
	 */
	public function bricks_setup() {
		if ( ! Platforms\Bricks::is_active() ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Bricks is not the active theme.' ) );
		}
		if ( ! Platforms\Bricks_Theme::enabled() ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'Theme style sync is switched off (Builder, Theme style).' ) );
		}
		$r = Platforms\Bricks_Theme::setup();
		return rest_ensure_response( array( 'ok' => true, 'style' => $r['id'], 'created' => $r['created'], 'theme' => self::theme_info(), 'theme_style' => self::theme_style() ) );
	}
}
