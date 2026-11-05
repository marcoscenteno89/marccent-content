<?php
/**
 * A post's value by key, as plain text, for the Field block, the binding source and {{ tokens }}:
 *
 *   title, excerpt, content, date, modified, link, id, author, type (the type's singular name)
 *   image, image:<size> (the featured image's URL; large by default), image_alt
 *   terms:<taxonomy> (the post's terms, comma-separated)
 *   <field>, <field>:<size> for an image field: one of the spec's fields (inc/types.php), with its prefix and suffix
 *   <image field>:alt: the image's alt text
 *   <file field>: its URL; <file field>:name its file name
 *   <gallery field>: its images' URLs, comma-separated (<field>:<size>); :count how many
 *   <location field>: its address; :latitude, :longitude, :map (a Google Maps link to it)
 *   <html field>: its text (the Field block shows it as HTML)
 *   <group>.<field>: a field of a group that doesn't repeat (the post's, else the site's): "office.phone"
 *   <relation>: the related post's title (several: comma-separated)
 *   <relation>.<key>: any of these keys of the related post, followed as far as it goes ("work_order.account.title");
 *   of several related posts, each one's, comma-separated ("products.title")
 *
 *   count:<what>, sum:<what>.<key>, average:…, minimum:…, maximum:… (or avg, min, max) over related posts, rows or a type's posts, with an optional
 *   where after a "?" (mcc_aggregate): "count:work_order.account?status=New", "sum:this.line_items.quantity", "count:city"
 *
 * Inside a Loop of a group's rows, the row's fields come first: "caption", "image:medium".
 *
 * and a format after a bar, "price | currency": currency (the spec's symbol, 2 decimals), number, date, upper,
 * lower, words:<n> (the first n words), raw (a field without its prefix and suffix).
 */

defined( 'ABSPATH' ) || exit;

// "price | currency" → [ 'price', 'currency' ].
function mcc_parse_key( string $key ): array {
	$parts = array_map( 'trim', explode( '|', $key, 2 ) );
	return array( $parts[0], $parts[1] ?? '' );
}

// The Loop's rows being rendered, innermost last: [ 'fields' => the group's fields, 'values' => the row ].
$GLOBALS['mcc_rows'] = array();

function mcc_row(): ?array {
	return $GLOBALS['mcc_rows'] ? end( $GLOBALS['mcc_rows'] ) : null;
}

// The field a key names, where the value comes from: the current row's, a group's ("office.phone") or the post's; at
// the end of the relations it follows ("account.logo": the account's logo field).
function mcc_field_def( int $post_id, string $key ): ?array {
	$name = explode( ':', mcc_parse_key( $key )[0], 2 )[0];
	$row  = mcc_row();
	if ( $row && isset( $row['fields'][ $name ] ) ) {
		return $row['fields'][ $name ];
	}
	if ( str_contains( $name, '.' ) ) {
		[ $first, $rest ] = explode( '.', $name, 2 );
		$f                = $row['fields'][ $first ] ?? mcc_post_field( $post_id, $first );
		if ( $f && 'relation' === $f['type'] ) {
			return mcc_type_field_def( $f['to'], $rest );
		}
		return mcc_groups()[ $first ]['fields'][ $rest ] ?? null; // a group's field ("office.phone")
	}
	return mcc_post_field( $post_id, $name );
}

// A post type's field a key names, through its relations ("account.owner.email").
function mcc_type_field_def( string $type, string $name ): ?array {
	if ( str_contains( $name, '.' ) ) {
		[ $first, $rest ] = explode( '.', $name, 2 );
		$f                = mcc_fields( $type )[ $first ] ?? null;
		return $f && 'relation' === $f['type'] ? mcc_type_field_def( $f['to'], $rest ) : null;
	}
	return mcc_fields( $type )[ $name ] ?? null;
}

/**
 * A key's first step when it follows a relation ("account.title"): the related posts (the row's relation's, else the
 * post's) and the rest of the key, to read on each. Null when the key's first part isn't a relation.
 */
