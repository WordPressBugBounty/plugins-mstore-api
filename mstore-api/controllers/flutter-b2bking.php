<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once(__DIR__ . '/flutter-base.php');

/*
 * B2BKing REST API Controller for MStore
 *
 * @since 1.4.0
 * @package MStore
 */

class FlutterB2BKing extends FlutterBaseController
{
    protected $namespace = 'api/flutter_b2bking';

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_b2bking_routes'));
        add_action('plugins_loaded', array($this, 'register_b2bking_filters'), 20);
    }

    public function register_b2bking_filters()
    {
        if (!class_exists('B2bking')) {
            return;
        }
        add_filter('woocommerce_rest_prepare_product_object', array($this, 'add_b2b_product_fields'), 32, 3);
        add_filter('woocommerce_rest_prepare_product_variation_object', array($this, 'add_b2b_variation_fields'), 32, 3);
        // woocommerce_rest_product_object_query fires for both /wc/v2/ and /wc/v3/ products.
        add_filter('woocommerce_rest_product_object_query', array($this, 'filter_products_by_b2b_visibility'), 10, 2);
        // woocommerce_rest_product_cat_query fires for both /wc/v2/ and /wc/v3/ product categories.
        add_filter('woocommerce_rest_product_cat_query', array($this, 'filter_categories_by_b2b_visibility'), 10, 2);
    }

    // -------------------------------------------------------------------------
    // User resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the B2B customer user ID from the User-Cookie request header.
     * Returns 0 if the header is absent, invalid, or the cookie does not validate.
     */
    private function resolve_b2b_user_id($request)
    {
        $cookie = get_header_user_cookie($request->get_header('User-Cookie'));
        if (empty($cookie)) {
            return 0;
        }
        $user_id = validateCookieLogin($cookie);
        return is_wp_error($user_id) ? 0 : (int) $user_id;
    }

    // -------------------------------------------------------------------------
    // Visibility filters
    // -------------------------------------------------------------------------

    /**
     * Filter the WooCommerce REST product query to only return products visible
     * to the B2B user identified by the User-Cookie request header.
     *
     * Uses posts_clauses (runs after pre_get_posts) to override any post__in
     * that B2BKing's pre_get_posts priority-9999 hook may have already set.
     */
    public function filter_products_by_b2b_visibility($args, $request)
    {
        $user_id = $this->resolve_b2b_user_id($request);
        if (!$user_id) {
            return $args;
        }

        // If B2BKing is set to show all products to all users, no filtering needed.
        if (intval(get_option('b2bking_all_products_visible_all_users_setting', 1)) === 1) {
            return $args;
        }

        $b2b_user_id = b2bking()->get_top_parent_account($user_id);

        // Remove B2BKing's product-hiding hooks so they don't interfere with our visibility list.
        $this->remove_b2bking_hiding_hooks();

        $lang        = defined('ICL_LANGUAGE_NAME_EN') ? ICL_LANGUAGE_NAME_EN : '';
        $cache_key   = 'b2bking_user_' . $b2b_user_id . '_ajax_visibility' . $lang;
        $visible_ids = get_transient($cache_key);

        if ($visible_ids === false) {
            $visible_ids = $this->compute_visible_product_ids($b2b_user_id);
            set_transient($cache_key, $visible_ids, YEAR_IN_SECONDS);
        }

        $args['post__in'] = $this->intersect_ids($args['post__in'] ?? [], $visible_ids);

        // B2BKing's pre_get_posts (priority 9999) runs AFTER woocommerce_rest_product_object_query
        // and overwrites post__in on the WP_Query object with the wrong user's visibility.
        // Override via posts_clauses which runs after all pre_get_posts hooks complete.
        $visibility_ids = $args['post__in'];
        add_filter('posts_clauses', function ($clauses, $query) use ($visibility_ids) {
            $post_type = $query->get('post_type');
            if ($post_type !== 'product' && !in_array('product', (array) $post_type, true)) {
                return $clauses;
            }
            global $wpdb;
            $ids_sql          = implode(',', array_map('intval', $visibility_ids));
            $table            = preg_quote($wpdb->posts, '/');
            $stripped         = preg_replace('/\s+AND\s+' . $table . '\.ID\s+IN\s*\([^)]+\)/i', '', $clauses['where']);
            $clauses['where'] = $stripped . " AND {$wpdb->posts}.ID IN ($ids_sql)";
            return $clauses;
        }, 1, 2);

        return $args;
    }

    /**
     * Filter the WooCommerce REST product category query to only return categories
     * visible to the B2B user identified by the User-Cookie request header.
     *
     * Only applies when b2bking_completely_category_restrict = 1.
     */
    public function filter_categories_by_b2b_visibility($args, $request)
    {
        if (get_option('b2bking_plugin_status_setting', 'b2b') === 'disabled') {
            return $args;
        }
        // Match B2BKing core: when "all products visible to all users" is on, skip all visibility filtering.
        if (intval(get_option('b2bking_all_products_visible_all_users_setting', 1)) === 1) {
            return $args;
        }
        if (intval(get_option('b2bking_completely_category_restrict', 1)) !== 1) {
            return $args;
        }

        $user_id      = $this->resolve_b2b_user_id($request);
        $b2b_user_id  = $user_id ? b2bking()->get_top_parent_account($user_id) : 0;
        $is_b2b_user  = $user_id && get_user_meta($user_id, 'b2bking_b2buser', true) === 'yes';
        $group_ids    = ['b2c'];
        $user_login   = 'b2c';

        if ($is_b2b_user) {
            $group_id = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($b2b_user_id), $b2b_user_id);
            if (empty($group_id) || $group_id === 'no') {
                $group_id = 'b2c';
            }
            $group_ids = [$group_id];
            $user_obj = get_user_by('id', $b2b_user_id);
            $user_login = $user_obj ? $user_obj->user_login : '';
        } elseif (!$user_id) {
            // Match B2BKing core: guests use term meta key b2bking_group_0.
            $group_ids = ['0'];
            $user_login = '0';
        }

        $lang        = apply_filters('wpml_current_language', '');
        $cache_key   = 'b2bking_mstore_visible_cats_' . md5(implode('|', $group_ids)) . '_' . md5((string) $user_login) . ($lang ? '_' . $lang : '');
        $visible_ids = get_transient($cache_key);

        if ($visible_ids === false) {
            $visible_ids = $this->get_visible_category_ids($group_ids, $user_login);
            set_transient($cache_key, $visible_ids, 12 * HOUR_IN_SECONDS);
        }

        $args['include'] = $this->intersect_ids($args['include'] ?? [], $visible_ids);

        return $args;
    }

    /**
     * Remove B2BKing's product-hiding hooks so they don't override our visibility list.
     * Uses unset() because remove_action() requires the exact same object instance.
     */
    private function remove_b2bking_hiding_hooks()
    {
        if (isset($GLOBALS['wp_filter']['woocommerce_product_query'])) {
            unset($GLOBALS['wp_filter']['woocommerce_product_query']->callbacks[9999]);
            unset($GLOBALS['wp_filter']['woocommerce_product_query']->callbacks[10]);
        }
        if (isset($GLOBALS['wp_filter']['pre_get_posts'])) {
            unset($GLOBALS['wp_filter']['pre_get_posts']->callbacks[9999]);
        }
        if (isset($GLOBALS['wp_filter']['woocommerce_product_is_visible'])) {
            unset($GLOBALS['wp_filter']['woocommerce_product_is_visible']->callbacks[100]);
        }
    }

    /**
     * Intersect a caller-supplied ID list with a visibility list.
     * If $current is empty, returns $visible as-is.
     * Returns [0] when the intersection is empty to force an empty DB result.
     */
    private function intersect_ids(array $current, array $visible)
    {
        if (empty($visible)) {
            return [0];
        }
        if (empty($current)) {
            return $visible;
        }
        $intersected = array_values(array_intersect($current, $visible));
        return $intersected ?: [0];
    }

    // -------------------------------------------------------------------------
    // Visibility SQL helpers
    // -------------------------------------------------------------------------

    /**
     * Return product category term IDs visible to a B2B group via raw SQL.
     * Bypasses B2BKing's get_terms_args hook which relies on get_current_user_id()
     * (always 0 in REST API context).
     */
    private function get_visible_category_ids($group_id, $user_login)
    {
        global $wpdb;
        $taxonomy = apply_filters('b2bking_visibility_taxonomy', 'product_cat');
        $group_ids = is_array($group_id) ? $group_id : [$group_id];
        $group_ids = array_values(array_filter(array_map('strval', $group_ids), function ($v) {
            return $v !== '';
        }));
        if (empty($group_ids)) {
            $group_ids = ['b2c'];
        }

        $meta_keys = array_map(function ($id) {
            return 'b2bking_group_' . $id;
        }, $group_ids);
        $meta_keys[] = 'b2bking_category_users_textarea';

        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, tm.meta_key, tm.meta_value
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
             LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id
                 AND tm.meta_key IN ($placeholders)",
            array_merge([$taxonomy], $meta_keys)
        ));

        $term_visibility = [];
        foreach ($rows as $row) {
            $term_id = (int) $row->term_id;
            if (!isset($term_visibility[$term_id])) {
                $term_visibility[$term_id] = false;
            }

            if (!empty($row->meta_key) && in_array($row->meta_key, $meta_keys, true)) {
                if ($row->meta_key === 'b2bking_category_users_textarea') {
                    if (!$term_visibility[$term_id] && $user_login && !empty($row->meta_value)) {
                        $users = array_filter(array_map('trim', explode(',', $row->meta_value)));
                        $term_visibility[$term_id] = in_array($user_login, $users, true);
                    }
                } elseif (intval($row->meta_value) === 1) {
                    $term_visibility[$term_id] = true;
                }
            }
        }

        $visible_ids = [];
        foreach ($term_visibility as $term_id => $is_visible) {
            if ($is_visible) {
                $visible_ids[] = $term_id;
            }
        }

        return $visible_ids;
    }

    /**
     * Compute product IDs visible to a B2B user via raw SQL.
     * Bypasses B2BKing's get_terms_args and pre_get_posts hooks that rely on
     * get_current_user_id() (always 0 in REST API context).
     *
     * Uses two queries:
     *   A — products in visible categories (without manual override)
     *   B — products with manual group/user override
     */
    private function compute_visible_product_ids($user_id)
    {
        global $wpdb;

        $group_id   = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($user_id), $user_id);
        $user_obj   = get_user_by('id', $user_id);
        $user_login = $user_obj ? $user_obj->user_login : '';

        // B2C users (no group assigned) use the special 'b2c' group key, matching B2BKing's own logic.
        if (empty($group_id) || $group_id === 'no') {
            $group_id = 'b2c';
        }
        $taxonomy   = apply_filters('b2bking_visibility_taxonomy', 'product_cat');

        $visible_categories = $this->get_visible_category_ids($group_id, $user_login);

        // Also collect hidden categories for the hidden-has-priority path.
        $meta_key = 'b2bking_group_' . $group_id;
        $all_cats = $wpdb->get_col($wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s",
            $taxonomy
        ));
        $hidden_categories = array_values(array_diff(array_map('intval', $all_cats), $visible_categories));

        $hidden_priority = intval(get_option('b2bking_hidden_has_priority_setting', 0)) === 1;

        // Query A: category-based visible products (non-manually-overridden).
        if ($hidden_priority) {
            if (empty($hidden_categories)) {
                $ids_a = $wpdb->get_col(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                     WHERE p.post_type = 'product' AND p.post_status = 'publish'
                       AND p.ID NOT IN (
                           SELECT pm.post_id FROM {$wpdb->postmeta} pm
                           WHERE pm.meta_key = 'b2bking_product_visibility_override' AND pm.meta_value = 'manual'
                       )"
                );
            } else {
                $ph    = implode(',', array_fill(0, count($hidden_categories), '%d'));
                $ids_a = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                                                          AND tt.taxonomy = %s
                     WHERE p.post_type = 'product' AND p.post_status = 'publish'
                       AND tt.term_id NOT IN ($ph)
                       AND p.ID NOT IN (
                           SELECT pm.post_id FROM {$wpdb->postmeta} pm
                           WHERE pm.meta_key = 'b2bking_product_visibility_override' AND pm.meta_value = 'manual'
                       )",
                    array_merge([$taxonomy], $hidden_categories)
                ));
            }
        } else {
            if (empty($visible_categories)) {
                $ids_a = [];
            } else {
                $ph    = implode(',', array_fill(0, count($visible_categories), '%d'));
                $ids_a = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                                                          AND tt.taxonomy = %s
                     WHERE p.post_type = 'product' AND p.post_status = 'publish'
                       AND tt.term_id IN ($ph)
                       AND p.ID NOT IN (
                           SELECT pm.post_id FROM {$wpdb->postmeta} pm
                           WHERE pm.meta_key = 'b2bking_product_visibility_override' AND pm.meta_value = 'manual'
                       )",
                    array_merge([$taxonomy], $visible_categories)
                ));
            }
        }

        // Query B: manually-restricted products with explicit group/user access.
        $ids_b = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_override ON pm_override.post_id = p.ID
                 AND pm_override.meta_key = 'b2bking_product_visibility_override'
                 AND pm_override.meta_value = 'manual'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND (
                   EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm1 WHERE pm1.post_id = p.ID
                           AND pm1.meta_key = %s AND pm1.meta_value = '1'
                   ) OR EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE pm2.post_id = p.ID
                           AND pm2.meta_key = %s AND pm2.meta_value = '1'
                   )
               )",
            'b2bking_group_' . $group_id,
            'b2bking_user_' . $user_login
        ));

        return array_values(array_unique(array_merge(
            array_map('intval', $ids_a),
            array_map('intval', $ids_b)
        )));
    }

    // -------------------------------------------------------------------------
    // Routes
    // -------------------------------------------------------------------------

    public function register_flutter_b2bking_routes()
    {
        $perm = function () { return parent::checkApiPermission(); };

        register_rest_route($this->namespace, '/roles', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'get_roles'),
            'permission_callback' => $perm,
        ]]);

        register_rest_route($this->namespace, '/register_fields', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'get_register_fields'),
            'permission_callback' => $perm,
        ]]);

        register_rest_route($this->namespace, '/countries', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'get_countries'),
            'permission_callback' => $perm,
        ]]);

        register_rest_route($this->namespace, '/registration_config', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'get_registration_config'),
            'permission_callback' => $perm,
        ]]);

        register_rest_route($this->namespace, '/register', [[
            'methods'             => 'POST',
            'callback'            => array($this, 'register'),
            'permission_callback' => $perm,
        ]]);

        $id_arg = ['id' => ['description' => __('Unique identifier for the resource.', 'mstore-api'), 'type' => 'integer']];

        register_rest_route($this->namespace, '/product/(?P<id>[\d]+)/tiered_price', [
            'args' => $id_arg,
            ['methods' => 'GET', 'callback' => array($this, 'get_tiered_price'), 'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, '/product/(?P<id>[\d]+)/info_table', [
            'args' => $id_arg,
            ['methods' => 'GET', 'callback' => array($this, 'get_info_table'), 'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, '/products/tiered_prices', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'get_tiered_prices_bulk'),
            'permission_callback' => $perm,
        ]]);

        register_rest_route($this->namespace, '/send_quote', [[
            'methods'             => 'POST',
            'callback'            => array($this, 'send_quote'),
            'permission_callback' => $perm,
        ]]);

        register_rest_route($this->namespace, '/debug_price', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'debug_b2b_price'),
            'permission_callback' => '__return_true',
        ]]);

        register_rest_route($this->namespace, '/debug_visibility', [[
            'methods'             => 'GET',
            'callback'            => array($this, 'debug_b2b_visibility'),
            'permission_callback' => '__return_true',
        ]]);
    }

    // -------------------------------------------------------------------------
    // Endpoint callbacks
    // -------------------------------------------------------------------------

    public function get_roles($request)
    {
        if (!class_exists('B2bking')) {
            return parent::send_invalid_plugin_error("You need to install B2BKing Core plugin to use this api");
        }

        $roles = get_posts([
            'post_type'   => 'b2bking_custom_role',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'meta_query'  => [['key' => 'b2bking_custom_role_status', 'value' => 1]],
        ]);

        return array_map(function ($role) {
            $approval_required = get_post_meta($role->ID, 'b2bking_custom_role_approval', true);
            return [
                'ID'               => $role->ID,
                'name'             => $role->post_title,
                'role'             => 'role_' . $role->ID,
                'approval_required' => $approval_required !== 'automatic',
            ];
        }, $roles);
    }

    public function get_register_fields($request)
    {
        if (!class_exists('B2bking')) {
            return parent::send_invalid_plugin_error("You need to install B2BKing Core plugin to use this api");
        }

        $custom_fields = get_posts([
            'post_type'   => 'b2bking_custom_field',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'meta_query'  => [['key' => 'b2bking_custom_field_status', 'value' => 1]],
        ]);

        return array_map(function ($field) {
            $wpml_id     = apply_filters('wpml_object_id', $field->ID, 'post', true);
            $field_type  = get_post_meta($field->ID, 'b2bking_custom_field_field_type', true);
            $required    = get_post_meta($field->ID, 'b2bking_custom_field_required', true);
            $role        = get_post_meta($field->ID, 'b2bking_custom_field_registration_role', true);
            $role_class  = $role !== 'multipleroles'
                ? esc_attr($role)
                : get_post_meta($field->ID, 'b2bking_custom_field_multiple_roles', true);

            $billing_connection = get_post_meta($field->ID, 'b2bking_custom_field_billing_connection', true) ?: 'none';

            $result = [
                'ID'                 => $field->ID,
                'label'              => get_post_meta($wpml_id, 'b2bking_custom_field_field_label', true),
                'placeholder'        => get_post_meta($wpml_id, 'b2bking_custom_field_field_placeholder', true),
                'type'               => $field_type,
                'required'           => $required === '1',
                'role'               => $role_class,
                'billing_connection' => $billing_connection,
            ];

            if (in_array($field_type, ['select', 'checkbox', 'radio'], true)) {
                $choices = get_post_meta($field->ID, 'b2bking_custom_field_user_choices', true);
                $result['options'] = $choices
                    ? array_values(array_filter(array_map('trim', explode(',', $choices))))
                    : [];
            }

            return $result;
        }, $custom_fields);
    }

    public function get_countries($request)
    {
        $wc_countries = new WC_Countries();
        $countries     = $wc_countries->get_allowed_countries();
        $all_states    = $wc_countries->get_states();

        $result = [];
        foreach ($countries as $code => $name) {
            $states = [];
            if (!empty($all_states[$code])) {
                foreach ($all_states[$code] as $state_code => $state_name) {
                    $states[] = ['code' => $state_code, 'name' => $state_name];
                }
            }
            $result[] = [
                'code'   => $code,
                'name'   => $name,
                'states' => $states,
            ];
        }

        return $result;
    }

    public function get_registration_config($request)
    {
        return [
            'roles'   => $this->get_roles($request),
            'fields'  => $this->get_register_fields($request),
            'countries' => $this->get_countries($request),
        ];
    }

    private function get_field_value($field_id, $custom_fields)
    {
        foreach ($custom_fields as $field) {
            if ($field_id == $field['id']) {
                return $field['value'];
            }
        }
        return null;
    }

    public function is_rest_api_request_custom($is_rest_api_request)
    {
        return false;
    }

    public function register()
    {
        $params        = json_decode(file_get_contents('php://input'), true) ?? [];
        $email         = sanitize_email($params['user_email'] ?? filter_input(INPUT_POST, 'email'));
        $password      = $params['user_pass'] ?? filter_input(INPUT_POST, 'password');
        $register_role = sanitize_text_field($params['b2bking_registration_roles_dropdown'] ?? filter_input(INPUT_POST, 'b2bking_registration_roles_dropdown'));

        if (!class_exists('B2bking')) {
            return parent::send_invalid_plugin_error("You need to install B2BKing Core plugin to use this api");
        }

        $roles = get_posts([
            'post_type'   => 'b2bking_custom_role',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'meta_query'  => [['key' => 'b2bking_custom_role_status', 'value' => 1]],
        ]);

        $valid_role = false;
        foreach ($roles as $role) {
            if ('role_' . $role->ID === $register_role) {
                $valid_role = true;
                break;
            }
        }
        if (!$valid_role) {
            return parent::sendError('required', 'role is incorrect', 400);
        }

        $custom_fields = get_posts([
            'post_type'   => 'b2bking_custom_field',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'meta_query'  => [['key' => 'b2bking_custom_field_status', 'value' => 1]],
        ]);

        foreach ($custom_fields as $field) {
            $required = get_post_meta($field->ID, 'b2bking_custom_field_required', true);
            if ($required !== '1') {
                continue;
            }
            $role          = get_post_meta($field->ID, 'b2bking_custom_field_registration_role', true);
            $check_required = $role === 'allroles'
                || ($role === 'multipleroles' && in_array($register_role, explode(',', get_post_meta($field->ID, 'b2bking_custom_field_multiple_roles', true))))
                || $register_role === $role;

            if ($check_required) {
                $value = sanitize_text_field(filter_input(INPUT_POST, 'b2bking_custom_field_' . $field->ID));
                if (empty($value)) {
                    $field_label = get_post_meta(apply_filters('wpml_object_id', $field->ID, 'post', true), 'b2bking_custom_field_field_label', true);
                    return parent::sendError('required', $field_label . ' is required.', 400);
                }
            }
        }

        add_filter('is_rest_api_request', array($this, 'is_rest_api_request_custom'), 10);
        $user_id = wp_insert_user(['user_email' => $email, 'user_login' => $email, 'user_pass' => $password]);
        remove_filter('is_rest_api_request', array($this, 'is_rest_api_request_custom'), 10);

        if (is_wp_error($user_id)) {
            return parent::sendError($user_id->get_error_code(), $user_id->get_error_message(), 400);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Product / variation field injection
    // -------------------------------------------------------------------------

    /**
     * Inject B2B price overrides and extra fields into the standard WooCommerce
     * product REST response.
     */
    public function add_b2b_product_fields($response, $object, $request)
    {
        return $this->apply_b2b_fields_to_response($response, $object, $request);
    }

    /**
     * Inject B2B price overrides and extra fields into each variation REST response.
     */
    public function add_b2b_variation_fields($response, $object, $request)
    {
        return $this->apply_b2b_fields_to_response($response, $object, $request);
    }

    private function apply_b2b_fields_to_response($response, $object, $request)
    {
        $user_id = $this->resolve_b2b_user_id($request) ?: get_current_user_id();
        if (!$user_id || get_user_meta($user_id, 'b2bking_b2buser', true) !== 'yes') {
            return $response;
        }

        $b2b_user_id = b2bking()->get_top_parent_account($user_id);
        $group_id    = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($b2b_user_id), $b2b_user_id);
        $post_id     = $object->get_id();
        $data        = $response->get_data();

        $b2b_prices            = $this->get_b2b_display_prices($object, $b2b_user_id);
        $data['price']         = $b2b_prices['price'];
        $data['regular_price'] = $b2b_prices['regular_price'];
        $data['sale_price']    = $b2b_prices['sale_price'];

        $data['b2b_tiered_prices'] = $this->get_b2b_tiered_prices_for_product($object, $b2b_user_id);

        $min_qty = get_post_meta($post_id, 'b2bking_minimum_quantity_group_' . $group_id, true);
        if ($min_qty === '') {
            $min_qty = get_post_meta($post_id, 'b2bking_minimum_quantity_group_b2c', true);
        }
        $max_qty = get_post_meta($post_id, 'b2bking_maximum_quantity_group_' . $group_id, true);
        if ($max_qty === '') {
            $max_qty = get_post_meta($post_id, 'b2bking_maximum_quantity_group_b2c', true);
        }
        $data['b2b_min_qty'] = $min_qty !== '' ? (int) $min_qty : null;
        $data['b2b_max_qty'] = $max_qty !== '' ? (int) $max_qty : null;

        $response->set_data($data);
        return $response;
    }

    // -------------------------------------------------------------------------
    // Pricing helpers
    // -------------------------------------------------------------------------

    /**
     * Compute B2B-adjusted display prices for a product/variation.
     * Reads B2BKing group meta directly to avoid changing wp_current_user.
     */
    private function get_b2b_display_prices($product, $user_id)
    {
        $post_id     = $product->get_id();
        $group_id    = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($user_id), $user_id);
        $is_b2b_user = get_user_meta($user_id, 'b2bking_b2buser', true);
        $decimals    = wc_get_price_decimals();

        $reg_raw  = get_post_meta($post_id, '_regular_price', true);
        $sale_raw = get_post_meta($post_id, '_sale_price', true);

        if ($is_b2b_user === 'yes' && $group_id) {
            $b2b_reg  = b2bking()->tofloat(get_post_meta($post_id, 'b2bking_regular_product_price_group_' . $group_id, true));
            $b2b_sale = b2bking()->tofloat(get_post_meta($post_id, 'b2bking_sale_product_price_group_' . $group_id, true));
            if (!empty($b2b_reg))  { $reg_raw  = $b2b_reg; }
            if (!empty($b2b_sale)) { $sale_raw = $b2b_sale; }
        }

        $uses_sale  = apply_filters('b2bking_tiered_table_discount_uses_sale_price', $product->is_on_sale()) && $sale_raw !== '';
        $active_raw = $uses_sale ? $sale_raw : $reg_raw;

        $fmt = function ($raw) use ($product, $decimals) {
            if ($raw === '' || $raw === false) return '';
            return (string) round(
                b2bking()->b2bking_wc_get_price_to_display($product, ['price' => (float) $raw]),
                $decimals
            );
        };

        return [
            'price'         => $fmt($active_raw),
            'regular_price' => $fmt($reg_raw),
            'sale_price'    => $fmt($sale_raw),
        ];
    }

    /**
     * Return tiered pricing array for a product for the given B2B user.
     * Shared between add_b2b_product_fields() and get_tiered_price() endpoint.
     */
    private function get_b2b_tiered_prices_for_product($product, $user_id)
    {
        $post_id     = $product->get_id();
        $group_id    = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($user_id), $user_id);
        $is_b2b_user = get_user_meta($user_id, 'b2bking_b2buser', true);

        if (apply_filters('b2bking_tiered_table_discount_uses_sale_price', $product->is_on_sale())) {
            $original_user_price = get_post_meta($post_id, '_sale_price', true);
            if ($is_b2b_user === 'yes') {
                $b2b_price = b2bking()->tofloat(get_post_meta($post_id, 'b2bking_sale_product_price_group_' . $group_id, true));
                if (!empty($b2b_price)) { $original_user_price = $b2b_price; }
            }
        } else {
            $original_user_price = get_post_meta($post_id, '_regular_price', true);
            if ($is_b2b_user === 'yes') {
                $b2b_price = b2bking()->tofloat(get_post_meta($post_id, 'b2bking_regular_product_price_group_' . $group_id, true));
                if (!empty($b2b_price)) { $original_user_price = $b2b_price; }
            }
        }

        $original_user_price = b2bking()->b2bking_wc_get_price_to_display($product, ['price' => $original_user_price]);

        $price_tiers   = get_post_meta($post_id, 'b2bking_product_pricetiers_group_' . $group_id, true);
        $grpriceexists = 'no';

        if ($group_id) {
            $grregprice  = get_post_meta($post_id, 'b2bking_regular_product_price_group_' . $group_id, true);
            $grsaleprice = get_post_meta($post_id, 'b2bking_sale_product_price_group_' . $group_id, true);
            if (!empty($grregprice)  && b2bking()->tofloat($grregprice)  !== 0) { $grpriceexists = 'yes'; }
            if (!empty($grsaleprice) && b2bking()->tofloat($grsaleprice) !== 0) { $grpriceexists = 'yes'; }
        }

        if (empty($price_tiers) && $grpriceexists === 'no') {
            $price_tiers = get_post_meta($post_id, 'b2bking_product_pricetiers_group_b2c', true);
        }

        $price_tiers = b2bking()->convert_price_tiers($price_tiers, $product);

        if (empty($price_tiers) || strlen($price_tiers) <= 1) {
            return [];
        }

        $helper_array = [];
        foreach (array_filter(explode(';', $price_tiers)) as $pair) {
            $parts = explode(':', $pair);
            $helper_array[$parts[0]] = b2bking()->tofloat($parts[1], 4);
        }
        ksort($helper_array);

        $tiered_prices = [];
        foreach ($helper_array as $index => $value) {
            $discount        = $original_user_price > 0 ? ($original_user_price - $value) / $original_user_price * 100 : 0;
            $tiered_prices[] = ['quantity' => $index, 'discount' => round($discount), 'price' => round($value, 2)];
        }

        return $tiered_prices;
    }

    // -------------------------------------------------------------------------
    // Other endpoint callbacks
    // -------------------------------------------------------------------------

    public function get_tiered_price($request)
    {
        if (!class_exists('B2bking')) {
            return parent::send_invalid_plugin_error('You need to install B2BKing Core plugin to use this api');
        }

        $post_id = $request->get_param('id');
        $product = wc_get_product($post_id);
        if (!$product) {
            return parent::sendError('invalid_product', 'Product not found', 404);
        }

        $user_id = $this->resolve_b2b_user_id($request);
        wp_set_current_user($user_id);
        $user_id = b2bking()->get_top_parent_account($user_id);

        if (null === WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            WC()->session  = new $session_class();
            WC()->session->init();
        }
        if (null === WC()->cart) {
            WC()->cart = new WC_Cart();
        }
        if (null === WC()->customer) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }

        return $this->get_b2b_tiered_prices_for_product($product, $user_id);
    }

    public function get_tiered_prices_bulk($request)
    {
        if (!class_exists('B2bking')) {
            return parent::send_invalid_plugin_error('You need to install B2BKing Core plugin to use this api');
        }

        $include = $request->get_param('include');
        if (is_string($include)) {
            $include = array_filter(array_map('trim', explode(',', $include)), 'strlen');
        }
        if (!is_array($include) || empty($include)) {
            return parent::sendError('invalid_param', 'include is required (comma-separated list or array)', 400);
        }

        $user_id = $this->resolve_b2b_user_id($request);
        wp_set_current_user($user_id);
        $user_id = b2bking()->get_top_parent_account($user_id);

        if (null === WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            WC()->session  = new $session_class();
            WC()->session->init();
        }
        if (null === WC()->cart) {
            WC()->cart = new WC_Cart();
        }
        if (null === WC()->customer) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }

        $data = [];
        foreach ($include as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $product = wc_get_product($pid);
            if (!$product) {
                $data[(string) $pid] = [];
                continue;
            }
            $tiers = $this->get_b2b_tiered_prices_for_product($product, $user_id);
            $data[(string) $pid] = is_array($tiers) ? $tiers : [];
        }

        return ['data' => $data];
    }

    public function get_info_table($request)
    {
        $user_id = $this->resolve_b2b_user_id($request);
        $post_id = $request->get_param('id');

        $is_enabled = get_post_meta($post_id, 'b2bking_show_information_table', true);
        if ($is_enabled === 'no' && !apply_filters('b2bking_show_information_table_all', false)) {
            return [];
        }

        $user_id     = b2bking()->get_top_parent_account($user_id);
        $group_id    = b2bking()->get_user_group($user_id);
        $customrows  = get_post_meta($post_id, 'b2bking_product_customrows_group_' . $group_id, true);

        if (empty($customrows) && apply_filters('b2bking_information_table_apply_regular_all', true)) {
            $customrows = get_post_meta($post_id, 'b2bking_product_customrows_group_b2c', true);
        }

        if (empty($customrows) && !apply_filters('b2bking_show_information_table_all', false)) {
            return [];
        }

        $customrows = str_replace('&amp;', '&', $customrows);
        $rows_array = apply_filters('b2bking_information_table_content_rows', explode(';', $customrows));
        $results    = [];

        foreach ($rows_array as $row) {
            $row_values = explode(':', $row, 2);
            if (!empty($row_values[0]) && !empty($row_values[1])) {
                $results[] = ['label' => $row_values[0], 'text' => $row_values[1]];
            }
        }

        return $results;
    }

    public function send_quote($request)
    {
        if (!class_exists('B2bking')) {
            return parent::send_invalid_plugin_error('You need to install B2BKing Core plugin to use this api');
        }

        $params  = json_decode(file_get_contents('php://input'), true) ?? [];
        $user_id = $this->resolve_b2b_user_id($request);

        if ($user_id) {
            wp_set_current_user($user_id);
        }

        if (null === WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            WC()->session  = new $session_class();
            WC()->session->init();
        }
        if (null === WC()->customer) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }
        if (null === WC()->cart) {
            WC()->cart = new WC_Cart();
        }
        WC()->cart->empty_cart(true);

        $line_items = $params['line_items'] ?? [];
        if (!empty($line_items)) {
            add_filter('woocommerce_is_purchasable', '__return_true', 9999);
            buildCartItemData($line_items, function ($productId, $quantity, $variationId, $attributes, $cart_item_data) {
                WC()->cart->add_to_cart($productId, $quantity, $variationId, $attributes, $cart_item_data);
            });
            remove_filter('woocommerce_is_purchasable', '__return_true', 9999);
            WC()->cart->calculate_totals();
        }

        do_action('b2bkingrequestquotecart');

        return ['success' => true];
    }

    /**
     * Debug endpoint: returns B2BKing visibility state for the current User-Cookie.
     * Route: GET /api/flutter_b2bking/debug_visibility
     * Add ?clear_cache=1 to flush cached visibility data for this user.
     */
    public function debug_b2b_price($request)
    {
        $user_id    = $this->resolve_b2b_user_id($request);
        $product_id = (int) $request->get_param('product_id');

        if (!$user_id || !$product_id) {
            return new WP_REST_Response(['error' => 'Missing User-Cookie or product_id param'], 400);
        }

        $b2b_user_id = b2bking()->get_top_parent_account($user_id);
        $group_id    = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($b2b_user_id), $b2b_user_id);
        $is_b2b      = get_user_meta($b2b_user_id, 'b2bking_b2buser', true);
        $product     = wc_get_product($product_id);
        $parent_id   = $product ? ($product->get_parent_id() ?: $product->get_id()) : 0;

        // Dump all b2bking price meta on both product and parent
        global $wpdb;
        $ids = array_unique(array_filter([$product_id, $parent_id]));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ($placeholders) AND meta_key LIKE %s",
                array_merge($ids, ['b2bking_%price%'])
            )
        );

        $meta = [];
        foreach ($rows as $row) {
            $meta[$row->post_id][$row->meta_key] = $row->meta_value;
        }

        return new WP_REST_Response([
            'user_id'        => $user_id,
            'b2b_user_id'    => $b2b_user_id,
            'is_b2b'         => $is_b2b,
            'group_id'       => $group_id,
            'product_id'     => $product_id,
            'parent_id'      => $parent_id,
            'expected_key'   => 'b2bking_regular_product_price_group_' . $group_id,
            'b2b_price_meta' => $meta,
            'default_price'  => $product ? $product->get_price() : null,
        ], 200);
    }

    public function debug_b2b_visibility($request)
    {
        if (!class_exists('B2bking')) {
            return new WP_REST_Response(['error' => 'B2BKing plugin not active'], 200);
        }

        $settings = [
            'plugin_status'                => get_option('b2bking_plugin_status_setting', 'b2b'),
            'all_products_visible'         => get_option('b2bking_all_products_visible_all_users_setting', '1'),
            'completely_category_restrict' => get_option('b2bking_completely_category_restrict', '1'),
            'disable_visibility'           => get_option('b2bking_disable_visibility_setting', '0'),
            'hidden_has_priority'          => get_option('b2bking_hidden_has_priority_setting', '0'),
        ];

        $user_id = $this->resolve_b2b_user_id($request);
        if (!$user_id) {
            return new WP_REST_Response([
                'user_id'  => 0,
                'note'     => 'No valid User-Cookie header found.',
                'settings' => $settings,
            ], 200);
        }

        $is_b2b_user = get_user_meta($user_id, 'b2bking_b2buser', true);
        $b2b_user_id = b2bking()->get_top_parent_account($user_id);
        $user_obj    = get_user_by('id', $b2b_user_id);
        $user_login  = $user_obj ? $user_obj->user_login : '';
        $group_id    = apply_filters('b2bking_b2b_group_for_pricing', b2bking()->get_user_group($b2b_user_id), $b2b_user_id);
        $lang        = apply_filters('wpml_current_language', '');

        $lang       = defined('ICL_LANGUAGE_NAME_EN') ? ICL_LANGUAGE_NAME_EN : '';
        $cache_key  = 'b2bking_user_' . $b2b_user_id . '_ajax_visibility' . $lang;

        if ($request->get_param('clear_cache')) {
            delete_transient($cache_key);
        }

        $cached      = get_transient($cache_key);
        $live_ids    = $this->compute_visible_product_ids($b2b_user_id);
        $product_id  = (int) $request->get_param('product_id');

        $product_meta = [];
        if ($product_id) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d
                   AND meta_key IN ('b2bking_product_visibility_override','b2bking_category_users_textarea','b2bking_user_%s')",
                $product_id,
                $user_login
            ));
            foreach ($rows as $row) {
                $product_meta[$row->meta_key] = $row->meta_value;
            }
            // Also check the exact individual user meta key
            $product_meta['b2bking_user_' . $user_login] = get_post_meta($product_id, 'b2bking_user_' . $user_login, true);
        }

        // Simulate what filter_products_by_b2b_visibility does
        $simulated_args = $product_id ? ['post__in' => [$product_id]] : [];
        $all_products_visible = intval(get_option('b2bking_all_products_visible_all_users_setting', 1)) === 1;
        $intersect_result = (!$all_products_visible && !empty($live_ids))
            ? $this->intersect_ids($simulated_args['post__in'] ?? [], $live_ids)
            : 'skipped (all_products_visible)';

        // Check registered hooks
        $hooks_registered = [
            'woocommerce_rest_product_object_query' => has_filter('woocommerce_rest_product_object_query', [$this, 'filter_products_by_b2b_visibility']),
            'woocommerce_product_query_10'          => isset($GLOBALS['wp_filter']['woocommerce_product_query']->callbacks[10]),
            'woocommerce_product_query_9999'        => isset($GLOBALS['wp_filter']['woocommerce_product_query']->callbacks[9999]),
            'pre_get_posts_9999'                    => isset($GLOBALS['wp_filter']['pre_get_posts']->callbacks[9999]),
            'woocommerce_product_is_visible_100'    => isset($GLOBALS['wp_filter']['woocommerce_product_is_visible']->callbacks[100]),
        ];

        return new WP_REST_Response([
            'user_id'              => $user_id,
            'b2b_user_id'          => $b2b_user_id,
            'is_b2b_user'          => $is_b2b_user,
            'group_id'             => $group_id,
            'user_login'           => $user_login,
            'settings'             => $settings,
            'cache_key'            => $cache_key,
            'cache_hit'            => $cached !== false,
            'cached_count'         => is_array($cached) ? count($cached) : null,
            'cached_has_product'   => $product_id && is_array($cached) ? in_array($product_id, $cached) : null,
            'live_count'           => count($live_ids),
            'live_has_product'     => $product_id ? in_array($product_id, $live_ids) : null,
            'live_category_ids'    => $this->get_visible_category_ids($group_id, $user_login),
            'product_id_checked'   => $product_id ?: null,
            'product_meta'         => $product_meta ?: null,
            'simulated_intersect'  => $intersect_result,
            'hooks'                => $hooks_registered,
        ], 200);
    }
}

new FlutterB2BKing;
