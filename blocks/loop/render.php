<?php
/**
 * Loop: the item (the block's inner blocks) once per post of the query, in the theme's grid (.grid.cols-N, one cell
 * per post). Each post is the current one while its item renders, and its id and type are the blocks' context, so
 * core post blocks, Field blocks, bindings and {{ tokens }} all show that post.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

$query = mcc_loop_query( $attributes );
$grid  = trim( sprintf( 'grid cols-%d %s', max( 1, (int) $attributes['cols'] ), $attributes['gap'] ) );

if ( ! $query->have_posts() ) {
	printf( '<p %s>%s</p>', get_block_wrapper_attributes( array( 'class' => 'loop-empty' ) ), esc_html( $attributes['empty'] ) );
	return;
}

// An item of several blocks gets a cell of its own, so it stays one grid cell.
$inner = $block->parsed_block['innerBlocks'];
$cell  = count( $inner ) > 1;

global $post;
$items = '';
foreach ( $query->posts as $post ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the loop's current post, reset below
	setup_postdata( $post );
	$html = '';
	foreach ( $inner as $item ) {
		$html .= ( new WP_Block( $item, array( 'postId' => $post->ID, 'postType' => $post->post_type ) ) )->render();
	}
	$items .= $cell ? '<div class="grid cols-1 gap-sm content-start">' . $html . '</div>' : $html;
}
wp_reset_postdata();
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => $grid ) ); ?>><?php echo $items; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks ?></div>
