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
		array( 'wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-plugins' ),
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

// The Loop's items in the theme's Grid, from Grid's settings (layout, columns, gap), written by Marccent Blocks.
function mcc_loop_grid( array $grid, string $items ): string {
	$wrapper = fn( array $attrs ) => get_block_wrapper_attributes( array_filter( $attrs, fn( $v ) => null !== $v && '' !== $v ) );
	if ( function_exists( 'marccent_blocks_grid' ) ) {
		return marccent_blocks_grid( $grid, $items, $wrapper );
	}
	return sprintf( '<div %s>%s</div>', $wrapper( array( 'class' => trim( 'grid cols-' . max( 1, (int) ( $grid['columns'] ?? 3 ) ) . ' ' . ( $grid['gap'] ?? '' ) ) ) ), $items );
}

// A Loop saved before its grid took Grid's settings (2026-11-10): cols is columns, and its layouts Grid's ('' equal
// columns, row each item its own width, wrap a wrapping row). Every render, however it's reached (a Loop nested in
// another's item is handed over already prepared). The editor does the same (assets/editor.js).
function mcc_loop_upgrade( array $a ): array {
	if ( array_key_exists( 'cols', $a ) ) {
		$a['columns'] ??= $a['cols'];
		unset( $a['cols'] );
	}
	$layouts = array( '' => 'cols', 'row' => 'auto-cols', 'wrap' => 'row-wrap' );
	if ( isset( $a['layout'] ) && isset( $layouts[ $a['layout'] ] ) ) {
		$a['layout'] = $layouts[ $a['layout'] ];
	}
	return $a;
}
add_filter(
	'register_block_type_args',
	function ( $args, $name ) {
		if ( 'marccent/loop' !== $name || empty( $args['render_callback'] ) ) {
			return $args;
		}
		$render                  = $args['render_callback'];
		$args['render_callback'] = function ( $attributes, $content, $block ) use ( $render ) {
			$saved = (array) ( $block->parsed_block['attrs'] ?? array() );
			if ( ( array_key_exists( 'cols', $saved ) || in_array( $saved['layout'] ?? null, array( '', 'row', 'wrap' ), true ) ) && $block->block_type ) {
				$attributes = $block->block_type->prepare_attributes_for_render( mcc_loop_upgrade( $saved ) );
			}
			return $render( $attributes, $content, $block );
		};
		return $args;
	},
	10,
	2
);

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
	// The Loop's grid: Grid's gap choices (marccent-theme markup/settings.json, from Marccent Blocks).
	$grid = function_exists( 'marccent_blocks_settings' ) ? ( marccent_blocks_settings()['Grid']['settings'] ?? array() ) : array();
	return array(
		'types'    => $types,
		'groups'   => (object) mcc_groups(),
		'currency' => mcc_spec()['currency'],
		'gaps'     => $grid['gap']['choices'] ?? array( 'gap-0', 'gap-sm', '', 'gap-lg' ),
	);
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
function mcc_loop_query( array $a, int $current = 0, array $visitor = array() ): WP_Query {
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
	// Only the public types the editor offers (mcc_editor_data); never password-protected posts. Any other type (one
	// the site doesn't have, as a kit's Loop before its type is added): nothing, so the Loop shows its empty text.
	$object = get_post_type_object( $a['postType'] );
	if ( ! is_post_type_viewable( $a['postType'] ) || ! $object || ! $object->public || ! $object->show_in_rest || 'attachment' === $object->name ) {
		$a['postType'] = 'post';
		$related       = array( 'post__in' => array( 0 ) );
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
		'no_found_rows'       => empty( $visitor['paged'] ),
	);
	// The visitor's page (the Loop's paging): the posts after the earlier pages' and the Loop's own offset.
	if ( ! empty( $visitor['paged'] ) ) {
		$args['offset'] += ( max( 1, (int) $visitor['page'] ) - 1 ) * $args['posts_per_page'];
	}
	if ( '' !== (string) ( $visitor['search'] ?? '' ) ) {
		$args['s'] = $visitor['search'];
	}
	if ( str_starts_with( $a['orderBy'], 'field:' ) ) {
		$name             = substr( $a['orderBy'], 6 );
		$field            = mcc_fields( $a['postType'] )[ $name ] ?? array();
		$args['meta_key'] = $name;
		$args['orderby']  = 'number' === ( $field['type'] ?? '' ) ? 'meta_value_num' : 'meta_value';
	}
	if ( $a['excludeCurrent'] && $current ) {
		$args['post__not_in'] = array( $current );
	}
	$exclude = array();
	$meta    = array_merge( $related['meta_query'] ?? array(), mcc_where_meta( $a['postType'], array_merge( mcc_where( (string) ( $a['where'] ?? '' ) ), $visitor['tests'] ?? array() ), $exclude ) );
	unset( $related['meta_query'] );
	if ( $related ) {
		$args = array_merge( $args, $related );
	}
	if ( $meta ) {
		$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta );
	}
	mcc_exclude_ids( $args, $exclude );
	if ( '' !== trim( (string) ( $a['parent'] ?? '' ) ) && is_post_type_hierarchical( $a['postType'] ) ) {
		$parent              = get_page_by_path( trim( $a['parent'], '/ ' ), OBJECT, $a['postType'] );
		$args['post_parent'] = $parent ? $parent->ID : -1; // no such parent: nothing, not every post
	}
	$terms = array_filter( array_map( 'trim', explode( ',', (string) $a['terms'] ) ) );
	if ( $a['taxonomy'] && $terms ) {
		$args['tax_query'] = array( array( 'taxonomy' => $a['taxonomy'], 'field' => 'slug', 'terms' => $terms ) );
	}
	// The visitor's term filters: each taxonomy's chosen term, on top of the Loop's own.
	foreach ( (array) ( $visitor['terms'] ?? array() ) as $tax => $slug ) {
		if ( is_object_in_taxonomy( $a['postType'], $tax ) ) {
			$args['tax_query'][] = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => array( $slug ) );
		}
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
function mcc_loop_rows( array $a, int $current = 0, array $visitor = array(), ?int &$total = null ): array {
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

	$tests  = array_merge( mcc_where( (string) $a['where'] ), $visitor['tests'] ?? array() );
	$search = mb_strtolower( (string) ( $visitor['search'] ?? '' ) );
	$rows   = array_filter( $rows, fn( $row ) => is_array( $row ) && mcc_where_row( $row, $tests )
		&& ( '' === $search || str_contains( mb_strtolower( implode( ' ', array_filter( $row, 'is_scalar' ) ) ), $search ) ) );
	$offset = max( 0, (int) $a['offset'] );
	$per    = max( 1, (int) $a['perPage'] );
	$total  = max( 0, count( $rows ) - $offset );
	$skip   = ! empty( $visitor['paged'] ) ? ( max( 1, (int) $visitor['page'] ) - 1 ) * $per : 0;
	return array_slice( array_values( $rows ), $offset + $skip, $per );
}

