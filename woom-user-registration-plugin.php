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


add_filter( 'woocommerce_order_item_display_meta_value', 'change_order_item_meta_value', 20, 3 );
function change_order_item_meta_value( $value, $meta, $item ) {

	// Change displayed value for specific order item meta key
	if ( stristr( $meta->key, '_join_url' ) ) {
		$value = __( '<a class="button" target="_blank" href="' . $value . '">Join Live Webinar</a>', 'woocommerce' );
	}
	return $value;
}

add_filter( 'woocommerce_order_item_display_meta_key', 'change_order_item_meta_key', 20, 3 );
function change_order_item_meta_key( $value, $meta, $item ) {

	// Change displayed value for specific order item meta key
	if ( stristr( $meta->key, '_join_url' ) ) {
		$value = __( 'Zoom Link', 'woocommerce' );
	}
	return $value;
}
