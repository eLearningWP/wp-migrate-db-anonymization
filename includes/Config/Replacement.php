<?php

namespace WPMDB\Anonymization\Config;

class Replacement {

	/**
	 * @param $password
	 *
	 * @return string
	 */
	public static function password( $password ) {
		if ( defined( 'WPMDB_ANONYMIZATION_DEFAULT_PASSWORD' ) && ! empty( WPMDB_ANONYMIZATION_DEFAULT_PASSWORD )) {
			$password = WPMDB_ANONYMIZATION_DEFAULT_PASSWORD;
		}

		return wp_hash_password( $password );
	}

	/**
	 * @param string $data
	 * @param object $row
	 * @param string $table
	 * @param string $column
	 * @param mixed  $constraint
	 *
	 * @return string
	 */
	public static function maybe_deterministic( $data, $row, $table, $column, $constraint = null ) {
		$user_id = self::get_user_id( $row );
		if ( empty( $user_id ) ) {
			return $data;
		}

		if ( 'users' === $table ) {
			switch ( $column ) {
				case 'user_login':
				case 'user_nicename':
					return sprintf( 'username_%d', $user_id );
				case 'user_email':
					return sprintf( 'user-%d@example.test', $user_id );
				case 'display_name':
					return sprintf( 'DisplayName-%d', $user_id );
			}
		}

		if ( 'usermeta' === $table && 'meta_value' === $column ) {
			$meta_key = self::get_meta_key( $row, $constraint );

			switch ( $meta_key ) {
				case 'first_name':
					return sprintf( 'FirstName-%d', $user_id );
				case 'last_name':
					return sprintf( 'LastName-%d', $user_id );
				case 'billing_first_name':
					return sprintf( 'BillingFirstName%d', $user_id );
				case 'billing_last_name':
					return sprintf( 'BillingLastName%d', $user_id );
			}
		}

		return $data;
	}

	/**
	 * @param object $row
	 * @param mixed  $constraint
	 *
	 * @return string
	 */
	protected static function get_meta_key( $row, $constraint ) {
		if ( isset( $row->meta_key ) ) {
			return $row->meta_key;
		}

		if ( is_array( $constraint ) && isset( $constraint['meta_key'] ) ) {
			return $constraint['meta_key'];
		}

		return '';
	}

	/**
	 * @param object $row
	 *
	 * @return int
	 */
	protected static function get_user_id( $row ) {
		if ( isset( $row->ID ) ) {
			return (int) $row->ID;
		}

		if ( isset( $row->user_id ) ) {
			return (int) $row->user_id;
		}

		return 0;
	}
}
