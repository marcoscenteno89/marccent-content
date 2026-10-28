<?php
/**
 * The spec: the site's post types, their taxonomies and fields, and fields on types that already exist (post, page).
 * Saved as JSON in the marccent_content option (Settings → Content), or given in code through the
 * marccent_content_spec filter. For example:
 *
 *   {
 *     "currency": "$",
 *     "types": {
 *       "project": {
 *         "label": "Projects", "singular": "Project", "icon": "dashicons-portfolio", "slug": "projects",
 *         "supports": [ "title", "editor", "thumbnail", "excerpt" ],
 *         "taxonomies": { "project_service": { "label": "Services", "singular": "Service", "hierarchical": true } },
 *         "fields": {
 *           "city": { "type": "string", "label": "City" },
 *           "year": { "type": "number", "label": "Year" },
 *           "before": { "type": "image", "label": "Before photo" }
 *         }
 *       }
 *     },
 *     "fields": { "post": { "reading_time": { "type": "number", "label": "Reading time", "suffix": " min" } } }
 *   }
 *
 * Field types: string, text (several lines), number, url, email, date, boolean, image (an attachment), select (with
 * "options": [ "a", "b" ]). A field may have a help line, a prefix and a suffix (shown around its value). Fields are
 * post meta under their own name, in the REST API, so the editor reads and saves them.
 */

defined( 'ABSPATH' ) || exit;

const MCC_FIELD_TYPES = array( 'string', 'text', 'number', 'url', 'email', 'date', 'boolean', 'image', 'select' );

function mcc_spec(): array {
	static $spec = null;
	if ( null === $spec ) {
		$saved = json_decode( (string) get_option( 'marccent_content', '{}' ), true );
		$spec  = apply_filters( 'marccent_content_spec', is_array( $saved ) ? $saved : array() );
		$spec += array( 'types' => array(), 'fields' => array(), 'currency' => '$' );
	}
	return $spec;
}

// Every type's fields by post type: the types' own and those added to existing types.
function mcc_fields( ?string $post_type = null ): array {
	$spec   = mcc_spec();
	$fields = array_map( fn( $t ) => $t['fields'] ?? array(), $spec['types'] );
	foreach ( $spec['fields'] as $type => $own ) {
		$fields[ $type ] = array_merge( $fields[ $type ] ?? array(), $own );
	}
	foreach ( $fields as $type => &$list ) {
		foreach ( $list as $name => &$f ) {
			$f = array_merge( array( 'label' => ucfirst( str_replace( '_', ' ', $name ) ), 'type' => 'string' ), $f );
			if ( ! in_array( $f['type'], MCC_FIELD_TYPES, true ) ) {
				$f['type'] = 'string';
			}
		}
	}
	unset( $list, $f );
	return null === $post_type ? $fields : ( $fields[ $post_type ] ?? array() );
}

function mcc_register_types(): void {
	foreach ( mcc_spec()['types'] as $name => $t ) {
		$label    = $t['label'] ?? ucfirst( $name ) . 's';
		$singular = $t['singular'] ?? ucfirst( $name );
		register_post_type( $name, array(
			'labels'       => array(
				'name'          => $label,
				'singular_name' => $singular,
				'add_new_item'  => sprintf( __( 'Add %s', 'marccent-content' ), $singular ),
				'edit_item'     => sprintf( __( 'Edit %s', 'marccent-content' ), $singular ),
				'all_items'     => sprintf( __( 'All %s', 'marccent-content' ), $label ),
			),
			'public'       => $t['public'] ?? true,
			'has_archive'  => $t['archive'] ?? true,
			'rewrite'      => array( 'slug' => $t['slug'] ?? $name, 'with_front' => false ),
			'menu_icon'    => $t['icon'] ?? 'dashicons-admin-post',
			'show_in_rest' => true,
			// custom-fields: the fields are in the REST API (the editor's fields panel saves through it).
			'supports'     => array_values( array_unique( array_merge( $t['supports'] ?? array( 'title', 'editor', 'thumbnail', 'excerpt' ), array( 'custom-fields' ) ) ) ),
		) );
		foreach ( $t['taxonomies'] ?? array() as $tax => $x ) {
			register_taxonomy( $tax, $name, array(
				'labels'            => array( 'name' => $x['label'] ?? ucfirst( $tax ), 'singular_name' => $x['singular'] ?? ( $x['label'] ?? ucfirst( $tax ) ) ),
				'hierarchical'      => $x['hierarchical'] ?? true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => $x['slug'] ?? $tax, 'with_front' => false ),
			) );
		}
	}

	foreach ( mcc_fields() as $post_type => $fields ) {
		foreach ( $fields as $name => $f ) {
			register_post_meta( $post_type, $name, array(
				'type'              => array( 'number' => 'number', 'boolean' => 'boolean', 'image' => 'integer' )[ $f['type'] ] ?? 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'label'             => $f['label'],
				'sanitize_callback' => fn( $value ) => mcc_sanitize( $value, $f ),
				'auth_callback'     => fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
			) );
		}
	}
}
add_action( 'init', 'mcc_register_types' );

function mcc_sanitize( $value, array $f ) {
	switch ( $f['type'] ) {
		case 'number':
			return is_numeric( $value ) ? 0 + $value : 0;
		case 'boolean':
			return (bool) $value;
		case 'image':
			return absint( $value );
		case 'url':
			return esc_url_raw( (string) $value );
		case 'email':
			return sanitize_email( (string) $value );
		case 'text':
			return sanitize_textarea_field( (string) $value );
		case 'select':
			return in_array( $value, $f['options'] ?? array(), true ) ? $value : '';
		default:
			return sanitize_text_field( (string) $value );
	}
}
