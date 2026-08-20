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
 * @package FlutterPhonePe
 */

class FlutterPhonePe extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_phonepe';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_phonepe_routes'));
    }

    public function register_flutter_phonepe_routes()
    {
        register_rest_route($this->namespace, '/callback', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'callback'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    public function callback($request)
    {
        $json = file_get_contents('php://input');
        $body = json_decode($json, true);
        $response_b64 = isset($body['response']) ? $body['response'] : '';

        if (empty($response_b64)) {
            return new WP_Error('invalid_callback', 'Empty response from PhonePe', array('status' => 400));
        }

        // 1. Get PhonePe config from WooCommerce
        // FIX: Removed Vietnamese comment to maintain standard English codebase.
        // NOTE: Ensure WP Admin enters correct UAT credentials into WooCommerce Settings
        $phonepe_settings = get_option('woocommerce_phonepe_settings');
        $salt_key = isset($phonepe_settings['salt_key']) ? $phonepe_settings['salt_key'] : '';
        $salt_index = isset($phonepe_settings['salt_index']) ? $phonepe_settings['salt_index'] : '1';
        $merchant_id = isset($phonepe_settings['merchant_id']) ? $phonepe_settings['merchant_id'] : '';

        // Auto-detect Sandbox/UAT based on Merchant ID or test mode setting
        $is_test_mode = (isset($phonepe_settings['testmode']) && $phonepe_settings['testmode'] === 'yes') || (strpos($merchant_id, 'UAT') !== false);

        $headers = array_change_key_case(getallheaders(), CASE_UPPER);
        $checksum = isset($headers['X-VERIFY']) ? $headers['X-VERIFY'] : (isset($_SERVER['HTTP_X_VERIFY']) ? $_SERVER['HTTP_X_VERIFY'] : '');

        if (empty($checksum) || empty($salt_key) || empty($merchant_id)) {
            return new WP_Error('invalid_config', 'Checksum or settings missing. Cannot verify callback.', array('status' => 400));
        }

        // 2. Validate Checksum (PhonePe S2S Standard)
        $server_checksum = hash('sha256', $response_b64 . $salt_key) . '###' . $salt_index;

        if (!hash_equals($server_checksum, $checksum)) {
            return new WP_Error('invalid_checksum', 'Checksum mismatch. Potential forgery.', array('status' => 401));
        }

        $decoded = json_decode(base64_decode($response_b64), true);

        if (!$decoded || !isset($decoded['data']['merchantTransactionId'])) {
            return new WP_Error('invalid_response', 'Invalid response data from PhonePe.', array('status' => 400));
        }

        $merchant_transaction_id = $decoded['data']['merchantTransactionId'];

        // 3. Extract Order ID safely (Handles both formats: "123_TIME" or just "123")
        // FIX: Keep the legacy 14-character slice as a fallback for older app versions to prevent 404s.
        $pos = strrpos($merchant_transaction_id, '_');
        if ($pos !== false) {
            $order_id = substr($merchant_transaction_id, 0, $pos);
        } else {
            // Fallback for legacy fstore app generating 14-char suffix without separator
            $order_id = substr($merchant_transaction_id, 0, -14);
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', array('status' => 404));
        }

        // Prevent Replay Attack
        if (!$order->needs_payment()) {
            return rest_ensure_response(array('status' => 'success', 'message' => 'Order already processed'));
        }

        // 4. Server-to-Server Verification (Bypass protection)
        $host = $is_test_mode ? "https://api-preprod.phonepe.com/apis/pg-sandbox" : "https://api.phonepe.com/apis/hermes";
        $status_endpoint = "/pg/v1/status/{$merchant_id}/{$merchant_transaction_id}";
        $status_url = $host . $status_endpoint;

        $status_checksum_data = $status_endpoint . $salt_key;
        $status_checksum = hash('sha256', $status_checksum_data) . '###' . $salt_index;

        $response = wp_remote_get($status_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-VERIFY' => $status_checksum,
                'X-MERCHANT-ID' => $merchant_id
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('status_check_failed', 'Failed to verify transaction status via S2S.', array('status' => 500));
        }

        $status_data = json_decode(wp_remote_retrieve_body($response), true);

        // 5. Final validation and status update
        if ($status_data && isset($status_data['success']) && $status_data['success'] === true) {
            if ($status_data['code'] === 'PAYMENT_SUCCESS') {

                // Bulletproof Amount Validation (Convert both to paise/integer for exact match)
                $order_total_paise = intval(round((float) $order->get_total() * 100));
                $response_amount_paise = isset($status_data['data']['amount']) ? intval($status_data['data']['amount']) : 0;

                if ($order_total_paise === $response_amount_paise) {
                    $order->payment_complete($merchant_transaction_id);
                    $order->add_order_note("PhonePe Verified: S2S Payment successful. Transaction ID: " . $merchant_transaction_id);

                    // FIX: Explicitly return success ONLY here.
                    return rest_ensure_response(array('status' => 'success'));

                } else {
                    // FIX: Use add_order_note to prevent sabotage and explicitly return WP_Error
                    $order->add_order_note(sprintf('Security Alert: PhonePe amount mismatch. Order total: %d paise, Paid: %d paise. Order status unchanged.', $order_total_paise, $response_amount_paise));
                    return new WP_Error('amount_mismatch', 'Amount paid does not match order total.', array('status' => 400));
                }
            } else {
                // FIX: Prevent order sabotage and return proper WP_Error
                $order->add_order_note("Security Alert: PhonePe Payment Failed (S2S). Reason: " . esc_html($status_data['message']));
                return new WP_Error('payment_failed', 'Payment failed: ' . esc_html($status_data['message']), array('status' => 400));
            }
        } else {
            // FIX: Prevent order sabotage and return proper WP_Error
            $order->add_order_note("Security Alert: PhonePe S2S validation failed.");
            return new WP_Error('validation_failed', 'S2S validation failed.', array('status' => 400));
        }

        // We can safely remove the final return statement here since all branches now return explicitly.
    }
}

new FlutterPhonePe;
