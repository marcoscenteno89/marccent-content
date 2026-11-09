<?php
/**
 * JSON-LD: one @graph per page, its nodes linked by @id, built from what WordPress knows and what the page shows.
 *
 * Built in, on every page: the site's Organization (other plugins can make it theirs: marccent_schema_organization),
 * the WebSite, the WebPage (its type from the page's Page type, or what's shown: CollectionPage for archives,
 * ProfilePage for an author, SearchResultsPage for a search) with its BreadcrumbList; a post's BlogPosting with its
 * author as a Person.
 *
 * From the fields: a post type or a field group can say what it is in schema.org's words (its "schema"):
 *
 *   On a post type, or a group that doesn't repeat: what a post is.
 *     "schema": { "type": "City", "id": "place", "map": { "name": "{{ title }}, {{ state }}",
 *                 "geo": { "@type": "GeoCoordinates", "latitude": "latitude", "longitude": "longitude" } } }
 *   On a repeating group: what its rows are together, and each one.
 *     "schema": { "type": "FAQPage", "id": "faq", "each": { "@type": "Question", "name": "question",
 *                 "acceptedAnswer": { "@type": "Answer", "text": "answer" } } }
 *
 * A value in "map" or "each" is a field's key (its value), text with {{ tokens }} (filled as in blocks), a nested
 * object (its own "@type"), or plain text when it names no field. A post's node is on its page (what the page is
 * about) and wherever a Loop lists it (an ItemList); a group's rows are in the graph where a Loop shows them, so the
 * graph says what the page shows. Loops in the header and footer (template parts) are the site's frame, not the page's:
 * left out. Other plugins add their nodes with marccent_schema_graph.
 */

defined( 'ABSPATH' ) || exit;

// What the Loops on this page showed, for the graph: nodes by @id.
$GLOBALS['mcc_schema_nodes'] = array();
$GLOBALS['mcc_schema_part']  = 0;

// The page types an editor can pick (Page type, in a page's sidebar).
const MCC_PAGE_TYPES = array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'CheckoutPage', 'ItemPage' );

add_action( 'init', function () {
	register_post_meta( 'page', 'page_type', array(
		'type'              => 'string',
		'single'            => true,
		'default'           => '',
		'show_in_rest'      => true,
		'auth_callback'     => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
		'sanitize_callback' => fn( $value ) => in_array( $value, MCC_PAGE_TYPES, true ) ? $value : '',
	) );
} );

// Inside a template part (the header, the footer) or not.
add_filter( 'pre_render_block', function ( $pre, $block ) {
	if ( 'core/template-part' === ( $block['blockName'] ?? '' ) ) {
		++$GLOBALS['mcc_schema_part'];
	}
	return $pre;
}, 10, 2 );
add_filter( 'render_block_core/template-part', function ( $html ) {
	$GLOBALS['mcc_schema_part'] = max( 0, $GLOBALS['mcc_schema_part'] - 1 );
	return $html;
} );

/* The mappings */

// A post type's or a group's schema, from the saved spec as it is (the group editor's Schema box, Fields → JSON).
function mcc_schema_of( string $kind, string $name ): array {
	$s = mcc_spec()[ $kind ][ $name ]['schema'] ?? null;
	return is_array( $s ) ? $s : array();
}

// What a post is: its type's schema and those of its groups that don't repeat, together.
function mcc_schema_post_mapping( WP_Post $post ): array {
	$out = mcc_schema_of( 'types', $post->post_type );
	foreach ( mcc_groups() as $name => $g ) {
		if ( $g['repeat'] || ! mcc_group_on_post( $g, $post->post_type, $post->post_name, (string) get_page_template_slug( $post ) ) ) {
			continue;
		}
		$s = mcc_schema_of( 'groups', $name );
		if ( $s ) {
			$out['type'] = $out['type'] ?? $s['type'] ?? null;
			$out['id']   = $out['id'] ?? $s['id'] ?? null;
			$out['map']  = array_merge( (array) ( $out['map'] ?? array() ), (array) ( $s['map'] ?? array() ) );
		}
	}
	return ! empty( $out['type'] ) ? $out : array();
}

/**
 * A mapping's value for a post (or the Loop row being read): a field's value, text with tokens, a nested object or
 * list, or the text itself. Numbers stay numbers.
 */
