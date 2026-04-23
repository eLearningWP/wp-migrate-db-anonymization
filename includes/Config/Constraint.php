<?php

namespace WPMDB\Anonymization\Config;

class Constraint {

	public static function is_not_whitelisted_user( $row ) {
		if ( isset( $row->user_login ) ) {
			$user = new \WP_User( $row );
		} elseif ( isset( $row->user_id ) ) {
			$user = new \WP_User( (int) $row->user_id );
		} else {
			return true;
		}

		return ! self::is_whitelisted_user( $user );
	}

	/**
	 * @param \WP_User $user
	 *
	 * @return bool
	 */
	protected static function is_whitelisted_user( $user ) {
		$whitelisted = false;
		if ( defined( 'WPMDB_ANONYMIZATION_USER_LOGIN_WHITELIST' ) ) {
			$whitelisted_user_logins = array_map( 'trim', explode( ',', WPMDB_ANONYMIZATION_USER_LOGIN_WHITELIST ) );

			$whitelisted = in_array( $user->user_login, $whitelisted_user_logins );
		}

		$excluded_roles = array( 'administrator' );
		if ( defined( 'WPMDB_ANONYMIZATION_USER_ROLE_WHITELIST' ) ) {
			$excluded_roles = array_map( 'trim', explode( ',', WPMDB_ANONYMIZATION_USER_ROLE_WHITELIST ) );
		}

		$excluded_roles = apply_filters( 'wpmdb_anonymization_excluded_user_roles', $excluded_roles, $user );
		if ( is_array( $user->roles ) && ! empty( array_intersect( $user->roles, $excluded_roles ) ) ) {
			$whitelisted = true;
		}

		return (bool) apply_filters( 'wpmdb_anonymization_user_whitelisted', $whitelisted, $user );
	}
}
