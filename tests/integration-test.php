<?php
/**
 * Integration test against a real WP Migrate Pro install.
 *
 * Simulates the local side of a pull: copies tables into _mig_ temporary tables
 * (what WP Migrate does while receiving the remote data), then runs WP Migrate's
 * own FinalizeMigration, which fires our hooks and renames the tables over the
 * live ones. Run each step in its own process, see tests/run.sh.
 *
 * Usage: wp eval-file tests/integration-test.php <setup|finalize|assert|finalize-fail|assert-fail|not-run|push>
 */

use DeliciousBrains\WPMDB\Common\Migration\FinalizeMigration;
use DeliciousBrains\WPMDB\WPMDBDI;

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

const ELWP_TEST_TEMP_PREFIX = '_mig_';

define( 'ELWP_ANON_USER_LOGIN_WHITELIST', 'keepme' );

$step = $args[0] ?? '';

$elwp_failures = 0;

function elwp_test_check( $label, $condition, $detail = '' ) {
	global $elwp_failures;

	if ( $condition ) {
		WP_CLI::log( "PASS {$label}" );
		return;
	}

	$elwp_failures++;
	WP_CLI::log( "FAIL {$label}" . ( '' !== $detail ? " -> {$detail}" : '' ) );
}

function elwp_test_tables() {
	global $wpdb;

	return array_map(
		fn( $table ) => $wpdb->base_prefix . $table,
		array( 'users', 'usermeta', 'comments', 'postmeta', 'wc_orders', 'wc_order_addresses', 'woocommerce_sessions', 'gf_entry_meta', 'newsletter' )
	);
}

function elwp_test_state() {
	global $wpdb;

	return array(
		'intent'       => 'pull',
		'url'          => 'https://production.example',
		'location'     => 'https://production.example',
		'tables'       => implode( ',', elwp_test_tables() ),
		'temp_prefix'  => ELWP_TEST_TEMP_PREFIX,
		'stage'        => 'migrate',
		'form_data'    => '',
		'site_details' => array(
			'local'  => array( 'prefix' => $wpdb->base_prefix ),
			'remote' => array( 'prefix' => $wpdb->base_prefix ),
		),
	);
}

function elwp_test_finalize( $fail = false ) {
	if ( $fail ) {
		add_filter(
			'elwp_anon_rules',
			function ( $rules ) {
				$rules['users']['update']['user_email'] = 'broken-{no_such_column}';
				return $rules;
			}
		);
	}

	$state = elwp_test_state();
	update_site_option( 'wpmdb_migration_state', $state );

	// Same order as FinalizeMigration::ajax_finalize_migration().
	do_action( 'wpmdb_finalize_migration', $state );
	$result = WPMDBDI::getInstance()->get( FinalizeMigration::class )->finalize_migration( $state );

	WP_CLI::log( 'finalize_migration returned: ' . wp_json_encode( $result ) );
}

