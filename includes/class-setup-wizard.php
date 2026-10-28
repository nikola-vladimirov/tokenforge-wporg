<?php
/**
 * Setup wizard: set the builder up the way TokenForge works best, in one go.
 *
 * Step 1, the builder's own settings (Bricks > Settings): a tested preset,
 * grouped, each group optional. The values are stored exactly as the builder's
 * settings screen stores them, and what that screen does after saving is done
 * too (the form submission table and the query filter tables are created when
 * those features are switched on). The settings before the wizard are kept, so
 * Undo puts them back.
 * Step 2, TokenForge and the builder: link the theme style, sync variables,
 * colours and classes.
 *
 * Site-specific settings (licence, API keys, user and role assignments, the AI
 * and abilities settings, remote template sources) are never touched.
 *
 * Original implementation.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builder setup in one go.
 */
class Setup_Wizard {

	const BACKUP = 'tokenforge_builder_settings_backup';
	const DONE   = 'tokenforge_setup_wizard';

	/**
	 * The preset: group => [ title, description, [ key => [ value, label ] ], needs ].
	 * A value of null means "switched off" (the key is removed, like an unticked box).
	 *
	 * @return array
	 */
	public static function groups() {
		$g = array(
			'comfort'  => array(
				__( 'Working in the builder', 'tokenforge' ),
				__( 'A calmer, more predictable builder: no autosave copies, custom element names in the breadcrumbs, the responsive indicator on every control, and quick element actions in the structure panel.', 'tokenforge' ),
				array(
					'builderAutosaveDisabled'           => array( true, __( 'Turn off autosave', 'tokenforge' ) ),
					'builderInstantNavigation'          => array( true, __( 'Reload the builder when switching between pages and templates (turns off instant navigation)', 'tokenforge' ) ),
					'builderElementBreadcrumbs'         => array( 'customElementNames', __( 'Element breadcrumbs with custom element names', 'tokenforge' ) ),
					'builderResponsiveControlIndicator' => array( 'any', __( 'Show the responsive indicator next to every control that can differ per breakpoint', 'tokenforge' ) ),
					'builderToolbarLogoLink'            => array( 'current', __( 'Toolbar logo opens the current page', 'tokenforge' ) ),
					'builderToolbarLogoLinkNewTab'      => array( true, __( 'Toolbar logo link opens in a new tab', 'tokenforge' ) ),
					'structureAutoSync'                 => array( true, __( 'Structure panel: expand the active element and scroll it into view', 'tokenforge' ) ),
					'structureDuplicateElement'         => array( true, __( 'Structure panel: Duplicate action', 'tokenforge' ) ),
					'structureDeleteElement'            => array( true, __( 'Structure panel: Delete action', 'tokenforge' ) ),
					'disableElementSpacing'             => array( true, __( 'Canvas: no extra element spacing', 'tokenforge' ) ),
					'builderQueryMaxResults'            => array( '6', __( 'Show at most 6 results per query loop in the builder', 'tokenforge' ) ),
					'builderQueryObjectType'            => array( true, __( 'Show the object type in the query loop type list', 'tokenforge' ) ),
					'builderMediaPicker'                => array( 'bricks', __( 'Media picker: the builder\'s own media folders', 'tokenforge' ) ),
					'importImageOnPaste'                => array( true, __( 'Import images and SVGs when pasting elements from another site', 'tokenforge' ) ),
					'enableDynamicDataPreview'          => array( true, __( 'Preview dynamic data in the builder', 'tokenforge' ) ),
				),
			),
			'previews' => array(
				__( 'Previews and smart inputs', 'tokenforge' ),
				__( 'See a class, colour, variable or font on the canvas while you hover over it, and let inputs turn space-m into var(--space-m) and work out sums.', 'tokenforge' ),
				array(
					'builderClassPreviewOnHover'           => array( true, __( 'Class preview on hover', 'tokenforge' ) ),
					'builderColorPreviewOnHover'           => array( true, __( 'Colour preview on hover', 'tokenforge' ) ),
					'builderVariablePreviewOnHover'        => array( true, __( 'Variable preview on hover', 'tokenforge' ) ),
					'builderFontFamilyPreviewOnHover'      => array( true, __( 'Font family preview on hover', 'tokenforge' ) ),
					'builderApplyVariablesAndCalculations' => array( true, __( 'Apply variables and calculations where needed', 'tokenforge' ) ),
				),
			),
			'classes'  => array(
				__( 'Classes and CSS', 'tokenforge' ),
				__( 'The CSS panel and the style controls stay in step, classes stay in step between open builder tabs, and class CSS keeps the order of the class manager.', 'tokenforge' ),
				array(
					'builderCssSync'             => array( true, __( 'Keep the Custom CSS panel and the style controls in step (both ways)', 'tokenforge' ) ),
					'builderGlobalClassesSync'   => array( true, __( 'Sync global classes between open builder tabs on every save', 'tokenforge' ) ),
					'globalClassesLoadOrder'     => array( true, __( 'Output global class CSS in the class manager\'s order', 'tokenforge' ) ),
					'disableClassChaining'       => array( true, __( 'No chaining of element and global class selectors', 'tokenforge' ) ),
					'builderGlobalClassesImport' => array( 'conflicts', __( 'Show the class import manager for class conflicts', 'tokenforge' ) ),
					'builderHtmlCssConverter'    => array( 'confirm', __( 'Turn pasted HTML and CSS into elements and classes (asks first)', 'tokenforge' ) ),
				),
			),
			'speed'    => array(
				__( 'Faster pages', 'tokenforge' ),
				__( 'Leave out scripts most sites never need, add element IDs only where needed, preload your own fonts and make the image sizes the builder needs.', 'tokenforge' ),
				array(
					'disableEmojis'        => array( true, __( 'No emoji script', 'tokenforge' ) ),
					'disableEmbed'         => array( true, __( 'No oEmbed script', 'tokenforge' ) ),
					'disableJqueryMigrate' => array( true, __( 'No jQuery Migrate', 'tokenforge' ) ),
					'disableLazyLoad'      => array( true, __( 'Turn off the builder\'s own lazy loading', 'tokenforge' ) ),
					'elementAttsAsNeeded'  => array( true, __( 'Add element IDs only where needed', 'tokenforge' ) ),
					'customImageSizes'     => array( true, __( 'Generate the builder\'s image sizes', 'tokenforge' ) ),
					'customFontsPreload'   => array( true, __( 'Preload custom fonts', 'tokenforge' ) ),
				),
			),
			'features' => array(
				__( 'Forms and queries', 'tokenforge' ),
				__( 'Keep form submissions in the database (to see them in the dashboard) and switch on query sort, filters and live search with custom fields.', 'tokenforge' ),
				array(
					'saveFormSubmissions'           => array( true, __( 'Save form submissions in the database', 'tokenforge' ) ),
					'enableQueryFilters'            => array( true, __( 'Query sort, filters and live search', 'tokenforge' ) ),
					'enableQueryFiltersIntegration' => array( true, __( 'Custom fields in query filters', 'tokenforge' ) ),
				),
			),
			'media'    => array(
				__( 'Media health', 'tokenforge' ),
				__( 'Check the media library little by little for missing alt text, broken or oversized files, missing image sizes and old formats.', 'tokenforge' ),
				array(
					'builderMediaHealthEnabled'            => array( '1', __( 'Media health checks', 'tokenforge' ) ),
					'builderMediaHealthCheckMissingAlt'    => array( '1', __( 'Missing alt text', 'tokenforge' ) ),
					'builderMediaHealthCheckBroken'        => array( '1', __( 'Broken files', 'tokenforge' ) ),
					'builderMediaHealthCheckDerivatives'   => array( '1', __( 'Missing image sizes', 'tokenforge' ) ),
					'builderMediaHealthCheckOversized'     => array( '1', __( 'Oversized files', 'tokenforge' ) ),
					'builderMediaHealthCheckObsolete'      => array( '1', __( 'Old file formats', 'tokenforge' ) ),
					'builderMediaHealthObsoleteExtensions' => array( 'bmp,tif,tiff', __( 'Old formats: bmp, tif, tiff', 'tokenforge' ) ),
					'builderMediaHealthImageMaxSize'       => array( '1', __( 'Images up to 1 MB', 'tokenforge' ) ),
					'builderMediaHealthVideoMaxSize'       => array( '50', __( 'Videos up to 50 MB', 'tokenforge' ) ),
					'builderMediaHealthAudioMaxSize'       => array( '10', __( 'Audio up to 10 MB', 'tokenforge' ) ),
					'builderMediaHealthDocumentMaxSize'    => array( '5', __( 'Documents up to 5 MB', 'tokenforge' ) ),
					'builderMediaHealthFontMaxSize'        => array( '0.5', __( 'Fonts up to 0.5 MB', 'tokenforge' ) ),
				),
			),
			'shots'    => array(
				__( 'No automatic screenshots', 'tokenforge' ),
				__( 'Do not make a screenshot of every template and component on each save (faster saves; you can still make them by hand).', 'tokenforge' ),
				array(
					'generateTemplateScreenshots'  => array( '0', __( 'No template screenshots on save', 'tokenforge' ) ),
					'generateComponentScreenshots' => array( '0', __( 'No component screenshots on save', 'tokenforge' ) ),
				),
			),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$g['shop'] = array(
				__( 'Shop', 'tokenforge' ),
				__( 'The builder\'s shop elements and features: the newer cart, checkout and account elements, AJAX add to cart, quantity in product lists, variation swatches, and "New" and "Sale" badges.', 'tokenforge' ),
				array(
					'woocommerceUseAdvancedModularElements'          => array( true, __( 'Advanced modular shop elements (cart, checkout and account v2)', 'tokenforge' ) ),
					'woocommerceEnableAjaxAddToCart'                 => array( true, __( 'AJAX add to cart', 'tokenforge' ) ),
					'woocommerceUseQtyInLoop'                        => array( true, __( 'Quantity field in product lists', 'tokenforge' ) ),
					'woocommerceUseVariationSwatches'                => array( true, __( 'Variation swatches', 'tokenforge' ) ),
					'woocommerceVariationAwareAttributeStockFilter'  => array( true, __( 'Variation-aware stock filter', 'tokenforge' ) ),
					'woocommerceUseBricksWooNotice'                  => array( true, __( 'The builder\'s notice element (add it where notices should show)', 'tokenforge' ) ),
					'woocommerceUseBricksWooCheckoutCoupon'          => array( true, __( 'The builder\'s checkout coupon element (add it to the checkout)', 'tokenforge' ) ),
					'woocommerceUseBricksWooCheckoutLogin'           => array( true, __( 'The builder\'s checkout login element (add it to the checkout)', 'tokenforge' ) ),
					'woocommerceBadgeNew'                            => array( '30', __( '"New" badge for products up to 30 days old', 'tokenforge' ) ),
					'woocommerceBadgeSale'                           => array( 'text', __( '"Sale" badge as text', 'tokenforge' ) ),
				),
			);
		}
		return $g;
	}