function mcc_relation_step( int $post_id, string $name ): ?array {
	if ( ! str_contains( $name, '.' ) ) {
		return null;
	}
	[ $first, $rest ] = explode( '.', $name, 2 );
	$row              = mcc_row();
	if ( $row && isset( $row['fields'][ $first ] ) ) {
		$f   = $row['fields'][ $first ];
		$raw = $row['values'][ $first ] ?? '';
	} elseif ( $f = mcc_post_field( $post_id, $first ) ) {
		$raw = post_password_required( $post_id ) ? '' : mcc_meta( $post_id, $first, $f );
	} else {
		return null;
	}
	return 'relation' === $f['type'] ? array( 'ids' => mcc_related_ids( $raw, $f ), 'rest' => $rest ) : null;
}

/**
 * Before a Loop renders its items: the posts their relations point to, in one query rather than one per item; their
 * fields too when the item reads one through the relation ("account.city"). Only the relations the item's markup
 * names. Each item is a post id, or a row (its values).
 */
function mcc_prime_related( array $fields, array $items, string $markup ): void {
	$ids  = array();
	$meta = false;
	foreach ( $fields as $name => $f ) {
		if ( 'relation' !== $f['type'] || ! str_contains( $markup, $name ) ) {
			continue;
		}
		$meta = $meta || preg_match( '/\b' . preg_quote( $name, '/' ) . '\.(?!(?:title|link|id)\b)/', $markup );
		foreach ( $items as $item ) {
			$raw = is_array( $item ) ? ( $item[ $name ] ?? '' ) : mcc_meta( $item, $name, $f );
			$ids = array_merge( $ids, array_map( 'absint', (array) $raw ) );
		}
	}
	$ids = array_values( array_filter( array_unique( $ids ) ) );
	if ( $ids ) {
		_prime_post_caches( $ids, false, $meta );
	}
}

// Reads a key on each of a step's posts, as themselves (not the Loop's row): their values, in order.
function mcc_on_related( array $step, callable $read ): array {
	$GLOBALS['mcc_rows'][] = null;
	$out                   = array_map( $read, $step['ids'] );
	array_pop( $GLOBALS['mcc_rows'] );
	return $out;
}

/**
 * The posts a key ends on when its last field is a relation ("account", "work_order.account"), for a Field block to
 * link each one; null when it isn't one.
 */
function mcc_key_posts( int $post_id, string $key ): ?array {
	$name = explode( ':', mcc_parse_key( $key )[0], 2 )[0];
	$step = mcc_relation_step( $post_id, $name );
	if ( $step ) {
		$lists = array_filter( mcc_on_related( $step, fn( $id ) => mcc_key_posts( $id, $step['rest'] ) ), 'is_array' );
		return $lists ? array_values( array_unique( array_merge( ...$lists ) ) ) : ( $step['ids'] ? null : array() );
	}
	$row = mcc_row();
	if ( $row && isset( $row['fields'][ $name ] ) ) {
		$f = $row['fields'][ $name ];
		return 'relation' === $f['type'] ? mcc_related_ids( $row['values'][ $name ] ?? '', $f ) : null;
	}
	$f = mcc_post_field( $post_id, $name );
	if ( ! $f || 'relation' !== $f['type'] ) {
		return null;
	}
	return post_password_required( $post_id ) ? array() : mcc_related_ids( mcc_meta( $post_id, $name, $f ), $f );
}

/**
 * The global values, the same anywhere (a page, the footer, a 404), from a fixed list of public ones, never any
 * setting by name:
 *   now:year, now:month, now:day, now:weekday, now:date   today (the site's time zone; a cached page keeps its day)
 *   site:name, site:tagline, site:url, site:logo          the site
 *   author:name, author:bio, author:link, author:image    the post's author (on an author's page, theirs): what their
 *                                                         public page shows, never their email or login
 *   search                                                what the visitor searched for: shown as text only, never
 *                                                         read in a where or a Show if ($GLOBALS['mcc_no_visitor'])
 * Null for anything else, so a post's own values are read as before.
 */
const MCC_GLOBALS = array( 'now', 'site', 'search' );

