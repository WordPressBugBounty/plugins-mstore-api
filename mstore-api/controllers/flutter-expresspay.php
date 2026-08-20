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
 * @package PayStack
 */

class FlutterExpressPay extends FlutterBaseController
{
    private function expresspay_post_json( $url, $payload ) {
        return wp_remote_post(
            $url,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode( $payload ),
            )
        );
    }

    private function expresspay_post_form( $url, $payload ) {
        return wp_remote_post(
            $url,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body'    => $payload,
            )
        );
    }

    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_expresspay';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_expresspay_routes'));
    }

    public function register_flutter_expresspay_routes()
    {
        register_rest_route($this->namespace, '/card_checkout', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'card_checkout'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/verify_payment', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'verify_payment'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    public function verify_payment($request)
    {

        if (!is_plugin_active('woo-web-payment-getaway/web-payment-gateway.php')) {
            return parent::send_invalid_plugin_error("You need to install ShahbandrPay plugin to use this api");
        }

        $json = file_get_contents('php://input');
        $body = json_decode($json, TRUE);
        $order_id = sanitize_text_field($body['order_id']);
        $transaction_id = sanitize_text_field($body['transaction_id']);

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

        // SECURITY CHECK 1: Prevent double processing
        if (!$order->needs_payment()) {
            return rest_ensure_response(['success' => true, 'message' => 'Order already processed.']);
        }

        // SECURITY CHECK 2: Replay Attack Prevention (HPOS Compatible)
        $existing_orders = wc_get_orders(array(
            'transaction_id' => $transaction_id,
            'status'         => array('processing', 'completed', 'on-hold'),
            'exclude'        => array($order_id),
            'limit'          => 1,
            'return'         => 'ids'
        ));

        if (!empty($existing_orders)) {
            $order->add_order_note(sprintf('Security Alert: ExpressPay transaction ID %s already used for another order. Replay attack blocked.', $transaction_id));
            return new WP_Error('replay_attack', 'This payment transaction was already used for another order.', array('status' => 403));
        }

        $options  = get_option( 'woocommerce_shahbandrpay_settings');
        $password = isset($options['password']) ? $options['password'] : '';
        $secret   = isset($options['secret']) ? $options['secret'] : '';
        $new_order_status = !empty($options['new_order_status']) ? $options['new_order_status'] : 'processing';

        if (empty($password) || empty($secret)) {
            return new WP_Error('expresspay_misconfigured', 'ExpressPay (ShahbandrPay) is not configured.', array('status' => 500));
        }

        $hash = sha1(md5(strtoupper($transaction_id . $password)));
        $url = 'https://pay.expresspay.sa/api/v1/payment/status';

        $main_json = [
            "merchant_key" => $secret,
            "payment_id" => $transaction_id,
            "hash" => $hash
        ];

        $result = $this->expresspay_post_json( $url, $main_json );
        $http_code = is_wp_error( $result ) ? 0 : wp_remote_retrieve_response_code( $result );
        $response_body = is_wp_error( $result ) ? '' : wp_remote_retrieve_body( $result );

        if ( is_wp_error( $result ) || $http_code !== 200 ) {
            $order->add_order_note('Security Alert: ExpressPay S2S verification request failed or returned HTTP ' . $http_code);
            return new WP_Error('s2s_verification_failed', 'Could not verify payment with ExpressPay.', array('status' => 502));
        }

        $response = json_decode($response_body, true);

        // SECURITY CHECK 3: Validate Payment Status
        if (isset($response['status']) && $response['status'] == 'settled') {

            // SECURITY CHECK 4: Bind the transaction to THIS order's amount and currency.
            //
            // 'settled' on its own only proves the transaction is real, not that it
            // paid for this order. Without this an attacker can settle a cheap order,
            // withhold the payment_success call so it stays pending (and therefore
            // out of the replay check above), then present that transaction id
            // against an expensive order.
            //
            // ExpressPay echoes the fields card_checkout() submits (order_amount /
            // order_currency); the alternatives are accepted so a response shape
            // change does not silently disable the check.
            $paid_amount   = $this->first_present($response, array('order_amount', 'amount', 'total'));
            $paid_currency = $this->first_present($response, array('order_currency', 'currency'));

            if ($paid_amount === null) {
                // Fail closed: an unverifiable amount is exactly the case being exploited.
                $order->add_order_note('Security Alert: ExpressPay status response carried no amount field, so the transaction could not be bound to this order. Payment not applied. Response keys: ' . esc_html(implode(', ', array_keys((array) $response))));
                return new WP_Error('amount_unverifiable', 'Could not verify the paid amount with ExpressPay.', array('status' => 502));
            }

            $order_total = (float) $order->get_total();

            if (abs($order_total - (float) $paid_amount) > 0.01) {
                $order->add_order_note(sprintf(
                    'Security Alert: ExpressPay amount mismatch. Order total: %s, Paid: %s. Order status unchanged.',
                    esc_html($order_total),
                    esc_html($paid_amount)
                ));
                return new WP_Error('amount_mismatch', 'Paid amount does not match order total.', array('status' => 400));
            }

            if ($paid_currency !== null
                && strtoupper((string) $paid_currency) !== strtoupper($order->get_currency())) {
                $order->add_order_note(sprintf(
                    'Security Alert: ExpressPay currency mismatch. Order currency: %s, Paid: %s. Order status unchanged.',
                    esc_html($order->get_currency()),
                    esc_html($paid_currency)
                ));
                return new WP_Error('currency_mismatch', 'Paid currency does not match order currency.', array('status' => 400));
            }

            // All checks passed.
            $order->payment_complete($transaction_id);
            $order->add_order_note('ExpressPay Verified: S2S Payment successful.<br/>Transaction ID: ' . esc_html($transaction_id));
            $order->update_meta_data('_expresspay_transaction_id', $transaction_id);
            $order->save();

            if ($order->get_status() !== $new_order_status) {
                $order->update_status($new_order_status);
            }

            return ['success' => true];
        } else {
            $order->add_order_note('Security Alert: ExpressPay payment not settled. Status: ' . esc_html($response['status'] ?? 'unknown'));
            return new WP_Error('payment_not_settled', $response['reason'] ?? 'ExpressPay payment failed.', array('status' => 400));
        }
    }

    /**
     * Return the first key present and non-empty-string in $data, or null.
     *
     * @param array $data
     * @param array $keys Candidate keys, in priority order.
     * @return mixed|null
     */
    private function first_present($data, $keys)
    {
        if (!is_array($data)) {
            return null;
        }

        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    public function card_checkout($request)
    {
        if (!is_plugin_active('woo-web-payment-getaway/web-payment-gateway.php')) {
            return parent::send_invalid_plugin_error("You need to install ShahbandrPay plugin to use this api");
        }

        $json = file_get_contents('php://input');
        $body = json_decode($json, TRUE);
        $order_id = sanitize_text_field($body['order_id']);
        $card_number = sanitize_text_field($body['card_number']);
        $card_exp = sanitize_text_field($body['card_exp']);
        $card_cvc = sanitize_text_field($body['card_cvc']);
        $return_url = sanitize_text_field($body['return_url']);


        $options  = get_option( 'woocommerce_shahbandrpay_settings');
        $password = $options['password'];
        $secret   = $options['secret'];

        global $woocommerce;

        $order = new WC_Order($order_id);
        $user = $order->get_user();
        $user_id = $order->get_user_id();
        $currency     = method_exists( $order, 'get_currency' ) ? $order->get_currency() : $order->order_currency;

        $action_adr = 'https://api.expresspay.sa/post';
        $customerName = '';
        if(mb_detect_encoding($order->get_billing_first_name()) !== 'UTF-8' && mb_detect_encoding($order->get_billing_last_name()) !== 'UTF-8') {
            $customerName = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        }
        $email =  $order->get_billing_email() ? $order->get_billing_email() : $user->email;

        if ($customerName == '') {
            $customer = array(
                'email' => $email
                );
        } else {
            $customer = array(
                'name' => $customerName,
                'email' => $email
            );
        }

        $billing_address = array(
            'country' => $order->get_billing_country() ? $order->get_billing_country() : 'NA',
            'state' => $order->get_billing_state() ? $order->get_billing_state() : 'NA',
            'city' => $order->get_billing_city() ? $order->get_billing_city() : 'NA',
            'address' => $order->get_billing_address_1() ? $order->get_billing_address_1() : 'NA',
            'zip' => $order->get_billing_postcode() ? $order->get_billing_postcode() : '12271',
            'phone' => $order->get_billing_phone() ? $order->get_billing_phone() : '',
            'email' => $email
        );

        $amount = number_format($order->get_total(), 2, '.', '');

        $order_json = array(
            'number' => "$order_id",
            'description' => __('Payment Order # ', 'mstore-api') . $order_id . __(' in the store ', 'mstore-api') . home_url('/'),
            'amount' => $amount,
            'currency' => $currency,
        );

        $card_number = str_replace(" ","",$card_number);
        if ($card_exp) {
            $exp_array = explode('/', $card_exp);
            $month = str_replace(" ", "",$exp_array[0]);
            $year = str_replace(" ", "",'20'.$exp_array[1]);
        } else {
            $month = '';
            $year = '';
        }

        $hash = md5(strtoupper(strrev($email).$password.strrev(substr($card_number,0,6).substr($card_number,-4))));

        $data = [
            'action'            => 'SALE',
            'client_key'        => $secret,
            'order_id'          => 'ORDER-' . $order_id . time(),
            'order_amount'      => $amount,
            'order_currency'    => $currency,
            'order_description' => __('Product Order # ', 'mstore-api') . $order_id,
            'card_number'       => $card_number,
            'card_exp_month'    => $month,
            'card_exp_year'     => $year,
            'card_cvv2'         => $card_cvc,
            'payer_first_name'  => $order->get_billing_first_name(),
            'payer_last_name'   => $order->get_billing_last_name(),
            'payer_address'     => $billing_address['address'],
            'payer_country'     => $billing_address['country'],
            'payer_city'        => $billing_address['city'],
            'payer_zip'         => $billing_address['zip'],
            'payer_email'       => $billing_address['email'],
            'payer_phone'       => $billing_address['phone'],
            'payer_ip'          => '123.123.123.123',
            'term_url_3ds'      => $return_url,
            'hash'              => $hash,
        ];


        $result = $this->expresspay_post_form( $action_adr, $data );
        $httpcode = is_wp_error( $result ) ? 0 : wp_remote_retrieve_response_code( $result );
        $response_body = is_wp_error( $result ) ? '' : wp_remote_retrieve_body( $result );
        $response = json_decode($response_body, true);

        if (is_wp_error($result) || $httpcode != 200) {
            $errors = '';
            if (isset($response['errors']) && is_array($response['errors'])) {
                foreach($response['errors'] as $value){
                    $errors .= $value['error_code'] . ' : ' .$value['error_message'].'<br>';
                }
            }
            if ($errors === '') {
                $errors = 'Please try again.';
            }
            return parent::sendError("invalid_payment", $errors, 400);
        }

        if ($response['result'] == 'SUCCESS' && $response['status'] == 'SETTLED') {

            $order->payment_complete($order_id);
            $order->update_status($new_order_status, 'ShahbandrPay successfully paid');
            $order->add_order_note( 'ShahbandrPay successfully paid' );

            update_post_meta( $order_id, 'trans_id', $response['trans_id'] );
            update_post_meta( $order_id, 'trans_date', $response['trans_date'] );
            update_post_meta( $order_id, 'trans_hash', $hash );

            return array(
                'success'   => true,
            );
        }elseif($response['result'] == 'REDIRECT' && $response['status'] == 'REDIRECT' ){
            $order->update_status('on-hold', 'Awaiting 3-D Secure Payment');
            update_post_meta( $order_id, 'trans_id', $response['trans_id'] );
            update_post_meta( $order_id, 'trans_date', $response['trans_date'] );
            update_post_meta( $order_id, 'trans_hash', $hash );

            $body   = $response['redirect_params']['body'];
            $url    = $response['redirect_url'];
            $method = $response['redirect_method'];

            return array(
                'body' => $response['redirect_params']['body'],
                'url' => $response['redirect_url'],
                'method' => $response['redirect_method'],
                'trans_id' => $response['trans_id']
            );
        }
        else {
            return parent::sendError("invalid_payment", 'Please try again.', 400);
        }
    }
}

new FlutterExpressPay;