	/**
	 * Is the builder there to set up?
	 *
	 * @return bool
	 */
	public static function available() {
		return Platforms\Bricks::is_active() && current_user_can( 'manage_options' );
	}

	/**
	 * The wizard's state for the dashboard: every group with its settings, each
	 * with the value now and the preset value, and whether it would change.
	 *
	 * @return array
	 */
	public static function state() {
		$now    = get_option( 'bricks_global_settings', array() );
		$now    = is_array( $now ) ? $now : array();
		$groups = array();
		foreach ( self::groups() as $id => $g ) {
			$items   = array();
			$changes = 0;
			foreach ( $g[2] as $key => $def ) {
				$cur  = array_key_exists( $key, $now ) ? $now[ $key ] : null;
				$diff = ! self::same( $cur, $def[0] );
				$changes += $diff ? 1 : 0;
				$items[]  = array(
					'key'    => $key,
					'label'  => $def[1],
					'change' => $diff,
				);
			}
			$groups[] = array(
				'id'      => $id,
				'title'   => $g[0],
				'desc'    => $g[1],
				'items'   => $items,
				'changes' => $changes,
			);
		}
		$backup = get_option( self::BACKUP, null );
		$done   = get_option( self::DONE, array() );
		return array(
			'available' => self::available(),
			'groups'    => $groups,
			'undo'      => is_array( $backup ) ? (int) $backup['time'] : 0,
			'done'      => is_array( $done ) && ! empty( $done['time'] ) ? (int) $done['time'] : 0,
			'connect'   => self::connect_state(),
		);
	}

