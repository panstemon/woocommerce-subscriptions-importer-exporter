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
        $site_url = get_bloginfo( 'url' );
        $final_filename = str_replace( '.tmp', '', $filename );
        $admin_email = get_option( 'admin_email' );
        
        // Get file size
        $file_size = file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : __( 'Unknown', 'wcs-import-export' );
        
        // Get secure download URL via WordPress AJAX endpoint
        $download_url = WCS_Export_Admin::get_secure_download_url( $final_filename, 'cron' );
        
        // Get exports page URL
        $exports_page_url = add_query_arg( 
            array( 
                'page' => 'export_subscriptions',
                'tab'  => 'wcsi-exports'
            ), 
            admin_url( 'admin.php' ) 
        );
        
        // Email subject
        $subject = sprintf( 
            /* translators: %s: site name */
            __( '[%s] Subscription Export Completed', 'wcs-import-export' ), 
            $site_name 
        );
        
        // HTML Email body
        $message = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f5f5f5; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #7f54b3; padding: 30px 40px; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                ✓ ' . esc_html__( 'Export Completed', 'wcs-import-export' ) . '
                            </h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 20px; color: #333333; font-size: 16px; line-height: 1.5;">
                                ' . sprintf( esc_html__( 'Your subscription export on %s has completed successfully.', 'wcs-import-export' ), '<strong>' . esc_html( $site_name ) . '</strong>' ) . '
                            </p>
                            
                            <!-- Export Details Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8f9fa; border-radius: 6px; margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h3 style="margin: 0 0 15px; color: #333333; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">
                                            ' . esc_html__( 'Export Details', 'wcs-import-export' ) . '
                                        </h3>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 8px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #e0e0e0;">
                                                    ' . esc_html__( 'Filename', 'wcs-import-export' ) . '
                                                </td>
                                                <td style="padding: 8px 0; color: #333333; font-size: 14px; text-align: right; border-bottom: 1px solid #e0e0e0;">
                                                    <strong>' . esc_html( $final_filename ) . '</strong>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #e0e0e0;">
                                                    ' . esc_html__( 'File Size', 'wcs-import-export' ) . '
                                                </td>
                                                <td style="padding: 8px 0; color: #333333; font-size: 14px; text-align: right; border-bottom: 1px solid #e0e0e0;">
                                                    ' . esc_html( $file_size ) . '
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #666666; font-size: 14px;">
                                                    ' . esc_html__( 'Completed', 'wcs-import-export' ) . '
                                                </td>
                                                <td style="padding: 8px 0; color: #333333; font-size: 14px; text-align: right;">
                                                    ' . esc_html( current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Download Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 20px;">
                                <tr>
                                    <td align="center">
                                        <a href="' . esc_url( $download_url ) . '" style="display: inline-block; background-color: #7f54b3; color: #ffffff; text-decoration: none; padding: 16px 40px; border-radius: 6px; font-size: 16px; font-weight: 600;">
                                            ⬇ ' . esc_html__( 'Download CSV File', 'wcs-import-export' ) . '
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Secondary Link -->
                            <p style="margin: 0; text-align: center;">
                                <a href="' . esc_url( $exports_page_url ) . '" style="color: #7f54b3; text-decoration: none; font-size: 14px;">
                                    ' . esc_html__( 'View all exports in WordPress admin', 'wcs-import-export' ) . ' →
                                </a>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0; color: #999999; font-size: 12px; text-align: center;">
                                ' . esc_html__( 'This is an automated message from', 'wcs-import-export' ) . ' <a href="' . esc_url( $site_url ) . '" style="color: #7f54b3; text-decoration: none;">' . esc_html( $site_name ) . '</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        // Email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
        );
        
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
