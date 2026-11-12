<?php
/**
 * Pattern: three cards in a grid.
 *
 * @package TokenForge
 */

defined( 'ABSPATH' ) || exit;

$tokenforge_cards = '';
foreach ( array( __( 'First point', 'tokenforge' ), __( 'Second point', 'tokenforge' ), __( 'Third point', 'tokenforge' ) ) as $tokenforge_title ) {
	$tokenforge_cards .= '<!-- wp:group {"className":"pad--m radius-m bg-base-ultra-light"} -->
<div class="wp-block-group pad--m radius-m bg-base-ultra-light"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">' . esc_html( $tokenforge_title ) . '</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html__( 'Describe it in a sentence or two.', 'tokenforge' ) . '</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

';
}

return array(
	'title'       => __( 'Cards', 'tokenforge' ),
	'description' => __( 'A heading and three cards side by side.', 'tokenforge' ),
	'content'     => '<!-- wp:group {"className":"section--m","layout":{"type":"constrained"}} -->
<div class="wp-block-group section--m"><!-- wp:heading {"className":"text-center"} -->
<h2 class="wp-block-heading text-center">' . esc_html__( 'What you get', 'tokenforge' ) . '</h2>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","className":"grid-3 gap-m"} -->
<div class="wp-block-group alignwide grid-3 gap-m">' . trim( $tokenforge_cards ) . '</div>
<!-- /wp:group --></div>
<!-- /wp:group -->',
);
