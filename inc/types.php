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
 *       "office": { "label": "Office", "on": [ "options" ], "fields": { "phone": {}, "latitude": { "type": "number" } } },
 *       "photos": { "label": "Photos", "repeat": true, "on": [ "page:gallery" ],
 *         "fields": { "image": { "type": "image" }, "caption": {}, "service": { "type": "select", "options": [ "a", "b" ] } } }
 *     }
 *   }
 *
 * A type with "noindex": true keeps its posts out of search results (robots noindex on each one's page), for types
 * that exist to be shown in loops rather than read on their own. A type with "private": true is only in wp-admin: no
 * pages, no archive, not in search or menus (a form's entries, notes kept for the team).
 *
 * Field groups, as ACF's: a named set of fields put "on" post types ("page"), on some pages only ("page:contact", by
 * slug), on those with a template ("page@landing"), on a taxonomy's terms ("term:category"), on users ("user") or on
 * the site ("options": the Site content screen). Field types: string, text (several lines), html (rich text: links,
 * bold, lists), number, url, email, date, boolean, color, image (an attachment), file (any attachment: a PDF…),
 * gallery (several images, in an order), location (an address with its latitude and longitude), select (with
 * "options": [ "a", "b" ]), relation (another post: "to" its post type; "multiple": true for several), json (any
 * data as names and values, a form's answers or an API's item: one field, its values read as {{ data:email }}), hours
 (opening hours: Monday to Sunday, read as the week, today or open now; "closed_days": a repeating group of the days
 that differ, inc/hours.php).
 *
 * A field may have a help line, a prefix and a suffix (shown around its value), and:
 * - "required": true: a post isn't published without it (drafts may leave it empty), nor the site content saved;
 * - "unique": true: no two posts of the type have the same value (a SKU, an email);
 * - "show_if": "kind=office": shown beside the post only while the post's other fields pass that where (the Loop's
 *   syntax, mcc_where); hidden, it isn't required either.
 * - "private": true: edited beside the post and nowhere shown (#62): no token, Field block, Loop, count or sum, no
 *   JSON-LD, and in the REST API only to those who can edit the post (an account's annual revenue).
 *
 * A relation is a foreign key, as Django's: { "account": { "type": "relation", "to": "account" } } on contacts is a
 * contact's account; with "multiple": true, a many-to-many ("products" on a work order). On posts each related post's
 * id is a meta row of its own (one for a single one, one per post for several), so a Loop finds the posts that point
 * to one with an indexed meta query: a contact's account is {{ account.title }}, an account's contacts a Loop of
 * contacts related by "account" (blocks/loop). In a repeating group's rows, the id (or the list of ids) is in the row.
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

const MCC_FIELD_TYPES = array( 'string', 'text', 'html', 'number', 'url', 'email', 'date', 'boolean', 'color', 'image', 'file', 'gallery', 'location', 'select', 'relation', 'json', 'hours' );

// A post's own values (inc/values.php): a field on posts can't have one of these names.
const MCC_RESERVED = array( 'title', 'excerpt', 'content', 'date', 'modified', 'link', 'id', 'author', 'type', 'image', 'image_alt', 'terms', 'count', 'sum', 'avg', 'min', 'max', 'average', 'minimum', 'maximum', 'loop', 'now', 'site', 'search' );

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
		if ( 'relation' === $f['type'] ) {
			$f['to']       = (string) ( $f['to'] ?? 'post' );
			$f['multiple'] = ! empty( $f['multiple'] );
		}
	}
	return $list;
}

// Whether a field is private: edited in wp-admin, never shown on a page (mcc_value, the Loop, the schema).
function mcc_is_private( ?array $f ): bool {
	return ! empty( $f['private'] );
}

// Several related posts: a meta row each.
function mcc_is_many( array $f ): bool {
	return 'relation' === $f['type'] && $f['multiple'];
}

/*
 * On delete: a post deleted for good (not trashed: that can be restored) leaves no relation pointing to it. Every
 * relation field to its type, in a group that doesn't repeat (each related post its own meta row), loses the rows
 * that hold its id: set null, Django's SET_NULL. A relation in a repeating group's rows is inside the rows' one value
 * and stays (the deleted post doesn't show: mcc_related_ids). Cascade and protect, per relation, are for after 1.0.
 */
