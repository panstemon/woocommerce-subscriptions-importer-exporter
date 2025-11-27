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
		wp_enqueue_script( 'wcs-exporter-admin', WCS_Importer_Exporter::plugin_url() . 'assets/js/wcs-exporter.js' );
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
			'wcsi-export'  => __( 'Export', 'wcs-import-export' ),
			'wcsi-headers' => __( 'CSV Headers', 'wcs-import-export' ),
			'wcsi-cron-exports' => __( 'Cron exports', 'wcs-import-export' )
		);

		$current_tab = ( empty( $_GET['tab'] ) ) ? 'wcsi-export' : urldecode( $_GET['tab'] );

		foreach ( $tabs as $tab_id => $tab_title ) {

			$class = ( $tab_id == $current_tab ) ? array( 'nav-tab', 'nav-tab-active', 'wcsi-exporter-tabs' ) : array( 'nav-tab', 'wcsi-exporter-tabs' );

			echo '<a href="#" id="' . esc_attr( $tab_id ) . '" class="' . esc_attr( implode( ' ', array_map( 'sanitize_html_class', $class ) ) ) . '">' . esc_html( $tab_title ) . '</a>';

		}

		echo '</h2>';
		echo '<form class="wcsi-exporter-form" method="POST" action="' . esc_attr( add_query_arg( 'step', 'download' ) ) . '">';
		$this->home_page();
		echo '<p class="submit">';
		echo '<input data-action="' . esc_attr( add_query_arg( 'step', 'download' ) ) . '" type="submit" class="button" value="' . esc_html__( 'Export Subscriptions', 'wcs-import-export' ) . '" />';
		echo '&nbsp;&nbsp;<input data-action="' . esc_attr( add_query_arg( 'step', 'cron-export' ) ) . '" type="submit" class="button" value="' . esc_html__( 'Export Subscriptions (using cron)', 'wcs-import-export' ) . '" />';
		echo '</p>';
		wp_nonce_field( 'wcsie-exporter-home', 'wcsie_wpnonce' );
		echo '</form>';
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
			wp_redirect( admin_url('admin.php?page=export_subscriptions&cron_deleted=true') );
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
						wp_redirect( admin_url('admin.php?page=export_subscriptions&cron_started=true') );
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
}