/**
 * A where, for a Loop's posts or rows and for counts and sums: tests joined by &, each a field, an operator and a value.
 *   =   one of the values, comma-separated: status=New,Scheduled (several related posts: one of them is)
 *   !=  none of them
 *   ~   in the field's comma-separated list: featured~home (several related posts: products~12)
 *   < > <= >=  numbers, or dates (2026-10-31; "today" is today's)
 *   *=  contains the text, any case: name*=smith
 * With nothing after = or !=, the field is empty or not: parent_account= (none), phone!= (has one).
 * A test that doesn't parse is left out; so is one on a field the post type doesn't have.
 */
function mcc_where( string $where ): array {
	$tests = array();
	foreach ( array_filter( array_map( 'trim', explode( '&', html_entity_decode( $where, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) ) as $test ) {
		if ( preg_match( '/^([a-z0-9_\-.:]+)\s*(!=|<=|>=|\*=|=|~|<|>)\s*(.*)$/i', $test, $m ) ) {
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
			case '*=':
				$pass = '' === $want || str_contains( mb_strtolower( implode( ' ', $have ) ), mb_strtolower( $want ) );
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
// != on a many-to-many can't be a meta query (any other row passes it): the posts that have one of its values go in
// $exclude instead, for the query to leave out (mcc_exclude_ids).
function mcc_where_meta( string $type, array $tests, array &$exclude = array() ): array {
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
		if ( '' === $want && in_array( $op, array( '=', '!=' ), true ) ) {
			// Empty: no row, or an empty one (an unset relation is 0). Not empty: a row with a value.
			$blank   = 'NUMERIC' === $cast ? '0' : '';
			$query[] = '=' === $op
				? array( 'relation' => 'OR', $unset, array( 'key' => $name, 'value' => array( '', $blank ), 'compare' => 'IN' ) )
				: array( 'key' => $name, 'value' => array( '', $blank ), 'compare' => 'NOT IN' );
			continue;
		}
		switch ( $op ) {
			case '*=':
				$query[] = array( 'key' => $name, 'value' => $want, 'compare' => 'LIKE' );
				break;
			case '=':
				$query[] = array( 'key' => $name, 'value' => $wants, 'compare' => 'IN', 'type' => $cast );
				break;
			case '!=':
				if ( mcc_is_many( $f ) ) {
					$exclude = array_merge( $exclude, mcc_ids_with( $name, $wants ) );
				} else {
					$query[] = array( 'relation' => 'OR', $unset, array( 'key' => $name, 'value' => $wants, 'compare' => 'NOT IN', 'type' => $cast ) );
				}
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

// The posts with a meta row of a field holding one of the values (a many-to-many's related ids).
function mcc_ids_with( string $name, array $values ): array {
	global $wpdb;
	$values = array_values( array_filter( $values, 'strlen' ) );
	if ( ! $values ) {
		return array();
	}
	$in = implode( ',', array_fill( 0, count( $values ), '%s' ) );
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value IN ($in)", $name, ...$values ) ) ); // phpcs:ignore WordPress.DB
}

// Leaves posts out of a query's args: from its post__in when it has one (WP_Query ignores post__not_in then).
function mcc_exclude_ids( array &$args, array $ids ): void {
	if ( ! $ids ) {
		return;
	}
	if ( ! empty( $args['post__in'] ) ) {
		$args['post__in'] = array_values( array_diff( $args['post__in'], $ids ) ) ?: array( 0 );
	} else {
		$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $ids );
	}
}

/* The Loop's visitor side: paging and filters */

/**
 * What the visitor asked a Loop for, from the address: its page (?mcc2p=3), its search (?mcc2s=…) and its filters
 * (?mcc2_status=New, ?mcc2_terms_project_service=patios). Each Loop on a page has its own number, in the order they
 * render, so two Loops page apart. Only the filters the Loop offers are read.
 */
function mcc_loop_visitor( array $a, int $n ): array {
	$get     = fn( $k ) => isset( $_GET[ $k ] ) && is_scalar( $_GET[ $k ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- a public listing's filters
	$visitor = array( 'paged' => ! empty( $a['paginate'] ), 'page' => max( 1, (int) $get( "mcc{$n}p" ) ), 'search' => ! empty( $a['searchBox'] ) ? mb_substr( $get( "mcc{$n}s" ), 0, 100 ) : '', 'tests' => array(), 'terms' => array(), 'chosen' => array() );
	foreach ( mcc_loop_filters( $a ) as $key => $f ) {
		$value = mb_substr( $get( "mcc{$n}_" . str_replace( ':', '_', $key ) ), 0, 100 );
		if ( '' === $value ) {
			continue;
		}
		$visitor['chosen'][ $key ] = $value;
		if ( str_starts_with( $key, 'terms:' ) ) {
			$visitor['terms'][ substr( $key, 6 ) ] = sanitize_title( $value );
		} else {
			$op                 = in_array( $f['type'], array( 'string', 'text', 'email', 'url' ), true ) ? '*=' : ( 'relation' === $f['type'] || 'select' === $f['type'] || 'boolean' === $f['type'] || 'number' === $f['type'] ? '=' : '*=' );
			$visitor['tests'][] = array( $key, $op, $value );
		}
	}
	return $visitor;
}

// The filters a Loop offers visitors: its "filters" attribute's fields (and terms:<taxonomy>), each with its field.
function mcc_loop_filters( array $a ): array {
	$fields = '' !== $a['source'] ? ( mcc_groups()[ $a['source'] ]['fields'] ?? array() ) : mcc_fields( $a['postType'] );
	$out    = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $a['filters'] ?? '' ) ) ) ) as $key ) {
		if ( str_starts_with( $key, 'terms:' ) && '' === $a['source'] && taxonomy_exists( substr( $key, 6 ) ) ) {
			$tax          = get_taxonomy( substr( $key, 6 ) );
			$out[ $key ] = array( 'type' => 'terms', 'label' => $tax->labels->singular_name );
		} elseif ( isset( $fields[ $key ] ) ) {
			$out[ $key ] = $fields[ $key ];
		}
	}
	return $out;
}

// The filters' form, above the Loop: a select for a choice, a yes / no, a relation or terms; a text box for the rest.
function mcc_loop_filters_html( array $a, int $n, array $visitor ): string {
	$filters = mcc_loop_filters( $a );
	if ( ! $filters && empty( $a['searchBox'] ) ) {
		return '';
	}
	$controls = '';
	if ( ! empty( $a['searchBox'] ) ) {
		$controls .= mcc_loop_field( "mcc{$n}s", __( 'Search', 'marccent-content' ), sprintf( '<input id="mcc%1$ds" type="search" class="input" name="mcc%1$ds" value="%2$s">', $n, esc_attr( $visitor['search'] ) ) );
	}
	foreach ( $filters as $key => $f ) {
		$name    = "mcc{$n}_" . str_replace( ':', '_', $key );
		$current = $visitor['chosen'][ $key ] ?? '';
		$choices = null;
		if ( 'terms' === $f['type'] ) {
			$terms   = get_terms( array( 'taxonomy' => substr( $key, 6 ), 'hide_empty' => true ) );
			$choices = is_array( $terms ) ? wp_list_pluck( $terms, 'name', 'slug' ) : array();
		} elseif ( 'select' === $f['type'] ) {
			$choices = array_combine( $f['options'] ?? array(), $f['options'] ?? array() ) ?: array();
		} elseif ( 'boolean' === $f['type'] ) {
			$choices = array( '1' => __( 'Yes', 'marccent-content' ), '0' => __( 'No', 'marccent-content' ) );
		} elseif ( 'relation' === $f['type'] ) {
			$choices = array();
			foreach ( get_posts( array( 'post_type' => $f['to'], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC', 'has_password' => false ) ) as $p ) {
				$choices[ $p->ID ] = wp_strip_all_tags( get_the_title( $p ) );
			}
		}
		if ( null === $choices ) {
			$control = sprintf( '<input id="%1$s" class="input" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $current ) );
		} else {
			$options = '<option value="">' . esc_html__( 'Any', 'marccent-content' ) . '</option>';
			foreach ( $choices as $value => $label ) {
				$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( (string) $current, (string) $value, false ), esc_html( $label ) );
			}
			$control = sprintf( '<select id="%1$s" class="input" name="%1$s">%2$s</select>', esc_attr( $name ), $options );
		}
		$controls .= mcc_loop_field( $name, $f['label'], $control );
	}
	// The address's other parameters (another Loop's page and filters) carry over; this Loop starts at its first page.
	$hidden = '';
	foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification -- carried over, escaped
		if ( is_scalar( $v ) && ! preg_match( "/^mcc{$n}(p|s|_.*)$/", (string) $k ) ) {
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $k ), esc_attr( wp_unslash( (string) $v ) ) );
		}
	}
	$reset = $visitor['chosen'] || '' !== $visitor['search'] ? sprintf( ' <a class="btn" href="%s#mcc-loop-%d">%s</a>', esc_url( remove_query_arg( array_merge( array( "mcc{$n}p", "mcc{$n}s" ), array_map( fn( $k ) => "mcc{$n}_" . str_replace( ':', '_', $k ), array_keys( $filters ) ) ) ) ), $n, esc_html__( 'Clear', 'marccent-content' ) ) : '';
	return sprintf( '<form class="row-wrap items-end gap-sm" method="get" action="#mcc-loop-%d" role="search">%s%s<div class="row-wrap gap-sm"><button class="btn primary" type="submit">%s</button>%s</div></form>', $n, $hidden, $controls, esc_html__( 'Filter', 'marccent-content' ), $reset );
}

// One filter: the theme's Field (its label joined to the control), the control's id the field's name.
function mcc_loop_field( string $name, string $label, string $control ): string {
	return sprintf( '<div data-component="field" class="field joined sub-x"><label class="field-label" for="%s">%s</label>%s</div>', esc_attr( $name ), esc_html( $label ), $control );
}

// The pages' links, under the Loop: previous, "Page 2 of 5", next; nothing for one page.
function mcc_loop_pager_html( int $n, int $page, int $pages ): string {
	if ( $pages < 2 ) {
		return '';
	}
	$link = fn( $p, $label, $rel ) => sprintf( '<a class="btn" rel="%s" href="%s#mcc-loop-%d">%s</a>', $rel, esc_url( $p > 1 ? add_query_arg( "mcc{$n}p", $p ) : remove_query_arg( "mcc{$n}p" ) ), $n, esc_html( $label ) );
	return sprintf(
		'<nav class="grid auto-cols cols-between items-center" aria-label="%s">%s<span>%s</span>%s</nav>',
		esc_attr__( 'Pages', 'marccent-content' ),
		$page > 1 ? $link( $page - 1, __( '← Previous', 'marccent-content' ), 'prev' ) : '<span></span>',
		/* translators: 1: this page, 2: how many pages */
		esc_html( sprintf( __( 'Page %1$d of %2$d', 'marccent-content' ), $page, $pages ) ),
		$page < $pages ? $link( $page + 1, __( 'Next →', 'marccent-content' ), 'next' ) : '<span></span>'
	);
}

/*
 * "Show if" on any block (the mccShowIf attribute, the editor's "Show if" panel): a where on the current row's,
 * post's or site's values (mcc_show_if). The block, and everything in it, renders only when it passes.
 */
add_filter( 'register_block_type_args', function ( $args ) {
	$args['attributes']['mccShowIf'] = array( 'type' => 'string', 'default' => '' );
	return $args;
} );

add_filter( 'render_block', function ( $html, $block, $instance = null ) {
	$if = (string) ( $block['attrs']['mccShowIf'] ?? '' );
	if ( '' === trim( $if ) || is_admin() ) {
		return $html;
	}
	return mcc_show_if( $if, mcc_context_post( $instance ) ) ? $html : '';
}, 5, 3 );