add_action( 'before_delete_post', function ( int $post_id ) {
	global $wpdb;
	$type = get_post_type( $post_id );
	foreach ( mcc_groups() as $g ) {
		if ( $g['repeat'] ) {
			continue;
		}
		foreach ( $g['fields'] as $name => $f ) {
			if ( 'relation' === $f['type'] && $f['to'] === $type ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", $name, (string) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( $ids as $id ) {
					delete_post_meta( (int) $id, $name, (string) $post_id );
				}
			}
		}
	}
} );

// A field's meta type: an image, a file or a related post is its id; a gallery a list of ids; a location an object.
function mcc_meta_type( array $f ): string {
	return array( 'number' => 'number', 'boolean' => 'boolean', 'image' => 'integer', 'file' => 'integer', 'relation' => 'integer', 'gallery' => 'array', 'location' => 'object', 'hours' => 'object' )[ $f['type'] ] ?? 'string';
}

// A field's REST schema (one meta value, or one value in a row).
function mcc_field_schema( array $f ): array {
	if ( mcc_is_many( $f ) || 'gallery' === $f['type'] ) {
		return array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
	}
	if ( 'hours' === $f['type'] ) {
		return array( 'type' => 'object', 'properties' => array_fill_keys( MCC_DAYS, array( 'type' => 'string' ) ), 'additionalProperties' => false );
	}
	if ( 'location' === $f['type'] ) {
		return array( 'type' => 'object', 'properties' => array( 'address' => array( 'type' => 'string' ), 'latitude' => array( 'type' => array( 'number', 'string' ) ), 'longitude' => array( 'type' => array( 'number', 'string' ) ), 'lat' => array( 'type' => array( 'number', 'string' ) ), 'lng' => array( 'type' => array( 'number', 'string' ) ) ), 'additionalProperties' => false ); // lat, lng: older saves
	}
	return array( 'type' => mcc_meta_type( $f ) );
}

// A field's saved value on a post: a list of ids for several related posts.
function mcc_meta( int $post_id, string $name, array $f ) {
	return mcc_is_many( $f ) ? array_map( 'intval', get_post_meta( $post_id, $name ) ) : get_post_meta( $post_id, $name, true );
}

/**
 * The posts a relation's value points to, in its order: those of its post type that are published and not
 * password-protected (a deleted or draft one isn't shown, as a broken key).
 */
function mcc_related_ids( $raw, array $f ): array {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : array( $raw ) ) ) ) );
	if ( count( $ids ) > 1 ) {
		_prime_post_caches( $ids, false, false ); // one query for those not loaded yet, not one each
	}
	return array_values( array_filter( $ids, function ( $id ) use ( $f ) {
		$post = get_post( $id );
		return $post && $post->post_type === $f['to'] && 'publish' === $post->post_status && '' === $post->post_password;
	} ) );
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

// The post types a group is on ("page:contact" and "page@landing" are on pages; not "term:…", "user" or "options").
function mcc_group_types( array $g ): array {
	$types = array();
	foreach ( $g['on'] as $on ) {
		if ( 'options' !== $on && 'user' !== $on && ! str_starts_with( $on, 'term:' ) ) {
			$types[] = preg_split( '/[:@]/', $on, 2 )[0];
		}
	}
	return array_values( array_unique( $types ) );
}

// The taxonomies a group is on, for their terms ("term:category").
function mcc_group_taxonomies( array $g ): array {
	return array_values( array_map( fn( $on ) => substr( $on, 5 ), array_filter( $g['on'], fn( $on ) => str_starts_with( $on, 'term:' ) ) ) );
}

// Whether a field is shown with these values (its show_if passes; a field without one always is).
function mcc_field_shown( array $f, array $values ): bool {
	$if = trim( (string) ( $f['show_if'] ?? '' ) );
	return '' === $if || mcc_where_row( array_map( fn( $v ) => is_bool( $v ) ? ( $v ? '1' : '' ) : $v, $values ), mcc_where( $if ) );
}

