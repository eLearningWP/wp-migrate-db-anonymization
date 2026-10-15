<?php
/**
 * Plugin Name:       WP Migrate Pull Anonymizer (eLearningWP fork)
 * Plugin URI:        https://github.com/eLearningWP/wp-migrate-db-anonymization
 * Description:       Anonymizes user data on the receiving site while pulling a database with WP Migrate Pro. Install it on the development site only. Fork of Delicious Brains' WP Migrate DB Anonymization.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            eLearningWP
 * Author URI:        https://elearningwp.com
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wpmdb-pull-anonymizer
 * Network:           true
 * Update URI:        false
 *
 * Forked from https://github.com/deliciousbrains/wp-migrate-db-anonymization
 * Original work copyright (c) 2018 Delicious Brains, released under the GPL.
 */

namespace ELWP\PullAnonymizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.0';
const FILE    = __FILE__;

require_once __DIR__ . '/includes/rules.php';
require_once __DIR__ . '/includes/class-anonymizer.php';
require_once __DIR__ . '/includes/migrate-hooks.php';
require_once __DIR__ . '/includes/admin-notices.php';

register_migration_hooks();
register_admin_hooks();
