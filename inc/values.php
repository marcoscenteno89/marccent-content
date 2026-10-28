<?php
/**
 * A post's value by key, as plain text, for the Field block, the binding source and {{ tokens }}:
 *
 *   title, excerpt, content, date, modified, link, id, author, type (the type's singular name)
 *   image, image:<size> (the featured image's URL; large by default), image_alt
 *   terms:<taxonomy> (the post's terms, comma-separated)
 *   <field>, <field>:<size> for an image field: one of the spec's fields (inc/types.php), with its prefix and suffix
 *
 * and a format after a bar, "price | currency": currency (the spec's symbol, 2 decimals), number, date, upper,
 * lower, words:<n> (the first n words), raw (a field without its prefix and suffix).
 */

defined( 'ABSPATH' ) || exit;

// "price | currency" → [ 'price', 'currency' ].
function mcc_parse_key( string $key ): array {
	$parts = array_map( 'trim', explode( '|', $key, 2 ) );
	return array( $parts[0], $parts[1] ?? '' );
}

function mcc_value( int $post_id, string $key, string $format = '' ): string {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	// A password-protected post shows only its title, link and the like until its password is given.
	$hidden = post_password_required( $post );
	[ $name, $arg ] = array_pad( explode( ':', $key, 2 ), 2, '' );
	$field          = mcc_fields( $post->post_type )[ $name ] ?? null;
	$text           = fn( $s ) => trim( html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) );

	switch ( true ) {
		case 'title' === $name:
			$value = $text( get_the_title( $post ) );
			break;
		case $hidden && ( in_array( $name, array( 'excerpt', 'content' ), true ) || null !== $field ):
			$value = '';
			break;
		case 'excerpt' === $name:
			$value = $text( get_the_excerpt( $post ) );
			break;
		case 'content' === $name:
			$value = $text( strip_shortcodes( $post->post_content ) );
			break;
		case 'date' === $name:
			$value = get_the_date( '', $post );
			break;
		case 'modified' === $name:
			$value = get_the_modified_date( '', $post );
			break;
		case 'link' === $name:
			$value = (string) get_permalink( $post );
			break;
		case 'id' === $name:
			$value = (string) $post->ID;
			break;
		case 'author' === $name:
			$value = get_the_author_meta( 'display_name', $post->post_author );
			break;
		case 'type' === $name:
			$value = get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type;
			break;
		case 'image' === $name:
			$value = (string) get_the_post_thumbnail_url( $post, $arg ?: 'large' );
			break;
		case 'image_alt' === $name:
			$value = (string) get_post_meta( (int) get_post_thumbnail_id( $post ), '_wp_attachment_image_alt', true );
			break;
		case 'terms' === $name:
			$terms = get_the_terms( $post, $arg );
			$value = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
			break;
		case null !== $field:
			$value = mcc_field_value( $post->ID, $name, $field, $arg );
			if ( '' !== $value && 'raw' !== $format && ! in_array( $field['type'], array( 'url', 'image' ), true ) ) {
				$value = ( $field['prefix'] ?? '' ) . $value . ( $field['suffix'] ?? '' );
			}
			break;
		default:
			$value = '';
	}
	return mcc_format( $value, $format );
}

// A field's saved value as text: an image's URL, Yes / No, a date in the site's format.
function mcc_field_value( int $post_id, string $name, array $field, string $size = '' ): string {
	$raw = get_post_meta( $post_id, $name, true );
	switch ( $field['type'] ) {
		case 'image':
			return $raw ? (string) wp_get_attachment_image_url( (int) $raw, $size ?: 'large' ) : '';
		case 'boolean':
			return $raw ? __( 'Yes', 'marccent-content' ) : __( 'No', 'marccent-content' );
		case 'date':
			return $raw ? date_i18n( get_option( 'date_format' ), strtotime( $raw ) ) : '';
		case 'number':
			return '' === $raw ? '' : (string) $raw;
		default:
			return (string) $raw;
	}
}

function mcc_format( string $value, string $format ): string {
	if ( '' === $value || '' === $format ) {
		return $value;
	}
	[ $name, $arg ] = array_pad( explode( ':', $format, 2 ), 2, '' );
	switch ( $name ) {
		case 'currency':
			$number = (float) preg_replace( '/[^0-9.\-]/', '', $value );
			return mcc_spec()['currency'] . number_format_i18n( $number, floor( $number ) == $number ? 0 : 2 );
		case 'number':
			return number_format_i18n( (float) preg_replace( '/[^0-9.\-]/', '', $value ) );
		case 'date':
			$time = strtotime( $value );
			return $time ? date_i18n( $arg ?: get_option( 'date_format' ), $time ) : $value;
		case 'upper':
			return mb_strtoupper( $value );
		case 'lower':
			return mb_strtolower( $value );
		case 'words':
			return wp_trim_words( $value, max( 1, (int) $arg ) );
		default:
			return $value;
	}
}
