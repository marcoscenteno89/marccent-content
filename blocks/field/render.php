<?php
/**
 * Field: one value of the post (inc/values.php) in the chosen tag, optionally linked to the post and after a label
 * ("Price: "). An image (the featured image, an image field) is an <img> with the post's title as its alt text; a URL
 * field is a link. Nothing at all when the value is empty, so a card has no blank line.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

$post_id = mcc_context_post( $block );
[ $key, $format ] = mcc_parse_key( $attributes['key'] . ( $attributes['format'] ? ' | ' . $attributes['format'] : '' ) );
$value = $post_id ? mcc_value( $post_id, $key, $format ) : '';
if ( '' === $value ) {
	return;
}

$name  = explode( ':', $key, 2 )[0];
$field = mcc_fields( (string) get_post_type( $post_id ) )[ $name ] ?? array();
$link  = $attributes['link'] ? get_permalink( $post_id ) : '';

if ( 'image' === $name || 'image' === ( $field['type'] ?? '' ) ) {
	$alt  = 'image' === $name ? mcc_value( $post_id, 'image_alt' ) : '';
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
