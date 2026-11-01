<?php
/**
 * The spec: the site's post types (with their taxonomies) and its field groups, kept in the database (the
 * marccent_content option), built under Fields → Field groups and Fields → Post types, or given in code through the
 * marccent_content_spec filter. For example:
 *
 *   {
 *     "currency": "$",
 *     "types": {
 *       "project": {
 *         "label": "Projects", "singular": "Project", "icon": "dashicons-portfolio", "slug": "projects",
 *         "supports": [ "title", "editor", "thumbnail", "excerpt" ],
 *         "taxonomies": { "project_service": { "label": "Services", "singular": "Service", "hierarchical": true } }
 *       }
 *     },
 *     "groups": {
 *       "project_details": { "label": "Project details", "on": [ "project" ], "fields": { "city": {}, "year": { "type": "number" } } },
 *       "office": { "label": "Office", "on": [ "options" ], "fields": { "phone": {}, "lat": { "type": "number" } } },
 *       "photos": { "label": "Photos", "repeat": true, "on": [ "page:gallery" ],
 *         "fields": { "image": { "type": "image" }, "caption": {}, "service": { "type": "select", "options": [ "a", "b" ] } } }
 *     }
 *   }
 *
 * A type with "noindex": true keeps its posts out of search results (robots noindex on each one's page), for types
 * that exist to be shown in loops rather than read on their own.
 *
 * Field groups, as ACF's: a named set of fields put "on" post types ("page"), on some pages only ("page:contact", by
 * slug) or on the site ("options": the Site content screen). Field types: string, text (several lines), number, url,
 * email, date, boolean, image (an attachment), select (with "options": [ "a", "b" ]). A field may have a help line, a
 * prefix and a suffix (shown around its value).
 *
 * Where values are kept: a group on posts, each field as post meta under its own name (in the REST API, so the editor
 * saves it); with "repeat": true, a list of rows a Loop can repeat its item for, as post meta under the group's name;
 * a group on the site, the mcc_<group> option. Things without a page of their own (offices, a gallery's photos, the
 * team) need no post type: a repeating group holds them.
 *
 * Older specs put fields straight on types ("types": { "x": { "fields" } }, "fields": { "page": {…} }); they're read
 * as a group each ("<type>_fields"), which saving the spec writes out.
 */

defined( 'ABSPATH' ) || exit;

const MCC_FIELD_TYPES = array( 'string', 'text', 'number', 'url', 'email', 'date', 'boolean', 'image', 'select' );

// A post's own values (inc/values.php): a field on posts can't have one of these names.
const MCC_RESERVED = array( 'title', 'excerpt', 'content', 'date', 'modified', 'link', 'id', 'author', 'type', 'image', 'image_alt', 'terms' );

function mcc_spec(): array {
	if ( ! isset( $GLOBALS['mcc_cache']['spec'] ) ) {
		$saved = json_decode( (string) get_option( 'marccent_content', '{}' ), true );
		$GLOBALS['mcc_cache']['spec'] = mcc_canonical( apply_filters( 'marccent_content_spec', is_array( $saved ) ? $saved : array() ) );
	}
	return $GLOBALS['mcc_cache']['spec'];
}

// After the spec changes in this request (saving, importing): read it again.
function mcc_spec_reset(): void {
	unset( $GLOBALS['mcc_cache'] );
}

// The spec in its current shape: types without fields, every field in a group.
function mcc_canonical( array $spec ): array {
	$types  = array();
	$groups = (array) ( $spec['groups'] ?? array() );
	$legacy = (array) ( $spec['fields'] ?? array() );
	foreach ( (array) ( $spec['types'] ?? array() ) as $name => $t ) {
		$t = (array) $t;
		if ( ! empty( $t['fields'] ) ) {
			$legacy[ $name ] = array_merge( (array) ( $legacy[ $name ] ?? array() ), (array) $t['fields'] );
		}
		unset( $t['fields'] );
		$types[ $name ] = $t;
	}
	foreach ( $legacy as $type => $fields ) {
		$key = "{$type}_fields";
		while ( isset( $groups[ $key ] ) ) {
			$key .= '_';
		}
		$groups[ $key ] = array( 'label' => ucfirst( str_replace( '_', ' ', $type ) ) . ' fields', 'on' => array( $type ), 'fields' => (array) $fields );
	}
	return array( 'currency' => (string) ( $spec['currency'] ?? '$' ), 'types' => $types, 'groups' => $groups );
}

