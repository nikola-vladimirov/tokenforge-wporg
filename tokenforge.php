<?php
/**
 * Plugin Name:       TokenForge
 * Plugin URI:        https://pristadigital.com
 * Description:       Design tokens, fluid scales and utility classes for any WordPress site, block editor included. With Bricks active it also syncs variables, colours, classes and a theme style both ways, and adds the dashboard inside the builder.
 * Version:           0.53.7
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Prista Digital
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tokenforge
 *
 * TokenForge is original software, released under the GNU GPL v2 or later.
 *
 * @package TokenForge
 */

defined( 'ABSPATH' ) || exit;

define( 'TOKENFORGE_VERSION', '0.53.7' );
define( 'TOKENFORGE_FILE', __FILE__ );
define( 'TOKENFORGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TOKENFORGE_URL', plugin_dir_url( __FILE__ ) );
define( 'TOKENFORGE_BASENAME', plugin_basename( __FILE__ ) );

// Explicit, reliable class map autoloader for the TokenForge\ namespace.
spl_autoload_register(
	function ( $class ) {
		$map = array(
			'TokenForge\\Plugin'                   => 'includes/class-plugin.php',
			'TokenForge\\Settings'                 => 'includes/class-settings.php',
			'TokenForge\\Css_Generator'            => 'includes/class-css-generator.php',
			'TokenForge\\Frontend'                 => 'includes/class-frontend.php',
			'TokenForge\\Admin'                    => 'includes/class-admin.php',
			'TokenForge\\Rest'                     => 'includes/class-rest.php',
			'TokenForge\\Tokens'                   => 'includes/class-tokens.php',
			'TokenForge\\Color_Engine'             => 'includes/class-color-engine.php',
			'TokenForge\\Scale_Engine'             => 'includes/class-scale-engine.php',
			'TokenForge\\Defaults'                 => 'includes/class-defaults.php',
			'TokenForge\\Components'               => 'includes/class-components.php',
			'TokenForge\\Scheme'                   => 'includes/class-scheme.php',
			'TokenForge\\Extras'                   => 'includes/class-extras.php',
			'TokenForge\\Content'                  => 'includes/class-content.php',
			'TokenForge\\Width'                    => 'includes/class-width.php',
			'TokenForge\\Motion'                   => 'includes/class-motion.php',
			'TokenForge\\Overlays'                 => 'includes/class-overlays.php',
			'TokenForge\\Attributes'               => 'includes/class-attributes.php',
			'TokenForge\\Blocks'                   => 'includes/class-blocks.php',
			'TokenForge\\Acss_Import'              => 'includes/class-acss-import.php',
			'TokenForge\\Framework'                => 'includes/class-framework.php',
			'TokenForge\\Scss'                     => 'includes/class-scss.php',
			'TokenForge\\Palette'                  => 'includes/class-palette.php',
			'TokenForge\\Fonts'                    => 'includes/class-fonts.php',
			'TokenForge\\Font_Convert'             => 'includes/class-font-convert.php',
			'TokenForge\\Modules'                  => 'includes/class-modules.php',
			'TokenForge\\Factory'                  => 'includes/class-factory.php',
			'TokenForge\\Token_Catalog'            => 'includes/class-token-catalog.php',
			'TokenForge\\Token_Usage'              => 'includes/class-token-usage.php',
			'TokenForge\\Class_Usage'              => 'includes/class-class-usage.php',
			'TokenForge\\Attribute_Usage'          => 'includes/class-attribute-usage.php',
			'TokenForge\\Setup_Wizard'             => 'includes/class-setup-wizard.php',
			'TokenForge\\Starter'                  => 'includes/class-starter.php',
			'TokenForge\\Transfer'                 => 'includes/class-transfer.php',
			'TokenForge\\History'                  => 'includes/class-history.php',
			'TokenForge\\Contrast'                 => 'includes/class-contrast.php',
			'TokenForge\\Token_Files'              => 'includes/class-token-files.php',
			'TokenForge\\Changelog'                => 'includes/class-changelog.php',
			'TokenForge\\Woo'                      => 'includes/class-woo.php',
			'TokenForge\\Platforms\\Bricks_Theme'    => 'includes/platforms/class-bricks-theme.php',
			'TokenForge\\Recipes'                  => 'includes/class-recipes.php',
			'TokenForge\\Cheatsheet'               => 'includes/class-cheatsheet.php',
			'TokenForge\\Ai'                       => 'includes/class-ai.php',
			'TokenForge\\Platforms\\Bricks'        => 'includes/platforms/class-bricks.php',
			'TokenForge\\Platforms\\Platforms'     => 'includes/platforms/class-platforms.php',
			'TokenForge\\Features\\Bem_Generator'       => 'includes/features/class-bem-generator.php',
			'TokenForge\\Features\\Builder_Panel'       => 'includes/features/class-builder-panel.php',
			'TokenForge\\Features\\A11y'                => 'includes/features/class-a11y.php',
			'TokenForge\\Features\\Check'               => 'includes/features/class-check.php',
			'TokenForge\\Features\\Builder_Enhancements' => 'includes/features/class-builder-enhancements.php',
			'TokenForge\\Features\\Template_Classes'     => 'includes/features/class-template-classes.php',
			'TokenForge\\Features\\Scss_Sync'            => 'includes/features/class-scss-sync.php',
			'TokenForge\\Features\\Form_Extras'          => 'includes/features/class-form-extras.php',
			'TokenForge\\Features\\Form_Fields'          => 'includes/features/class-form-fields.php',
			'TokenForge\\Features\\Form_Rules'           => 'includes/features/class-form-rules.php',
			'TokenForge\\Features\\Form_Presets'         => 'includes/features/class-form-presets.php',
			'TokenForge\\Features\\Form_Submissions'     => 'includes/features/class-form-submissions.php',
			'TokenForge\\Features\\Form_Submissions_Screen' => 'includes/features/class-form-submissions-screen.php',
			'TokenForge\\Features\\Form_Submissions_Export' => 'includes/features/class-form-submissions-export.php',
			'TokenForge\\Features\\A11y_Auto'            => 'includes/features/class-a11y-auto.php',
			'TokenForge\\Features\\Attr_Front'           => 'includes/features/class-attr-front.php',
			'TokenForge\\Cli'                            => 'includes/class-cli.php',
		);
		if ( isset( $map[ $class ] ) ) {
			require_once TOKENFORGE_DIR . $map[ $class ];
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\TokenForge\Cli::register(); // wp tokenforge ...
}

register_activation_hook(
	__FILE__,
	function () {
		// A fresh site starts with the starter design system; the Bricks setup
		// (theme style) runs on the next load, once Bricks is loaded too.
		if ( \TokenForge\Starter::install() ) {
			update_option( 'tokenforge_setup_pending', 1, false );
		}
		\TokenForge\Css_Generator::get_instance()->generate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'tokenforge_subs_cleanup' ); // form submissions auto-delete
	}
);

add_action(
	'plugins_loaded',
	function () {
		require_once TOKENFORGE_DIR . 'includes/class-plugin.php';
		\TokenForge\Plugin::get_instance();
	}
);