// Whether a value counts as empty for a required field.
function mcc_is_empty( $value, array $f ): bool {
	if ( 'location' === $f['type'] ) {
		return '' === trim( (string) ( ( (array) $value )['address'] ?? '' ) ) && '' === (string) ( ( (array) $value )['latitude'] ?? ( (array) $value )['lat'] ?? '' );
	}
	if ( 'boolean' === $f['type'] ) {
		return ! $value;
	}
	return is_array( $value ) ? ! array_filter( $value, fn( $v ) => '' !== $v && 0 !== $v && null !== $v ) : ( '' === trim( (string) $value ) || ( in_array( $f['type'], array( 'image', 'file', 'relation' ), true ) && ! (int) $value ) );
}

/**
 * What's missing or taken in a group's values: the required fields left empty (unless show_if hides them), and for a
 * post's fields the unique ones another post of its type already has: [ 'required' => messages, 'unique' => messages ].
 */
function mcc_check_values( array $g, $values, int $post_id = 0, string $post_type = '' ): array {
	$errors = array( 'required' => array(), 'unique' => array() );
	$rows   = $g['repeat'] ? (array) $values : array( (array) $values );
	foreach ( $rows as $i => $row ) {
		$row = (array) $row;
		foreach ( $g['fields'] as $name => $f ) {
			if ( ! empty( $f['required'] ) && mcc_field_shown( $f, $row ) && mcc_is_empty( $row[ $name ] ?? '', $f ) ) {
				$errors['required'][] = $g['repeat']
					/* translators: 1: a field's label, 2: a group's label, 3: a row's number */
					? sprintf( __( '%1$s is required (%2$s, row %3$d).', 'marccent-content' ), $f['label'], $g['label'], $i + 1 )
					/* translators: %s: a field's label */
					: sprintf( __( '%s is required.', 'marccent-content' ), $f['label'] );
			}
			if ( ! empty( $f['unique'] ) && ! $g['repeat'] && $post_type && ! mcc_is_empty( $row[ $name ] ?? '', $f ) && is_scalar( $row[ $name ] ) ) {
				$taken = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'numberposts' => 1, 'fields' => 'ids', 'post__not_in' => array( $post_id ?: 0 ), 'meta_query' => array( array( 'key' => $name, 'value' => (string) $row[ $name ] ) ) ) );
				if ( $taken ) {
					/* translators: 1: a field's label, 2: the value, 3: another post's title */
					$errors['unique'][] = sprintf( __( '%1$s “%2$s” is already used by “%3$s”.', 'marccent-content' ), $f['label'], $row[ $name ], get_the_title( $taken[0] ) );
				}
			}
		}
	}
	return $errors;
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

// The field of a post's type by that name, if it has one.
function mcc_post_field( int $post_id, string $name ): ?array {
	return $post_id ? ( mcc_fields( (string) get_post_type( $post_id ) )[ $name ] ?? null ) : null;
}

// A group's REST schema: an object of its fields, or a list of them.
function mcc_group_schema( array $g ): array {
	$props = array();
	foreach ( $g['fields'] as $name => $f ) {
		// A private field in a row: only in the edit context (rest_filter_response_by_context drops it elsewhere).
		$props[ $name ] = ( mcc_is_private( $f ) ? array( 'context' => array( 'edit' ) ) : array() ) + mcc_field_schema( $f );
	}
	$row = array( 'type' => 'object', 'properties' => $props, 'additionalProperties' => false );
	return $g['repeat'] ? array( 'type' => 'array', 'items' => $row ) : $row;
}

function mcc_group_sanitize( $value, array $g ): array {
	$row = function ( $r ) use ( $g ) {
		$out = array();
		foreach ( $g['fields'] as $name => $f ) {
			$value        = ( (array) $r )[ $name ] ?? '';
			$out[ $name ] = mcc_sanitize( ( mcc_is_many( $f ) || 'gallery' === $f['type'] ) && ! is_array( $value ) ? array_filter( array( $value ) ) : $value, $f );
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
			foreach ( $g['fields'] as $name => $f ) {
				$value[ $name ] = mcc_meta( $post_id, $name, $f );
			}
		}
	} elseif ( ( $object = mcc_queried_object( $g ) ) ) {
		$value = mcc_object_group_value( $group, $object[0], $object[1] );
	} elseif ( in_array( 'options', $g['on'], true ) ) {
		$value = get_option( "mcc_$group", array() );
	} else {
		$value = array();
	}
	return is_array( $value ) ? $value : array();
}

