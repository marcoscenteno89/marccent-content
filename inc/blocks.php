<?php
/**
 * The blocks (blocks/loop, blocks/field) and the editor's script (assets/editor.js, plain JS on WordPress's own
 * packages: no build): the blocks' editing, the fields panel beside a post, and the binding source's preview. The
 * editor gets the types, their taxonomies and fields as window.marccentContent.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	wp_register_script(
		'marccent-content-editor',
		plugins_url( 'assets/editor.js', MCC_FILE ),
		array( 'wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
		filemtime( MCC_DIR . '/assets/editor.js' ),
		true
	);
	if ( is_admin() ) { // the editor and the admin screens are its only pages: no need to build the data elsewhere
		wp_add_inline_script( 'marccent-content-editor', 'window.marccentContent = ' . wp_json_encode( mcc_editor_data() ) . ';', 'before' );
	}
	// The Loop renders its item itself, once per post or row: without this WordPress renders it first too, unused, and
	// every Loop nested in it doubles that (2^depth renders), as core's Post Template does.
	register_block_type( MCC_DIR . '/blocks/loop', array( 'skip_inner_blocks' => true ) );
	register_block_type( MCC_DIR . '/blocks/field' );
}, 20 ); // after the types (init, 10)

// The fields panel is part of the same script, so it loads in every post editor, not only with a block.
add_action( 'enqueue_block_editor_assets', fn() => wp_enqueue_script( 'marccent-content-editor' ) );

// Every public type with its taxonomies and fields (post and page too), and the field groups, for the editor.
function mcc_editor_data(): array {
	$types = array();
	foreach ( get_post_types( array( 'show_in_rest' => true, 'public' => true ), 'objects' ) as $type ) {
		if ( 'attachment' === $type->name ) {
			continue;
		}
		$types[] = array(
			'name'       => $type->name,
			'label'      => $type->labels->name,
			'singular'   => $type->labels->singular_name,
			'hierarchical' => $type->hierarchical,
			'taxonomies' => array_values( array_map( fn( $t ) => array( 'name' => $t->name, 'label' => $t->labels->name ), get_object_taxonomies( $type->name, 'objects' ) ) ),
			'fields'     => (object) mcc_fields( $type->name ),
		);
	}
	return array( 'types' => $types, 'groups' => (object) mcc_groups(), 'currency' => mcc_spec()['currency'] );
}

/**
 * The Loop's query from its settings: published posts of one type, newest first by default, optionally of some terms,
 * the children of one parent (a hierarchical type's path, "services": its pages) and ordered by a field
 * ("field:price"); without the current post. Only public types, no password-protected posts.
 *
 * Related to the current post (the outer Loop's item, else the page's), by a relation field ("relation"):
 * - "account": the posts of the type whose account is the current post (Django's contact_set), by an indexed meta query;
 * - "this.products": the current post's products (the type is the relation's), in their order with "post__in".
 * Without a current post, or with an unknown field, nothing: not every post.
 *
 * Only posts whose fields pass a where (mcc_where): "status=New,Scheduled&total>=1000".
 */
