<?php
/**
 * Sources (Fields → Sources): data from another site's API, pulled into the fields when someone presses Pull now,
 * never on a schedule and never live on a page. Once pulled it's the site's content like any other: Loops, patterns,
 * {{ tokens }} and the JSON-LD show it.
 *
 * A source is a request (an https URL, headers, a body), where its items are in the answer (a path: "reviews"), where
 * they go (a repeating group's rows, the site's or a page's; or posts of a type) and what each field takes from an
 * item (a map, a line each):
 *
 *   name = authorAttribution.displayName      a path in the item (dots; a number for a list's place: photos.0.url)
 *   source = "Google"                         text in quotes, as it is
 *   photo = authorAttribution.photoUri        an image field: the image is copied into the Media Library
 *   photo:alt = authorAttribution.displayName its alt text (RULES.md A7)
 *
 * "Match by" names the fields that say two items are the same (a review's name and date): those rows or posts are
 * updated, the others added, and rows or posts the source didn't bring (typed by hand) are left as they are. "Also
 * save" puts values from the whole answer into a site group's fields (google.rating = rating).
 *
 * Keys: {{ key:google_places }} in the URL, a header or the body is that key from Appearance → Marccent settings,
 * filled in only as the request goes out: never saved in the source, its log or the export. Requests are https only,
 * through wp_safe_remote_request (no address on the server's own network), time-limited and size-limited; every value
 * is cleaned for its field's type, so nothing in an answer runs as code or HTML.
 */

defined( 'ABSPATH' ) || exit;

const MCC_SOURCES     = 'marccent_sources';
const MCC_SOURCES_LOG = 'marccent_sources_log';

function mcc_sources(): array {
	$saved = get_option( MCC_SOURCES, array() );
	return is_array( $saved ) ? $saved : array();
}

// Where a source can put its items: "rows:<group>" (a site group's rows), "rows:<group>@<page slug>" (a page's),
// "posts:<type>". [ value => label ].
function mcc_source_targets(): array {
	$out = array();
	foreach ( mcc_groups() as $name => $g ) {
		if ( ! $g['repeat'] ) {
			continue;
		}
		if ( in_array( 'options', $g['on'], true ) ) {
			/* translators: %s: a field group's label */
			$out[ "rows:$name" ] = sprintf( __( '%s rows (the site’s)', 'marccent-content' ), $g['label'] );
		}
		foreach ( $g['on'] as $on ) {
			if ( preg_match( '/^page:([a-z0-9-]+)$/', $on, $m ) ) {
				/* translators: 1: a field group's label, 2: a page's slug */
				$out[ "rows:$name@{$m[1]}" ] = sprintf( __( '%1$s rows on the page “%2$s”', 'marccent-content' ), $g['label'], $m[1] );
			}
		}
	}
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type => $object ) {
		if ( 'attachment' !== $type ) {
			/* translators: %s: a post type's name */
			$out[ "posts:$type" ] = sprintf( __( '%s (a post each)', 'marccent-content' ), $object->labels->name );
		}
	}
	return $out;
}

// A source as saved: each setting checked.
function mcc_source_sanitize( array $in ): array {
	$lines = fn( $s ) => implode( "\n", array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', (string) $s ) ) ) ) );
	$url   = trim( (string) ( $in['url'] ?? '' ) );
	return array(
		'label'   => sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
		'method'  => 'POST' === ( $in['method'] ?? '' ) ? 'POST' : 'GET',
		// A {{ key:… }} stays as it is; the rest of the address has to be https.
		'url'     => str_starts_with( $url, 'https://' ) ? $url : '',
		'headers' => $lines( $in['headers'] ?? '' ),
		'body'    => trim( (string) ( $in['body'] ?? '' ) ),
		'items'   => preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) ( $in['items'] ?? '' ) ),
		'into'    => array_key_exists( (string) ( $in['into'] ?? '' ), mcc_source_targets() ) ? (string) $in['into'] : '',
		'map'     => $lines( $in['map'] ?? '' ),
		'also'    => $lines( $in['also'] ?? '' ),
		'match'   => implode( ', ', array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $in['match'] ?? '' ) ) ) ) ),
		'limit'   => max( 1, min( 100, (int) ( $in['limit'] ?? 20 ) ) ),
		'status'  => in_array( $in['status'] ?? '', array( 'publish', 'draft' ), true ) ? $in['status'] : 'draft',
	);
}

