<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package PayPal
 */

class FlutterPayPal extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_paypal';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_paypal_routes'));
    }

    public function register_flutter_paypal_routes()
    {
        register_rest_route($this->namespace, '/process_payment', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'dokan_paypal_api_process_payment'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                },
                'args' => [
                    'order_id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ( $value ) {
                            return $value > 0 && (bool) wc_get_order( $value );
                        },
                    ],
                ],
            ),
        ));
    }

    function dokan_paypal_api_process_payment( \WP_REST_Request $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'dokan_paypal_invalid_order', __( 'Invalid order.', 'dokan' ), [ 'status' => 404 ] );
        }

        // Ensure WooCommerce session exists for gateway side-effects.
        if ( null === WC()->session ) {
            WC()->initialize_session();
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways['dokan_paypal_marketplace'] ?? null;

        if ( ! $gateway || 'yes' !== $gateway->get_option( 'enabled' ) ) {
            return new \WP_Error( 'dokan_paypal_disabled', __( 'Dokan PayPal Marketplace gateway is not available.', 'dokan' ), [ 'status' => 400 ] );
        }

        $result = $gateway->process_payment( $order_id );

        if ( empty( $result ) || ( isset( $result['result'] ) && 'success' !== $result['result'] ) ) {
            return new \WP_Error(
                'dokan_paypal_failed',
                __( 'PayPal failed to create the checkout session.', 'dokan' ),
                [
                    'status'  => 500,
                    'details' => $result,
                ]
            );
        }

        $response = [
            'order_id'          => $order_id,
            'paypal_order_id'   => $order->get_meta( '_dokan_paypal_payment_id' ),
            'result'            => $result['result'] ?? 'success',
            'redirect'          => $result['redirect'] ?? '',
            'has_sub_orders'    => (bool) $order->get_meta( 'has_sub_order' ),
            'sub_orders'        => dokan()->order->get_child_orders( $order_id, 'ids' ),
            'gateway_reference' => $order->get_transaction_id(),
            'success_redirect'  => $result['success_redirect'] ?? '',
            'cancel_redirect'   => $result['cancel_redirect'] ?? ''
        ];

        return rest_ensure_response( $response );
    }
}

new FlutterPayPal;