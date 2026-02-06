<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package FlutterRental
 */

class FlutterRental extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_rental';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_rental_routes'));
    }

    public function register_flutter_rental_routes()
    {
        register_rest_route($this->namespace, '/rental/availability', array(
            array(
                'methods' => "POST",
                'callback' => array($this, 'rental_availability'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/rental/disabled-dates', array(
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array($this, 'rental_disabled_dates'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/rental/settings', array(
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array($this, 'rental_settings'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

    }

    public function rental_availability(WP_REST_Request $request)
    {
        if (!function_exists('wcrp_rental_products_check_availability')) {
            return $this->sendError(
                'rental_products_inactive',
                __('Rental Products plugin is required to check availability.', 'mstore-api'),
                400
            );
        }

        $product_id   = absint($request->get_param('product_id'));
        $rent_from    = sanitize_text_field($request->get_param('rent_from'));
        $rent_to      = sanitize_text_field($request->get_param('rent_to'));
        $quantity     = absint($request->get_param('quantity'));
        $variation_id = absint($request->get_param('variation_id'));

        if ($quantity < 1) {
            $quantity = 1;
        }

        if (empty($product_id) || empty($rent_from) || empty($rent_to)) {
            return $this->sendError('missing_params', __('product_id, rent_from and rent_to are required.', 'mstore-api'), 400);
        }

        $rent_from_time = strtotime($rent_from);
        $rent_to_time   = strtotime($rent_to);

        if (false === $rent_from_time || false === $rent_to_time) {
            return $this->sendError('invalid_dates', __('rent_from and rent_to must be valid dates (Y-m-d).', 'mstore-api'), 400);
        }

        $rent_from = gmdate('Y-m-d', $rent_from_time);
        $rent_to   = gmdate('Y-m-d', $rent_to_time);

        if ($rent_from > $rent_to) {
            return $this->sendError('invalid_range', __('rent_from must be before rent_to.', 'mstore-api'), 400);
        }

        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);

        if (!$product) {
            return $this->sendError('product_not_found', __('The product could not be found.', 'mstore-api'), 404);
        }

        if ($variation_id > 0) {
            if ('variation' !== $product->get_type()) {
                return $this->sendError('invalid_variation', __('variation_id must reference a valid variation.', 'mstore-api'), 400);
            }

            if ($product->get_parent_id() !== $product_id) {
                return $this->sendError('variation_mismatch', __('variation_id does not belong to the provided product_id.', 'mstore-api'), 400);
            }
        }

        $availability_product_id = $product->get_id();
        $price_parent_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $variation_for_price     = $product->is_type('variation') ? $product->get_id() : 0;

        if (!$price_parent_product_id) {
            $price_parent_product_id = $product->get_id();
        }

        $availability_code = wcrp_rental_products_check_availability(
            $availability_product_id,
            $rent_from,
            $rent_to,
            $quantity
        );

        $is_available = ('available' === $availability_code);

        $unit_price = null;
        $total_price = null;
        $total_price_display = null;

        if (class_exists('WCRP_Rental_Products_Cart_Checks')) {
            $calculated_price = WCRP_Rental_Products_Cart_Checks::check_520_temp_cart_item_price_validation(
                $price_parent_product_id,
                $quantity,
                $rent_from,
                $rent_to,
                $variation_for_price
            );

            if (null !== $calculated_price) {
                $unit_price = (float) wc_format_decimal($calculated_price, wc_get_price_decimals());
                $total_price = (float) wc_format_decimal($unit_price * $quantity, wc_get_price_decimals());
                $total_price_display = wc_price($total_price);
            }
        }

        $response = array(
            'product_id'          => $product_id,
            'variation_id'        => $variation_for_price ? $variation_for_price : null,
            'rent_from'           => $rent_from,
            'rent_to'             => $rent_to,
            'quantity'            => $quantity,
            'availability_code'   => $availability_code,
            'is_available'        => $is_available,
            'currency'            => get_woocommerce_currency(),
            'unit_price'          => $unit_price,
            'total_price'         => $total_price,
            'total_price_display' => $total_price_display,
        );

        return rest_ensure_response($response);
    }

    public function rental_disabled_dates(WP_REST_Request $request)
    {
        if (!function_exists('wcrp_rental_products_check_availability')) {
            return $this->sendError(
                'rental_products_inactive',
                __('Rental Products plugin is required to check availability.', 'mstore-api'),
                400
            );
        }

        $product_id = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id'));
        $rent_from = sanitize_text_field($request->get_param('rent_from'));
        $rent_to = sanitize_text_field($request->get_param('rent_to'));
        $quantity_needed = max(1, absint($request->get_param('quantity')));

        if (empty($product_id)) {
            return $this->sendError('missing_params', __('product_id is required.', 'mstore-api'), 400);
        }

        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);

        if (!$product) {
            return $this->sendError(
                'product_not_found',
                __('The product could not be found.', 'mstore-api'),
                404
            );
        }

        if ($variation_id > 0) {
            if ('variation' !== $product->get_type()) {
                return $this->sendError(
                    'invalid_variation',
                    __('variation_id must reference a valid variation.', 'mstore-api'),
                    400
                );
            }

            if ($product->get_parent_id() !== $product_id) {
                return $this->sendError(
                    'variation_mismatch',
                    __('variation_id does not belong to the provided product_id.', 'mstore-api'),
                    400
                );
            }
        }

        $target_product_id = $variation_id > 0 ? $variation_id : $product_id;
        $default_rental_options = wcrp_rental_products_default_rental_options();
        $return_days_threshold = get_post_meta($target_product_id, '_wcrp_rental_products_return_days_threshold', true);
        $return_days_threshold = ('' !== $return_days_threshold ? $return_days_threshold : $default_rental_options['_wcrp_rental_products_return_days_threshold']);

        $ajax_body = [
            'action' => 'wcrp_rental_products_rental_form_update',
            'nonce' => wp_create_nonce('wcrp_rental_products_rental_form_update'),
            'qty' => $quantity_needed,
            'product_id' => $product_id,
            'rent_from' => $rent_from,
            'rent_to' => $rent_to,
            'return_days_threshold' => $return_days_threshold,
            'add_rental_products_popup' => '0',
        ];

        if ($variation_id > 0) {
            $ajax_body['variation_id'] = $variation_id;
        }

        $ajax_response = wp_remote_post(
            admin_url('admin-ajax.php'),
            [
                'body' => $ajax_body,
                'timeout' => 20,
            ]
        );

        if (is_wp_error($ajax_response)) {
            return $this->sendError(
                'ajax_request_failed',
                __('Unable to reach the rental form update endpoint.', 'mstore-api'),
                500
            );
        }

        $ajax_body_response = wp_remote_retrieve_body($ajax_response);

        if (empty($ajax_body_response)) {
            return $this->sendError(
                'ajax_response_empty',
                __('The rental form update response was empty.', 'mstore-api'),
                500
            );
        }

        $ajax_data = json_decode($ajax_body_response, true);

        if (!is_array($ajax_data) || !isset($ajax_data['disabled_dates'])) {
            return $this->sendError(
                'invalid_ajax_response',
                sprintf(
                    /* translators: %s: raw ajax response */
                    __('Unexpected rental form response: %s', 'mstore-api'),
                    wp_trim_words($ajax_body_response, 40)
                ),
                500
            );
        }

        $response = [
            'product_id' => $product_id,
            'variation_id' => $variation_id > 0 ? $variation_id : null,
            'quantity' => $quantity_needed,
            'rent_from' => $rent_from,
            'rent_to' => $rent_to,
            'minimum_date' => gmdate('Y-m-d', strtotime('-7 days')),
            'maximum_date' => wcrp_rental_products_rental_form_maximum_date('date'),
            'disabled_dates' => $ajax_data['disabled_dates'],
        ];

        return rest_ensure_response($response);
    }

    public function rental_settings(WP_REST_Request $request)
    {
        if (!function_exists('wcrp_rental_products_default_rental_options')) {
            return $this->sendError(
                'rental_products_inactive',
                __('Rental Products plugin is required to read settings.', 'mstore-api'),
                400
            );
        }

        $product_id = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id'));

        if (empty($product_id)) {
            return $this->sendError('missing_params', __('product_id is required.', 'mstore-api'), 400);
        }

        $target_product_id = $variation_id > 0 ? $variation_id : $product_id;
        $default_rental_options = wcrp_rental_products_default_rental_options();

        $start_days_threshold = get_post_meta($target_product_id, '_wcrp_rental_products_start_days_threshold', true);
        $start_days_threshold = (
            '' !== $start_days_threshold
                ? $start_days_threshold
                : $default_rental_options['_wcrp_rental_products_start_days_threshold']
        );

        $return_days_threshold = get_post_meta($target_product_id, '_wcrp_rental_products_return_days_threshold', true);
        $return_days_threshold = (
            '' !== $return_days_threshold
                ? $return_days_threshold
                : $default_rental_options['_wcrp_rental_products_return_days_threshold']
        );

        $earliest_available_date = get_post_meta($target_product_id, '_wcrp_rental_products_earliest_available_date', true);
        $earliest_available_date = (
            '' !== $earliest_available_date
                ? $earliest_available_date
                : $default_rental_options['_wcrp_rental_products_earliest_available_date']
        );

        $response = [
            'product_id' => $product_id,
            'variation_id' => $variation_id > 0 ? $variation_id : null,
            'start_days_threshold' => $start_days_threshold,
            'return_days_threshold' => $return_days_threshold,
            'earliest_available_date' => $earliest_available_date,
            'maximum_date' => wcrp_rental_products_rental_form_maximum_date('date'),
        ];

        return rest_ensure_response($response);
    }
}

new FlutterRental;