/* Pulling */

// A key from Appearance → Marccent settings, by its {{ key:id }}; only a key that's on that page, else nothing.
function mcc_source_keys( string $text ): string {
	return (string) preg_replace_callback( '/\{\{\s*key:([a-z0-9_]+)\s*\}\}/', function ( $m ) {
		return function_exists( 'marccent_keys' ) && isset( marccent_keys()[ $m[1] ] ) ? marccent_key( $m[1] ) : '';
	}, $text );
}

// A value at a path in an answer: "text.text", "photos.0.url"; '' is the whole thing.
function mcc_source_path( $data, string $path ) {
	foreach ( '' === $path ? array() : explode( '.', $path ) as $step ) {
		if ( is_array( $data ) && array_key_exists( $step, $data ) ) {
			$data = $data[ $step ];
		} elseif ( is_array( $data ) && is_numeric( $step ) && array_key_exists( (int) $step, $data ) ) {
			$data = $data[ (int) $step ];
		} else {
			return null;
		}
	}
	return $data;
}

// A map's lines as [ field, alt (true for field:alt), path or null, text or null ].
function mcc_source_map( string $map ): array {
	$out = array();
	foreach ( explode( "\n", $map ) as $line ) {
		if ( ! preg_match( '/^\s*([a-z0-9_.]+)(:alt)?\s*=\s*(.+?)\s*$/i', $line, $m ) ) {
			continue;
		}
		$quoted = preg_match( '/^"(.*)"$/', $m[3], $q );
		$out[]  = array( strtolower( $m[1] ), '' !== $m[2], $quoted ? null : $m[3], $quoted ? $q[1] : null );
	}
	return $out;
}

// An image from an answer, in the Media Library (once: the same address again is the same image), or 0.
function mcc_source_image( string $url, string $alt ): int {
	if ( ! wp_http_validate_url( $url ) || ! str_starts_with( $url, 'https://' ) ) {
		return 0;
	}
	$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_mcc_source_url', 'meta_value' => $url ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	if ( $found ) {
		return (int) $found[0];
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$fail = function ( string $why ) {
		$GLOBALS['mcc_source_image_errors'][] = $why; // in the pull's message
		return 0;
	};
	$file = download_url( $url, 15 );
	if ( is_wp_error( $file ) ) {
		return $fail( $file->get_error_message() );
	}
	// A name from its alt text (RULES.md A7: a file name that says what it shows), an image type only.
	$type = wp_get_image_mime( $file );
	$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' )[ $type ] ?? '';
	if ( '' === $ext ) {
		wp_delete_file( $file );
		return $fail( __( 'not an image', 'marccent-content' ) );
	}
	$id = media_handle_sideload( array( 'name' => sanitize_title( $alt ?: 'image' ) . ".$ext", 'tmp_name' => $file ), 0 );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $file );
		return $fail( $id->get_error_message() );
	}
	update_post_meta( $id, '_mcc_source_url', esc_url_raw( $url ) );
	update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	return (int) $id;
}

