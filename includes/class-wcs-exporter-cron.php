<?php
/**
 * Subscription Export CSV with Cron Class
 *
 * @since 1.0
 */
class WCS_Exporter_Cron {

    private static $payment = null;

    public static $cron_dir = WP_CONTENT_DIR . '/uploads/woocommerce-subscriptions-importer-exporter';

    /**
	 * Check params sent and start the export file writing.
	 *
	 * @since 2.0-beta
	 * @param array $post_data
	 * @param array $headers
	 */
    public static function cron_handler( $post_data, $headers ) {

		self::create_upload_directory();

        $done_export = false;

        $subscriptions = self::get_subscriptions_to_export( $post_data );
        $subscriptions_count = count($subscriptions);

        $file_path = self::$cron_dir . '/' . $post_data['filename'];

        // Determine export format
        $export_format = isset( $post_data['export_format'] ) ? $post_data['export_format'] : 'standard';

        if ( ! empty( $subscriptions ) ) {

            $file = fopen($file_path, 'a');

            WCS_Exporter::$file = $file;
            WCS_Exporter::$headers = $headers;

            // Initialize Shopify API if credentials are provided
            if ( ! empty( $post_data['shopify_store_url'] ) && ! empty( $post_data['shopify_access_token'] ) ) {
                $storefront_url = ! empty( $post_data['shopify_storefront_url'] ) ? $post_data['shopify_storefront_url'] : '';
                $shopify_api = new WCS_Shopify_API( 
                    $post_data['shopify_store_url'], 
                    $post_data['shopify_access_token'],
                    $storefront_url
                );
                WCS_Exporter::set_shopify_api( $shopify_api );
            }

            if ( 'appstle' === $export_format ) {
                // Appstle Quick Checkout format
                if ( $post_data['offset'] == 0 ) {
                    WCS_Exporter::write_appstle_headers();
                }

                foreach ( $subscriptions as $subscription ) {
                    WCS_Exporter::write_appstle_csv_row( $subscription );
                }
            } else {
                // Standard WooCommerce format
                if ( $post_data['offset'] == 0 ) {
                    WCS_Exporter::write_headers( $headers );
                }

                foreach ( $subscriptions as $subscription ) {
                    WCS_Exporter::write_subscriptions_csv_row( $subscription );
                }
            }

            if ( $subscriptions_count == $post_data['limit'] ) {

                $post_data['offset'] = $post_data['offset'] + $post_data['limit'];

                $event_args = array(
        			'post_data' => $post_data,
        			'headers' => $headers
        		);

        		wp_schedule_single_event( time() + 60, 'wcs_export_cron', $event_args );

            } else {
                $done_export = true;
            }

        } else {
            $done_export = true;
        }

        if ( $done_export === true ) {
            $final_file_path = str_replace('.tmp', '', $file_path);
            rename($file_path, $final_file_path);
            
            // Send notification email if configured
            if ( ! empty( $post_data['notification_email'] ) ) {
                self::send_completion_notification( $post_data['notification_email'], $post_data['filename'], $final_file_path );
            }
        }

        fclose($file);
    }

