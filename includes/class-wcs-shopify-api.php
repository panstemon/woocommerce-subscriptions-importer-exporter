<?php
/**
 * Shopify API Integration for WooCommerce Subscriptions Exporter
 *
 * Handles GraphQL requests to Shopify to fetch product and variant IDs
 *
 * @since 2.1
 */
class WCS_Shopify_API {

	/**
	 * Shopify store URL
	 *
	 * @var string
	 */
	private $store_url;

	/**
	 * Shopify access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Cache for product lookups to minimize API calls
	 *
	 * @var array
	 */
	private $product_cache = array();

	/**
	 * Cache for selling plans to minimize API calls
	 *
	 * @var array
	 */
	private $selling_plans_cache = array();

	/**
	 * Constructor
	 *
	 * @param string $store_url Shopify store URL (e.g., mystore.myshopify.com)
	 * @param string $access_token Shopify Admin API access token
	 */
	public function __construct( $store_url, $access_token ) {
		$this->store_url    = rtrim( $store_url, '/' );
		$this->access_token = $access_token;
	}

	/**
	 * Check if the API is configured
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->store_url ) && ! empty( $this->access_token );
	}

	/**
	 * Make a GraphQL request to Shopify
	 *
	 * @param string $query GraphQL query
	 * @param array $variables Query variables
	 * @return array|WP_Error Response data or error
	 */
	private function graphql_request( $query, $variables = array() ) {
		// Ensure the URL is properly formatted
		$url = $this->store_url;
		
		// Add https:// if not present
		if ( strpos( $url, 'http://' ) !== 0 && strpos( $url, 'https://' ) !== 0 ) {
			$url = 'https://' . $url;
		}
		
		// Add /admin/api/2024-01/graphql.json endpoint
		$endpoint = trailingslashit( $url ) . 'admin/api/2024-01/graphql.json';

		$body = array(
			'query' => $query,
		);

		if ( ! empty( $variables ) ) {
			$body['variables'] = $variables;
		}

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Content-Type'                 => 'application/json',
				'X-Shopify-Access-Token'       => $this->access_token,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $response_code !== 200 ) {
			return new WP_Error( 
				'shopify_api_error', 
				sprintf( 
					__( 'Shopify API error: %s', 'wcs-import-export' ), 
					isset( $data['errors'] ) ? wp_json_encode( $data['errors'] ) : $response_body 
				)
			);
		}

		if ( isset( $data['errors'] ) && ! empty( $data['errors'] ) ) {
			return new WP_Error( 
				'shopify_graphql_error', 
				sprintf( 
					__( 'Shopify GraphQL error: %s', 'wcs-import-export' ), 
					wp_json_encode( $data['errors'] ) 
				)
			);
		}