// An item's values for the fields the map names, each made fit for its field (a date as a date, an image copied).
function mcc_source_values( $item, array $map, array $fields ): array {
	$values = array();
	$alts   = array();
	foreach ( $map as [ $field, $alt, $path, $text ] ) {
		$value = null !== $text ? $text : mcc_source_path( $item, $path );
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value ); // a whole object where a value was meant: shown, not lost
		}
		if ( $alt ) {
			$alts[ $field ] = (string) $value;
		} elseif ( isset( $fields[ $field ] ) || in_array( $field, array( 'title', 'content', 'excerpt' ), true ) ) {
			$values[ $field ] = $value;
		}
	}
	foreach ( $values as $field => $value ) {
		$type = $fields[ $field ]['type'] ?? '';
		if ( 'date' === $type && $value && false !== strtotime( (string) $value ) ) {
			$values[ $field ] = wp_date( 'Y-m-d', strtotime( (string) $value ) );
		} elseif ( 'image' === $type && is_string( $value ) && ! is_numeric( $value ) ) {
			$values[ $field ] = mcc_source_image( $value, $alts[ $field ] ?? '' );
		}
	}
	return $values;
}

// Whether two items are the same, by the match fields (none: never, so every item is added).
function mcc_source_same( array $a, array $b, array $match ): bool {
	foreach ( $match as $field ) {
		if ( ! isset( $a[ $field ], $b[ $field ] ) || (string) $a[ $field ] !== (string) $b[ $field ] ) {
			return false;
		}
	}
	return (bool) $match;
}

/**
 * Pulls a source now: [ ok, message, added, updated ], and the result in its log (never the answer or a key).
 */
