<?php
/**
 * Field: one value of the post or the Loop's row (inc/values.php) in the chosen tag, optionally linked to the post and
 * after a label ("Price: "). An image (the featured image, an image field) is an <img> with its alt text (else the
 * post's title); a URL field is a link; a relation, the related posts' titles linked to their pages. Nothing at all
 * when the value is empty, so a card has no blank line.
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
	$alt   = mcc_value( $post_id, $field ? "$name:alt" : 'image_alt' ) ?: mcc_value( $post_id, 'title' );
	$style = 'display: block; height: auto; aspect-ratio: 4 / 3; object-fit: cover';
	// WordPress's own markup when the image is known (its width and height, so nothing jumps as it loads; srcset and
	// sizes; lazy or first-priority as WordPress decides), else the URL.
	$row = mcc_row();
	$id  = ! $field ? (int) get_post_thumbnail_id( $post_id ) : absint( $row && isset( $row['fields'][ $name ] ) ? ( $row['values'][ $name ] ?? 0 ) : ( str_contains( $name, '.' ) ? 0 : get_post_meta( $post_id, $name, true ) ) );
	$html = $id && wp_attachment_is_image( $id )
		? wp_get_attachment_image( $id, explode( ':', $key, 2 )[1] ?? 'large', false, array( 'alt' => $alt, 'class' => 'w-100 round', 'style' => $style, 'decoding' => 'async' ) )
		: sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async" class="w-100 round" style="%s">', esc_url( $value ), esc_attr( $alt ), $style );
	$tag  = 'figure';
} elseif ( 'relation' === ( $field['type'] ?? '' ) && ( $related = mcc_key_posts( $post_id, $key ) ) ) {
	// The related posts, each linked to its page (several: comma-separated).
	$html = implode( ', ', array_map( fn( $id ) => sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $id ) ), esc_html( wp_strip_all_tags( get_the_title( $id ) ) ) ), $related ) );
	$link = '';
	$tag  = in_array( $attributes['tag'], array( 'p', 'span', 'div', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $attributes['tag'] : 'p';
} elseif ( 'gallery' === ( $field['type'] ?? '' ) && ! str_contains( $key, ':count' ) ) {
	// Its images, in a grid of their own (the theme's), each with its alt text.
	$row  = mcc_row();
	$ids  = $row && isset( $row['fields'][ $name ] ) ? (array) ( $row['values'][ $name ] ?? array() ) : (array) get_post_meta( $post_id, $name, true );
	$size = explode( ':', $key, 2 )[1] ?? 'medium_large';
	$html = implode( '', array_map( fn( $id ) => wp_get_attachment_image( (int) $id, $size, false, array( 'class' => 'w-100 round', 'loading' => 'lazy', 'style' => 'display: block; height: auto; aspect-ratio: 4 / 3; object-fit: cover' ) ), array_filter( array_map( 'absint', $ids ) ) ) );
	printf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => 'grid cols-3 gap-sm' ) ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- WordPress's image markup
	return;
} elseif ( 'file' === ( $field['type'] ?? '' ) && ! str_contains( $key, ':name' ) ) {
	$html = sprintf( '<a href="%s" download>%s</a>', esc_url( $value ), esc_html( mcc_value( $post_id, "$name:name" ) ) );
	$link = '';
	$tag  = in_array( $attributes['tag'], array( 'p', 'span', 'div' ), true ) ? $attributes['tag'] : 'p';
} elseif ( 'html' === ( $field['type'] ?? '' ) ) {
	// Rich text, as it was saved (filtered as a post's content is).
	$row  = mcc_row();
	$raw  = $row && isset( $row['fields'][ $name ] ) ? (string) ( $row['values'][ $name ] ?? '' ) : ( str_contains( $name, '.' ) ? '' : (string) get_post_meta( $post_id, $name, true ) );
	$html = wp_kses_post( '' !== $raw ? $raw : esc_html( $value ) );
	$tag  = 'div';
} elseif ( 'location' === ( $field['type'] ?? '' ) && ! preg_match( '/:(lat|lng|map)$/', $key ) ) {
	// The address, linked to its map.
	$html = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( mcc_value( $post_id, "$name:map" ) ), esc_html( $value ) );
	$link = '';
	$tag  = in_array( $attributes['tag'], array( 'p', 'span', 'div' ), true ) ? $attributes['tag'] : 'p';
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
