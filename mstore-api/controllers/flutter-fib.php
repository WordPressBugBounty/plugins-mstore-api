<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package FIB
 */

class FlutterFIB extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_fib';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_fib_routes'));
    }

    public function register_flutter_fib_routes()
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

    public function payment_success($request)
    {
        $json = file_get_contents('php://input');
        $body = json_decode($json, true);

        $order_id   = isset($body['order_id']) ? sanitize_text_field($body['order_id']) : null;
        $payment_id = isset($body['payment_id']) ? sanitize_text_field($body['payment_id']) : null;

        if (empty($order_id) || empty($payment_id)) {
            return new WP_Error('missing_parameters', 'Order ID and Payment ID are required.', array('status' => 400));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found.', array('status' => 404));
        }

        // SECURITY CHECK 0: Authorise the caller against this specific order.
        // These routes are otherwise gated only by the site-global purchase-code
        // check, so nothing ties the caller to the order they name.
        $key_check = mstore_api_check_payment_order_key($order, $body);
        if (is_wp_error($key_check)) {
            return $key_check;
        }

        // Prevent double processing
        if (!$order->needs_payment()) {
            return rest_ensure_response(array('status' => 'success', 'message' => 'Order already processed'));
        }

        // INDEPENDENT REPLAY ATTACK PREVENTION:
        // Check if this payment_id has already been used for ANY OTHER order in the database
        $existing_orders = wc_get_orders(array(
            'transaction_id' => $payment_id,
            'limit'          => 1,
            'return'         => 'ids',
            'exclude'        => array($order_id)
        ));

        if (!empty($existing_orders)) {
            $order->add_order_note('Security Alert: Payment ID has already been used for another order. Replay attack blocked.');
            return new WP_Error('replay_attack', 'This payment transaction was already used.', array('status' => 403));
        }

        // Fetch FIB config from WooCommerce Settings (Admin must input Client ID & Secret)
        $fib_settings  = get_option('woocommerce_fib_settings', []);
        $client_id     = isset($fib_settings['client_id']) ? $fib_settings['client_id'] : '';
        $client_secret = isset($fib_settings['client_secret']) ? $fib_settings['client_secret'] : '';
        $mode          = isset($fib_settings['mode']) ? $fib_settings['mode'] : 'stage';

        if (empty($client_id) || empty($client_secret)) {
            return new WP_Error('fib_misconfigured', 'FIB is not configured.', array('status' => 500));
        }

        // 1. GET ACCESS TOKEN (Server-to-Server Implementation)
        $token_url = "https://fib.{$mode}.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token";

        $token_response = wp_remote_post($token_url, array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($token_response) || wp_remote_retrieve_response_code($token_response) !== 200) {
            return new WP_Error('fib_token_failed', 'Failed to authenticate with FIB server.', array('status' => 500));
        }

        $token_body = json_decode(wp_remote_retrieve_body($token_response), true);
        $access_token = isset($token_body['access_token']) ? $token_body['access_token'] : '';

        if (empty($access_token)) {
            return new WP_Error('fib_token_missing', 'Invalid token response from FIB.', array('status' => 500));
        }

        // 2. VERIFY PAYMENT STATUS WITH FIB API
        $status_url = "https://fib.{$mode}.fib.iq/protected/v1/payments/{$payment_id}/status";

        $status_response = wp_remote_get($status_url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($status_response) || wp_remote_retrieve_response_code($status_response) !== 200) {
            return new WP_Error('fib_status_failed', 'Could not verify payment status with FIB.', array('status' => 502));
        }

        $status_body = json_decode(wp_remote_retrieve_body($status_response), true);

        // 3. SECURITY VALIDATION & ORDER COMPLETION
        if (isset($status_body['status']) && strtoupper($status_body['status']) === 'PAID') {

            // Advanced Security: Verify amount match to prevent parameter tampering on the client side
            if (isset($status_body['monetaryValue']['amount'])) {
                $order_total = (float) $order->get_total();
                $paid_amount = (float) $status_body['monetaryValue']['amount'];

                if ($order_total != $paid_amount) {
                    $order->update_status('on-hold', sprintf('SECURITY ALERT: FIB amount mismatch. Expected: %s, Paid: %s.', $order_total, $paid_amount));
                    return new WP_Error('amount_mismatch', 'Paid amount does not match order total.', array('status' => 400));
                }
            }

            // Mark order as complete and permanently bind the Transaction ID to prevent Replay Attacks
            $order->payment_complete($payment_id);
            $order->add_order_note('FIB Verified: S2S Payment successful.<br/>Payment ID: ' . esc_html($payment_id));

        } else {
            $order->add_order_note('Security Alert: FIB S2S Check: Payment failed or not paid. Status: ' . esc_html($status_body['status'] ?? 'Unknown'));
            return new WP_Error('payment_not_paid', 'Payment is not PAID.', array('status' => 400));
        }

        return rest_ensure_response(array('status' => 'success'));
    }
}

new FlutterFIB;