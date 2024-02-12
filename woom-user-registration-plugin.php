<?php
/*
Plugin Name: Woom User Registration Plugin
Plugin URI: https://www.westerncpe.com/
Description: A plugin that schedules a cron task when a user checked out of WooCommerce if the product they purchased has a webinar id value stored in a meta value for the product.
Version: 2024.1.29
Author: Western CPE
Author URI: https://www.westerncpe.com/
License: BSD 3-Clause
*/

// Check if named constant is defined and define it if not
if ( ! defined( 'WOOM_DEBUG' ) ) {
	define( 'WOOM_DEBUG', true );
}

if ( ! defined( 'WOOM_LOGGING' ) ) {
	define( 'WOOM_LOGGING', true );
}

if ( ! defined( 'WOOM_PLUGIN_PATH' ) ) {
	\define( 'WOOM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WOOM_USER_REGISTRATION_VERSION' ) ) {
	define( 'WOOM_USER_REGISTRATION_VERSION', '2024.2.12' );
}

// unix timestamp of start time of event
if ( ! defined( 'WOOM_PRODUCT_START_TIME_META' ) ) {
	define( 'WOOM_PRODUCT_START_TIME_META', 'wpcf-event-start-date-and-time' );
}

require_once WOOM_PLUGIN_PATH . '/classes/class-woom-user-registration.php';
$wooom_user_registration = new WOOM_USER_REGISTRATION();
