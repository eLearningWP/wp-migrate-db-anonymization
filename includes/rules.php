<?php
/**
 * Default anonymization rules.
 *
 * Rules are keyed by table name without the database prefix (and without the
 * blog ID on multisite subsite tables). Each table can define:
 *
 * - 'user_id_column' Column holding the user ID. Rows belonging to whitelisted users are skipped.
 * - 'update'         Column => template. Every row gets the column set to the template.
 * - 'meta'           meta_key => template. Rows with that meta_key get meta_value set to the template.
 * - 'delete_meta'    List of meta_keys whose rows are deleted.
 * - 'truncate'       true to empty the table.
 *
 * Templates are plain strings where {column} is replaced by that column's value
 * in the same row, e.g. 'user-{ID}@example.test'.
 */

namespace ELWP\PullAnonymizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the anonymization rules.
 *
 * @return array
 */
function get_rules() {
	$rules = merge_rules(
		wordpress_rules(),
		woocommerce_rules()
	);

	/**
	 * Filter the anonymization rules.
	 *
	 * @param array $rules Rules keyed by unprefixed table name.
	 */
	return apply_filters( 'elwp_anon_rules', $rules );
}

/**
 * Merge rule sets, combining the rules of tables present in more than one set.
 *
 * @param array ...$rule_sets
 *
 * @return array
 */
function merge_rules( ...$rule_sets ) {
	$merged = array();

	foreach ( $rule_sets as $rule_set ) {
		foreach ( $rule_set as $table => $rule ) {
			if ( ! isset( $merged[ $table ] ) ) {
				$merged[ $table ] = $rule;
				continue;
			}

			foreach ( $rule as $key => $value ) {
				if ( is_array( $value ) ) {
					$merged[ $table ][ $key ] = array_merge( $merged[ $table ][ $key ] ?? array(), $value );
				} else {
					$merged[ $table ][ $key ] = $value;
				}
			}
		}
	}

	return $merged;
}

/**
 * WordPress core rules.
 *
 * @return array
 */
function wordpress_rules() {
	return array(
		'users'    => array(
			'user_id_column' => 'ID',
			'update'         => array(
				'user_login'          => 'username_{ID}',
				'user_nicename'       => 'username_{ID}',
				'user_email'          => 'user-{ID}@example.test',
				'display_name'        => 'DisplayName-{ID}',
				'user_url'            => '',
				'user_activation_key' => '',
				'user_pass'           => get_password_hash(),
			),
		),
		'usermeta' => array(
			'user_id_column' => 'user_id',
			'meta'           => array(
				'first_name'  => 'FirstName-{user_id}',
				'last_name'   => 'LastName-{user_id}',
				'nickname'    => 'username_{user_id}',
				'description' => '',
			),
			'delete_meta'    => array(
				'session_tokens',
				'community-events-location',
			),
		),
		'comments' => array(
			'user_id_column' => 'user_id',
			'update'         => array(
				'comment_author'       => 'Commenter-{comment_ID}',
				'comment_author_email' => 'comment-{comment_ID}@example.test',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_agent'        => '',
			),
		),
		'signups'  => array(
			'update' => array(
				'user_login' => 'signup_{signup_id}',
				'user_email' => 'signup-{signup_id}@example.test',
			),
		),
	);
}

/**
 * WooCommerce rules, covering legacy post storage and HPOS tables.
 *
 * @return array
 */
