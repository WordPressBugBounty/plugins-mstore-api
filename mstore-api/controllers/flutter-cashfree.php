<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package FlutterCashfree
 */

class FlutterCashfree extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_cashfree';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_cashfree_routes'));
    }

    public function register_flutter_cashfree_routes()
    {
        register_rest_route($this->namespace, '/create_order', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_order'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    /**
     * Create Cashfree order using plugin adapter to avoid exposing keys on app.
     */
    public function create_order($request)
    {
        $gateway = $this->get_cashfree_gateway();
        if (is_wp_error($gateway)) {
            return $gateway;
        }

        $body = $this->read_json_body();
        $raw_order_id = isset($body['orderId']) ? $body['orderId'] : null;

        if ($raw_order_id === null || $raw_order_id === '') {
            return new WP_Error('invalid_order', 'Missing orderId', array('status' => 400));
        }

        if (!is_numeric($raw_order_id)) {
            return new WP_Error('invalid_order', 'Invalid orderId', array('status' => 400));
        }

        $order_id = absint($raw_order_id);
        if ($order_id <= 0) {
            return new WP_Error('invalid_order', 'Invalid orderId', array('status' => 400));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }

        if ($order->get_payment_method() !== 'cashfree') {
            return new WP_Error('invalid_payment_method', 'Order payment method is not cashfree', array('status' => 400));
        }

        try {
            $adapter = new WC_Cashfree_Adapter($gateway);
            $result = $adapter->checkout($order_id);

            if (!is_array($result) || empty($result['payment_session_id']) || empty($result['order_id'])) {
                return new WP_Error('invalid_response', 'Invalid response from Cashfree adapter', array('status' => 500));
            }

            return array(
                'order_id' => $result['order_id'],
                'payment_session_id' => $result['payment_session_id'],
                'environment' => isset($result['environment']) ? $result['environment'] : 'sandbox',
            );
        } catch (Exception $e) {
            return new WP_Error('create_order_failed', $e->getMessage(), array('status' => 500));
        }
    }

    private function get_cashfree_gateway()
    {
        if (!is_plugin_active('cashfree/cashfree.php')) {
            return parent::send_invalid_plugin_error('You need to install Cashfree WooCommerce plugin to use this api');
        }

        if (!class_exists('WC_Cashfree_Adapter')) {
            require_once WP_PLUGIN_DIR . '/cashfree/includes/http/class-wc-cashfree-adapter.php';
        }

        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return new WP_Error('gateway_unavailable', 'WooCommerce payment gateways unavailable', array('status' => 500));
        }

        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($payment_gateways['cashfree'])) {
            return new WP_Error('gateway_unavailable', 'Cashfree gateway unavailable', array('status' => 500));
        }

        return $payment_gateways['cashfree'];
    }

    private function read_json_body()
    {
        $json = file_get_contents('php://input');
        if (empty($json)) {
            return array();
        }

        $body = json_decode($json, true);
        return is_array($body) ? $body : array();
    }
}

new FlutterCashfree;