switch ( $step ) {
	case 'setup':
		global $wpdb;

		foreach ( array( 'jane', 'keepme' ) as $login ) {
			$existing = get_user_by( 'login', $login );
			if ( $existing ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $existing->ID );
			}
		}

		$jane = wp_insert_user(
			array(
				'user_login'   => 'jane',
				'user_email'   => 'jane@real-customer.com',
				'user_pass'    => 'jane-password',
				'role'         => 'editor',
				'first_name'   => 'Jane',
				'display_name' => 'Jane Real',
				'user_url'     => 'https://jane.example.com',
			)
		);
		update_user_meta( $jane, 'last_name', '' );
		update_user_meta( $jane, 'billing_first_name', 'Jane' );
		update_user_meta( $jane, 'billing_email', 'jane@real-customer.com' );
		update_user_meta( $jane, 'billing_address_1', '1 Real Street' );
		update_user_meta( $jane, 'session_tokens', array( 'abc' => array( 'expiration' => time() + 3600 ) ) );

		$keep = wp_insert_user(
			array(
				'user_login' => 'keepme',
				'user_email' => 'keep@real-customer.com',
				'user_pass'  => 'keep-password',
				'role'       => 'subscriber',
				'first_name' => 'Keep',
			)
		);

		update_user_meta( 1, 'first_name', 'Site' );
		update_user_meta( 1, 'session_tokens', array( 'def' => array( 'expiration' => time() + 3600 ) ) );

		$guest_comment = wp_insert_comment(
			array(
				'comment_post_ID'      => 1,
				'comment_author'       => 'Guest Person',
				'comment_author_email' => 'guest@real-customer.com',
				'comment_author_IP'    => '203.0.113.9',
				'comment_content'      => 'Hello',
			)
		);
		$admin_comment = wp_insert_comment(
			array(
				'comment_post_ID'      => 1,
				'user_id'              => 1,
				'comment_author'       => 'admin',
				'comment_author_email' => 'admin@example.org',
				'comment_content'      => 'Reply',
			)
		);

		$order = wp_insert_post(
			array(
				'post_type'   => 'shop_order',
				'post_status' => 'publish',
				'post_title'  => 'Order',
			)
		);
		update_post_meta( $order, '_billing_first_name', 'Jane' );
		update_post_meta( $order, '_billing_email', 'jane@real-customer.com' );
		update_post_meta( $order, '_billing_address_index', 'Jane 1 Real Street' );
		update_post_meta( $order, '_shipping_company', '' );

		$prefix = $wpdb->base_prefix;
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}wc_orders, {$prefix}wc_order_addresses, {$prefix}woocommerce_sessions, {$prefix}gf_entry_meta, {$prefix}newsletter" );
		$wpdb->query( "CREATE TABLE {$prefix}wc_orders (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, billing_email VARCHAR(320), ip_address VARCHAR(100), customer_note TEXT)" );
		$wpdb->query( "INSERT INTO {$prefix}wc_orders VALUES (500, 'jane@real-customer.com', '203.0.113.9', 'Leave at the door')" );
		$wpdb->query( "CREATE TABLE {$prefix}wc_order_addresses (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED, address_type VARCHAR(20), first_name TEXT, email VARCHAR(320), address_1 TEXT, country TEXT)" );
		$wpdb->query( "INSERT INTO {$prefix}wc_order_addresses (order_id, address_type, first_name, email, address_1, country) VALUES (500, 'billing', 'Jane', 'jane@real-customer.com', '1 Real Street', 'GB'), (500, 'shipping', 'Jane', NULL, '1 Real Street', 'GB')" );
		$wpdb->query( "CREATE TABLE {$prefix}woocommerce_sessions (session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_key CHAR(32), session_value LONGTEXT)" );
		$wpdb->query( "INSERT INTO {$prefix}woocommerce_sessions (session_key, session_value) VALUES ('k', 'a:1:{s:5:\"email\";s:3:\"x@y\";}')" );
		$wpdb->query( "CREATE TABLE {$prefix}gf_entry_meta (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, entry_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT)" );
		$wpdb->query( "CREATE TABLE {$prefix}newsletter (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(320), status VARCHAR(20))" );

		// What WP Migrate does on the local site while receiving a pull.
		foreach ( elwp_test_tables() as $table ) {
			$temp = ELWP_TEST_TEMP_PREFIX . $table;
			$wpdb->query( "DROP TABLE IF EXISTS `{$temp}`" );
			$wpdb->query( "CREATE TABLE `{$temp}` LIKE `{$table}`" );
			$wpdb->query( "INSERT INTO `{$temp}` SELECT * FROM `{$table}`" );
		}

		delete_site_option( 'elwp_anon_last_run' );
		update_option(
			'elwp_test_ids',
			array(
				'jane'          => $jane,
				'keep'          => $keep,
				'jane_pass'     => get_userdata( $jane )->user_pass,
				'admin_pass'    => get_userdata( 1 )->user_pass,
				'guest_comment' => $guest_comment,
				'admin_comment' => $admin_comment,
				'order'         => $order,
			)
		);

		WP_CLI::success( 'Test data and _mig_ tables created.' );
		break;

	case 'finalize':
		elwp_test_finalize();
		break;

	case 'finalize-fail':
		elwp_test_finalize( true );
		break;

	case 'assert':
		global $wpdb;

		$ids    = get_option( 'elwp_test_ids' );
		$prefix = $wpdb->base_prefix;
		$jane   = $ids['jane'];
		$order  = $ids['order'];
		$user   = fn( $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}users WHERE ID = %d", $id ) );
		// get_row, as get_var returns null for empty strings.
		$meta   = fn( $id, $key ) => $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM {$prefix}usermeta WHERE user_id = %d AND meta_key = %s", $id, $key ) )?->meta_value;

		$temp_left = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( ELWP_TEST_TEMP_PREFIX ) . '%' ) );
		elwp_test_check( 'temporary tables were renamed over live tables', ! array_intersect( $temp_left, array_map( fn( $t ) => ELWP_TEST_TEMP_PREFIX . $t, elwp_test_tables() ) ), implode( ',', $temp_left ) );

		$row = $user( $jane );
		elwp_test_check( 'users.user_login', "username_{$jane}" === $row->user_login, $row->user_login );
		elwp_test_check( 'users.user_nicename', "username_{$jane}" === $row->user_nicename, $row->user_nicename );
		elwp_test_check( 'users.user_email', "user-{$jane}@example.test" === $row->user_email, $row->user_email );
		elwp_test_check( 'users.display_name', "DisplayName-{$jane}" === $row->display_name, $row->display_name );
		elwp_test_check( 'users.user_url emptied', '' === $row->user_url, $row->user_url );
		elwp_test_check( 'users.user_pass replaced', $ids['jane_pass'] !== $row->user_pass );

		$admin = $user( 1 );
		elwp_test_check( 'administrator kept (role whitelist)', 'admin' === $admin->user_login && $ids['admin_pass'] === $admin->user_pass, $admin->user_login );
		elwp_test_check( 'administrator meta kept', 'Site' === $meta( 1, 'first_name' ) );
		elwp_test_check( 'administrator session_tokens kept', null !== $meta( 1, 'session_tokens' ) );

		$keep = $user( $ids['keep'] );
		elwp_test_check( 'login whitelist kept', 'keepme' === $keep->user_login && 'keep@real-customer.com' === $keep->user_email, $keep->user_email );
		elwp_test_check( 'login whitelist meta kept', 'Keep' === $meta( $ids['keep'], 'first_name' ) );

		elwp_test_check( 'usermeta.first_name', "FirstName-{$jane}" === $meta( $jane, 'first_name' ), (string) $meta( $jane, 'first_name' ) );
		elwp_test_check( 'usermeta empty last_name stays empty', '' === $meta( $jane, 'last_name' ), (string) $meta( $jane, 'last_name' ) );
		elwp_test_check( 'usermeta.nickname', "username_{$jane}" === $meta( $jane, 'nickname' ), (string) $meta( $jane, 'nickname' ) );
		elwp_test_check( 'usermeta.billing_first_name', "BillingFirstName{$jane}" === $meta( $jane, 'billing_first_name' ), (string) $meta( $jane, 'billing_first_name' ) );
		elwp_test_check( 'usermeta.billing_email', "user-{$jane}@example.test" === $meta( $jane, 'billing_email' ), (string) $meta( $jane, 'billing_email' ) );
		elwp_test_check( 'usermeta.billing_address_1', '123 Example St' === $meta( $jane, 'billing_address_1' ), (string) $meta( $jane, 'billing_address_1' ) );
		elwp_test_check( 'usermeta.session_tokens deleted', null === $meta( $jane, 'session_tokens' ) );

		$comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}comments WHERE comment_ID = %d", $ids['guest_comment'] ) );
		elwp_test_check( 'comments.comment_author', "Commenter-{$ids['guest_comment']}" === $comment->comment_author, $comment->comment_author );
		elwp_test_check( 'comments.comment_author_email', "comment-{$ids['guest_comment']}@example.test" === $comment->comment_author_email, $comment->comment_author_email );
		elwp_test_check( 'comments.comment_author_IP', '127.0.0.1' === $comment->comment_author_IP, $comment->comment_author_IP );
		$comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}comments WHERE comment_ID = %d", $ids['admin_comment'] ) );
		elwp_test_check( 'administrator comment kept', 'admin@example.org' === $comment->comment_author_email, $comment->comment_author_email );

		$post_meta = fn( $key ) => $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM {$prefix}postmeta WHERE post_id = %d AND meta_key = %s", $order, $key ) )?->meta_value;
		elwp_test_check( 'postmeta._billing_first_name', "OrderBillingFirstName{$order}" === $post_meta( '_billing_first_name' ), (string) $post_meta( '_billing_first_name' ) );
		elwp_test_check( 'postmeta._billing_email', "order-{$order}@example.test" === $post_meta( '_billing_email' ), (string) $post_meta( '_billing_email' ) );
		elwp_test_check( 'postmeta._billing_address_index emptied', '' === $post_meta( '_billing_address_index' ), (string) $post_meta( '_billing_address_index' ) );

		$wc_order = $wpdb->get_row( "SELECT * FROM {$prefix}wc_orders WHERE id = 500" );
		elwp_test_check( 'wc_orders.billing_email', 'order-500@example.test' === $wc_order->billing_email, $wc_order->billing_email );
		elwp_test_check( 'wc_orders.ip_address', '127.0.0.1' === $wc_order->ip_address, $wc_order->ip_address );
		elwp_test_check( 'wc_orders.customer_note emptied', '' === $wc_order->customer_note, $wc_order->customer_note );

		$addresses = $wpdb->get_results( "SELECT * FROM {$prefix}wc_order_addresses ORDER BY id" );
		elwp_test_check( 'wc_order_addresses.first_name', 'OrderFirstName500' === $addresses[0]->first_name, $addresses[0]->first_name );
		elwp_test_check( 'wc_order_addresses.email', 'order-500@example.test' === $addresses[0]->email, $addresses[0]->email );
		elwp_test_check( 'wc_order_addresses NULL email stays NULL', null === $addresses[1]->email, (string) $addresses[1]->email );
		elwp_test_check( 'wc_order_addresses.country untouched', 'GB' === $addresses[0]->country, $addresses[0]->country );

		elwp_test_check( 'woocommerce_sessions truncated', '0' === $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}woocommerce_sessions" ) );

		$last_run = get_site_option( 'elwp_anon_last_run' );
		$warnings = implode( ' | ', $last_run['warnings'] ?? array() );
		elwp_test_check( 'last run recorded as success', 'success' === ( $last_run['status'] ?? '' ), wp_json_encode( $last_run ) );
		elwp_test_check( 'warning for known form table', str_contains( $warnings, "{$prefix}gf_entry_meta" ), $warnings );
		elwp_test_check( 'warning for email column without rule', str_contains( $warnings, "{$prefix}newsletter" ) && str_contains( $warnings, 'email' ), $warnings );
		elwp_test_check( 'no warning for covered tables', ! str_contains( $warnings, 'wc_orders' ) && ! str_contains( $warnings, "{$prefix}users" ) && ! str_contains( $warnings, 'comments' ), $warnings );
		elwp_test_check( 'whitelisted count', 2 === ( $last_run['whitelisted'] ?? 0 ), (string) ( $last_run['whitelisted'] ?? '' ) );

		WP_CLI::log( 'Warnings: ' . $warnings );
		break;

	case 'assert-fail':
		global $wpdb;

		$ids    = get_option( 'elwp_test_ids' );
		$prefix = $wpdb->base_prefix;
		$email  = $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$prefix}users WHERE ID = %d", $ids['jane'] ) );
		$temp   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ELWP_TEST_TEMP_PREFIX . $prefix . 'users' ) );

		elwp_test_check( 'live users untouched after failure', 'jane@real-customer.com' === $email, (string) $email );
		elwp_test_check( 'temporary tables not renamed after failure', null !== $temp );

		$last_run = get_site_option( 'elwp_anon_last_run' );
		elwp_test_check( 'last run recorded as error', 'error' === ( $last_run['status'] ?? '' ) && str_contains( $last_run['error'], 'no_such_column' ), wp_json_encode( $last_run ) );
		break;

	case 'not-run':
		delete_site_option( 'elwp_anon_last_run' );

		// This site being the source of someone else's pull: must be ignored.
		do_action( 'wpmdb_migration_complete', 'pull', 'https://dev.example', '' );
		elwp_test_check( 'remote pull completion ignored', false === get_site_option( 'elwp_anon_last_run' ) );

		// A local pull that finished without our hooks running.
		do_action( 'wpmdb_migration_complete', 'pull', 'https://production.example' );
		$last_run = get_site_option( 'elwp_anon_last_run' );
		elwp_test_check( 'unanonymized pull flagged', 'not_run' === ( $last_run['status'] ?? '' ), wp_json_encode( $last_run ) );

		elwp_test_check( 'incoming pushes disabled', false === get_site_option( 'wpmdb_settings' )['allow_push'] );
		break;

	case 'push':
		$_POST = array( 'intent' => 'push' );
		apply_filters( 'wpmdb_initiate_key_rules', array(), 'ajax_initiate_migration' );
		WP_CLI::log( 'FAIL push was not blocked' );
		exit( 1 );

	default:
		WP_CLI::error( 'Unknown step.' );
}

if ( $elwp_failures ) {
	WP_CLI::error( "{$elwp_failures} check(s) failed." );
}