function mcc_global_value( string $name, string $arg, int $post_id ): ?string {
	$text = fn( $s ) => html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	switch ( $name ) {
		case 'now':
			$formats = array( 'year' => 'Y', 'month' => 'F', 'day' => 'j', 'weekday' => 'l', 'date' => get_option( 'date_format' ) );
			return isset( $formats[ $arg ] ) ? wp_date( $formats[ $arg ] ) : '';
		case 'site':
			$logo = get_theme_mod( 'custom_logo' );
			$site = array(
				'name'    => $text( get_bloginfo( 'name' ) ),
				'tagline' => $text( get_bloginfo( 'description' ) ),
				'url'     => home_url( '/' ),
				'logo'    => $logo ? (string) wp_get_attachment_image_url( $logo, 'full' ) : (string) get_site_icon_url( 512 ),
			);
			return $site[ $arg ] ?? '';
		case 'author':
			if ( '' === $arg ) {
				return null; // {{ author }}: the post's, read with its other values
			}
			$post = $post_id ? get_post( $post_id ) : null;
			$user = $post ? (int) $post->post_author : ( is_author() ? get_queried_object_id() : 0 );
			if ( ! $user ) {
				return '';
			}
			$author = array(
				'name'  => (string) get_the_author_meta( 'display_name', $user ),
				'bio'   => (string) get_the_author_meta( 'description', $user ),
				'link'  => get_author_posts_url( $user ),
				'image' => (string) get_avatar_url( $user, array( 'size' => 256 ) ),
			);
			return $author[ $arg ] ?? '';
		case 'search':
			return '' === $arg && empty( $GLOBALS['mcc_no_visitor'] ) ? get_search_query( false ) : '';
	}
	return null;
}

function mcc_value( int $post_id, string $key, string $format = '' ): string {
	[ $name, $arg ] = array_pad( explode( ':', $key, 2 ), 2, '' );

	// {{ loop:number }}: the item's place in its Loop, 1 for the first (on later pages, counting on): numbered steps.
	if ( 'loop' === $name && 'number' === $arg ) {
		return empty( $GLOBALS['mcc_loop_pos'] ) ? '' : (string) end( $GLOBALS['mcc_loop_pos'] );
	}
	$global = mcc_global_value( $name, $arg, $post_id );
	if ( null !== $global ) {
		return mcc_format( $global, $format );
	}

	// A relation followed: the rest of the key on the related post (each one's, when there are several).
	$step = mcc_relation_step( $post_id, $name );
	if ( $step ) {
		$rest = $step['rest'] . ( '' !== $arg ? ":$arg" : '' );
		return implode( ', ', array_filter( mcc_on_related( $step, fn( $id ) => mcc_value( $id, $rest, $format ) ), 'strlen' ) );
	}

	$row = mcc_row();
	if ( $row && isset( $row['fields'][ $name ] ) ) {
		$field = $row['fields'][ $name ];
		return mcc_format( mcc_affix( mcc_display_value( $row['values'][ $name ] ?? '', $field, $arg ), $field, $format ), $format );
	}
	if ( in_array( $name, MCC_AGGREGATES, true ) && '' !== $arg ) {
		return mcc_format( mcc_aggregate( $post_id, $name, $arg ), $format );
	}
	if ( str_contains( $name, '.' ) ) {
		[ $group, $sub ] = explode( '.', $name, 2 );
		$g     = mcc_groups()[ $group ] ?? null;
		$field = $g['fields'][ $sub ] ?? null;
		if ( ! $field || $g['repeat'] ) {
			return '';
		}
		$values = mcc_group_value( $group, $post_id );
		return mcc_format( mcc_affix( mcc_display_value( $values[ $sub ] ?? '', $field, $arg ), $field, $format ), $format );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	// A password-protected post shows only its title, link and the like until its password is given.
	$hidden = post_password_required( $post );
	$field  = mcc_fields( $post->post_type )[ $name ] ?? null;
	$text           = fn( $s ) => trim( html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) );

	switch ( true ) {
		case 'title' === $name:
			$value = $text( get_the_title( $post ) );
			break;
		case $hidden && ( in_array( $name, array( 'excerpt', 'content' ), true ) || null !== $field ):
			$value = '';
			break;
		case 'excerpt' === $name:
			$value = $text( get_the_excerpt( $post ) );
			break;
		case 'content' === $name:
			$value = $text( strip_shortcodes( $post->post_content ) );
			break;
		case 'date' === $name:
			$value = get_the_date( '', $post );
			break;
		case 'modified' === $name:
			$value = get_the_modified_date( '', $post );
			break;
		case 'link' === $name:
			$value = (string) get_permalink( $post );
			break;
		case 'id' === $name:
			$value = (string) $post->ID;
			break;
		case 'author' === $name:
			$value = get_the_author_meta( 'display_name', $post->post_author );
			break;
		case 'type' === $name:
			$value = get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type;
			break;
		case 'image' === $name:
			$value = (string) get_the_post_thumbnail_url( $post, $arg ?: 'large' );
			break;
		case 'image_alt' === $name:
			$value = (string) get_post_meta( (int) get_post_thumbnail_id( $post ), '_wp_attachment_image_alt', true );
			break;
		case 'terms' === $name:
			$terms = get_the_terms( $post, $arg );
			$value = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
			break;
		case null !== $field:
			$value = mcc_affix( mcc_field_value( $post->ID, $name, $field, $arg ), $field, $format );
			break;
		default:
			$value = '';
	}
	return mcc_format( $value, $format );
}

