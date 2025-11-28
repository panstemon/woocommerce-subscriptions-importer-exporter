<?php
/**
 * Admin section for the WooCommerce Subscriptions exporter
 *
 * @since 1.0
 */
class WCS_Export_Admin {

	public $exporter_setup = array();
	public $action        = '';
	public $error_message = '';

	/**
	 * Option keys for AJAX export progress
	 */
	const OPTION_EXPORT_STATUS = '_wcs_export_status';
	const OPTION_EXPORT_DATA = '_wcs_export_data';
	const BATCH_SIZE = 25;

	/**
	 * Initialise all admin hooks and filters for the subscriptions exporter
	 *
	 * @since 1.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( &$this, 'add_sub_menu' ), 10 );

		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

		add_action( 'admin_init', array( &$this, 'export_handler' ) );

		add_filter( 'woocommerce_screen_ids', array( &$this, 'register_export_screen_id' ) );

		$this->action = admin_url( 'admin.php?page=export_subscriptions' );

		add_action( 'admin_notices', array( &$this, 'cron_start_notice' ), 500 );

		add_action( 'admin_init', array( &$this, 'process_cron_export_delete' ) );

		// AJAX handlers for batch export
		add_action( 'wp_ajax_wcs_export_start', array( &$this, 'ajax_export_start' ) );
		add_action( 'wp_ajax_wcs_export_batch', array( &$this, 'ajax_export_batch' ) );
		add_action( 'wp_ajax_wcs_export_cancel', array( &$this, 'ajax_export_cancel' ) );
		add_action( 'wp_ajax_wcs_export_download', array( &$this, 'ajax_export_download' ) );

		// Handle file download
		add_action( 'admin_init', array( &$this, 'handle_export_download' ) );
	}

	/**
	 * Adds the Subscriptions exporter under Tools
	 *
	 * @since 1.0
	 */
	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Exporter', 'wcs-import-export' ),  __( 'Subscription Exporter', 'wcs-import-export' ), 'manage_woocommerce', 'export_subscriptions', array( &$this, 'export_page' ) );
	}

	/**
	 * Load exporter scripts
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();
		
		if ( ! $screen || $screen->id !== 'woocommerce_page_export_subscriptions' ) {
			return;
		}

		// Main exporter script (tabs, form handling)
		wp_enqueue_script( 'wcs-exporter-admin', WCS_Importer_Exporter::plugin_url() . 'assets/js/wcs-exporter.js', array( 'jquery' ), '1.0', true );

		// AJAX batch export script
		wp_enqueue_script( 'wcs-exporter-ajax', WCS_Importer_Exporter::plugin_url() . 'assets/js/wcs-exporter-ajax.js', array( 'jquery' ), '1.0', true );

		wp_localize_script( 'wcs-exporter-ajax', 'wcsExporterAjax', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wcs_export_nonce' ),
			'strings' => array(
				'initializing'     => __( 'Initializing export...', 'wcs-import-export' ),
				'processing'       => __( 'Processing...', 'wcs-import-export' ),
				'completed'        => __( 'Export completed!', 'wcs-import-export' ),
				'cancelled'        => __( 'Export cancelled.', 'wcs-import-export' ),
				'cancelling'       => __( 'Cancelling...', 'wcs-import-export' ),
				'cancel'           => __( 'Cancel Export', 'wcs-import-export' ),
				'error'            => __( 'An error occurred during export.', 'wcs-import-export' ),
				'connectionError'  => __( 'Connection error. Please try again.', 'wcs-import-export' ),
				'batchError'       => __( 'Error processing batch.', 'wcs-import-export' ),
				'permissionDenied' => __( 'Permission denied. Please refresh the page and try again.', 'wcs-import-export' ),
				'noSubscriptions'  => __( 'No subscriptions found matching your criteria.', 'wcs-import-export' ),
				'exportComplete'   => __( 'Export completed successfully! Your download should start automatically.', 'wcs-import-export' ),
				'confirmCancel'    => __( 'Are you sure you want to cancel the export?', 'wcs-import-export' ),
				'closeWarning'     => __( 'Export is in progress. Are you sure you want to leave this page?', 'wcs-import-export' ),
			),
		) );

		// Add inline styles for progress bar
		wp_add_inline_style( 'woocommerce_admin_styles', '
			#wcs-export-progress-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
			}
			#wcs-export-progress-section h3 {
				margin-top: 0;
			}
			.wcs-progress-container {
				background: #f0f0f0;
				border-radius: 4px;
				height: 30px;
				margin: 15px 0;
				overflow: hidden;
			}
			#wcs-export-progress-bar {
				background: linear-gradient(90deg, #0073aa, #00a0d2);
				height: 100%;
				width: 0%;
				transition: width 0.3s ease;
				border-radius: 4px;
			}
			#wcs-export-progress-text {
				text-align: center;
				margin-top: -25px;
				font-weight: bold;
				color: #fff;
				text-shadow: 1px 1px 1px rgba(0,0,0,0.3);
				position: relative;
				z-index: 1;
				line-height: 25px;
			}
			.wcs-stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
				gap: 15px;
				margin: 20px 0;
			}
			.wcs-stat-box {
				background: #f9f9f9;
				padding: 10px;
				border-radius: 4px;
				text-align: center;
			}
			.wcs-stat-label {
				font-size: 12px;
				color: #666;
				display: block;
			}
			.wcs-stat-value {
				font-size: 18px;
				font-weight: bold;
				color: #0073aa;
			}
			.wcs-warning-box {
				background: #fff8e5;
				border-left: 4px solid #ffb900;
				padding: 12px 15px;
				margin: 15px 0;
			}
			.wcs-warning-box strong {
				color: #826200;
			}
			#wcs-export-cancel {
				margin-top: 10px;
			}
			.wcs-export-status {
				margin-top: 15px;
				padding: 10px;
				background: #f9f9f9;
				border-radius: 4px;
				font-style: italic;
				color: #666;
			}
		' );
	}

	/**
	 * Export Home page
	 *
	 * @since 1.0
	 */
	public function export_page() {
		?>

		<div class="wrap woocommerce">
		<h2><?php __( 'Subscription CSV Exporter', 'wcs-import-export' ); ?></h2>

		<?php if ( ! empty( $this->error_message ) ) : ?>
			<div id="message" class="error">
				<p><?php echo esc_html( $this->error_message ); ?></p>
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper woo-nav-tab-wrapper"><?php

		$tabs = array(
			'wcsi-export'       => __( 'Export', 'wcs-import-export' ),
			'wcsi-headers'      => __( 'CSV Headers', 'wcs-import-export' ),
			'wcsi-cron-exports' => __( 'Cron exports', 'wcs-import-export' )
		);

		$current_tab = ( empty( $_GET['tab'] ) ) ? 'wcsi-export' : sanitize_text_field( $_GET['tab'] );
		
		// Validate tab exists
		if ( ! array_key_exists( $current_tab, $tabs ) ) {
			$current_tab = 'wcsi-export';
		}

		foreach ( $tabs as $tab_id => $tab_title ) {

			$class = ( $tab_id == $current_tab ) ? array( 'nav-tab', 'nav-tab-active', 'wcsi-exporter-tabs' ) : array( 'nav-tab', 'wcsi-exporter-tabs' );
			$tab_url = add_query_arg( 'tab', $tab_id, admin_url( 'admin.php?page=export_subscriptions' ) );

			echo '<a href="' . esc_url( $tab_url ) . '" id="' . esc_attr( $tab_id ) . '" data-tab="' . esc_attr( $tab_id ) . '" class="' . esc_attr( implode( ' ', array_map( 'sanitize_html_class', $class ) ) ) . '">' . esc_html( $tab_title ) . '</a>';

		}

		echo '</h2>';
		
		// Progress Section (hidden initially)
		$this->render_progress_section();
		
		echo '<form class="wcsi-exporter-form" method="POST" action="' . esc_attr( add_query_arg( 'step', 'download' ) ) . '">';
		$this->home_page();
		echo '<p class="submit">';
		echo '<input data-action="' . esc_attr( add_query_arg( 'step', 'download' ) ) . '" type="submit" class="button button-primary" value="' . esc_html__( 'Export Subscriptions', 'wcs-import-export' ) . '" />';
		echo '&nbsp;&nbsp;<input data-action="' . esc_attr( add_query_arg( 'step', 'cron-export' ) ) . '" type="submit" class="button" value="' . esc_html__( 'Export Subscriptions (using cron)', 'wcs-import-export' ) . '" />';
		echo '</p>';
		wp_nonce_field( 'wcsie-exporter-home', 'wcsie_wpnonce' );
		echo '</form>';
	}

	/**
	 * Render the export progress section (hidden initially)
	 *
	 * @since 2.1
	 */
	private function render_progress_section() {
		?>
		<div id="wcs-export-progress-section" style="display: none;">
			<h3><?php esc_html_e( 'Export Progress', 'wcs-import-export' ); ?></h3>
			
			<div class="wcs-warning-box">
				<strong>⚠️ <?php esc_html_e( 'Do not close this window!', 'wcs-import-export' ); ?></strong>
				<?php esc_html_e( 'The export is processing. Closing this window will cancel the export.', 'wcs-import-export' ); ?>
			</div>
			
			<div class="wcs-progress-container">
				<div id="wcs-export-progress-bar"></div>
			</div>
			<div id="wcs-export-progress-text">0%</div>
			
			<div class="wcs-stats-grid">
				<div class="wcs-stat-box">
					<span class="wcs-stat-label"><?php esc_html_e( 'Total', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-total" class="wcs-stat-value">0</span>
				</div>
				<div class="wcs-stat-box">
					<span class="wcs-stat-label"><?php esc_html_e( 'Processed', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-processed" class="wcs-stat-value">0</span>
				</div>
				<div class="wcs-stat-box">
					<span class="wcs-stat-label"><?php esc_html_e( 'Elapsed', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-elapsed" class="wcs-stat-value">00:00</span>
				</div>
				<div class="wcs-stat-box">
					<span class="wcs-stat-label"><?php esc_html_e( 'Estimated Total', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-estimated" class="wcs-stat-value">--:--</span>
				</div>
				<div class="wcs-stat-box">
					<span class="wcs-stat-label"><?php esc_html_e( 'Remaining', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-remaining" class="wcs-stat-value">--:--</span>
				</div>
			</div>
			
			<button type="button" id="wcs-export-cancel" class="button">
				<?php esc_html_e( 'Cancel Export', 'wcs-import-export' ); ?>
			</button>
			
			<div id="wcs-export-status-text" class="wcs-export-status"></div>
		</div>
		<?php
	}

	/**
	 * Home page for the exporter. Allows the store manager to choose various options for the export.
	 *
	 * @since 1.0
	 */
	public function home_page() {
		$statuses      = wcs_get_subscription_statuses();
		$status_counts = array();

		if ( wcsi_is_hpos_enabled() ) {
			$wcs_datastore = WC_Data_Store::load( 'subscription' );

			foreach ( array_keys( $statuses ) as $status ) {
				$status_counts[ $status ] = $wcs_datastore->get_order_count( $status );
			}
		} else {
			foreach ( wp_count_posts( 'shop_subscription' ) as $status => $count ) {
				if ( array_key_exists( $status, $statuses ) ) {
					$status_counts[ $status ] = $count;
				}
			}
		}

		if ( ! isset( $POST['wcsie_wpnonce'] ) || check_admin_referer( 'wcsie-exporter-home', 'wcsie_wpnonce' ) ) { ?>
			<table class="widefat striped" id="wcsi-export-table">
				<tbody>
					<tr>
						<td width="200"><label for="export_format"><?php esc_html_e( 'Export Format', 'wcs-import-export' ); ?>:</label></td>
						<td>
							<select name="export_format" id="export_format" style="min-width: 220px">
								<option value="standard" <?php selected( ! empty( $_POST['export_format'] ) && $_POST['export_format'] === 'standard' ); ?>><?php esc_html_e( 'Standard WooCommerce Format', 'wcs-import-export' ); ?></option>
								<!-- 
								<option value="appstle" <?php selected( ! empty( $_POST['export_format'] ) && $_POST['export_format'] === 'appstle' ); ?>><?php esc_html_e( 'Appstle Quick Checkout Format', 'wcs-import-export' ); ?></option>
							 	-->
							</select>
							<p class="description" id="appstle-format-note" style="display:none; color: #d63638; margin-top: 5px;">
								<?php esc_html_e( 'Note: Appstle format requires Shopify credentials below. Each subscription line item will be exported as a separate row. Custom CSV headers will be ignored.', 'wcs-import-export' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<td width="200"><label for="filename"><?php esc_html_e( 'Export File name', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="text" name="filename" placeholder="export filename" value="<?php echo ! empty( $_POST['filename'] ) ? esc_attr( $_POST['filename'] ) : 'subscriptions.csv'; ?>" required></td>
					</tr>
					<tr>
						<td style="vertical-align:top"><?php esc_html_e( 'Subscription Statuses', 'wcs-import-export' ); ?>:</td>
						<td>
							<?php foreach ( $statuses as $status => $status_display ) : ?>
								<input type="checkbox" name="status[<?php echo esc_attr( $status ); ?>]" checked><?php echo esc_html( $status_display ); ?>  [<?php echo esc_html( ! empty( $status_counts[ $status ] ) ? $status_counts[ $status ] : 0 ); ?>]<br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<td><label for="customer"><?php esc_html_e( 'Export for Customer', 'wcs-import-export' ); ?>:</label></td>
						<td>
							<select class="wc-customer-search" name="customer" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'wcs-import-export' ); ?>" data-allow_clear="true" style="min-width: 220px">
								<option value="" selected="selected"><?php esc_attr_e( 'All users', 'wcs-import-export' ); ?><option>
							</select>
						</td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Payment Method', 'wcs-import-export' ); ?>:</label></td>
						<td>
							<select name="payment" style="min-width: 220px">
								<option value="any"><?php esc_html_e( 'Any Payment Method', 'wcs-import-export' ); ?></option>
								<option value="none"><?php esc_html_e( 'None', 'wcs-import-export' ); ?></option>

								<?php foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway_id => $gateway ) : ?>
									<option value="<?php echo esc_attr( $gateway_id ); ?>"><?php echo esc_html( $gateway->title ); ?></option>;
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Payment Method Tokens', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="checkbox" name="paymentmeta"><?php esc_html_e( 'Export your customers payment and credit cart tokens to the CSV', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Offset', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="number" name="offset" min="0" value="0"> <?php esc_html_e( 'Offset export results to export a specific subset of your subscriptions. Defaults to 0. Subscriptions are chosen based on start date from newest to oldest.', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Limit Export', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="number" name="limit" min="0"> <?php esc_html_e( 'Export only a certain number of subscriptions. Leave empty or set to "0" to export all subscriptions.', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Limit Batch', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="number" name="limit_batch" min="10" value="500" step="10"> <?php esc_html_e( 'When exporting using cron, this will limit the number of records that will processed on each batch. Reduce this if your PHP limits are low.', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label for="notification_email"><?php esc_html_e( 'Notification Email', 'wcs-import-export' ); ?>:</label></td>
						<td>
							<input type="email" name="notification_email" id="notification_email" placeholder="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" value="<?php echo ! empty( $_POST['notification_email'] ) ? esc_attr( $_POST['notification_email'] ) : ''; ?>" style="width: 300px;">
							<p class="description"><?php esc_html_e( 'Email address to receive notification when cron export completes. Leave empty to skip notification.', 'wcs-import-export' ); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2"><strong><?php esc_html_e( 'Shopify Integration (Optional)', 'wcs-import-export' ); ?></strong></td>
					</tr>
					<tr>
						<td colspan="2" style="padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa;">
							<strong><?php esc_html_e( 'Important:', 'wcs-import-export' ); ?></strong> <?php esc_html_e( 'To use Shopify integration, you must enable the woo.id metafield for filtering in Shopify Admin:', 'wcs-import-export' ); ?><br>
							<ol style="margin: 10px 0 10px 20px;">
								<li><?php esc_html_e( 'Go to Shopify Admin > Settings > Custom data', 'wcs-import-export' ); ?></li>
								<li><?php esc_html_e( 'Click on Products (and/or Variants)', 'wcs-import-export' ); ?></li>
								<li><?php esc_html_e( 'Find and click on the woo.id metafield definition', 'wcs-import-export' ); ?></li>
								<li><?php esc_html_e( 'Enable "Storefront Filtering" option and save', 'wcs-import-export' ); ?></li>
							</ol>
							<?php esc_html_e( 'Without this, product matching via GraphQL will not work.', 'wcs-import-export' ); ?>
						</td>
					</tr>
					<tr>
						<td><label for="shopify_store_url"><?php esc_html_e( 'Shopify Store URL', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="text" name="shopify_store_url" id="shopify_store_url" placeholder="mystore.myshopify.com" value="<?php echo ! empty( $_POST['shopify_store_url'] ) ? esc_attr( $_POST['shopify_store_url'] ) : ''; ?>" style="width: 300px;"> <?php esc_html_e( 'Enter your Shopify store URL (e.g., mystore.myshopify.com)', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label for="shopify_access_token"><?php esc_html_e( 'Shopify Access Token', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="password" name="shopify_access_token" id="shopify_access_token" placeholder="shpat_xxxxxxxxxxxxx" value="<?php echo ! empty( $_POST['shopify_access_token'] ) ? esc_attr( $_POST['shopify_access_token'] ) : ''; ?>" style="width: 300px;"> <?php esc_html_e( 'Enter your Shopify Admin API access token. Required for shopify_order_items column.', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label for="shopify_storefront_url"><?php esc_html_e( 'Shopify Storefront URL', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="text" name="shopify_storefront_url" id="shopify_storefront_url" placeholder="mystore.com" value="<?php echo ! empty( $_POST['shopify_storefront_url'] ) ? esc_attr( $_POST['shopify_storefront_url'] ) : ''; ?>" style="width: 300px;"> <?php esc_html_e( 'Enter your Shopify storefront URL for Quick Checkout links (e.g., mystore.com). Leave empty to use Admin API URL.', 'wcs-import-export' ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php esc_html_e( 'When exporting all subscriptions, your site may experience memory exhaustion and therefore you may need to use the limit and offset to separate your export into multiple CSV files.', 'wcs-import-export' ); ?>

			<?php
				$this->export_headers();
				$this->export_crons();
		}
	}

	/**
	 * Export headers page
	 *
	 * Display a list of all the csv headers that can be exported. Each csv header can be modified and disabled
	 *
	 * @since 1.0
	 */
	public function export_headers() {

		$csv_headers = apply_filters( 'wcsie_export_headers', array(
			'subscription_id'          => __( 'Subscription ID', 'wcs-import-export' ),
			'subscription_status'      => __( 'Subscription Status', 'wcs-import-export' ),
			'customer_id'              => __( 'Customer ID', 'wcs-import-export' ),
			'start_date'               => __( 'Start Date', 'wcs-import-export' ),
			'trial_end_date'           => __( 'Trial End Date', 'wcs-import-export' ),
			'next_payment_date'        => __( 'Next Payment Date', 'wcs-import-export' ),
			'last_payment_date'        => __( 'Last Order Date', 'wcs-import-export' ),
			'cancelled_date'           => __( 'Cancellation Date', 'wcs-import-export' ),
			'end_date'                 => __( 'End Date', 'wcs-import-export' ),
			'billing_period'           => __( 'Billing Period', 'wcs-import-export' ),
			'billing_interval'         => __( 'Billing Interval', 'wcs-import-export' ),
			'order_shipping'           => __( 'Total Shipping', 'wcs-import-export' ),
			'order_shipping_tax'       => __( 'Total Shipping Tax', 'wcs-import-export' ),
			'fee_total'                => __( 'Total Subscription Fees', 'wcs-import-export' ),
			'fee_tax_total'            => __( 'Total Fees Tax', 'wcs-import-export' ),
			'order_tax'                => __( 'Subscription Total Tax', 'wcs-import-export' ),
			'cart_discount'            => __( 'Total Discount', 'wcs-import-export' ),
			'cart_discount_tax'        => __( 'Total Discount Tax', 'wcs-import-export' ),
			'order_total'              => __( 'Subscription Total', 'wcs-import-export' ),
			'order_currency'           => __( 'Subscription Currency', 'wcs-import-export' ),
			'payment_method'           => __( 'Payment Method', 'wcs-import-export' ),
			'payment_method_title'     => __( 'Payment Method Title', 'wcs-import-export' ),
			'payment_method_post_meta' => __( 'Payment Method Post Meta', 'wcs-import-export' ),
			'payment_method_user_meta' => __( 'Payment Method User Meta', 'wcs-import-export' ),
			'requires_manual_renewal'  => __( 'Requires Manual Renewal Flag', 'wcs-import-export' ),
			'shipping_method'          => __( 'Shipping Method', 'wcs-import-export' ),
			'billing_first_name'       => __( 'Billing First Name', 'wcs-import-export' ),
			'billing_last_name'        => __( 'Billing Last Name', 'wcs-import-export' ),
			'billing_email'            => __( 'Billing Email', 'wcs-import-export' ),
			'billing_phone'            => __( 'Billing Phone', 'wcs-import-export' ),
			'billing_address_1'        => __( 'Billing Address 1', 'wcs-import-export' ),
			'billing_address_2'        => __( 'Billing Address 2', 'wcs-import-export' ),
			'billing_postcode'         => __( 'Billing Postcode', 'wcs-import-export' ),
			'billing_city'             => __( 'Billing City', 'wcs-import-export' ),
			'billing_state'            => __( 'Billing State', 'wcs-import-export' ),
			'billing_country'          => __( 'Billing Country', 'wcs-import-export' ),
			'billing_company'          => __( 'Billing Company', 'wcs-import-export' ),
			'shipping_first_name'      => __( 'Shipping First Name', 'wcs-import-export' ),
			'shipping_last_name'       => __( 'Shipping Last Name', 'wcs-import-export' ),
			'shipping_address_1'       => __( 'Shipping Address 1', 'wcs-import-export' ),
			'shipping_address_2'       => __( 'Shipping Address 2', 'wcs-import-export' ),
			'shipping_postcode'        => __( 'Shipping Post code', 'wcs-import-export' ),
			'shipping_city'            => __( 'Shipping City', 'wcs-import-export' ),
			'shipping_state'           => __( 'Shipping State', 'wcs-import-export' ),
			'shipping_country'         => __( 'Shipping Country', 'wcs-import-export' ),
			'shipping_company'         => __( 'Shipping Company', 'wcs-import-export' ),
			'customer_note'            => __( 'Customer Note', 'wcs-import-export' ),
			'order_items'              => __( 'Subscription Items', 'wcs-import-export' ),
			'shopify_order_items'      => __( 'Shopify Order Items', 'wcs-import-export' ),
			'order_notes'              => __( 'Subscription order notes', 'wcs-import-export' ),
			'coupon_items'             => __( 'Coupons', 'wcs-import-export' ),
			'fee_items'                => __( 'Fees', 'wcs-import-export' ),
			'tax_items'                => __( 'Taxes', 'wcs-import-export' ),
			'download_permissions'     => __( 'Download Permissions Granted', 'wcs-import-export' ),
			'shopify_checkout_link'    => __( 'Shopify Quick Checkout Link', 'wcs-import-export' ),
			'shopify_subscription_status'     => __( 'Shopify Subscription Status', 'wcs-import-export' ),
			'shopify_subscription_id'         => __( 'Shopify Subscription ID', 'wcs-import-export' ),
			'shopify_subscription_key'        => __( 'Shopify Subscription Key', 'wcs-import-export' ),
			'shopify_subscription_created_at' => __( 'Shopify Subscription Created At', 'wcs-import-export' ),
			'shopify_subscription_cancelled_at' => __( 'Shopify Subscription Cancelled At', 'wcs-import-export' ),
			'shopify_subscription_edit_url'   => __( 'Shopify Subscription Edit URL', 'wcs-import-export' ),
		) );
		?>

		<table class="widefat widefat_importer striped" id="wcsi-headers-table" style="display:none;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Include', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Subscription Details', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Importer Compatible Header', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'CSV Column Header', 'wcs-import-export' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $csv_headers as $data => $title ) : ?>
					<tr>
						<td width="15" style="text-align:center"><input type="checkbox" name="mapped[<?php echo esc_attr( $data ); ?>]" checked></td>
						<td><label><?php echo esc_html( $title ); ?></label></td>
						<td><label><?php echo esc_html( $data ); ?></label></td>
						<td><input type="text" name="<?php echo esc_attr( $data ); ?>" value="<?php echo esc_attr( $data ); ?>" placeholder="<?php echo esc_attr( $data ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

	}

	/**
	 * Export crons page
	 *
	 * Display a list of all the crons preparing exports.
	 *
	 * @since 2.0-beta
	 */
	public function export_crons() {

		$files = array();
		$files_data = array();

		if ( file_exists(WCS_Exporter_Cron::$cron_dir) ) {
			$files = scandir(WCS_Exporter_Cron::$cron_dir);
		}

		if ( !empty($files) ) {

			$files      = array_diff( $files, array('.', '..', 'index.php', '.htaccess' ) );
			$upload_dir = wp_upload_dir();
			$files_url  = $upload_dir['baseurl'] . '/woocommerce-subscriptions-importer-exporter/';

			// Get the site's date and time format settings.
			$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

			foreach ( $files as $file ) {

				// set status
				$status = 'completed';
				if ( strpos($file, 'tmp') !== false ) {
					$status = 'processing';
				}

				$file_data = array(
					'name'   => $file,
					'url'    => $files_url . $file,
					'status' => $status,
					'date'   => date_i18n( $datetime_format, absint( filectime( trailingslashit( WCS_Exporter_Cron::$cron_dir ) . $file ) ) ),
				);

				$files_data[] = $file_data;
			}
		}
		?>
		<table class="widefat widefat_crons striped" id="wcsi-cron-exports-table" style="display:none;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wcs-import-export' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<?php if ( empty($files_data) ): ?>
				<tbody>
					<tr>
						<td colspan="3"><?php esc_html_e( 'There are no files completed or processing.', 'wcs-import-export' ); ?></td>
					</tr>
				</tbody>
			<?php else: ?>
				<tbody>
					<?php foreach ( $files_data as $file_data ): ?>
						<tr>
							<td>
								<?php if ( $file_data['status'] == 'processing' ): ?>
									<?php echo $file_data['name']; ?>
								<?php else: ?>
									<a href="<?php echo $file_data['url']; ?>"><?php echo $file_data['name']; ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo $file_data['date']; ?></td>
							<td>
								<?php
									if ( $file_data['status'] == 'processing' ) {
										esc_html_e( 'Processing', 'wcs-import-export' );
									} else {
										esc_html_e( 'Completed', 'wcs-import-export' );
									}
								?>
							</td>
							<td align="right">
								<a class="button" href="<?php echo wp_nonce_url(admin_url('admin.php?page=export_subscriptions&delete_export=' . $file_data['name']), 'delete_export', '_wpnonce'); ?>" onclick="return confirm('<?php esc_html_e( 'Are you sure you want to delete this export?', 'wcs-import-export' ); ?>')"><?php esc_html_e( 'Delete', 'wcs-import-export' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Query the subscriptions using the users specific filters.
	 *
	 * @since 1.0
	 * @return array
	 */
	private function get_subscriptions_to_export() {

		check_admin_referer( 'wcsie-exporter-home', 'wcsie_wpnonce' );

		$args = array(
			'subscriptions_per_page' => ! empty( $_POST['limit'] ) ? absint( $_POST['limit'] ) : -1,
			'offset'                 => isset( $_POST['offset'] ) ? $_POST['offset'] : 0,
			'product'                => isset( $_POST['product'] ) ? $_POST['product'] : '',
			'subscription_status'    => 'none', // don't default to 'any' status if no statuses were chosen
		);

		if ( ! empty( $_POST['status'] ) ) {
			$args['subscription_status'] = array_keys( $_POST['status'] );
		}

		if ( ! empty( $_POST['customer'] ) && is_numeric( $_POST['customer'] ) ) {
			$args['customer_id'] = $_POST['customer'];
		}

		if ( ! empty( $_POST['payment'] ) ) {
			add_filter( 'woocommerce_get_subscriptions_query_args', array( &$this, 'filter_payment_method' ), 10, 2 );
		}

		return wcs_get_subscriptions( $args );
	}

	/**
	 * Filter the query in @see wcs_get_subscriptions() by payment method.
	 *
	 * @since 1.0
	 * @param array $query_args
	 * @param array $args
	 * @return array
	 */
	public function filter_payment_method( $query_args, $args ) {

		check_admin_referer( 'wcsie-exporter-home', 'wcsie_wpnonce' );

		if ( isset( $_POST['payment'] ) && 'any' != $_POST['payment'] ) {
			$payment_payment = ( 'none' == $_POST['payment'] ) ? '' : $_POST['payment'];

			if ( wcsi_is_hpos_enabled() ) {
				$query_args['payment_method'] = $payment_payment;
			} else {
				$query_args['meta_query'][] = array(
					'key'   => '_payment_method',
					'value' => $payment_payment,
				);
			}
		}

		return $query_args;
	}

	/**
	 * Function to start the download process
	 *
	 * @since 1.0
	 * @param array $headers
	 */
	public function process_download( $headers = array() ) {

		check_admin_referer( 'wcsie-exporter-home', 'wcsie_wpnonce' );

		WC()->payment_gateways();

		$subscriptions = $this->get_subscriptions_to_export();

		if ( ! empty( $subscriptions ) ) {
			if ( empty( $_POST['paymentmeta'] ) ) {
				unset( $headers['payment_method_post_meta'] );
				unset( $headers['payment_method_user_meta'] );
			}

			// Check if Appstle format is selected
			$export_format = isset( $_POST['export_format'] ) ? sanitize_text_field( $_POST['export_format'] ) : 'standard';

			// Initialize Shopify API if credentials are provided
			$shopify_api = null;
			if ( ! empty( $_POST['shopify_store_url'] ) && ! empty( $_POST['shopify_access_token'] ) ) {
				$storefront_url = ! empty( $_POST['shopify_storefront_url'] ) ? sanitize_text_field( $_POST['shopify_storefront_url'] ) : '';
				$shopify_api = new WCS_Shopify_API( 
					sanitize_text_field( $_POST['shopify_store_url'] ), 
					sanitize_text_field( $_POST['shopify_access_token'] ),
					$storefront_url
				);
				WCS_Exporter::set_shopify_api( $shopify_api );
			}

			if ( 'appstle' === $export_format ) {
				// Appstle Quick Checkout format export
				if ( ! $shopify_api || ! $shopify_api->is_configured() ) {
					$this->error_message = __( 'Appstle format requires Shopify Store URL and Access Token to be configured.', 'wcs-import-export' );
					return;
				}

				WCS_Exporter::write_appstle_headers();

				foreach ( $subscriptions as $subscription ) {
					WCS_Exporter::write_appstle_csv_row( $subscription );
				}

				WCS_Exporter::process_export( $_POST['filename'] );
			} else {
				// Standard WooCommerce format export
				if ( isset( $headers['shopify_order_items'] ) && ! $shopify_api ) {
					// Remove shopify_order_items from headers if Shopify credentials are not provided
					unset( $headers['shopify_order_items'] );
				}

				WCS_Exporter::write_headers( $headers );

				foreach ( $subscriptions as $subscription ) {
					WCS_Exporter::write_subscriptions_csv_row( $subscription );
				}

				WCS_Exporter::process_export( $_POST['filename'] );
			}
		} else {
			$this->error_message = __( 'No subscriptions to export given the filters you have selected.', 'wcs-import-export' );
		}

	}

	/**
	 * Function to start the cron process
	 *
	 * @since 1.0
	 * @param array $headers
	 */
	public function process_cron_export( $headers = array() ) {

		check_admin_referer( 'wcsie-exporter-home', 'wcsie_wpnonce' );

		$post_data = $_POST;

		// add tmp and timestamp to filename.
		$filename       = $post_data['filename'];
		$file_extension = pathinfo($filename, PATHINFO_EXTENSION);
		$handle         = str_replace('.' . $file_extension, '', $filename );

		$post_data['filename'] = $handle . '-' . wp_hash( time() . $handle ) . '.tmp.' . $file_extension;

		// set the initial limit
		$post_data['limit'] = $post_data['limit_batch'] != '' ? $post_data['limit_batch'] : 500;
		unset($post_data['limit_batch']);

		// set the initial offset
		$post_data['offset'] = 0;

		// Handle export format
		$export_format = isset( $post_data['export_format'] ) ? sanitize_text_field( $post_data['export_format'] ) : 'standard';
		$post_data['export_format'] = $export_format;

		// Handle Shopify credentials
		if ( ! empty( $post_data['shopify_store_url'] ) && ! empty( $post_data['shopify_access_token'] ) ) {
			$post_data['shopify_store_url'] = sanitize_text_field( $post_data['shopify_store_url'] );
			$post_data['shopify_access_token'] = sanitize_text_field( $post_data['shopify_access_token'] );
			$post_data['shopify_storefront_url'] = ! empty( $post_data['shopify_storefront_url'] ) ? sanitize_text_field( $post_data['shopify_storefront_url'] ) : '';
		}

		// Handle notification email
		if ( ! empty( $post_data['notification_email'] ) ) {
			$post_data['notification_email'] = sanitize_email( $post_data['notification_email'] );
		}

		// Validate Appstle format requirements
		if ( 'appstle' === $export_format ) {
			if ( empty( $post_data['shopify_store_url'] ) || empty( $post_data['shopify_access_token'] ) ) {
				$this->error_message = __( 'Appstle format requires Shopify Store URL and Access Token to be configured.', 'wcs-import-export' );
				return;
			}
			// For Appstle format, use the fixed headers
			$headers = WCS_Exporter::get_appstle_headers();
		} elseif ( isset( $headers['shopify_order_items'] ) && ( empty( $post_data['shopify_store_url'] ) || empty( $post_data['shopify_access_token'] ) ) ) {
			// Remove shopify_order_items from headers if credentials not provided
			unset( $headers['shopify_order_items'] );
		}

		// event args
		$event_args = array(
			'post_data' => $post_data,
			'headers' => $headers
		);

		// Create directory if it does not exist and create the file.
		WCS_Exporter_Cron::create_upload_directory();

		$file_path = WCS_Exporter_Cron::$cron_dir . '/' . $post_data['filename'];
		$file = fopen($file_path, 'a');
		fclose($file);

		wp_schedule_single_event( time() + 60, 'wcs_export_cron', $event_args );
		wp_schedule_single_event( time() + WEEK_IN_SECONDS, 'wcs_export_scheduled_cleanup', array( $post_data['filename'] ) );
	}

	/**
	 * Function to remove a cron export file.
	 *
	 * @since 2.0-beta
	 * @param array $headers
	 */
	public function process_cron_export_delete() {
		if ( isset($_GET['delete_export']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_export')) {
			WCS_Exporter_Cron::delete_export_file($_GET['delete_export']);
			wp_redirect( admin_url('admin.php?page=export_subscriptions&tab=wcsi-cron-exports&cron_deleted=true') );
		}
	}

	/**
	 * Check params sent through as POST and start the export
	 *
	 * @since 1.0
	 */
	public function export_handler() {

		if ( isset( $_GET['page'] ) && 'export_subscriptions' == $_GET['page'] ) {
			if ( isset( $_GET['step'] ) && ( 'download' == $_GET['step'] || 'cron-export' == $_GET['step'] ) ) {

				check_admin_referer( 'wcsie-exporter-home', 'wcsie_wpnonce' );

				if ( ! empty( $_POST['mapped'] ) ) {
					$csv_headers = array();

					foreach ( array_keys( $_POST['mapped'] ) as $column ) {
						if ( ! empty( $_POST[ $column ] ) ) {
							$csv_headers[ $column ] = $_POST[ $column ];
						}
					}
				}

				if ( ! empty( $csv_headers ) ) {
					if ( 'download' == $_GET['step'] ) {
						$this->process_download( $csv_headers );
					} elseif ( 'cron-export' == $_GET['step'] ) {
						$this->process_cron_export( $csv_headers );
						wp_redirect( admin_url('admin.php?page=export_subscriptions&tab=wcsi-cron-exports&cron_started=true') );
						exit();
					}
				} else {
					$this->error_message = __( 'No csv headers were chosen, please select at least one CSV header to complete the Subscriptions Exporter.', 'wcs-import-export' );
				}
			}
		}
	}

	/**
	 * Filter screen ids to add the export page so that WooCommerce will load all their admin scripts
	 *
	 * @since 1.0
	 * @param array $screen_ids
	 */
	public function register_export_screen_id( $screen_ids ) {
		if ( isset( $_GET['page'] ) && 'export_subscriptions' == $_GET['page'] ) {
			$screen_ids[] = 'woocommerce_page_export_subscriptions';
		}

		return $screen_ids;
	}

	/**
	 * Display success message when cron job is set to start.
	 *
	 * @since 2.0-beta
	 */
	public function cron_start_notice() {
		if ( isset($_GET["cron_started"]) && $_GET["cron_started"] == "true") {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo __( 'The export is scheduled. You can see the status and download the file on the "Cron export" tab.', 'wcs-import-export' ); ?></p>
			</div>
			<?php
		}
		if ( isset($_GET["cron_deleted"]) && $_GET["cron_deleted"] == "true") {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo __( 'The export file was successfully deleted.', 'wcs-import-export' ); ?></p>
			</div>
			<?php
		}

    }

	/**
	 * AJAX handler to start the export process.
	 *
	 * @since 2.1.0
	 */
	public function ajax_export_start() {
		check_ajax_referer( 'wcs_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export subscriptions.', 'wcs-import-export' ) ) );
		}

		// Parse form data
		parse_str( $_POST['form_data'], $form_data );

		// Get CSV headers from form
		$csv_headers = array();
		if ( ! empty( $form_data['mapped'] ) ) {
			foreach ( array_keys( $form_data['mapped'] ) as $column ) {
				if ( ! empty( $form_data[ $column ] ) ) {
					$csv_headers[ $column ] = $form_data[ $column ];
				}
			}
		}

		// Get export format
		$export_format = isset( $form_data['export_format'] ) ? sanitize_text_field( $form_data['export_format'] ) : 'standard';

		// Validate based on export format
		if ( $export_format === 'appstle' ) {
			// Appstle format requires Shopify credentials
			if ( empty( $form_data['shopify_store_url'] ) || empty( $form_data['shopify_api_token'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Appstle export format requires Shopify credentials. Please enter your Shopify Store URL and API Token.', 'wcs-import-export' ) ) );
			}
		} elseif ( empty( $csv_headers ) ) {
			// Standard format requires at least one CSV header
			wp_send_json_error( array( 'message' => __( 'No CSV headers were chosen. Please select at least one CSV header.', 'wcs-import-export' ) ) );
		}

		// Count total subscriptions to export
		$subscription_args = array(
			'subscriptions_per_page' => -1,
			'subscription_status'    => isset( $form_data['status'] ) ? array_map( 'sanitize_text_field', $form_data['status'] ) : array( 'any' ),
		);

		// Apply date filters
		if ( ! empty( $form_data['start_date'] ) ) {
			$subscription_args['start_date_after'] = sanitize_text_field( $form_data['start_date'] ) . ' 00:00:00';
		}
		if ( ! empty( $form_data['end_date'] ) ) {
			$subscription_args['start_date_before'] = sanitize_text_field( $form_data['end_date'] ) . ' 23:59:59';
		}

		$subscriptions = wcs_get_subscriptions( $subscription_args );
		$total_subscriptions = count( $subscriptions );

		if ( $total_subscriptions === 0 ) {
			wp_send_json_error( array( 'message' => __( 'No subscriptions found matching the selected criteria.', 'wcs-import-export' ) ) );
		}

		// Get subscription IDs
		$subscription_ids = array_keys( $subscriptions );

		// Generate unique session ID
		$session_id = wp_generate_uuid4();

		// Create temp file
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/wcs-exports/';
		
		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
			// Add index.php for security
			file_put_contents( $export_dir . 'index.php', '<?php // Silence is golden' );
		}

		$filename = 'wcs-export-' . $session_id . '.csv';
		$filepath = $export_dir . $filename;

		// Initialize export file with headers
		$file = fopen( $filepath, 'w' );
		if ( ! $file ) {
			wp_send_json_error( array( 'message' => __( 'Could not create export file. Check directory permissions.', 'wcs-import-export' ) ) );
		}

		// Write BOM for Excel compatibility
		fwrite( $file, chr(0xEF) . chr(0xBB) . chr(0xBF) );

		// Write CSV headers based on format
		if ( $export_format === 'appstle' ) {
			// Appstle format headers
			$appstle_headers = WCS_Exporter::get_appstle_headers();
			fputcsv( $file, $appstle_headers );
		} else {
			// Standard format headers
			fputcsv( $file, array_values( $csv_headers ) );
		}

		fclose( $file );

		// Store export state in transient
		$export_state = array(
			'session_id'       => $session_id,
			'subscription_ids' => $subscription_ids,
			'csv_headers'      => $csv_headers,
			'export_format'    => $export_format,
			'filepath'         => $filepath,
			'filename'         => $filename,
			'processed'        => 0,
			'total'            => $total_subscriptions,
			'form_data'        => $form_data,
		);

		set_transient( 'wcs_export_' . $session_id, $export_state, HOUR_IN_SECONDS );

		wp_send_json_success( array(
			'session_id' => $session_id,
			'total'      => $total_subscriptions,
			'message'    => sprintf( __( 'Starting export of %d subscriptions...', 'wcs-import-export' ), $total_subscriptions ),
		) );
	}

	/**
	 * AJAX handler to process a batch of subscriptions.
	 *
	 * @since 2.1.0
	 */
	public function ajax_export_batch() {
		check_ajax_referer( 'wcs_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export subscriptions.', 'wcs-import-export' ) ) );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
		$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session ID.', 'wcs-import-export' ) ) );
		}

		// Get export state
		$export_state = get_transient( 'wcs_export_' . $session_id );

		if ( ! $export_state ) {
			wp_send_json_error( array( 'message' => __( 'Export session expired. Please start a new export.', 'wcs-import-export' ) ) );
		}

		$subscription_ids = $export_state['subscription_ids'];
		$csv_headers      = $export_state['csv_headers'];
		$export_format    = $export_state['export_format'];
		$filepath         = $export_state['filepath'];
		$processed        = $export_state['processed'];
		$total            = $export_state['total'];
		$form_data        = $export_state['form_data'];

		// Get batch of subscription IDs
		$batch_ids = array_slice( $subscription_ids, $processed, $batch_size );

		if ( empty( $batch_ids ) ) {
			wp_send_json_success( array(
				'complete'  => true,
				'processed' => $processed,
				'total'     => $total,
			) );
		}

		// Open file in append mode
		$file = fopen( $filepath, 'a' );
		if ( ! $file ) {
			wp_send_json_error( array( 'message' => __( 'Could not open export file for writing.', 'wcs-import-export' ) ) );
		}

		// Initialize Shopify API if needed
		$shopify_api = null;
		if ( ! empty( $form_data['shopify_store_url'] ) && ! empty( $form_data['shopify_api_token'] ) ) {
			$shopify_api = new WCS_Shopify_API(
				sanitize_text_field( $form_data['shopify_store_url'] ),
				sanitize_text_field( $form_data['shopify_api_token'] ),
				isset( $form_data['shopify_storefront_url'] ) ? sanitize_text_field( $form_data['shopify_storefront_url'] ) : ''
			);
		}

		// Process batch
		$batch_processed = 0;
		foreach ( $batch_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			
			if ( ! $subscription ) {
				continue;
			}

			if ( $export_format === 'appstle' ) {
				// Appstle format export - returns array of rows (one per line item)
				$rows = WCS_Exporter::get_appstle_row_data( $subscription, $shopify_api );
				foreach ( $rows as $row ) {
					fputcsv( $file, $row );
				}
			} else {
				// Standard format export
				$row = array();
				foreach ( array_keys( $csv_headers ) as $column ) {
					$row[] = WCS_Exporter::get_data( $column, $subscription, $shopify_api );
				}
				fputcsv( $file, $row );
			}

			$batch_processed++;
		}

		fclose( $file );

		// Update processed count
		$export_state['processed'] = $processed + $batch_processed;
		set_transient( 'wcs_export_' . $session_id, $export_state, HOUR_IN_SECONDS );

		$is_complete = ( $export_state['processed'] >= $total );

		wp_send_json_success( array(
			'complete'  => $is_complete,
			'processed' => $export_state['processed'],
			'total'     => $total,
			'message'   => $is_complete 
				? __( 'Export complete!', 'wcs-import-export' )
				: sprintf( __( 'Processed %d of %d subscriptions...', 'wcs-import-export' ), $export_state['processed'], $total ),
		) );
	}

	/**
	 * AJAX handler to cancel an export.
	 *
	 * @since 2.1.0
	 */
	public function ajax_export_cancel() {
		check_ajax_referer( 'wcs_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to cancel exports.', 'wcs-import-export' ) ) );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';

		if ( ! empty( $session_id ) ) {
			$export_state = get_transient( 'wcs_export_' . $session_id );
			
			if ( $export_state && ! empty( $export_state['filepath'] ) && file_exists( $export_state['filepath'] ) ) {
				unlink( $export_state['filepath'] );
			}
			
			delete_transient( 'wcs_export_' . $session_id );
		}

		wp_send_json_success( array( 'message' => __( 'Export cancelled.', 'wcs-import-export' ) ) );
	}

	/**
	 * AJAX handler to download the completed export file.
	 *
	 * @since 2.1.0
	 */
	public function ajax_export_download() {
		check_ajax_referer( 'wcs_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to download exports.', 'wcs-import-export' ) ) );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session ID.', 'wcs-import-export' ) ) );
		}

		$export_state = get_transient( 'wcs_export_' . $session_id );

		if ( ! $export_state || empty( $export_state['filepath'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Export session not found.', 'wcs-import-export' ) ) );
		}

		if ( ! file_exists( $export_state['filepath'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Export file not found.', 'wcs-import-export' ) ) );
		}

		// Generate download URL
		$download_url = add_query_arg( array(
			'action'     => 'wcs_export_file_download',
			'session_id' => $session_id,
			'nonce'      => wp_create_nonce( 'wcs_export_download_' . $session_id ),
		), admin_url( 'admin-ajax.php' ) );

		wp_send_json_success( array(
			'download_url' => $download_url,
			'filename'     => 'wcs-subscriptions-export-' . date( 'Y-m-d-His' ) . '.csv',
		) );
	}

	/**
	 * Handle direct file download request.
	 *
	 * @since 2.1.0
	 */
	public function handle_export_download() {
		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';
		$nonce      = isset( $_GET['nonce'] ) ? $_GET['nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'wcs_export_download_' . $session_id ) ) {
			wp_die( __( 'Invalid download link.', 'wcs-import-export' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission to download exports.', 'wcs-import-export' ) );
		}

		$export_state = get_transient( 'wcs_export_' . $session_id );

		if ( ! $export_state || empty( $export_state['filepath'] ) ) {
			wp_die( __( 'Export session not found or expired.', 'wcs-import-export' ) );
		}

		$filepath = $export_state['filepath'];

		if ( ! file_exists( $filepath ) ) {
			wp_die( __( 'Export file not found.', 'wcs-import-export' ) );
		}

		// Set download headers
		$filename = 'wcs-subscriptions-export-' . date( 'Y-m-d-His' ) . '.csv';
		
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Output file
		readfile( $filepath );

		// Clean up
		unlink( $filepath );
		delete_transient( 'wcs_export_' . $session_id );

		exit;
	}
}
