<?php
/**
 * Settings → Content: the spec (inc/types.php) as JSON, checked before it's saved, with what it registers. Saving
 * flushes the rewrite rules on the next load, so a new type's URLs work at once.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( __( 'Content types', 'marccent-content' ), __( 'Content', 'marccent-content' ), 'manage_options', 'marccent-content', 'mcc_settings_page' );
} );

add_action( 'admin_post_mcc_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'marccent-content' ) );
	}
	check_admin_referer( 'mcc_save' );
	$json = trim( wp_unslash( (string) ( $_POST['spec'] ?? '' ) ) );
	$spec = json_decode( $json ?: '{}', true );
	$back = admin_url( 'options-general.php?page=marccent-content' );
	if ( ! is_array( $spec ) ) {
		set_transient( 'mcc_draft', $json, 300 );
		wp_safe_redirect( add_query_arg( 'mcc', 'invalid', $back ) );
		exit;
	}
	update_option( 'marccent_content', wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	update_option( 'mcc_flush_rewrites', 1 );
	wp_safe_redirect( add_query_arg( 'mcc', 'saved', $back ) );
	exit;
} );

add_action( 'init', function () {
	if ( get_option( 'mcc_flush_rewrites' ) ) {
		delete_option( 'mcc_flush_rewrites' );
		flush_rewrite_rules( false );
	}
}, 99 );

function mcc_settings_page(): void {
	$status = sanitize_key( $_GET['mcc'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- a notice only
	$json   = get_transient( 'mcc_draft' ) ?: (string) get_option( 'marccent_content', '' );
	delete_transient( 'mcc_draft' );
	if ( '' === $json ) {
		$json = wp_json_encode( array( 'currency' => '$', 'types' => (object) array(), 'fields' => (object) array() ), JSON_PRETTY_PRINT );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Content types', 'marccent-content' ); ?></h1>
		<?php if ( 'saved' === $status ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Saved.', 'marccent-content' ); ?></p></div>
		<?php elseif ( 'invalid' === $status ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'That isn’t valid JSON, so nothing was saved. Your text is below to fix.', 'marccent-content' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'The site’s post types, their taxonomies and fields, and fields on existing types (post, page). Field types: string, text, number, url, email, date, boolean, image, select (with "options"). A field can have "help", "prefix" and "suffix".', 'marccent-content' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mcc_save">
			<?php wp_nonce_field( 'mcc_save' ); ?>
			<textarea name="spec" rows="24" class="large-text code" spellcheck="false"><?php echo esc_textarea( $json ); ?></textarea>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Registered', 'marccent-content' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Type', 'marccent-content' ); ?></th><th><?php esc_html_e( 'Fields', 'marccent-content' ); ?></th><th><?php esc_html_e( 'Tokens', 'marccent-content' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( mcc_editor_data()['types'] as $type ) : ?>
				<?php
				$fields = array_keys( (array) $type['fields'] );
				$tokens = array_merge( array( 'title', 'excerpt', 'date', 'link', 'image', 'author' ), array_map( fn( $t ) => 'terms:' . $t['name'], $type['taxonomies'] ), $fields );
				?>
				<tr>
					<td><strong><?php echo esc_html( $type['label'] ); ?></strong> <code><?php echo esc_html( $type['name'] ); ?></code></td>
					<td><?php echo esc_html( $fields ? implode( ', ', $fields ) : '—' ); ?></td>
					<td><code><?php echo esc_html( implode( '  ', array_map( fn( $t ) => '{{ ' . $t . ' }}', $tokens ) ) ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
