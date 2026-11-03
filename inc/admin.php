<?php
/**
 * The admin side, everything kept in the database:
 * - Fields → Field groups: the groups in WordPress's list table (inc/list-table.php: search, views, sorting, bulk
 *   delete, screen options); adding or editing one opens its editor (assets/admin.js). Fields → Post types: the same
 *   by pointing and clicking, for administrators. Fields → JSON: the same spec as JSON, for whoever prefers to type it.
 * - Site content: the groups that are on the site (offices, opening hours…), for anyone who edits pages.
 * - Tools → Marccent export (Marccent Blocks): the spec and the site content, as JSON.
 * The screens save through the marccent-content/v1 REST routes below. Saving the spec flushes the rewrite rules on
 * the next load, so a new type's URLs work at once.
 */

defined( 'ABSPATH' ) || exit;

// Who edits the site content (the groups on the site): anyone who edits pages, unless a filter says otherwise.
function mcc_site_capability(): string {
	return (string) apply_filters( 'marccent_content_site_capability', 'edit_pages' );
}

function mcc_site_groups(): array {
	return array_filter( mcc_groups(), fn( $g ) => in_array( 'options', $g['on'], true ) );
}

function mcc_site_values(): array {
	return array_map( fn( $name ) => mcc_group_value( $name ), array_combine( array_keys( mcc_site_groups() ), array_keys( mcc_site_groups() ) ) ?: array() );
}

// Saves the site's groups, those given; each sanitized for its fields. A required field left empty saves nothing:
// a list of what's missing, else true.
function mcc_save_site_values( array $values, bool $check = true ) {
	$errors = array();
	foreach ( mcc_site_groups() as $name => $g ) {
		if ( $check && array_key_exists( $name, $values ) ) {
			$errors = array_merge( $errors, mcc_check_values( $g, mcc_group_sanitize( $values[ $name ], $g ) )['required'] );
		}
	}
	if ( $errors ) {
		return $errors;
	}
	foreach ( mcc_site_groups() as $name => $g ) {
		if ( array_key_exists( $name, $values ) ) {
			update_option( "mcc_$name", mcc_group_sanitize( $values[ $name ], $g ) );
		}
	}
	return true;
}

add_action( 'init', function () {
	// Read from the autoloaded options: when the flag isn't set (nearly always), get_option would ask the database.
	if ( ! empty( wp_load_alloptions()['mcc_flush_rewrites'] ) ) {
		delete_option( 'mcc_flush_rewrites' );
		flush_rewrite_rules( false );
	}
}, 99 );

/* REST: the spec (administrators) and the site content (page editors) */

add_action( 'rest_api_init', function () {
	register_rest_route( 'marccent-content/v1', '/spec', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'callback'            => fn() => array( 'spec' => mcc_spec_json(), 'postTypes' => mcc_admin_post_types(), 'taxonomies' => mcc_admin_taxonomies(), 'templates' => mcc_admin_templates() ),
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'callback'            => function ( WP_REST_Request $request ) {
				$spec  = $request->get_param( 'spec' );
				$saved = is_array( $spec ) ? mcc_save_spec( $spec ) : new WP_Error( 'mcc_spec', __( 'No spec given.', 'marccent-content' ), array( 'status' => 400 ) );
				return is_wp_error( $saved ) ? $saved : array( 'spec' => mcc_spec_json(), 'postTypes' => mcc_admin_post_types(), 'taxonomies' => mcc_admin_taxonomies(), 'templates' => mcc_admin_templates() );
			},
		),
	) );
	register_rest_route( 'marccent-content/v1', '/site', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => fn() => current_user_can( mcc_site_capability() ),
			'callback'            => fn() => array( 'groups' => (object) mcc_site_groups(), 'values' => (object) mcc_site_values() ),
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => current_user_can( mcc_site_capability() ),
			'callback'            => function ( WP_REST_Request $request ) {
				$saved = mcc_save_site_values( (array) $request->get_param( 'values' ) );
				if ( true !== $saved ) {
					return new WP_Error( 'mcc_invalid', implode( ' ', $saved ), array( 'status' => 400, 'errors' => $saved ) );
				}
				return array( 'groups' => (object) mcc_site_groups(), 'values' => (object) mcc_site_values() );
			},
		),
	) );
} );

// The spec for JSON: no types or groups is {}, not [].
function mcc_spec_json(): array {
	$spec = mcc_spec();
	foreach ( array( 'types', 'groups' ) as $key ) {
		$spec[ $key ] = (object) $spec[ $key ];
	}
	return $spec;
}

// The post types a group can go on: the public ones (pages, posts, the spec's own…), and the spec's not yet registered.
function mcc_admin_post_types(): array {
	$types = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		if ( 'attachment' !== $type->name ) {
			$types[ $type->name ] = $type->labels->name;
		}
	}
	foreach ( mcc_spec()['types'] as $name => $t ) {
		$types[ $name ] = $types[ $name ] ?? ( $t['label'] ?? $name );
	}
	return $types;
}

