<?php
/**
 * Pattern: hero (heading, lead text, two buttons).
 *
 * @package TokenForge
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Hero', 'tokenforge' ),
	'description' => __( 'A large heading, a short text and two buttons.', 'tokenforge' ),
	'content'     => '<!-- wp:group {"className":"section--m text-center","layout":{"type":"constrained"}} -->
<div class="wp-block-group section--m text-center"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">' . esc_html__( 'Say what you do in one line', 'tokenforge' ) . '</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"text-l"} -->
<p class="text-l">' . esc_html__( 'Add one or two sentences that tell visitors why it matters to them.', 'tokenforge' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-tf-primary"} -->
<div class="wp-block-button is-style-tf-primary"><a class="wp-block-button__link wp-element-button">' . esc_html__( 'Get started', 'tokenforge' ) . '</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">' . esc_html__( 'Learn more', 'tokenforge' ) . '</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->',
);
