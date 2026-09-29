<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-object authorization for the vendor-admin REST surface.
 *
 * Holding a marketplace vendor role only proves the caller may use the
 * vendor-admin API at all. Every handler that reads or writes a specific
 * order, product or review must also confirm that the object belongs to the
 * caller (or that the caller manages the whole store). These helpers are the
 * single place where that ownership rule lives so every platform helper
 * (Woo, WCFM, Dokan) applies the same check.
 */
class VendorAdminAccessHelper
{
    /**
     * Store managers may act on any object in the store.
     */
    public static function is_store_manager($user_id)
    {
        $user_id = absint($user_id);
        if (empty($user_id)) {
            return false;
        }

        return user_can($user_id, 'manage_woocommerce') || user_can($user_id, 'manage_options');
    }

    /**
     * Resolve the vendor / author of a product (variations resolve to their parent).
     */
    public static function get_product_owner_id($product)
    {
        if (is_numeric($product)) {
            $product = wc_get_product(absint($product));
        }

        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return 0;
        }

        $post_id = $product->get_id();
        if (method_exists($product, 'get_parent_id') && $product->get_parent_id()) {
            $post_id = $product->get_parent_id();
        }

        $post = get_post($post_id);

        return $post ? absint($post->post_author) : 0;
    }

    public static function can_manage_product($user_id, $product)
    {
        $user_id = absint($user_id);
        if (empty($user_id)) {
            return false;
        }

        if (self::is_store_manager($user_id)) {
            return true;
        }

        $owner_id = self::get_product_owner_id($product);

        return $owner_id > 0 && $owner_id === $user_id;
    }

    /**
     * Collect every vendor id attached to an order: marketplace order tables
     * (WCFM / Dokan) plus the author of each purchased product.
     *
     * @return int[]
     */
    public static function get_order_vendor_ids($order)
    {
        global $wpdb;

        if (is_numeric($order)) {
            $order = wc_get_order(absint($order));
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return array();
        }

        $order_id = $order->get_id();
        $vendor_ids = array();

        if (class_exists('WCFMmp') || is_plugin_active('wc-multivendor-marketplace/wc-multivendor-marketplace.php')) {
            $table = $wpdb->prefix . 'wcfm_marketplace_orders';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_col($wpdb->prepare("SELECT vendor_id FROM `{$table}` WHERE order_id = %d", $order_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $vendor_ids[] = absint($row);
                }
            }
        }

        if (function_exists('dokan') || is_plugin_active('dokan-lite/dokan.php')) {
            $table = $wpdb->prefix . 'dokan_orders';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_col($wpdb->prepare("SELECT seller_id FROM `{$table}` WHERE order_id = %d", $order_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $vendor_ids[] = absint($row);
                }
            }
        }

        foreach ($order->get_items('line_item') as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $product_id = $item->get_product_id();
            if (!$product_id) {
                continue;
            }
            $post = get_post($product_id);
            if ($post && !empty($post->post_author)) {
                $vendor_ids[] = absint($post->post_author);
            }
        }

        $vendor_ids = array_values(array_unique(array_filter(array_map('absint', $vendor_ids))));

        /**
         * Filter the vendor ids that are allowed to manage an order.
         *
         * @param int[]    $vendor_ids
         * @param WC_Order $order
         */
        return apply_filters('mstore_vendor_admin_order_vendor_ids', $vendor_ids, $order);
    }

    public static function can_manage_order($user_id, $order)
    {
        $user_id = absint($user_id);
        if (empty($user_id)) {
            return false;
        }

        if (is_numeric($order)) {
            $order = wc_get_order(absint($order));
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return false;
        }

        if (self::is_store_manager($user_id)) {
            return true;
        }

        if (function_exists('dokan_is_seller_has_order') && dokan_is_seller_has_order($user_id, $order->get_id())) {
            return true;
        }

        return in_array($user_id, self::get_order_vendor_ids($order), true);
    }

    /**
     * Only accounts that actually hold a delivery role may be assigned to an order.
     */
    public static function is_delivery_user($user_id)
    {
        $user_id = absint($user_id);
        if (empty($user_id)) {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->roles)) {
            return false;
        }

        $delivery_roles = apply_filters('mstore_allowed_delivery_roles', array(
            'administrator',
            'shop_manager',
            'driver',
            'delivery_boy',
            'wcfm_delivery_boy',
            'lddfw_driver',
        ));

        return (bool) array_intersect((array) $user->roles, (array) $delivery_roles);
    }

    /**
     * Normalise a requested WooCommerce order status and make sure it is one the
     * store actually knows about.
     *
     * @return string|false The status without the `wc-` prefix, or false.
     */
    public static function normalize_order_status($status)
    {
        $status = sanitize_key((string) $status);
        if ($status === '') {
            return false;
        }

        $status = 'wc-' === substr($status, 0, 3) ? substr($status, 3) : $status;

        if (function_exists('wc_get_order_statuses')) {
            $known = wc_get_order_statuses();
            if (is_array($known) && !array_key_exists('wc-' . $status, $known)) {
                return false;
            }
        }

        return $status;
    }

    public static function forbidden_error($message = '')
    {
        if ($message === '') {
            $message = 'You are not allowed to manage this resource.';
        }

        return new WP_Error('forbidden', $message, array('status' => 403));
    }
}