// The taxonomies a group can go on (their terms): the public ones, and the spec's not yet registered.
function mcc_admin_taxonomies(): array {
	$taxes = array();
	foreach ( get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $tax ) {
		if ( 'post_format' !== $tax->name ) {
			$taxes[ $tax->name ] = $tax->labels->name;
		}
	}
	foreach ( mcc_spec()['types'] as $t ) {
		foreach ( (array) ( $t['taxonomies'] ?? array() ) as $name => $x ) {
			$taxes[ $name ] = $taxes[ $name ] ?? ( $x['label'] ?? $name );
		}
	}
	return $taxes;
}

// The templates a page or post can have (the theme's), by slug: those a group can be limited to ("page@landing").
function mcc_admin_templates(): array {
	$out = array();
	foreach ( function_exists( 'get_block_templates' ) ? get_block_templates( array(), 'wp_template' ) : array() as $t ) {
		if ( ! empty( $t->is_custom ) || ! empty( $t->post_types ) ) {
			$out[ $t->slug ] = $t->title ?: $t->slug;
		}
	}
	foreach ( wp_get_theme()->get_page_templates() as $file => $title ) {
		$out[ $file ] = $out[ $file ] ?? $title;
	}
	return $out;
}

/* The screens */

add_action( 'admin_menu', function () {
	$app = function ( string $screen ) {
		return function () use ( $screen ) {
			printf( '<div class="wrap"><div id="mcc-admin" data-screen="%s"></div></div>', esc_attr( $screen ) );
		};
	};
	add_menu_page( __( 'Field groups', 'marccent-content' ), __( 'Fields', 'marccent-content' ), 'manage_options', 'mcc-groups', 'mcc_groups_page', 'dashicons-feedback', 81 );
	$hooks   = array();
	$hooks[] = add_submenu_page( 'mcc-groups', __( 'Field groups', 'marccent-content' ), __( 'Field groups', 'marccent-content' ), 'manage_options', 'mcc-groups', 'mcc_groups_page' );
	add_action( "load-{$hooks[0]}", 'mcc_groups_load' );
	$hooks[] = add_submenu_page( 'mcc-groups', __( 'Post types', 'marccent-content' ), __( 'Post types', 'marccent-content' ), 'manage_options', 'mcc-types', $app( 'types' ) );
	add_submenu_page( 'mcc-groups', __( 'Fields as JSON', 'marccent-content' ), __( 'JSON', 'marccent-content' ), 'manage_options', 'mcc-json', 'mcc_json_page' );
	if ( mcc_site_groups() ) {
		$hooks[] = add_menu_page( __( 'Site content', 'marccent-content' ), __( 'Site content', 'marccent-content' ), mcc_site_capability(), 'mcc-site', $app( 'site' ), 'dashicons-admin-site-alt3', 21 );
	}
	foreach ( $hooks as $hook ) {
		add_action( "admin_print_scripts-$hook", function () {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_script( 'marccent-content-admin' );
		} );
	}
} );

add_action( 'admin_init', function () {
	wp_register_script(
		'marccent-content-admin',
		plugins_url( 'assets/admin.js', MCC_FILE ),
		array( 'marccent-content-editor', 'wp-api-fetch', 'wp-components', 'wp-element' ),
		filemtime( MCC_DIR . '/assets/admin.js' ),
		true
	);
} );

// The old Settings → Content address goes to Fields → Field groups.
add_action( 'admin_init', function () {
	global $pagenow;
	if ( 'options-general.php' === $pagenow && 'marccent-content' === ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a redirect only
		wp_safe_redirect( admin_url( 'admin.php?page=mcc-groups' ) );
		exit;
	}
} );

/* Fields → JSON */

add_action( 'admin_post_mcc_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'marccent-content' ) );
	}
	check_admin_referer( 'mcc_save' );
	$json = trim( wp_unslash( (string) ( $_POST['spec'] ?? '' ) ) );
	$spec = json_decode( $json ?: '{}', true );
	$back = admin_url( 'admin.php?page=mcc-json' );
	$done = is_array( $spec ) ? mcc_save_spec( $spec ) : new WP_Error( 'mcc_spec', __( 'That isn’t valid JSON, so nothing was saved. Your text is below to fix.', 'marccent-content' ) );
	if ( is_wp_error( $done ) ) {
		set_transient( 'mcc_draft', array( 'json' => $json, 'error' => $done->get_error_message() ), 300 );
		wp_safe_redirect( add_query_arg( 'mcc', 'invalid', $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'mcc', 'saved', $back ) );
	exit;
} );

