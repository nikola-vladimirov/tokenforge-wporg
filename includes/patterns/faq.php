<?php
/**
 * Pattern: questions and answers (each opens on click).
 *
 * @package TokenForge
 */

defined( 'ABSPATH' ) || exit;

$tokenforge_items = '';
foreach ( array( __( 'First question?', 'tokenforge' ), __( 'Second question?', 'tokenforge' ), __( 'Third question?', 'tokenforge' ) ) as $tokenforge_question ) {
	$tokenforge_items .= '<!-- wp:details -->
<details class="wp-block-details"><summary>' . esc_html( $tokenforge_question ) . '</summary><!-- wp:paragraph -->
<p>' . esc_html__( 'Write the answer here.', 'tokenforge' ) . '</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

';
}

return array(
	'title'       => __( 'Questions and answers', 'tokenforge' ),
	'description' => __( 'A heading and three questions that open on click.', 'tokenforge' ),
	'content'     => '<!-- wp:group {"className":"section--m","layout":{"type":"constrained"}} -->
<div class="wp-block-group section--m"><!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html__( 'Common questions', 'tokenforge' ) . '</h2>
<!-- /wp:heading -->

' . trim( $tokenforge_items ) . '</div>
<!-- /wp:group -->',
);
