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
		add_action( 'wp_ajax_wcs_export_complete', array( &$this, 'ajax_export_complete' ) );

		// AJAX handler for secure file downloads
		add_action( 'wp_ajax_wcs_download_export', array( &$this, 'ajax_download_export' ) );

		// Ensure export directory is secured (runs once per admin init)
		add_action( 'admin_init', array( __CLASS__, 'maybe_secure_export_directory' ) );
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

		// Register and enqueue a dummy style handle for our inline styles
		wp_register_style( 'wcs-exporter-styles', false );
		wp_enqueue_style( 'wcs-exporter-styles' );

		// Add inline styles for progress bar
		wp_add_inline_style( 'wcs-exporter-styles', '
			#wcs-export-progress-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
			}
			#wcs-export-progress-section h3 {
				margin-top: 0;
				margin-bottom: 15px;
				font-size: 1.3em;
			}
			.wcs-progress-container {
				background: #e0e0e0;
				border-radius: 4px;
				height: 30px;
				margin: 15px 0;
				overflow: hidden;
				position: relative;
			}
			#wcs-export-progress-bar {
				background: linear-gradient(90deg, #0073aa, #00a0d2);
				height: 100%;
				width: 0%;
				transition: width 0.3s ease;
				border-radius: 4px;
				position: relative;
				overflow: hidden;
			}
			#wcs-export-progress-bar::after {
				content: "";
				position: absolute;
				top: 0;
				left: -100%;
				width: 100%;
				height: 100%;
				background: linear-gradient(
					90deg,
					transparent,
					rgba(255, 255, 255, 0.3),
					transparent
				);
				animation: wcs-shimmer 1.5s infinite;
			}
			@keyframes wcs-shimmer {
				0% { left: -100%; }
				100% { left: 100%; }
			}
			#wcs-export-progress-bar.complete::after {
				animation: none;
				display: none;
			}
			#wcs-export-progress-text {
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				display: flex;
				align-items: center;
				justify-content: center;
				font-weight: bold;
				font-size: 14px;
				color: #333;
				text-shadow: 0 0 2px rgba(255,255,255,0.8);
			}
			.wcs-stats-grid {
				display: grid;
				grid-template-columns: repeat(5, 1fr);
				gap: 15px;
				margin: 20px 0;
			}
			@media (max-width: 782px) {
				.wcs-stats-grid {
					grid-template-columns: repeat(2, 1fr);
				}
			}
			.wcs-stat-box {
				background: #f9f9f9;
				padding: 15px 10px;
				border-radius: 4px;
				text-align: center;
				border: 1px solid #e0e0e0;
			}
			.wcs-stat-label {
				font-size: 11px;
				color: #666;
				display: block;
				text-transform: uppercase;
				margin-bottom: 5px;
			}
			.wcs-stat-value {
				font-size: 20px;
				font-weight: bold;
				color: #0073aa;
				display: block;
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
			#wcs-export-status-text {
				margin-top: 15px;
				padding: 10px;
				background: #f9f9f9;
				border-radius: 4px;
				font-style: italic;
				color: #666;
			}
			#wcs-export-status-text:empty {
				display: none;
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
			'wcsi-exports'      => __( 'Exports', 'wcs-import-export' )
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
				<div id="wcs-export-progress-text">0%</div>
			</div>
			
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
					<span class="wcs-stat-label"><?php esc_html_e( 'Estimated', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-estimated" class="wcs-stat-value">--:--</span>
				</div>
				<div class="wcs-stat-box">
					<span class="wcs-stat-label"><?php esc_html_e( 'Remaining', 'wcs-import-export' ); ?></span>
					<span id="wcs-stat-remaining" class="wcs-stat-value">--:--</span>
				</div>
			</div>
			
			<button type="button" id="wcs-export-cancel" class="button button-secondary">
				<?php esc_html_e( 'Cancel Export', 'wcs-import-export' ); ?>
			</button>
			
			<div id="wcs-export-status-text"></div>
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
							<?php $current_user_email = wp_get_current_user()->user_email; ?>
							<input type="email" name="notification_email" id="notification_email" placeholder="<?php echo esc_attr( $current_user_email ); ?>" value="<?php echo ! empty( $_POST['notification_email'] ) ? esc_attr( $_POST['notification_email'] ) : esc_attr( $current_user_email ); ?>" style="width: 300px;">
							<p class="description"><?php esc_html_e( 'Email address to receive notification when cron export completes. Leave empty to skip notification.', 'wcs-import-export' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			
			<?php 
			// Show Shopify Integration notice if configured
			$shopify_store_url = get_option( 'wcs_shopify_store_url', '' );
			$shopify_configured = ! empty( $shopify_store_url ) && ! empty( get_option( 'wcs_shopify_access_token', '' ) );
			?>
			<div style="background: #f9f9f9; border-left: 4px solid <?php echo $shopify_configured ? '#00a32a' : '#dba617'; ?>; padding: 12px 15px; margin: 15px 0;">
				<strong><?php esc_html_e( 'Shopify Integration', 'wcs-import-export' ); ?></strong>
				<?php if ( $shopify_configured ) : ?>
					<span style="color: #00a32a; margin-left: 10px;">✓ <?php esc_html_e( 'Configured', 'wcs-import-export' ); ?></span>
					<p style="margin: 8px 0 0;">
						<?php 
						printf( 
							/* translators: %s: Settings page URL */
							esc_html__( 'Shopify credentials are configured. You can manage them in %s.', 'wcs-import-export' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=shopify_migration' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Advanced → Shopify Migration', 'wcs-import-export' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
					<p style="margin: 8px 0 0;">
						<?php 
						printf( 
							/* translators: %s: Settings page URL */
							esc_html__( 'To use Shopify product matching and Quick Checkout links, configure your credentials in %s.', 'wcs-import-export' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=shopify_migration' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Advanced → Shopify Migration', 'wcs-import-export' ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>
			
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
	 * Export files page
	 *
	 * Display a list of all completed and processing exports (both cron and AJAX).
	 *
	 * @since 2.0-beta
	 */
	public function export_crons() {

		$files_data = array();
		$upload_dir = wp_upload_dir();
		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Get cron exports from /woocommerce-subscriptions-importer-exporter/
		if ( file_exists( WCS_Exporter_Cron::$cron_dir ) ) {
			$cron_files = scandir( WCS_Exporter_Cron::$cron_dir );
			$cron_files = array_diff( $cron_files, array( '.', '..', 'index.php', '.htaccess' ) );

			foreach ( $cron_files as $file ) {
				$file_path = trailingslashit( WCS_Exporter_Cron::$cron_dir ) . $file;
				$file_time = filemtime( $file_path );

				// Set status
				$status = 'completed';
				if ( strpos( $file, 'tmp' ) !== false ) {
					$status = 'processing';
				}

				$files_data[] = array(
					'name'      => $file,
					'url'       => self::get_secure_download_url( $file, 'cron' ),
					'status'    => $status,
					'type'      => 'cron',
					'date'      => date_i18n( $datetime_format, $file_time ),
					'timestamp' => $file_time,
				);
			}
		}

		// Get AJAX exports from /wcs-exports/
		$ajax_export_dir = $upload_dir['basedir'] . '/wcs-exports/';
		if ( file_exists( $ajax_export_dir ) ) {
			$ajax_files = glob( $ajax_export_dir . '*.csv' );

			foreach ( $ajax_files as $file_path ) {
				$file = basename( $file_path );
				$file_time = filemtime( $file_path );

				// Check if this is a temp file (in-progress export)
				// Temp files start with 'wcs-export-' and contain a session ID
				$is_temp = ( strpos( $file, 'wcs-export-' ) === 0 );

				$files_data[] = array(
					'name'      => $file,
					'url'       => self::get_secure_download_url( $file, 'ajax' ),
					'status'    => $is_temp ? 'processing' : 'completed',
					'type'      => 'ajax',
					'date'      => date_i18n( $datetime_format, $file_time ),
					'timestamp' => $file_time,
				);
			}
		}

		// Sort by timestamp (newest first)
		if ( ! empty( $files_data ) ) {
			usort( $files_data, function( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			} );
		}
		?>
		<table class="widefat widefat_crons striped" id="wcsi-exports-table" style="display:none;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wcs-import-export' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<?php if ( empty( $files_data ) ) : ?>
				<tbody>
					<tr>
						<td colspan="5"><?php esc_html_e( 'There are no export files.', 'wcs-import-export' ); ?></td>
					</tr>
				</tbody>
			<?php else : ?>
				<tbody>
					<?php foreach ( $files_data as $file_data ) : ?>
						<tr>
							<td>
								<?php if ( $file_data['status'] === 'processing' ) : ?>
									<?php echo esc_html( $file_data['name'] ); ?>
								<?php else : ?>
									<a href="<?php echo esc_url( $file_data['url'] ); ?>"><?php echo esc_html( $file_data['name'] ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $file_data['date'] ); ?></td>
							<td>
								<?php
								if ( $file_data['type'] === 'cron' ) {
									esc_html_e( 'Cron', 'wcs-import-export' );
								} else {
									esc_html_e( 'Browser', 'wcs-import-export' );
								}
								?>
							</td>
							<td>
								<?php
								if ( $file_data['status'] === 'processing' ) {
									echo '<span style="color: #d63638;">' . esc_html__( 'Processing', 'wcs-import-export' ) . '</span>';
								} else {
									echo '<span style="color: #00a32a;">' . esc_html__( 'Completed', 'wcs-import-export' ) . '</span>';
								}
								?>
							</td>
							<td align="right">
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=export_subscriptions&delete_export=' . $file_data['name'] . '&export_type=' . $file_data['type'] ), 'delete_export', '_wpnonce' ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this export?', 'wcs-import-export' ); ?>')"><?php esc_html_e( 'Delete', 'wcs-import-export' ); ?></a>
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
	 * Function to remove an export file.
	 *
	 * @since 2.0-beta
	 */
	public function process_cron_export_delete() {
		if ( isset( $_GET['delete_export'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'delete_export' ) ) {
			$file_name   = sanitize_file_name( $_GET['delete_export'] );
			$export_type = isset( $_GET['export_type'] ) ? sanitize_text_field( $_GET['export_type'] ) : 'cron';

			if ( $export_type === 'ajax' ) {
				// Delete from AJAX exports directory
				$upload_dir = wp_upload_dir();
				$ajax_export_dir = $upload_dir['basedir'] . '/wcs-exports/';
				$file_path = $ajax_export_dir . $file_name;
				
				if ( file_exists( $file_path ) ) {
					unlink( $file_path );
				}
			} else {
				// Delete from cron exports directory
				WCS_Exporter_Cron::delete_export_file( $file_name );
			}

			wp_redirect( admin_url( 'admin.php?page=export_subscriptions&tab=wcsi-exports&cron_deleted=true' ) );
			exit;
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
						wp_redirect( admin_url('admin.php?page=export_subscriptions&tab=wcsi-exports&cron_started=true') );
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
				<p><?php echo __( 'The export is scheduled. You can see the status and download the file on the "Exports" tab.', 'wcs-import-export' ); ?></p>
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
			// Appstle format requires Shopify credentials from WooCommerce settings
			$shopify_store_url = get_option( 'wcs_shopify_store_url', '' );
			$shopify_access_token = get_option( 'wcs_shopify_access_token', '' );
			if ( empty( $shopify_store_url ) || empty( $shopify_access_token ) ) {
				wp_send_json_error( array( 'message' => __( 'Appstle export format requires Shopify credentials. Please configure them in WooCommerce → Settings → Advanced → Shopify Migration.', 'wcs-import-export' ) ) );
			}
		} elseif ( empty( $csv_headers ) ) {
			// Standard format requires at least one CSV header
			wp_send_json_error( array( 'message' => __( 'No CSV headers were chosen. Please select at least one CSV header.', 'wcs-import-export' ) ) );
		}

		// Count total subscriptions to export
		$subscription_args = array(
			'subscriptions_per_page' => -1,
			'subscription_status'    => ! empty( $form_data['status'] ) ? array_keys( $form_data['status'] ) : array( 'any' ),
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
			// Secure the directory - deny direct access
			self::secure_export_directory( $export_dir );
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

		// Get user's custom filename and apply hash (same pattern as cron exports)
		$original_filename = isset( $form_data['filename'] ) ? sanitize_file_name( $form_data['filename'] ) : 'subscriptions.csv';
		$file_extension    = pathinfo( $original_filename, PATHINFO_EXTENSION );
		if ( empty( $file_extension ) ) {
			$file_extension = 'csv';
		}
		$handle            = str_replace( '.' . $file_extension, '', $original_filename );
		$final_filename    = $handle . '-' . wp_hash( time() . $handle ) . '.' . $file_extension;

		// Store export state in transient
		$export_state = array(
			'session_id'       => $session_id,
			'subscription_ids' => $subscription_ids,
			'csv_headers'      => $csv_headers,
			'export_format'    => $export_format,
			'filepath'         => $filepath,
			'filename'         => $filename,
			'final_filename'   => $final_filename,
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

		// Initialize Shopify API if configured in WooCommerce settings
		$shopify_store_url = get_option( 'wcs_shopify_store_url', '' );
		$shopify_access_token = get_option( 'wcs_shopify_access_token', '' );
		if ( ! empty( $shopify_store_url ) && ! empty( $shopify_access_token ) ) {
			$shopify_storefront_url = get_option( 'wcs_shopify_storefront_url', '' );
			$shopify_api = new WCS_Shopify_API(
				$shopify_store_url,
				$shopify_access_token,
				$shopify_storefront_url
			);
			WCS_Exporter::set_shopify_api( $shopify_api );
		}

		// Set up WCS_Exporter with file and headers
		WCS_Exporter::$file = $file;
		WCS_Exporter::$headers = $csv_headers;

		// Process batch
		$batch_processed = 0;
		foreach ( $batch_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			
			if ( ! $subscription ) {
				continue;
			}

			if ( $export_format === 'appstle' ) {
				// Appstle format export
				WCS_Exporter::write_appstle_csv_row( $subscription );
			} else {
				// Standard format export
				WCS_Exporter::write_subscriptions_csv_row( $subscription );
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
	public function ajax_export_complete() {
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

		// Rename file to final filename (so it persists for Exports tab)
		$final_filename = $export_state['final_filename'];
		$final_filepath = dirname( $export_state['filepath'] ) . '/' . $final_filename;
		rename( $export_state['filepath'], $final_filepath );

		// Clean up transient - no longer needed
		delete_transient( 'wcs_export_' . $session_id );

		// Generate secure download URL via WordPress AJAX endpoint
		$download_url = self::get_secure_download_url( $final_filename, 'ajax' );

		wp_send_json_success( array(
			'download_url' => $download_url,
			'filename'     => $final_filename,
		) );
	}

	/**
	 * AJAX handler for secure file downloads.
	 * Checks user capability and serves the file.
	 *
	 * @since 2.1.0
	 */
	public function ajax_download_export() {
		// Check user capability - this is sufficient security since only admins can download
		// No nonce used because download links in emails need to work after 24+ hours
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission to download this file.', 'wcs-import-export' ), 403 );
		}

		// Get file parameters
		$filename = isset( $_GET['file'] ) ? sanitize_file_name( $_GET['file'] ) : '';
		$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'ajax';

		if ( empty( $filename ) ) {
			wp_die( __( 'No file specified.', 'wcs-import-export' ), 400 );
		}

		// Determine directory based on type
		$upload_dir = wp_upload_dir();
		if ( 'cron' === $type ) {
			$file_path = $upload_dir['basedir'] . '/woocommerce-subscriptions-importer-exporter/' . $filename;
		} else {
			$file_path = $upload_dir['basedir'] . '/wcs-exports/' . $filename;
		}

		// Security: ensure filename doesn't contain path traversal
		if ( strpos( $filename, '..' ) !== false || strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false ) {
			wp_die( __( 'Invalid filename.', 'wcs-import-export' ), 400 );
		}

		// Check file exists and is a CSV
		if ( ! file_exists( $file_path ) || pathinfo( $file_path, PATHINFO_EXTENSION ) !== 'csv' ) {
			wp_die( __( 'File not found.', 'wcs-import-export' ), 404 );
		}

		// Serve the file
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Pragma: public' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );

		// Clear output buffer
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $file_path );
		exit;
	}

	/**
	 * Generate a secure download URL for an export file.
	 *
	 * @since 2.1.0
	 * @param string $filename The filename.
	 * @param string $type Either 'ajax' or 'cron'.
	 * @return string The secure download URL.
	 */
	public static function get_secure_download_url( $filename, $type = 'ajax' ) {
		return add_query_arg( array(
			'action' => 'wcs_download_export',
			'file'   => $filename,
			'type'   => $type,
		), admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Secure the export directory using .htaccess.
	 * Blocks ALL direct access - downloads go through WordPress AJAX.
	 *
	 * @since 2.1.0
	 * @param string $dir_path The directory path to secure.
	 */
	public static function secure_export_directory( $dir_path ) {
		// Create index.php to prevent directory listing
		$index_file = $dir_path . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '<?php // Silence is golden' );
		}

		// Create .htaccess that blocks ALL direct access
		// Downloads are served through WordPress AJAX endpoint which checks capabilities
		$htaccess_file = $dir_path . '.htaccess';
		$htaccess_content = "# Block all direct access to export files\n";
		$htaccess_content .= "# Downloads are served via WordPress AJAX with capability check\n";
		$htaccess_content .= "Order Deny,Allow\n";
		$htaccess_content .= "Deny from all\n";
		file_put_contents( $htaccess_file, $htaccess_content );
	}

	/**
	 * Ensure export directories are secured. Called on admin_init.
	 *
	 * @since 2.1.0
	 */
	public static function maybe_secure_export_directory() {
		$upload_dir = wp_upload_dir();
		
		// Secure AJAX exports directory
		$ajax_export_dir = $upload_dir['basedir'] . '/wcs-exports/';
		if ( file_exists( $ajax_export_dir ) ) {
			self::secure_export_directory( $ajax_export_dir );
		}
		
		// Secure Cron exports directory
		$cron_export_dir = $upload_dir['basedir'] . '/woocommerce-subscriptions-importer-exporter/';
		if ( file_exists( $cron_export_dir ) ) {
			self::secure_export_directory( $cron_export_dir );
		}
	}
}