function mcc_loop_query( array $a, int $current = 0 ): WP_Query {
	$rel     = trim( (string) ( $a['relation'] ?? '' ) );
	$related = null; // the post ids, or the meta query, of a relation
	if ( str_starts_with( $rel, 'this.' ) ) {
		$name = substr( $rel, 5 );
		$f    = mcc_post_field( $current, $name );
		if ( $f && 'relation' === $f['type'] ) {
			$a['postType'] = $f['to'];
			$related       = array( 'post__in' => post_password_required( $current ) ? array( 0 ) : ( mcc_related_ids( mcc_meta( $current, $name, $f ), $f ) ?: array( 0 ) ) );
		} else {
			$related = array( 'post__in' => array( 0 ) );
		}
	} elseif ( '' !== $rel ) {
		$f       = mcc_fields( $a['postType'] )[ $rel ] ?? null;
		$related = $f && 'relation' === $f['type'] && $current && ! post_password_required( $current )
			? array( 'meta_query' => array( array( 'key' => $rel, 'value' => $current, 'compare' => '=', 'type' => 'NUMERIC' ) ) )
			: array( 'post__in' => array( 0 ) );
	}
	// Only the public types the editor offers (mcc_editor_data); never password-protected posts.
	$object = get_post_type_object( $a['postType'] );
	if ( ! is_post_type_viewable( $a['postType'] ) || ! $object || ! $object->public || ! $object->show_in_rest || 'attachment' === $object->name ) {
		$a['postType'] = 'post';
	}
	$args = array(
		'post_type'           => $a['postType'],
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, (int) $a['perPage'] ),
		'offset'              => max( 0, (int) $a['offset'] ),
		'order'               => 'asc' === strtolower( $a['order'] ) ? 'ASC' : 'DESC',
		'orderby'             => $a['orderBy'],
		'has_password'        => false,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);
	if ( str_starts_with( $a['orderBy'], 'field:' ) ) {
		$name             = substr( $a['orderBy'], 6 );
		$field            = mcc_fields( $a['postType'] )[ $name ] ?? array();
		$args['meta_key'] = $name;
		$args['orderby']  = 'number' === ( $field['type'] ?? '' ) ? 'meta_value_num' : 'meta_value';
	}
	if ( $a['excludeCurrent'] && $current ) {
		$args['post__not_in'] = array( $current );
	}
	$meta = array_merge( $related['meta_query'] ?? array(), mcc_where_meta( $a['postType'], mcc_where( (string) ( $a['where'] ?? '' ) ) ) );
	unset( $related['meta_query'] );
	if ( $related ) {
		$args = array_merge( $args, $related );
	}
	if ( $meta ) {
		$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta );
	}
	if ( '' !== trim( (string) ( $a['parent'] ?? '' ) ) && is_post_type_hierarchical( $a['postType'] ) ) {
		$parent              = get_page_by_path( trim( $a['parent'], '/ ' ), OBJECT, $a['postType'] );
		$args['post_parent'] = $parent ? $parent->ID : -1; // no such parent: nothing, not every post
	}
	$terms = array_filter( array_map( 'trim', explode( ',', (string) $a['terms'] ) ) );
	if ( $a['taxonomy'] && $terms ) {
		$args['tax_query'] = array( array( 'taxonomy' => $a['taxonomy'], 'field' => 'slug', 'terms' => $terms ) );
	}
	// The posts in one query, not their ids and then the posts: without a persistent object cache to read them from,
	// WordPress's split is a second round trip to the database.
	$split = wp_using_ext_object_cache() ? null : '__return_false';
	if ( $split ) {
		add_filter( 'split_the_query', $split );
	}
	$query = new WP_Query( apply_filters( 'marccent_content_loop_query', $args, $a ) );
	if ( $split ) {
		remove_filter( 'split_the_query', $split );
	}
	return $query;
}

/**
 * The Loop's rows when its source is a repeating group: the group's rows from the current post (the outer Loop's item,
 * else the page being viewed), a page (its path, "gallery") or the site ("options"), in their saved order, those that
 * match where (mcc_where), then offset and how many.
 */
function mcc_loop_rows( array $a, int $current = 0 ): array {
	$g = mcc_groups()[ $a['source'] ] ?? null;
	if ( ! $g || ! $g['repeat'] ) {
		return array();
	}
	$from = trim( (string) $a['from'], '/ ' );
	if ( 'options' === $from ) {
		$post_id = 0;
	} elseif ( '' === $from ) {
		$post_id = $current; // the outer Loop's item, else the page's
	} else {
		$page    = get_page_by_path( $from, OBJECT, mcc_group_types( $g ) ?: array( 'page' ) );
		$post_id = $page && 'publish' === $page->post_status ? $page->ID : -1; // no such page: no rows, not the site's
	}
	$rows = $post_id < 0 ? array() : mcc_group_value( $a['source'], $post_id );

	$tests = mcc_where( (string) $a['where'] );
	$rows  = array_filter( $rows, fn( $row ) => is_array( $row ) && mcc_where_row( $row, $tests ) );
	return array_slice( array_values( $rows ), max( 0, (int) $a['offset'] ), max( 1, (int) $a['perPage'] ) );
}

/**
 * A where, for a Loop's posts or rows and for counts and sums: tests joined by &, each a field, an operator and a value.
 *   =   one of the values, comma-separated: status=New,Scheduled (several related posts: one of them is)
 *   !=  none of them
 *   ~   in the field's comma-separated list: featured~home (several related posts: products~12)
 *   < > <= >=  numbers, or dates (2026-10-31; "today" is today's)
 * A test that doesn't parse is left out; so is one on a field the post type doesn't have.
 */