    /**
     * Send email notification when export completes
     *
     * @since 2.1.0
     * @param string $email
     * @param string $filename
     * @param string $file_path
     */
    public static function send_completion_notification( $email, $filename, $file_path ) {
        $site_name = get_bloginfo( 'name' );
        $final_filename = str_replace( '.tmp', '', $filename );
        
        // Get download URL
        $upload_dir = wp_upload_dir();
        $download_url = $upload_dir['baseurl'] . '/woocommerce-subscriptions-importer-exporter/' . $final_filename;
        
        // Get cron exports page URL
        $cron_page_url = add_query_arg( 
            array( 
                'page' => 'export_subscriptions',
                'tab'  => 'wcsi-cron-exports'
            ), 
            admin_url( 'admin.php' ) 
        );
        
        // Email subject
        $subject = sprintf( 
            /* translators: %s: site name */
            __( '[%s] Subscription Export Completed', 'wcs-import-export' ), 
            $site_name 
        );
        
        // Email body
        $message = sprintf( 
            /* translators: %s: site name */
            __( 'Your subscription export on %s has completed successfully.', 'wcs-import-export' ), 
            $site_name 
        ) . "\n\n";
        
        $message .= __( 'Export Details:', 'wcs-import-export' ) . "\n";
        $message .= '-----------------------------------' . "\n";
        $message .= sprintf( __( 'Filename: %s', 'wcs-import-export' ), $final_filename ) . "\n";
        $message .= sprintf( __( 'Completed at: %s', 'wcs-import-export' ), current_time( 'mysql' ) ) . "\n\n";
        
        $message .= __( 'Download your export file:', 'wcs-import-export' ) . "\n";
        $message .= $download_url . "\n\n";
        
        $message .= __( 'View all cron exports:', 'wcs-import-export' ) . "\n";
        $message .= $cron_page_url . "\n\n";
        
        $message .= '-----------------------------------' . "\n";
        $message .= __( 'Note: This is an automated message. Please do not reply.', 'wcs-import-export' ) . "\n";
        
        // Send email
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        
        wp_mail( $email, $subject, $message, $headers );
    }

    /**
	 * Query the subscriptions using the users specific filters.
	 *
	 * @since 2.0-beta
	 * @return array
	 */
    public static function get_subscriptions_to_export( $post_data ) {

        $args = array(
			'subscriptions_per_page' => ! empty( $post_data['limit'] ) ? absint( $post_data['limit'] ) : -1,
			'offset'                 => isset( $post_data['offset'] ) ? $post_data['offset'] : 0,
			'product'                => isset( $post_data['product'] ) ? $post_data['product'] : '',
			'subscription_status'    => 'none'
		);

		if ( ! empty( $post_data['status'] ) ) {
			$args['subscription_status'] = array_keys( $post_data['status'] );
		}

		if ( ! empty( $post_data['customer'] ) && is_numeric( $post_data['customer'] ) ) {
			$args['customer_id'] = $post_data['customer'];
		}

		if ( ! empty( $post_data['payment'] ) ) {
            if ( $post_data['payment'] !== 'any' ) {
                self::$payment = ( 'none' == $post_data['payment'] ) ? '' : $post_data['payment'];
			    add_filter( 'woocommerce_get_subscriptions_query_args', array( 'WCS_Exporter_Cron', 'filter_payment_method' ), 10, 2 );
            }
		}

        return wcs_get_subscriptions( $args );

    }

    /**
	 * Filter the query in @see wcs_get_subscriptions() by payment method.
	 *
	 * @since 2.0-beta
	 * @param array $query_args
	 * @param array $args
	 * @return array
	 */
	public static function filter_payment_method( $query_args, $args ) {

		if ( self::$payment !== null ) {

			$query_args['meta_query'][] = array(
				'key'   => '_payment_method',
				'value' => self::$payment
			);

		}

		return $query_args;
	}

    /**
	 * Delete subscription export file, including the tmp file if it exists.
	 *
	 * @since 2.0-beta
	 * @param string $file_name
	 * @return array
	 */
    public static function delete_export_file( $file_name )  {
        if ( $file_name == '' ) {
            return;
        }

        $file_path = self::$cron_dir . '/' . $file_name;
        if ( file_exists($file_path) ) {
            unlink($file_path);
        }

		if ( false !== strpos( $file_name, '.tmp' ) && file_exists( $file_path = str_replace( '.tmp', '', $file_path ) ) ) {
			unlink( $file_path );
		}
    }

	/**
	 * Creates upload directory. Does nothing if directory and index/htaccess files already exist
	 *
	 * @since 2.1
	 * @return void
	 */
	public static function create_upload_directory() {
		$base = self::$cron_dir;

		if ( wp_mkdir_p( $base ) ) {
			// create an empty index.php
			if ( ! file_exists( $base . '/index.php' ) ) {
				file_put_contents( $base . '/index.php', '' );
			}

			// create a .htaccess file with "deny from all" command
			if ( ! file_exists( $base . '/.htaccess' ) ) {
				file_put_contents( $base . '/.htaccess', 'Deny from all' );
			}
		}
	}
}