const MCC_AGGREGATES = array( 'count', 'sum', 'average', 'minimum', 'maximum', 'avg', 'min', 'max' ); // the last three: the short names, still read

/**
 * The set a path names, seen from a post: what count:, sum:… (mcc_aggregate) and {{ each: }} (inc/bindings.php) go
 * over, only what passes the where's tests (mcc_where):
 *   this.line_items       a repeating group's rows on the post
 *   this.products         the post's own related posts, in their order
 *   work_order.account    the posts of a type whose relation is the post (Django's work_order_set)
 *   city                  every published post of a type, in their menu order, then by title; page?parent=services:
 *                         the pages under one
 * and after the set, a key on each (sum:work_order.account.total, sum:this.line_items.quantity): 'rest'.
 * [ 'rows' => …, 'fields' => the group's fields ] or [ 'ids' => …, 'to' => their type ], with 'rest'; null when the
 * path names no group, relation or type (or the post's are behind a password).
 */
function mcc_set( int $post_id, string $path, array $tests ): ?array {
	$open = $post_id && ! post_password_required( $post_id );
	if ( str_starts_with( $path, 'this.' ) ) {
		if ( ! $open ) {
			return null;
		}
		$type             = (string) get_post_type( $post_id );
		[ $first, $rest ] = array_pad( explode( '.', substr( $path, 5 ), 2 ), 2, '' );
		$g                = mcc_groups()[ $first ] ?? null;
		if ( $g && $g['repeat'] && in_array( $type, mcc_group_types( $g ), true ) ) {
			$rows = array_values( array_filter( mcc_group_value( $first, $post_id ), fn( $r ) => is_array( $r ) && mcc_where_row( $r, $tests ) ) );
			return array( 'rows' => $rows, 'fields' => $g['fields'], 'rest' => $rest );
		}
		$f = mcc_fields( $type )[ $first ] ?? null;
		if ( ! $f || 'relation' !== $f['type'] ) {
			return null;
		}
		$ids = mcc_related_ids( mcc_meta( $post_id, $first, $f ), $f );
		if ( $ids && $tests ) {
			$ids = get_posts( array( 'post_type' => $f['to'], 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => mcc_where_meta( $f['to'], $tests ) ) );
		}
		return array( 'ids' => $ids, 'to' => $f['to'], 'rest' => $rest );
	}
	[ $of, $by, $rest ] = array_pad( explode( '.', $path, 3 ), 3, '' );
	$f                  = mcc_fields( $of )[ $by ] ?? null;
	$posts              = array( 'post_type' => $of, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => -1, 'fields' => 'ids' );
	if ( $f && 'relation' === $f['type'] ) {
		if ( ! $open ) {
			return null;
		}
		$ids = get_posts( $posts + array( 'meta_query' => array_merge( array( 'relation' => 'AND', array( 'key' => $by, 'value' => $post_id, 'compare' => '=', 'type' => 'NUMERIC' ) ), mcc_where_meta( $of, $tests ) ) ) );
		return array( 'ids' => $ids, 'to' => $of, 'rest' => $rest );
	}
	if ( ! post_type_exists( $of ) || ! is_post_type_viewable( $of ) ) {
		return null;
	}
	// parent=<path>: the children of one post of a hierarchical type (page?parent=services), as the Loop's parent.
	foreach ( $tests as [ $name, $op, $want ] ) {
		if ( 'parent' === $name && '=' === $op ) {
			$parent               = get_page_by_path( $want, OBJECT, $of );
			$posts['post_parent'] = $parent ? $parent->ID : -1;
		}
	}
	$ids = get_posts( $posts + array( 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ), 'meta_query' => mcc_where_meta( $of, $tests ) ) );
	return array( 'ids' => $ids, 'to' => $of, 'rest' => '' === $by ? '' : rtrim( "$by.$rest", '.' ) );
}

