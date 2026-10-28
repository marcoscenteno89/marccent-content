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
			// Text attributes are put in as HTML (rich text); URLs and alt texts as attribute values, escaped there, URLs
			// without unsafe schemes (javascript:…).
			if ( in_array( $attribute, array( 'content', 'caption', 'text' ), true ) ) {
				return esc_html( $value );
			}
			return in_array( $attribute, array( 'url', 'href', 'src' ), true ) ? esc_url_raw( $value ) : $value;
		},
	) );
} );

// The post a block renders for: its context's (inside a loop), else the current post.
function mcc_context_post( $instance ): int {
	return (int) ( ( $instance instanceof WP_Block ? ( $instance->context['postId'] ?? 0 ) : 0 ) ?: get_the_ID() );
}

/*
 * {{ tokens }} in a block's markup, in two passes:
 * - URL attributes (href, src… wp_kses_uri_attributes()), found by WordPress's HTML parser: a token there is a URL,
 *   so its value goes through esc_url_raw (javascript: and other unsafe schemes are dropped) and the parser escapes
 *   the attribute. A link the editor saved URL-encoded (%7B%7B link %7D%7D) is read there too.
 * - Every token left (in text, in other attributes) is the value escaped as text, which is safe in both.
 * A token is strictly a key and an optional format; anything else between braces is left as it was.
 */
function mcc_token( string $inner ): ?array {
	return preg_match( '/^\s*([a-z0-9_:\-]+(?:\s*\|\s*[a-z0-9_:\-]+)?)\s*$/i', $inner, $t ) ? mcc_parse_key( $t[1] ) : null;
}

add_filter( 'render_block', function ( $html, $block, $instance = null ) {
	if ( ! $html || ( ! str_contains( $html, '{{' ) && false === stripos( $html, '%7B%7B' ) ) ) {
		return $html;
	}
	$post_id = mcc_context_post( $instance );
	if ( ! $post_id ) {
		return $html;
	}

	$uri  = array_merge( wp_kses_uri_attributes(), array( 'srcset' ) );
	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag() ) {
		foreach ( $tags->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
			$value = $tags->get_attribute( $name );
			if ( ! is_string( $value ) || ! in_array( $name, $uri, true ) || ( ! str_contains( $value, '{{' ) && false === stripos( $value, '%7B%7B' ) ) ) {
				continue;
			}
			$value = preg_replace_callback( '/%7B%7B(.*?)%7D%7D/i', fn( $m ) => '{{' . rawurldecode( $m[1] ) . '}}', $value );
			$value = preg_replace_callback( '/\{\{(.*?)\}\}/', function ( $m ) use ( $post_id ) {
				$token = mcc_token( $m[1] );
				return $token ? esc_url_raw( mcc_value( $post_id, ...$token ) ) : $m[0];
			}, $value );
			$tags->set_attribute( $name, $value );
		}
	}
	$html = $tags->get_updated_html();

	return preg_replace_callback( '/\{\{(.*?)\}\}/', function ( $m ) use ( $post_id ) {
		$token = mcc_token( $m[1] );
		return $token ? esc_html( mcc_value( $post_id, ...$token ) ) : $m[0];
	}, $html );
}, 20, 3 );
