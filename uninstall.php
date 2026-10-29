<?php
/**
 * Deleting the plugin: its settings (the content types' spec) go, on every site of a network. The posts of those
 * types, their terms and their fields stay: they're the site's content, and they come back as they were if the plugin
 * is installed again with the same spec.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mcc_uninstall = function () {
	delete_option( 'marccent_content' );
	delete_option( 'mcc_flush_rewrites' );
	delete_transient( 'mcc_draft' );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mcc_site ) {
		switch_to_blog( $mcc_site );
		$mcc_uninstall();
		restore_current_blog();
	}
} else {
	$mcc_uninstall();
}
