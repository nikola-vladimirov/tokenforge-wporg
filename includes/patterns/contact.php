<?php
/**
 * Pattern: contact (heading, text, a button).
 *
 * @package TokenForge
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'       => __( 'Contact', 'tokenforge' ),
	'description' => __( 'A heading, a short text and a button to get in touch.', 'tokenforge' ),
	'content'     => '<!-- wp:group {"className":"section--m text-center bg-primary-ultra-light","layout":{"type":"constrained"}} -->
<div class="wp-block-group section--m text-center bg-primary-ultra-light"><!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html__( 'Get in touch', 'tokenforge' ) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html__( 'Tell visitors how to reach you and when they can expect an answer.', 'tokenforge' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-tf-primary"} -->
<div class="wp-block-button is-style-tf-primary"><a class="wp-block-button__link wp-element-button">' . esc_html__( 'Contact us', 'tokenforge' ) . '</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->',
);
