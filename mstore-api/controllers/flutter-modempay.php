<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Modempay
 */

class FlutterModempay extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_modempay';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_modempay_routes'));
    }

    public function register_flutter_modempay_routes()
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

    /**
     * Reuse logic from the payment gateway plugin
     * Source: https://docs.modempay.com/plugins/woocommerce
     * Webhook receiver for ModemPay
     */
    public function payment_success($request)
    {
        // Parse webhook payload
        $json = file_get_contents('php://input');
        $webhook_data = json_decode($json, true);

        // SECURITY CHECK 1: Verify Webhook Signature (CRITICAL)
        // Robust header fetching to avoid Nginx stripping issues
        $headers = array_change_key_case(getallheaders(), CASE_UPPER);
        $signature = isset($headers['X-MODEM-SIGNATURE']) ? $headers['X-MODEM-SIGNATURE'] : (isset($headers['X-MODEMPAY-SIGNATURE']) ? $headers['X-MODEMPAY-SIGNATURE'] : '');

        if (empty($signature)) {
            // Fallback to $_SERVER if getallheaders() missed it
            $signature = isset($_SERVER['HTTP_X_MODEM_SIGNATURE']) ? $_SERVER['HTTP_X_MODEM_SIGNATURE'] : (isset($_SERVER['HTTP_X_MODEMPAY_SIGNATURE']) ? $_SERVER['HTTP_X_MODEMPAY_SIGNATURE'] : '');
        }

        if (empty($signature)) {
            return new WP_Error('missing_signature', 'Webhook signature is missing.', array('status' => 401));
        }

        // FIX: Drop the secret_key fallback. Webhook secret must be strictly used.
        $modempay_settings = get_option('woocommerce_modempay_settings', []);
        $webhook_secret = isset($modempay_settings['webhook_secret']) ? $modempay_settings['webhook_secret'] : '';

        if (empty($webhook_secret)) {
            return new WP_Error('missing_webhook_secret', 'ModemPay webhook secret is not configured.', array('status' => 500));
        }

        // FIX: Verify signature.
        // NOTE: Please confirm with ModemPay docs if the payload needs timestamp appending or base64 encoding.
        $expected_signature = hash_hmac('sha256', $json, $webhook_secret);
        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error('invalid_signature', 'Invalid webhook signature.', array('status' => 401));
        }

        // Validate webhook payload structure
        if (!isset($webhook_data['event']) || !isset($webhook_data['payload'])) {
            return new WP_Error('invalid_webhook', 'Invalid webhook structure', array('status' => 400));
        }

        $event = $webhook_data['event'];
        $payload = $webhook_data['payload'];

        // Process only successful charges
        if ($event !== 'charge.succeeded') {
            return rest_ensure_response(array('success' => true, 'message' => 'Event ignored: ' . $event));
        }

        // Extract Order ID from metadata safely
        $metadata = array();
        if (isset($payload['metadata'])) {
            if (is_array($payload['metadata'])) {
                $metadata = $payload['metadata'];
            } elseif (is_string($payload['metadata'])) {
                $decoded_metadata = json_decode($payload['metadata'], true);
                if (is_array($decoded_metadata)) {
                    $metadata = $decoded_metadata;
                }
            }
        }

        $order_id = isset($metadata['order_id']) ? sanitize_text_field($metadata['order_id']) : null;

        if (empty($order_id)) {
            return new WP_Error('missing_order_id', 'Order ID not found in webhook metadata', array('status' => 400));
        }

        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found: ' . $order_id, array('status' => 404));
        }

        // Get transaction ID (try multiple fields)
        $transaction_id = $this->get_transaction_id($payload);
        if (empty($transaction_id)) {
            return new WP_Error('missing_transaction_id', 'Transaction ID not found in webhook payload', array('status' => 400));
        }

        // SECURITY CHECK 2: Double processing prevention (Corrected WooCommerce way)
        if (!$order->needs_payment()) {
            $order->add_order_note('Webhook received but order is already processed. Transaction ID: ' . $transaction_id);
            return rest_ensure_response(array('success' => true, 'message' => 'Order already processed'));
        }

        // SECURITY CHECK 3: Replay Attack Prevention (HPOS Compatible)
        $existing_orders = wc_get_orders(array(
            'transaction_id' => $transaction_id,
            'status'         => array('processing', 'completed', 'on-hold'),
            'exclude'        => array($order_id),
            'limit'          => 1,
            'return'         => 'ids'
        ));

        if (!empty($existing_orders)) {
            $order->add_order_note(sprintf(
                'SECURITY ALERT: Duplicate transaction ID %s already used for Order #%s',
                $transaction_id,
                $existing_orders[0]->get_id()
            ));
            return new WP_Error('duplicate_transaction', 'Transaction already processed for another order', array('status' => 403));
        }

        // SECURITY CHECK 4: Amount matching
        $expected_amount = floatval($order->get_total());
        $transaction_amount = isset($payload['amount']) ? floatval($payload['amount']) : 0;

        // FIX: Ensure you check if ModemPay sends major units (dollars) or minor units (cents) here.
        if (abs($expected_amount - $transaction_amount) > 0.01) {
            $order->add_order_note(sprintf(
                'Security Alert: Amount mismatch detected. Expected: %s %s, Received: %s %s. Order status left unchanged to protect against tampering.',
                $expected_amount,
                $order->get_currency(),
                $transaction_amount,
                isset($payload['currency']) ? $payload['currency'] : 'N/A'
            ));
            return new WP_Error('amount_mismatch', 'Transaction amount does not match order total', array('status' => 400));
        }

        // SECURITY CHECK 5: Transaction status verification
        $status = isset($payload['status']) ? strtolower($payload['status']) : '';
        $valid_statuses = array('success', 'successful', 'completed', 'paid');

        // FIX: Change to add_order_note to prevent order sabotage.
        if (!in_array($status, $valid_statuses)) {
            $order->add_order_note('Security Alert: Webhook received with invalid status: ' . esc_html($status));
            return new WP_Error('invalid_status', 'Transaction status is not successful: ' . $status, array('status' => 400));
        }

        // Store transaction metadata
        $order->update_meta_data('_modempay_transaction_id', $transaction_id);
        $order->update_meta_data('_modempay_payment_reference', isset($payload['reference']) ? sanitize_text_field($payload['reference']) : '');
        $order->update_meta_data('_modempay_payment_method', isset($payload['payment_method']) ? sanitize_text_field($payload['payment_method']) : '');
        $order->update_meta_data('_modempay_webhook_received', current_time('mysql'));

        // FIX: Keep the raw payload for dispute forensics as suggested by security review.
        $order->update_meta_data('_modempay_raw_webhook_payload', $json);

        $order->save();

        // Complete the payment
        $order->payment_complete($transaction_id);

        // Build detailed order note
        $note_parts = array(
            'ModemPay Verified: Payment successful via Webhook',
            'Transaction ID: ' . esc_html($transaction_id),
            'Reference: ' . esc_html(isset($payload['reference']) ? $payload['reference'] : 'N/A'),
            'Payment Method: ' . esc_html(isset($payload['payment_method']) ? $payload['payment_method'] : 'N/A'),
            'Amount: ' . esc_html($transaction_amount) . ' ' . esc_html(isset($payload['currency']) ? $payload['currency'] : ''),
            'Customer: ' . esc_html(isset($payload['customer_name']) ? $payload['customer_name'] : 'N/A'),
            'Source: ' . esc_html(isset($metadata['source']) ? $metadata['source'] : 'mobile_app')
        );

        if (isset($payload['test_mode']) && $payload['test_mode']) {
            $note_parts[] = '<strong>TEST MODE</strong>';
        }

        $order->add_order_note(implode('<br/>', $note_parts));

        // Return success response
        return rest_ensure_response(array(
            'success'        => true,
            'message'        => 'Payment processed successfully',
            'order_id'       => $order_id,
            'transaction_id' => $transaction_id
        ));
    }

    /**
     * Helper: Extract transaction ID from various possible payload fields
     */
    private function get_transaction_id($payload)
    {
        if (isset($payload['id'])) {
            return sanitize_text_field($payload['id']);
        } elseif (isset($payload['transaction_id'])) {
            return sanitize_text_field($payload['transaction_id']);
        } elseif (isset($payload['transaction_reference'])) {
            return sanitize_text_field($payload['transaction_reference']);
        } elseif (isset($payload['reference'])) {
            return sanitize_text_field($payload['reference']);
        } elseif (isset($payload['payment_intent_id'])) {
            return sanitize_text_field($payload['payment_intent_id']);
        }
        return '';
    }
}

new FlutterModempay;
