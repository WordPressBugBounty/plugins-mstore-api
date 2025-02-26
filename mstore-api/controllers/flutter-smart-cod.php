<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package SmartCOD
 */
class FlutterSmartCOD extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_smart_cod';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_filter( 'flutter_woo_payment_method_response', array($this, 'check_smart_cod'), 10, 3 );
        add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'recalculate_order_risk_free_after_calculate_totals' ), 10, 2 );
        add_action('rest_api_init', array($this, 'register_flutter_smart_cod_routes'));
    }

    public function check_smart_cod($result, $payment_gateway, $cart)
    {
        if ( class_exists( 'Wc_Smart_Cod_Admin' ) ) {
            if ( $payment_gateway instanceof Wc_Smart_Cod_Admin ) {
                require_once(plugin_dir_path(__FILE__) . 'helpers/extensions/flutter-wc-smart-cod-public.php');
                $obj = new Flutter_Wc_Smart_Cod_Public();

                $extra_fee = $obj->get_extra_fee($cart);
                $risk_free_cod = $obj->get_risk_free_cod($cart, $extra_fee);

                if ($risk_free_cod != null) {
                    if (!empty($risk_free_cod['description'])) {
                        $result['description'] = str_replace("\r\n", "<br>", $result['description']).'<br /> <br />'.$risk_free_cod['description'];
                    }
                    if ( array_key_exists( 'description', $risk_free_cod ) ) {
                        unset( $risk_free_cod[ 'description' ] );
                    }
                    $result['smart_cod'] = array_merge([
                        'is_risk_free_enabled' => true,
                        'extra_fee' => $extra_fee,
                    ], $risk_free_cod);
                } else {
                    $result['smart_cod'] = [
                        'is_risk_free_enabled' => false,
                        'extra_fee' => $extra_fee,
                    ];
                }
            }
        }
        return $result;
    }

    public function recalculate_order_risk_free_after_calculate_totals( $and_taxes, $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				return;
			}
            $wsc_advance_total = $order->get_meta('wsc_advance_total');
            $connected_order_id = $order->get_meta( 'wsc_advance_order_id' );
            if (!empty($wsc_advance_total) && !$connected_order_id) {
                $order->set_total($wsc_advance_total);
            }
	}

    public function register_flutter_smart_cod_routes()
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
        $body = json_decode($json, TRUE);
        $order_id = sanitize_text_field($body['order_id']);
        
        $order = wc_get_order( $order_id );

		if ( ! $this->is_rf_order( $order ) ) {
			return ['success' => true];
		}

		$settings = get_option( 'woocommerce_smartcod_riskfree_settings' );

		if ( isset( $settings['disable_additional_order'] ) && $settings['disable_additional_order'] === 'yes' ) {
			$order->calculate_totals();
			if ( !isset( $settings['use_advance_payment_method'] ) || $settings['use_advance_payment_method'] === 'no' ) {
				
				$order->add_meta_data( 'original_payment_method', $order->get_payment_method() );
				$order->set_payment_method('cod');
			}

			$order->save();
			return ['success' => true];
		}

		$connected_order_id = $order->get_meta( 'wsc_advance_order_id' );
		if ( $connected_order_id ) {
			// processed
			return ['success' => true];
		}

		$advance_amount = $order->get_total();

		/**
		 * Create risk free
		 * advance amount order
		 */

		$force_cod_method = isset( $settings['force_payment_method_cod'] ) && $settings['force_payment_method_cod'] === 'yes';
		
		$_order_id = $this->create_wc_order( $order, $advance_amount, $force_cod_method );

		/**
		 * Subtract the advance
		 */
		$item = new WC_Order_Item_Fee();
		$item->set_name( __( 'Advance amount', 'wc-smart-cod' ) );
		$item->set_total( - $advance_amount );
		$item->add_meta_data( 'is_wsc_advance_fee', true );
		$item->set_tax_status( 'none' );
		$item->set_tax_class( '' );
		$item->save();

		if ( class_exists( 'PaytabsHelper' ) ) {
			/**
			 * Remove the previously added
			 * negative Item Fee
			 */
			foreach ( $order->get_items( 'fee' ) as $item_id => $item_fee ) {
				if ( $item_fee->get_meta( 'is_wsc_rf_ramount' ) === '1' ) {
					$order->remove_item( $item_id );
					break;
				}
			}
		}

		$order->add_item( $item );
		$order->add_meta_data( 'wsc_advance_order_id', $_order_id );
		$order->set_payment_method( 'cod' );
		$order->calculate_totals( false );

		if ( isset( $settings['change_order_status'] ) && $settings['change_order_status'] !== '0' ) {
			$new_status = str_replace( 'wc-', '', $settings['change_order_status'] );
			$order->update_status( $new_status );
		}

		$order->save();
		return ['success' => true];
    }

    private function is_rf_order( $order ) {
		return $order->get_meta( 'is_wsc_rf' ) === '1';
	}

    private function create_wc_order( $original_order, $advance_amount, $force_cod_method ) {
		$order = new WC_Order();

		$data = $original_order->get_data();

		$billing  = $data['billing'];
		$shipping = $data['shipping'];
		foreach ( $billing as $key => $value ) {
			if ( is_callable( array( $order, "set_billing_{$key}" ) ) ) {
				$order->{"set_billing_{$key}"}( $value );
			}
		}

		foreach ( $shipping as $key => $value ) {
			if ( is_callable( array( $order, "set_shipping_{$key}" ) ) ) {
				$order->{"set_shipping_{$key}"}( $value );
			}
		}

		$payment_method = $data['payment_method'];

		$order->set_payment_method( $force_cod_method ? 'cod' : $payment_method );
		$order->set_created_via( 'programatically' );
		$order->set_customer_id( get_current_user_id() );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );

		$item = new WC_Order_Item_Fee();
		$item->set_name( "Advance amount for cash on delivery order through $payment_method" );
		$item->set_total( $advance_amount );
		$item->set_tax_class( '' );
		$item->set_tax_status( 'none' );
		$item->save();

		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->add_meta_data( 'is_wsc_advance', 1 );
		$order->add_meta_data( 'original_payment_method', $data['payment_method'] );
		if ( $original_order->is_paid() ) {
			$order->set_status( 'completed' );
		}
		$order_id = $order->save();

		// Returns the order ID
		return $order_id;
	}
}

new FlutterSmartCOD;