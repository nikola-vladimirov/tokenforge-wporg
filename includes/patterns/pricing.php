<?php
/**
 * Pattern: three pricing plans.
 *
 * @package TokenForge
 */

defined( 'ABSPATH' ) || exit;

$tokenforge_plans = '';
foreach ( array(
	array( __( 'Starter', 'tokenforge' ), '9' ),
	array( __( 'Team', 'tokenforge' ), '29' ),
	array( __( 'Business', 'tokenforge' ), '79' ),
) as $tokenforge_plan ) {
	$tokenforge_plans .= '<!-- wp:group {"className":"pad--m radius-m bg-base-ultra-light"} -->
<div class="wp-block-group pad--m radius-m bg-base-ultra-light"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">' . esc_html( $tokenforge_plan[0] ) . '</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"text-xl"} -->
<p class="text-xl">' . esc_html( $tokenforge_plan[1] ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>' . esc_html__( 'What is included', 'tokenforge' ) . '</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>' . esc_html__( 'Another thing included', 'tokenforge' ) . '</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-tf-primary"} -->
<div class="wp-block-button is-style-tf-primary"><a class="wp-block-button__link wp-element-button">' . esc_html__( 'Choose plan', 'tokenforge' ) . '</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

';
}

return array(
	'title'       => __( 'Pricing', 'tokenforge' ),
	'description' => __( 'Three plans with a price, a list and a button.', 'tokenforge' ),
	'content'     => '<!-- wp:group {"className":"section--m","layout":{"type":"constrained"}} -->
<div class="wp-block-group section--m"><!-- wp:heading {"className":"text-center"} -->
<h2 class="wp-block-heading text-center">' . esc_html__( 'Plans', 'tokenforge' ) . '</h2>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","className":"grid-3 gap-m"} -->
<div class="wp-block-group alignwide grid-3 gap-m">' . trim( $tokenforge_plans ) . '</div>
<!-- /wp:group --></div>
<!-- /wp:group -->',
);
