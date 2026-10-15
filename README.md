WP Migrate Pull Anonymizer (eLearningWP fork)
=============================================

Anonymizes user data **on the site doing the pull** with [WP Migrate Pro](https://deliciousbrains.com/wp-migrate-db-pro/). Install it on your development site only: the production site just needs WP Migrate Pro.

> Fork of [deliciousbrains/wp-migrate-db-anonymization](https://github.com/deliciousbrains/wp-migrate-db-anonymization), reworked to run on the receiving site. It no longer anonymizes exports or pushes from the source site, and it no longer uses Faker: values are predictable, e.g. `user-42@example.test`.

Requires WordPress 6.2+, PHP 8.0+ and WP Migrate Pro 2.7+.

## How it works

During a pull, WP Migrate imports the remote tables into temporary tables on your site (`_mig_wp_users`, …) and renames them over the live tables at the end. This plugin anonymizes the temporary tables right before that rename, with one SQL `UPDATE` per rule, so it's fast even on large sites.

If anonymizing fails, the migration stops with an error and your live tables are left as they were.

It works the same for pulls from the WP Migrate screen and with WP-CLI (`wp migratedb pull`). WP-CLI also prints a summary and any warnings.

While the plugin is active:

- **Pushes from this site are blocked**, so anonymized data can't overwrite a real site.
- **Pushes into this site are rejected**, as they would bring real data in without anonymizing it.

Deactivate the plugin to lift these restrictions.

The real data still travels from production to your site and sits in the temporary tables until the pull finishes.

## What gets anonymized

Users with the `administrator` role are kept as they are (see [Whitelisting users](#whitelisting-users)).

| Table | Columns / meta keys | Replaced with |
|---|---|---|
| users | `user_login`, `user_nicename` | `username_{ID}` |
| | `user_email` | `user-{ID}@example.test` |
| | `display_name` | `DisplayName-{ID}` |
| | `user_url`, `user_activation_key` | empty |
| | `user_pass` | hash of `ELWP_ANON_DEFAULT_PASSWORD`, or of a random password |
| usermeta | `first_name`, `last_name`, `nickname` | `FirstName-{user_id}`, `LastName-{user_id}`, `username_{user_id}` |
| | `description` | empty |
| | `session_tokens`, `community-events-location` | deleted |
| | WooCommerce `billing_*`, `shipping_*` | `BillingFirstName{user_id}`, `user-{user_id}@example.test`, `123 Example St`, … |
| | `_stripe_customer_id`, PayPal payer details | empty / placeholders |
| comments | author, email, URL, IP, user agent | `Commenter-{comment_ID}`, `comment-{comment_ID}@example.test`, empty, `127.0.0.1`, empty |
| signups (multisite) | `user_login`, `user_email` | `signup_{signup_id}`, `signup-{signup_id}@example.test` |
| postmeta (WooCommerce orders) | `_billing_*`, `_shipping_*`, address indexes, IP, payment IDs | `OrderBillingFirstName{post_id}`, `order-{post_id}@example.test`, … |
| wc_orders, wc_orders_meta, wc_order_addresses (HPOS) | email, IP, user agent, customer note, addresses, payment IDs | `order-{id}@example.test`, … |
| wc_customer_lookup | username, names, email, city, postcode | `customer_{customer_id}`, … |
| woocommerce_downloadable_product_permissions, wc_download_log | email, IP | placeholders |
| woocommerce_payment_tokens | `token` | empty, so the site can't charge saved cards |
| woocommerce_sessions | | emptied |

Empty values stay empty. See [`includes/rules.php`](includes/rules.php) for the full list.

## Warnings for other personal data

After each pull the plugin looks for personal data it has no rules for:

- tables of plugins known to store form entries (Gravity Forms, WPForms, Forminator, Formidable, Elementor, Fluent Forms),
- columns whose names look like personal data (`email`, `phone`, `first_name`, `address`, `ip_address`, …).

Warnings are shown on the WP Migrate screen, printed by WP-CLI and written to the PHP error log. They don't stop the pull. Add the tables with the `elwp_anon_personal_data_tables` filter.

If a pull ever finishes without being anonymized (for example if WP Migrate changes its hooks), an error notice is shown on every admin screen.

## Configuration

Add these to the development site's `wp-config.php`:

    // Comma separated user logins that are not anonymized.
    define( 'ELWP_ANON_USER_LOGIN_WHITELIST', 'somelogin, anotherlogin' );

    // Comma separated roles whose users are not anonymized. Default: administrator.
    define( 'ELWP_ANON_USER_ROLE_WHITELIST', 'administrator, shop_manager' );

    // Password for all anonymized users. Default: a random password.
    define( 'ELWP_ANON_DEFAULT_PASSWORD', 'password123' );

### Whitelisting users

Whitelisted users' rows are left as they are in every table with a user ID column (users, usermeta, comments, …). On multisite, super admins are always kept.

Roles and logins are read from the **incoming** data. You can change the list of user IDs with a filter:

    add_filter( 'elwp_anon_whitelisted_user_ids', function ( $ids, $users_table, $usermeta_table ) {
        global $wpdb;

        $team = $wpdb->get_col( $wpdb->prepare(
            'SELECT ID FROM %i WHERE user_email LIKE %s',
            $users_table,
            '%@mycompany.com'
        ) );

        return array_merge( $ids, $team );
    }, 10, 3 );

## Extending

Rules are keyed by table name without the prefix. Use `{column}` to insert a value from the same row:

    add_filter( 'elwp_anon_rules', function ( $rules ) {
        // A meta key.
        $rules['usermeta']['meta']['date_of_birth'] = '1970-01-01';

        // Columns of a plugin table.
        $rules['newsletter_subscribers'] = array(
            'update' => array(
                'email' => 'subscriber-{id}@example.test',
                'name'  => 'Subscriber {id}',
            ),
        );

        // Empty a table.
        $rules['gf_entry_meta'] = array( 'truncate' => true );

        return $rules;
    } );

Each table rule can define `update` (column => template), `meta` (meta_key => template for `meta_value`), `delete_meta` (meta keys to delete), `truncate`, and `user_id_column` (skip whitelisted users' rows).

For anything the rules can't express, hook `elwp_anon_after_anonymize`. It fires after the rules have run and before the tables go live, and receives the temporary table names:

    add_action( 'elwp_anon_after_anonymize', function ( $tables, $anonymizer ) {
        // $tables: '_mig_wp_my_table' => 'my_table'
    }, 10, 2 );

## Development

`tests/run.sh` runs an integration test against a real WP Migrate Pro install in a [wp-agent-sandbox](https://github.com/eLearningWP/wp-agent-sandbox) environment. Update the WP Migrate Pro path in `wp-agent-sandbox.config.json`, then run:

    wp-agent-sandbox up
    tests/run.sh

## License

GPLv3. Original work copyright (c) 2018 Delicious Brains.