function mcc_where( string $where ): array {
	$tests = array();
	foreach ( array_filter( array_map( 'trim', explode( '&', html_entity_decode( $where, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) ) as $test ) {
		if ( preg_match( '/^([a-z0-9_\-]+)\s*(!=|<=|>=|=|~|<|>)\s*(.*)$/i', $test, $m ) ) {
			$want    = trim( $m[3] );
			$tests[] = array( $m[1], $m[2], 'today' === strtolower( $want ) ? current_time( 'Y-m-d' ) : $want );
		}
	}
	return $tests;
}

// Whether a row passes a where's tests.
function mcc_where_row( array $row, array $tests ): bool {
	foreach ( $tests as [ $name, $op, $want ] ) {
		$raw   = $row[ $name ] ?? '';
		$have  = array_map( 'strval', is_array( $raw ) ? $raw : array( $raw ) );
		$wants = array_map( 'trim', explode( ',', $want ) );
		switch ( $op ) {
			case '=':
				$pass = (bool) array_intersect( $have, $wants );
				break;
			case '!=':
				$pass = ! array_intersect( $have, $wants );
				break;
			case '~':
				$pass = in_array( $want, is_array( $raw ) ? $have : array_map( 'trim', explode( ',', (string) $raw ) ), true );
				break;
			default:
				$pass = mcc_compare( (string) ( $have[0] ?? '' ), $op, $want );
		}
		if ( ! $pass ) {
			return false;
		}
	}
	return true;
}

// <, >, <=, >= on numbers, else on text (dates as 2026-10-31 compare as text); never on an empty value.
function mcc_compare( string $have, string $op, string $want ): bool {
	if ( '' === $have ) {
		return false;
	}
	$c = is_numeric( $have ) && is_numeric( $want ) ? (float) $have <=> (float) $want : strcmp( $have, $want ) <=> 0;
	return array( '<' => $c < 0, '>' => $c > 0, '<=' => $c <= 0, '>=' => $c >= 0 )[ $op ];
}

// A where's tests as a meta query on a post type's fields (each a meta row: several related posts, a row each).
function mcc_where_meta( string $type, array $tests ): array {
	$fields = mcc_fields( $type );
	$query  = array();
	foreach ( $tests as [ $name, $op, $want ] ) {
		$f = $fields[ $name ] ?? null;
		if ( ! $f ) {
			continue;
		}
		$cast  = in_array( $f['type'], array( 'number', 'relation', 'image' ), true ) ? 'NUMERIC' : ( 'date' === $f['type'] ? 'DATE' : 'CHAR' );
		$wants = array_map( 'trim', explode( ',', $want ) );
		$unset = array( 'key' => $name, 'compare' => 'NOT EXISTS' );
		if ( 'boolean' === $f['type'] ) {
			// Saved as 1, or as nothing ("" or no row) when it's off.
			$yes     = in_array( strtolower( $want ), array( '1', 'yes', 'true', 'on' ), true ) === ( '!=' !== $op );
			$query[] = $yes ? array( 'key' => $name, 'value' => '1' ) : array( 'relation' => 'OR', $unset, array( 'key' => $name, 'value' => '1', 'compare' => '!=' ) );
			continue;
		}
		switch ( $op ) {
			case '=':
				$query[] = array( 'key' => $name, 'value' => $wants, 'compare' => 'IN', 'type' => $cast );
				break;
			case '!=':
				$query[] = array( 'relation' => 'OR', $unset, array( 'key' => $name, 'value' => $wants, 'compare' => 'NOT IN', 'type' => $cast ) );
				break;
			case '~':
				$query[] = mcc_is_many( $f )
					? array( 'key' => $name, 'value' => $want, 'type' => $cast )
					: array( 'key' => $name, 'value' => '(^|,)[[:space:]]*' . preg_quote( $want, '' ) . '[[:space:]]*(,|$)', 'compare' => 'REGEXP' );
				break;
			default:
				$query[] = array( 'key' => $name, 'value' => $want, 'compare' => $op, 'type' => $cast );
		}
	}
	return $query;
}