function mcc_json_page(): void {
	$status = sanitize_key( $_GET['mcc'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- a notice only
	$draft  = get_transient( 'mcc_draft' );
	delete_transient( 'mcc_draft' );
	$json = $draft['json'] ?? wp_json_encode( mcc_spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fields as JSON', 'marccent-content' ); ?></h1>
		<?php if ( 'saved' === $status ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Saved.', 'marccent-content' ); ?></p></div>
		<?php elseif ( $draft ) : ?>
			<div class="notice notice-error"><p><?php echo nl2br( esc_html( $draft['error'] ) ); ?></p><p><?php esc_html_e( 'Nothing was saved; your text is below to fix.', 'marccent-content' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'The post types and field groups that Fields → Field groups and Post types build, as JSON. Saving checks it first.', 'marccent-content' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mcc_save">
			<?php wp_nonce_field( 'mcc_save' ); ?>
			<textarea name="spec" rows="30" class="large-text code" spellcheck="false"><?php echo esc_textarea( $json ); ?></textarea>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* Tools → Marccent export: the field groups and post types, then the site content (after them, so its groups exist) */

add_filter( 'marccent_export_parts', function ( $parts ) {
	$parts['content']      = array(
		'label'  => __( 'Field groups and post types (Fields)', 'marccent-content' ),
		'export' => 'mcc_spec_json',
		'import' => 'mcc_save_spec',
	);
	$parts['site_content'] = array(
		'label'  => __( 'Site content (the groups on the site)', 'marccent-content' ),
		'export' => 'mcc_site_values',
		'import' => function ( array $data ) {
			mcc_save_site_values( $data, false ); // as it was exported, even with a field required since
		},
	);
	return $parts;
} );

/*
 * Field groups on terms ("term:<taxonomy>") and users ("user"): their fields on the term's and the user's screens,
 * drawn by the same controls as beside a post (assets/admin.js, data-mcc-object) into a hidden field, and saved with
 * the term or the user: a repeating group's rows under its name, the others' fields each under its own (term or user
 * meta). On the term's archive or the author's page, {{ group.field }} and a Loop of the group's rows read them.
 */
function mcc_object_groups( string $kind, string $taxonomy = '' ): array {
	return array_filter( mcc_groups(), fn( $g ) => 'user' === $kind ? in_array( 'user', $g['on'], true ) : in_array( $taxonomy, mcc_group_taxonomies( $g ), true ) );
}

function mcc_object_fields( array $groups, string $kind, int $id, bool $table ): void {
	if ( ! $groups ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_script( 'marccent-content-admin' );
	$values = array();
	foreach ( array_keys( $groups ) as $name ) {
		$values[ $name ] = $id ? mcc_object_group_value( $name, $kind, $id ) : ( $groups[ $name ]['repeat'] ? array() : (object) array() );
	}
	wp_nonce_field( 'mcc_object', '_mcc_object_nonce' );
	$box = sprintf(
		'<input type="hidden" name="mcc_object" value="%1$s"><div data-mcc-object data-groups="%2$s" data-values="%1$s"></div>',
		esc_attr( wp_json_encode( (object) $values ) ),
		esc_attr( wp_json_encode( (object) $groups ) )
	);
	if ( $table ) {
		printf( '<tr class="form-field"><th scope="row">%s</th><td style="max-width:40rem">%s</td></tr>', esc_html__( 'Fields', 'marccent-content' ), $box ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	} else {
		echo '<div class="form-field" style="max-width:40rem">' . $box . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}
}

function mcc_object_save( array $groups, string $kind, int $id ): void {
	if ( ! $groups || ! isset( $_POST['mcc_object'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mcc_object_nonce'] ?? '' ), 'mcc_object' ) ) {
		return;
	}
	$values = json_decode( wp_unslash( (string) $_POST['mcc_object'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each group sanitized for its fields
	foreach ( $groups as $name => $g ) {
		if ( is_array( $values ) && array_key_exists( $name, $values ) ) {
			mcc_save_object_group( $name, $kind, $id, $values[ $name ] );
		}
	}
}

add_action( 'admin_init', function () {
	foreach ( mcc_admin_taxonomies() as $tax => $label ) {
		add_action( "{$tax}_add_form_fields", fn() => mcc_object_fields( mcc_object_groups( 'term', $tax ), 'term', 0, false ) );
		add_action( "{$tax}_edit_form_fields", fn( $term ) => mcc_object_fields( mcc_object_groups( 'term', $tax ), 'term', $term->term_id, true ) );
		$save = function ( $term_id ) use ( $tax ) {
			if ( current_user_can( 'edit_term', $term_id ) ) {
				mcc_object_save( mcc_object_groups( 'term', $tax ), 'term', (int) $term_id );
			}
		};
		add_action( "created_$tax", $save );
		add_action( "edited_$tax", $save );
	}
	$user = function ( WP_User $u ) {
		if ( mcc_object_groups( 'user' ) ) {
			echo '<h2>' . esc_html__( 'Fields', 'marccent-content' ) . '</h2><table class="form-table" role="presentation">';
			mcc_object_fields( mcc_object_groups( 'user' ), 'user', $u->ID, true );
			echo '</table>';
		}
	};
	add_action( 'show_user_profile', $user );
	add_action( 'edit_user_profile', $user );
	$save = function ( $user_id ) {
		if ( current_user_can( 'edit_user', $user_id ) ) {
			mcc_object_save( mcc_object_groups( 'user' ), 'user', (int) $user_id );
		}
	};
	add_action( 'personal_options_update', $save );
	add_action( 'edit_user_profile_update', $save );
} );
