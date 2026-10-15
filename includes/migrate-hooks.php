<?php
/**
 * WP Migrate integration: anonymize pulls before they go live and block pushes.
 *
 * During a pull WP Migrate imports the remote tables into temporary tables
 * (e.g. _mig_wp_users) on this site, then renames them over the live tables when
 * finalizing. We anonymize the temporary tables right before that rename, so the
 * real data never reaches the live tables. Returning an error from
 * wpmdb_before_finalize_migration stops the migration.
 */

namespace ELWP\PullAnonymizer;

use WP_CLI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAST_RUN_OPTION = 'elwp_anon_last_run';

/**
 * Register WP Migrate hooks.
 */
function register_migration_hooks() {
	add_action( 'wpmdb_finalize_migration', __NAMESPACE__ . '\\capture_finalize_state' );
	// Late priority: WP Migrate's own callback on this filter discards the value it receives, which would drop our error.
	add_filter( 'wpmdb_before_finalize_migration', __NAMESPACE__ . '\\anonymize_before_finalize', 999 );
	add_action( 'wpmdb_migration_complete', __NAMESPACE__ . '\\check_pull_was_anonymized', 10, 3 );
	add_filter( 'wpmdb_initiate_key_rules', __NAMESPACE__ . '\\block_push_from_this_site', 10, 2 );
	add_filter( 'site_option_wpmdb_settings', __NAMESPACE__ . '\\disable_incoming_push' );
}

/**
 * Remember the migration state while this site finalizes a migration it started.
 *
 * wpmdb_finalize_migration only fires on the site running the migration (browser
 * and WP-CLI), so it tells us the following finalize is ours and not a remote one.
 *
 * @param array $state_data
 */
function capture_finalize_state( $state_data ) {
	finalize_state( $state_data );
}

/**
 * @param array|null $state_data State to store, or null to read.
 *
 * @return array|null
 */
function finalize_state( $state_data = null ) {
	static $state = null;

	if ( null !== $state_data ) {
		$state = $state_data;
	}

	return $state;
}

/**
 * @param bool|null $done True to mark this request's pull as anonymized, or null to read.
 *
 * @return bool
 */
function pull_anonymized( $done = null ) {
	static $anonymized = false;

	if ( null !== $done ) {
		$anonymized = $done;
	}

	return $anonymized;
}

/**
 * Anonymize the pulled temporary tables before WP Migrate renames them over the live tables.
 *
 * @param true|WP_Error $response
 *
 * @return true|WP_Error
 */
function anonymize_before_finalize( $response ) {
	global $wpdb;

	$state = finalize_state();

	if ( is_wp_error( $response ) || ! is_array( $state ) || 'pull' !== ( $state['intent'] ?? '' ) ) {
		return $response;
	}

	$temp_prefix = $state['temp_prefix'] ?? '_mig_';
	$prefixes    = array(
		$wpdb->base_prefix,
		$state['site_details']['remote']['prefix'] ?? '',
	);

	$anonymizer = new Anonymizer( get_pulled_tables( $state, $temp_prefix ), $temp_prefix, $prefixes );
	$result     = $anonymizer->run();

	record_run( $state, $anonymizer, $result );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	pull_anonymized( true );

	return $response;
}

/**
 * Temporary tables of this pull that exist on this site.
 *
 * Mirrors how WP Migrate's FinalizeMigration works out the tables to rename.
 *
 * @param array  $state
 * @param string $temp_prefix
 *
 * @return string[]
 */
function get_pulled_tables( $state, $temp_prefix ) {
	global $wpdb;

	$tables = $state['tables'] ?? array();
	$tables = is_string( $tables ) ? explode( ',', $tables ) : (array) $tables;

	$state['source_prefix']      = $state['site_details']['remote']['prefix'] ?? $state['source_prefix'] ?? $wpdb->base_prefix;
	$state['destination_prefix'] = $state['site_details']['local']['prefix'] ?? $state['destination_prefix'] ?? $wpdb->base_prefix;

	$change_prefix = $state['source_prefix'] !== $state['destination_prefix'] && '1' !== ( $state['mst_select_subsite'] ?? '' );

	$existing = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $temp_prefix ) . '%' ) );
	$pulled   = array();

	foreach ( $tables as $table ) {
		$table = trim( $table );

		if ( '' === $table ) {
			continue;
		}

		if ( $change_prefix && str_starts_with( $table, $state['source_prefix'] ) ) {
			$table = $state['destination_prefix'] . substr( $table, strlen( $state['source_prefix'] ) );
		}

		// WP Migrate's own filter, so we match the tables it's about to rename.
		$temp_table = $temp_prefix . apply_filters( 'wpmdb_finalize_target_table_name', $table, $state );

		if ( in_array( $temp_table, $existing, true ) ) {
			$pulled[] = $temp_table;
		}
	}

	return $pulled;
}

