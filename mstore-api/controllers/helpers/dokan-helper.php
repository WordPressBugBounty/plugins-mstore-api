<?php
if ( ! function_exists( 'mstore_api_get_dokan_split_payment_breakdown' ) ) {
    /**
     * Calculate Dokan vendor earning and commission for a WooCommerce order.
     *
     * @param WC_Order $order
     *
     * @return array|null
     */
    function mstore_api_get_dokan_split_payment_breakdown( $order ) {
        if ( ! ( $order instanceof WC_Order ) || ! function_exists( 'dokan' ) ) {
            return null;
        }

        $net_amount  = dokan()->commission->get_earning_by_order( $order, 'seller' );
        $order_total = (float) $order->get_total();

        if ( is_wp_error( $net_amount ) || null === $net_amount ) {
            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT order_total, net_amount FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d LIMIT 1",
                    $order->get_id()
                )
            );

            if ( $row ) {
                $order_total = isset( $row->order_total ) ? (float) $row->order_total : $order_total;
                $net_amount  = isset( $row->net_amount ) ? (float) $row->net_amount : $net_amount;
            }
        }

        if ( ! is_numeric( $net_amount ) ) {
            return null;
        }

        $net_amount       = (float) $net_amount;
        $total_commission = (float) $order_total - $net_amount;

        return array(
            'vendor_earning'   => wc_format_decimal( $net_amount, wc_get_price_decimals() ),
            'total_commission' => wc_format_decimal( $total_commission, wc_get_price_decimals() ),
        );
    }
}
