<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Midtrans
 */

class FlutterMidtrans extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_midtrans';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_midtrans_routes'));
    }

    public function register_flutter_midtrans_routes()
    {
        register_rest_route($this->namespace, '/generate_snap_token', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'generate_snap_token'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

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

    public function generate_snap_token($request)
    {
        if (!is_plugin_active('midtrans-woocommerce/midtrans-gateway.php')) {
            return parent::send_invalid_plugin_error("You need to install Midtrans WooCommerce Payment Gateway plugin to use this api");
        }
        $json = file_get_contents('php://input');
        $body = json_decode($json, true);

        // FIX: Fetch order early to calculate gross_amount from the actual order total,
        // preventing client-side amount tampering.
        $order_id = sanitize_text_field($body['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found.', array('status' => 404));
        }
        $order_total = (float) $order->get_total();

        if ($body['currency'] != 'IDR') {
            $options = get_option('woocommerce_midtrans_settings');
            if ($options && !empty($options['to_idr_rate'])) {
                $params = array(
                    'transaction_details' => array(
                        'order_id' => $order_id,
                        'gross_amount' => $order_total * floatval($options['to_idr_rate']),
                    )
                );
            }
        }

        if (!isset($params)) {
            $params = array(
                'transaction_details' => array(
                    'order_id' => $order_id,
                    'gross_amount' => $order_total,
                )
            );
        }

        require_once ABSPATH . 'wp-content/plugins/midtrans-woocommerce/midtrans-gateway.php';
        $snapResponse = WC_Midtrans_API::createSnapTransactionHandleDuplicate($order, $params, 'midtrans');

        return $snapResponse;
    }

    public function payment_success($request)
    {
        $json = file_get_contents('php://input');
        $body = json_decode($json, true);

        $order_id       = isset($body['order_id']) ? sanitize_text_field($body['order_id']) : null;
        $transaction_id = isset($body['transaction_id']) ? sanitize_text_field($body['transaction_id']) : null;

        if (empty($order_id) || empty($transaction_id)) {
            return new WP_Error('missing_parameters', 'Order ID and Transaction ID are required.', array('status' => 400));
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

        // Prevent processing if order is already paid or cancelled
        if (!$order->needs_payment()) {
            return rest_ensure_response(array('status' => 'success', 'message' => 'Order already processed'));
        }

        // REPLAY ATTACK PREVENTION: Check if transaction_id is used for another order
        $existing_orders = wc_get_orders(array(
            'transaction_id' => $transaction_id,
            'limit'          => 1,
            'return'         => 'ids',
            'exclude'        => array($order_id)
        ));

        if (!empty($existing_orders)) {
            $order->add_order_note('Security Alert: Transaction ID has already been used for another order. Replay attack blocked.');
            return new WP_Error('replay_attack', 'This payment transaction was already used.', array('status' => 403));
        }

        // Fetch Midtrans settings directly from WooCommerce
        $midtrans_settings = get_option('woocommerce_midtrans_settings', []);

        // Handle possible different key names depending on the plugin version
        $server_key  = isset($midtrans_settings['server_key_v2']) ? $midtrans_settings['server_key_v2'] : (isset($midtrans_settings['server_key']) ? $midtrans_settings['server_key'] : '');
        $environment = isset($midtrans_settings['environment']) ? $midtrans_settings['environment'] : 'sandbox';

        if (empty($server_key)) {
             return new WP_Error('midtrans_misconfigured', 'Midtrans server key is missing.', array('status' => 500));
        }

        // 1. VERIFY STATUS DIRECTLY WITH MIDTRANS API (Server-to-Server)
        // FIX: Midtrans API supports status check via transaction_id.
        // This avoids 404s caused by the -1, -2 suffixes appended to the original order_id.
        $base_url   = ($environment === 'production') ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2';
        $verify_url = $base_url . '/' . $transaction_id . '/status';

        $response = wp_remote_get($verify_url, array(
            'headers' => array(
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($server_key . ':')
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('midtrans_verification_failed', 'Could not verify payment with Midtrans.', array('status' => 502));
        }

        $status_data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($status_data) || !isset($status_data['transaction_status'])) {
             return new WP_Error('invalid_response', 'Invalid response from Midtrans.', array('status' => 502));
        }

        $transaction_status = $status_data['transaction_status'];
        $fraud_status       = isset($status_data['fraud_status']) ? $status_data['fraud_status'] : 'accept';

        // 2. CHECK TRANSACTION & FRAUD STATUS
        // Success statuses: 'settlement' (e-wallet/bank transfer) or 'capture' (credit card without fraud challenge)
        if ($transaction_status === 'capture') {
            if ($fraud_status === 'challenge') {
                 $order->update_status('on-hold', 'Midtrans payment challenged by FDS. Awaiting manual review.');
                 return new WP_Error('payment_challenged', 'Payment is challenged.', array('status' => 400));
            }
        } elseif ($transaction_status !== 'settlement') {
            $order->add_order_note('Security Alert: Midtrans payment failed or pending. Status: ' . $transaction_status);
            return new WP_Error('payment_not_settled', 'Payment is not fully settled.', array('status' => 400));
        }

        // 3. SECURE AMOUNT MATCHING (Handling Currency Exchange Rate)
        $order_total     = (float) $order->get_total();
        $expected_amount = $order_total;

        // If the store currency is NOT IDR, we must apply the conversion rate before matching
        if ($order->get_currency() !== 'IDR' && !empty($midtrans_settings['to_idr_rate'])) {
             $expected_amount = $order_total * (float) $midtrans_settings['to_idr_rate'];
        }

        $paid_amount = isset($status_data['gross_amount']) ? (float) $status_data['gross_amount'] : 0;

        // FIX: Use absolute difference for safer and more precise floating-point comparison.
        if (abs($expected_amount - $paid_amount) > 0.01) {
            $order->update_status('on-hold', sprintf('SECURITY ALERT: Midtrans amount mismatch. Expected: %s, Paid: %s.', $expected_amount, $paid_amount));
            return new WP_Error('amount_mismatch', 'Paid amount does not match order total.', array('status' => 400));
        }

        // ALL CHECKS PASSED: Finalize payment
        $order->payment_complete($transaction_id);
        $order->add_order_note('Midtrans Verified: S2S Payment successful. Transaction ID: ' . esc_html($transaction_id));

        return rest_ensure_response(array('status' => 'success'));
    }
}

new FlutterMidtrans;