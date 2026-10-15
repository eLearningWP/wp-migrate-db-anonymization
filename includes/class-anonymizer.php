<?php
/**
 * Anonymizes the temporary tables WP Migrate creates on the local site during a pull.
 */

namespace ELWP\PullAnonymizer;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anonymizer {

	/**
	 * Temporary table name => table name without prefix, e.g. '_mig_wp_2_comments' => 'comments'.
	 *
	 * @var array
	 */
	protected $tables = array();

	/**
	 * @var string
	 */
	protected $temp_prefix;

	/**
	 * Table prefixes the incoming capabilities meta keys may use.
	 *
	 * @var array
	 */
	protected $prefixes = array();

	/**
	 * @var array
	 */
	protected $rules = array();

	/**
	 * @var int[]
	 */
	protected $whitelisted_user_ids = array();

	/**
	 * Rows changed per table.
	 *
	 * @var array
	 */
	protected $affected = array();

	/**
	 * Possible personal data the rules don't cover.
	 *
	 * @var string[]
	 */
	protected $warnings = array();

	/**
	 * @param string[] $temp_tables Temporary tables created by the pull.
	 * @param string   $temp_prefix Temporary table prefix, usually '_mig_'.
	 * @param string[] $prefixes    Local table prefix, plus the remote one if different.
	 */
	public function __construct( array $temp_tables, string $temp_prefix, array $prefixes ) {
		global $wpdb;

		$this->temp_prefix = $temp_prefix;
		$this->prefixes    = array_unique( array_filter( $prefixes ) );

		foreach ( $temp_tables as $temp_table ) {
			$name = $this->unprefixed_name( substr( $temp_table, strlen( $temp_prefix ) ), $wpdb->base_prefix );

			if ( null !== $name ) {
				$this->tables[ $temp_table ] = $name;
			}
		}
	}