function mcc_source_pull( string $key ): array {
	$s    = mcc_sources()[ $key ] ?? null;
	$done = function ( bool $ok, string $message, int $added = 0, int $updated = 0 ) use ( $key ) {
		$log         = (array) get_option( MCC_SOURCES_LOG, array() );
		$log[ $key ] = array( 'time' => time(), 'ok' => $ok, 'message' => $message, 'added' => $added, 'updated' => $updated );
		update_option( MCC_SOURCES_LOG, $log, false );
		return array( 'ok' => $ok, 'message' => $message, 'added' => $added, 'updated' => $updated );
	};
	if ( ! $s || ! $s['url'] || ! $s['into'] ) {
		return $done( false, __( 'The source needs an https address and a place for its items.', 'marccent-content' ) );
	}

	$headers = array( 'Accept' => 'application/json' );
	foreach ( explode( "\n", $s['headers'] ) as $line ) {
		if ( str_contains( $line, ':' ) ) {
			[ $name, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$headers[ $name ] = mcc_source_keys( $value );
		}
	}
	$response = wp_safe_remote_request( mcc_source_keys( $s['url'] ), array(
		'method'              => $s['method'],
		'headers'             => $headers,
		'body'                => 'POST' === $s['method'] ? mcc_source_keys( $s['body'] ) : null,
		'timeout'             => 15,
		'limit_response_size' => 2 * MB_IN_BYTES,
		'redirection'         => 2,
	) );
	if ( is_wp_error( $response ) ) {
		return $done( false, sprintf( /* translators: %s: the error */ __( 'The request failed: %s', 'marccent-content' ), $response->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 ) {
		$why = is_array( $data ) ? (string) ( $data['error']['message'] ?? $data['error_description'] ?? $data['message'] ?? '' ) : '';
		return $done( false, sprintf( /* translators: 1: an HTTP status, 2: the API's message */ __( 'The API answered %1$d. %2$s', 'marccent-content' ), $code, wp_strip_all_tags( mcc_source_unkey( $why ) ) ) );
	}
	if ( ! is_array( $data ) ) {
		return $done( false, __( 'The answer isn’t JSON.', 'marccent-content' ) );
	}

	// Values from the whole answer, into a site group's fields: google.rating = rating.
	foreach ( mcc_source_map( $s['also'] ) as [ $target, , $path, $text ] ) {
		[ $group, $field ] = array_pad( explode( '.', $target, 2 ), 2, '' );
		$g                 = mcc_groups()[ $group ] ?? null;
		if ( $g && ! $g['repeat'] && in_array( 'options', $g['on'], true ) && isset( $g['fields'][ $field ] ) ) {
			$values           = (array) get_option( "mcc_$group", array() );
			$values[ $field ] = null !== $text ? $text : mcc_source_path( $data, $path );
			update_option( "mcc_$group", mcc_group_sanitize( $values, $g ) );
		}
	}

	$items = mcc_source_path( $data, $s['items'] );
	$items = is_array( $items ) ? ( array_is_list( $items ) ? $items : array( $items ) ) : array();
	$items = array_slice( $items, 0, $s['limit'] );
	$map   = mcc_source_map( $s['map'] );
	$match = array_filter( array_map( 'trim', explode( ',', $s['match'] ) ) );
	[ $kind, $where ] = explode( ':', $s['into'], 2 );
	$added   = 0;
	$updated = 0;

	if ( 'rows' === $kind ) {
		[ $group, $slug ] = array_pad( explode( '@', $where, 2 ), 2, '' );
		$g                = mcc_groups()[ $group ] ?? null;
		$page             = $slug ? get_page_by_path( $slug ) : null;
		if ( ! $g || ( $slug && ! $page ) ) {
			return $done( false, __( 'Its field group or page isn’t there any more.', 'marccent-content' ) );
		}
		$rows = $page ? (array) get_post_meta( $page->ID, $group, true ) : (array) get_option( "mcc_$group", array() );
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		foreach ( $items as $item ) {
			$values = mcc_source_values( $item, $map, $g['fields'] );
			foreach ( $rows as $i => $row ) {
				if ( mcc_source_same( $row, mcc_group_sanitize( array( $values ), $g )[0], $match ) ) {
					$rows[ $i ] = array_merge( $row, $values );
					++$updated;
					continue 2;
				}
			}
			$rows[] = $values;
			++$added;
		}
		$rows = mcc_group_sanitize( $rows, $g );
		$page ? update_post_meta( $page->ID, $group, wp_slash( $rows ) ) : update_option( "mcc_$group", $rows );
	} else {
		$fields = mcc_fields( $where );
		foreach ( $items as $item ) {
			$values = mcc_source_values( $item, $map, $fields );
			$title  = sanitize_text_field( (string) ( $values['title'] ?? '' ) );
			$found  = 0;
			if ( $match ) {
				$meta = array();
				foreach ( $match as $field ) {
					$meta[] = 'title' === $field ? null : array( 'key' => $field, 'value' => (string) ( $values[ $field ] ?? '' ) );
				}
				$found = (int) ( get_posts( array( 'post_type' => $where, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'title' => in_array( 'title', $match, true ) ? $title : '', 'meta_query' => array_filter( $meta ) ) )[0] ?? 0 ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			$post = array( 'post_type' => $where, 'post_title' => $title, 'post_content' => wp_kses_post( (string) ( $values['content'] ?? '' ) ), 'post_excerpt' => sanitize_textarea_field( (string) ( $values['excerpt'] ?? '' ) ) );
			$id   = $found ? wp_update_post( array( 'ID' => $found ) + $post, true ) : wp_insert_post( $post + array( 'post_status' => $s['status'] ), true );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			foreach ( $values as $field => $value ) {
				if ( isset( $fields[ $field ] ) ) {
					update_post_meta( $id, $field, wp_slash( mcc_sanitize( $value, $fields[ $field ] ) ) );
				}
			}
			$found ? ++$updated : ++$added;
		}
	}
	/* translators: 1: how many items came, 2: added, 3: updated */
	$message = sprintf( __( '%1$d items: %2$d added, %3$d updated.', 'marccent-content' ), count( $items ), $added, $updated );
	$images  = array_unique( $GLOBALS['mcc_source_image_errors'] ?? array() );
	if ( $images ) {
		/* translators: 1: how many images, 2: why */
		$message .= ' ' . sprintf( __( '%1$d images weren’t copied: %2$s', 'marccent-content' ), count( $GLOBALS['mcc_source_image_errors'] ), implode( '; ', $images ) );
	}
	return $done( true, $message, $added, $updated );
}

// An API's message, with any key that's in it blanked (some echo the request back).
function mcc_source_unkey( string $text ): string {
	if ( function_exists( 'marccent_keys' ) ) {
		foreach ( array_keys( marccent_keys() ) as $id ) {
			$key = marccent_key( $id );
			if ( strlen( $key ) > 6 ) {
				$text = str_replace( $key, '…', $text );
			}
		}
	}
	return $text;
}

/* The screen: Fields → Sources */

add_action( 'admin_menu', function () {
	add_submenu_page( 'mcc-groups', __( 'Sources', 'marccent-content' ), __( 'Sources', 'marccent-content' ), 'manage_options', 'mcc-sources', 'mcc_sources_page' );
}, 20 );

function mcc_sources_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=mcc-sources' ) );
}

function mcc_sources_page(): void {
	$action = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- which screen only
	$key    = sanitize_key( $_GET['source'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap">';
	if ( isset( $_GET['pulled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a notice
		$log = (array) ( get_option( MCC_SOURCES_LOG, array() )[ $key ] ?? array() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', ! empty( $log['ok'] ) ? 'success' : 'error', esc_html( (string) ( $log['message'] ?? '' ) ) );
	}
	if ( isset( $_GET['not_https'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a notice
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Saved without its address: it has to start with https://.', 'marccent-content' ) . '</p></div>';
	}
	if ( 'edit' === $action || 'new' === $action ) {
		mcc_source_form( $key );
		echo '</div>';
		return;
	}
	$sources = mcc_sources();
	$log     = (array) get_option( MCC_SOURCES_LOG, array() );
	$targets = mcc_source_targets();
	printf( '<h1 class="wp-heading-inline">%s</h1> <a class="page-title-action" href="%s">%s</a><hr class="wp-header-end">', esc_html__( 'Sources', 'marccent-content' ), esc_url( mcc_sources_url( array( 'action' => 'new' ) ) ), esc_html__( 'Add source', 'marccent-content' ) );
	echo '<p>' . esc_html__( 'Data from another site’s API, pulled into your fields when you press Pull now. Once pulled, it’s the site’s content: Loops and patterns show it.', 'marccent-content' ) . '</p>';
	if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Source saved.', 'marccent-content' ) . '</p></div>';
	}
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'marccent-content' ) . '</th><th>' . esc_html__( 'Into', 'marccent-content' ) . '</th><th>' . esc_html__( 'Last pull', 'marccent-content' ) . '</th><th></th></tr></thead><tbody>';
	if ( ! $sources ) {
		echo '<tr><td colspan="4">' . esc_html__( 'No sources yet.', 'marccent-content' ) . '</td></tr>';
	}
	foreach ( $sources as $k => $s ) {
		$l    = $log[ $k ] ?? null;
		$last = $l ? sprintf( '%s · %s', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $l['time'] ), $l['message'] ) : __( 'Never', 'marccent-content' );
		printf(
			'<tr><td><strong><a href="%s">%s</a></strong></td><td>%s</td><td%s>%s</td><td><form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="mcc_source_pull"><input type="hidden" name="source" value="%s"><button class="button button-primary">%s</button></form> <a class="button" href="%s">%s</a> <a class="button-link-delete" href="%s" onclick="return confirm(\'%s\')">%s</a></td></tr>',
			esc_url( mcc_sources_url( array( 'action' => 'edit', 'source' => $k ) ) ),
			esc_html( $s['label'] ?: $k ),
			esc_html( $targets[ $s['into'] ] ?? $s['into'] ),
			$l && ! $l['ok'] ? ' style="color:#b32d2e"' : '',
			esc_html( $last ),
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( "mcc_source_pull_$k", '_wpnonce', true, false ),
			esc_attr( $k ),
			esc_html__( 'Pull now', 'marccent-content' ),
			esc_url( mcc_sources_url( array( 'action' => 'edit', 'source' => $k ) ) ),
			esc_html__( 'Edit', 'marccent-content' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mcc_source_delete&source=' . $k ), "mcc_source_delete_$k" ) ),
			esc_js( __( 'Delete this source? What it pulled stays.', 'marccent-content' ) ),
			esc_html__( 'Delete', 'marccent-content' )
		);
	}
	echo '</tbody></table></div>';
}

function mcc_source_form( string $key ): void {
	$s       = mcc_sources()[ $key ] ?? array();
	$s      += array( 'label' => '', 'method' => 'GET', 'url' => '', 'headers' => '', 'body' => '', 'items' => '', 'into' => '', 'map' => '', 'also' => '', 'match' => '', 'limit' => 20, 'status' => 'draft' );
	$targets = mcc_source_targets();
	$row     = function ( $label, $control, $help = '' ) {
		printf( '<tr><th scope="row">%s</th><td>%s%s</td></tr>', esc_html( $label ), $control, $help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped below
	};
	$text = fn( $name, $value, $class = 'regular-text' ) => sprintf( '<input name="%s" value="%s" class="%s code" spellcheck="false">', esc_attr( $name ), esc_attr( (string) $value ), esc_attr( $class ) );
	$area = fn( $name, $value, $rows = 4 ) => sprintf( '<textarea name="%s" rows="%d" class="large-text code" spellcheck="false">%s</textarea>', esc_attr( $name ), $rows, esc_textarea( (string) $value ) );

	printf( '<h1>%s</h1>', $key ? esc_html__( 'Edit source', 'marccent-content' ) : esc_html__( 'Add source', 'marccent-content' ) );
	printf( '<p><a href="%s">← %s</a></p>', esc_url( mcc_sources_url() ), esc_html__( 'Sources', 'marccent-content' ) );
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'mcc_source_save' );
	echo '<input type="hidden" name="action" value="mcc_source_save"><input type="hidden" name="was" value="' . esc_attr( $key ) . '">';
	echo '<table class="form-table" role="presentation">';
	$row( __( 'Name', 'marccent-content' ), $text( 'source[label]', $s['label'] ) );
	$row( __( 'Request', 'marccent-content' ), sprintf( '<select name="source[method]"><option%s>GET</option><option%s>POST</option></select> %s', selected( $s['method'], 'GET', false ), selected( $s['method'], 'POST', false ), $text( 'source[url]', $s['url'], 'large-text' ) ), __( 'An https address. A key from Appearance → Marccent settings goes in as <code>{{ key:google_places }}</code>: it’s filled in only as the request goes out.', 'marccent-content' ) );
	$row( __( 'Headers', 'marccent-content' ), $area( 'source[headers]', $s['headers'], 3 ), __( 'One per line: <code>Name: value</code>. Keys as above.', 'marccent-content' ) );
	$row( __( 'Body (POST)', 'marccent-content' ), $area( 'source[body]', $s['body'], 3 ) );
	$row( __( 'Items at', 'marccent-content' ), $text( 'source[items]', $s['items'] ), __( 'Where the list is in the answer: <code>reviews</code>, <code>data.items</code>. Empty: the answer is the list (or one item).', 'marccent-content' ) );
	$options = '<option value="">' . esc_html__( 'Choose…', 'marccent-content' ) . '</option>';
	foreach ( $targets as $value => $label ) {
		$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $s['into'], $value, false ), esc_html( $label ) );
	}
	$row( __( 'Into', 'marccent-content' ), '<select name="source[into]">' . $options . '</select>', __( 'A repeating field group’s rows (the site’s, or a page’s), or posts of a type (a post each, with its own page).', 'marccent-content' ) );
	$row( __( 'Map', 'marccent-content' ), $area( 'source[map]', $s['map'], 7 ), __( 'A line per field: <code>field = path.in.the.item</code>, or <code>field = "text"</code>. An image field copies the image into the Media Library; give it alt text with <code>field:alt = path</code>. For posts: <code>title</code>, <code>content</code>, <code>excerpt</code> and the type’s fields.', 'marccent-content' ) );
	$row( __( 'Match by', 'marccent-content' ), $text( 'source[match]', $s['match'] ), __( 'The fields that make two items the same (comma-separated): those are updated, not added again. Rows or posts the source didn’t bring stay as they are.', 'marccent-content' ) );
	$row( __( 'Also save', 'marccent-content' ), $area( 'source[also]', $s['also'], 2 ), __( 'Values from the whole answer into a site group’s fields: <code>google.rating = rating</code>.', 'marccent-content' ) );
	$row( __( 'At most', 'marccent-content' ), sprintf( '<input type="number" name="source[limit]" value="%d" min="1" max="100" class="small-text"> %s', (int) $s['limit'], esc_html__( 'items a pull', 'marccent-content' ) ) );
	$row( __( 'New posts', 'marccent-content' ), sprintf( '<select name="source[status]"><option value="draft"%s>%s</option><option value="publish"%s>%s</option></select>', selected( $s['status'], 'draft', false ), esc_html__( 'Drafts, to look over', 'marccent-content' ), selected( $s['status'], 'publish', false ), esc_html__( 'Published', 'marccent-content' ) ), __( 'Only for posts.', 'marccent-content' ) );
	echo '</table>';
	submit_button( __( 'Save source', 'marccent-content' ) );
	echo '</form>';
}

add_action( 'admin_post_mcc_source_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You can’t change sources.', 'marccent-content' ), 403 );
	}
	check_admin_referer( 'mcc_source_save' );
	$raw = (array) wp_unslash( $_POST['source'] ?? array() );
	$in  = mcc_source_sanitize( $raw );
	$was = sanitize_key( $_POST['was'] ?? '' );
	$key = $was ?: ( sanitize_key( str_replace( ' ', '_', $in['label'] ) ) ?: 'source' );
	$all = mcc_sources();
	while ( ! $was && isset( $all[ $key ] ) ) {
		$key .= '_2';
	}
	$all[ $key ] = $in;
	update_option( MCC_SOURCES, $all, false );
	// An address that isn't https was left out: back to the form, saying so.
	if ( '' !== trim( (string) ( $raw['url'] ?? '' ) ) && '' === $in['url'] ) {
		wp_safe_redirect( mcc_sources_url( array( 'action' => 'edit', 'source' => $key, 'not_https' => 1 ) ) );
		exit;
	}
	wp_safe_redirect( mcc_sources_url( array( 'saved' => 1 ) ) );
	exit;
} );

add_action( 'admin_post_mcc_source_pull', function () {
	$key = sanitize_key( $_POST['source'] ?? '' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You can’t pull sources.', 'marccent-content' ), 403 );
	}
	check_admin_referer( "mcc_source_pull_$key" );
	mcc_source_pull( $key );
	wp_safe_redirect( mcc_sources_url( array( 'pulled' => 1, 'source' => $key ) ) );
	exit;
} );

add_action( 'admin_post_mcc_source_delete', function () {
	$key = sanitize_key( $_GET['source'] ?? '' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You can’t delete sources.', 'marccent-content' ), 403 );
	}
	check_admin_referer( "mcc_source_delete_$key" );
	$all = mcc_sources();
	unset( $all[ $key ] );
	update_option( MCC_SOURCES, $all, false );
	wp_safe_redirect( mcc_sources_url() );
	exit;
} );

// WP-CLI: wp marccent-content pull <source>, for a person at a terminal (still a manual pull).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'marccent-content pull', function ( $args ) {
		$result = mcc_source_pull( sanitize_key( $args[0] ?? '' ) );
		$result['ok'] ? WP_CLI::success( $result['message'] ) : WP_CLI::error( $result['message'] );
	} );
}

// Tools → Marccent export: the sources (their {{ key:… }} placeholders, never a key).
add_filter( 'marccent_export_parts', function ( $parts ) {
	$parts['sources'] = array(
		'label'  => __( 'Sources (Fields → Sources)', 'marccent-content' ),
		'export' => 'mcc_sources',
		'import' => fn( array $data ) => update_option( MCC_SOURCES, array_map( 'mcc_source_sanitize', array_filter( $data, 'is_array' ) ), false ),
	);
	return $parts;
} );