function mcc_schema_value( $spec, int $post_id ) {
	if ( is_array( $spec ) && isset( $spec['@each'] ) ) {
		return mcc_schema_each( $spec, $post_id );
	}
	if ( is_array( $spec ) ) {
		$out = array();
		foreach ( $spec as $key => $value ) {
			// "@type" and the like as written; an "@id" with tokens filled ("{{ link }}#place").
			$out[ $key ] = is_string( $key ) && str_starts_with( $key, '@' ) ? ( '@id' === $key && is_string( $value ) && str_contains( $value, '{{' ) ? mcc_schema_value( $value, $post_id ) : $value ) : mcc_schema_value( $value, $post_id );
		}
		return $out;
	}
	if ( ! is_string( $spec ) ) {
		return $spec;
	}
	if ( str_contains( $spec, '{{' ) ) {
		$text = trim( html_entity_decode( wp_strip_all_tags( mcc_fill( $spec, $post_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		// Only the words around the tokens left (", " of two empty values): nothing.
		return preg_match( '/[\p{L}\p{N}]/u', $text ) ? $text : '';
	}
	$field = mcc_field_def( $post_id, $spec );
	// Opening hours: the week's openingHoursSpecification; "hours:special", its days that differ.
	if ( 'hours' === ( $field['type'] ?? '' ) ) {
		return str_ends_with( $spec, ':special' ) ? mcc_hours_special_schema( $field ) : mcc_hours_schema( mcc_schema_raw( $post_id, explode( ':', $spec )[0] ) );
	}
	if ( $field || in_array( explode( ':', $spec )[0], MCC_RESERVED, true ) ) {
		$value = html_entity_decode( mcc_value( $post_id, $spec, 'raw' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return 'number' === ( $field['type'] ?? '' ) && is_numeric( $value ) ? 0 + $value : $value;
	}
	return $spec;
}

// A field's saved value as it is, where a mapping reads it: the row's, a site group's ("business.hours") or the post's.
function mcc_schema_raw( int $post_id, string $name ) {
	$row = mcc_row();
	if ( $row && array_key_exists( $name, $row['fields'] ) ) {
		return $row['values'][ $name ] ?? null;
	}
	if ( str_contains( $name, '.' ) ) {
		[ $group, $sub ] = explode( '.', $name, 2 );
		return mcc_group_value( $group, $post_id )[ $sub ] ?? null;
	}
	return $post_id ? get_post_meta( $post_id, $name, true ) : null;
}

/**
 * A list in a mapping, one item per row or post: { "@each": "<set>", …the item's mapping } with the set as
 * {{ each: }} takes it (mcc_set: services, the site's rows; city, every City; this.nearby; with a where after a
 * "?"). Each item's values are its row's or its post's.
 *   "hasOfferCatalog": { "@type": "OfferCatalog", "itemListElement": { "@each": "services", "@type": "Offer",
 *     "itemOffered": { "@type": "Service", "name": "name", "serviceType": "type" } } }
 *   "areaServed": { "@each": "city", "@id": "{{ link }}#place" }
 *   "sameAs": { "@each": "profiles", "@value": "link" }        a list of values, not objects
 */
function mcc_schema_each( array $spec, int $post_id ): array {
	[ $path, $where ] = array_pad( explode( '?', (string) $spec['@each'], 2 ), 2, '' );
	unset( $spec['@each'] );
	// "@value": a list of plain values, not of objects ("sameAs": { "@each": "profiles", "@value": "link" }).
	$spec = isset( $spec['@value'] ) ? $spec['@value'] : $spec;
	$set = mcc_set( $post_id, trim( $path ), mcc_where( $where ) );
	$out = array();
	if ( $set && '' === $set['rest'] && isset( $set['rows'] ) ) {
		foreach ( $set['rows'] as $row ) {
			$GLOBALS['mcc_rows'][] = array( 'fields' => $set['fields'], 'values' => $row );
			$out[]                 = mcc_schema_value( $spec, $post_id );
			array_pop( $GLOBALS['mcc_rows'] );
		}
	} elseif ( $set && '' === $set['rest'] ) {
		// A post's own values, not those of a row around it (the site group being mapped).
		$rows                = $GLOBALS['mcc_rows'];
		$GLOBALS['mcc_rows'] = array();
		foreach ( array_slice( $set['ids'], 0, 200 ) as $id ) {
			$out[] = mcc_schema_value( $spec, (int) $id );
		}
		$GLOBALS['mcc_rows'] = $rows;
	}
	return $out;
}

/**
 * The site groups' nodes (a group on the site, not repeating, with a schema): its fields by their names, as a row's.
 * One whose id is "organization" is the site's Organization (mcc_schema_organization: a business's details, its type
 * a LocalBusiness or any of schema.org's); the others are nodes of their own on every page. [ id => node ].
 */
function mcc_schema_site_nodes(): array {
	if ( isset( $GLOBALS['mcc_cache']['site_nodes'] ) ) {
		return $GLOBALS['mcc_cache']['site_nodes'];
	}
	$out = array();
	foreach ( mcc_groups() as $name => $g ) {
		$s = mcc_schema_of( 'groups', $name );
		if ( $g['repeat'] || empty( $s['type'] ) || ! in_array( 'options', $g['on'], true ) ) {
			continue;
		}
		$id                    = sanitize_key( $s['id'] ?? $name );
		$GLOBALS['mcc_rows'][] = array( 'fields' => $g['fields'], 'values' => mcc_group_value( $name ) );
		$out[ $id ]            = array_merge( array( '@type' => $s['type'], '@id' => home_url( '/' ) . '#' . $id ), (array) mcc_schema_value( (array) ( $s['map'] ?? array() ), 0 ) );
		array_pop( $GLOBALS['mcc_rows'] );
	}
	return $GLOBALS['mcc_cache']['site_nodes'] = $out;
}

// A post's node from its mapping (null without one): what its page is about, and an item where a Loop lists it.
function mcc_schema_post_node( WP_Post $post ): ?array {
	$m = mcc_schema_post_mapping( $post );
	if ( ! $m ) {
		return null;
	}
	$node = array(
		'@type' => $m['type'],
		'@id'   => get_permalink( $post ) . '#' . sanitize_key( $m['id'] ?? 'entity' ),
		'url'   => get_permalink( $post ),
		'name'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
	);
	return array_merge( $node, mcc_schema_value( (array) ( $m['map'] ?? array() ), $post->ID ) );
}

/* What the Loops show (blocks/loop/render.php calls this after drawing its items) */

function mcc_schema_collecting(): bool {
	return ! is_admin() && ! wp_is_serving_rest_request() && ! wp_is_json_request() && 0 === $GLOBALS['mcc_schema_part'];
}

function mcc_schema_add( array $node ): void {
	$id = $node['@id'] ?? '';
	if ( '' === $id ) {
		$GLOBALS['mcc_schema_nodes'][] = $node;
		return;
	}
	$old = $GLOBALS['mcc_schema_nodes'][ $id ] ?? null;
	// The same list shown twice (two Loops of one group's rows): its items once, together.
	foreach ( array( 'mainEntity', 'itemListElement', 'hasPart' ) as $list ) {
		if ( $old && isset( $old[ $list ], $node[ $list ] ) && array_is_list( $old[ $list ] ) ) {
			$node[ $list ] = array_values( array_unique( array_merge( $old[ $list ], $node[ $list ] ), SORT_REGULAR ) );
		}
	}
	$GLOBALS['mcc_schema_nodes'][ $id ] = $node;
}

// A Loop of a repeating group's rows: the group's node, each row an item ("each"), where the group has a schema.
function mcc_schema_collect_rows( string $group, array $rows, int $post_id ): void {
	$s = mcc_schema_of( 'groups', $group );
	if ( ! $rows || empty( $s['type'] ) || empty( $s['each'] ) || ! mcc_schema_collecting() ) {
		return;
	}
	$fields = mcc_groups()[ $group ]['fields'] ?? array();
	$items  = array();
	foreach ( $rows as $values ) {
		$GLOBALS['mcc_rows'][] = array( 'fields' => $fields, 'values' => $values );
		$items[]               = mcc_schema_value( (array) $s['each'], $post_id );
		array_pop( $GLOBALS['mcc_rows'] );
	}
	$list = $s['list'] ?? ( 'FAQPage' === $s['type'] ? 'mainEntity' : ( 'ItemList' === $s['type'] ? 'itemListElement' : 'hasPart' ) );
	if ( 'itemListElement' === $list ) {
		$items = array_map( fn( $item, $i ) => array( '@type' => 'ListItem', 'position' => $i + 1, 'item' => $item ), $items, array_keys( $items ) );
	}
	$url = mcc_schema_context()['url'];
	mcc_schema_add( array(
		'@type'    => $s['type'],
		'@id'      => $url . '#' . sanitize_key( $s['id'] ?? $group ),
		'isPartOf' => array( '@id' => $url . '#webpage' ),
		$list      => $items,
	) );
}

// A Loop of posts: an ItemList of them, where their type has a schema (each one's node in the graph too).
function mcc_schema_collect_posts( array $posts, int $loop ): void {
	if ( ! $posts || ! mcc_schema_collecting() ) {
		return;
	}
	$items = array();
	foreach ( $posts as $post ) {
		$node = mcc_schema_post_node( $post );
		if ( ! $node ) {
			return; // a type without a schema: no list
		}
		mcc_schema_add( $node + ( $GLOBALS['mcc_schema_nodes'][ $node['@id'] ] ?? array() ) );
		$items[] = array( '@type' => 'ListItem', 'position' => count( $items ) + 1, 'item' => array( '@id' => $node['@id'] ) );
	}
	mcc_schema_add( array( '@type' => 'ItemList', '@id' => mcc_schema_context()['url'] . '#list-' . $loop, 'itemListElement' => $items ) );
}

/* The page */

/**
 * What the request is: its URL, title, description and image (search's: Rank Math's title and description when it's
 * there), the breadcrumb trail ([ name, url ] from Home), the WebPage's type, and the post. Plugins can change it
 * (marccent_schema_context).
 */
function mcc_schema_context(): array {
	static $ctx = null;
	if ( null !== $ctx && ! empty( $ctx['done'] ) ) {
		return $ctx;
	}
	$home = home_url( '/' );
	$ctx  = array( 'post' => null, 'title' => '', 'description' => '', 'url' => '', 'image' => '', 'trail' => array( array( __( 'Home', 'marccent-content' ), $home ) ), 'type' => 'WebPage', 'done' => did_action( 'wp' ) > 0 );
	$name = fn( $s ) => html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	if ( is_singular() ) {
		$post               = get_queried_object();
		$ctx['post']        = $post;
		$ctx['title']       = $name( get_the_title( $post ) );
		$ctx['description'] = wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 30, '…' );
		$ctx['url']         = is_front_page() ? $home : get_permalink( $post );
		$ctx['image']       = has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, 'large' ) : '';
		$type               = get_post_type_object( $post->post_type );
		if ( 'post' === $post->post_type ) {
			$blog = (int) get_option( 'page_for_posts' );
			if ( $blog ) {
				$ctx['trail'][] = array( $name( get_the_title( $blog ) ), get_permalink( $blog ) );
			}
			$category = get_the_category( $post->ID )[0] ?? null;
			if ( $category ) {
				$ctx['trail'][] = array( $name( $category->name ), get_category_link( $category ) );
			}
		} elseif ( $type && $type->has_archive ) {
			$ctx['trail'][] = array( $name( $type->labels->archives ?: $type->labels->name ), get_post_type_archive_link( $post->post_type ) );
		}
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
			$ctx['trail'][] = array( $name( get_the_title( $ancestor ) ), get_permalink( $ancestor ) );
		}
		if ( ! is_front_page() ) {
			$ctx['trail'][] = array( $ctx['title'], $ctx['url'] );
		}
		if ( 'page' === $post->post_type && in_array( get_post_meta( $post->ID, 'page_type', true ), MCC_PAGE_TYPES, true ) ) {
			$ctx['type'] = get_post_meta( $post->ID, 'page_type', true );
		}
	} elseif ( is_author() ) {
		$author       = get_queried_object();
		$ctx['type']  = 'ProfilePage';
		$ctx['title'] = $name( $author->display_name );
		$ctx['url']   = get_author_posts_url( $author->ID );
		$ctx['trail'][] = array( $ctx['title'], $ctx['url'] );
	} elseif ( is_search() ) {
		$ctx['type']  = 'SearchResultsPage';
		$ctx['title'] = $name( wp_get_document_title() );
		$ctx['url']   = get_search_link();
	} elseif ( is_home() && ! get_option( 'page_for_posts' ) ) {
		// The latest posts as the home page: the site's own page, no trail.
		$ctx['type']  = 'CollectionPage';
		$ctx['title'] = $name( get_bloginfo( 'name' ) );
		$ctx['url']   = $home;
	} elseif ( is_archive() || is_home() ) {
		$ctx['type']  = 'CollectionPage';
		// A post type's archive by its archive label ("Service Areas"), not WordPress's "Archives: Cities".
		$type         = is_post_type_archive() ? get_post_type_object( (string) get_query_var( 'post_type' ) ) : null;
		$ctx['title'] = $name( is_home() ? get_the_title( (int) get_option( 'page_for_posts' ) ) : ( $type ? ( $type->labels->archives ?: $type->labels->name ) : get_the_archive_title() ) );
		$ctx['url']   = is_post_type_archive() ? get_post_type_archive_link( get_query_var( 'post_type' ) ) : ( is_home() ? get_permalink( (int) get_option( 'page_for_posts' ) ) : get_pagenum_link( 1, false ) );
		$ctx['description'] = wp_strip_all_tags( get_the_archive_description() );
		$ctx['trail'][]     = array( $ctx['title'], $ctx['url'] );
	} elseif ( ! is_404() ) {
		$ctx['url'] = home_url( add_query_arg( array() ) );
	}
	if ( ! $ctx['image'] ) {
		$ctx['image'] = (string) get_site_icon_url( 512 ); // the brand's, for sharing
	}
	if ( class_exists( '\\RankMath\\Paper\\Paper' ) && did_action( 'wp' ) ) {
		$paper              = \RankMath\Paper\Paper::get();
		$ctx['title']       = $name( $paper->get_title() ) ?: $ctx['title'];
		$ctx['description'] = $paper->get_description() ?: $ctx['description'];
	}
	$ctx = apply_filters( 'marccent_schema_context', $ctx );
	return $ctx;
}

// The site's Organization: its name, address, logo; with a site group mapped as "organization" (a business's
// details, its type a LocalBusiness…), that group's values over these.
function mcc_schema_organization(): array {
	$home = home_url( '/' );
	$logo = get_theme_mod( 'custom_logo' );
	$org  = array(
		'@type'       => 'Organization',
		'@id'         => $home . '#organization',
		'name'        => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'description' => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'url'         => $home,
		'logo'        => $logo ? wp_get_attachment_image_url( $logo, 'full' ) : get_site_icon_url( 512 ),
	);
	$mapped = mcc_schema_site_nodes()['organization'] ?? array();
	if ( $mapped ) {
		$org = array_merge( $org, array_filter( $mapped, fn( $v ) => '' !== $v && array() !== $v ) );
		$org['@id'] = $home . '#organization';
	}
	return apply_filters( 'marccent_schema_organization', $org );
}

// A post's author: a Person, with their profile page, bio and website.
function mcc_schema_person( int $user_id ): array {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return array();
	}
	return array(
		'@type'       => 'Person',
		'@id'         => home_url( '/' ) . '#/person/' . $user->user_nicename,
		'name'        => $user->display_name,
		'url'         => get_author_posts_url( $user_id ),
		'description' => $user->description,
		'sameAs'      => $user->user_url ? array( $user->user_url ) : array(),
		'image'       => get_avatar_url( $user_id, array( 'size' => 256 ) ),
	);
}

function mcc_schema_graph(): array {
	$c     = mcc_schema_context();
	$home  = home_url( '/' );
	$org   = mcc_schema_organization();
	$graph = array();
	$add   = function ( array $node ) use ( &$graph ) {
		$graph[ $node['@id'] ?? count( $graph ) ] = $node;
	};
	$add( $org );
	foreach ( mcc_schema_site_nodes() as $id => $node ) {
		if ( 'organization' !== $id ) {
			$add( $node );
		}
	}
	$add( array(
		'@type'      => 'WebSite',
		'@id'        => $home . '#website',
		'url'        => $home,
		'name'       => $org['name'] ?? get_bloginfo( 'name' ),
		'publisher'  => array( '@id' => $org['@id'] ),
		'inLanguage' => get_bloginfo( 'language' ),
	) );

	if ( $c['url'] ) {
		$url  = $c['url'];
		$page = array(
			'@type'       => $c['type'],
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => $c['title'],
			'description' => $c['description'],
			'isPartOf'    => array( '@id' => $home . '#website' ),
			'about'       => array( '@id' => $org['@id'] ),
			'inLanguage'  => get_bloginfo( 'language' ),
		);
		if ( count( $c['trail'] ) > 1 ) {
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			$add( array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => array_map( fn( $crumb, $i ) => array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb[0], 'item' => $crumb[1] ), $c['trail'], array_keys( $c['trail'] ) ),
			) );
		}
		$post = $c['post'];
		if ( $post ) {
			$page['datePublished'] = get_post_time( 'c', true, $post );
			$page['dateModified']  = get_post_modified_time( 'c', true, $post );
			if ( $c['image'] && has_post_thumbnail( $post ) ) {
				$page['primaryImageOfPage'] = array( '@type' => 'ImageObject', 'url' => $c['image'] );
			}
			// What the post is (its fields' mapping): the page is about it.
			$node = mcc_schema_post_node( $post );
			if ( $node ) {
				$add( $node );
				$page['about'] = array( '@id' => $node['@id'] );
			}
			// A blog post: an article, by its author, published by the site.
			if ( 'post' === $post->post_type ) {
				$person = mcc_schema_person( (int) $post->post_author );
				$add( $person );
				$add( array(
					'@type'            => 'BlogPosting',
					'@id'              => $url . '#article',
					'headline'         => $c['title'],
					'description'      => $c['description'],
					'image'            => has_post_thumbnail( $post ) ? $c['image'] : '',
					'datePublished'    => get_post_time( 'c', true, $post ),
					'dateModified'     => get_post_modified_time( 'c', true, $post ),
					'author'           => array( '@id' => $person['@id'] ?? '' ),
					'publisher'        => array( '@id' => $org['@id'] ),
					'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
					'articleSection'   => wp_list_pluck( get_the_category( $post->ID ), 'name' ),
					'keywords'         => wp_list_pluck( (array) get_the_tags( $post->ID ), 'name' ),
					'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
					'inLanguage'       => get_bloginfo( 'language' ),
				) );
				$page['about'] = array( '@id' => $url . '#article' );
			}
		}
		if ( is_author() ) {
			$person = mcc_schema_person( get_queried_object_id() );
			$add( $person );
			$page['mainEntity'] = array( '@id' => $person['@id'] );
		}
		// What the page's Loops showed; a collection page's main entity is its first list.
		foreach ( $GLOBALS['mcc_schema_nodes'] as $node ) {
			$add( $node );
			if ( 'CollectionPage' === $c['type'] && empty( $page['mainEntity'] ) && 'ItemList' === ( $node['@type'] ?? '' ) ) {
				$page['mainEntity'] = array( '@id' => $node['@id'] );
			}
		}
		$add( $page );
	}
	return array_values( apply_filters( 'marccent_schema_graph', $graph, $c ) );
}

// Drop empty values (an @id with no page yet, a missing image) so validators see only real ones.
function mcc_schema_clean( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$list  = array_is_list( $value );
	$value = array_filter( array_map( 'mcc_schema_clean', $value ), fn( $v ) => null !== $v && '' !== $v && array() !== $v );
	return $list ? array_values( $value ) : $value;
}

// Printed in the head: a block theme has drawn the page (its Loops) before it; a classic theme's is at the end.
$mcc_schema_print = function () {
	static $done = false;
	if ( $done || is_admin() || is_feed() || is_embed() || ! apply_filters( 'marccent_schema_print', true ) ) {
		return;
	}
	$done = true;
	// < > & as \u escapes, so nothing in the data (a title, an answer) can close the script tag.
	printf( "<script type=\"application/ld+json\">%s</script>\n", wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => mcc_schema_clean( mcc_schema_graph() ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
};
add_action( 'wp_head', function () use ( $mcc_schema_print ) {
	if ( wp_is_block_theme() ) {
		$mcc_schema_print();
	}
}, 5 );
add_action( 'wp_footer', $mcc_schema_print, 100 );