// The term or user the page is about, when the group is on it: [ 'term'|'user', id ], else null.
function mcc_queried_object( array $g ): ?array {
	$q = get_queried_object();
	if ( $q instanceof WP_Term && in_array( $q->taxonomy, mcc_group_taxonomies( $g ), true ) ) {
		return array( 'term', $q->term_id );
	}
	if ( $q instanceof WP_User && in_array( 'user', $g['on'], true ) ) {
		return array( 'user', $q->ID );
	}
	return null;
}

// A group's values on a term or a user: its rows under the group's name, or each field under its own.
function mcc_object_group_value( string $group, string $kind, int $id ): array {
	$g   = mcc_groups()[ $group ] ?? null;
	$get = 'term' === $kind ? 'get_term_meta' : 'get_user_meta';
	if ( ! $g || ! $id ) {
		return array();
	}
	if ( $g['repeat'] ) {
		$value = $get( $id, $group, true );
		return is_array( $value ) ? $value : array();
	}
	$value = array();
	foreach ( $g['fields'] as $name => $f ) {
		$value[ $name ] = $get( $id, $name, true );
	}
	return $value;
}

function mcc_save_object_group( string $group, string $kind, int $id, $values ): void {
	$g   = mcc_groups()[ $group ] ?? null;
	$put = 'term' === $kind ? 'update_term_meta' : 'update_user_meta';
	if ( ! $g || ! $id ) {
		return;
	}
	$clean = mcc_group_sanitize( $values, $g );
	if ( $g['repeat'] ) {
		$put( $id, $group, $clean );
		return;
	}
	foreach ( $clean as $name => $value ) {
		$put( $id, $name, $value );
	}
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
	$taxes = array_merge( ...array_map( fn( $t ) => array_keys( (array) ( $t['taxonomies'] ?? array() ) ), array_values( $spec['types'] ) ?: array( array() ) ) );
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
			$on = (string) $on;
			if ( str_starts_with( $on, 'term:' ) ) {
				$tax = substr( $on, 5 );
				if ( ! taxonomy_exists( $tax ) && ! in_array( $tax, $taxes, true ) ) {
					/* translators: 1: a field group's label, 2: a taxonomy's name */
					$errors[] = sprintf( __( '%1$s: there is no taxonomy “%2$s”.', 'marccent-content' ), $label, $tax );
				}
				continue;
			}
			$type = preg_split( '/[:@]/', $on, 2 )[0];
			if ( 'options' !== $on && 'user' !== $on && ! in_array( $type, $known, true ) ) {
				/* translators: 1: a field group's label, 2: a post type's name */
				$errors[] = sprintf( __( '%1$s: there is no post type “%2$s”.', 'marccent-content' ), $label, $type );
			}
		}
		foreach ( (array) ( $g['fields'] ?? array() ) as $field => $f ) {
			$f = (array) $f;
			if ( ! $name( $field ) ) {
				/* translators: 1: a field group's label, 2: a field's name */
				$errors[] = sprintf( __( '%1$s: the field name “%2$s” must be lowercase letters, numbers or underscores, starting with a letter.', 'marccent-content' ), $label, $field );
			}
			if ( ! in_array( $f['type'] ?? 'string', MCC_FIELD_TYPES, true ) ) {
				/* translators: 1: a field group's label, 2: a field's name */
				$errors[] = sprintf( __( '%1$s: the field “%2$s” has an unknown type.', 'marccent-content' ), $label, $field );
			}
			if ( 'relation' === ( $f['type'] ?? '' ) && ! in_array( (string) ( $f['to'] ?? '' ), $known, true ) ) {
				/* translators: 1: a field group's label, 2: a field's name */
				$errors[] = sprintf( __( '%1$s: the relation “%2$s” needs the post type it relates to.', 'marccent-content' ), $label, $field );
			}
			// "account.title" follows the relation; "office.phone" is a group's field: one name can't be both.
			if ( 'relation' === ( $f['type'] ?? '' ) && isset( $spec['groups'][ $field ] ) ) {
				/* translators: 1: a field group's label, 2: a field's name */
				$errors[] = sprintf( __( '%1$s: the relation “%2$s” has a field group’s name; give one of them another.', 'marccent-content' ), $label, $field );
			}
		}
		// On posts, a group's fields (or a repeating group itself) are post meta: their names can't clash.
		$types = mcc_group_types( array( 'on' => array_map( 'strval', (array) ( $g['on'] ?? array() ) ) ) );
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
	update_option( 'mcc_flush_rewrites', 1, true ); // autoloaded: read on every load (inc/admin.php)
	mcc_spec_reset();
	return true;
}