// Fields with their label and a known type.
function mcc_normalize_fields( array $list ): array {
	foreach ( $list as $name => &$f ) {
		$f = array_merge( array( 'label' => ucfirst( str_replace( '_', ' ', $name ) ), 'type' => 'string' ), (array) $f );
		if ( ! in_array( $f['type'], MCC_FIELD_TYPES, true ) ) {
			$f['type'] = 'string';
		}
	}
	return $list;
}

// The field groups, each with its label, whether it repeats, where it's put and its fields.
function mcc_groups(): array {
	if ( ! isset( $GLOBALS['mcc_cache']['groups'] ) ) {
		$groups = array();
		foreach ( mcc_spec()['groups'] as $name => $g ) {
			$g               = (array) $g;
			$groups[ $name ] = array(
				'label'  => (string) ( $g['label'] ?? ucfirst( str_replace( '_', ' ', $name ) ) ),
				'repeat' => ! empty( $g['repeat'] ),
				'on'     => array_values( array_map( 'strval', (array) ( $g['on'] ?? array() ) ) ),
				'fields' => mcc_normalize_fields( (array) ( $g['fields'] ?? array() ) ),
			);
		}
		$GLOBALS['mcc_cache']['groups'] = $groups;
	}
	return $GLOBALS['mcc_cache']['groups'];
}

// The post types a group is on ("page:contact" is on pages).
function mcc_group_types( array $g ): array {
	return array_values( array_unique( array_diff( array_map( fn( $on ) => explode( ':', $on, 2 )[0], $g['on'] ), array( 'options' ) ) ) );
}

// Every post type's fields, from the groups on it that don't repeat (each field its own post meta).
function mcc_fields( ?string $post_type = null ): array {
	if ( ! isset( $GLOBALS['mcc_cache']['fields'] ) ) {
		$fields = array();
		foreach ( mcc_groups() as $g ) {
			if ( ! $g['repeat'] ) {
				foreach ( mcc_group_types( $g ) as $type ) {
					$fields[ $type ] = array_merge( $fields[ $type ] ?? array(), $g['fields'] );
				}
			}
		}
		$GLOBALS['mcc_cache']['fields'] = $fields;
	}
	$fields = $GLOBALS['mcc_cache']['fields'];
	return null === $post_type ? $fields : ( $fields[ $post_type ] ?? array() );
}

// A group's REST schema: an object of its fields, or a list of them.
function mcc_group_schema( array $g ): array {
	$props = array();
	foreach ( $g['fields'] as $name => $f ) {
		$props[ $name ] = array( 'type' => array( 'number' => 'number', 'boolean' => 'boolean', 'image' => 'integer' )[ $f['type'] ] ?? 'string' );
	}
	$row = array( 'type' => 'object', 'properties' => $props, 'additionalProperties' => false );
	return $g['repeat'] ? array( 'type' => 'array', 'items' => $row ) : $row;
}

function mcc_group_sanitize( $value, array $g ): array {
	$row = function ( $r ) use ( $g ) {
		$out = array();
		foreach ( $g['fields'] as $name => $f ) {
			$out[ $name ] = mcc_sanitize( ( (array) $r )[ $name ] ?? '', $f );
		}
		return $out;
	};
	$value = (array) $value;
	return $g['repeat'] ? array_values( array_map( $row, array_filter( $value, fn( $r ) => is_array( $r ) || is_object( $r ) ) ) ) : $row( $value );
}

