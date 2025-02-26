<?php

class Flutter_Wc_Smart_Cod_Public extends Wc_Smart_Cod_Public {
    public function __construct() {
		parent::__construct( 'wc-smart-cod-risk-free' );
    }

    private function is_enabled() {
		$settings = $this->get_rf_settings();
		return isset( $settings['enabled'] ) && $settings['enabled'] === 'yes' && isset( $settings['rules'] ) && ! empty( $settings['rules'] );
	}

    protected function get_rf_settings() {
		return get_option( 'woocommerce_smartcod_riskfree_settings' );
	}

    private function has_gateways_available( $rule ) {

		if ( empty( $rule['disallowed_payment_gateways'] ) ) {
			return true;
		}

        $gateways = WC()->payment_gateways->get_available_payment_gateways();

		foreach ( $gateways as $key => $gateway ) {
			if ( in_array( $key, $rule['disallowed_payment_gateways'] ) ) {
				unset( $gateways[ $key ] );
			}
		}

		return count($gateways) > 0;

	}

    public function get_risk_free_cod($cart, $extra_fee){
        if ( ! $this->is_enabled() ) {
            return null;
        }
        $settings = $this->get_rf_settings();
        $targets  = Wc_Smart_Cod_Risk_Free_Admin::$default_restriction_settings;
        $rule     = $this->check_user_applies_rule( $settings, $targets );

        if ( ! $rule ) {
            return null;
        }

        if ( ! $this->has_gateways_available( $rule )) {
            return null;
        }

        $total = $cart->get_totals()['total'];

		$user_advance_amount = $this->get_user_advance_amount( $rule['fee'], $rule['fee_type'], 'rf_percentage_calculation' );

		if ( ! $user_advance_amount || ! is_numeric( $user_advance_amount ) ) {
			return null;
		}

		$remaining_amount = $total + $extra_fee - $user_advance_amount;

		if ( $remaining_amount > 0 ) {
            $description = "";
            
            if ( $extra_fee > 0 && ( ! isset( $settings['hide_additional_extrafee_description'] ) || $settings['hide_additional_extrafee_description'] !== 'yes' )) {
                $description .= sprintf( __( '<strong>Cash on delivery fee of %s</strong> has been added to order total amount', 'wc-smart-cod' ), strip_tags( wc_price( $extra_fee ) ) );
            }

            if ( ! isset( $settings['hide_additional_rf_description'] ) || $settings['hide_additional_rf_description'] !== 'yes' ) {
                if (!empty($description)) {
                    $description .= '<br /> <br />';
                }
				$description .= sprintf( __( '<strong>Pay %s online</strong> and %s with cash on delivery', 'wc-smart-cod' ), strip_tags( wc_price( $user_advance_amount ) ), strip_tags( wc_price( $remaining_amount ) ) );
			}

            return ['description' => $description, 'disallowed_payment_gateways' => $rule['disallowed_payment_gateways'], 'real_total' => $total + $extra_fee, 'remaining_amount'=>$remaining_amount, 'user_advance_amount' => $user_advance_amount];
		}

        return null;
    }

    public function get_extra_fee($cart){
        if ( ! $this->are_extrafees_enabled() ) {
			return 0;
		}
        $settings = $this->get_extrafees_settings();

		$fees = array();

		$multivendor = Wc_Smart_Cod_MultiVendors_Helper::get_instance();

		if ( $multivendor::has_wcfm()
			&& isset( $settings['wcfm_multifees_enabled'] )
			&& $settings['wcfm_multifees_enabled'] === 'yes' ) {
			$fees = $this->handle_vendor_fees( $settings );
		}

		if ( $multivendor::has_dokan() ) {
			$subcarts = $multivendor::break_cart_by_vendors( $cart, $fees, $settings );
			foreach ( $subcarts as $vendor_id => $vendor_data ) {
				$name        = apply_filters( 'wc_smart_cod_fee_title', __( 'Cash on delivery', 'woocommerce' ) );
				$vendor_name = $vendor_data['vendor_name'];
				$name       .= " - $vendor_name";

				$fee = $this->calculate_smartcod_fees( $vendor_data['total'] );

				$fees[] = array(
					'amount' => floatval( $fee ),
					'name'   => $name,
					'id'     => "wsc_extra_fee_$vendor_id",
				);
			}
		}

		if ( empty( $fees ) ) {
			$fees[] = array(
				'amount' => $this->calculate_smartcod_fees(),
				'name'   => apply_filters( 'wc_smart_cod_fee_title', __( 'Cash on delivery', 'woocommerce' ) ),
				'id'     => 'wsc_extra_fee',
			);
		}

		$total_fee = 0;

		foreach ( $fees as $fee_obj ) {

			$fee = apply_filters( 'wc_smart_cod_fee', $fee_obj );

			$amount = $fee['amount'];

			if ( ! is_null( $amount ) ) {

				if ( ! is_numeric( $amount ) ) {
					return;
				}

				if ( is_string( $amount ) ) {
					$amount = floatval( $amount );
				}

				$amount = parent::maybe_get_multicurrency_value( $amount );

				if ( $amount > 0 ) {
					$total_fee += $amount;
				}
			}
		}

        return $total_fee;
    }

    private function handle_vendor_fees( $ef_settings, $key = 'wcfm_vendors_restriction' ) {
		$targets = Wc_Smart_Cod_Risk_Free_Admin::$default_restriction_settings;
		$rules   = $this->get_user_rules_map( $ef_settings, $targets, $key, false );
		$fees    = array();
		foreach ( $rules as $rule ) {
			$id     = $rule['id'];
			$fees[] = array(
				'amount' => $this->get_user_advance_amount( $rule['fee'], $rule['fee_type'], 'percentage_calculation' ),
				'name'   => $rule['name'],
				'id'     => "wsc_extra_fee_{$id}",
			);
		}
		return $fees;
	}
}