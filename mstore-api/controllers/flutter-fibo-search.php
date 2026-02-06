<?php
require_once(__DIR__ . '/flutter-base.php');

// Include FiboSearch WooCommerce REST API Integration
require_once(__DIR__ . '/helpers/fibosearch-woo-rest-integration.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Flutterwave
 */

class FlutterFiboSearch extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_fibo_search';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_flutterfibo_routes'));
    }

    public function register_flutter_flutterfibo_routes()
    {
        register_rest_route($this->namespace, '/settings', array(
            array(
                'methods' => "GET",
                'callback' => array($this, 'settings'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/search', array(
            array(
                'methods' => "GET",
                'callback' => array($this, 'search'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                },
                'args' => array(
                    's' => array(
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Search query'
                    )
                )
            ),
        ));
    }

    public function settings($request)
    {
        if (!mstore_is_fibosearch_active()) {
            return parent::send_invalid_plugin_error("You need to install FiboSearch – Ajax Search for WooCommerce plugin (Free or Premium) to use this api");
        }

        try {
            $settings = mstore_get_fibosearch_settings();
            if (!$settings) {
                return parent::sendError("fibosearch_error", "Unable to load FiboSearch settings", 500);
            }

            return array(
                'settings' => array(
                    'min_chars' => (int)$settings->getOption('min_chars', 3),
                    'search_placeholder' => $settings->getOption('search_placeholder', 'Search for products...'),
                    'limit_chars' => (int)$settings->getOption('suggestions_limit', 10),
                    'search_no_results_text' => $settings->getOption('search_no_results_text', 'No results'),
                    'show_product_image' => $settings->getOption('show_product_image') === 'on',
                    'show_product_price' => $settings->getOption(option_key: 'show_product_price') === 'on',
                    'show_product_desc' => $settings->getOption('show_product_desc') === 'on',
                    'show_product_sku' => $settings->getOption('show_product_sku') === 'on',
                    'search_see_all_results_text' => $settings->getOption('search_see_all_results_text', 'See all products...'),
                    'show_matching_categories' => $settings->getOption('show_product_tax_product_cat') === 'on',
                    'show_matching_tags' => $settings->getOption('show_product_tax_product_tag') === 'on',
                    'show_categories_images' => $settings->getOption('show_product_tax_product_cat_images') === 'on',
                    'search_in_product_description' => $settings->getOption('search_in_product_content') === 'on',
                    'search_in_product_short_description' => $settings->getOption('search_in_product_excerpt') === 'on',
                    'search_in_product_sku' => $settings->getOption('search_in_product_sku') === 'on',
                    'exclude_out_of_stock_products' => $settings->getOption(option_key: 'exclude_out_of_stock') === 'on',
                ),
            );
        } catch (Exception $e) {
            return parent::sendError("invalid_settings", $e->getMessage(), 400);
        }
    }

    public function search($request)
    {
        if (!mstore_is_fibosearch_active()) {
            return parent::send_invalid_plugin_error("You need to install FiboSearch – Ajax Search for WooCommerce plugin (Free or Premium) to use this api");
        }

        try {
            $page = isset($request['page']) ? absint($request['page']) : 1;
            $per_page = isset($request['per_page']) ? absint($request['per_page']) : 20;
            $per_page = min($per_page, 100);

            $settings = mstore_get_fibosearch_settings();
            if (!$settings) {
                return parent::sendError("fibosearch_error", "Unable to load FiboSearch settings", 500);
            }

            $search_phrase = $request->get_param('s');

            // Get settings
            $suggestions_limit = (int)$settings->getOption('suggestions_limit', 10);
            $min_chars = (int)$settings->getOption('min_chars', 3);
            $show_image = $settings->getOption('show_product_image') === 'on';
            $show_price = $settings->getOption('show_product_price') === 'on';
            $show_desc = $settings->getOption('show_product_desc') === 'on';
            $show_sku = $settings->getOption('show_product_sku') === 'on';
            $search_in_content = $settings->getOption('search_in_product_content') === 'on';
            $search_in_excerpt = $settings->getOption('search_in_product_excerpt') === 'on';
            $search_in_sku = $settings->getOption('search_in_product_sku') === 'on';
            $exclude_out_of_stock = $settings->getOption('exclude_out_of_stock') === 'on';
            $show_matching_categories = $settings->getOption('show_product_tax_product_cat') === 'on';
            $show_matching_tags = $settings->getOption('show_product_tax_product_tag') === 'on';

            // Validate min chars
            if (strlen($search_phrase) < $min_chars) {
                return array(
                    'message' => sprintf('Minimum %d characters required', $min_chars),
                    'categories' => array(),
                    'tags' => array(),
                    'products' => array(),
                    'total_products' => 0
                );
            }

            // Get instance of FiboSearch
            $dgwt_wcas = DGWT_WCAS();

            // Use FiboSearch native search to get ALL matching product IDs
            $searchResults = $dgwt_wcas->nativeSearch->getSearchResults($search_phrase, true, 'product-ids');

            // Get product IDs from search results
            $all_product_ids = array();
            if (isset($searchResults['suggestions']) && is_array($searchResults['suggestions'])) {
                $all_product_ids = wp_list_pluck($searchResults['suggestions'], 'ID');
            }

            // Remove dummy product ID = 0 if no results
            if (isset($all_product_ids[0]) && $all_product_ids[0] === 0) {
                $all_product_ids = array();
            }

            // Store total count before pagination
            $total_products = count($all_product_ids);

            // Apply pagination (live search uses suggestions_limit)
            $product_ids = array_slice($all_product_ids, 0, $suggestions_limit);

            // Format products data
            $products = array();

            // Process search results
            foreach ($product_ids as $product_id) {
                $wc_product = wc_get_product($product_id);
                if (!$wc_product) {
                    continue;
                }

                $product_data = array(
                    'id' => $product_id,
                    'name' => $wc_product->get_name(),
                    'type' => $wc_product->get_type(),
                    'status' => $wc_product->get_status(),
                    'featured' => $wc_product->is_featured(),
                    'catalog_visibility' => $wc_product->get_catalog_visibility(),
                    'on_sale' => $wc_product->is_on_sale(),
                    'manage_stock' => $wc_product->get_manage_stock(),
                    'stock_quantity' => $wc_product->get_stock_quantity(),
                    'stock_status' => $wc_product->get_stock_status(),
                    'backorders' => $wc_product->get_backorders(),
                    'backorders_allowed' => $wc_product->backorders_allowed(),
                    'backordered' => $wc_product->is_on_backorder(),
                    'purchaseable' => $wc_product->is_purchasable(),
                    'virtual' => $wc_product->is_virtual(),
                    'downloadable' => $wc_product->is_downloadable(),
                    'tax_status' => $wc_product->get_tax_status(),
                    'tax_class' => $wc_product->get_tax_class(),
                    'shipping_required' => !$wc_product->is_virtual(),
                    'shipping_taxable' => $wc_product->is_shipping_taxable(),
                    'weight' => $wc_product->get_weight(),
                    'dimensions' => array(
                        'length' => $wc_product->get_length(),
                        'width' => $wc_product->get_width(),
                        'height' => $wc_product->get_height()
                    )
                );

                if ($show_image) {
                    $images = array();
                    // Get featured image
                    $featured_image_id = $wc_product->get_image_id();
                    if ($featured_image_id) {
                        $images[] =  wp_get_attachment_image_url($featured_image_id);
                    }

                    // Get gallery images
                    $gallery_image_ids = $wc_product->get_gallery_image_ids();
                    foreach ($gallery_image_ids as $gallery_image_id) {
                        $images[] = wp_get_attachment_image_url($gallery_image_id);
                    }
                    $product_data['images'] = $images;
                }

                if ($show_price) {
                    if ($wc_product->is_type('variable')) {
                        $variation_prices = $wc_product->get_variation_prices();
                        $min_price = current($variation_prices['price']) ?: '';
                        $max_price = end($variation_prices['price']) ?: '';
                        $min_regular = current($variation_prices['regular_price']) ?: '';
                        $max_regular = end($variation_prices['regular_price']) ?: '';
                        $min_sale = current($variation_prices['sale_price']) ?: '';
                        $max_sale = end($variation_prices['sale_price']) ?: '';
                    } else {
                        $price = $wc_product->get_price() ?: '';
                        $min_price = $max_price = $price;
                        $min_regular = $max_regular = $wc_product->get_regular_price() ?: '';
                        $min_sale = $max_sale = $wc_product->get_sale_price() ?: '';
                    }
                    $product_data['price'] = $wc_product->get_price() ?: '';
                    $product_data['regular_price'] = $wc_product->get_regular_price() ?: '';
                    $product_data['sale_price'] = $wc_product->get_sale_price() ?: '';
                    $product_data['min_price'] = $min_price;
                    $product_data['max_price'] = $max_price;
                    $product_data['min_regular_price'] = $min_regular;
                    $product_data['max_regular_price'] = $max_regular;
                    $product_data['min_sale_price'] = $min_sale;
                    $product_data['max_sale_price'] = $max_sale;
                    $product_data['price_formatted'] = wc_price($product_data['price']);
                    $product_data['regular_price_formatted'] = wc_price($product_data['regular_price']);
                    $product_data['sale_price_formatted'] = $product_data['sale_price'] ? wc_price($product_data['sale_price']) : '';
                    $product_data['price_range'] = $wc_product->get_price_html();
                }

                if ($show_desc) {
                    $short_description = $wc_product->get_short_description();
                    // Convert HTML to plain text
                    $short_description = wp_strip_all_tags($short_description);
                    $short_description = html_entity_decode($short_description);
                    $short_description = preg_replace('/\s+/', ' ', $short_description);
                    $short_description = trim($short_description);
                    $product_data['short_description'] = $short_description;
                    $description = $wc_product->get_description();
                    // Convert HTML to plain text
                    $description = wp_strip_all_tags($description); // Remove HTML tags
                    $description = html_entity_decode($description); // Convert HTML entities to characters
                    $description = preg_replace('/\s+/', ' ', $description); // Replace multiple spaces with single space
                    $description = trim($description); // Remove leading/trailing spaces
                    $product_data['description'] = $description;
                }

                if ($show_sku) {
                    $product_data['sku'] = $wc_product->get_sku();
                }

                // Get attributes
                $attributes = array();
                foreach ($wc_product->get_attributes() as $attribute) {
                    $attribute_data = array(
                        'id' => $attribute->get_id(),
                        'name' => $attribute->get_name(),
                        'position' => $attribute->get_position(),
                        'visible' => $attribute->get_visible(),
                        'variation' => $attribute->get_variation(),
                        'options' => $attribute->get_options()
                    );
                    $attributes[] = $attribute_data;
                }
                $product_data['attributes'] = $attributes;

                // Get default attributes
                if ($wc_product->is_type('variable')) {
                    $default_attributes = array();
                    foreach ($wc_product->get_default_attributes() as $default_attribute_name => $default_attribute_value) {
                        $default_attributes[] = array(
                            'id' => wc_attribute_taxonomy_id_by_name($default_attribute_name),
                            'name' => $default_attribute_name,
                            'option' => $default_attribute_value
                        );
                    }
                    $product_data['default_attributes'] = $default_attributes;

                    // Get variations
                    $variations = array();
                    foreach ($wc_product->get_children() as $child_id) {
                        $variation = wc_get_product($child_id);
                        if (!$variation || !$variation->exists()) {
                            continue;
                        }
                        $variations[] = array(
                            'id' => $variation->get_id(),
                            'attributes' => $variation->get_variation_attributes(),
                            'price' => $variation->get_price(),
                            'regular_price' => $variation->get_regular_price(),
                            'sale_price' => $variation->get_sale_price(),
                            'stock_quantity' => $variation->get_stock_quantity(),
                            'in_stock' => $variation->is_in_stock()
                        );
                    }
                    $product_data['variations'] = $variations;
                }

                $products[] = $product_data;
            }
            wp_reset_postdata();

            // Get matching categories
            $categories = array();
            if ($show_matching_categories) {
                $matching_terms = get_terms(array(
                    'taxonomy' => 'product_cat',
                    'name__like' => $search_phrase,
                    'hide_empty' => true
                ));

                foreach ($matching_terms as $term) {
                    $categories[] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'count' => $term->count
                    );
                }
            }

            // Get matching tags with pagination
            $tags = array();
            if ($show_matching_tags) {
                $matching_terms = get_terms(array(
                    'taxonomy' => 'product_tag',
                    'name__like' => $search_phrase,
                    'hide_empty' => true,
                    'number' => $per_page,
                    'offset' => ($page - 1) * $per_page,
                    'orderby' => 'count',
                    'order' => 'DESC'
                ));

                foreach ($matching_terms as $term) {
                    $tags[] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'count' => $term->count
                    );
                }
            }

            return array(
                'categories' => $categories,
                'tags' => $tags,
                'products' => $products,
                'total_products' => $total_products,
            );

        } catch (Exception $e) {
            return parent::sendError("search_error", $e->getMessage(), 400);
        }
    }
}

new FlutterFiboSearch;