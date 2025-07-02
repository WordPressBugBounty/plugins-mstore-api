<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package DiscountRules
 */

class FlutterDiscountRules extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_discount_rules';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_discount_rules_routes'));
    }

    public function register_flutter_discount_rules_routes()
    {
        register_rest_route($this->namespace, '/calculate_checkout_totals', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'calculate_checkout_totals'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    public function calculate_checkout_totals($request)
    {
        if (!class_exists('Wdr\App\Controllers\ManageDiscount')) {
            return parent::send_invalid_plugin_error("You need to install Discount Rules for WooCommerce plugin to use this api");
        }

        $json = file_get_contents('php://input');
        $body = json_decode($json, TRUE);

        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        if (isset($cookie) && $cookie != null) {
            $user_id = validateCookieLogin($cookie);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            $user = get_userdata($user_id);
            wp_set_current_user($user_id, $user->user_login);
            $cookie_elements = explode('|', $cookie);
            if (count($cookie_elements) == 4) {
                list($username, $expiration, $token, $hmac) = $cookie_elements;
                $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
                wp_set_auth_cookie($user_id, true, '', $token);
            }
        }
        
        if (null === WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');

            WC()->session = new $session_class();
            WC()->session->init();
        }

        if (null === WC()->customer) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }

        if (null === WC()->cart) {
            WC()->cart = new WC_Cart();
        }
        WC()->cart->empty_cart(true);

        $products = $body['line_items'];

        add_filter( 'woocommerce_is_purchasable', '__return_true', 9999 );
        buildCartItemData($products, function($productId, $quantity, $variationId, $attributes, $cart_item_data){
            global $woocommerce;
            $woocommerce->cart->add_to_cart($productId, $quantity, $variationId, $attributes, $cart_item_data);
        });
        remove_filter( 'woocommerce_is_purchasable', '__return_true' );
        WC()->cart->calculate_totals();

        $cart = WC()->cart;
        $cart_contents = array_values(array_map(function ($item) {
            return [
                'product_id'   => $item['product_id'],
                'variation_id' => $item['variation_id'],
                'quantity'     => $item['quantity'],
                'line_total'   => $item['line_total'],
                'is_awdr_free_product' => !empty( $item['wdr_free_product'] )  && $item['wdr_free_product'] == 'Free'
            ];
        }, $cart->get_cart()));
    
        return [
            'cart_contents' => $cart_contents,
            'coupon_discount_totals' => $cart->get_coupon_discount_totals(),
        ];
    }
}

new FlutterDiscountRules;