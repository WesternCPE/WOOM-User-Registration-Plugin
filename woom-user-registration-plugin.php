<?php
/*
Plugin Name: Woom User Registration Plugin
Plugin URI: https://www.westerncpe.com/
Description: A plugin that schedules a cron task when a user checked out of WooCommerce if the product they purchased has a webinar id value stored in a meta value for the product.
Version: 1.0.0
Author: Western CPE
Author URI: https://www.westerncpe.com/
License: BSD 3-Clause
*/

// Add meta box to product edit screen
add_action( 'add_meta_boxes', 'woom_add_webinar_id_meta_box' );
function woom_add_webinar_id_meta_box() {
	add_meta_box( 'woom_webinar_id_meta_box', 'Webinar ID', 'woom_render_webinar_id_meta_box', 'product', 'side', 'high' );
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

// Cron task callback function
add_action( 'woom_cron_task', 'woom_process_cron_task' );
function woom_process_cron_task( $order_id, $item_id ) {
	$client_key    = get_option( 'woom_client_key' );
	$client_secret = get_option( 'woom_client_secret' );

	// Call Zoom Authentication API to get bearer token
	// Replace with your actual API call

	$bearer_token = 'your_bearer_token';

	// Call POST user bulk registration endpoint
	// Replace with your actual API call

	$first_name = 'John';
	$last_name  = 'Doe';
	$email      = 'john.doe@example.com';

	// Store the response and perform any necessary actions
	// Make the API call to register the attendee for the Zoom Webinar
	// Replace the API endpoint and parameters with the actual values for your Zoom Webinar API
	$api_endpoint = 'https://api.zoom.us/v2/webinars/{webinar_id}/registrants';
	$api_key      = 'your_api_key';
	$api_secret   = 'your_api_secret';

	$data = array(
		'email' => $customer_email,
		// Add any additional parameters as needed
		// ...
	);

	$headers = array(
		'Authorization' => 'Bearer ' . base64_encode( $api_key . ':' . $api_secret ),
		'Content-Type'  => 'application/json',
	);

	// Make the API call using a library like cURL or wp_remote_post()
	// ...

	// Return the API response
	// ...
}


// Add meta box to site options page
add_action( 'admin_menu', 'woom_add_site_options_meta_box' );
function woom_add_site_options_meta_box() {
	add_options_page( 'Woom Options', 'Woom Options', 'manage_options', 'woom_site_options', 'woom_render_site_options_meta_box' );
}

function woom_render_site_options_meta_box() {
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
					<th scope="row">Zoom Client Key</th>
					<td><input type="text" name="woom_client_key" value="<?php echo esc_attr( $client_key ); ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">Zoom Client Secret</th>
					<td><input type="password" name="woom_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" /></td>
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
	register_setting( 'woom_site_options_group', 'woom_client_key' );
	register_setting( 'woom_site_options_group', 'woom_client_secret' );
}
