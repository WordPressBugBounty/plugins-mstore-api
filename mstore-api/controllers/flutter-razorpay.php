<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package FlutterRazorpay
 */

class FlutterRazorpay extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_razorpay';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_razorpay_routes'));
    }

    public function register_flutter_razorpay_routes()
    {
        register_rest_route($this->namespace, '/payment_success', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'payment_success'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

    }

    public function payment_success()
    {
        if (!is_plugin_active('woo-razorpay/woo-razorpay.php')) {
            return parent::send_invalid_plugin_error("You need to install Razorpay for WooCommerce plugin to use this api");
        }

        $json = file_get_contents('php://input');
        $body = json_decode($json, TRUE);
        $orderId = $body['orderId'];
        $razorpayPaymentId = $body['razorpayPaymentId'];
        $order = wc_get_order($orderId);
        if($order){                                   
            $order->payment_complete($razorpayPaymentId);
            $order->add_order_note("Razorpay payment successful <br/>Razorpay Id: $razorpayPaymentId");
        }
        return  true;
    }
}

new FlutterRazorpay;