// A group's saved value: the post's, when the group is on its type; else the site's, when it's on the site.
function mcc_group_value( string $group, int $post_id = 0 ): array {
	$g = mcc_groups()[ $group ] ?? null;
	if ( ! $g ) {
		return array();
	}
	if ( $post_id && in_array( get_post_type( $post_id ), mcc_group_types( $g ), true ) ) {
		if ( post_password_required( $post_id ) ) {
			return array();
		}
		if ( $g['repeat'] ) {
			$value = get_post_meta( $post_id, $group, true );
		} else {
			$value = array();
			foreach ( array_keys( $g['fields'] ) as $name ) {
				$value[ $name ] = get_post_meta( $post_id, $name, true );
			}
		}
	} elseif ( in_array( 'options', $g['on'], true ) ) {
		$value = get_option( "mcc_$group", array() );
	} else {
		$value = array();
	}
	return is_array( $value ) ? $value : array();
}

/**
 * Checks a spec and saves it (the marccent_content option), so the next load registers it. Returns true, or a
 * WP_Error listing what's wrong (nothing is saved then).
 */
function mcc_save_spec( array $spec ) {
	$spec   = mcc_canonical( $spec );
	$errors = array();
	$name   = fn( $s, $max = 64 ) => is_string( $s ) && preg_match( '/^[a-z][a-z0-9_]{0,' . ( $max - 1 ) . '}$/', $s );

	$builtin = get_post_types( array( '_builtin' => true ) );
	foreach ( $spec['types'] as $type => $t ) {
		if ( ! $name( $type, 20 ) || isset( $builtin[ $type ] ) ) {
			/* translators: %s: a post type's name */
			$errors[] = sprintf( __( 'Post type “%s”: its name must be up to 20 lowercase letters, numbers or underscores, starting with a letter, and not one of WordPress’s own.', 'marccent-content' ), $type );
		}
		foreach ( array_keys( (array) ( $t['taxonomies'] ?? array() ) ) as $tax ) {
			if ( ! $name( $tax, 32 ) ) {
				/* translators: 1: a taxonomy's name, 2: its post type */
				$errors[] = sprintf( __( 'Taxonomy “%1$s” (%2$s): its name must be up to 32 lowercase letters, numbers or underscores.', 'marccent-content' ), $tax, $type );
			}
		}
	}

	$known = array_merge( array_keys( $spec['types'] ), array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ) );
	$keys  = array(); // per post type: meta key => the group that uses it
	foreach ( $spec['groups'] as $group => $g ) {
		$g     = (array) $g;
		$label = (string) ( $g['label'] ?? $group );
		if ( ! $name( $group ) ) {
			/* translators: %s: a field group's name */
			$errors[] = sprintf( __( 'Field group “%s”: its name must be lowercase letters, numbers or underscores, starting with a letter.', 'marccent-content' ), $group );
		}
		if ( ! (array) ( $g['on'] ?? array() ) ) {
			/* translators: %s: a field group's label */
			$errors[] = sprintf( __( '%s: choose where it goes (a post type, some pages, or the site).', 'marccent-content' ), $label );
		}
		foreach ( (array) ( $g['on'] ?? array() ) as $on ) {
			$type = explode( ':', (string) $on, 2 )[0];
			if ( 'options' !== $on && ! in_array( $type, $known, true ) ) {
				/* translators: 1: a field group's label, 2: a post type's name */
				$errors[] = sprintf( __( '%1$s: there is no post type “%2$s”.', 'marccent-content' ), $label, $type );
			}
		}
		foreach ( (array) ( $g['fields'] ?? array() ) as $field => $f ) {
			if ( ! $name( $field ) ) {
				/* translators: 1: a field group's label, 2: a field's name */
				$errors[] = sprintf( __( '%1$s: the field name “%2$s” must be lowercase letters, numbers or underscores, starting with a letter.', 'marccent-content' ), $label, $field );
			}
			if ( ! in_array( ( (array) $f )['type'] ?? 'string', MCC_FIELD_TYPES, true ) ) {
				/* translators: 1: a field group's label, 2: a field's name */
				$errors[] = sprintf( __( '%1$s: the field “%2$s” has an unknown type.', 'marccent-content' ), $label, $field );
			}
		}
		// On posts, a group's fields (or a repeating group itself) are post meta: their names can't clash.
		$types = array_unique( array_diff( array_map( fn( $on ) => explode( ':', (string) $on, 2 )[0], (array) ( $g['on'] ?? array() ) ), array( 'options' ) ) );
		$own   = empty( $g['repeat'] ) ? array_keys( (array) ( $g['fields'] ?? array() ) ) : array( $group );
		foreach ( $types as $type ) {
			foreach ( $own as $key ) {
				if ( in_array( $key, MCC_RESERVED, true ) ) {
					/* translators: 1: a field group's label, 2: a field's name */
					$errors[] = sprintf( __( '%1$s: “%2$s” is a value every post already has; give the field another name.', 'marccent-content' ), $label, $key );
				} elseif ( isset( $keys[ $type ][ $key ] ) ) {
					/* translators: 1: a field group's label, 2: a field's name, 3: a post type, 4: another field group's label */
					$errors[] = sprintf( __( '%1$s: “%2$s” is already used on %3$s by %4$s.', 'marccent-content' ), $label, $key, $type, $keys[ $type ][ $key ] );
				}
				$keys[ $type ][ $key ] = $label;
			}
		}
	}
	if ( $errors ) {
		return new WP_Error( 'mcc_spec', implode( "\n", array_unique( $errors ) ), array( 'status' => 400, 'errors' => array_values( array_unique( $errors ) ) ) );
	}

	update_option( 'marccent_content', wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	update_option( 'mcc_flush_rewrites', 1 );
	mcc_spec_reset();
	return true;
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

	// Repeating groups on posts: their rows, as one value under the group's name. (A group on the site is an option,
	// saved through the Site content screen: inc/admin.php.)
	foreach ( mcc_groups() as $name => $g ) {
		if ( ! $g['repeat'] ) {
			continue;
		}
		$schema = mcc_group_schema( $g );
		foreach ( mcc_group_types( $g ) as $post_type ) {
			register_post_meta( $post_type, $name, array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => array( 'schema' => $schema ),
				'label'             => $g['label'],
				'sanitize_callback' => fn( $value ) => mcc_group_sanitize( $value, $g ),
				'auth_callback'     => fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
			) );
		}
	}
}
add_action( 'init', 'mcc_register_types' );