	/**
	 * Same setting value? (true / 'on' / '1' all mean a ticked box.)
	 *
	 * @param mixed $a Value now.
	 * @param mixed $b Preset value.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( true === $b ) {
			return true === $a || 'on' === $a || '1' === $a || 1 === $a;
		}
		if ( null === $b ) {
			return null === $a;
		}
		return null !== $a && (string) $a === (string) $b;
	}

	/**
	 * Step 2's parts and whether each is in place.
	 *
	 * @return array
	 */
	private static function connect_state() {
		$s    = Settings::get_instance()->get_settings();
		return array(
			array( 'id' => 'sync', 'label' => __( 'Send TokenForge\'s variables, colours and classes to the builder', 'tokenforge' ), 'on' => ! empty( $s['sync_bricks_vars'] ) && ! empty( $s['sync_bricks_colors'] ) && ! empty( Framework::settings()['register_with_bricks'] ) ),
			array( 'id' => 'canvas', 'label' => __( 'Load TokenForge\'s stylesheet in the builder canvas', 'tokenforge' ), 'on' => ! empty( $s['enqueue_in_builder'] ) ),
			array( 'id' => 'theme', 'label' => __( 'Link the site\'s theme style to TokenForge (buttons, forms, typography follow your settings)', 'tokenforge' ), 'on' => Platforms\Bricks_Theme::enabled() && '' !== Platforms\Bricks_Theme::style_id( get_option( Platforms\Bricks_Theme::OPTION, array() ) ) ),
		);
	}

