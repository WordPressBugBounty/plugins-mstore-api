<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Checkout
 */

class FlutterCheckout extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_checkout';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_checkout_routes'));
    }

    public function register_flutter_checkout_routes()
    {
        register_rest_route($this->namespace, '/checkout_fields', array(
            array(
                'methods' => "GET",
                'callback' => array($this, 'get_checkout_fields'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/checkout_fields/countries', array(
            array(
                'methods' => "GET",
                'callback' => array($this, 'get_checkout_countries'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/customer', array(
            array(
                'methods' => "GET",
                'callback' => array($this, 'get_customer_info'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    private function array_omit(array $source, array $fields): array {
        $result = array_map(function($item) use ($fields) {
            return array_diff_key($item, array_flip($fields));
        }, $source);
        return $result;
    }

    public function get_checkout_fields($request)
    {
        $billing_fields = WC()->checkout->get_checkout_fields('billing');
        $shipping_fields = WC()->checkout->get_checkout_fields('shipping');
        $additional_fields = WC()->checkout->get_checkout_fields('additional');

        $omit_fields = ['class', 'position', 'extra_class'];

        return  [
            'billing' => $this->array_omit(array_values($billing_fields), $omit_fields),
            'shipping' => $this->array_omit(array_values($shipping_fields), $omit_fields),
            'additional' => $this->array_omit(array_values($additional_fields), $omit_fields),
        ];
    }

    public function get_checkout_countries($request){
        $key = $request->get_param('key');

        if (!$key) {
            return new WP_Error(
                'missing_key',
                __('The "key" query parameter is required.', 'text-domain'),
                array('status' => 400)
            );
        }

        $array = 'shipping_country' === $key ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();

        $keys = array_keys($array);
        $countries = array();
        for ($i = 0; $i < count($keys); $i++) {
            $countries[] = ["code" => $keys[$i], "name" => $array[$keys[$i]]];
        }
        return $countries;
    }

    public function get_customer_info($request) {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        if (isset($cookie) && $cookie != null) {
            $user_id = validateCookieLogin($cookie);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            
            wp_set_current_user($user_id);

            // Get checkout fields
            $billing_fields = WC()->checkout->get_checkout_fields('billing');
            $shipping_fields = WC()->checkout->get_checkout_fields('shipping');
            $additional_fields = WC()->checkout->get_checkout_fields('additional');
            
            // Get billing values
            $billing_data = array();
            foreach ($billing_fields as $key => $field) {
                $billing_data[str_replace('billing_', '', $key)] = WC()->checkout->get_value($key);
            }
            
            // Get shipping values
            $shipping_data = array();
            foreach ($shipping_fields as $key => $field) {
                $shipping_data[str_replace('shipping_', '', $key)] = WC()->checkout->get_value($key);
            }
            
            // Get additional values
            $additional_data = array();
            foreach ($additional_fields as $key => $field) {
                $additional_data[$key] = WC()->checkout->get_value($key);
            }

            return [
                'billing' => $billing_data,
                'shipping' => $shipping_data,
                'additional' => $additional_data
            ];
        } else {
            return parent::sendError("cookie_required","User-Cookie is required", 400);
        }
    }
}

new FlutterCheckout;
