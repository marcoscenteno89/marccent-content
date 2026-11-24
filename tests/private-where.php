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

// The Loop's posts for a where (and a visitor's tests, search and paging), only those of $ids: the site's own posts
// are not part of the check.
function mcc_test_list( string $where, array $ids, array $visitor = array() ): array {
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
		),
		0,
		$visitor
	);
	return array_values( array_intersect( wp_list_pluck( $query->posts, 'ID' ), $ids ) );
}

// The fields of the core post type: revenue and vip are private, stage, featured, products and price are public, and
// sponsors is a private many-to-many.
$GLOBALS['mcc_cache']['fields'] = array(
	'post' => array(
		'revenue'  => array( 'type' => 'number', 'private' => true ),
		'stage'    => array( 'type' => 'text' ),
		'vip'      => array( 'type' => 'boolean', 'private' => true ),
		'featured' => array( 'type' => 'boolean' ),
		'price'    => array( 'type' => 'number' ),
		'products' => array( 'type' => 'relation', 'to' => 'post', 'multiple' => true ),
		'sponsors' => array( 'type' => 'relation', 'to' => 'post', 'multiple' => true, 'private' => true ),
	),
);

// Two posts of the core post type: revenue is private, stage is public (the control).
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
$GLOBALS['mcc_test_ids'] = $ids;

// A Loop over the posts with a where, as the block builds it.
$loop = fn( string $where ) => mcc_test_list( $where, $GLOBALS['mcc_test_ids'] );

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

// --- Relations and yes / no fields -------------------------------------------------------------------------------
// Product posts (price, and vip on one of them), and two relation posts: A has products P1 and P2 and sponsor B;
// B has product P2 and no sponsor. A is featured and vip; B is neither.
$product_one = wp_insert_post( array( 'post_title' => 'Relation test product one', 'post_status' => 'publish', 'post_type' => 'post' ) );
$product_two = wp_insert_post( array( 'post_title' => 'Relation test product two', 'post_status' => 'publish', 'post_type' => 'post' ) );
update_post_meta( $product_one, 'price', 10 );
update_post_meta( $product_one, 'vip', '1' );
update_post_meta( $product_two, 'price', 20 );
$post_a = wp_insert_post( array( 'post_title' => 'Relation test A', 'post_status' => 'publish', 'post_type' => 'post' ) );
$post_b = wp_insert_post( array( 'post_title' => 'Relation test B', 'post_status' => 'publish', 'post_type' => 'post' ) );
foreach ( array( $post_a, $post_b ) as $id ) {
	update_post_meta( $id, 'stage', 'marccent-relation-test' );
}
update_post_meta( $post_a, 'revenue', 2000000 );
update_post_meta( $post_b, 'revenue', 500000 );
update_post_meta( $post_a, 'featured', '1' );
update_post_meta( $post_a, 'vip', '1' );
add_post_meta( $post_a, 'products', $product_one );
add_post_meta( $post_a, 'products', $product_two );
add_post_meta( $post_a, 'sponsors', $post_b );
add_post_meta( $post_b, 'products', $product_two );

$relation_ids = array( $post_a, $post_b );
$relation     = fn( string $where, array $visitor = array() ) => mcc_test_list( $where, $relation_ids, $visitor );

// Yes / no, public: a checked box is saved as 1, an unchecked one as nothing.
mcc_check( 'yes/no public: featured=yes lists only the featured post', $relation( 'featured=yes' ) === array( $post_a ) );
mcc_check( 'yes/no public: featured=no lists only the other post', $relation( 'featured=no' ) === array( $post_b ) );

// Yes / no, private: the value reads as empty, so yes lists nothing (the stored 1 doesn't leak) and no lists both.
mcc_check( 'yes/no private: vip=yes lists neither post (A is vip, but it reads as empty)', $relation( 'vip=yes' ) === array() );
mcc_check( 'yes/no private: vip=no lists both posts (an empty value is no)', $relation( 'vip=no' ) === $relation_ids );
mcc_check( 'yes/no private: vip!=yes lists both posts', $relation( 'vip!=yes' ) === $relation_ids );

// Many-to-many, public: != leaves out the posts that have the value (the $exclude route), ~ and = find a row.
mcc_check( 'many public: products!=P1 leaves out A (it has P1), keeps B', $relation( 'products!=' . $product_one ) === array( $post_b ) );
mcc_check( 'many public: products!=P2 leaves out both (each has P2)', $relation( 'products!=' . $product_two ) === array() );
mcc_check( 'many public: products=P1 lists only A', $relation( 'products=' . $product_one ) === array( $post_a ) );
mcc_check( 'many public: products~P2 lists both posts', $relation( 'products~' . $product_two ) === $relation_ids );
mcc_check( 'many public: products~P1 lists only A', $relation( 'products~' . $product_one ) === array( $post_a ) );

// Many-to-many, private: the value reads as empty, so a link never matches, is never excluded, and != keeps all.
mcc_check( 'many private: sponsors=B lists neither post (A has B, but it reads as empty)', $relation( 'sponsors=' . $post_b ) === array() );
mcc_check( 'many private: sponsors~B lists neither post', $relation( 'sponsors~' . $post_b ) === array() );
mcc_check( 'many private: sponsors!=B lists both posts (nothing is excluded)', $relation( 'sponsors!=' . $post_b ) === $relation_ids );

