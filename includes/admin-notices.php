<?php
/**
 * Admin notices: what the plugin does on WP Migrate's screen, and the outcome of the last pull.
 */

namespace ELWP\PullAnonymizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register admin hooks.
 */
function register_admin_hooks() {
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_notices' );
	add_action( 'network_admin_notices', __NAMESPACE__ . '\\render_notices' );
}

/**
 * Render the notices.
 */
function render_notices() {
	if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
		return;
	}

	$last_run          = get_site_option( LAST_RUN_OPTION );
	$screen            = get_current_screen();
	$is_migrate_screen = $screen && str_contains( $screen->id, 'wp-migrate-db' );
	$failed            = is_array( $last_run ) && 'not_run' === ( $last_run['status'] ?? '' );

	// A pull that wasn't anonymized is shown everywhere, the rest only on WP Migrate's screen.
	if ( $failed && ! $is_migrate_screen ) {
		render_last_run( $last_run );
		return;
	}

	if ( ! $is_migrate_screen ) {
		return;
	}
	?>
	<div class="notice notice-info">
		<p>
			<?php
			echo wp_kses(
				__( '<strong>WP Migrate Pull Anonymizer</strong> is active: pulled data is anonymized before it replaces this site\'s tables. Pushes to and from this site are blocked. Deactivate the plugin to lift these restrictions.', 'wpmdb-pull-anonymizer' ),
				array( 'strong' => array() )
			);
			?>
		</p>
	</div>
	<?php

	if ( is_array( $last_run ) ) {
		render_last_run( $last_run );
	}
}

/**
 * Render the outcome of the last pull.
 *
 * @param array $last_run
 */
function render_last_run( $last_run ) {
	$status   = $last_run['status'] ?? '';
	$warnings = $last_run['warnings'] ?? array();
	$when     = sprintf(
		/* translators: 1: date and time, 2: remote site URL */
		__( 'Last pull (%1$s from %2$s):', 'wpmdb-pull-anonymizer' ),
		wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run['time'] ?? 0 ),
		$last_run['url'] ?? '?'
	);

	if ( 'success' === $status && ! $warnings ) {
		return;
	}

	$class = 'success' === $status ? 'notice-warning' : 'notice-error';
	?>
	<div class="notice <?php echo esc_attr( $class ); ?>">
		<p>
			<strong><?php echo esc_html( $when ); ?></strong>
			<?php
			if ( 'success' === $status ) {
				esc_html_e( 'Anonymized, but these tables may still hold personal data. Add rules with the elwp_anon_rules filter if needed.', 'wpmdb-pull-anonymizer' );
			} else {
				echo esc_html( $last_run['error'] ?? '' );
			}
			?>
		</p>
		<?php if ( $warnings ) : ?>
			<ul style="list-style: disc; padding-left: 20px;">
				<?php foreach ( $warnings as $warning ) : ?>
					<li><?php echo esc_html( $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
