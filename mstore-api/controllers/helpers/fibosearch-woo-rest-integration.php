<?php
/**
 * FiboSearch Integration for WooCommerce REST API
 *
 * Integrates FiboSearch relevance ordering into WooCommerce standard REST API endpoints.
 * This allows /wc/v3/products?orderby=relevance to work with FiboSearch plugin.
 */

/**
 * Detect FiboSearch plugin state
 */
function mstore_detect_fibosearch() {
    static $state = null;
    
    if ($state === null) {
        $is_premium = is_plugin_active('ajax-search-for-woocommerce-premium/ajax-search-for-woocommerce.php');
        $is_free = is_plugin_active('ajax-search-for-woocommerce/ajax-search-for-woocommerce.php');
        
        if ($is_premium) {
            $state = [
                'active' => function_exists('DGWT_WCAS'),
                'dir' => WP_PLUGIN_DIR . '/ajax-search-for-woocommerce-premium',
                'type' => 'premium'
            ];
        } elseif ($is_free) {
            $state = [
                'active' => function_exists('DGWT_WCAS'),
                'dir' => WP_PLUGIN_DIR . '/ajax-search-for-woocommerce',
                'type' => 'free'
            ];
        } else {
            $state = [
                'active' => false,
                'dir' => null,
                'type' => null
            ];
        }
    }
    
    return $state;
}

/**
 * Check if FiboSearch plugin is active (Free or Premium)
 */
function mstore_is_fibosearch_active() {
    return mstore_detect_fibosearch()['active'];
}

/**
 * Get FiboSearch plugin directory
 */
function mstore_get_fibosearch_dir() {
    return mstore_detect_fibosearch()['dir'];
}

/**
 * Get FiboSearch Settings instance
 */
function mstore_get_fibosearch_settings() {
    static $settings = false;
    
    if ($settings === false) {
        if (!mstore_is_fibosearch_active()) {
            $settings = null;
            return null;
        }
        
        if (!class_exists('\DgoraWcas\Settings')) {
            $dir = mstore_get_fibosearch_dir();
            $file = $dir . '/includes/Settings.php';
            if ($dir && file_exists($file)) {
                require_once $file;
            }
        }
        
        $settings = class_exists('\DgoraWcas\Settings') ? new \DgoraWcas\Settings() : null;
    }
    
    return $settings;
}

/**
 * Step 1: Intercept request BEFORE validation
 *
 * WooCommerce REST API doesn't support 'relevance' as orderby value by default.
 * We intercept it before validation and temporarily change it to a valid value.
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    $route = $request->get_route();

    if (preg_match('#^/wc/v[0-9]+/products#', $route) && $request->get_param('orderby') === 'relevance') {
        $GLOBALS['_mstore_fibo_original_orderby'] = 'relevance';

        // Bypass WooCommerce validation
        $_GET['orderby'] = 'date';
        $_REQUEST['orderby'] = 'date';
        $request->set_param('orderby', 'date');
    }

    return $result;
}, 1, 3);

/**
 * Step 2: Apply FiboSearch ordering
 *
 * Get ALL matching product IDs from FiboSearch (in relevance order),
 * then let WooCommerce apply its filters on this set.
 * This ensures 100% consistency with web while maintaining filter support.
 */
add_filter('woocommerce_rest_product_object_query', function($args, $request) {
    if (!isset($GLOBALS['_mstore_fibo_original_orderby']) || $GLOBALS['_mstore_fibo_original_orderby'] !== 'relevance') {
        return $args;
    }

    if (!mstore_is_fibosearch_active()) {
        unset($GLOBALS['_mstore_fibo_original_orderby']);
        return $args;
    }

    // Only use 'search' parameter for FiboSearch (not 'sku')
    // FiboSearch already handles SKU search internally
    $search = $request->get_param('search');

    if (empty($search)) {
        unset($GLOBALS['_mstore_fibo_original_orderby']);
        return $args;
    }

    try {
        $dgwt_wcas = DGWT_WCAS();

        if (!$dgwt_wcas || !isset($dgwt_wcas->nativeSearch)) {
            throw new Exception('FiboSearch instance not available');
        }

        // Get ALL matching products from FiboSearch index
        add_filter('dgwt/wcas/suggestions/limit', fn() => 9999, 999);
        $searchResults = $dgwt_wcas->nativeSearch->getSearchResults($search, true, 'product-ids');
        remove_all_filters('dgwt/wcas/suggestions/limit', 999);

        // Extract product IDs from FiboSearch results
        $product_ids = array();
        if (!empty($searchResults['suggestions']) && is_array($searchResults['suggestions'])) {
            foreach ($searchResults['suggestions'] as $suggestion) {
                if (is_object($suggestion)) {
                    $id = $suggestion->ID ?? $suggestion->post_id ?? $suggestion->id ?? 0;
                } elseif (is_array($suggestion)) {
                    $id = $suggestion['post_id'] ?? $suggestion['ID'] ?? $suggestion['id'] ?? $suggestion['value'] ?? 0;
                } elseif (is_numeric($suggestion)) {
                    $id = intval($suggestion);
                } else {
                    continue;
                }

                if ($id > 0) {
                    $product_ids[] = $id;
                }
            }
        }

        $product_ids = array_unique(array_filter($product_ids));

        // Apply FiboSearch ordering
        $args['post__in'] = !empty($product_ids) ? $product_ids : array(0);
        $args['orderby'] = 'post__in';

        // Remove SKU filter (already handled by FiboSearch)
        if (isset($args['meta_query'])) {
            foreach ($args['meta_query'] as $key => $meta) {
                if (isset($meta['key']) && $meta['key'] === '_sku') {
                    unset($args['meta_query'][$key]);
                }
            }
        }

    } catch (Exception $e) {
        error_log('[MStore FiboSearch] Error: ' . $e->getMessage());
    }

    unset($GLOBALS['_mstore_fibo_original_orderby']);
    return $args;
}, 10, 2);
