<?php
/**
 * Shopify Migration Settings for WooCommerce
 *
 * Registers Shopify settings in WooCommerce > Settings > Advanced tab
 *
 * @since 2.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_Shopify_Settings {

	/**
	 * Initialize the settings
	 */
	public static function init() {
		// Add settings section to WooCommerce > Settings > Advanced
		add_filter( 'woocommerce_get_sections_advanced', array( __CLASS__, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( __CLASS__, 'get_settings' ), 10, 2 );
		
		// Add row action to subscriptions list
		add_filter( 'woocommerce_subscription_list_table_actions', array( __CLASS__, 'add_quick_checkout_action' ), 10, 2 );
		
		// Handle the quick checkout link generation via AJAX
		add_action( 'wp_ajax_wcs_generate_quick_checkout', array( __CLASS__, 'ajax_generate_quick_checkout' ) );
		
		// Add admin scripts for the modal
		add_action( 'admin_footer', array( __CLASS__, 'add_admin_scripts' ) );
		
		// Add metabox to single subscription page
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_quick_checkout_metabox' ) );
	}

	/**
	 * Add Shopify Migration section to WooCommerce Advanced settings
	 *
	 * @param array $sections Existing sections
	 * @return array Modified sections
	 */
	public static function add_section( $sections ) {
		$sections['shopify_migration'] = __( 'Shopify Migration', 'wcs-import-export' );
		return $sections;
	}

	/**
	 * Get Shopify Migration settings
	 *
	 * @param array $settings Existing settings
	 * @param string $current_section Current section ID
	 * @return array Settings for the section
	 */
	public static function get_settings( $settings, $current_section ) {
		if ( 'shopify_migration' !== $current_section ) {
			return $settings;
		}

		$shopify_settings = array(
			array(
				'title' => __( 'Shopify Migration Settings', 'wcs-import-export' ),
				'type'  => 'title',
				'desc'  => __( 'Configure your Shopify store credentials for subscription migration and Quick Checkout link generation.', 'wcs-import-export' ),
				'id'    => 'wcs_shopify_migration_settings',
			),
			array(
				'title'    => __( 'Shopify Store URL', 'wcs-import-export' ),
				'desc'     => __( 'Enter your Shopify Admin API URL (e.g., mystore.myshopify.com)', 'wcs-import-export' ),
				'id'       => 'wcs_shopify_store_url',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 350px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Shopify Access Token', 'wcs-import-export' ),
				'desc'     => __( 'Enter your Shopify Admin API access token (starts with shpat_)', 'wcs-import-export' ),
				'id'       => 'wcs_shopify_access_token',
				'type'     => 'password',
				'default'  => '',
				'css'      => 'min-width: 350px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Shopify Storefront URL', 'wcs-import-export' ),
				'desc'     => __( 'Enter your Shopify storefront URL for Quick Checkout links (e.g., mystore.com). Leave empty to use Admin API URL.', 'wcs-import-export' ),
				'id'       => 'wcs_shopify_storefront_url',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 350px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Default Discount Code', 'wcs-import-export' ),
				'desc'     => __( 'Default discount code to include in Quick Checkout links (optional)', 'wcs-import-export' ),
				'id'       => 'wcs_shopify_discount_code',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 350px;',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcs_shopify_migration_settings',
			),
			array(
				'title' => __( 'Setup Instructions', 'wcs-import-export' ),
				'type'  => 'title',
				'desc'  => self::get_setup_instructions_html(),
				'id'    => 'wcs_shopify_setup_instructions',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcs_shopify_setup_instructions',
			),
		);

		return $shopify_settings;
	}

	/**
	 * Get HTML for setup instructions
	 *
	 * @return string HTML
	 */
	private static function get_setup_instructions_html() {
		ob_start();
		?>
		<div style="background: #f9f9f9; border-left: 4px solid #0073aa; padding: 15px; margin-top: 10px;">
			<strong><?php esc_html_e( 'Important:', 'wcs-import-export' ); ?></strong> <?php esc_html_e( 'To use Shopify integration, you must enable the woo.id metafield for filtering in Shopify Admin:', 'wcs-import-export' ); ?>
			<ol style="margin: 10px 0 10px 20px;">
				<li><?php esc_html_e( 'Go to Shopify Admin → Settings → Custom data', 'wcs-import-export' ); ?></li>
				<li><?php esc_html_e( 'Click on Products (and/or Variants)', 'wcs-import-export' ); ?></li>
				<li><?php esc_html_e( 'Find and click on the woo.id metafield definition', 'wcs-import-export' ); ?></li>
				<li><?php esc_html_e( 'Enable "Storefront Filtering" option and save', 'wcs-import-export' ); ?></li>
			</ol>
			<p style="margin-bottom: 0;"><?php esc_html_e( 'Without this, product matching via GraphQL will not work correctly.', 'wcs-import-export' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get Shopify API instance using saved settings
	 *
	 * @return WCS_Shopify_API|null
	 */
	public static function get_shopify_api() {
		$store_url      = get_option( 'wcs_shopify_store_url', '' );
		$access_token   = get_option( 'wcs_shopify_access_token', '' );
		$storefront_url = get_option( 'wcs_shopify_storefront_url', '' );

		if ( empty( $store_url ) || empty( $access_token ) ) {
			return null;
		}

		return new WCS_Shopify_API( $store_url, $access_token, $storefront_url );
	}

	/**
	 * Add Quick Checkout Link action to subscription row actions
	 *
	 * @param array $actions Existing actions
	 * @param WC_Subscription $subscription The subscription object
	 * @return array Modified actions
	 */
	public static function add_quick_checkout_action( $actions, $subscription ) {
		// Only show if Shopify is configured
		$store_url    = get_option( 'wcs_shopify_store_url', '' );
		$access_token = get_option( 'wcs_shopify_access_token', '' );

		if ( empty( $store_url ) || empty( $access_token ) ) {
			return $actions;
		}

		// WooCommerce Subscriptions expects an HTML string, not an array
		// Use URL hash to pass subscription ID since data attributes may be stripped by sanitization
		$actions['quick_checkout'] = sprintf(
			'<a href="#wcs-quick-checkout-%d" class="wc-action-button wc-action-button-quick_checkout quick_checkout" title="%s">%s</a>',
			$subscription->get_id(),
			esc_attr__( 'Generate Shopify Quick Checkout Link', 'wcs-import-export' ),
			esc_html__( 'Quick Checkout', 'wcs-import-export' )
		);

		return $actions;
	}

	/**
	 * AJAX handler for generating Quick Checkout link
	 */
	public static function ajax_generate_quick_checkout() {
		check_ajax_referer( 'wcs_quick_checkout_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wcs-import-export' ) ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;

		if ( ! $subscription_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'wcs-import-export' ) ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			wp_send_json_error( array( 'message' => __( 'Subscription not found.', 'wcs-import-export' ) ) );
		}

		$shopify_api = self::get_shopify_api();

		if ( ! $shopify_api ) {
			wp_send_json_error( array( 'message' => __( 'Shopify API is not configured. Please configure it in WooCommerce → Settings → Advanced → Shopify Migration.', 'wcs-import-export' ) ) );
		}

		// Use Shopify API to build checkout link from subscription
		$discount_code = get_option( 'wcs_shopify_discount_code', '' );
		$result = $shopify_api->build_checkout_link_from_subscription( $subscription, $discount_code );

		if ( ! $result['success'] ) {
			$error_message = implode( "\n", $result['errors'] );
			wp_send_json_error( array( 'message' => $error_message ) );
		}

		$response = array(
			'link'    => $result['link'],
			'message' => __( 'Quick Checkout link generated successfully!', 'wcs-import-export' ),
		);

		if ( ! empty( $result['errors'] ) ) {
			$response['warnings'] = $result['errors'];
		}

		wp_send_json_success( $response );
	}

	/**
	 * Add Quick Checkout metabox to single subscription page
	 */
	public static function add_quick_checkout_metabox() {
		// Check if Shopify is configured
		$store_url    = get_option( 'wcs_shopify_store_url', '' );
		$access_token = get_option( 'wcs_shopify_access_token', '' );

		if ( empty( $store_url ) || empty( $access_token ) ) {
			return;
		}

		// Add metabox for both legacy and HPOS
		add_meta_box(
			'wcs-shopify-quick-checkout',
			__( 'Shopify Quick Checkout', 'wcs-import-export' ),
			array( __CLASS__, 'render_quick_checkout_metabox' ),
			'shop_subscription',
			'side',
			'default'
		);

		// For HPOS
		add_meta_box(
			'wcs-shopify-quick-checkout',
			__( 'Shopify Quick Checkout', 'wcs-import-export' ),
			array( __CLASS__, 'render_quick_checkout_metabox' ),
			'woocommerce_page_wc-orders--shop_subscription',
			'side',
			'default'
		);
	}

	/**
	 * Render Quick Checkout metabox content
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object or order object (for HPOS)
	 */
	public static function render_quick_checkout_metabox( $post_or_order ) {
		// Get subscription ID - handle both legacy and HPOS
		if ( $post_or_order instanceof WP_Post ) {
			$subscription_id = $post_or_order->ID;
		} else {
			$subscription_id = $post_or_order->get_id();
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			echo '<p>' . esc_html__( 'Invalid subscription.', 'wcs-import-export' ) . '</p>';
			return;
		}
		?>
		<div id="wcs-quick-checkout-metabox-content">
			<p><?php esc_html_e( 'Generate a Shopify Quick Checkout link for this subscription.', 'wcs-import-export' ); ?></p>
			<p>
				<a href="#wcs-quick-checkout-<?php echo esc_attr( $subscription_id ); ?>" 
				   class="button button-primary quick_checkout" 
				   id="wcs-generate-quick-checkout-btn">
					<?php esc_html_e( 'Generate Quick Checkout Link', 'wcs-import-export' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Add admin scripts for Quick Checkout modal
	 */
	public static function add_admin_scripts() {
		$screen = get_current_screen();

		// Load on subscriptions list page and single subscription page
		$allowed_screens = array( 'edit-shop_subscription', 'shop_subscription', 'woocommerce_page_wc-orders--shop_subscription' );
		if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		// Check if Shopify is configured
		$store_url    = get_option( 'wcs_shopify_store_url', '' );
		$access_token = get_option( 'wcs_shopify_access_token', '' );

		if ( empty( $store_url ) || empty( $access_token ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'wcs_quick_checkout_nonce' );
		?>
		<style>
			.wcs-quick-checkout-modal {
				display: none;
				position: fixed;
				z-index: 100001;
				left: 0;
				top: 0;
				width: 100%;
				height: 100%;
				background-color: rgba(0,0,0,0.5);
			}
			.wcs-quick-checkout-modal-content {
				background-color: #fff;
				margin: 10% auto;
				padding: 20px;
				border-radius: 4px;
				width: 600px;
				max-width: 90%;
				box-shadow: 0 4px 20px rgba(0,0,0,0.2);
			}
			.wcs-quick-checkout-modal-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 15px;
				padding-bottom: 15px;
				border-bottom: 1px solid #ddd;
			}
			.wcs-quick-checkout-modal-header h2 {
				margin: 0;
			}
			.wcs-quick-checkout-modal-close {
				font-size: 24px;
				font-weight: bold;
				cursor: pointer;
				color: #666;
			}
			.wcs-quick-checkout-modal-close:hover {
				color: #000;
			}
			.wcs-quick-checkout-link-container {
				background: #f5f5f5;
				padding: 15px;
				border-radius: 4px;
				margin: 15px 0;
				word-break: break-all;
			}
			.wcs-quick-checkout-link-container input {
				width: 100%;
				padding: 10px;
				font-family: monospace;
				font-size: 12px;
			}
			.wcs-quick-checkout-actions {
				display: flex;
				gap: 10px;
				margin-top: 15px;
			}
			.wcs-quick-checkout-loading {
				text-align: center;
				padding: 20px;
			}
			.wcs-quick-checkout-error {
				color: #d63638;
				background: #fcf0f1;
				padding: 10px 15px;
				border-radius: 4px;
				margin: 10px 0;
			}
			.wcs-quick-checkout-warnings {
				color: #996800;
				background: #fcf9e8;
				padding: 10px 15px;
				border-radius: 4px;
				margin: 10px 0;
			}
			.wc-action-button-quick_checkout::after {
				font-family: Dashicons !important;
				content: "\f504" !important;
			}
			a.quick_checkout {
				cursor: pointer;
			}
		</style>

		<div id="wcs-quick-checkout-modal" class="wcs-quick-checkout-modal">
			<div class="wcs-quick-checkout-modal-content">
				<div class="wcs-quick-checkout-modal-header">
					<h2><?php esc_html_e( 'Quick Checkout Link', 'wcs-import-export' ); ?></h2>
					<span class="wcs-quick-checkout-modal-close">&times;</span>
				</div>
				<div id="wcs-quick-checkout-modal-body">
					<div class="wcs-quick-checkout-loading">
						<span class="spinner is-active" style="float: none;"></span>
						<?php esc_html_e( 'Generating link...', 'wcs-import-export' ); ?>
					</div>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var $modal = $('#wcs-quick-checkout-modal');
			var $modalBody = $('#wcs-quick-checkout-modal-body');

			// Handle click on Quick Checkout action
			// The action HTML is wrapped in a <span class="quick_checkout">, so we need to find the anchor inside
			$(document).on('click', 'span.quick_checkout a, a.quick_checkout', function(e) {
				e.preventDefault();
				
				var $link = $(this);
				
				// Get subscription ID from URL hash (format: #wcs-quick-checkout-123)
				var href = $link.attr('href') || '';
				var match = href.match(/^#wcs-quick-checkout-(\d+)$/);
				var subscriptionId = match ? match[1] : null;
				
				if (!subscriptionId) {
					alert('<?php echo esc_js( __( 'Could not determine subscription ID.', 'wcs-import-export' ) ); ?>');
					return;
				}
				
				// Show modal with loading state
				$modal.show();
				$modalBody.html('<div class="wcs-quick-checkout-loading"><span class="spinner is-active" style="float: none;"></span> <?php echo esc_js( __( 'Generating link...', 'wcs-import-export' ) ); ?></div>');

				// Make AJAX request
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wcs_generate_quick_checkout',
						nonce: '<?php echo esc_js( $nonce ); ?>',
						subscription_id: subscriptionId
					},
					success: function(response) {
						if (response.success) {
							var html = '';
							
							if (response.data.warnings && response.data.warnings.length > 0) {
								html += '<div class="wcs-quick-checkout-warnings"><strong><?php echo esc_js( __( 'Warnings:', 'wcs-import-export' ) ); ?></strong><br>' + response.data.warnings.join('<br>') + '</div>';
							}
							
							html += '<p><?php echo esc_js( __( 'Copy this link and send it to the customer:', 'wcs-import-export' ) ); ?></p>';
							html += '<div class="wcs-quick-checkout-link-container">';
							html += '<input type="text" id="wcs-quick-checkout-link" value="' + response.data.link + '" readonly>';
							html += '</div>';
							html += '<div class="wcs-quick-checkout-actions">';
							html += '<button type="button" class="button button-primary" id="wcs-copy-link"><?php echo esc_js( __( 'Copy Link', 'wcs-import-export' ) ); ?></button>';
							html += '<a href="' + response.data.link + '" target="_blank" class="button"><?php echo esc_js( __( 'Open Link', 'wcs-import-export' ) ); ?></a>';
							html += '</div>';
							
							$modalBody.html(html);
						} else {
							$modalBody.html('<div class="wcs-quick-checkout-error">' + response.data.message + '</div>');
						}
					},
					error: function() {
						$modalBody.html('<div class="wcs-quick-checkout-error"><?php echo esc_js( __( 'An error occurred. Please try again.', 'wcs-import-export' ) ); ?></div>');
					}
				});
			});

			// Copy link button
			$(document).on('click', '#wcs-copy-link', function() {
				var $input = $('#wcs-quick-checkout-link');
				$input.select();
				document.execCommand('copy');
				$(this).text('<?php echo esc_js( __( 'Copied!', 'wcs-import-export' ) ); ?>');
				setTimeout(function() {
					$('#wcs-copy-link').text('<?php echo esc_js( __( 'Copy Link', 'wcs-import-export' ) ); ?>');
				}, 2000);
			});

			// Close modal
			$('.wcs-quick-checkout-modal-close').on('click', function() {
				$modal.hide();
			});

			$(window).on('click', function(e) {
				if ($(e.target).is($modal)) {
					$modal.hide();
				}
			});
		});
		</script>
		<?php
	}
}

// Initialize
WCS_Shopify_Settings::init();
