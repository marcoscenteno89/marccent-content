<?php
/**
 * A post's values in blocks, two ways:
 *
 * - Block bindings: the marccent/field source, for the blocks WordPress binds (a heading's or paragraph's text, an
 *   image's URL and alt, a core button's text and URL). In the editor: the block's Attributes panel, or by hand:
 *   "metadata": { "bindings": { "content": { "source": "marccent/field", "args": { "key": "price | currency" } } } }
 * - Tokens: {{ key }} anywhere in any block's markup, in its text or its attributes, filled as the block renders.
 *   This is what reaches the Marccent blocks, which save their markup as it is (a card's heading "{{ title }}", a
 *   button's link "{{ link }}"): "{{ price | currency }}", "{{ image:medium }}", "{{ terms:project_service }}".
 *
 * The post is the loop's current one (the Loop block), else the page's own. Keys and formats: inc/values.php.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_block_bindings_source( 'marccent/field', array(
		'label'              => __( 'Marccent field', 'marccent-content' ),
		'uses_context'       => array( 'postId', 'postType' ),
		'get_value_callback' => function ( array $args, WP_Block $block, string $attribute ) {
			$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
			[ $key, $format ] = mcc_parse_key( (string) ( $args['key'] ?? '' ) );
			if ( ! $post_id || '' === $key ) {
				return null;
			}
			$value = mcc_value( $post_id, $key, $format );
			// Text attributes are put in as HTML (rich text); URLs and alt texts as attribute values, escaped there.
			return in_array( $attribute, array( 'content', 'caption', 'text' ), true ) ? esc_html( $value ) : $value;
		},
	) );
} );

// The post a block renders for: its context's (inside a loop), else the current post.
function mcc_context_post( $instance ): int {
	return (int) ( ( $instance instanceof WP_Block ? ( $instance->context['postId'] ?? 0 ) : 0 ) ?: get_the_ID() );
}

/*
 * {{ tokens }} in a block's markup. A link field saved by the editor may come URL-encoded (%7B%7B link %7D%7D).
 * Values are escaped for where they land: URLs as URLs, the rest as text (safe in text and in attribute values).
 */
add_filter( 'render_block', function ( $html, $block, $instance = null ) {
	if ( ! $html || ( ! str_contains( $html, '{{' ) && ! str_contains( $html, '%7B%7B' ) ) ) {
		return $html;
	}
	$post_id = mcc_context_post( $instance );
	if ( ! $post_id ) {
		return $html;
	}
	// A token's text: strictly a key and an optional format, else it's left as it was (never decoded into the page).
	$fill = function ( string $inner ) use ( $post_id ): ?string {
		if ( ! preg_match( '/^\s*([a-z0-9_:\-]+(?:\s*\|\s*[a-z0-9_:\-]+)?)\s*$/i', $inner, $t ) ) {
			return null;
		}
		[ $key, $format ] = mcc_parse_key( $t[1] );
		$value            = mcc_value( $post_id, $key, $format );
		return mcc_is_url( $post_id, $key ) ? esc_url( $value ) : esc_html( $value );
	};
	$html = preg_replace_callback( '/%7B%7B(.*?)%7D%7D/i', fn( $m ) => $fill( rawurldecode( $m[1] ) ) ?? $m[0], $html );
	return preg_replace_callback( '/\{\{(.*?)\}\}/', fn( $m ) => $fill( $m[1] ) ?? $m[0], $html );
}, 20, 3 );
