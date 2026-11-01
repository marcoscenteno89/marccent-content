<?php
/**
 * Field: one value of the post or the Loop's row (inc/values.php) in the chosen tag, optionally linked to the post and
 * after a label ("Price: "). An image (the featured image, an image field) is an <img> with its alt text (else the
 * post's title); a URL field is a link. Nothing at all when the value is empty, so a card has no blank line.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

$post_id = mcc_context_post( $block );
[ $key, $format ] = mcc_parse_key( $attributes['key'] . ( $attributes['format'] ? ' | ' . $attributes['format'] : '' ) );
$value = $post_id || mcc_row() || str_contains( $key, '.' ) ? mcc_value( $post_id, $key, $format ) : '';
if ( '' === $value ) {
	return;
}

$name  = explode( ':', $key, 2 )[0];
$field = mcc_field_def( $post_id, $key ) ?? array();
$link  = $attributes['link'] && $post_id ? get_permalink( $post_id ) : '';

if ( 'image' === ( $field['type'] ?? ( 'image' === $name ? 'image' : '' ) ) ) {
	// The image's own alt text: the featured image's, or an image field's.
	$alt  = mcc_value( $post_id, $field ? "$name:alt" : 'image_alt' );
	$html = sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async" class="w-100 round" style="display: block; height: auto; aspect-ratio: 4 / 3; object-fit: cover">', esc_url( $value ), esc_attr( $alt ?: mcc_value( $post_id, 'title' ) ) );
	$tag  = 'figure';
} elseif ( 'url' === ( $field['type'] ?? '' ) ) {
	$html = sprintf( '<a href="%1$s">%1$s</a>', esc_url( $value ) );
	$link = '';
	$tag  = in_array( $attributes['tag'], array( 'p', 'span', 'div' ), true ) ? $attributes['tag'] : 'p';
} else {
	$html = esc_html( $value );
	$tag  = in_array( $attributes['tag'], array( 'p', 'span', 'div', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $attributes['tag'] : 'p';
}
if ( $link ) {
	$html = sprintf( '<a href="%s">%s</a>', esc_url( $link ), $html );
}
if ( $attributes['label'] ) {
	$html = sprintf( '<span class="fw-bold">%s</span> %s', esc_html( $attributes['label'] ), $html );
}
printf( '<%1$s %2$s>%3$s</%1$s>', $tag, get_block_wrapper_attributes(), $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