add_filter( 'wp_robots', function ( $robots ) {
	foreach ( mcc_spec()['types'] as $name => $t ) {
		if ( ! empty( $t['noindex'] ) && is_singular( $name ) ) {
			return array_merge( $robots, array( 'noindex' => true, 'follow' => true ) );
		}
	}
	return $robots;
} );

/*
 * The REST API shows a post's fields to anyone who can read it, but a password-protected post's are hidden until its
 * password is given (as WordPress does with its content), unless the user can edit it.
 */
add_action( 'rest_api_init', function () {
	$keys = array_map( 'array_keys', mcc_fields() );
	foreach ( mcc_groups() as $name => $g ) {
		foreach ( $g['repeat'] ? mcc_group_types( $g ) : array() as $post_type ) {
			$keys[ $post_type ][] = $name;
		}
	}
	foreach ( $keys as $post_type => $names ) {
		add_filter( "rest_prepare_$post_type", function ( $response, $post, $request ) use ( $names ) {
			$locked = '' !== $post->post_password && ! current_user_can( 'edit_post', $post->ID )
				&& ! hash_equals( $post->post_password, (string) $request['password'] );
			if ( $locked && isset( $response->data['meta'] ) ) {
				foreach ( $names as $name ) {
					unset( $response->data['meta'][ $name ] );
				}
			}
			return $response;
		}, 10, 3 );
	}
} );

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
