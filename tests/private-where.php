<?php
/**
 * A Loop's where reads a private field as empty, the way the show-if does: a where on it can't tell what the value is.
 *
 * Run it on the test site (wp.localhost, the wp-test container), where the plugin is active:
 *   docker exec wp-test php /var/www/html/wp-content/plugins/marccent-content/tests/private-where.php
 * Exits 0 when every check passes, 1 when one fails.
 */

require dirname( __DIR__, 4 ) . '/wp-load.php';

$failed = 0;
function mcc_check( string $label, bool $ok ): void {
	global $failed;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $ok ) {
		++$failed;
	}
}

// Two posts of the core post type: revenue is private, stage is public (the control).
$GLOBALS['mcc_cache']['fields'] = array(
	'post' => array(
		'revenue' => array( 'type' => 'number', 'private' => true ),
		'stage'   => array( 'type' => 'text' ),
	),
);
$ids = array();
foreach ( array( 2000000, 500000 ) as $revenue ) {
	$ids[] = wp_insert_post(
		array(
			'post_title'  => "Private where test $revenue",
			'post_status' => 'publish',
			'post_type'   => 'post',
		)
	);
	update_post_meta( end( $ids ), 'revenue', $revenue );
	update_post_meta( end( $ids ), 'stage', 'marccent-where-test' );
}

// A Loop over the posts with a where, as the block builds it.
$loop = function ( string $where ) {
	$query = mcc_loop_query(
		array(
			'postType'       => 'post',
			'where'          => $where,
			'relation'       => '',
			'perPage'        => 50,
			'offset'         => 0,
			'order'          => 'asc',
			'orderBy'        => 'date',
			'excludeCurrent' => false,
			'terms'          => '',
			'taxonomy'       => '',
			'parent'         => '',
		)
	);
	// Only this test's posts: the site's own posts are not part of the check.
	return array_values( array_intersect( wp_list_pluck( $query->posts, 'ID' ), $GLOBALS['mcc_test_ids'] ) );
};
$GLOBALS['mcc_test_ids'] = $ids;

// The control: a public field's where still filters the posts.
mcc_check( 'control: stage=marccent-where-test lists both posts', count( $loop( 'stage=marccent-where-test' ) ) === 2 );

// The leak: the private revenue's where must not list the post above the threshold, nor any other.
mcc_check( 'private: revenue>1000000 lists neither post', $loop( 'revenue>1000000' ) === array() );
mcc_check( 'private: revenue<1000000 lists neither post', $loop( 'revenue<1000000' ) === array() );
mcc_check( 'private: revenue=500000 lists neither post', $loop( 'revenue=500000' ) === array() );

// A where on a private value that passes for an empty value (not equal to anything) lists every post, as show-if does.
mcc_check( 'private: revenue!=500000 lists both posts (an empty value is not 500000)', count( $loop( 'revenue!=500000' ) ) === 2 );

// Repeating group rows: the same rule on a row's private field.
$group_fields = array(
	'amount' => array( 'type' => 'number', 'private' => true ),
	'label'  => array( 'type' => 'text' ),
);
$row         = array( 'amount' => '500', 'label' => 'Tier' );
mcc_check( 'row: a private amount>100 fails the row', ! mcc_where_row( $row, array( array( 'amount', '>', '100' ) ), mcc_private_names( $group_fields ) ) );
mcc_check( 'row: a public label=Tier still passes the row', mcc_where_row( $row, array( array( 'label', '=', 'Tier' ) ), mcc_private_names( $group_fields ) ) );
mcc_check( 'row: without the private names the amount passes (the control)', mcc_where_row( $row, array( array( 'amount', '>', '100' ) ) ) );

// The visitor's search over a row: a private value is never searched, so typing it finds nothing.
mcc_check( 'search: a private amount (500) is not found', ! mcc_row_matches( $row, '500', mcc_private_names( $group_fields ) ) );
mcc_check( 'search: a public label (tier) is found', mcc_row_matches( $row, 'tier', mcc_private_names( $group_fields ) ) );
mcc_check( 'search: without the private names the amount is found (the control)', mcc_row_matches( $row, '500' ) );

// Clean up what the test made.
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
unset( $GLOBALS['mcc_cache']['fields'] );

echo $failed ? "\n$failed check(s) failed\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
