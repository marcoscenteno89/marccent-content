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
 * - A loop inside text: {{ each:city }}{{ title }}{{ separator }}, {{ /separator }}{{ /each }} (mcc_each, below).
 *
 * The post is the loop's current one (the Loop block, an each), else the page's own; in a Loop of a group's rows, the
 * row's fields come first. Keys and formats: inc/values.php.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_block_bindings_source( 'marccent/field', array(
		'label'              => __( 'Marccent field', 'marccent-content' ),
		'uses_context'       => array( 'postId', 'postType' ),
		'get_value_callback' => function ( array $args, WP_Block $block, string $attribute ) {
			$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
			[ $key, $format ] = mcc_parse_key( (string) ( $args['key'] ?? '' ) );
			if ( ( ! $post_id && ! mcc_row() && ! str_contains( $key, '.' ) ) || '' === $key ) {
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
 * - URL attributes (href, src… wp_kses_uri_attributes()), found by WordPress's HTML parser: a token that starts the
 *   URL is a URL, one inside it a part of it (URL-encoded); the URL then goes through esc_url_raw (javascript: and
 *   other unsafe schemes are dropped) and the parser escapes the attribute. A link the editor saved URL-encoded (%7B%7B link %7D%7D) is read there too.
 * - Every token left (in text, in other attributes) is the value escaped as text, which is safe in both.
 * A token is strictly a key and an optional format; anything else between braces is left as it was.
 */
function mcc_token( string $inner ): ?array {
	// A where after a "?" (count:work_order.account?status=New&total>100), its & and < saved as entities in the markup.
	$inner = html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( preg_match( '/^\s*(each:|\/?(each|separator|sep|else)\s*$)/', $inner ) ) {
		return null; // an each's own tags (mcc_each), left as they are when one has no end, so it shows
	}
	return preg_match( '/^\s*([a-z0-9_:.\-]+(?:\?[^|{}]*)?(?:\s*\|\s*[a-z0-9_:\-]+)?)\s*$/i', $inner, $t ) ? mcc_parse_key( $t[1] ) : null;
}

add_filter( 'render_block', function ( $html, $block, $instance = null ) {
	if ( ! $html || ( ! str_contains( $html, '{{' ) && false === stripos( $html, '%7B%7B' ) ) ) {
		return $html;
	}
	return mcc_fill( $html, mcc_context_post( $instance ) );
}, 20, 3 );

// A block's markup with its tokens filled for a post: its {{ each: }} lists first (mcc_each), then the tokens left.
function mcc_fill( string $html, int $post_id ): string {
	$html = mcc_each( $html, $post_id );
	// Without a post or a row, only a group's values (the site's: "office.phone") and the global ones (now, site,
	// search: inc/values.php) can be filled; other tokens stay.
	$known = fn( $token ) => $token && ( $post_id || mcc_row() || str_contains( $token[0], '.' ) || in_array( explode( ':', $token[0] )[0], MCC_GLOBALS, true ) );

	$uri  = array_merge( wp_kses_uri_attributes(), array( 'srcset' ) );
	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag() ) {
		foreach ( $tags->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
			$value = $tags->get_attribute( $name );
			if ( ! is_string( $value ) || ! in_array( $name, $uri, true ) || ( ! str_contains( $value, '{{' ) && false === stripos( $value, '%7B%7B' ) ) ) {
				continue;
			}
			$value = preg_replace_callback( '/%7B%7B(.*?)%7D%7D/i', fn( $m ) => '{{' . rawurldecode( $m[1] ) . '}}', $value );
			// A token that starts the URL is a URL ({{ link }}, {{ website }}#contact); one inside it is a part of it,
			// URL-encoded (…?mlat={{ latitude }}). The whole URL is then escaped once, unless a token in it is left.
			$value = preg_replace_callback( '/\{\{(.*?)\}\}/', function ( $m ) use ( $post_id, $known ) {
				$token = mcc_token( $m[1][0] );
				if ( ! $known( $token ) ) {
					return $m[0][0];
				}
				$v = mcc_value( $post_id, ...$token );
				return 0 === $m[0][1] ? $v : rawurlencode( $v );
			}, trim( $value ), -1, $count, PREG_OFFSET_CAPTURE );
			$value = str_contains( $value, '{{' ) ? $value : esc_url_raw( $value );
			$tags->set_attribute( $name, $value );
		}
	}
	$html = $tags->get_updated_html();

	return preg_replace_callback( '/\{\{(.*?)\}\}/', function ( $m ) use ( $post_id, $known ) {
		$token = mcc_token( $m[1] );
		return $known( $token ) ? esc_html( mcc_value( $post_id, ...$token ) ) : $m[0];
	}, $html );
}


/*
 * A loop inside any text: what's between {{ each:<set> }} and {{ /each }} once per item of the set, its tokens that
 * item's, as the Loop block does with blocks:
 *
 *   <p>We serve {{ each:city?region=eastern-idaho }}{{ title }}{{ separator }}, {{ /separator }}{{ /each }}.</p>
 *
 * The set is a path as count: and sum: take (mcc_set): every post of a type (city), the post's related posts
 * (this.nearby) or a repeating group's rows on it (this.photos), the posts pointing to it (work_order.account), each
 * with a where after a "?". {{ separator }}…{{ /separator }} goes between the items, not after the last; {{ else }} starts what
 * shows when there are none. They nest: an each inside an each goes over its item. At most 200 items.
 */
function mcc_each( string $html, int $post_id ): string {
	$out = '';
	$pos = 0;
	while ( preg_match( '/\{\{\s*each:([^{}]*?)\s*\}\}/', $html, $open, PREG_OFFSET_CAPTURE, $pos ) ) {
		// Its {{ /each }}: past the eaches inside it.
		$depth = 1;
		$scan  = $open[0][1] + strlen( $open[0][0] );
		$close = null;
		while ( preg_match( '/\{\{\s*(each:[^{}]*?|\/each)\s*\}\}/', $html, $t, PREG_OFFSET_CAPTURE, $scan ) ) {
			$depth += '/each' === $t[1][0] ? -1 : 1;
			$scan   = $t[0][1] + strlen( $t[0][0] );
			if ( 0 === $depth ) {
				$close = $t[0];
				break;
			}
		}
		if ( ! $close ) {
			break; // no end: left as it is
		}
		$start = $open[0][1] + strlen( $open[0][0] );
		$out  .= substr( $html, $pos, $open[0][1] - $pos ) . mcc_each_list( $open[1][0], substr( $html, $start, $close[1] - $start ), $post_id );
		$pos   = $close[1] + strlen( $close[0] );
	}
	return $out . substr( $html, $pos );
}

// One {{ each: }}: its items, filled, with its separator between them; or its {{ else }} when there are none.
function mcc_each_list( string $arg, string $body, int $post_id ): string {
	[ $path, $where ]          = array_pad( explode( '?', html_entity_decode( $arg, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 2 ), 2, '' );
	[ $item, $between, $none ] = mcc_each_parts( $body );
	$set                       = mcc_set( $post_id, trim( $path ), mcc_where( $where ) );
	$items                     = array();
	if ( $set && '' === $set['rest'] && isset( $set['rows'] ) ) {
		foreach ( array_slice( $set['rows'], 0, 200 ) as $row ) {
			$GLOBALS['mcc_rows'][] = array( 'fields' => $set['fields'], 'values' => $row );
			$items[]               = mcc_fill( $item, $post_id );
			array_pop( $GLOBALS['mcc_rows'] );
		}
	} elseif ( $set && '' === $set['rest'] ) {
		$ids = array_slice( $set['ids'], 0, 200 );
		_prime_post_caches( $ids, false, true ); // the posts and their fields, in one query rather than one each
		foreach ( $ids as $id ) {
			$items[] = mcc_fill( $item, $id );
		}
	}
	// An item's text is done: braces its values brought ({{ in a title) aren't tokens for the block around it.
	$items = array_map( fn( $i ) => str_replace( '{{', '&#123;&#123;', $i ), $items );
	return $items ? implode( mcc_fill( $between, $post_id ), $items ) : mcc_fill( $none, $post_id );
}

// An each's body as its item, its separator ({{ separator }}…{{ /separator }}) and its {{ else }}: its own, not those of an each
// inside it.
function mcc_each_parts( string $body ): array {
	preg_match_all( '/\{\{\s*(each:[^{}]*?|\/each|else|separator|\/separator|sep|\/sep)\s*\}\}/', $body, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	$depth = 0;
	$found = array();
	foreach ( $tags as $t ) {
		$name = str_starts_with( $t[1][0], 'each:' ) ? 'each' : str_replace( 'separator', 'sep', $t[1][0] ); // the short name, still read
		if ( 'each' === $name || '/each' === $name ) {
			$depth += 'each' === $name ? 1 : -1;
		} elseif ( 0 === $depth && ! isset( $found[ $name ] ) ) {
			$found[ $name ] = $t[0];
		}
	}
	$item = $body;
	$none = '';
	if ( isset( $found['else'] ) ) {
		$item = substr( $body, 0, $found['else'][1] );
		$none = substr( $body, $found['else'][1] + strlen( $found['else'][0] ) );
	}
	$between = '';
	if ( isset( $found['sep'], $found['/sep'] ) && $found['sep'][1] < $found['/sep'][1] && $found['/sep'][1] < strlen( $item ) ) {
		$from    = $found['sep'][1] + strlen( $found['sep'][0] );
		$between = substr( $item, $from, $found['/sep'][1] - $from );
		$item    = substr( $item, 0, $found['sep'][1] ) . substr( $item, $found['/sep'][1] + strlen( $found['/sep'][0] ) );
	}
	return array( $item, $between, $none );
}
