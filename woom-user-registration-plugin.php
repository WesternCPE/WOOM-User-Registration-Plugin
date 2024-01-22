<?php
/*
Plugin Name: Woom User Registration Plugin
Plugin URI: https://www.westerncpe.com/
Description: A plugin that schedules a cron task when a user checked out of WooCommerce if the product they purchased has a webinar id value stored in a meta value for the product.
Version: 2024.1.22
Author: Western CPE
Author URI: https://www.westerncpe.com/
License: BSD 3-Clause
*/

// Add meta box to product edit screen
add_action( 'add_meta_boxes', 'woom_add_webinar_id_meta_box', 5 );
function woom_add_webinar_id_meta_box() {
	add_meta_box( 'woom_webinar_id_meta_box', 'Webinar ID', 'woom_render_webinar_id_meta_box', 'product', 'side', 'default' );
}

function woom_render_webinar_id_meta_box( $post ) {
	$webinar_id = get_post_meta( $post->ID, 'woom_webinar_id', true );
	?>
	<label for="woom_webinar_id">Webinar ID:</label>
	<input type="text" id="woom_webinar_id" name="woom_webinar_id" value="<?php echo esc_attr( $webinar_id ); ?>">
	<?php
}

// Save webinar ID meta value
add_action( 'save_post_product', 'woom_save_webinar_id_meta_value' );
function woom_save_webinar_id_meta_value( $post_id ) {
	if ( isset( $_POST['woom_webinar_id'] ) ) {
		$webinar_id = sanitize_text_field( $_POST['woom_webinar_id'] );
		update_post_meta( $post_id, 'woom_webinar_id', $webinar_id );
	}
}

// Schedule cron task after WooCommerce checkout
add_action( 'woocommerce_checkout_order_processed', 'woom_schedule_cron_task' );
function woom_schedule_cron_task( $order_id ) {
	$order = wc_get_order( $order_id );
	$items = $order->get_items();

	foreach ( $items as $item_id => $item ) {
		$product_id = $item->get_product_id();
		$webinar_id = get_post_meta( $product_id, 'woom_webinar_id', true );

		if ( ! empty( $webinar_id ) ) {
			$timestamp = strtotime( '+1 minute' );
			wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
			break;
		}
	}
}

// Test Action to fire without WooCommerce checkout
// add_action( 'plugins_loaded', 'run_woom_process_cron_task' );
// function run_woom_process_cron_task() {
//  $order_id = 0;
//  $item_id  = 0;
//  woom_process_cron_task( $order_id, $item_id );
// }