function mcc_register_types(): void {
	foreach ( mcc_spec()['types'] as $name => $t ) {
		$label    = $t['label'] ?? ucfirst( $name ) . 's';
		$singular = $t['singular'] ?? ucfirst( $name );
		$private  = ! empty( $t['private'] );
		register_post_type( $name, array(
			'labels'              => array(
				'name'          => $label,
				'singular_name' => $singular,
				'add_new_item'  => sprintf( __( 'Add %s', 'marccent-content' ), $singular ),
				'edit_item'     => sprintf( __( 'Edit %s', 'marccent-content' ), $singular ),
				'all_items'     => sprintf( __( 'All %s', 'marccent-content' ), $label ),
			),
			'public'              => ! $private && ( $t['public'] ?? true ),
			'show_ui'             => true,
			'publicly_queryable'  => ! $private,
			'exclude_from_search' => $private,
			'show_in_nav_menus'   => ! $private,
			'has_archive'         => ! $private && ( $t['archive'] ?? true ),
			'rewrite'             => $private ? false : array( 'slug' => $t['slug'] ?? $name, 'with_front' => false ),
			'query_var'           => ! $private,
			'menu_icon'           => $t['icon'] ?? 'dashicons-admin-post',
			'show_in_rest'        => true,
			// custom-fields: the fields are in the REST API (the editor's fields panel saves through it).
			'supports'            => array_values( array_unique( array_merge( $t['supports'] ?? array( 'title', 'editor', 'thumbnail', 'excerpt' ), array( 'custom-fields' ) ) ) ),
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
				'type'              => mcc_meta_type( $f ),
				'single'            => ! mcc_is_many( $f ), // several related posts: a meta row each
				// A private field only in the edit context (the editor's fields panel), not to every visitor of the API.
				'show_in_rest'      => mcc_is_private( $f )
					? array( 'schema' => array( 'context' => array( 'edit' ) ) + mcc_field_schema( $f ) )
					: ( in_array( $f['type'], array( 'gallery', 'location', 'hours' ), true ) ? array( 'schema' => mcc_field_schema( $f ) ) : true ),
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
		case 'file':
			return absint( $value );
		case 'gallery':
			return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		case 'location':
			$v   = (array) $value;
			$num = fn( $n, $max ) => is_numeric( $n ) && abs( (float) $n ) <= $max ? round( (float) $n, 7 ) : '';
			return array( 'address' => sanitize_text_field( (string) ( $v['address'] ?? '' ) ), 'latitude' => $num( $v['latitude'] ?? $v['lat'] ?? '', 90 ), 'longitude' => $num( $v['longitude'] ?? $v['lng'] ?? '', 180 ) );
		case 'color':
			return (string) sanitize_hex_color( (string) $value );
		case 'html':
			return wp_kses_post( (string) $value );
		case 'relation':
			// Several in a row's value are a list; on a post, each meta row is one id.
			if ( is_array( $value ) ) {
				return array_values( array_filter( array_map( 'absint', $value ) ) );
			}
			return absint( $value );
		case 'url':
			return esc_url_raw( (string) $value );
		case 'email':
			return sanitize_email( (string) $value );
		case 'text':
			return sanitize_textarea_field( (string) $value );
		case 'select':
			return in_array( $value, $f['options'] ?? array(), true ) ? $value : '';
		case 'json':
			return mcc_json_text( $value );
		case 'hours':
			return mcc_hours_sanitize( $value );
		default:
			return sanitize_text_field( (string) $value );
	}
}

// A json field's value as saved: JSON text, every name and text in it plain text; '' for nothing or for what isn't
// JSON. Given as JSON text or as the data itself (an array, from code).
function mcc_json_text( $value ): string {
	$data = is_string( $value ) ? json_decode( wp_unslash( $value ), true ) : json_decode( (string) wp_json_encode( $value ), true );
	if ( null === $data || ( is_array( $data ) && ! $data ) ) {
		return '';
	}
	return (string) wp_json_encode( mcc_json_clean( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

// JSON data with its names and texts cleaned (plain text, as a text field's); numbers, yes / no and empty kept. At most
// ten levels deep.
function mcc_json_clean( $data, int $depth = 0 ) {
	if ( is_array( $data ) ) {
		if ( $depth >= 10 ) {
			return array();
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$out[ is_int( $key ) ? $key : sanitize_text_field( (string) $key ) ] = mcc_json_clean( $value, $depth + 1 );
		}
		return $out;
	}
	return is_string( $data ) ? sanitize_textarea_field( $data ) : $data;
}

/*
 * Required and unique fields, checked when the editor saves a post (its REST request): a post isn't published, nor a
 * published one updated, with a required field empty; a draft may be. A unique value another post of the type has is
 * refused whatever the status. The editor shows the message and keeps the post as it was.
 */
add_action( 'rest_api_init', function () {
	foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
		$groups = array_filter( mcc_groups(), fn( $g ) => in_array( $post_type, mcc_group_types( $g ), true ) && array_filter( $g['fields'], fn( $f ) => ! empty( $f['required'] ) || ! empty( $f['unique'] ) ) );
		if ( ! $groups ) {
			continue;
		}
		add_filter( "rest_pre_insert_$post_type", function ( $prepared, WP_REST_Request $request ) use ( $groups, $post_type ) {
			$id     = (int) ( $prepared->ID ?? 0 );
			$status = $prepared->post_status ?? ( $id ? get_post_status( $id ) : 'draft' );
			$slug   = $prepared->post_name ?? ( $id ? get_post_field( 'post_name', $id ) : '' );
			$tpl    = (string) ( $request['template'] ?? ( $id ? get_page_template_slug( $id ) : '' ) );
			$meta   = (array) ( $request['meta'] ?? array() );
			$errors = array();
			foreach ( $groups as $name => $g ) {
				if ( ! mcc_group_on_post( $g, $post_type, (string) $slug, $tpl ) ) {
					continue;
				}
				// What it will be: what's sent, else what's saved.
				if ( $g['repeat'] ) {
					$values = array_key_exists( $name, $meta ) ? $meta[ $name ] : ( $id ? get_post_meta( $id, $name, true ) : array() );
				} else {
					$values = array();
					foreach ( $g['fields'] as $field => $f ) {
						$values[ $field ] = array_key_exists( $field, $meta ) ? $meta[ $field ] : ( $id ? mcc_meta( $id, $field, $f ) : '' );
					}
				}
				$found  = mcc_check_values( $g, $values ?: array(), $id, $post_type );
				$public = in_array( $status, array( 'publish', 'future', 'private' ), true ); // a draft may be incomplete
				$errors = array_merge( $errors, $public ? $found['required'] : array(), $found['unique'] );
			}
			return $errors ? new WP_Error( 'mcc_invalid', implode( ' ', $errors ), array( 'status' => 400, 'errors' => $errors ) ) : $prepared;
		}, 10, 2 );
	}
} );

// Whether a group is on a post: its type, its slug ("page:contact") or its template ("page@landing").
function mcc_group_on_post( array $g, string $post_type, string $slug, string $template ): bool {
	foreach ( $g['on'] as $on ) {
		if ( $on === $post_type || $on === "$post_type:$slug" || ( '' !== $template && $on === "$post_type@$template" ) ) {
			return true;
		}
	}
	return false;
}
