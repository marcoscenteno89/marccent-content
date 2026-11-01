<?php
/**
 * Fields → Field groups: the groups in WordPress's own list table, as Posts lists posts: search, the All / Repeating /
 * On the site views, sorting, a checkbox per row and bulk delete, the Edit and Delete row actions, and Screen Options
 * (columns shown, groups per page). Adding or editing a group opens its editor (assets/admin.js, the "group" screen),
 * which comes back here when it saves.
 */

defined( 'ABSPATH' ) || exit;

// The list's address, with a notice or a filter on it.
function mcc_groups_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=mcc-groups' ) );
}

// Adding or editing a group: its editor, not the list.
function mcc_groups_editing(): bool {
	return in_array( $_GET['action'] ?? '', array( 'new', 'edit' ), true ); // phpcs:ignore WordPress.Security.NonceVerification -- which screen only
}

// Before the screen prints: deleting (a row's link or the bulk action), then the screen options and the table.
function mcc_groups_load(): void {
	if ( mcc_groups_editing() ) {
		return;
	}
	$table  = mcc_groups_table();
	$action = $table->current_action();
	if ( 'delete' === $action ) {
		$names = array_map( 'sanitize_key', (array) ( $_REQUEST['group'] ?? array() ) );
		check_admin_referer( 1 === count( $names ) && isset( $_GET['_wpnonce'] ) && ! isset( $_GET['action2'] ) ? 'mcc_delete_group_' . $names[0] : 'bulk-field-groups' );
		$spec = mcc_spec();
		$gone = array_values( array_intersect( $names, array_keys( $spec['groups'] ) ) );
		foreach ( $gone as $name ) {
			unset( $spec['groups'][ $name ] );
		}
		$saved = $gone ? mcc_save_spec( $spec ) : true;
		wp_safe_redirect( mcc_groups_url( is_wp_error( $saved ) ? array( 'mcc_error' => 1 ) : array( 'deleted' => count( $gone ) ) ) );
		exit;
	}
	if ( ! empty( $_GET['_wp_http_referer'] ) ) { // the search form's own fields, out of the address
		wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
		exit;
	}
	add_screen_option( 'per_page', array( 'default' => 20, 'option' => 'mcc_groups_per_page' ) );
}

add_filter( 'set_screen_option_mcc_groups_per_page', fn( $keep, $option, $value ) => max( 1, min( 999, (int) $value ) ), 10, 3 );

function mcc_groups_table(): MCC_Groups_Table {
	static $table = null;
	if ( null === $table ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new MCC_Groups_Table();
	}
	return $table;
}

