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


if ( ! defined( 'WOOM_USER_REGISTRATION_VERSION' ) ) {
	define( 'WOOM_USER_REGISTRATION_VERSION', '2024.1.32' );
}

/**
 * Class WCPE_GROUPS_KLAVIYO
 */
class WOOM_USER_REGISTRATION_PLUGIN {

	private $logging_entry_id = false;

	/**************************************************************************
	 * REQUIRED. Set up a constructor that references the parent constructor. We
	 * use the parent reference to set some default configs.
	 ***************************************************************************/
	function __construct() {

		// Add meta box to product edit screen
		add_action( 'add_meta_boxes', array( $this, 'woom_add_webinar_id_meta_box' ), 5 );

		// Save webinar ID meta value
		add_action( 'save_post_product', array( $this, 'woom_save_webinar_id_meta_value' ) );

		// Schedule cron task after WooCommerce checkout
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'woom_schedule_cron_task' ) );

		// Cron task callback function
		add_action( 'woom_cron_task', array( $this, 'woom_process_cron_task' ), 10, 2 );

		// Add meta box to site options page
		add_action( 'admin_menu', array( $this, 'woom_add_site_options_meta_box' ) );

		// Save site options
		add_action( 'admin_init', array( $this, 'woom_save_site_options' ) );

		// Test Action to fire without WooCommerce checkout
		// add_action( 'init', array( $this, 'run_woom_process_cron_task' ) );

		if ( WOOM_LOGGING ) {
			if ( version_compare( get_option( 'woom_user_registration_db_version' ), WOOM_USER_REGISTRATION_VERSION, '<' ) ) {
				// removed 2022.6.7 due to changes in db structure
				$this->activate();
			}
		}
	} // end __construct


	public function activate() {
		// BEGIN: Add table using dbDelta
		global $wpdb;
		$table_name = $wpdb->prefix . 'woom_logging';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			ID INT(11) NOT NULL AUTO_INCREMENT,
			user_id INT(11) NOT NULL,
			order_id INT(11) NOT NULL,
			item_id INT(11) NOT NULL,
			product_id INT(11) NOT NULL,
			webinar_id INT(11) NOT NULL,
			cron_date DATETIME NOT NULL,
			request_data TEXT,
			response_data TEXT,
			request_headers TEXT,
			bearer_token TEXT,
			join_url TEXT,
			PRIMARY KEY (ID)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$results = dbDelta( $sql );
		// END: Add table using dbDelta

		// var_dump( $results );

		// // Print last SQL query string
		// echo $wpdb->last_query;

		// // Print last SQL query result
		// echo $wpdb->last_result;

		// // Print last SQL query Error
		// echo $wpdb->last_error;

		// if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
		//  // Table was not created !!
		//  var_dump( 'Table Created!!' );
		// } else {
		//  var_dump( 'Table FAILED Created!!' );
		// }

		// die();

		update_option( 'woom_user_registration_db_version', WOOM_USER_REGISTRATION_VERSION );
	}

	public function woom_add_webinar_id_meta_box() {
		add_meta_box( 'woom_webinar_id_meta_box', 'Webinar ID', array( $this, 'woom_render_webinar_id_meta_box' ), 'product', 'side', 'default' );
	}

	public function woom_render_webinar_id_meta_box( $post ) {
		$webinar_id = get_post_meta( $post->ID, 'woom_webinar_id', true );
		?>
		<label for="woom_webinar_id">Webinar ID:</label>
		<input type="text" id="woom_webinar_id" name="woom_webinar_id" value="<?php echo esc_attr( $webinar_id ); ?>">
		<?php
	}


	public function woom_save_webinar_id_meta_value( $post_id ) {
		if ( isset( $_POST['woom_webinar_id'] ) ) {
			$webinar_id = sanitize_text_field( $_POST['woom_webinar_id'] );
			update_post_meta( $post_id, 'woom_webinar_id', $webinar_id );
		}
	}


	public function woom_schedule_cron_task( $order_id ) {
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

	public function run_woom_process_cron_task() {
		$order_id = 364676;
		$item_id  = 603366;
		// woom_process_cron_task( $order_id, $item_id );
		$timestamp = strtotime( '+1 minute' );
		wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
	}



	public function create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woom_logging';

		$data = array(
			'order_id'   => $order_id,
			'item_id'    => $item_id,
			'product_id' => $product_id,
			'user_id'    => $user_id,
			'webinar_id' => $webinar_id,
			'cron_date'  => gmdate( 'Y-m-d H:i:s' ),
		);

		$results = $wpdb->insert( $table_name, $data );

		if ( false !== $results ) {
			$this->logging_entry_id = $wpdb->insert_id;
		}
	}

	public function update_woom_logging_entry( $data ) {
		global $wpdb;

		if ( 0 < $this->logging_entry_id ) {
			$table_name = $wpdb->prefix . 'woom_logging';
			$results    = $wpdb->update( $table_name, $data, array( 'ID' => $this->logging_entry_id ) );

			// var_dump( $results );

			// // Print last SQL query string
			// echo $wpdb->last_query;

			// // Print last SQL query result
			// echo $wpdb->last_result;

			// // Print last SQL query Error
			// echo $wpdb->last_error;

			// return $results;
		}
	}

	public function woom_process_cron_task( $order_id, $item_id ) {

		$account_id    = get_option( 'woom_account_id' );
		$client_key    = get_option( 'woom_client_key' );
		$client_secret = get_option( 'woom_client_secret' );

		// $item       = wc_get_order_item( $item_id );
		$item       = new WC_Order_Item_Product( $item_id );
		$product_id = $item->get_product_id();
		$webinar_id = get_post_meta( $product_id, 'woom_webinar_id', true );

		if ( WOOM_DEBUG ) {
			error_log( 'woom_process_cron_task: ' . $order_id . ', ' . $item_id . ', ' . $product_id . ', ' . $webinar_id );
		}

		if ( ! empty( $webinar_id ) ) {
			// Get the order item from the item ID

			$order = wc_get_order( $order_id );

			// Get the Customer ID (User ID)
			$user_id = $order->get_customer_id();

			if ( WOOM_LOGGING ) {

				$this->create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id );
			}

			$bearer_token = wp_cache_get( 'bearer_token_' . base64_encode( $client_key . ':' . $client_secret ), 'woom_plugin', false, $found );

			// $found
			// bool
			// optional
			// Whether the key was found in the cache (passed by reference).
			// Disambiguates a return of false, a storable value.

			if ( $found ) {
				$token_data = json_decode( base64_decode( str_replace( '_', '/', str_replace( '-', '+', explode( '.', $bearer_token )[1] ) ) ) );

				if ( time() > $token_data->exp ) {
					$error_message = sprintf( 'Token Expired [difference: %s] [token: %d] [time: %d]', human_time_diff( $token_data->exp, time() ), $token_data->exp, time() );
					if ( WOOM_DEBUG ) {
						error_log( 'bearer_token: ' . $error_message );
					}

					if ( WOOM_LOGGING ) {
						$data = array(
							'bearer_token' => $error_message,
						);

						$this->update_woom_logging_entry( $data );
					}
					$found = false;
				} else {
					$error_message = sprintf( 'Token Valid [difference: %s] [token: %d] [time: %d]', human_time_diff( $token_data->exp, time() ), $token_data->exp, time() );
					if ( WOOM_DEBUG ) {
						error_log( 'bearer_token: ' . $error_message );
					}
				}
			} else {
				$error_message = 'Token Not Found in Cache';
				if ( WOOM_DEBUG ) {
					error_log( 'bearer_token: ' . $error_message );
				}
			}

			// if not found in cache or expired
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

						// Store the bearer token in cache
						wp_cache_set( 'bearer_token_' . base64_encode( $client_key . ':' . $client_secret ), $bearer_token, 'woom_plugin', 3000 );

					} else {
						// Handle error by scheduling the cron task again
						$timestamp = strtotime( '+1 minute' );
						wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
						return;
					}
				}
			}

			if ( WOOM_DEBUG ) {
				error_log( 'bearer_token: ' . $bearer_token );
				// error_log( base64_decode( str_replace( '_', '/', str_replace( '-', '+', explode( '.', $bearer_token )[1] ) ) ) );
			}

			$token_data = json_decode( base64_decode( str_replace( '_', '/', str_replace( '-', '+', explode( '.', $bearer_token )[1] ) ) ) );

			if ( time() > $token_data->exp ) {
				$error_message = sprintf( 'Token Expired [difference: %s] [token: %d] [time: %d]', human_time_diff( $token_data->exp, time() ), $token_data->exp, time() );
				if ( WOOM_DEBUG ) {
					error_log( 'bearer_token: ' . $error_message );
				}

				if ( WOOM_LOGGING ) {
					$data = array(
						'bearer_token' => $error_message,
					);
					$this->update_woom_logging_entry( $data );
				}
				return false;
			} else {
				$error_message = sprintf( 'Token Valid [difference: %s] [token: %d] [time: %d]', human_time_diff( $token_data->exp, time() ), $token_data->exp, time() );
				if ( WOOM_DEBUG ) {
					error_log( 'bearer_token: ' . $error_message );
				}
			}

			if ( $account_id !== $token_data->aid ) {
				$error_message = 'Invalid Account ID';
				if ( WOOM_DEBUG ) {
					error_log( 'bearer_token: ' . $error_message );
				}

				if ( WOOM_LOGGING ) {
					$data = array(
						'bearer_token' => $error_message,
					);
					$this->update_woom_logging_entry( $data );
				}
				return false;
			}

			if ( WOOM_LOGGING ) {
				$data = array(
					'bearer_token' => $bearer_token,
				);

				$this->update_woom_logging_entry( $data );
			}

			// Call POST user bulk registration endpoint
			// Avoid duplicate ZOOM API URL calls
			if ( ! empty( wc_get_order_item_meta( $item_id, 'product_' . $product_id . '_join_url', true ) ) ) {
				// Handle error by scheduling the cron task

				$join_url = wc_get_order_item_meta( $item_id, 'product_' . $product_id . '_join_url', true );

				if ( WOOM_LOGGING ) {
					$data = array(
						'join_url' => $join_url,
					);

					$this->update_woom_logging_entry( $data );
				}

				do_action( 'handle_join_url', $order_id, $item_id, $user_id, $join_url );
				return;
			}

			// Store the response and perform any necessary actions
			// Make the API call to register the attendee for the Zoom Webinar
			$api_endpoint = 'https://api.zoom.us/v2/webinars/' . $webinar_id . '/registrants';

			// $user = get_userdata( get_post_field( 'post_author', $order_id ) );
			$order = wc_get_order( $order_id );

			// Get the Customer ID (User ID)
			$user_id = $order->get_customer_id();

			$user = get_userdata( $user_id );

			$email      = $user->user_email;
			$first_name = $user->first_name;
			$last_name  = $user->last_name;

			$request_data = array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				// Add any additional parameters as needed
				// ...
			);

			if ( WOOM_DEBUG ) {
				error_log( 'data: ' . print_r( $request_data, true ) );
			}
			if ( WOOM_LOGGING ) {
				$data = array(
					'request_data' => json_encode( $request_data ),
				);
				$this->update_woom_logging_entry( $data );
			}

			$request_headers = array(
				'Authorization' => 'Bearer ' . $bearer_token,
				'Content-Type'  => 'application/json',
			);

			if ( WOOM_DEBUG ) {
				error_log( 'Request Headers: ' . print_r( $request_headers, true ) );
			}
			if ( WOOM_LOGGING ) {
				$data = array(
					'request_headers' => json_encode( $request_headers ),
				);
				$this->update_woom_logging_entry( $data );
			}

			$response = wp_remote_post(
				$api_endpoint,
				array(
					'headers' => $request_headers,
					'body'    => json_encode( $request_data ),
				)
			);

			if ( is_wp_error( $response ) ) {
				// Handle error
				// Handle error by scheduling the cron task again
				// $timestamp = strtotime( '+1 minute' );
				// wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );

				$error_string = $response->get_error_message();

				if ( WOOM_DEBUG ) {
					error_log( 'Response: ' . $error_string );
				}

				if ( WOOM_LOGGING ) {
					$data = array(
						'response_data' => $error_string,
					);
					$this->update_woom_logging_entry( $data );
				}

				return;

			} else {
				$body          = wp_remote_retrieve_body( $response );
				$response_data = json_decode( $body, true );

				if ( WOOM_DEBUG ) {
					error_log( 'Response: ' . print_r( $response_data, true ) );
				}

				if ( isset( $response_data['join_url'] ) ) {
					$join_url = $response_data['join_url'];

					// Use the bearer token for further API calls

					// Store the join_url in user meta
					delete_user_meta( $user_id, 'product_' . $product_id . '_join_url' );
					update_user_meta( $user_id, 'product_' . $product_id . '_join_url', $join_url );

					// Store the join_url in order item meta
					wc_delete_order_item_meta( $item_id, 'product_' . $product_id . '_join_url' );
					wc_add_order_item_meta( $item_id, 'product_' . $product_id . '_join_url', $join_url );

					if ( WOOM_LOGGING ) {
						$data = array(
							'join_url'      => $join_url,
							'response_data' => json_encode( $response_data ),
						);

						$this->update_woom_logging_entry( $data );
					}

					// Call the action to handle the join URL
					do_action( 'handle_join_url', $order_id, $item_id, $user_id, $join_url );

				} else {
					// Handle error by scheduling the cron task again
					// $timestamp = strtotime( '+1 minute' );
					// wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );
					$error_message = 'Join URL Not Found in Response';
					if ( WOOM_LOGGING ) {
						$data = array(
							'join_url'      => $error_message,
							'response_data' => json_encode( $response_data ),
						);

						$this->update_woom_logging_entry( $data );
					}
					return;
				}
			}
		}
	}



	public function woom_add_site_options_meta_box() {
		add_options_page( 'Woom Options', 'Woom Options', 'manage_options', 'woom_site_options', array( $this, 'woom_render_site_options_meta_box' ) );
	}

	public function woom_render_site_options_meta_box() {
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


	public function woom_save_site_options() {
		register_setting( 'woom_site_options_group', 'woom_account_id' );
		register_setting( 'woom_site_options_group', 'woom_client_key' );
		register_setting( 'woom_site_options_group', 'woom_client_secret' );
	}
}


$woom_user_registration_plugin = new WOOM_USER_REGISTRATION_PLUGIN();
