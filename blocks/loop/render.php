<?php
/**
 * Loop: the item (the block's inner blocks) once per post of the query, in the theme's grid (.grid.cols-N, one cell
 * per post). Each post is the current one while its item renders, and its id and type are the blocks' context, so
 * core post blocks, Field blocks, bindings and {{ tokens }} all show that post.
 *
 * With a repeating group as its source, once per row instead (mcc_loop_rows): the row is the current one while its
 * item renders, so its fields are what Field blocks, bindings and {{ tokens }} show.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

$rows  = '' !== $attributes['source'] ? mcc_loop_rows( $attributes ) : null;
$query = null === $rows ? mcc_loop_query( $attributes ) : null;
$grid  = trim( sprintf( 'grid cols-%d %s', max( 1, (int) $attributes['cols'] ), $attributes['gap'] ) );

if ( null === $rows ? ! $query->have_posts() : ! $rows ) {
	printf( '<p %s>%s</p>', get_block_wrapper_attributes( array( 'class' => 'loop-empty' ) ), esc_html( $attributes['empty'] ) );
	return;
}

// An item of several blocks gets a cell of its own, so it stays one grid cell.
$inner = $block->parsed_block['innerBlocks'];
$cell  = count( $inner ) > 1;

if ( null !== $rows ) {
	$fields  = mcc_groups()[ $attributes['source'] ]['fields'];
	$context = array( 'postId' => get_the_ID(), 'postType' => get_post_type(), 'marccent/source' => $attributes['source'] );
	$items   = '';
	foreach ( $rows as $values ) {
		$GLOBALS['mcc_rows'][] = array( 'fields' => $fields, 'values' => $values );
		$html                  = '';
		foreach ( $inner as $item ) {
			$html .= ( new WP_Block( $item, $context ) )->render();
		}
		array_pop( $GLOBALS['mcc_rows'] );
		$items .= $cell ? '<div class="grid cols-1 gap-sm content-start">' . $html . '</div>' : $html;
	}
	printf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => $grid ) ), $items ); // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks
	return;
}

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