function mcc_groups_page(): void {
	if ( mcc_groups_editing() ) {
		printf(
			'<div class="wrap"><div id="mcc-admin" data-screen="group" data-name="%s" data-list="%s"></div></div>',
			esc_attr( sanitize_key( $_GET['group'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification -- which group to open
			esc_url( mcc_groups_url() )
		);
		return;
	}
	$table = mcc_groups_table();
	$table->prepare_items();
	// phpcs:disable WordPress.Security.NonceVerification -- notices and the search term only
	$deleted = (int) ( $_GET['deleted'] ?? 0 );
	$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Field groups', 'marccent-content' ); ?></h1>
		<a href="<?php echo esc_url( mcc_groups_url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Field Group', 'marccent-content' ); ?></a>
		<?php if ( '' !== $search ) : ?>
			<span class="subtitle">
				<?php
				/* translators: %s: the search term */
				printf( esc_html__( 'Search results for: %s', 'marccent-content' ), '<strong>' . esc_html( $search ) . '</strong>' );
				?>
			</span>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Field group saved.', 'marccent-content' ); ?></p></div>
		<?php elseif ( $deleted ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				/* translators: %d: how many field groups */
				echo esc_html( sprintf( _n( '%d field group deleted. The values saved under it stay in the database.', '%d field groups deleted. The values saved under them stay in the database.', $deleted, 'marccent-content' ), $deleted ) );
				?>
			</p></div>
		<?php elseif ( isset( $_GET['mcc_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Nothing was deleted: the field groups left would not be valid.', 'marccent-content' ); ?></p></div>
		<?php endif; ?>
		<?php // phpcs:enable ?>
		<?php $table->views(); ?>
		<form method="get">
			<input type="hidden" name="page" value="mcc-groups">
			<?php $table->search_box( __( 'Search Field Groups', 'marccent-content' ), 'mcc-group' ); ?>
			<?php $table->display(); ?>
		</form>
	</div>
	<?php
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MCC_Groups_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array( 'singular' => 'field-group', 'plural' => 'field-groups', 'ajax' => false, 'screen' => get_current_screen() ) );
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'title'    => __( 'Field group', 'marccent-content' ),
			'key'      => __( 'Key', 'marccent-content' ),
			'location' => __( 'Location', 'marccent-content' ),
			'fields'   => __( 'Fields', 'marccent-content' ),
			'repeats'  => __( 'Repeats', 'marccent-content' ),
		);
	}

	protected function get_sortable_columns() {
		return array( 'title' => array( 'title', false ), 'key' => array( 'key', false ), 'fields' => array( 'fields', true ) );
	}

	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'marccent-content' ) );
	}

	// All / Repeating / On the site, with their counts.
	protected function get_views() {
		$groups  = mcc_groups();
		$current = sanitize_key( $_GET['view'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- a filter
		$views   = array(
			''       => array( __( 'All', 'marccent-content' ), count( $groups ) ),
			'repeat' => array( __( 'Repeating', 'marccent-content' ), count( array_filter( $groups, fn( $g ) => $g['repeat'] ) ) ),
			'site'   => array( __( 'On the site', 'marccent-content' ), count( mcc_site_groups() ) ),
		);
		$out = array();
		foreach ( $views as $view => [ $label, $count ] ) {
			if ( $view && ! $count ) {
				continue;
			}
			$out[ $view ?: 'all' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( mcc_groups_url( $view ? array( 'view' => $view ) : array() ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		}
		return $out;
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification -- the list's filters
		$view   = sanitize_key( $_GET['view'] ?? '' );
		$search = strtolower( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		$by     = sanitize_key( $_GET['orderby'] ?? '' );
		$desc   = 'desc' === strtolower( (string) ( $_GET['order'] ?? '' ) );
		// phpcs:enable

		$items = array();
		foreach ( mcc_groups() as $name => $g ) {
			if ( ( 'repeat' === $view && ! $g['repeat'] ) || ( 'site' === $view && ! in_array( 'options', $g['on'], true ) ) ) {
				continue;
			}
			// A search finds a group by its name, key or a field's.
			$haystack = strtolower( implode( ' ', array_merge( array( $g['label'], $name ), array_keys( $g['fields'] ), wp_list_pluck( $g['fields'], 'label' ) ) ) );
			if ( '' !== $search && ! str_contains( $haystack, $search ) ) {
				continue;
			}
			$items[] = array( 'name' => $name ) + $g;
		}
		if ( $by ) { // else the order they were added in
			$value = array( 'title' => fn( $i ) => strtolower( $i['label'] ), 'key' => fn( $i ) => $i['name'], 'fields' => fn( $i ) => count( $i['fields'] ) )[ $by ] ?? null;
			if ( $value ) {
				usort( $items, fn( $a, $b ) => ( $desc ? -1 : 1 ) * ( $value( $a ) <=> $value( $b ) ) );
			}
		}

		$per_page = $this->get_items_per_page( 'mcc_groups_per_page', 20 );
		$this->set_pagination_args( array( 'total_items' => count( $items ), 'per_page' => $per_page ) );
		$this->items           = array_slice( $items, ( $this->get_pagenum() - 1 ) * $per_page, $per_page );
		$this->_column_headers = $this->get_column_info();
	}

	public function no_items() {
		esc_html_e( 'No field groups found.', 'marccent-content' );
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<label class="label-covers-full-cell" for="cb-select-%1$s"><span class="screen-reader-text">%2$s</span></label><input id="cb-select-%1$s" type="checkbox" name="group[]" value="%1$s" />',
			esc_attr( $item['name'] ),
			/* translators: %s: a field group's name */
			esc_html( sprintf( __( 'Select %s', 'marccent-content' ), $item['label'] ) )
		);
	}

	protected function column_title( $item ) {
		$edit = mcc_groups_url( array( 'action' => 'edit', 'group' => $item['name'] ) );
		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $edit ), esc_html( $item['label'] ) );
	}

	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		$delete = wp_nonce_url( mcc_groups_url( array( 'action' => 'delete', 'group' => $item['name'] ) ), 'mcc_delete_group_' . $item['name'] );
		return $this->row_actions( array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( mcc_groups_url( array( 'action' => 'edit', 'group' => $item['name'] ) ) ), esc_html__( 'Edit', 'marccent-content' ) ),
			/* translators: %s: a field group's name */
			'delete' => sprintf( '<a href="%s" class="submitdelete" onclick="return confirm( this.dataset.confirm );" data-confirm="%s">%s</a>', esc_url( $delete ), esc_attr( sprintf( __( 'Delete “%s”? The values saved under it stay in the database, but nothing shows or edits them any more.', 'marccent-content' ), $item['label'] ) ), esc_html__( 'Delete', 'marccent-content' ) ),
		) );
	}

	protected function column_key( $item ) {
		return '<code>' . esc_html( $item['name'] ) . '</code>';
	}

	// Where it goes, in words: "Pages: gallery, Work orders, The site".
	protected function column_location( $item ) {
		$types = mcc_admin_post_types();
		$by    = array();
		foreach ( $item['on'] as $on ) {
			if ( 'options' === $on ) {
				continue;
			}
			[ $type, $slug ] = array_pad( explode( ':', $on, 2 ), 2, '' );
			$by[ $type ]     = array_merge( $by[ $type ] ?? array(), $slug ? array( $slug ) : array() );
		}
		$out = array();
		foreach ( $by as $type => $slugs ) {
			$out[] = esc_html( $types[ $type ] ?? $type ) . ( $slugs ? ': <code>' . esc_html( implode( ', ', $slugs ) ) . '</code>' : '' );
		}
		if ( in_array( 'options', $item['on'], true ) ) {
			$out[] = esc_html__( 'The site (Site content)', 'marccent-content' );
		}
		return $out ? implode( '<br>', $out ) : '&mdash;';
	}

	// The fields' names, a relation with the type it points to.
	protected function column_fields( $item ) {
		if ( ! $item['fields'] ) {
			return '&mdash;';
		}
		$types = mcc_admin_post_types();
		$names = array();
		foreach ( $item['fields'] as $f ) {
			$names[] = esc_html( $f['label'] ) . ( 'relation' === $f['type'] ? ' &rarr; ' . esc_html( $types[ $f['to'] ] ?? $f['to'] ) : '' );
		}
		return implode( ', ', $names );
	}

	protected function column_repeats( $item ) {
		return $item['repeat'] ? esc_html__( 'Yes', 'marccent-content' ) : '&mdash;';
	}
}
