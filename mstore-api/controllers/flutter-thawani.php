<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Thawani
 */

class FlutterThawani extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_thawani';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_thawani_routes'));
    }

    public function register_flutter_thawani_routes()
    {
        register_rest_route($this->namespace, '/order_success', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'update_order_success'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    public function update_order_success($request)
    {
        $json = file_get_contents('php://input');
        $body = json_decode($json, true);

        $order_id   = isset($body['order_id']) ? sanitize_text_field($body['order_id']) : null;
        $session_id = isset($body['session_token']) ? sanitize_text_field($body['session_token']) : null;

        if (empty($order_id) || empty($session_id)) {
            return new WP_Error('missing_parameters', 'Order ID and Session Token are required.', array('status' => 400));
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

        // SECURITY CHECK 1: Prevent double processing
        if (!$order->needs_payment()) {
            return rest_ensure_response(array('success' => true, 'message' => 'Order already processed.'));
        }

        // SECURITY CHECK 2: Replay Attack Prevention (HPOS Compatible)
        // FIX: Query by native transaction_id instead of meta_key for HPOS compatibility
        $existing_orders = wc_get_orders(array(
            'transaction_id' => $session_id,
            'status'         => array('processing', 'completed', 'on-hold'),
            'exclude'        => array($order_id),
            'limit'          => 1,
            'return'         => 'ids'
        ));

        if (!empty($existing_orders)) {
            // FIX: Use add_order_note to prevent order sabotage
            $order->add_order_note('Security Alert: Thawani session ID has already been used for another order. Replay attack blocked.');
            return new WP_Error('replay_attack', 'This payment session was already used.', array('status' => 403));
        }

        // Safely get Thawani gateway settings from WooCommerce
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        if (!isset($payment_gateways['thawani_gw'])) {
            return new WP_Error('gateway_not_found', 'Thawani gateway (thawani_gw) is not active.', array('status' => 500));
        }

        $thawani_gateway = $payment_gateways['thawani_gw'];

        // Handle possible different key names depending on the plugin version
        $secret_key = isset($thawani_gateway->settings['secret_key']) ? $thawani_gateway->settings['secret_key'] : (isset($thawani_gateway->settings['api_key']) ? $thawani_gateway->settings['api_key'] : '');
        $is_test_mode = (isset($thawani_gateway->settings['test_mode']) && $thawani_gateway->settings['test_mode'] === 'yes');

        if (empty($secret_key)) {
            return new WP_Error('thawani_misconfigured', 'Thawani Secret Key is not configured.', array('status' => 500));
        }

        // Set correct API endpoints based on environment
        $base_url   = $is_test_mode ? 'https://uatcheckout.thawani.om/api/v1' : 'https://checkout.thawani.om/api/v1';
        $verify_url = $base_url . '/checkout/session/' . $session_id;

        // SECURITY CHECK 3: Server-to-Server Verification
        $response = wp_remote_get($verify_url, array(
            'headers' => array(
                'Thawani-Api-Key' => $secret_key,
                'Content-Type'    => 'application/json'
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // FIX: Use add_order_note to prevent order sabotage
            $order->add_order_note('Security Alert: Thawani S2S payment verification failed.');
            return new WP_Error('thawani_verification_failed', 'Could not verify payment with Thawani.', array('status' => 502));
        }

        $payment_data = json_decode(wp_remote_retrieve_body($response), true);

        // SECURITY CHECK 4: Validate Payment Status
        if (empty($payment_data['success']) || !isset($payment_data['data']) || strtolower($payment_data['data']['payment_status']) !== 'paid') {
            $status = $payment_data['data']['payment_status'] ?? 'unknown';
            // FIX: Use add_order_note to prevent order sabotage
            $order->add_order_note(sprintf('Security Alert: Thawani payment status is %s.', esc_html($status)));
            return new WP_Error('thawani_payment_not_paid', 'Payment status is not PAID.', array('status' => 400));
        }

        $session_data = $payment_data['data'];

        // SECURITY CHECK 5: Details and Amount Matching (OMR uses 3 decimal places -> multiplier 1000)
        $multiplier      = 1000;
        $expected_amount = (int) round((float) $order->get_total() * $multiplier);
        $paid_amount     = isset($session_data['total_amount']) ? (int) $session_data['total_amount'] : 0;

        if ((string) $session_data['client_reference_id'] !== (string) $order_id) {
            // FIX: Prevent order sabotage by using add_order_note instead of update_status('on-hold')
            $order->add_order_note('Security Alert: Thawani Client Reference ID mismatch. Order status left unchanged.');
            return new WP_Error('thawani_order_mismatch', 'Transaction does not match the order.', array('status' => 400));
        }

        if ($expected_amount !== $paid_amount) {
            // FIX: Prevent order sabotage by using add_order_note instead of update_status('on-hold')
            $order->add_order_note(sprintf('Security Alert: Thawani amount mismatch. Expected: %d, Paid: %d. Order status left unchanged.', $expected_amount, $paid_amount));
            return new WP_Error('thawani_amount_mismatch', 'Paid amount does not match order total.', array('status' => 400));
        }

        // All checks passed. Complete the payment.
        // FIX: payment_complete($session_id) already stores the transaction_id natively.
        // Dropped the custom '_thawani_session_id' meta update which was dead code.
        $order->payment_complete($session_id);
        $order->add_order_note('Thawani Verified: S2S Payment successful.<br/>Session ID: ' . esc_html($session_id));

        // Return exact format expected by Flutter app
        return rest_ensure_response(array('success' => true));
    }
}

new FlutterThawani;