	/**
	 * Anonymize all pulled tables that have rules.
	 *
	 * @return true|WP_Error
	 */
	public function run() {
		$this->rules                = get_rules();
		$this->whitelisted_user_ids = $this->get_whitelisted_user_ids();

		foreach ( $this->tables as $temp_table => $name ) {
			if ( empty( $this->rules[ $name ] ) ) {
				continue;
			}

			$result = $this->apply_rule( $temp_table, $this->rules[ $name ] );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$this->scan_for_personal_data();

		/**
		 * Fires after the pulled tables are anonymized, before they replace the live tables.
		 *
		 * Use it to anonymize tables the rules can't express.
		 *
		 * @param array      $tables     Temporary table name => unprefixed table name.
		 * @param Anonymizer $anonymizer
		 */
		do_action( 'elwp_anon_after_anonymize', $this->tables, $this );

		return true;
	}

	/**
	 * @return array Temporary table name => unprefixed table name.
	 */
	public function get_tables() {
		return $this->tables;
	}

	/**
	 * @return int[]
	 */
	public function get_whitelisted_ids() {
		return $this->whitelisted_user_ids;
	}

	/**
	 * @return array Table => rows changed.
	 */
	public function get_affected() {
		return $this->affected;
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * Add a warning, e.g. from an elwp_anon_after_anonymize callback.
	 *
	 * @param string $warning
	 */
	public function add_warning( $warning ) {
		$this->warnings[] = $warning;
	}

	/**
	 * Strip the database prefix, and the blog ID on multisite subsite tables.
	 *
	 * @param string $table
	 * @param string $prefix
	 *
	 * @return string|null Null when the table doesn't use the prefix.
	 */
	protected function unprefixed_name( $table, $prefix ) {
		if ( ! str_starts_with( $table, $prefix ) ) {
			return null;
		}

		$name = substr( $table, strlen( $prefix ) );

		if ( is_multisite() && preg_match( '/^\d+_(.+)$/', $name, $matches ) ) {
			return $matches[1];
		}

		return $name;
	}

	/**
	 * The pulled temporary table for a global table, or the live one when it wasn't pulled.
	 *
	 * @param string $name Unprefixed table name, e.g. 'users'.
	 *
	 * @return string
	 */
	protected function table_for( $name ) {
		global $wpdb;

		$live_table = $wpdb->base_prefix . $name;
		$temp_table = $this->temp_prefix . $live_table;

		return isset( $this->tables[ $temp_table ] ) ? $temp_table : $live_table;
	}

	/**
	 * Apply one table's rules.
	 *
	 * @param string $table
	 * @param array  $rule
	 *
	 * @return true|WP_Error
	 */
	protected function apply_rule( $table, $rule ) {
		if ( ! empty( $rule['truncate'] ) ) {
			return $this->query( $table, 'TRUNCATE TABLE %i', array( $table ) );
		}

		$columns = $this->get_columns( $table );

		list( $user_sql, $user_args ) = $this->user_condition( $rule, $columns );

		if ( ! empty( $rule['update'] ) ) {
			$set_sql  = array();
			$set_args = array();

			foreach ( $rule['update'] as $column => $template ) {
				if ( ! in_array( $column, $columns, true ) ) {
					continue;
				}

				$value = $this->compile_template( $template, $columns, $table );
				if ( is_wp_error( $value ) ) {
					return $value;
				}

				// Leave empty values empty.
				$set_sql[] = "%i = CASE WHEN %i IS NULL OR %i = '' THEN %i ELSE {$value[0]} END";
				$set_args  = array_merge( $set_args, array( $column, $column, $column, $column ), $value[1] );
			}

			if ( $set_sql ) {
				$sql  = 'UPDATE %i SET ' . implode( ', ', $set_sql ) . ' WHERE 1=1' . $user_sql;
				$args = array_merge( array( $table ), $set_args, $user_args );

				$result = $this->query( $table, $sql, $args );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$is_meta_table = in_array( 'meta_key', $columns, true ) && in_array( 'meta_value', $columns, true );

		if ( ! empty( $rule['meta'] ) && $is_meta_table ) {
			foreach ( $rule['meta'] as $meta_key => $template ) {
				$value = $this->compile_template( $template, $columns, $table );
				if ( is_wp_error( $value ) ) {
					return $value;
				}

				$sql  = "UPDATE %i SET meta_value = {$value[0]} WHERE meta_key = %s AND meta_value <> ''" . $user_sql;
				$args = array_merge( array( $table ), $value[1], array( $meta_key ), $user_args );

				$result = $this->query( $table, $sql, $args );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		if ( ! empty( $rule['delete_meta'] ) && $is_meta_table ) {
			$placeholders = implode( ', ', array_fill( 0, count( $rule['delete_meta'] ), '%s' ) );
			$sql          = "DELETE FROM %i WHERE meta_key IN ({$placeholders})" . $user_sql;
			$args         = array_merge( array( $table ), array_values( $rule['delete_meta'] ), $user_args );

			$result = $this->query( $table, $sql, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * SQL condition skipping whitelisted users' rows.
	 *
	 * @param array    $rule
	 * @param string[] $columns
	 *
	 * @return array SQL fragment and its arguments.
	 */
	protected function user_condition( $rule, $columns ) {
		$column = $rule['user_id_column'] ?? '';

		if ( ! $column || ! $this->whitelisted_user_ids || ! in_array( $column, $columns, true ) ) {
			return array( '', array() );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $this->whitelisted_user_ids ), '%d' ) );

		return array(
			" AND ( %i IS NULL OR %i NOT IN ({$placeholders}) )",
			array_merge( array( $column, $column ), $this->whitelisted_user_ids ),
		);
	}

	/**
	 * Turn a template like 'user-{ID}@example.test' into an SQL expression.
	 *
	 * @param string   $template
	 * @param string[] $columns Columns of the table.
	 * @param string   $table
	 *
	 * @return array|WP_Error SQL expression and its arguments.
	 */
	protected function compile_template( $template, $columns, $table ) {
		$parts = preg_split( '/\{([A-Za-z0-9_]+)\}/', (string) $template, -1, PREG_SPLIT_DELIM_CAPTURE );
		$sql   = array();
		$args  = array();

		foreach ( $parts as $index => $part ) {
			// Even parts are literal text, odd parts are column names.
			if ( 0 === $index % 2 ) {
				if ( '' !== $part ) {
					$sql[]  = '%s';
					$args[] = $part;
				}
				continue;
			}

			if ( ! in_array( $part, $columns, true ) ) {
				return new WP_Error(
					'elwp_anon_unknown_column',
					sprintf(
						/* translators: 1: column name, 2: template, 3: table name */
						__( 'Anonymization rule error: column "%1$s" used in "%2$s" doesn\'t exist in %3$s.', 'wpmdb-pull-anonymizer' ),
						$part,
						$template,
						$table
					)
				);
			}

			$sql[]  = "COALESCE(%i, '')";
			$args[] = $part;
		}

		if ( ! $sql ) {
			return array( '%s', array( '' ) );
		}

		if ( 1 === count( $sql ) ) {
			return array( $sql[0], $args );
		}

		return array( 'CONCAT(' . implode( ', ', $sql ) . ')', $args );
	}

	/**
	 * Run a query and count the changed rows.
	 *
	 * @param string $table
	 * @param string $sql
	 * @param array  $args
	 *
	 * @return true|WP_Error
	 */
	protected function query( $table, $sql, $args ) {
		global $wpdb;

		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		if ( false === $result ) {
			return new WP_Error(
				'elwp_anon_query_failed',
				sprintf(
					/* translators: 1: table name, 2: database error */
					__( 'Anonymization failed on %1$s: %2$s. The pulled data was not applied.', 'wpmdb-pull-anonymizer' ),
					$table,
					$wpdb->last_error
				)
			);
		}

		$this->affected[ $table ] = ( $this->affected[ $table ] ?? 0 ) + (int) $result;

		return true;
	}

	/**
	 * @param string $table
	 *
	 * @return string[]
	 */
	protected function get_columns( $table ) {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	}

	/**
	 * IDs of the incoming users that must not be anonymized.
	 *
	 * @return int[]
	 */
	protected function get_whitelisted_user_ids() {
		global $wpdb;

		$users_table    = $this->table_for( 'users' );
		$usermeta_table = $this->table_for( 'usermeta' );
		$ids            = array();

		$logins = constant_list( 'ELWP_ANON_USER_LOGIN_WHITELIST' );
		if ( is_multisite() ) {
			$logins = array_merge( $logins, $this->get_super_admin_logins() );
		}

		if ( $logins ) {
			$placeholders = implode( ', ', array_fill( 0, count( $logins ), '%s' ) );
			$ids          = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM %i WHERE user_login IN ({$placeholders})",
					array_merge( array( $users_table ), $logins )
				)
			);
		}

		$roles = defined( 'ELWP_ANON_USER_ROLE_WHITELIST' ) ? constant_list( 'ELWP_ANON_USER_ROLE_WHITELIST' ) : array( 'administrator' );

		/**
		 * Filter the roles whose users are not anonymized.
		 *
		 * @param string[] $roles
		 */
		$roles = apply_filters( 'elwp_anon_whitelisted_roles', $roles );

		if ( $roles ) {
			$ids = array_merge( $ids, $this->get_user_ids_with_roles( $usermeta_table, $roles ) );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		/**
		 * Filter the IDs of incoming users that are not anonymized.
		 *
		 * @param int[]  $ids
		 * @param string $users_table    Table to read the incoming users from.
		 * @param string $usermeta_table Table to read the incoming user meta from.
		 */
		$ids = apply_filters( 'elwp_anon_whitelisted_user_ids', $ids, $users_table, $usermeta_table );

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Users having any of the roles on any site.
	 *
	 * @param string   $usermeta_table
	 * @param string[] $roles
	 *
	 * @return int[]
	 */
	protected function get_user_ids_with_roles( $usermeta_table, $roles ) {
		global $wpdb;

		$key_sql  = array();
		$role_sql = array();
		$args     = array( $usermeta_table );

		// Matches {prefix}capabilities and {prefix}{blog_id}_capabilities.
		foreach ( $this->prefixes as $prefix ) {
			$key_sql[] = 'meta_key LIKE %s';
			$args[]    = $wpdb->esc_like( $prefix ) . '%' . $wpdb->esc_like( 'capabilities' );
		}

		foreach ( $roles as $role ) {
			$role_sql[] = 'meta_value LIKE %s';
			$args[]     = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, meta_key, meta_value FROM %i WHERE (' . implode( ' OR ', $key_sql ) . ') AND (' . implode( ' OR ', $role_sql ) . ')',
				$args
			)
		);

		$ids = array();

		foreach ( $rows as $row ) {
			if ( ! $this->is_capabilities_key( $row->meta_key ) ) {
				continue;
			}

			$capabilities = unserialize( $row->meta_value, array( 'allowed_classes' => false ) );

			if ( ! is_array( $capabilities ) ) {
				continue;
			}

			foreach ( $roles as $role ) {
				if ( ! empty( $capabilities[ $role ] ) ) {
					$ids[] = (int) $row->user_id;
					break;
				}
			}
		}

		return $ids;
	}

	/**
	 * @param string $meta_key
	 *
	 * @return bool
	 */
	protected function is_capabilities_key( $meta_key ) {
		foreach ( $this->prefixes as $prefix ) {
			if ( str_starts_with( $meta_key, $prefix ) && preg_match( '/^(\d+_)?capabilities$/', substr( $meta_key, strlen( $prefix ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Super admin logins from the incoming network, or the local one when sitemeta isn't pulled.
	 *
	 * @return string[]
	 */
	protected function get_super_admin_logins() {
		global $wpdb;

		$values = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE meta_key = %s',
				$this->table_for( 'sitemeta' ),
				'site_admins'
			)
		);

		$logins = array();

		foreach ( $values as $value ) {
			$admins = unserialize( $value, array( 'allowed_classes' => false ) );

			if ( is_array( $admins ) ) {
				$logins = array_merge( $logins, $admins );
			}
		}

		return array_filter( array_map( 'strval', $logins ) );
	}

	/**
	 * Warn about pulled columns that look like personal data but have no rule.
	 */
	protected function scan_for_personal_data() {
		$pattern = '/(e_?mail|phone|first_?name|last_?name|full_?name|(^|_)address(_?\d|_line_?\d)?$|(^|_)ip($|_)|ip_?address|user_?agent|post_?code|zip_?code)/i';

		/**
		 * Filter the tables known to hold personal data in a way the column scan can't detect,
		 * such as form entries stored as key/value rows.
		 *
		 * @param string[] $tables Unprefixed table names.
		 */
		$known_tables = apply_filters(
			'elwp_anon_personal_data_tables',
			array(
				'gf_entry',
				'gf_entry_meta',
				'rg_lead',
				'rg_lead_detail',
				'wpforms_entries',
				'wpforms_entry_fields',
				'frmt_form_entry_meta',
				'frm_item_metas',
				'e_submissions_values',
				'fluentform_entry_details',
			)
		);

		foreach ( $this->tables as $temp_table => $name ) {
			$rule = $this->rules[ $name ] ?? array();

			if ( ! empty( $rule['truncate'] ) ) {
				continue;
			}

			$live_table = substr( $temp_table, strlen( $this->temp_prefix ) );

			if ( in_array( $name, $known_tables, true ) ) {
				$this->warnings[] = sprintf(
					/* translators: %s: table name */
					__( '%s is known to store personal data (e.g. form entries) and has no anonymization rule.', 'wpmdb-pull-anonymizer' ),
					$live_table
				);
				continue;
			}

			$covered = array_keys( $rule['update'] ?? array() );
			$matches = array();

			foreach ( $this->get_columns( $temp_table ) as $column ) {
				if ( ! in_array( $column, $covered, true ) && preg_match( $pattern, $column ) ) {
					$matches[] = $column;
				}
			}

			if ( $matches ) {
				$this->warnings[] = sprintf(
					/* translators: 1: table name, 2: column names */
					__( '%1$s has columns that may hold personal data and have no anonymization rule: %2$s.', 'wpmdb-pull-anonymizer' ),
					$live_table,
					implode( ', ', $matches )
				);
			}
		}
	}
}

/**
 * Read a comma separated list from a constant.
 *
 * @param string $name
 *
 * @return string[]
 */
function constant_list( $name ) {
	if ( ! defined( $name ) || ! is_string( constant( $name ) ) ) {
		return array();
	}

	return array_values( array_filter( array_map( 'trim', explode( ',', constant( $name ) ) ) ) );
}
