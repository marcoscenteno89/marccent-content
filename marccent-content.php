<?php
/**
 * Plugin Name: Marccent Content
 * Description: Custom post types, their fields and loops for Marccent sites. Define the types and fields once (Settings → Content), edit the fields beside each post, and design a loop of them in the editor with the Marccent blocks: a Loop block repeats its item for every post, and the item shows the post's fields through the Field block, {{ tokens }} in any block, or block bindings.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Marcos Centeno
 * License: GPL-2.0-or-later
 * Text Domain: marccent-content
 */

defined( 'ABSPATH' ) || exit;

const MCC_DIR  = __DIR__;
const MCC_FILE = __FILE__;

require MCC_DIR . '/inc/types.php';    // the spec: post types, taxonomies and fields, registered
require MCC_DIR . '/inc/values.php';   // a post's value by key: title, link, image, terms, a field…
require MCC_DIR . '/inc/bindings.php'; // the values in blocks: the marccent/field binding source and {{ tokens }}
require MCC_DIR . '/inc/blocks.php';   // the Loop and Field blocks, and the editor's fields panel
require MCC_DIR . '/inc/settings.php'; // Settings → Content

register_activation_hook( __FILE__, function () {
	mcc_register_types();
	flush_rewrite_rules( false );
} );
