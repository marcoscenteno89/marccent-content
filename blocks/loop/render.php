<?php
/**
 * Loop: the item (the block's inner blocks) once per post of the query, in the theme's grid (.grid.cols-N, one cell
 * per post). Each post is the current one while its item renders, and its id and type are the blocks' context, so
 * core post blocks, Field blocks, bindings and {{ tokens }} all show that post.
 *
 * With a repeating group as its source, once per row instead (mcc_loop_rows): the row is the current one while its
 * item renders, so its fields are what Field blocks, bindings and {{ tokens }} show.
 *
 * The current post, which a relation ("the posts whose account is this one") and rows from "the page being viewed"
 * are read from, is the block's context: inside another Loop, that Loop's item; else the page's own. So Loops nest,
 * each level related to the one around it (an account's work orders' products).
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

$current = (int) ( $block->context['postId'] ?? 0 ) ?: ( is_singular() ? get_queried_object_id() : 0 );
$rows    = '' !== $attributes['source'] ? mcc_loop_rows( $attributes, $current ) : null;
$query   = null === $rows ? mcc_loop_query( $attributes, $current ) : null;
$grid  = trim( sprintf( 'grid cols-%d %s', max( 1, (int) $attributes['cols'] ), $attributes['gap'] ) );

if ( null === $rows ? ! $query->have_posts() : ! $rows ) {
	printf( '<p %s>%s</p>', get_block_wrapper_attributes( array( 'class' => 'loop-empty' ) ), esc_html( $attributes['empty'] ) );
	return;
}

// An item of several blocks gets a cell of its own, so it stays one grid cell.
$inner  = $block->parsed_block['innerBlocks'];
$cell   = count( $inner ) > 1;
$markup = serialize_blocks( $inner ); // what the item reads: the relations to load up front

if ( null !== $rows ) {
	$fields  = mcc_groups()[ $attributes['source'] ]['fields'];
	$context = array( 'postId' => $current, 'postType' => get_post_type( $current ), 'marccent/source' => $attributes['source'] );
	$items   = '';
	mcc_prime_related( $fields, $rows, $markup );
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
$outer = $post; // inside another Loop, its item: back to it after, not to the page's post
$items = '';
mcc_prime_related( mcc_fields( $query->posts[0]->post_type ), wp_list_pluck( $query->posts, 'ID' ), $markup );
foreach ( $query->posts as $post ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the loop's current post, reset below
	setup_postdata( $post );
	$html = '';
	foreach ( $inner as $item ) {
		$html .= ( new WP_Block( $item, array( 'postId' => $post->ID, 'postType' => $post->post_type ) ) )->render();
	}
	$items .= $cell ? '<div class="grid cols-1 gap-sm content-start">' . $html . '</div>' : $html;
}
$post = $outer; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- put back
if ( $post ) {
	setup_postdata( $post );
} else {
	wp_reset_postdata();
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => $grid ) ); ?>><?php echo $items; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks ?></div>
