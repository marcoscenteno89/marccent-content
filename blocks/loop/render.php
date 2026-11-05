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
 * With paging (paginate), its pages' links under it; with filters (a field's name each, or terms:<taxonomy>) or a
 * search box, a form above it that visitors narrow it with. Both are in the address (mcc_loop_visitor), so a filtered
 * page can be shared and works without JavaScript.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

$current = (int) ( $block->context['postId'] ?? 0 ) ?: ( is_singular() ? get_queried_object_id() : 0 );
$n       = $GLOBALS['mcc_loop_n'] = ( $GLOBALS['mcc_loop_n'] ?? 0 ) + 1; // this Loop's number on the page
// A where can read the item around it, an outer Loop's row or post: the cities of each region, mcl_region={{ key }}.
// A token with nothing to fill it stays as it is, and matches nothing.
if ( str_contains( (string) $attributes['where'], '{{' ) ) {
	$GLOBALS['mcc_no_visitor'] = true; // what a visitor typed ({{ search }}) never decides what a Loop lists
	$attributes['where']       = mcc_fill( $attributes['where'], $current );
	$GLOBALS['mcc_no_visitor'] = false;
}
$visitor = mcc_loop_visitor( $attributes, $n );
$total   = 0;
$rows    = '' !== $attributes['source'] ? mcc_loop_rows( $attributes, $current, $visitor, $total ) : null;
$query   = null === $rows ? mcc_loop_query( $attributes, $current, $visitor ) : null;
$grid    = trim( sprintf( 'grid cols-%d %s', max( 1, (int) $attributes['cols'] ), $attributes['gap'] ) );
$per     = max( 1, (int) $attributes['perPage'] );
$pages   = $visitor['paged'] ? (int) ceil( ( null === $rows ? max( 0, $query->found_posts - (int) $attributes['offset'] ) : $total ) / $per ) : 1;
$filters = mcc_loop_filters_html( $attributes, $n, $visitor );
$pager   = mcc_loop_pager_html( $n, min( $visitor['page'], max( 1, $pages ) ), $pages );
// The filters and pages around the grid, in a box the links come back to (#mcc-loop-2).
$wrap = fn( $html ) => $filters || $visitor['paged'] ? sprintf( '<div id="mcc-loop-%d" class="mcc-loop">%s%s%s</div>', $n, $filters, $html, $pager ) : $html;

if ( null === $rows ? ! $query->have_posts() : ! $rows ) {
	echo $wrap( sprintf( '<p %s>%s</p>', get_block_wrapper_attributes( array( 'class' => 'loop-empty' ) ), esc_html( $attributes['empty'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped as built
	return;
}

// An item of several blocks gets a cell of its own, so it stays one grid cell.
$inner  = $block->parsed_block['innerBlocks'];
$cell   = count( $inner ) > 1;
$markup = serialize_blocks( $inner ); // what the item reads: the relations to load up front
$first  = ( $visitor['paged'] ? ( max( 1, (int) $visitor['page'] ) - 1 ) * $per : 0 ) + 1; // {{ loop:number }} of the first item

if ( null !== $rows ) {
	$fields  = mcc_groups()[ $attributes['source'] ]['fields'];
	$context = array( 'postId' => $current, 'postType' => get_post_type( $current ), 'marccent/source' => $attributes['source'] );
	$items   = '';
	mcc_prime_related( $fields, $rows, $markup );
	foreach ( $rows as $i => $values ) {
		$GLOBALS['mcc_rows'][]     = array( 'fields' => $fields, 'values' => $values );
		$GLOBALS['mcc_loop_pos'][] = $first + $i;
		$html                      = '';
		foreach ( $inner as $item ) {
			$html .= ( new WP_Block( $item, $context ) )->render();
		}
		array_pop( $GLOBALS['mcc_rows'] );
		array_pop( $GLOBALS['mcc_loop_pos'] );
		$items .= $cell ? '<div class="grid cols-1 gap-sm content-start">' . $html . '</div>' : $html;
	}
	mcc_schema_collect_rows( $attributes['source'], $rows, $current ); // the rows in the page's JSON-LD (inc/schema.php)
	echo $wrap( sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => $grid ) ), $items ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks
	return;
}

global $post;
$outer = $post; // inside another Loop, its item: back to it after, not to the page's post
$items = '';
mcc_prime_related( mcc_fields( $query->posts[0]->post_type ), wp_list_pluck( $query->posts, 'ID' ), $markup );
foreach ( $query->posts as $i => $post ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the loop's current post, reset below
	setup_postdata( $post );
	$GLOBALS['mcc_loop_pos'][] = $first + $i;
	$html = '';
	foreach ( $inner as $item ) {
		$html .= ( new WP_Block( $item, array( 'postId' => $post->ID, 'postType' => $post->post_type ) ) )->render();
	}
	array_pop( $GLOBALS['mcc_loop_pos'] );
	$items .= $cell ? '<div class="grid cols-1 gap-sm content-start">' . $html . '</div>' : $html;
}
mcc_schema_collect_posts( $query->posts, $n ); // the posts in the page's JSON-LD (inc/schema.php)
$post = $outer; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- put back
if ( $post ) {
	setup_postdata( $post );
} else {
	wp_reset_postdata();
}
echo $wrap( sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => $grid ) ), $items ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- rendered blocks
