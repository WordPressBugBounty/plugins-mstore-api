<?php
require_once(__DIR__ . '/flutter-base.php');
require_once(__DIR__ . '/helpers/dokan-helper.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Stripe
 */

class FlutterStripe extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_stripe';

    /**
     * Tracks whether Dokan Stripe has been bootstrapped for this request.
     *
     * @var bool
     */
    protected $dokan_stripe_bootstrapped = false;

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_stripe_routes'));
    }

    public function register_flutter_stripe_routes()
    {
        register_rest_route($this->namespace, '/payment_intent', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'create_payment_intent'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route(
            $this->namespace,
            '/payment_intent' . '/(?P<id>[\w]+)',
            array(
                array(
                    'methods' => 'GET',
                    'callback' => array($this, 'get_payment_intent'),
                    'permission_callback' => function () {
                        return parent::checkApiPermission();
                    }
                ),
            )
        );
    }

    /**
     * Determine whether a Stripe integration is available.
     *
     * @return bool
     */
    protected function is_stripe_integration_available() {
        return $this->has_woocommerce_stripe_gateway() || $this->has_dokan_stripe_module();
    }

    /**
     * Check if WooCommerce Stripe Gateway classes are available.
     *
     * @return bool
     */
    protected function has_woocommerce_stripe_gateway() {
        return class_exists( 'WC_Stripe_API' ) && class_exists( 'WC_Stripe_Helper' );
    }

    /**
     * Check if a Dokan Stripe module is available.
     *
     * @return bool
     */
    protected function has_dokan_stripe_module() {
        return class_exists( '\\WeDevs\\DokanPro\\Modules\\Stripe\\Helper' )
            || class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Helper' )
            || class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Config' );
    }

    /**
     * Normalize Stripe amounts across available integrations.
     *
     * @param float|int $amount
     * @param string    $currency
     *
     * @return int
     */
    protected function get_stripe_amount_value( $amount, $currency ) {
        $currency = strtolower( $currency );

        if ( $this->has_woocommerce_stripe_gateway() ) {
            return WC_Stripe_Helper::get_stripe_amount( $amount, $currency );
        }

        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Helper' ) ) {
            return \WeDevs\DokanPro\Modules\StripeExpress\Support\Helper::get_stripe_amount( $amount, $currency );
        }

        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\Stripe\\Helper' ) ) {
            return \WeDevs\DokanPro\Modules\Stripe\Helper::get_stripe_amount( $amount );
        }

        return 0;
    }

    /**
     * Ensure Dokan Stripe SDK is ready before hitting the Stripe API directly.
     *
     * @return void
     */
    protected function bootstrap_dokan_stripe() {
        if ( $this->dokan_stripe_bootstrapped ) {
            return;
        }

        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\Stripe\\Helper' ) ) {
            \WeDevs\DokanPro\Modules\Stripe\Helper::bootstrap_stripe();
            $this->dokan_stripe_bootstrapped = true;
            return;
        }

        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Config' ) ) {
            $config = \WeDevs\DokanPro\Modules\StripeExpress\Support\Config::instance();

            if ( method_exists( $config, 'get_secret_key' ) ) {
                $secret_key = $config->get_secret_key();

                if ( ! empty( $secret_key ) && class_exists( '\\Stripe\\Stripe' ) ) {
                    \Stripe\Stripe::setApiKey( $secret_key );
                    $this->dokan_stripe_bootstrapped = true;
                }
            }
        }
    }

    /**
     * Proxy payment intent creation for the available Stripe integration.
     *
     * @param array $params
     *
     * @return object|\WP_Error
     */
    protected function create_payment_intent_request( array $params ) {
        if ( $this->has_dokan_stripe_module() ) {
            $this->bootstrap_dokan_stripe();

            try {
                return \Stripe\PaymentIntent::create( $params );
            } catch ( \Exception $exception ) {
                return new WP_Error( 400, $exception->getMessage(), array( 'status' => 400 ) );
            }
        }

        if ( $this->has_woocommerce_stripe_gateway() ) {
            return WC_Stripe_API::request( $params, 'payment_intents' );
        }

        return new WP_Error( 400, __( 'Stripe integration is not available.', 'mstore-api' ), array( 'status' => 400 ) );
    }

    /**
     * Proxy payment intent retrieval for the available Stripe integration.
     *
     * @param string $payment_intent_id
     *
     * @return object|\WP_Error
     */
    protected function retrieve_payment_intent( $payment_intent_id ) {
        if ( $this->has_dokan_stripe_module() ) {
            $this->bootstrap_dokan_stripe();

            try {
                return \Stripe\PaymentIntent::retrieve( $payment_intent_id );
            } catch ( \Exception $exception ) {
                return new WP_Error( 400, $exception->getMessage(), array( 'status' => 400 ) );
            }
        }

        if ( $this->has_woocommerce_stripe_gateway() ) {
            return WC_Stripe_API::request( array(), "payment_intents/$payment_intent_id", 'GET' );
        }

        return new WP_Error( 400, __( 'Stripe integration is not available.', 'mstore-api' ), array( 'status' => 400 ) );
    }

    public function create_payment_intent($request)
    {
        if ( ! $this->is_stripe_integration_available() ) {
            return parent::send_invalid_plugin_error(
                "You need to install WooCommerce Stripe Gateway or enable a Dokan Stripe module to use this api"
            );
        }
        $json = file_get_contents('php://input');
        $body = json_decode($json, TRUE);
        $order_id = sanitize_text_field($body['orderId']);
        $capture_method = sanitize_text_field($body['captureMethod']);
        $return_url = sanitize_text_field($body['returnUrl']);
        $email = sanitize_text_field($body['email']);
        $payment_method_types = sanitize_text_field($body['payment_method_types']);
        $save_card_after_checkout = $body['saveCardAfterCheckout'] == true;

        $order    = wc_get_order( $order_id );
        $amount   = 0;
        if ( is_a( $order, 'WC_Order' ) ) {
            $amount = $order->get_total();
        }
        $currency = get_woocommerce_currency();

        $params = [
            'amount'               => $this->get_stripe_amount_value( $amount, $currency ),
            'currency'             => strtolower( $currency ),
            'payment_method_types' => isset($payment_method_types) && !empty($payment_method_types) ? $payment_method_types : ['card'],
            'capture_method'       => $capture_method == 'automatic' ? 'automatic' : 'manual',
            'metadata'             => ['order_id'=>$order_id],
            'description'          => $email,
            'receipt_email'        => $email
        ];

        if ( isset( $body['isSplitPayment'] ) && $body['isSplitPayment'] == true && $this->has_dokan_stripe_module() ) {
            $vendor_id         = dokan_get_seller_id_by_order( $order_id );
            
            if ( class_exists( '\WeDevs\DokanPro\Modules\StripeExpress\Support\UserMeta' ) ) {
                $connected_account = \WeDevs\DokanPro\Modules\StripeExpress\Support\UserMeta::get_stripe_account_id( $vendor_id );
            } else {
                $connected_account = get_user_meta( $vendor_id, 'dokan_connected_vendor_id', true );
            }

            if ( $connected_account ) {
                $params['transfer_data']         = [
                    'destination' => $connected_account,
                ];

                $calculated_split = $this->get_calculated_split_payment( $order );

                if ( $calculated_split ) {
                    $vendor_earning        = $calculated_split['vendor_earning'];
                    $total_commission     = $calculated_split['total_commission'];
                } else {
                    $vendor_earning        = wc_format_decimal( 0, wc_get_price_decimals() );
                    $total_commission     = wc_format_decimal( 0, wc_get_price_decimals() );
                }

                $split_payload = [
                    'vendor_earning'   => $vendor_earning,
                    'total_commission' => $total_commission,
                ];

                $params['metadata']['split_payment'] = wp_json_encode( $split_payload );

                if ( $total_commission > 0 ) {
                    $params['application_fee_amount'] = $this->get_stripe_amount_value( $total_commission, $currency );
                }
            }
        }

        if(isset($body['request3dSecure'])){
            $request_3d_secure = sanitize_text_field($body['request3dSecure']);
            $params['payment_method_options'] = ['card' => ['request_three_d_secure' => $request_3d_secure ?? 'automatic']];
            $params['confirm'] = 'false';
        }
        if(isset($body['payment_method_id'])){
            $payment_method_id = sanitize_text_field($body['payment_method_id']);
            $params['payment_method'] = $payment_method_id;
            $params['confirm'] = 'true';
        }

        if ( $save_card_after_checkout ) {
            $stripe_customer = $this->get_or_create_stripe_customer_for_order( $order );

            if ( is_wp_error( $stripe_customer ) ) {
                return $stripe_customer;
            }

            if ( ! empty( $stripe_customer ) ) {
                $params['customer']           = $stripe_customer;
                $params['setup_future_usage'] = 'off_session';
            }
        }

        $payment_intent = $this->create_payment_intent_request( $params );

        if ( is_wp_error( $payment_intent ) ) {
            return $payment_intent;
        }

        if ( isset( $payment_intent->error ) && ! empty( $payment_intent->error ) ) {
            return new WP_Error( 400, $payment_intent->error->message, array( 'status' => 400 ) );
        }

        $response = [
            'id'            => $payment_intent->id,
            'client_secret' => $payment_intent->client_secret,
            'customer_id'   => isset( $stripe_customer ) ? $stripe_customer : null,
            'connected_account' => isset($connected_account) ? $connected_account : null
        ];

        if ( $save_card_after_checkout && ! empty( $stripe_customer ) ) {
            $ephemeral_key = $this->create_ephemeral_key_for_customer( $stripe_customer );
            if ( is_wp_error( $ephemeral_key ) ) {
                return $ephemeral_key;
            }

            $setup_intent_secret = $this->create_setup_intent_for_customer( $stripe_customer );
            if ( is_wp_error( $setup_intent_secret ) ) {
                return $setup_intent_secret;
            }

            if ( ! empty( $ephemeral_key ) ) {
                $response['ephemeral_key'] = $ephemeral_key;
            }
            if ( ! empty( $setup_intent_secret ) ) {
                $response['setup_intent'] = $setup_intent_secret;
            }
        }

        return $response;
    }

    protected function get_calculated_split_payment( $order ) {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return null;
        }

        return mstore_api_get_dokan_split_payment_breakdown( $order );
    }

    /**
     * Create ephemeral key for a Stripe customer.
     *
     * @param string $customer_id
     * @return string|\WP_Error|null
     */
    protected function create_ephemeral_key_for_customer( $customer_id ) {
        if ( empty( $customer_id ) ) {
            return null;
        }

        $stripe_version = '2022-11-15';

        if ( $this->has_dokan_stripe_module() && class_exists( '\\Stripe\\EphemeralKey' ) ) {
            $this->bootstrap_dokan_stripe();
            try {
                $key = \Stripe\EphemeralKey::create(
                    array( 'customer' => $customer_id ),
                    array( 'stripe_version' => $stripe_version )
                );
                return isset( $key->secret ) ? $key->secret : null;
            } catch ( \Exception $exception ) {
                return new WP_Error( 400, $exception->getMessage(), array( 'status' => 400 ) );
            }
        }

        if ( $this->has_woocommerce_stripe_gateway() && method_exists( 'WC_Stripe_API', 'request' ) ) {
            $payload = array( 'customer' => $customer_id );
            $key     = WC_Stripe_API::request( $payload, 'ephemeral_keys', 'POST' );

            if ( is_wp_error( $key ) ) {
                return $key;
            }
            if ( isset( $key->error ) && ! empty( $key->error->message ) ) {
                return new WP_Error( 400, $key->error->message, array( 'status' => 400 ) );
            }
            return isset( $key->secret ) ? $key->secret : null;
        }

        return null;
    }

    /**
     * Create setup intent for a Stripe customer (for saving card).
     *
     * @param string $customer_id
     * @return string|\WP_Error|null
     */
    protected function create_setup_intent_for_customer( $customer_id ) {
        if ( empty( $customer_id ) ) {
            return null;
        }

        if ( $this->has_dokan_stripe_module() && class_exists( '\\Stripe\\SetupIntent' ) ) {
            $this->bootstrap_dokan_stripe();
            try {
                $intent = \Stripe\SetupIntent::create( array( 'customer' => $customer_id ) );
                return isset( $intent->client_secret ) ? $intent->client_secret : null;
            } catch ( \Exception $exception ) {
                return new WP_Error( 400, $exception->getMessage(), array( 'status' => 400 ) );
            }
        }

        if ( $this->has_woocommerce_stripe_gateway() && method_exists( 'WC_Stripe_API', 'request' ) ) {
            $payload = array( 'customer' => $customer_id );
            $intent  = WC_Stripe_API::request( $payload, 'setup_intents', 'POST' );

            if ( is_wp_error( $intent ) ) {
                return $intent;
            }
            if ( isset( $intent->error ) && ! empty( $intent->error->message ) ) {
                return new WP_Error( 400, $intent->error->message, array( 'status' => 400 ) );
            }
            return isset( $intent->client_secret ) ? $intent->client_secret : null;
        }

        return null;
    }

    /**
     * Lookup or create a Stripe customer for the order's WooCommerce customer id.
     *
     * @param WC_Order $order
     *
     * @return string|null|\WP_Error
     */
    protected function get_or_create_stripe_customer_for_order( $order ) {
        if ( ! is_a( $order, 'WC_Order' ) || ! $order->get_customer_id() ) {
            return null;
        }

        $meta_keys = array( '_stripe_customer_id', 'wc_stripe_customer_id', 'woocommerce_stripe_customer_id' );

        // Try order meta first, then user meta.
        foreach ( $meta_keys as $meta_key ) {
            $existing = $order->get_meta( $meta_key );
            if ( ! empty( $existing ) ) {
                return $existing;
            }
        }

        $user_id = $order->get_customer_id();
        foreach ( $meta_keys as $meta_key ) {
            $existing = get_user_meta( $user_id, $meta_key, true );
            if ( ! empty( $existing ) ) {
                return $existing;
            }
        }

        $customer_payload = array(
            'email'       => $order->get_billing_email(),
            'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'description' => sprintf( 'WooCommerce customer %d', $user_id ),
            'metadata'    => array(
                'wp_user_id' => $user_id,
                'wp_order_id' => $order->get_id(),
            ),
        );

        if ( $this->has_dokan_stripe_module() ) {
            $this->bootstrap_dokan_stripe();
            try {
                $customer = \Stripe\Customer::create( $customer_payload );
            } catch ( \Exception $exception ) {
                return new WP_Error( 400, $exception->getMessage(), array( 'status' => 400 ) );
            }
        } elseif ( $this->has_woocommerce_stripe_gateway() ) {
            $customer = WC_Stripe_API::request( $customer_payload, 'customers' );
            if ( is_wp_error( $customer ) ) {
                return $customer;
            }
            if ( isset( $customer->error ) && ! empty( $customer->error->message ) ) {
                return new WP_Error( 400, $customer->error->message, array( 'status' => 400 ) );
            }
        } else {
            return new WP_Error( 400, __( 'Stripe integration is not available.', 'mstore-api' ), array( 'status' => 400 ) );
        }

        if ( empty( $customer->id ) ) {
            return new WP_Error( 400, __( 'Unable to create Stripe customer.', 'mstore-api' ), array( 'status' => 400 ) );
        }

        // Persist for future lookups.
        foreach ( $meta_keys as $meta_key ) {
            update_user_meta( $user_id, $meta_key, $customer->id );
            $order->update_meta_data( $meta_key, $customer->id );
        }
        $order->save();

        return $customer->id;
    }

    public function get_payment_intent($request)
    {
        if ( ! $this->is_stripe_integration_available() ) {
            return parent::send_invalid_plugin_error(
                "You need to install WooCommerce Stripe Gateway or enable a Dokan Stripe module to use this api"
            );
        }
        $parameters = $request->get_params();
        $payment_intent_id = $parameters['id'];
        $payment_intent = $this->retrieve_payment_intent( $payment_intent_id );

        if ( is_wp_error( $payment_intent ) ) {
            return $payment_intent;
        }

        if ( isset( $payment_intent->error ) && ! empty( $payment_intent->error ) ) {
            return new WP_Error( 400, $payment_intent->error->message, array( 'status' => 400 ) );
        }

        return $payment_intent;
    }
}

new FlutterStripe;
