<?php
/**
 * A post's value by key, as plain text, for the Field block, the binding source and {{ tokens }}:
 *
 *   title, excerpt, content, date, modified, link, id, author, type (the type's singular name)
 *   image, image:<size> (the featured image's URL; large by default), image_alt
 *   terms:<taxonomy> (the post's terms, comma-separated)
 *   <field>, <field>:<size> for an image field: one of the spec's fields (inc/types.php), with its prefix and suffix
 *   <image field>:alt: the image's alt text
 *   <group>.<field>: a field of a group that doesn't repeat (the post's, else the site's): "office.phone"
 *
 * Inside a Loop of a group's rows, the row's fields come first: "caption", "image:medium".
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

// The Loop's rows being rendered, innermost last: [ 'fields' => the group's fields, 'values' => the row ].
$GLOBALS['mcc_rows'] = array();

function mcc_row(): ?array {
	return $GLOBALS['mcc_rows'] ? end( $GLOBALS['mcc_rows'] ) : null;
}

// The field a key names, where the value comes from: the current row's, a group's ("office.phone") or the post's.
function mcc_field_def( int $post_id, string $key ): ?array {
	$name = explode( ':', mcc_parse_key( $key )[0], 2 )[0];
	$row  = mcc_row();
	if ( $row && isset( $row['fields'][ $name ] ) ) {
		return $row['fields'][ $name ];
	}
	if ( str_contains( $name, '.' ) ) {
		[ $group, $sub ] = explode( '.', $name, 2 );
		return mcc_groups()[ $group ]['fields'][ $sub ] ?? null;
	}
	return $post_id ? ( mcc_fields( (string) get_post_type( $post_id ) )[ $name ] ?? null ) : null;
}

function mcc_value( int $post_id, string $key, string $format = '' ): string {
	[ $name, $arg ] = array_pad( explode( ':', $key, 2 ), 2, '' );

	$row = mcc_row();
	if ( $row && isset( $row['fields'][ $name ] ) ) {
		$field = $row['fields'][ $name ];
		return mcc_format( mcc_affix( mcc_display_value( $row['values'][ $name ] ?? '', $field, $arg ), $field, $format ), $format );
	}
	if ( str_contains( $name, '.' ) ) {
		[ $group, $sub ] = explode( '.', $name, 2 );
		$g     = mcc_groups()[ $group ] ?? null;
		$field = $g['fields'][ $sub ] ?? null;
		if ( ! $field || $g['repeat'] ) {
			return '';
		}
		$values = mcc_group_value( $group, $post_id );
		return mcc_format( mcc_affix( mcc_display_value( $values[ $sub ] ?? '', $field, $arg ), $field, $format ), $format );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	// A password-protected post shows only its title, link and the like until its password is given.
	$hidden = post_password_required( $post );
	$field  = mcc_fields( $post->post_type )[ $name ] ?? null;
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
			$value = mcc_affix( mcc_field_value( $post->ID, $name, $field, $arg ), $field, $format );
			break;
		default:
			$value = '';
	}
	return mcc_format( $value, $format );
}

// A field's prefix and suffix around its value (not around a URL or an image, nor with the raw format).
function mcc_affix( string $value, array $field, string $format ): string {
	if ( '' === $value || 'raw' === $format || in_array( $field['type'], array( 'url', 'image' ), true ) ) {
		return $value;
	}
	return ( $field['prefix'] ?? '' ) . $value . ( $field['suffix'] ?? '' );
}

function mcc_field_value( int $post_id, string $name, array $field, string $size = '' ): string {
	return mcc_display_value( get_post_meta( $post_id, $name, true ), $field, $size );
}

// A field's saved value as text: an image's URL (or its alt text, "alt"), Yes / No, a date in the site's format.
function mcc_display_value( $raw, array $field, string $size = '' ): string {
	if ( is_array( $raw ) || is_object( $raw ) ) {
		return '';
	}
	switch ( $field['type'] ) {
		case 'image':
			if ( 'alt' === $size ) {
				return $raw ? (string) get_post_meta( (int) $raw, '_wp_attachment_image_alt', true ) : '';
			}
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
