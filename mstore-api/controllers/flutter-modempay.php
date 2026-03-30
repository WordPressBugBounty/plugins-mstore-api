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
     */
    public function payment_success($request)
    {
        // Parse webhook payload
        $json = file_get_contents('php://input');
        $webhook_data = json_decode($json, true);

        // Validate webhook structure
        if (!isset($webhook_data['event']) || !isset($webhook_data['payload'])) {
            return new WP_Error('invalid_webhook', 'Invalid webhook structure', array('status' => 400));
        }

        $event = $webhook_data['event'];
        $payload = $webhook_data['payload'];

        // Only process successful charge events
        if ($event !== 'charge.succeeded') {
            return new WP_Error('unsupported_event', 'Unsupported event type: ' . $event, array('status' => 400));
        }

        // Extract order ID from metadata
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

        // SECURITY CHECK 1: Check if already paid to prevent duplicate processing
        if ($order->is_paid()) {
            $order->add_order_note('Webhook received but order already paid. Transaction ID: ' . $transaction_id);
            return array(
                'success' => true,
                'message' => 'Order already paid'
            );
        }

        // SECURITY CHECK 2: Check for duplicate transaction ID across all orders
        $existing_orders = wc_get_orders(array(
            'meta_key' => '_modempay_transaction_id',
            'meta_value' => $transaction_id,
            'status' => array('processing', 'completed', 'on-hold'),
            'exclude' => array($order_id)
        ));

        if (!empty($existing_orders)) {
            $order->add_order_note(sprintf(
                'SECURITY: Duplicate transaction ID %s already used for Order #%s',
                $transaction_id,
                $existing_orders[0]->get_id()
            ));
            return new WP_Error('duplicate_transaction', 'Transaction already processed for another order', array('status' => 400));
        }

        // SECURITY CHECK 3: Verify transaction amount matches order total
        $expected_amount = floatval($order->get_total());
        $transaction_amount = isset($payload['amount']) ? floatval($payload['amount']) : 0;

        if (abs($expected_amount - $transaction_amount) > 0.01) {
            $order->add_order_note(sprintf(
                'SECURITY: Amount mismatch. Expected: %s %s, Received: %s %s',
                $expected_amount,
                $order->get_currency(),
                $transaction_amount,
                isset($payload['currency']) ? $payload['currency'] : 'N/A'
            ));
            return new WP_Error('amount_mismatch', 'Transaction amount does not match order total', array('status' => 400));
        }

        // SECURITY CHECK 4: Verify customer email matches (if available)
        $order_email = strtolower(trim($order->get_billing_email()));
        $transaction_email = isset($payload['customer_email']) ? strtolower(trim($payload['customer_email'])) : '';

        if (!empty($transaction_email) && $order_email !== $transaction_email) {
            $order->add_order_note(sprintf(
                'SECURITY: Customer email mismatch. Order: %s, Transaction: %s',
                $order_email,
                $transaction_email
            ));
            return new WP_Error('email_mismatch', 'Customer email does not match', array('status' => 400));
        }

        // SECURITY CHECK 5: Check transaction age (reject if older than 24 hours)
        if (isset($payload['createdAt'])) {
            $transaction_time = strtotime($payload['createdAt']);
            $current_time = current_time('timestamp');
            $time_diff = $current_time - $transaction_time;

            if ($time_diff > (24 * 60 * 60)) {
                $order->add_order_note(sprintf(
                    'SECURITY: Transaction too old. Created: %s (%.2f hours ago)',
                    $payload['createdAt'],
                    $time_diff / 3600
                ));
                return new WP_Error('transaction_expired', 'Transaction has expired', array('status' => 400));
            }
        }

        // SECURITY CHECK 6: Verify transaction status is successful
        $status = isset($payload['status']) ? strtolower($payload['status']) : '';
        $valid_statuses = array('success', 'successful', 'completed', 'paid');

        if (!in_array($status, $valid_statuses)) {
            $order->add_order_note('Webhook received with invalid status: ' . esc_html($status));
            return new WP_Error('invalid_status', 'Transaction status is not successful: ' . $status, array('status' => 400));
        }

        // Store transaction metadata
        $order->update_meta_data('_modempay_transaction_id', $transaction_id);
        $order->update_meta_data('_modempay_payment_reference', isset($payload['reference']) ? sanitize_text_field($payload['reference']) : '');
        $order->update_meta_data('_modempay_payment_method', isset($payload['payment_method']) ? sanitize_text_field($payload['payment_method']) : '');
        $order->update_meta_data('_modempay_transaction', $json);
        $order->update_meta_data('_modempay_webhook_received', current_time('mysql'));
        $order->save();

        // Complete the payment
        $order->payment_complete($transaction_id);

        // Build detailed order note
        $note_parts = array(
            'Modem Pay payment successful via webhook',
            'Transaction ID: ' . esc_html($transaction_id),
            'Reference: ' . esc_html(isset($payload['reference']) ? $payload['reference'] : 'N/A'),
            'Payment Method: ' . esc_html(isset($payload['payment_method']) ? $payload['payment_method'] : 'N/A'),
            'Amount: ' . esc_html($transaction_amount) . ' ' . esc_html(isset($payload['currency']) ? $payload['currency'] : ''),
            'Customer: ' . esc_html(isset($payload['customer_name']) ? $payload['customer_name'] : 'N/A'),
            'Phone: ' . esc_html(isset($payload['customer_phone']) ? $payload['customer_phone'] : 'N/A'),
            'Email: ' . esc_html($transaction_email),
            'Source: ' . esc_html(isset($metadata['source']) ? $metadata['source'] : 'mobile_app')
        );

        if (isset($payload['test_mode']) && $payload['test_mode']) {
            $note_parts[] = '<strong>TEST MODE</strong>';
        }

        $order->add_order_note(implode('<br/>', $note_parts));

        // Return success response
        return array(
            'success' => true,
            'message' => 'Payment processed successfully',
            'order_id' => $order_id,
            'transaction_id' => $transaction_id
        );
    }

    /**
     * Get transaction ID from payload (handles various field names)
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
