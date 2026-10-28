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
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
		filemtime( MCC_DIR . '/assets/editor.js' ),
		true
	);
	wp_add_inline_script( 'marccent-content-editor', 'window.marccentContent = ' . wp_json_encode( mcc_editor_data() ) . ';', 'before' );
	register_block_type( MCC_DIR . '/blocks/loop' );
	register_block_type( MCC_DIR . '/blocks/field' );
}, 20 ); // after the types (init, 10)

// The fields panel is part of the same script, so it loads in every post editor, not only with a block.
add_action( 'enqueue_block_editor_assets', fn() => wp_enqueue_script( 'marccent-content-editor' ) );

// Every public type with its taxonomies and fields (post and page too), for the editor.
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
			'taxonomies' => array_values( array_map( fn( $t ) => array( 'name' => $t->name, 'label' => $t->labels->name ), get_object_taxonomies( $type->name, 'objects' ) ) ),
			'fields'     => (object) mcc_fields( $type->name ),
		);
	}
	return array( 'types' => $types, 'currency' => mcc_spec()['currency'] );
}

/**
 * The Loop's query from its settings: published posts of one type, newest first by default, optionally of some terms
 * and ordered by a field ("field:price"); on a single post, without that post. Only public types, no
 * password-protected posts.
 */
function mcc_loop_query( array $a ): WP_Query {
	// Only the public types the editor offers; never password-protected posts.
	if ( ! is_post_type_viewable( $a['postType'] ) || ! in_array( $a['postType'], wp_list_pluck( mcc_editor_data()['types'], 'name' ), true ) ) {
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
	if ( $a['excludeCurrent'] && is_singular() ) {
		$args['post__not_in'] = array( get_queried_object_id() );
	}
	$terms = array_filter( array_map( 'trim', explode( ',', (string) $a['terms'] ) ) );
	if ( $a['taxonomy'] && $terms ) {
		$args['tax_query'] = array( array( 'taxonomy' => $a['taxonomy'], 'field' => 'slug', 'terms' => $terms ) );
	}
	return new WP_Query( apply_filters( 'marccent_content_loop_query', $args, $a ) );
}