		return $data;
	}

	/**
	 * Get Shopify product data by WooCommerce product ID (stored in woo.id metafield)
	 *
	 * @param int $woo_product_id WooCommerce product ID
	 * @return array|WP_Error Array with 'shopify_product_id' and 'shopify_variant_id', or error
	 */
	public function get_shopify_product_by_woo_id( $woo_product_id ) {
		// Check cache first
		if ( isset( $this->product_cache[ $woo_product_id ] ) ) {
			return $this->product_cache[ $woo_product_id ];
		}

		// GraphQL query to find product by woo.id metafield
		// Using inline query with quoted metafield value for reliable filtering
		$query = sprintf(
			'query GetProductByMetafield {
				products(first: 20, query: "metafields.woo.id:\"%s\"") {
					edges {
						node {
							id
							title
							handle
							metafield(namespace: "woo", key: "id") {
								value
								type
							}
							variants(first: 100) {
								edges {
									node {
										id
										title
										metafield(namespace: "woo", key: "id") {
											value
										}
									}
								}
							}
						}
					}
				}
			}',
			esc_attr( $woo_product_id )
		);

		$response = $this->graphql_request( $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if product was found
		if ( empty( $response['data']['products']['edges'] ) ) {
			// Try alternative search method - search all products and check metafields
			return $this->get_shopify_product_by_woo_id_fallback( $woo_product_id );
		}

		// Find the exact match by verifying the metafield value
		$matched_product = null;
		foreach ( $response['data']['products']['edges'] as $edge ) {
			$product = $edge['node'];
			if ( isset( $product['metafield']['value'] ) && $product['metafield']['value'] === strval( $woo_product_id ) ) {
				$matched_product = $product;
				break;
			}
		}

		// If no exact match found, use the first result (fallback)
		if ( ! $matched_product ) {
			if ( ! empty( $response['data']['products']['edges'] ) ) {
				$matched_product = $response['data']['products']['edges'][0]['node'];
			} else {
				return $this->get_shopify_product_by_woo_id_fallback( $woo_product_id );
			}
		}

		// Extract numeric ID from the global ID (e.g., "gid://shopify/Product/123456789")
		$shopify_product_id = $this->extract_numeric_id( $matched_product['id'] );
		
		// Get the first variant ID (or you can match by variant metafield if needed)
		$shopify_variant_id = '';
		if ( ! empty( $matched_product['variants']['edges'] ) ) {
			$shopify_variant_id = $this->extract_numeric_id( $matched_product['variants']['edges'][0]['node']['id'] );
		}

		$result = array(
			'shopify_product_id' => $shopify_product_id,
			'shopify_variant_id' => $shopify_variant_id,
			'product_title'      => $matched_product['title'],
			'handle'             => isset( $matched_product['handle'] ) ? $matched_product['handle'] : '',
		);

		// Cache the result
		$this->product_cache[ $woo_product_id ] = $result;

		return $result;
	}

	/**
	 * Fallback method to find Shopify product by WooCommerce ID using alternative approach
	 *
	 * @param int $woo_product_id WooCommerce product ID
	 * @return array|WP_Error
	 */
	private function get_shopify_product_by_woo_id_fallback( $woo_product_id ) {
		// Alternative query - get products and check their metafields
		$query = '
			query GetProductsWithMetafields($first: Int!) {
				products(first: $first) {
					edges {
						node {
							id
							title
							metafield(namespace: "woo", key: "id") {
								value
							}
							variants(first: 100) {
								edges {
									node {
										id
										title
										metafield(namespace: "woo", key: "id") {
											value
										}
									}
								}
							}
						}
					}
					pageInfo {
						hasNextPage
						endCursor
					}
				}
			}
		';

		$variables = array(
			'first' => 250, // Maximum allowed by Shopify
		);

		$response = $this->graphql_request( $query, $variables );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Search through products for matching woo.id
		if ( ! empty( $response['data']['products']['edges'] ) ) {
			foreach ( $response['data']['products']['edges'] as $edge ) {
				$product = $edge['node'];
				
				// Check product-level metafield
				if ( isset( $product['metafield']['value'] ) && intval( $product['metafield']['value'] ) === intval( $woo_product_id ) ) {
					$shopify_product_id = $this->extract_numeric_id( $product['id'] );
					$shopify_variant_id = '';
					
					if ( ! empty( $product['variants']['edges'] ) ) {
						$shopify_variant_id = $this->extract_numeric_id( $product['variants']['edges'][0]['node']['id'] );
					}

					$result = array(
						'shopify_product_id' => $shopify_product_id,
						'shopify_variant_id' => $shopify_variant_id,
						'product_title'      => $product['title'],
					);

					$this->product_cache[ $woo_product_id ] = $result;
					return $result;
				}

				// Also check variant-level metafields for variable products
				if ( ! empty( $product['variants']['edges'] ) ) {
					foreach ( $product['variants']['edges'] as $variant_edge ) {
						$variant = $variant_edge['node'];
						if ( isset( $variant['metafield']['value'] ) && intval( $variant['metafield']['value'] ) === intval( $woo_product_id ) ) {
							$result = array(
								'shopify_product_id' => $this->extract_numeric_id( $product['id'] ),
								'shopify_variant_id' => $this->extract_numeric_id( $variant['id'] ),
								'product_title'      => $product['title'] . ' - ' . $variant['title'],
							);

							$this->product_cache[ $woo_product_id ] = $result;
							return $result;
						}
					}
				}
			}
		}

		// Product not found
		$result = array(
			'shopify_product_id' => '',
			'shopify_variant_id' => '',
			'product_title'      => '',
			'error'              => sprintf( __( 'Product with WooCommerce ID %d not found in Shopify', 'wcs-import-export' ), $woo_product_id ),
		);

		$this->product_cache[ $woo_product_id ] = $result;
		return $result;
	}

	/**
	 * Get Shopify product data by WooCommerce variation ID
	 *
	 * @param int $woo_product_id Parent product ID
	 * @param int $woo_variation_id Variation ID
	 * @return array|WP_Error
	 */
	public function get_shopify_variant_by_woo_id( $woo_product_id, $woo_variation_id ) {
		// Check cache first using variation ID as key
		$cache_key = $woo_product_id . '_' . $woo_variation_id;
		if ( isset( $this->product_cache[ $cache_key ] ) ) {
			return $this->product_cache[ $cache_key ];
		}

		// First, try to find the parent product by woo.id and then match the variant
		$query = sprintf(
			'query GetProductByMetafield {
				products(first: 20, query: "metafields.woo.id:\"%s\"") {
					edges {
						node {
							id
							title
							handle
							metafield(namespace: "woo", key: "id") {
								value
								type
							}
							variants(first: 100) {
								edges {
									node {
										id
										title
										metafield(namespace: "woo", key: "id") {
											value
										}
									}
								}
							}
						}
					}
				}
			}',
			esc_attr( $woo_product_id )
		);

		$response = $this->graphql_request( $query );

		if ( ! is_wp_error( $response ) && ! empty( $response['data']['products']['edges'] ) ) {
			// Find the parent product and look for the variant
			foreach ( $response['data']['products']['edges'] as $edge ) {
				$product = $edge['node'];
				
				// Verify this is the correct parent product
				if ( isset( $product['metafield']['value'] ) && $product['metafield']['value'] === strval( $woo_product_id ) ) {
					// Look for the variant with matching woo.id
					if ( ! empty( $product['variants']['edges'] ) ) {
						foreach ( $product['variants']['edges'] as $variant_edge ) {
							$variant = $variant_edge['node'];
							$variant_woo_id = isset( $variant['metafield']['value'] ) ? $variant['metafield']['value'] : '';
							
							if ( $variant_woo_id === strval( $woo_variation_id ) ) {
								$result = array(
									'shopify_product_id' => $this->extract_numeric_id( $product['id'] ),
									'shopify_variant_id' => $this->extract_numeric_id( $variant['id'] ),
									'product_title'      => $product['title'] . ' - ' . $variant['title'],
									'handle'             => isset( $product['handle'] ) ? $product['handle'] : '',
								);

								$this->product_cache[ $cache_key ] = $result;
								return $result;
							}
						}
					}
					
					// Parent found but variant not matched - return first variant as fallback
					if ( ! empty( $product['variants']['edges'] ) ) {
						$first_variant = $product['variants']['edges'][0]['node'];
						$result = array(
							'shopify_product_id' => $this->extract_numeric_id( $product['id'] ),
							'shopify_variant_id' => $this->extract_numeric_id( $first_variant['id'] ),
							'product_title'      => $product['title'] . ' - ' . $first_variant['title'],
							'handle'             => isset( $product['handle'] ) ? $product['handle'] : '',
						);

						$this->product_cache[ $cache_key ] = $result;
						return $result;
					}
				}
			}
		}

		// Second approach: Try to find by variation ID directly (variant might have its own woo.id metafield)
		$query_by_variation = sprintf(
			'query GetProductByVariantMetafield {
				products(first: 20, query: "metafields.woo.id:\"%s\"") {
					edges {
						node {
							id
							title
							handle
							metafield(namespace: "woo", key: "id") {
								value
								type
							}
							variants(first: 100) {
								edges {
									node {
										id
										title
										metafield(namespace: "woo", key: "id") {
											value
										}
									}
								}
							}
						}
					}
				}
			}',
			esc_attr( $woo_variation_id )
		);

		$response = $this->graphql_request( $query_by_variation );

		if ( ! is_wp_error( $response ) && ! empty( $response['data']['products']['edges'] ) ) {
			foreach ( $response['data']['products']['edges'] as $edge ) {
				$product = $edge['node'];
				
				// Check if any variant has the matching woo.id
				if ( ! empty( $product['variants']['edges'] ) ) {
					foreach ( $product['variants']['edges'] as $variant_edge ) {
						$variant = $variant_edge['node'];
						$variant_woo_id = isset( $variant['metafield']['value'] ) ? $variant['metafield']['value'] : '';
						
						if ( $variant_woo_id === strval( $woo_variation_id ) ) {
							$result = array(
								'shopify_product_id' => $this->extract_numeric_id( $product['id'] ),
								'shopify_variant_id' => $this->extract_numeric_id( $variant['id'] ),
								'product_title'      => $product['title'] . ' - ' . $variant['title'],
								'handle'             => isset( $product['handle'] ) ? $product['handle'] : '',
							);

							$this->product_cache[ $cache_key ] = $result;
							return $result;
						}
					}
				}
				
				// If product-level metafield matches the variation ID (simple product case)
				if ( isset( $product['metafield']['value'] ) && $product['metafield']['value'] === strval( $woo_variation_id ) ) {
					$shopify_variant_id = '';
					if ( ! empty( $product['variants']['edges'] ) ) {
						$shopify_variant_id = $this->extract_numeric_id( $product['variants']['edges'][0]['node']['id'] );
					}
					
					$result = array(
						'shopify_product_id' => $this->extract_numeric_id( $product['id'] ),
						'shopify_variant_id' => $shopify_variant_id,
						'product_title'      => $product['title'],
						'handle'             => isset( $product['handle'] ) ? $product['handle'] : '',
					);

					$this->product_cache[ $cache_key ] = $result;
					return $result;
				}
			}
		}

		// If variation not found, try to get the parent product as last resort
		$parent_result = $this->get_shopify_product_by_woo_id( $woo_product_id );
		
		if ( ! is_wp_error( $parent_result ) && ! empty( $parent_result['shopify_product_id'] ) ) {
			$this->product_cache[ $cache_key ] = $parent_result;
			return $parent_result;
		}

		// Not found
		$result = array(
			'shopify_product_id' => '',
			'shopify_variant_id' => '',
			'product_title'      => '',
			'error'              => sprintf( __( 'Variant with WooCommerce ID %d not found in Shopify', 'wcs-import-export' ), $woo_variation_id ),
		);

		$this->product_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Extract numeric ID from Shopify global ID
	 *
	 * @param string $global_id Shopify global ID (e.g., "gid://shopify/Product/123456789")
	 * @return string Numeric ID
	 */
	private function extract_numeric_id( $global_id ) {
		if ( empty( $global_id ) ) {
			return '';
		}
		
		// Extract the numeric portion from the global ID
		$parts = explode( '/', $global_id );
		return end( $parts );
	}

	/**
	 * Clear the product cache
	 */
	public function clear_cache() {
		$this->product_cache = array();
		$this->selling_plans_cache = array();
	}

	/**
	 * Get selling plan ID for a product variant based on billing interval and period
	 *
	 * @since 2.1
	 * @param string $shopify_product_id Shopify product ID (numeric)
	 * @param string $shopify_variant_id Shopify variant ID (numeric)
	 * @param string $billing_interval Billing interval (e.g., 1, 2, 3)
	 * @param string $billing_period Billing period (e.g., 'day', 'week', 'month', 'year')
	 * @return array Array with 'selling_plan_id' and 'selling_plan_name', or empty values if not found
	 */
	public function get_selling_plan_for_product( $shopify_product_id, $shopify_variant_id, $billing_interval, $billing_period ) {
		if ( empty( $shopify_product_id ) ) {
			return array(
				'selling_plan_id'   => '',
				'selling_plan_name' => '',
			);
		}

		// Create cache key
		$cache_key = $shopify_product_id . '_' . $billing_interval . '_' . $billing_period;

		if ( isset( $this->selling_plans_cache[ $cache_key ] ) ) {
			return $this->selling_plans_cache[ $cache_key ];
		}

		// Normalize billing period to Shopify format
		$shopify_interval_unit = $this->convert_woo_period_to_shopify( $billing_period );

		if ( empty( $shopify_interval_unit ) ) {
			return array(
				'selling_plan_id'   => '',
				'selling_plan_name' => '',
				'error'             => sprintf( __( 'Unknown billing period: %s', 'wcs-import-export' ), $billing_period ),
			);
		}

		// Fetch selling plans for the product
		$selling_plans = $this->fetch_selling_plans_for_product( $shopify_product_id );

		if ( is_wp_error( $selling_plans ) ) {
			return array(
				'selling_plan_id'   => '',
				'selling_plan_name' => '',
				'error'             => $selling_plans->get_error_message(),
			);
		}

		// Find matching selling plan based on interval and period
		$result = $this->match_selling_plan( $selling_plans, $billing_interval, $shopify_interval_unit );

		// Cache the result
		$this->selling_plans_cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Fetch all selling plans for a specific product from Shopify
	 *
	 * @since 2.1
	 * @param string $shopify_product_id Shopify product ID (numeric)
	 * @return array|WP_Error Array of selling plans or WP_Error on failure
	 */
	private function fetch_selling_plans_for_product( $shopify_product_id ) {
		// GraphQL query to fetch selling plans for a product
		// Using optimized query that fetches selling plan groups and their plans
		$query = '
			query GetProductSellingPlans($productId: ID!) {
				product(id: $productId) {
					id
					title
					sellingPlanGroups(first: 20) {
						edges {
							node {
								id
								name
								appId
								sellingPlans(first: 50) {
									edges {
										node {
											id
											name
											description
											options
											billingPolicy {
												... on SellingPlanRecurringBillingPolicy {
													interval
													intervalCount
												}
												... on SellingPlanFixedBillingPolicy {
													remainingBalanceChargeTimeAfterCheckout
												}
											}
											deliveryPolicy {
												... on SellingPlanRecurringDeliveryPolicy {
													interval
													intervalCount
												}
												... on SellingPlanFixedDeliveryPolicy {
													anchors {
														day
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		';

		$variables = array(
			'productId' => 'gid://shopify/Product/' . $shopify_product_id,
		);

		$response = $this->graphql_request( $query, $variables );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Extract selling plans from response
		$selling_plans = array();

		if ( empty( $response['data']['product']['sellingPlanGroups']['edges'] ) ) {
			return $selling_plans;
		}

		foreach ( $response['data']['product']['sellingPlanGroups']['edges'] as $group_edge ) {
			$group = $group_edge['node'];
			$group_name = $group['name'];

			if ( empty( $group['sellingPlans']['edges'] ) ) {
				continue;
			}

			foreach ( $group['sellingPlans']['edges'] as $plan_edge ) {
				$plan = $plan_edge['node'];

				$selling_plans[] = array(
					'id'              => $this->extract_numeric_id( $plan['id'] ),
					'global_id'       => $plan['id'],
					'name'            => $plan['name'],
					'description'     => isset( $plan['description'] ) ? $plan['description'] : '',
					'group_name'      => $group_name,
					'billing_policy'  => isset( $plan['billingPolicy'] ) ? $plan['billingPolicy'] : array(),
					'delivery_policy' => isset( $plan['deliveryPolicy'] ) ? $plan['deliveryPolicy'] : array(),
				);
			}
		}

		return $selling_plans;
	}

	/**
	 * Match a selling plan based on billing interval and period
	 *
	 * @since 2.1
	 * @param array $selling_plans Array of selling plans
	 * @param string $billing_interval Billing interval (e.g., 1, 2, 3)
	 * @param string $shopify_interval_unit Shopify interval unit (DAY, WEEK, MONTH, YEAR)
	 * @return array Array with 'selling_plan_id' and 'selling_plan_name'
	 */
	private function match_selling_plan( $selling_plans, $billing_interval, $shopify_interval_unit ) {
		if ( empty( $selling_plans ) ) {
			return array(
				'selling_plan_id'   => '',
				'selling_plan_name' => '',
			);
		}

		$billing_interval = intval( $billing_interval );

		foreach ( $selling_plans as $plan ) {
			// Check billing policy for recurring billing
			if ( ! empty( $plan['billing_policy'] ) ) {
				$policy = $plan['billing_policy'];

				// Check if it's a recurring billing policy with matching interval
				if ( isset( $policy['interval'] ) && isset( $policy['intervalCount'] ) ) {
					$plan_interval = strtoupper( $policy['interval'] );
					$plan_interval_count = intval( $policy['intervalCount'] );

					if ( $plan_interval === $shopify_interval_unit && $plan_interval_count === $billing_interval ) {
						return array(
							'selling_plan_id'   => $plan['id'],
							'selling_plan_name' => $plan['name'],
							'selling_plan_group' => $plan['group_name'],
						);
					}
				}
			}

			// Fallback: Check delivery policy if billing policy doesn't match
			if ( ! empty( $plan['delivery_policy'] ) ) {
				$policy = $plan['delivery_policy'];

				if ( isset( $policy['interval'] ) && isset( $policy['intervalCount'] ) ) {
					$plan_interval = strtoupper( $policy['interval'] );
					$plan_interval_count = intval( $policy['intervalCount'] );

					if ( $plan_interval === $shopify_interval_unit && $plan_interval_count === $billing_interval ) {
						return array(
							'selling_plan_id'   => $plan['id'],
							'selling_plan_name' => $plan['name'],
							'selling_plan_group' => $plan['group_name'],
						);
					}
				}
			}
		}

		// No exact match found, return first selling plan as fallback (if any exist)
		return array(
			'selling_plan_id'   => '',
			'selling_plan_name' => '',
			'no_match'          => sprintf(
				__( 'No selling plan found matching %d %s', 'wcs-import-export' ),
				$billing_interval,
				$shopify_interval_unit
			),
		);
	}

	/**
	 * Convert WooCommerce billing period to Shopify interval unit
	 *
	 * @since 2.1
	 * @param string $woo_period WooCommerce period (day, week, month, year)
	 * @return string Shopify interval unit (DAY, WEEK, MONTH, YEAR)
	 */
	private function convert_woo_period_to_shopify( $woo_period ) {
		$period_map = array(
			'day'   => 'DAY',
			'days'  => 'DAY',
			'week'  => 'WEEK',
			'weeks' => 'WEEK',
			'month' => 'MONTH',
			'months' => 'MONTH',
			'year'  => 'YEAR',
			'years' => 'YEAR',
		);

		$woo_period = strtolower( trim( $woo_period ) );

		return isset( $period_map[ $woo_period ] ) ? $period_map[ $woo_period ] : '';
	}

	/**
	 * Get all selling plans for a product (public method for debugging/testing)
	 *
	 * @since 2.1
	 * @param string $shopify_product_id Shopify product ID
	 * @return array|WP_Error
	 */
	public function get_product_selling_plans( $shopify_product_id ) {
		return $this->fetch_selling_plans_for_product( $shopify_product_id );
	}

	/**
	 * Test the connection to Shopify
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function test_connection() {
		$query = '
			query {
				shop {
					name
					primaryDomain {
						url
					}
				}
			}
		';

		$response = $this->graphql_request( $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['data']['shop']['name'] ) ) {
			return true;
		}

		return new WP_Error( 'shopify_connection_failed', __( 'Failed to connect to Shopify store', 'wcs-import-export' ) );
	}
}