/**
 * Store the outcome of a run for the admin notice, and report it to the log and WP-CLI.
 *
 * @param array         $state
 * @param Anonymizer    $anonymizer
 * @param true|WP_Error $result
 */
function record_run( $state, $anonymizer, $result ) {
	$warnings = $anonymizer->get_warnings();
	$affected = $anonymizer->get_affected();

	update_site_option(
		LAST_RUN_OPTION,
		array(
			'time'        => time(),
			'status'      => is_wp_error( $result ) ? 'error' : 'success',
			'error'       => is_wp_error( $result ) ? $result->get_error_message() : '',
			'url'         => $state['url'] ?? '',
			'affected'    => $affected,
			'whitelisted' => count( $anonymizer->get_whitelisted_ids() ),
			'warnings'    => $warnings,
		)
	);

	foreach ( $warnings as $warning ) {
		error_log( '[WP Migrate Pull Anonymizer] ' . $warning );
	}

	if ( is_wp_error( $result ) ) {
		error_log( '[WP Migrate Pull Anonymizer] ' . $result->get_error_message() );
	}

	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || is_wp_error( $result ) ) {
		return;
	}

	WP_CLI::log(
		sprintf(
			/* translators: 1: number of rows, 2: number of tables, 3: number of users */
			__( 'Anonymized %1$d rows in %2$d tables, kept %3$d whitelisted users.', 'wpmdb-pull-anonymizer' ),
			array_sum( $affected ),
			count( $affected ),
			count( $anonymizer->get_whitelisted_ids() )
		)
	);

	foreach ( $warnings as $warning ) {
		WP_CLI::warning( $warning );
	}
}

/**
 * Flag a pull that finished without being anonymized, e.g. if WP Migrate changed its hooks.
 *
 * WP Migrate passes a third argument only when this site was the source of
 * another site's pull, which doesn't concern us.
 *
 * @param string      $type
 * @param string      $location
 * @param string|null $remote
 */
function check_pull_was_anonymized( $type = '', $location = '', $remote = null ) {
	if ( 'pull' !== $type || null !== $remote || pull_anonymized() ) {
		return;
	}

	$message = __( 'A pull finished but the data was NOT anonymized. WP Migrate may have changed how migrations are finalized. Check this site\'s data before using it.', 'wpmdb-pull-anonymizer' );

	update_site_option(
		LAST_RUN_OPTION,
		array(
			'time'   => time(),
			'status' => 'not_run',
			'error'  => $message,
			'url'    => $location,
		)
	);

	error_log( '[WP Migrate Pull Anonymizer] ' . $message );
}

/**
 * Refuse to start a push from this site, it would overwrite real data with anonymized data.
 *
 * @param array  $key_rules
 * @param string $context
 *
 * @return array
 */
function block_push_from_this_site( $key_rules, $context ) {
	if ( 'ajax_initiate_migration' !== $context ) {
		return $key_rules;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP Migrate verifies the request before this filter runs.
	$intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';

	if ( 'push' !== $intent ) {
		return $key_rules;
	}

	$message = __( 'Pushing from this site is blocked by WP Migrate Pull Anonymizer: this site holds anonymized data that would overwrite the real data on the remote site. Deactivate the plugin if you really need to push.', 'wpmdb-pull-anonymizer' );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( $message );
	}

	wp_send_json_error( $message );
}

/**
 * Reject pushes into this site, they would bring in real data without anonymizing it.
 *
 * @param mixed $settings
 *
 * @return mixed
 */
function disable_incoming_push( $settings ) {
	if ( is_array( $settings ) ) {
		$settings['allow_push'] = false;
	}

	return $settings;
}
