<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CUSTOM_WC_REST_Orders_Controller extends WC_REST_Orders_Controller
{
    private function is_themehigh_checkout_field_editor_active() {
        return function_exists('is_plugin_active') && (
            is_plugin_active('woo-checkout-field-editor-pro/checkout-form-designer.php')
        );
    }

    private function normalize_themehigh_checkout_meta($params) {
        if (!$this->is_themehigh_checkout_field_editor_active()) {
            return $params;
        }

        if (!isset($params['meta_data']) || !is_array($params['meta_data'])) {
            return $params;
        }

        $normalized_meta = array();

        foreach ($params['meta_data'] as $meta_item) {
            if (!is_array($meta_item) || !isset($meta_item['key'])) {
                continue;
            }

            $raw_key = sanitize_text_field($meta_item['key']);
            $value = isset($meta_item['value']) ? $meta_item['value'] : '';

            if ($raw_key === '') {
                continue;
            }

            // App payload for QuadLayers uses "_additional_*"; ThemeHigh reads "additional_*".
            $normalized_key = strpos($raw_key, '_additional_') === 0 ? ltrim($raw_key, '_') : $raw_key;

            // Keep WooCommerce note in customer_note and avoid duplicating it in meta fields.
            if ($normalized_key === 'additional_order_comments' || $normalized_key === 'order_comments') {
                if (!isset($params['customer_note']) || $params['customer_note'] === '') {
                    $params['customer_note'] = is_scalar($value) ? sanitize_text_field((string) $value) : '';
                }
                continue;
            }

            $normalized_meta[$normalized_key] = array(
                'key' => $normalized_key,
                'value' => $value,
            );
        }

        $params['meta_data'] = array_values($normalized_meta);
        return $params;
    }

    private function persist_themehigh_additional_meta_to_order($order, $params) {
        if (!$this->is_themehigh_checkout_field_editor_active()) {
            return;
        }

        if (!is_a($order, 'WC_Order') || !isset($params['meta_data']) || !is_array($params['meta_data'])) {
            return;
        }

        $has_changes = false;

        foreach ($params['meta_data'] as $meta_item) {
            if (!is_array($meta_item) || !isset($meta_item['key'])) {
                continue;
            }

            $key = sanitize_text_field($meta_item['key']);
            if ($key === '' || strpos($key, 'additional_') !== 0) {
                continue;
            }
            if ($key === 'additional_order_comments' || $key === 'order_comments') {
                continue;
            }

            $value = isset($meta_item['value']) ? $meta_item['value'] : '';
            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            }

            $order->update_meta_data($key, $value);
            $has_changes = true;
        }

        if ($has_changes) {
            $order->save();
        }
    }


    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_order';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_woo_routes'));
    }

    public function register_flutter_woo_routes()
    {
        register_rest_route($this->namespace, '/create', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_new_order'),
                'permission_callback' => array($this, 'custom_create_item_permissions_check'),
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE),
            ),
            'schema' => array($this, 'get_public_item_schema'),
        ));

        //some reasons can't use PUT method
        register_rest_route(
            $this->namespace,
            '/update' . '/(?P<id>[\d]+)',
            array(
                'args' => array(
                    'id' => array(
                        'description' => __('Unique identifier for the resource.', 'mstore-api'),
                        'type' => 'integer',
                    ),
                ),
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'update_item'),
                    'permission_callback' => array($this, 'custom_update_item_permissions_check'),
                    'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
                ),
                'schema' => array($this, 'get_public_item_schema'),
            )
        );

		register_rest_route(
            $this->namespace,
            '/update' . '/(?P<id>[\d]+)',
            array(
                'args' => array(
                    'id' => array(
                        'description' => __('Unique identifier for the resource.', 'mstore-api'),
                        'type' => 'integer',
                    ),
                ),
                array(
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => array($this, 'update_item'),
                    'permission_callback' => array($this, 'custom_update_item_permissions_check'),
                    'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE),
                ),
                'schema' => array($this, 'get_public_item_schema'),
            )
        );

        //some reasons can't use DELETE method
        register_rest_route(
            $this->namespace,
            '/delete' . '/(?P<id>[\d]+)',
            array(
                'args' => array(
                    'id' => array(
                        'description' => __('Unique identifier for the resource.', 'mstore-api'),
                        'type' => 'integer',
                    ),
                ),
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'new_delete_pending_order'),
                    'permission_callback' => array($this, 'custom_delete_item_permissions_check'),
                ),
                'schema' => array($this, 'get_public_item_schema'),
            )
        );
    }

    function custom_create_item_permissions_check($request)
    {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        $json = file_get_contents('php://input');
        $params = json_decode($json, TRUE);
        if (isset($cookie) && $cookie != null) {
            $user_id = validateCookieLogin($cookie);
            if (is_wp_error($user_id)) {
                return false;
            }
            $params["customer_id"] = $user_id;
            wp_set_current_user($user_id);
            $request->set_body_params($params);
            return true;
        } else {
            $params["customer_id"] = 0;
            $request->set_body_params($params);
            return true;
        }
    }

    function custom_update_item_permissions_check($request)
    {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        if (!isset($cookie) || $cookie == null) {
            return new WP_Error('rest_unauthenticated', 'Missing or invalid authentication cookie.', array('status' => 401));
        }

        $user_id = validateCookieLogin($cookie);
        if (is_wp_error($user_id)) {
            return $user_id; // validateCookieLogin already returns WP_Error on failure
        }

        $order = wc_get_order( (int) $request['id'] );

        if ( ! $order ) {
            return new WP_Error('rest_order_invalid', 'Invalid order ID.', array('status' => 404));
        }

        // Allow admins or shop managers (bypass ownership and field restrictions)
        if ( user_can( $user_id, 'edit_shop_orders' ) ) {
            wp_set_current_user($user_id);
            return true;
        }

        $params  = $request->get_params();

        // Only allow cancellation if the order is currently 'pending' or 'failed'.
        if (isset($params['status']) && $params['status'] === 'cancelled' && !in_array($order->get_status(), array('pending', 'failed'))) {
            return new WP_Error('rest_forbidden_status', 'You can only cancel pending or failed orders.', array('status' => 403));
        }

        // Keep status updates temporarily for backward compatibility with legacy apps.
        $blocked = array('set_paid', 'total', 'line_items', 'shipping_lines', 'fee_lines', 'coupon_lines', 'customer_id', 'transaction_id');

        foreach ($blocked as $field) {
            if (isset($params[$field])) {
                return new WP_Error('rest_forbidden_field', 'You do not have permission to update the ' . $field . ' field.', array('status' => 403));
            }
        }

        // Check order ownership & secure guest access
        $customer_id     = (int) $order->get_customer_id();
        $current_user_id = (int) $user_id;

        if ( $customer_id === 0 ) {
            // Guest order: validate via order_key
            $key = $request->get_param('order_key');
            if ( empty($key) || !hash_equals($order->get_order_key(), $key) ) {
                return new WP_Error('rest_forbidden_guest', 'Invalid order key for guest order.', array('status' => 403));
            }
        } else if ( $customer_id !== $current_user_id ) {
            // Logged-in user ownership check
            return new WP_Error('rest_forbidden_owner', 'You do not have permission to edit this order.', array('status' => 403));
        }

        // Set current user only after all authorization checks pass
        wp_set_current_user($user_id);

        return true;
    }

    function custom_delete_item_permissions_check($request)
    {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        if (!isset($cookie) || $cookie == null) {
            return new WP_Error('rest_unauthenticated', 'Missing or invalid authentication cookie.', array('status' => 401));
        }

        $user_id = validateCookieLogin($cookie);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $order = wc_get_order( (int) $request['id'] );

        if ( ! $order ) {
            return new WP_Error('rest_order_invalid', 'Invalid order ID.', array('status' => 404));
        }

        // Allow admins or shop managers
        if ( user_can( $user_id, 'edit_shop_orders' ) ) {
            return true;
        }

        // Check order ownership & secure guest access
        $customer_id     = (int) $order->get_customer_id();
        $current_user_id = (int) $user_id;

        if ( $customer_id === 0 ) {
            // Guest order: validate via order_key
            $key = $request->get_param('order_key');
            if ( empty($key) || !hash_equals($order->get_order_key(), $key) ) {
                return new WP_Error('rest_forbidden_guest', 'Invalid order key for guest order deletion.', array('status' => 403));
            }
        } else if ( $customer_id !== $current_user_id ) {
            // Logged-in user ownership check
            return new WP_Error('rest_forbidden_owner', 'You do not have permission to delete this order.', array('status' => 403));
        }

        return true;
    }

    function get_items($request)
    {
        // Exclude checkout-draft orders (created automatically by WooCommerce when loading checkout page)
        if (empty($request['status'])) {
            add_filter('woocommerce_rest_orders_prepare_object_query', function ($args) {
                if (isset($args['post_status']) && is_array($args['post_status'])) {
                    $args['post_status'] = array_diff($args['post_status'], ['wc-checkout-draft', 'checkout-draft']);
                } elseif (isset($args['status']) && is_array($args['status'])) {
                    $args['status'] = array_diff($args['status'], ['wc-checkout-draft', 'checkout-draft']);
                }
                return $args;
            });
        }
        return parent::get_items($request);
    }

    function create_new_order($request)
    {
        $params = $request->get_body_params();
        $params = $this->normalize_themehigh_checkout_meta($params);
        $request->set_body_params($params);

        if (isset($params['fee_lines']) && count($params['fee_lines']) > 0) {
            $fee_name = $params['fee_lines'][0]['name'];
            if ($fee_name == 'Via Wallet') {
                if (is_plugin_active('woo-wallet/woo-wallet.php')) {
                    $balance = woo_wallet()->wallet->get_wallet_balance($params["customer_id"], 'Edit');
                    $total = $params['fee_lines'][0]['total'];
                    if (floatval($balance) < floatval($total) * (-1)) {
                        return new WP_Error("invalid_wallet", "The wallet is not enough to checkout", array('status' => 400));
                    }
                }
            }
        }
        if (isset($params['payment_method']) && $params['payment_method'] == 'wallet' && isset($params['total'])) {
            if (is_plugin_active('woo-wallet/woo-wallet.php')) {
                $balance = woo_wallet()->wallet->get_wallet_balance($params["customer_id"], 'Edit');
                if (floatval($balance) < floatval($params['total'])) {
                    return new WP_Error("invalid_wallet", "The wallet is not enough to checkout", array('status' => 400));
                }
            }
        }

        /*** Fix: can not save all meta_data if they have same key ***/
        $has_change = false;
        if (isset($params['line_items']) && count($params['line_items']) > 0) {
            $line_items = array();
            foreach ($params['line_items'] as $key => $value) {
               if (isset($value['meta_data']) && count($value['meta_data']) > 0){
                $meta_data = array();
                $keys = array();
                foreach ($value['meta_data'] as $k => $v) {
                    $keys[] = $v['key'];
                    $count = array_count_values($keys)[$v['key']];
                    if ($count > 1) {
                        $has_change = true;
                        $meta_data[] = ['key'=>$v['key'].' '.$count, 'value'=>$v['value']];
                    }else{
                        $meta_data[] = $v;
                    }
                }
                $value['meta_data'] = $meta_data;
               }
               $line_items[] = $value;
            }
            $params['line_items'] = $line_items;
        }
        if($has_change){
            $request = new WP_REST_Request( $request->get_method() );
		    $request->set_body_params( $params );
        }
        /************************/
        $auction_validation = $this->validate_auction_line_items( $params );
        if ( is_wp_error( $auction_validation ) ) {
            return $auction_validation;
        }

        // Same process from the function WC_AJAX()->update_order_review in the
        // file wp-content/plugins/woocommerce/includes/class-wc-ajax.php
        // Or WC_Checkout()->process_customer in the file
        // wp-content/plugins/woocommerce/includes/class-wc-checkout.php
        $billing = isset($params['billing']) ? $params['billing'] : NULL;
        $shipping = isset($params['shipping']) ? $params['shipping'] : $billing;

        if (isset($params["customer_id"]) && $params["customer_id"] != 0) {
            $user_id = $params["customer_id"];

            if (isset($billing)) {
                if (isset($billing["first_name"]) && !empty($billing["first_name"])) {
                    update_user_meta($user_id, 'billing_first_name', $billing["first_name"]);
                }
                if (isset($billing["last_name"]) && !empty($billing["last_name"])) {
                    update_user_meta($user_id, 'billing_last_name', $billing["last_name"]);
                }
                if (isset($billing["company"]) && !empty($billing["company"])) {
                    update_user_meta($user_id, 'billing_company', $billing["company"]);
                }
                if (isset($billing["address_1"]) && !empty($billing["address_1"])) {
                    update_user_meta($user_id, 'billing_address_1', $billing["address_1"]);
                }
                if (isset($billing["address_2"]) && !empty($billing["address_2"])) {
                    update_user_meta($user_id, 'billing_address_2', $billing["address_2"]);
                }
                if (isset($billing["city"]) && !empty($billing["city"])) {
                    update_user_meta($user_id, 'billing_city', $billing["city"]);
                }
                if (isset($billing["state"]) && !empty($billing["state"])) {
                    update_user_meta($user_id, 'billing_state', $billing["state"]);
                }
                if (isset($billing["postcode"]) && !empty($billing["postcode"])) {
                    update_user_meta($user_id, 'billing_postcode', $billing["postcode"]);
                }
                if (isset($billing["country"]) && !empty($billing["country"])) {
                    update_user_meta($user_id, 'billing_country', $billing["country"]);
                }
                if (isset($billing["email"]) && !empty($billing["email"])) {
                    update_user_meta($user_id, 'billing_email', $billing["email"]);
                }
                if (isset($billing["phone"]) && !empty($billing["phone"])) {
                    update_user_meta($user_id, 'billing_phone', $billing["phone"]);
                }
            }
            if (isset($shipping)) {
                if (isset($shipping["first_name"]) && !empty($shipping["first_name"])) {
                    update_user_meta($user_id, 'shipping_first_name', $shipping["first_name"]);
                }
                if (isset($shipping["last_name"]) && !empty($shipping["last_name"])) {
                    update_user_meta($user_id, 'shipping_last_name', $shipping["last_name"]);
                }
                if (isset($shipping["company"]) && !empty($shipping["company"])) {
                    update_user_meta($user_id, 'shipping_company', $shipping["company"]);
                }
                if (isset($shipping["address_1"]) && !empty($shipping["address_1"])) {
                    update_user_meta($user_id, 'shipping_address_1', $shipping["address_1"]);
                }
                if (isset($shipping["address_2"]) && !empty($shipping["address_2"])) {
                    update_user_meta($user_id, 'shipping_address_2', $shipping["address_2"]);
                }
                if (isset($shipping["city"]) && !empty($shipping["city"])) {
                    update_user_meta($user_id, 'shipping_city', $shipping["city"]);
                }
                if (isset($shipping["state"]) && !empty($shipping["state"])) {
                    update_user_meta($user_id, 'shipping_state', $shipping["state"]);
                }
                if (isset($shipping["postcode"]) && !empty($shipping["postcode"])) {
                    update_user_meta($user_id, 'shipping_postcode', $shipping["postcode"]);
                }
                if (isset($shipping["country"]) && !empty($shipping["country"])) {
                    update_user_meta($user_id, 'shipping_country', $shipping["country"]);
                }
                if (isset($shipping["email"]) && !empty($shipping["email"])) {
                    update_user_meta($user_id, 'shipping_email', $shipping["email"]);
                }
                if (isset($shipping["phone"]) && !empty($shipping["phone"])) {
                    update_user_meta($user_id, 'shipping_phone', $shipping["phone"]);
                }
            }
        }

        // B2BKing price filters are NOT registered during REST API bootstrap because
        // the user is unauthenticated at plugins_loaded/init time. Inject correct B2B
        // prices into the request before create_item() so WooCommerce uses them directly,
        // avoiding a costly post-creation recalculation.
        if (class_exists('B2bking') && get_current_user_id() && isset($params['line_items'])) {
            $user_id     = get_current_user_id();
            $b2b_user_id = b2bking()->get_top_parent_account($user_id);
            $group_id    = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($b2b_user_id), $b2b_user_id);
            $is_b2b      = get_user_meta($b2b_user_id, 'b2bking_b2buser', true) === 'yes';

            if ($is_b2b && $group_id) {
                foreach ($params['line_items'] as &$line_item) {
                    $product_id = $line_item['product_id'] ?? 0;
                    if (!$product_id) continue;
                    $variation_id = !empty($line_item['variation_id']) ? (int) $line_item['variation_id'] : 0;
                    $product = wc_get_product($variation_id ?: $product_id);
                    if (!$product) continue;

                    // B2BKing stores group prices on the parent product ID, not the variation.
                    $price_post_id = $product->get_parent_id() ?: $product->get_id();
                    $b2b_reg  = b2bking()->tofloat(get_post_meta($price_post_id, 'b2bking_regular_product_price_group_' . $group_id, true));
                    $b2b_sale = b2bking()->tofloat(get_post_meta($price_post_id, 'b2bking_sale_product_price_group_' . $group_id, true));
                    if (empty($b2b_reg) && empty($b2b_sale)) continue;

                    $uses_sale  = $product->is_on_sale() && !empty($b2b_sale);
                    $b2b_price  = (float) ($uses_sale ? $b2b_sale : $b2b_reg);
                    $qty        = $line_item['quantity'] ?? 1;
                    $subtotal   = wc_get_price_excluding_tax($product, ['qty' => $qty, 'price' => $b2b_price]);

                    $line_item['subtotal'] = $subtotal;
                    $line_item['total']    = $subtotal;
                }
                unset($line_item);
                // WP_REST_Request reads JSON via get_json_params() which parses the raw body.
                // Must replace the raw body so $request['line_items'] reflects the updated prices.
                $request->set_body(wp_json_encode($params));
            }
        }

        $response = $this->create_item($request);
        if(is_wp_error($response)){
            return $response;
        }
		$data = $response->get_data();

        // Send the customer invoice email.
       	$order = wc_get_order( $data['id'] );
        // Add additional field in order detail
        $this->persist_themehigh_additional_meta_to_order($order, $params);

        if ($order->get_payment_method() == 'cod') {
           if ( $order->get_total() > 0 ) {
			// Mark as processing or on-hold (payment won't be taken until delivery).
                $order->update_status( apply_filters( 'woocommerce_cod_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'mstore-api' ) );
            } else {
                $order->payment_complete();
            }
        }

        if($order->get_payment_method() == 'cod' || $order->has_status( array( 'processing', 'completed' ) )){
            WC()->payment_gateways();
            WC()->shipping();
            WC()->mailer()->customer_invoice( $order );
            WC()->mailer()->emails['WC_Email_New_Order']->trigger( $order->get_id(), $order, true );
            add_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
            WC()->mailer()->emails['WC_Email_New_Order']->trigger( $order->get_id(), $order, true );
        }

        //add order note if payment method is tap
        if (isset($params['payment_method']) && $params['payment_method'] == 'tap' && isset($params['transaction_id'])) {
            $order->payment_complete();
            $order->add_order_note('Tap payment successful.<br/>Tap ID: '.$params['transaction_id']);
        }

        //update order type for wholesale
        if (class_exists('WooCommerceWholeSalePrices')) {
            global $wc_wholesale_prices;
            $wc_wholesale_prices->wwp_order->add_order_type_meta_to_wc_orders($data['id']);
        }

        //add order to wcfm_marketplace_orders table to show order on the vendor dashboard
        if(class_exists('WCFMmp')) {
            do_action('wcfm_manual_order_processed', $data['id'], $order, $order);
        }

        return  $response;
    }

    /**
     * Validate auction line items before creating order.
     *
     * @param array $params Request body params.
     * @return null|WP_Error
     */
    private function validate_auction_line_items( $params ) {
        if ( empty( $params['line_items'] ) || ! is_array( $params['line_items'] ) ) {
            return null;
        }

        if ( ! class_exists( 'WooCommerce_simple_auction' ) ) {
            return null;
        }

        $customer_id = isset( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0;

        foreach ( $params['line_items'] as $line_item ) {
            $product_id   = isset( $line_item['product_id'] ) ? absint( $line_item['product_id'] ) : 0;
            $variation_id = isset( $line_item['variation_id'] ) ? absint( $line_item['variation_id'] ) : 0;
            $target_id    = $variation_id > 0 ? $variation_id : $product_id;

            if ( ! $target_id ) {
                continue;
            }

            $product = wc_get_product( $target_id );
            if ( ! $product || ! method_exists( $product, 'get_type' ) || $product->get_type() !== 'auction' ) {
                continue;
            }

            if ( $customer_id <= 0 ) {
                return new WP_Error(
                    'auction_login_required',
                    __( 'You must be logged in to pay for auction products.', 'mstore-api' ),
                    array( 'status' => 401 )
                );
            }

            if ( (string) $product->get_auction_closed() !== '2' ) {
                return new WP_Error(
                    'auction_not_ready',
                    __( 'This auction is closed.', 'mstore-api' ),
                    array( 'status' => 400 )
                );
            }

            if ( $product->get_auction_payed() ) {
                return new WP_Error(
                    'auction_already_paid',
                    __( 'This auction product has already been paid for.', 'mstore-api' ),
                    array( 'status' => 400 )
                );
            }

            if ( $product->get_auction_type() === 'reverse' && get_option( 'simple_auctions_remove_pay_reverse', 'no' ) === 'yes' ) {
                return new WP_Error(
                    'auction_reverse_not_payable',
                    __( 'Reverse auctions cannot be paid for via this endpoint.', 'mstore-api' ),
                    array( 'status' => 400 )
                );
            }

            $current_bider = absint( $product->get_auction_current_bider() );
            if ( $current_bider !== $customer_id ) {
                return new WP_Error(
                    'auction_not_winner',
                    sprintf(
                        /* translators: %s: product title. */
                        __( 'You are not the winning bidder for "%s".', 'mstore-api' ),
                        $product->get_title()
                    ),
                    array( 'status' => 400 )
                );
            }
        }

        return null;
    }

    function new_delete_pending_order($request){
        add_filter( 'woocommerce_rest_check_permissions', '__return_true' );
        $response = $this->delete_item($request);
        remove_filter( 'woocommerce_rest_check_permissions', '__return_true' );
        return $response;
    }
}

new CUSTOM_WC_REST_Orders_Controller();
