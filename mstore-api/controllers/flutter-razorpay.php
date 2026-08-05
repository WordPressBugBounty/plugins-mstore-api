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
     * Register all routes related with stores
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
        $body = json_decode($json, true);

        // Fix Payload mapping: Matches EXACTLY what the Flutter app sends (camelCase)
        $order_id   = isset($body['orderId']) ? sanitize_text_field($body['orderId']) : '';
        $payment_id = isset($body['razorpayPaymentId']) ? sanitize_text_field($body['razorpayPaymentId']) : '';

        if (empty($order_id) || empty($payment_id)) {
            return new WP_Error('invalid_request', 'Invalid request. Missing orderId or razorpayPaymentId.', array('status' => 400));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found.', array('status' => 404));
        }

        // SECURITY CHECK 0: Authorise the caller against this specific order.
        // These routes are otherwise gated only by the site-global purchase-code
        // check, so nothing ties the caller to the order they name.
        $key_check = mstore_api_check_payment_order_key($order, $body);
        if (is_wp_error($key_check)) {
            return $key_check;
        }

        // SECURITY CHECK 1: Prevent double processing for this specific order
        if (!$order->needs_payment()) {
            return rest_ensure_response(array('status' => 'success', 'message' => 'Order already processed.'));
        }

        // SECURITY CHECK 2: Replay Attack Prevention (Cross-order Check)
        // Ensure this Razorpay Payment ID hasn't been used to complete ANY other order
        $existing_orders = wc_get_orders(array(
            'transaction_id' => $payment_id,
            'limit'          => 1,
            'return'         => 'ids',
            'exclude'        => array($order_id)
        ));

        if (!empty($existing_orders)) {
            // FIX: Prevent order sabotage and return proper WP_Error
            $order->add_order_note('Security Alert: Razorpay payment ID is already bound to another order. Order status unchanged.');
            return new WP_Error('replay_attack', 'This payment transaction was already used.', array('status' => 403));
        }

        // Fetch Razorpay credentials from WooCommerce settings
        $razorpay_settings = get_option('woocommerce_razorpay_settings');
        $key_id = isset($razorpay_settings['key_id']) ? $razorpay_settings['key_id'] : '';
        $secret = isset($razorpay_settings['key_secret']) ? $razorpay_settings['key_secret'] : '';

        if (empty($key_id) || empty($secret)) {
            return new WP_Error('invalid_configuration', 'Razorpay API keys are not configured.', array('status' => 500));
        }

        // SECURITY CHECK 3: Server-to-Server Verification (Directly ask Razorpay)
        $url  = 'https://api.razorpay.com/v1/payments/' . $payment_id;
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($key_id . ':' . $secret)
            ),
            'timeout' => 15
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // FIX: Prevent order sabotage and return proper WP_Error
            $order->add_order_note('Security Alert: Razorpay S2S verification could not be completed. Order status unchanged.');
            return new WP_Error('s2s_verification_failed', 'Could not verify payment with Razorpay.', array('status' => 502));
        }

        $payment_data = json_decode(wp_remote_retrieve_body($response), true);

        // SECURITY CHECK 4: Validate Payment Status is 'captured'
        // NOTE: stores configured for manual capture report 'authorized' here, which is
        // deliberately not treated as paid.
        if (!isset($payment_data['status']) || $payment_data['status'] !== 'captured') {
            // FIX: Prevent order sabotage and return proper WP_Error
            $order->add_order_note('Security Alert: Razorpay payment not captured. Status: ' . esc_html($payment_data['status'] ?? 'unknown') . '. Order status unchanged.');
            return new WP_Error('payment_not_captured', 'Payment was not captured.', array('status' => 400));
        }

        // SECURITY CHECK 5: Amount Matching (Razorpay returns amount in paise/cents)
        $order_total_paise = intval(round((float) $order->get_total() * 100));
        $paid_amount_paise = isset($payment_data['amount']) ? intval($payment_data['amount']) : 0;

        if ($order_total_paise !== $paid_amount_paise) {
            $order->update_status('on-hold', sprintf('SECURITY ALERT: Razorpay amount mismatch. Expected: %d, Paid: %d.', $order_total_paise, $paid_amount_paise));
            return new WP_Error('amount_mismatch', 'Paid amount does not match order total.', array('status' => 400));
        }

        // SECURITY CHECK 6: Currency Matching
        $paid_currency = isset($payment_data['currency']) ? (string) $payment_data['currency'] : '';
        if (strtoupper($order->get_currency()) !== strtoupper($paid_currency)) {
            $order->update_status('on-hold', sprintf('SECURITY ALERT: Razorpay currency mismatch. Expected: %s, Paid: %s.', $order->get_currency(), $paid_currency));
            return new WP_Error('currency_mismatch', 'Paid currency does not match order currency.', array('status' => 400));
        }

        // SECURITY CHECK 7: Order Association Check
        // The Flutter app sends 'woocommerce_order_id' in notes when creating the order.
        // We verify that the payment actually belongs to this WooCommerce order.
        $notes_order_id = isset($payment_data['notes']['woocommerce_order_id']) ? (string) $payment_data['notes']['woocommerce_order_id'] : '';
        if (!empty($notes_order_id) && $notes_order_id !== (string) $order_id) {
            $order->update_status('on-hold', 'SECURITY ALERT: Payment notes do not match Order ID.');
            return new WP_Error('order_mismatch', 'Payment belongs to a different order.', array('status' => 400));
        }

        // All checks passed. Complete the payment.
        $order->payment_complete($payment_id);

        // Add note indicating successful S2S verification
        $order->add_order_note("Razorpay Verified: S2S Payment successful.<br/>Payment ID: " . esc_html($payment_id));

        return rest_ensure_response(array('status' => 'success'));
    }
}

new FlutterRazorpay;