	/**
	 * Run the wizard.
	 *
	 * @param string[] $groups  Group ids to apply.
	 * @param string[] $connect Step 2 parts to do (sync, canvas, theme).
	 * @return array [ 'changed' => int settings changed, 'groups' => applied ids ]
	 */
	public static function apply( $groups, $connect ) {
		$connect = array_values( array_intersect( array( 'sync', 'canvas', 'theme' ), (array) $connect ) );
		$all     = self::groups();
		$groups  = array_values( array_intersect( array_keys( $all ), (array) $groups ) );
		$now     = get_option( 'bricks_global_settings', array() );
		$now     = is_array( $now ) ? $now : array();
		$new     = $now;
		$changed = 0;
		foreach ( $groups as $id ) {
			foreach ( $all[ $id ][2] as $key => $def ) {
				if ( self::same( array_key_exists( $key, $new ) ? $new[ $key ] : null, $def[0] ) ) {
					continue;
				}
				if ( null === $def[0] ) {
					unset( $new[ $key ] );
				} else {
					$new[ $key ] = $def[0];
				}
				$changed++;
			}
		}
		if ( $changed ) {
			// Keep the settings from before the first run, so Undo can always go back to them.
			if ( ! is_array( get_option( self::BACKUP, null ) ) ) {
				update_option( self::BACKUP, array( 'time' => time(), 'settings' => $now ), false );
			}
			update_option( 'bricks_global_settings', $new );
			self::after_save( $new );
		}
		if ( $connect ) {
			self::connect( $connect );
		}
		update_option(
			self::DONE,
			array(
				'time'    => time(),
				'groups'  => $groups,
				'connect' => $connect,
			),
			false
		);
		return array(
			'changed' => $changed,
			'groups'  => $groups,
		);
	}

	/**
	 * What the builder's settings screen does after saving, for the features the
	 * preset can switch on.
	 *
	 * @param array $settings The new settings.
	 * @return void
	 */
	private static function after_save( $settings ) {
		if ( isset( $settings['saveFormSubmissions'] ) && class_exists( '\Bricks\Integrations\Form\Submission_Database' ) && method_exists( '\Bricks\Integrations\Form\Submission_Database', 'maybe_create_table' ) ) {
			\Bricks\Integrations\Form\Submission_Database::maybe_create_table();
		}
		if ( isset( $settings['enableQueryFilters'] ) && class_exists( '\Bricks\Query_Filters' ) && method_exists( '\Bricks\Query_Filters', 'get_instance' ) ) {
			$filters = \Bricks\Query_Filters::get_instance();
			if ( is_object( $filters ) && method_exists( $filters, 'maybe_create_tables' ) ) {
				$filters->maybe_create_tables();
			}
		}
	}

	/**
	 * Step 2: TokenForge and the builder work together.
	 *
	 * @param string[] $parts sync, canvas, theme.
	 * @return void
	 */
	private static function connect( $parts ) {
		$settings = Settings::get_instance();
		$s        = $settings->get_settings();
		if ( in_array( 'sync', $parts, true ) ) {
			$s['sync_bricks_vars']   = true;
			$s['sync_bricks_colors'] = true;
		}
		if ( in_array( 'canvas', $parts, true ) ) {
			$s['enqueue_in_builder'] = true;
		}
		if ( in_array( 'theme', $parts, true ) ) {
			$s['bricks_theme_sync'] = true;
		}
		$settings->save_settings( $s );
		if ( in_array( 'sync', $parts, true ) ) {
			$fw = Modules::raw( array( Framework::class, 'settings' ) );
			if ( empty( $fw['register_with_bricks'] ) ) {
				$fw['register_with_bricks'] = true;
				Framework::save_settings( $fw );
			}
		}
		if ( in_array( 'theme', $parts, true ) && Platforms\Bricks_Theme::enabled() ) {
			Platforms\Bricks_Theme::setup(); // links the site-wide style, or makes one
		}
		Css_Generator::get_instance()->rebuild(); // variables, colours and classes to the builder
	}

	/**
	 * Put the builder's settings back as they were before the wizard.
	 *
	 * @return bool
	 */
	public static function undo() {
		$backup = get_option( self::BACKUP, null );
		if ( ! is_array( $backup ) || ! isset( $backup['settings'] ) || ! is_array( $backup['settings'] ) ) {
			return false;
		}
		update_option( 'bricks_global_settings', $backup['settings'] );
		delete_option( self::BACKUP );
		return true;
	}
}