function woocommerce_rules() {
	$payment_meta = array(
		'_stripe_customer_id'  => '',
		'_customer_ip_address' => '127.0.0.1',
		'_customer_user_agent' => '',
		'Payer PayPal address' => 'payer-{%s}@example.test',
		'Payer first name'     => 'PayerFirstName{%s}',
		'Payer last name'      => 'PayerLastName{%s}',
	);

	return array(
		'usermeta'                                   => array(
			'user_id_column' => 'user_id',
			'meta'           => array_merge(
				customer_address_meta( '', '{user_id}', 'user' ),
				customer_address_meta( '', '{user_id}', 'user', 'shipping' ),
				payment_meta( $payment_meta, 'user_id' )
			),
		),
		'postmeta'                                   => array(
			'meta' => array_merge(
				customer_address_meta( '_', '{post_id}', 'order' ),
				customer_address_meta( '_', '{post_id}', 'order', 'shipping' ),
				payment_meta( $payment_meta, 'post_id' ),
				array(
					'_billing_address_index'  => '',
					'_shipping_address_index' => '',
				)
			),
		),
		'wc_orders'                                  => array(
			'update' => array(
				'billing_email' => 'order-{id}@example.test',
				'ip_address'    => '127.0.0.1',
				'user_agent'    => '',
				'customer_note' => '',
			),
		),
		'wc_orders_meta'                             => array(
			'meta' => payment_meta( $payment_meta, 'order_id' ),
		),
		'wc_order_addresses'                         => array(
			'update' => array(
				'first_name' => 'OrderFirstName{order_id}',
				'last_name'  => 'OrderLastName{order_id}',
				'company'    => '',
				'address_1'  => '123 Example St',
				'address_2'  => '',
				'city'       => 'Example City',
				'postcode'   => '00000',
				'email'      => 'order-{order_id}@example.test',
				'phone'      => '000-000-0000',
			),
		),
		'wc_customer_lookup'                         => array(
			'user_id_column' => 'user_id',
			'update'         => array(
				'username'   => 'customer_{customer_id}',
				'first_name' => 'CustomerFirstName{customer_id}',
				'last_name'  => 'CustomerLastName{customer_id}',
				'email'      => 'customer-{customer_id}@example.test',
				'city'       => 'Example City',
				'postcode'   => '00000',
			),
		),
		'woocommerce_downloadable_product_permissions' => array(
			'update' => array(
				'user_email' => 'download-{permission_id}@example.test',
			),
		),
		'wc_download_log'                            => array(
			'update' => array(
				'user_ip_address' => '127.0.0.1',
			),
		),
		'woocommerce_payment_tokens'                 => array(
			'user_id_column' => 'user_id',
			'update'         => array(
				'token' => '',
			),
		),
		'woocommerce_sessions'                       => array(
			'truncate' => true,
		),
	);
}

/**
 * Build billing or shipping address meta rules.
 *
 * Existing values in usermeta keep the format from the original fork
 * (BillingFirstName{user_id}), orders use an Order prefix.
 *
 * @param string $key_prefix '_' for order postmeta, '' for usermeta.
 * @param string $id_token   Template token for the row ID, e.g. '{user_id}'.
 * @param string $context    'user' or 'order'.
 * @param string $type       'billing' or 'shipping'.
 *
 * @return array
 */
function customer_address_meta( $key_prefix, $id_token, $context, $type = 'billing' ) {
	$label = 'order' === $context ? 'Order' . ucfirst( $type ) : ucfirst( $type );
	$email = 'order' === $context ? 'order-' . $id_token . '@example.test' : 'user-' . $id_token . '@example.test';
	$key   = $key_prefix . $type . '_';

	$meta = array(
		$key . 'first_name' => $label . 'FirstName' . $id_token,
		$key . 'last_name'  => $label . 'LastName' . $id_token,
		$key . 'company'    => '',
		$key . 'address_1'  => '123 Example St',
		$key . 'address_2'  => '',
		$key . 'city'       => 'Example City',
		$key . 'postcode'   => '00000',
		$key . 'phone'      => '000-000-0000',
	);

	if ( 'billing' === $type ) {
		$meta[ $key . 'email' ] = $email;
	}

	return $meta;
}

/**
 * Fill the row ID column into the payment meta templates.
 *
 * @param array  $meta      meta_key => template with a {%s} placeholder for the ID column.
 * @param string $id_column ID column of the meta table.
 *
 * @return array
 */
function payment_meta( $meta, $id_column ) {
	return array_map(
		fn( $template ) => sprintf( $template, $id_column ),
		$meta
	);
}

/**
 * Password hash used for all anonymized users.
 *
 * Uses ELWP_ANON_DEFAULT_PASSWORD when defined, otherwise a random password
 * nobody knows.
 *
 * @return string
 */
function get_password_hash() {
	static $hash = null;

	if ( null === $hash ) {
		$password = defined( 'ELWP_ANON_DEFAULT_PASSWORD' ) && ELWP_ANON_DEFAULT_PASSWORD ? ELWP_ANON_DEFAULT_PASSWORD : wp_generate_password( 32, true, true );
		$hash     = wp_hash_password( $password );
	}

	return $hash;
}