/**
 * A count, sum, average, least or greatest over a set (mcc_set), as Django's aggregates:
 *   count:work_order.account            the work orders whose account is the post (a post type and its relation)
 *   sum:work_order.account.total        their totals: after the relation, any key on each (through relations too)
 *   count:this.products                 the post's own related posts; sum:this.products.price
 *   count:this.line_items               a repeating group's rows on the post; sum:this.line_items.quantity
 *   count:city                          every post of a type; sum:product.price
 * each with a where after a "?" (mcc_where): "count:work_order.account?status=New,Scheduled". A count is 0 and a sum
 * 0 when there's nothing; an average, least or greatest empty. Empty too when the key names no relation, group or type.
 */
function mcc_aggregate( int $post_id, string $fn, string $arg ): string {
	[ $path, $where ] = array_pad( explode( '?', $arg, 2 ), 2, '' );
	$set              = mcc_set( $post_id, $path, mcc_where( $where ) );
	if ( ! $set ) {
		return '';
	}
	$rest = $set['rest'];
	if ( isset( $set['rows'] ) ) {
		$values = array();
		foreach ( '' === $rest ? array() : $set['rows'] as $r ) {
			$GLOBALS['mcc_rows'][] = array( 'fields' => $set['fields'], 'values' => $r );
			$values[]              = mcc_value( $post_id, $rest, 'raw' );
			array_pop( $GLOBALS['mcc_rows'] );
		}
		return mcc_reduce( $fn, count( $set['rows'] ), $values );
	}
	$ids = $set['ids'];
	if ( '' !== $rest && $ids ) {
		// The posts read below, in one query rather than one each (their fields too, when the key names one).
		_prime_post_caches( $ids, false, isset( mcc_fields( $set['to'] )[ explode( '.', $rest, 2 )[0] ] ) );
	}
	$values = '' === $rest ? array() : mcc_on_related( array( 'ids' => $ids ), fn( $id ) => mcc_value( $id, $rest, 'raw' ) );
	return mcc_reduce( $fn, count( $ids ), $values );
}

// The count, or the numbers' sum, average, least or greatest (a value of several related posts, "85.5, 1200": each).
function mcc_reduce( string $fn, int $count, array $values ): string {
	if ( 'count' === $fn ) {
		return (string) $count;
	}
	$numbers = array();
	foreach ( $values as $value ) {
		foreach ( explode( ',', (string) $value ) as $n ) {
			if ( is_numeric( trim( $n ) ) ) {
				$numbers[] = 0 + trim( $n );
			}
		}
	}
	if ( ! $numbers ) {
		return 'sum' === $fn ? '0' : '';
	}
	$fn  = array( 'avg' => 'average', 'min' => 'minimum', 'max' => 'maximum' )[ $fn ] ?? $fn;
	$out = array( 'sum' => fn() => array_sum( $numbers ), 'average' => fn() => array_sum( $numbers ) / count( $numbers ), 'minimum' => fn() => min( $numbers ), 'maximum' => fn() => max( $numbers ) )[ $fn ]();
	return (string) round( $out, 6 );
}

// A field's prefix and suffix around its value (not around a URL or an image, nor with the raw format).
function mcc_affix( string $value, array $field, string $format ): string {
	if ( '' === $value || 'raw' === $format || in_array( $field['type'], array( 'url', 'image' ), true ) ) {
		return $value;
	}
	return ( $field['prefix'] ?? '' ) . $value . ( $field['suffix'] ?? '' );
}

function mcc_field_value( int $post_id, string $name, array $field, string $size = '' ): string {
	return mcc_display_value( mcc_meta( $post_id, $name, $field ), $field, $size );
}

