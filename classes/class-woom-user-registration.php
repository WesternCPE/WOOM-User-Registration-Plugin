<?php

/**
 * Class WCPE_GROUPS_KLAVIYO
 */
class WOOM_USER_REGISTRATION {

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
		// add_action( 'woocommerce_checkout_order_processed', array( $this, 'woom_schedule_cron_task' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'woom_schedule_cron_task' ) );

		// Cron task callback function
		add_action( 'woom_cron_task', array( $this, 'woom_process_cron_task' ), 10, 3 );

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

		// zoom_registration_link
		add_shortcode( 'woom_registration_link', array( $this, 'woom_registration_link' ) );
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
			webinar_id BIGINT(16) NOT NULL,
			cron_date DATETIME NOT NULL,
			calling_function TEXT,
			request_data TEXT,
			response_data TEXT,
			request_headers TEXT,
			bearer_token TEXT,
			bearer_token_status_code TEXT,
			response_status_code TEXT,
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

				if ( WOOM_LOGGING ) {
					$user_id = $order->get_customer_id();
					$this->create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id, __METHOD__ );
				}

				$start_date = get_post_meta( $product_id, WOOM_PRODUCT_START_TIME_META, true );
				// sets time to 12:00 am
				$date               = gmdate( 'Y-m-d', $start_date );
				$midnight_timestamp = strtotime( $date );

				// Aftern midnight on day of event
				if ( $midnight_timestamp < time() ) {
					// directly call the cron task
					$this->woom_process_cron_task( $order_id, $item_id, 0 );
				}
			}
		}
	}

	public function run_woom_process_cron_task() {
		// $order_id = 369877;
		$item_id = 612324;

		$item       = new WC_Order_Item_Product( $item_id );
		$order_id   = $item->get_order_id();
		$product_id = $item->get_product_id();
		$order      = wc_get_order( $order_id );
		$user_id    = $order->get_customer_id();

		// woom_process_cron_task( $order_id, $item_id );
		$timestamp = strtotime( '+1 minute' );
		wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, 0 ) );

		$webinar_id = get_post_meta( $product_id, 'woom_webinar_id', true );

		$this->create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id, __METHOD__ );
	}

	// $this->create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id, __METHOD__ );
	public function create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id, $calling_function = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woom_logging';

		$data = array(
			'order_id'         => $order_id,
			'item_id'          => $item_id,
			'product_id'       => $product_id,
			'user_id'          => $user_id,
			'webinar_id'       => $webinar_id,
			'cron_date'        => gmdate( 'Y-m-d H:i:s' ),
			'calling_function' => $calling_function,
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

	public function woom_process_cron_task( $order_id, $item_id, $previous_status_code = 0 ) {

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
				$this->create_woom_logging_entry( $order_id, $item_id, $product_id, $user_id, $webinar_id, __METHOD__ );
			}

			$found        = false;
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

				$status_code = wp_remote_retrieve_response_code( $response );

				if ( WOOM_DEBUG ) {
					error_log( 'Status Code: ' . $status_code );
				}

				if ( is_wp_error( $response ) ) {
					// Handle error
					$timestamp = strtotime( '+10 minute' );
					wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, -1 ) );

					$error_string = $response->get_error_message();

					if ( WOOM_DEBUG ) {
						error_log( 'bearer_token: ' . $error_string );
					}

					if ( WOOM_LOGGING ) {
						$data = array(
							'bearer_token' => $error_string,
						);
						$this->update_woom_logging_entry( $data );
					}

					return;

				} else {

					if ( 200 === $status_code ) {
						$body = wp_remote_retrieve_body( $response );
						$data = json_decode( $body, true );

						if ( isset( $data['access_token'] ) ) {
							$bearer_token = $data['access_token'];
							// Use the bearer token for further API calls

							// Store the bearer token in cache
							$wp_cache_set = wp_cache_set( 'bearer_token_' . base64_encode( $client_key . ':' . $client_secret ), $bearer_token, 'woom_plugin', 3000 );

							if ( WOOM_DEBUG ) {
								error_log( 'bearer_token: ' . $body );
							}

							if ( WOOM_LOGGING ) {
								$data = array(
									'bearer_token' => $body,
									'bearer_token_status_code' => $status_code,
								);
								$this->update_woom_logging_entry( $data );
							}
						} else {
							// Handle error by scheduling the cron task again
							$timestamp = strtotime( '+10 minute' );
							wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, $status_code ) );

							if ( WOOM_DEBUG ) {
								error_log( 'bearer_token: ' . $body );
							}

							if ( WOOM_LOGGING ) {
								$data = array(
									'bearer_token' => $body,
									'bearer_token_status_code' => $status_code,
								);
								$this->update_woom_logging_entry( $data );
							}
							return;
						}
					} else {
						// Handle error by scheduling the cron task again
						$timestamp = strtotime( '+10 minute' );
						wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, $status_code ) );

						if ( WOOM_DEBUG ) {
							error_log( 'bearer_token: ' . $body );
						}

						if ( WOOM_LOGGING ) {
							$data = array(
								'bearer_token'             => $body,
								'bearer_token_status_code' => $status_code,
							);
							$this->update_woom_logging_entry( $data );
						}
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

				$timestamp = strtotime( '+10 minute' );
				wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, $status_code ) );

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

				$timestamp = strtotime( '+10 minute' );
				wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, $status_code ) );

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

				$error_message = 'Join URL Already Exists';

				if ( WOOM_DEBUG ) {
					error_log( 'join_url: ' . $error_message );
				}

				if ( WOOM_LOGGING ) {
					$data = array(
						'join_url'        => $join_url,
						'request_headers' => $error_message,
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
				$timestamp = strtotime( '+10 minute' );
				wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, -1 ) );

				$error_string = $response->get_error_message();

				if ( WOOM_DEBUG ) {
					error_log( 'Response: ' . $error_string );
				}

				// $status_code = wp_remote_retrieve_response_code( $response );

				if ( WOOM_LOGGING ) {
					$data = array(
						'response_data' => $error_string,
						// Status code not available on wp_error
						// 'response_status_code' => $status_code,
					);
					$this->update_woom_logging_entry( $data );
				}

				return false;

			} else {
				$body = wp_remote_retrieve_body( $response );

				$status_code = wp_remote_retrieve_response_code( $response );

				if ( WOOM_DEBUG ) {
					error_log( 'Status Code: ' . $status_code );
				}

				if ( 201 === $status_code ) {

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
								'join_url'             => $join_url,
								'response_data'        => json_encode( $response_data ),
								'response_status_code' => $status_code,
							);

							$this->update_woom_logging_entry( $data );
						}

						// Call the action to handle the join URL
						do_action( 'handle_join_url', $order_id, $item_id, $user_id, $join_url );

					} else {
						// Handle error by scheduling the cron task again
						$timestamp = strtotime( '+10 minute' );
						wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );

						$error_message = 'Join URL Not Found in Response';
						if ( WOOM_LOGGING ) {
							$data = array(
								'join_url'             => $error_message,
								'response_data'        => $body,
								'response_status_code' => $status_code,
							);

							$this->update_woom_logging_entry( $data );
						}
						return;
					}
				} elseif ( 429 === $status_code ) {
					// Handle error by scheduling the cron task again

					if ( WOOM_DEBUG ) {
						error_log( 'Response: ' . $body );
					}

					// Get the midnight of the next day (API resets at 00:00 UTC)
					$timestamp = strtotime( 'tomorrow 11:00' );

					$start_date = get_post_meta( $product_id, WOOM_PRODUCT_START_TIME_META, true );

					if ( $timestamp < $start_date ) {
						wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id, 429 ) );

					} else {
						// Don't bother scheduling the cron task, as would be past event

					}

					$error_message = 'Exceed a rate limit, the API request will fail and return a HTTP 429 status code';

					if ( WOOM_DEBUG ) {
						error_log( 'Product ID: ' . $product_id );
						error_log( WOOM_PRODUCT_START_TIME_META );
						error_log( 'Start Date: ' . $start_date );
						error_log( 'Timestamp: ' . $timestamp );
					}

					if ( WOOM_LOGGING ) {
						$data = array(
							'join_url'             => $error_message,
							'response_data'        => $body,
							'response_status_code' => $status_code,
						);

						$this->update_woom_logging_entry( $data );
					}
					return;

				} else {

					// Handle error by scheduling the cron task again
					$timestamp = strtotime( '+10 minute' );
					wp_schedule_single_event( $timestamp, 'woom_cron_task', array( $order_id, $item_id ) );

					$error_message = 'Join URL Not Found in Response';
					if ( WOOM_LOGGING ) {
						$data = array(
							'join_url'             => $error_message,
							'response_data'        => json_encode( $response_data ),
							'response_status_code' => $status_code,
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
		// test call for checkout hook
		// $this->woom_schedule_cron_task( 369888 );
		// $this->woom_schedule_cron_task( 369889 );

		register_setting( 'woom_site_options_group', 'woom_account_id' );
		register_setting( 'woom_site_options_group', 'woom_client_key' );
		register_setting( 'woom_site_options_group', 'woom_client_secret' );
	}

	public function woom_registration_link( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
				'user_id'    => 0,
			),
			$atts,
			'woom_registration_link'
		);

		if ( 0 === intval( $atts['user_id'] ) ) {
			$user_id = get_current_user_id();
		} else {
			$user_id = $atts['user_id'];
		}

		if ( 0 === intval( $atts['product_id'] ) ) {
			$product_id = get_the_ID();
		} else {
			$product_id = intval( $atts['product_id'] );
		}

		$results = '';

		if ( 0 < $user_id && 0 < $product_id ) {

			$join_url = get_user_meta( $user_id, 'product_' . $product_id . '_join_url', true );

			$webinar_id = get_post_meta( $product_id, 'woom_webinar_id', true );

			$start_date = get_post_meta( $product_id, WOOM_PRODUCT_START_TIME_META, true );

			// sets time to 12:00 am
			$date               = gmdate( 'Y-m-d', $start_date );
			$midnight_timestamp = strtotime( $date );

			// Before midnight and don't have a join link
			if ( ! $join_url && $midnight_timestamp > time() ) {
				if ( '' !== $content ) {
					return $content;
				} else {
					return 'Please check back later for the join link.';
				}
			}

			// $results  = '<div ';
			// $results .= 'data-user_id="' . $user_id . '" ';
			// $results .= 'data-product_id="' . $product_id . '" ';
			// $results .= 'data-join_url="' . $join_url . '" ';
			// $results .= 'data-start_date="' . $start_date . '" ';
			// $results .= 'data-start_date_midnight="' . $date . '" ';
			// $results .= 'data-start_timestamp_midnight="' . $midnight_timestamp . '" ';
			// $results .= 'data-current_timestamp="' . time() . '" ';
			// $results .= '>';

			if ( $join_url ) {

				$results .= '<a href="' . $join_url . '" class="join-live-webcast-link" target="_BLANK" data-product_id="' . $product_id . '" target="_BLANK">Join Live Webcast</a>';
				// QR Image Code
				// $results .= '<div style="margin-top: 5px; text-align: center;"><img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $join_url ) . '" /></div>';
			} else {
				$results .= '<a href="https://us06web.zoom.us/webinar/register/' . $webinar_id . '" class="join-live-webcast-link" target="_BLANK" data-product_id="' . $product_id . '" target="_BLANK">Join Live Webcast</a>';

			}

			// $results .= '</div>';
		}

		return $results;
	}
}


// $woom_user_registration_plugin = new WOOM_USER_REGISTRATION();
