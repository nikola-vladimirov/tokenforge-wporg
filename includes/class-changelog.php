<?php
/**
 * Changelog: a "Changelog" link on the Plugins screen that opens CHANGELOG.md
 * in a popup, like "View details" does for plugins from WordPress.org.
 *
 * @package TokenForge
 */

namespace TokenForge;

defined( 'ABSPATH' ) || exit;

/**
 * Changelog link + viewer.
 */
class Changelog {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_link' ), 10, 2 );
		add_action( 'wp_ajax_tokenforge_changelog', array( __CLASS__, 'render' ) );
	}

	/**
	 * Add the link to TokenForge's row on the Plugins screen.
	 *
	 * @param array  $links Row links.
	 * @param string $file  Plugin file.
	 * @return array
	 */
	public static function row_link( $links, $file ) {
		if ( TOKENFORGE_BASENAME !== $file || ! current_user_can( 'activate_plugins' ) ) {
			return $links;
		}
		add_thickbox();
		$url     = add_query_arg(
			array(
				'action'    => 'tokenforge_changelog',
				'_wpnonce'  => wp_create_nonce( 'tokenforge_changelog' ),
				'TB_iframe' => 'true',
				'width'     => 772,
				'height'    => 700,
			),
			admin_url( 'admin-ajax.php' )
		);
		$links[] = '<a href="' . esc_url( $url ) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr__( 'TokenForge changelog', 'tokenforge' ) . '">' . esc_html__( 'Changelog', 'tokenforge' ) . '</a>';
		return $links;
	}

	/**
	 * Markdown (the small subset CHANGELOG.md uses) to safe HTML.
	 *
	 * @param string $md Markdown.
	 * @return string
	 */
	public static function to_html( $md ) {
		$html = '';
		$list = false;
		$para = array();
		$flush = function () use ( &$html, &$para ) {
			if ( $para ) {
				$html .= '<p>' . implode( ' ', $para ) . "</p>\n";
				$para  = array();
			}
		};
		$inline = function ( $t ) {
			$t = esc_html( $t );
			$t = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $t );
			return preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t );
		};
		foreach ( preg_split( '/\r?\n/', (string) $md ) as $line ) {
			if ( preg_match( '/^(#{1,4})\s+(.*)$/', $line, $m ) ) {
				$flush();
				if ( $list ) {
					$html .= "</ul>\n";
					$list  = false;
				}
				$level = min( 4, strlen( $m[1] ) );
				$html .= "<h$level>" . $inline( $m[2] ) . "</h$level>\n";
			} elseif ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				$flush();
				if ( ! $list ) {
					$html .= "<ul>\n";
					$list  = true;
				}
				$html .= '<li>' . $inline( $m[1] ) . "</li>\n";
			} elseif ( '' === trim( $line ) ) {
				$flush();
				if ( $list ) {
					$html .= "</ul>\n";
					$list  = false;
				}
			} elseif ( $list && preg_match( '/^\s{2,}(\S.*)$/', $line, $m ) ) {
				$html = preg_replace( '/<\/li>\n$/', ' ' . $inline( $m[1] ) . "</li>\n", $html );
			} else {
				$para[] = $inline( trim( $line ) );
			}
		}
		$flush();
		if ( $list ) {
			$html .= "</ul>\n";
		}
		return $html;
	}

	/**
	 * The popup page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this page.', 'tokenforge' ), 403 );
		}
		check_admin_referer( 'tokenforge_changelog' );
		$file = TOKENFORGE_DIR . 'CHANGELOG.md';
		$md   = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'TokenForge changelog', 'tokenforge' ); ?></title>
<style>
body { margin: 0; padding: 24px 32px; font: 14px/1.6 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; background: #fff; }
h1 { font-size: 22px; margin: 0 0 16px; }
h2 { font-size: 17px; margin: 28px 0 8px; padding-top: 18px; border-top: 1px solid #dcdcde; }
h2:first-of-type { border-top: 0; padding-top: 0; }
h3, h4 { font-size: 14px; margin: 16px 0 6px; }
ul { margin: 0 0 12px; padding-left: 20px; }
li { margin: 4px 0; }
code { background: #f0f0f1; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
</style>
</head>
<body>
		<?php echo self::to_html( $md ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped text in to_html() ?>
</body>
</html>
		<?php
		exit;
	}
}
