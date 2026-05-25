<?php
/**
 * WooCommerce integration — detection helpers and Theme Builder hints.
 *
 * WooCommerce ships 25 Divi 5 modules that are mostly meant to live
 * inside Divi Theme Builder templates (Shop archive, Single product,
 * Cart, Checkout), not inside standalone pages. This module gives the
 * AI two read-only endpoints so it can:
 *
 *  - probe the site's WooCommerce state (active? version? shop pages?
 *    are there already Theme Builder templates for the WC contexts?),
 *  - look up the canonical mapping of WC contexts to the modules
 *    typically used in them.
 *
 * The endpoints are intentionally read-only — actual template/layout
 * creation is performed via the existing `IAWM_Divi_Theme_Builder`
 * surface; this class only tells the agent **which modules to put
 * where**.
 *
 * Routes (all POST, JSON body):
 *  - /woocommerce/status    — audit() result
 *  - /woocommerce/contexts  — static template_contexts() list
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce detection + Theme Builder context catalog.
 */
class IAWM_WooCommerce {

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers WooCommerce routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/woocommerce/status'   => array( 'handle_status', 'guard_read' ),
			'/woocommerce/contexts' => array( 'handle_contexts', 'guard_read' ),
		);

		foreach ( $routes as $path => $config ) {
			register_rest_route(
				IAWM_REST_NAMESPACE,
				$path,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $config[0] ),
					'permission_callback' => array( 'IAWM_Auth', $config[1] ),
				)
			);
		}
	}

	/**
	 * Whether WooCommerce is installed AND active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'woocommerce/woocommerce.php' )
			|| class_exists( 'WooCommerce' );
	}

	/**
	 * Returns the Theme Builder template contexts WooCommerce introduces.
	 *
	 * Each entry has:
	 *  - slug              (string) — short identifier (shop, single-product, cart, checkout)
	 *  - label             (string) — human-readable label
	 *  - description       (string) — what the template represents
	 *  - use_on            (array)  — suggested Divi assignment expressions
	 *                                  for `template/assign` (`use_on`).
	 *  - suggested_modules (array)  — canonical Divi 5 module names typically
	 *                                  placed inside the body layout of that
	 *                                  template (cross-checked against
	 *                                  docs/divi5-modules-registry.json).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function template_contexts() {
		return array(
			array(
				'slug'              => 'shop',
				'label'             => 'Shop archive',
				'description'       => 'The main shop listing page (product archive).',
				'use_on'            => array( 'archive:product' ),
				'suggested_modules' => array(
					'divi/woocommerce-breadcrumb',
					'divi/shop',
				),
			),
			array(
				'slug'              => 'single-product',
				'label'             => 'Single product',
				'description'       => 'The product detail page.',
				'use_on'            => array( 'singular:product' ),
				'suggested_modules' => array(
					'divi/woocommerce-breadcrumb',
					'divi/woocommerce-product-images',
					'divi/woocommerce-product-gallery',
					'divi/woocommerce-product-title',
					'divi/woocommerce-product-rating',
					'divi/woocommerce-product-price',
					'divi/woocommerce-product-stock',
					'divi/woocommerce-product-description',
					'divi/woocommerce-product-add-to-cart',
					'divi/woocommerce-product-meta',
					'divi/woocommerce-product-additional-info',
					'divi/woocommerce-product-tabs',
					'divi/woocommerce-product-reviews',
					'divi/woocommerce-related-products',
					'divi/woocommerce-product-upsell',
				),
			),
			array(
				'slug'              => 'cart',
				'label'             => 'Cart',
				'description'       => 'Shopping cart page.',
				'use_on'            => array( 'page:cart' ),
				'suggested_modules' => array(
					'divi/woocommerce-cart-notice',
					'divi/woocommerce-cart-products',
					'divi/woocommerce-cart-totals',
					'divi/woocommerce-cross-sells',
				),
			),
			array(
				'slug'              => 'checkout',
				'label'             => 'Checkout',
				'description'       => 'Checkout flow.',
				'use_on'            => array( 'page:checkout' ),
				'suggested_modules' => array(
					'divi/woocommerce-checkout-billing',
					'divi/woocommerce-checkout-shipping',
					'divi/woocommerce-checkout-additional-info',
					'divi/woocommerce-checkout-order-details',
					'divi/woocommerce-checkout-payment-info',
				),
			),
		);
	}

	/**
	 * Builds an audit of the current site's WooCommerce setup.
	 *
	 * Shape:
	 *  - is_active       (bool)
	 *  - version         (string|null) — WC()->version when available
	 *  - products_count  (int)         — published products
	 *  - currency        (string|null) — woocommerce_currency
	 *  - pages           ({shop, cart, checkout, myaccount}: int|null)
	 *  - has_template_for ({shop, single_product, cart, checkout}: bool)
	 *  - templates       (array)       — raw Theme Builder template summary
	 *                                    (id, title, use_on) when detection
	 *                                    succeeded, otherwise empty array.
	 *
	 * @return array<string, mixed>
	 */
	public static function audit() {
		$active = self::is_active();

		$version = null;
		if ( $active && function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && isset( $wc->version ) ) {
				$version = (string) $wc->version;
			}
		}

		$products_count = 0;
		if ( post_type_exists( 'product' ) ) {
			$counts = wp_count_posts( 'product' );
			if ( is_object( $counts ) && isset( $counts->publish ) ) {
				$products_count = (int) $counts->publish;
			}
		}

		$currency = $active ? (string) get_option( 'woocommerce_currency', '' ) : null;
		if ( '' === $currency ) {
			$currency = null;
		}

		$pages = array(
			'shop'      => self::page_id_or_null( 'woocommerce_shop_page_id' ),
			'cart'      => self::page_id_or_null( 'woocommerce_cart_page_id' ),
			'checkout'  => self::page_id_or_null( 'woocommerce_checkout_page_id' ),
			'myaccount' => self::page_id_or_null( 'woocommerce_myaccount_page_id' ),
		);

		$tb_templates    = self::collect_theme_builder_templates();
		$has_template_for = array(
			'shop'           => self::has_template_matching( $tb_templates, array( 'archive:product' ) ),
			'single_product' => self::has_template_matching( $tb_templates, array( 'singular:product' ) ),
			'cart'           => self::has_template_matching(
				$tb_templates,
				array_filter( array(
					'page:cart',
					null !== $pages['cart'] ? 'page:' . $pages['cart'] : null,
				) )
			),
			'checkout'       => self::has_template_matching(
				$tb_templates,
				array_filter( array(
					'page:checkout',
					null !== $pages['checkout'] ? 'page:' . $pages['checkout'] : null,
				) )
			),
		);

		return array(
			'is_active'        => $active,
			'version'          => $version,
			'products_count'   => $products_count,
			'currency'         => $currency,
			'pages'            => $pages,
			'has_template_for' => $has_template_for,
			'templates'        => $tb_templates,
		);
	}

	/**
	 * Helper: reads a WC page id option and returns a sane int or null.
	 *
	 * @param string $option Option name.
	 * @return int|null
	 */
	protected static function page_id_or_null( $option ) {
		$id = (int) get_option( $option, 0 );
		return $id > 0 ? $id : null;
	}

	/**
	 * Returns a compact summary of Theme Builder templates currently
	 * defined on the site: [{ id, title, use_on }, ...].
	 *
	 * Relies on `IAWM_Divi_Theme_Builder::handle_list()`. If that call
	 * fails (e.g. Divi not active), returns an empty array — the caller
	 * still gets a useful audit otherwise.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected static function collect_theme_builder_templates() {
		if ( ! class_exists( 'IAWM_Divi_Theme_Builder' ) ) {
			return array();
		}

		$req = new WP_REST_Request( 'POST', '/' . IAWM_REST_NAMESPACE . '/divi/theme-builder/list' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'live' => true ) ) );

		$response = IAWM_Divi_Theme_Builder::handle_list( $req );
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return array();
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
			return array();
		}

		$summary = array();
		foreach ( $data['templates'] as $tmpl ) {
			$summary[] = array(
				'id'     => isset( $tmpl['id'] ) ? (int) $tmpl['id'] : 0,
				'title'  => isset( $tmpl['title'] ) ? (string) $tmpl['title'] : '',
				'use_on' => isset( $tmpl['use_on'] ) && is_array( $tmpl['use_on'] ) ? array_values( $tmpl['use_on'] ) : array(),
			);
		}
		return $summary;
	}

	/**
	 * Whether any of the templates targets one of the given conditions.
	 *
	 * @param array<int, array<string, mixed>> $templates Compact template list.
	 * @param array<int, string>               $needles   Conditions to match.
	 * @return bool
	 */
	protected static function has_template_matching( $templates, $needles ) {
		if ( empty( $templates ) || empty( $needles ) ) {
			return false;
		}
		foreach ( $templates as $tmpl ) {
			$use_on = isset( $tmpl['use_on'] ) && is_array( $tmpl['use_on'] ) ? $tmpl['use_on'] : array();
			foreach ( $needles as $needle ) {
				if ( in_array( $needle, $use_on, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * POST /woocommerce/status — returns the audit() result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		unset( $request );
		return new WP_REST_Response(
			array_merge( array( 'ok' => true ), self::audit() ),
			200
		);
	}

	/**
	 * POST /woocommerce/contexts — returns the static template_contexts() list.
	 *
	 * Includes is_active so the caller can decide whether to act on the
	 * suggestions or surface a "WooCommerce is not installed" warning.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_contexts( $request ) {
		unset( $request );
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'is_active' => self::is_active(),
				'contexts'  => self::template_contexts(),
			),
			200
		);
	}
}