// The visitor's URL filter on a private field must not match or leak, and the visitor's != on a public link works.
mcc_check( 'visitor: revenue>1000000 in the URL lists neither post', $relation( '', array( 'tests' => mcc_where( 'revenue>1000000' ) ) ) === array() );
mcc_check( 'visitor: vip=yes in the URL lists neither post', $relation( '', array( 'tests' => mcc_where( 'vip=yes' ) ) ) === array() );
mcc_check( 'visitor: stage=marccent-relation-test in the URL lists both posts (the control)', $relation( '', array( 'tests' => mcc_where( 'stage=marccent-relation-test' ) ) ) === $relation_ids );
mcc_check( 'visitor: products!=P1 in the URL lists only B (the $exclude route)', $relation( '', array( 'tests' => mcc_where( 'products!=' . $product_one ) ) ) === array( $post_b ) );

// mcc_set: count and sum over a post's related products, with a private where (the set's posts read the private
// field as empty) and with the public controls.
mcc_check( 'set: count:this.products is 2 (the control)', mcc_aggregate( $post_a, 'count', 'this.products' ) === '2' );
mcc_check( 'set: count:this.products?revenue>1000000 is 0', mcc_aggregate( $post_a, 'count', 'this.products?revenue>1000000' ) === '0' );
mcc_check( 'set: count:this.products?vip=yes is 0 (product one is vip, but it reads as empty)', mcc_aggregate( $post_a, 'count', 'this.products?vip=yes' ) === '0' );
mcc_check( 'set: sum:this.products.price is 30 (the control)', mcc_aggregate( $post_a, 'sum', 'this.products.price' ) === '30' );
mcc_check( 'set: sum:this.products.price?vip=yes is 0', mcc_aggregate( $post_a, 'sum', 'this.products.price?vip=yes' ) === '0' );
mcc_check( 'set: sum:this.products.price?vip=no is 30 (every product reads as not vip)', mcc_aggregate( $post_a, 'sum', 'this.products.price?vip=no' ) === '30' );

// show-if: a private field reads as empty, so a block shows on no and not on yes or a threshold.
mcc_check( 'show-if: revenue>1000000 hides the block (private reads as empty)', ! mcc_show_if( 'revenue>1000000', $post_a ) );
mcc_check( 'show-if: vip=yes hides the block', ! mcc_show_if( 'vip=yes', $post_a ) );
mcc_check( 'show-if: vip=no shows the block', mcc_show_if( 'vip=no', $post_a ) );
mcc_check( 'show-if: featured=yes shows the block (the control)', mcc_show_if( 'featured=yes', $post_a ) );
mcc_check( 'show-if: featured=yes hides the block on B (the control)', ! mcc_show_if( 'featured=yes', $post_b ) );

// --- mcc_loop_rows, end to end -----------------------------------------------------------------------------------
// A repeating group on posts: A has two tiers (the first private amount 500, the second 9), the group is on posts.
$GLOBALS['mcc_cache']['groups'] = array(
	'tiers' => array(
		'label'  => 'Tiers',
		'repeat' => true,
		'on'     => array( 'post' ),
		'fields' => array(
			'amount' => array( 'label' => 'Amount', 'type' => 'number', 'private' => true ),
			'label'  => array( 'label' => 'Label', 'type' => 'text' ),
		),
	),
);
update_post_meta( $post_a, 'tiers', array( array( 'amount' => '500', 'label' => 'Tier' ), array( 'amount' => '9', 'label' => 'Small' ) ) );

// A Loop of rows from the current post (A), with the Loop's own where and paging, and the visitor's; each returns the
// rows and the total, which counts every row that matches, not the page.
$rows_for = function ( array $attributes, array $visitor = array() ) use ( $post_a, &$total ) {
	$total = null;
	$rows  = mcc_loop_rows(
		$attributes + array( 'source' => 'tiers', 'from' => '', 'where' => '', 'offset' => 0, 'perPage' => 10 ),
		$post_a,
		$visitor,
		$total
	);
	return array( array_values( array_column( $rows, 'label' ) ), $total );
};

[ $labels, $all_total ] = $rows_for( array() );
mcc_check( 'rows: no where lists both rows, total 2', $labels === array( 'Tier', 'Small' ) && 2 === $all_total );
[ $labels, $one_total ] = $rows_for( array( 'perPage' => 1 ) );
mcc_check( 'rows: perPage 1 lists the first row, total is still 2', $labels === array( 'Tier' ) && 2 === $one_total );
[ $labels, $offset_total ] = $rows_for( array( 'offset' => 1 ) );
mcc_check( 'rows: offset 1 lists the second row, total 1', $labels === array( 'Small' ) && 1 === $offset_total );
[ $labels, $label_total ] = $rows_for( array( 'where' => 'label=Tier' ) );
mcc_check( 'rows: label=Tier lists the first row, total 1', $labels === array( 'Tier' ) && 1 === $label_total );
[ $labels, $private_total ] = $rows_for( array( 'where' => 'amount>100' ) );
mcc_check( 'rows: a private amount>100 lists no row, total 0', $labels === array() && 0 === $private_total );
[ $labels, $visitor_total ] = $rows_for( array(), array( 'tests' => mcc_where( 'amount=500' ) ) );
mcc_check( 'rows: the visitor\'s private amount=500 lists no row, total 0', $labels === array() && 0 === $visitor_total );
[ $labels, $page_total ] = $rows_for( array( 'perPage' => 1 ), array( 'paged' => true, 'page' => 2, 'search' => '', 'tests' => array() ) );
mcc_check( 'rows: page 2 of perPage 1 lists the second row, total is still 2', $labels === array( 'Small' ) && 2 === $page_total );

// Clean up what the test made.
foreach ( array_merge( $ids, array( $product_one, $product_two, $post_a, $post_b ) ) as $id ) {
	wp_delete_post( $id, true );
}
unset( $GLOBALS['mcc_cache']['fields'], $GLOBALS['mcc_cache']['groups'] );

echo $failed ? "\n$failed check(s) failed\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