// A field's saved value as text: an image's URL (or its alt text, "alt"), Yes / No, a date in the site's format, the
// related posts' titles.
function mcc_display_value( $raw, array $field, string $size = '' ): string {
	if ( 'relation' === $field['type'] ) {
		$text = fn( $id ) => trim( html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ) );
		return implode( ', ', array_map( $text, mcc_related_ids( $raw, $field ) ) );
	}
	if ( 'gallery' === $field['type'] ) {
		$ids = array_filter( array_map( 'absint', (array) $raw ) );
		return 'count' === $size ? (string) count( $ids ) : implode( ', ', array_filter( array_map( fn( $id ) => (string) wp_get_attachment_image_url( $id, $size ?: 'large' ), $ids ) ) );
	}
	if ( 'location' === $field['type'] ) {
		$l = (array) $raw;
		// Older saves have lat and lng: read as latitude and longitude, and :lat and :lng still ask for them.
		$l = array(
			'address'   => (string) ( $l['address'] ?? '' ),
			'latitude'  => (string) ( $l['latitude'] ?? $l['lat'] ?? '' ),
			'longitude' => (string) ( $l['longitude'] ?? $l['lng'] ?? '' ),
		);
		$size = array( 'lat' => 'latitude', 'lng' => 'longitude' )[ $size ] ?? $size;
		if ( 'map' === $size ) {
			$q = '' !== $l['latitude'] ? $l['latitude'] . ',' . $l['longitude'] : $l['address'];
			return '' !== $q ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $q ) : '';
		}
		return $l[ in_array( $size, array( 'latitude', 'longitude' ), true ) ? $size : 'address' ];
	}
	if ( is_array( $raw ) || is_object( $raw ) ) {
		return '';
	}
	switch ( $field['type'] ) {
		case 'file':
			if ( ! $raw ) {
				return '';
			}
			return 'name' === $size ? wp_basename( (string) get_attached_file( (int) $raw ) ) : (string) wp_get_attachment_url( (int) $raw );
		case 'html':
			return trim( html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES, 'UTF-8' ) );
		case 'image':
			if ( 'alt' === $size ) {
				return $raw ? (string) get_post_meta( (int) $raw, '_wp_attachment_image_alt', true ) : '';
			}
			return $raw ? (string) wp_get_attachment_image_url( (int) $raw, $size ?: 'large' ) : '';
		case 'boolean':
			return $raw ? __( 'Yes', 'marccent-content' ) : __( 'No', 'marccent-content' );
		case 'date':
			return $raw ? date_i18n( get_option( 'date_format' ), strtotime( $raw ) ) : '';
		case 'number':
			return '' === $raw ? '' : (string) $raw;
		default:
			return (string) $raw;
	}
}

function mcc_format( string $value, string $format ): string {
	if ( '' === $value || '' === $format ) {
		return $value;
	}
	[ $name, $arg ] = array_pad( explode( ':', $format, 2 ), 2, '' );
	switch ( $name ) {
		case 'currency':
			$number = (float) preg_replace( '/[^0-9.\-]/', '', $value );
			return mcc_spec()['currency'] . number_format_i18n( $number, floor( $number ) == $number ? 0 : 2 );
		case 'number':
			return number_format_i18n( (float) preg_replace( '/[^0-9.\-]/', '', $value ) );
		case 'date':
			$time = strtotime( $value );
			return $time ? date_i18n( $arg ?: get_option( 'date_format' ), $time ) : $value;
		case 'upper':
			return mb_strtoupper( $value );
		case 'lower':
			return mb_strtolower( $value );
		case 'words':
			return wp_trim_words( $value, max( 1, (int) $arg ) );
		default:
			return $value;
	}
}

/**
 * A block's "show if" (its mccShowIf attribute): a where (mcc_where's syntax) on the current row's, post's or site's
 * values, by any key the tokens take: "price>0", "office.phone!=" (not empty), "featured=1", "status=New,Scheduled".
 * A yes / no field is 1 or empty. Whether the block shows.
 */
function mcc_show_if( string $where, int $post_id ): bool {
	$tests = mcc_where( $where );
	if ( ! $tests ) {
		return true;
	}
	$values = array();
	$GLOBALS['mcc_no_visitor'] = true; // what a visitor typed doesn't decide what shows
	foreach ( $tests as [ $key ] ) {
		$value          = mcc_value( $post_id, $key, 'raw' );
		$values[ $key ] = 'boolean' === ( mcc_field_def( $post_id, $key )['type'] ?? '' ) ? ( __( 'Yes', 'marccent-content' ) === $value ? '1' : '' ) : $value;
	}
	$GLOBALS['mcc_no_visitor'] = false;
	$yes = fn( $want ) => in_array( strtolower( $want ), array( 'yes', 'true', 'on' ), true ) ? '1' : ( in_array( strtolower( $want ), array( 'no', 'false', 'off' ), true ) ? '' : $want );
	return mcc_where_row( $values, array_map( fn( $t ) => array( $t[0], $t[1], $yes( $t[2] ) ), $tests ) );
}
