<?php
/**
 * A form's entries as posts of a type made in Fields: the Form block's "Save entries to" (Marccent Blocks) names the
 * type, and each entry is saved here as a private post of it, never shown on the site. A type for entries is usually
 * "Only in wp-admin" (Fields → Post types), with a group of:
 *
 *   a json field (Data)       everything the visitor sent, by the form's field names: {{ answers:email }}
 *   a field named as a form field (email, phone…)   that answer too, as a field of its own, to sort or filter by
 *   form                      the form's ID
 *   page                      the page it was sent from (a relation to pages, or text: its title)
 *   status                    a choice from a list: the first choice (New)
 *
 * Fields it doesn't have are left out; the entry's title says where it came from.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'marccent_forms_save_entry', function ( $id, string $type, array $form, array $values ) {
	if ( $id || ! isset( mcc_spec()['types'][ $type ] ) ) {
		return $id;
	}
	$answers = array();
	foreach ( array_keys( $form['fields'] ) as $name ) {
		$answers[ $name ] = (string) ( $values[ $name ] ?? '' );
	}
	$page = $form['post'];
	$id   = wp_insert_post( array(
		'post_type'   => $type,
		'post_status' => 'private',
		/* translators: %s: the page the form is on */
		'post_title'  => sprintf( __( 'Entry from “%s”', 'marccent-content' ), wp_strip_all_tags( get_the_title( $page ) ) ),
	), true );
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	$json = false;
	foreach ( mcc_fields( $type ) as $name => $f ) {
		if ( 'json' === $f['type'] && ! $json ) {
			$value = $answers;
			$json  = true;
		} elseif ( array_key_exists( $name, $answers ) ) {
			$value = $answers[ $name ];
		} elseif ( 'form' === $name ) {
			$value = (string) $form['attrs']['formId'];
		} elseif ( 'page' === $name ) {
			$value = 'relation' === $f['type'] ? $page->ID : wp_strip_all_tags( get_the_title( $page ) );
		} elseif ( 'status' === $name && 'select' === $f['type'] ) {
			$value = (string) ( $f['options'][0] ?? '' );
		} else {
			continue;
		}
		$clean = mcc_sanitize( $value, $f );
		if ( mcc_is_many( $f ) ) {
			foreach ( (array) $clean as $one ) {
				add_post_meta( $id, $name, $one );
			}
		} else {
			update_post_meta( $id, $name, wp_slash( $clean ) );
		}
	}
	return (int) $id;
}, 10, 4 );