// Cron task callback function
add_action( 'woom_cron_task', 'woom_process_cron_task' );
function woom_process_cron_task( $order_id, $item_id ) {
	$account_id    = get_option( 'woom_account_id' );
	$client_key    = get_option( 'woom_client_key' );
	$client_secret = get_option( 'woom_client_secret' );

	// $item       = wc_get_order_item( $item_id );
	$item       = new WC_Order_Item_Product( $item_id );
	$product_id = $item->get_product_id();
	$webinar_id = get_post_meta( $product_id, 'woom_webinar_id', true );

	if ( ! empty( $webinar_id ) ) {
		// Get the order item from the item ID

		$bearer_token = wp_cache_get( 'bearer_token_' . base64_encode( $client_key . ':' . $client_secret ), 'woom_plugin', false, $found );

		if ( ! $found ) {
			// Call Zoom Authentication API to get bearer token
			// $api_endpoint = 'https://api.zoom.us/v2/users/me/token';
			$api_endpoint = 'https://zoom.us/oauth/token';

			$headers = array(
				'Authorization' => 'Basic ' . base64_encode( $client_key . ':' . $client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			);

			$data = array(
				'grant_type' => 'account_credentials',
				'account_id' => $account_id,
			);

			$response = wp_remote_post(
				$api_endpoint,
				array(
					'headers' => $headers,
					'body'    => $data,
				)
			);

			if ( is_wp_error( $response ) ) {
				// Handle error
				$timestamp = strtotime( '+1 minute' );
				wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
				return;

			} else {
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );

				if ( isset( $data['access_token'] ) ) {
					$bearer_token = $data['access_token'];
					// Use the bearer token for further API calls
				} else {
					// Handle error by scheduling the cron task again
					$timestamp = strtotime( '+1 minute' );
					wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
					return;
				}
			}

			wp_cache_set( 'bearer_token_' . base64_encode( $client_key . ':' . $client_secret ), $bearer_token, 'woom_plugin', 3000 );
		}

		// Call POST user bulk registration endpoint

		// Store the response and perform any necessary actions
		// Make the API call to register the attendee for the Zoom Webinar
		$api_endpoint = 'https://api.zoom.us/v2/webinars/' . $webinar_id . '/registrants';

		// $user = get_userdata( get_post_field( 'post_author', $order_id ) );
		$order = wc_get_order( $order_id );

		// Get the Customer ID (User ID)
		$user_id = $order->get_customer_id();
		$user    = get_userdata( $user_id );

		$email      = $user->user_email;
		$first_name = $user->first_name;
		$last_name  = $user->last_name;

		$data = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			// Add any additional parameters as needed
			// ...
		);

		$headers = array(
			'Authorization' => 'Bearer ' . $bearer_token,
			'Content-Type'  => 'application/json',
		);

		$response = wp_remote_post(
			$api_endpoint,
			array(
				'headers' => $headers,
				'body'    => json_encode( $data ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Handle error
			// Handle error by scheduling the cron task again
			$timestamp = strtotime( '+1 minute' );
			wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
			return;

		} else {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['join_url'] ) ) {
				$join_url = $data['join_url'];

				// Use the bearer token for further API calls

				// Store the join_url in user meta
				delete_user_meta( $user_id, 'product_' . $product_id . '_join_url' );
				update_user_meta( $user_id, 'product_' . $product_id . '_join_url', $join_url );

				// Store the join_url in order item meta
				wc_delete_order_item_meta( $item_id, 'product_' . $product_id . '_join_url' );
				wc_add_order_item_meta( $item_id, 'product_' . $product_id . '_join_url', $join_url );

				// Call the action to handle the join URL
				do_action( 'handle_join_url', $order_id, $item_id, $user_id, $join_url );

			} else {
				// Handle error by scheduling the cron task again
				$timestamp = strtotime( '+1 minute' );
				wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
				return;
			}
		}
	}
}


// Add meta box to site options page
add_action( 'admin_menu', 'woom_add_site_options_meta_box' );
function woom_add_site_options_meta_box() {
	add_options_page( 'Woom Options', 'Woom Options', 'manage_options', 'woom_site_options', 'woom_render_site_options_meta_box' );
}

function woom_render_site_options_meta_box() {
	$account_id    = get_option( 'woom_account_id' );
	$client_key    = get_option( 'woom_client_key' );
	$client_secret = get_option( 'woom_client_secret' );

	?>
	<div class="wrap">
		<h1>Woom Site Options</h1>
		<form method="post" action="options.php">
	<?php settings_fields( 'woom_site_options_group' ); ?>
			<?php do_settings_sections( 'woom_site_options' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">Account ID</th>
					<td><input type="text" name="woom_account_id" value="<?php echo esc_attr( $account_id ); ?>" size="32" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">Zoom Client Key</th>
					<td><input type="text" name="woom_client_key" value="<?php echo esc_attr( $client_key ); ?>" size="32" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">Zoom Client Secret</th>
					<td><input type="password" name="woom_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" size="32" /></td>
				</tr>
				
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

// Save site options
add_action( 'admin_init', 'woom_save_site_options' );
function woom_save_site_options() {
	register_setting( 'woom_site_options_group', 'woom_account_id' );
	register_setting( 'woom_site_options_group', 'woom_client_key' );
	register_setting( 'woom_site_options_group', 'woom_client_secret' );
}
