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
		$query = '
			query GetProductByWooId($query: String!) {
				products(first: 1, query: $query) {
					edges {
						node {
							id
							title
							variants(first: 100) {
								edges {
									node {
										id
										title
										metafields(first: 10) {
											edges {
												node {
													namespace
													key
													value
												}
											}
										}
									}
								}
							}
							metafields(first: 10) {
								edges {
									node {
										namespace
										key
										value
									}
								}
							}
						}
					}
				}
			}
		';

		// Search for products with the woo.id metafield matching the WooCommerce product ID
		$variables = array(
			'query' => sprintf( 'metafields.woo.id:%d', $woo_product_id ),
		);

		$response = $this->graphql_request( $query, $variables );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check if product was found
		if ( empty( $response['data']['products']['edges'] ) ) {
			// Try alternative search method - search all products and check metafields
			return $this->get_shopify_product_by_woo_id_fallback( $woo_product_id );
		}

		$product = $response['data']['products']['edges'][0]['node'];
		
		// Extract numeric ID from the global ID (e.g., "gid://shopify/Product/123456789")
		$shopify_product_id = $this->extract_numeric_id( $product['id'] );
		
		// Get the first variant ID (or you can match by variant metafield if needed)
		$shopify_variant_id = '';
		if ( ! empty( $product['variants']['edges'] ) ) {
			$shopify_variant_id = $this->extract_numeric_id( $product['variants']['edges'][0]['node']['id'] );
		}

		$result = array(
			'shopify_product_id' => $shopify_product_id,
			'shopify_variant_id' => $shopify_variant_id,
			'product_title'      => $product['title'],
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

		// First try to find by variation ID in variant metafields
		$query = '
			query GetProductsWithVariantMetafields($first: Int!) {
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
				}
			}
		';

		$variables = array(
			'first' => 250,
		);

		$response = $this->graphql_request( $query, $variables );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Search for the product and variant
		if ( ! empty( $response['data']['products']['edges'] ) ) {
			foreach ( $response['data']['products']['edges'] as $edge ) {
				$product = $edge['node'];
				
				// Check if this is the right product (by parent woo.id)
				$product_woo_id = isset( $product['metafield']['value'] ) ? intval( $product['metafield']['value'] ) : 0;
				
				if ( $product_woo_id === intval( $woo_product_id ) || $product_woo_id === 0 ) {
					// Look for the variant with matching woo.id
					if ( ! empty( $product['variants']['edges'] ) ) {
						foreach ( $product['variants']['edges'] as $variant_edge ) {
							$variant = $variant_edge['node'];
							$variant_woo_id = isset( $variant['metafield']['value'] ) ? intval( $variant['metafield']['value'] ) : 0;
							
							if ( $variant_woo_id === intval( $woo_variation_id ) ) {
								$result = array(
									'shopify_product_id' => $this->extract_numeric_id( $product['id'] ),
									'shopify_variant_id' => $this->extract_numeric_id( $variant['id'] ),
									'product_title'      => $product['title'] . ' - ' . $variant['title'],
								);

								$this->product_cache[ $cache_key ] = $result;
								return $result;
							}
						}
					}
				}
			}
		}

		// If variation not found, try to get the parent product
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
