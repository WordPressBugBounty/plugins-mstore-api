<?php
/**
 * Pure Taxonomies Helper
 *
 * This helper adds full taxonomy data to REST API responses.
 * Migrated from WP REST API – Pure Taxonomies plugin.
 *
 * @package MStore API
 * @since 4.18.3
 */

defined('ABSPATH') or die('No script kiddies please!');

class MStore_Pure_Taxonomies_Helper {

    // Theme names
    protected $_listingPro = 'listingpro';
    protected $_myListing = 'my listing';
    protected $_listeo = 'listeo';

    // Theme detection flags
    protected $_isListeo = false;
    protected $_isMyListing = false;
    protected $_isListingPro = false;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'detect_theme'));
        add_action('rest_api_init', array($this, 'register_pure_taxonomies_field'));
    }

    /**
     * Detect active theme and set related properties
     */
    public function detect_theme() {
        $theme = wp_get_theme(get_template());
        $template = strtolower($theme->get('Name'));

        if (strpos($template, $this->_listeo) !== false) {
            $this->_isListeo = true;
        } elseif (strpos($template, $this->_myListing) !== false) {
            $this->_isMyListing = true;
        } elseif (strpos($template, $this->_listingPro) !== false) {
            $this->_isListingPro = true;
        }
    }

    /**
     * Register the pure_taxonomies field for all public post types
     */
    public function register_pure_taxonomies_field() {
        // Skip registration if old plugin is active to avoid conflict
        if (class_exists('custom_taxonomies_posts')) {
            return;
        }

        $post_types = get_post_types(array('public' => true), 'objects');

        foreach ($post_types as $post_type) {
            register_rest_field(
                $post_type->name,
                'pure_taxonomies',
                array(
                    'get_callback' => array($this, 'get_all_taxonomies'),
                    'schema' => null,
                )
            );
        }
    }

    /**
     * Convert icon class to Iconify SVG URL
     * Supports: Font Awesome, Material Icons (MyListing), custom icon fonts
     *
     * @param string $icon_class Icon class like "fas fa-car", "mi bookmark_border", "icon-pin-2"
     * @return string SVG URL or empty string if cannot convert
     */
    private function convert_icon_class_to_url($icon_class) {
        if (empty($icon_class) || !is_string($icon_class)) {
            return '';
        }

        $icon_name = trim($icon_class);

        // Already a URL, return as-is
        if (strpos($icon_name, 'http') === 0) {
            return $icon_name;
        }

        // MyListing Material Icons: "mi bookmark_border" -> "ic:bookmark-border"
        // Iconify uses 'ic' collection for Material Icons
        if (strpos($icon_name, 'mi ') === 0) {
            $name = trim(str_replace('mi ', '', $icon_name));
            // Convert underscores to dashes for Iconify compatibility
            $name = str_replace('_', '-', $name);
            if (!empty($name) && preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
                return "https://api.iconify.design/ic:{$name}.svg";
            }
        }

        // MyListing custom icon font: "icon-location-pin-4" -> Not supported by Iconify
        // These are theme-specific icon fonts loaded via CSS, cannot convert to URL
        // User must switch to icon_type='image' or use Material Icons/Font Awesome instead
        if (strpos($icon_name, 'icon-') === 0) {
            // Return empty - custom theme icons require image upload or different icon choice
            return '';
        }

        // Font Awesome and other standard icon libraries
        // Order matters - check specific patterns first
        // Support both short (fas fa-) and long (fa-solid fa-) formats
        $icon_map = array(
            // Long format (ListingPro style)
            'fa-brands fa-' => 'fa-brands:',
            'fa-solid fa-' => 'fa-solid:',
            'fa-regular fa-' => 'fa-regular:',
            'fa-light fa-' => 'fa-light:',
            // Short format (standard)
            'fab fa-' => 'fa-brands:',
            'fas fa-' => 'fa-solid:',
            'far fa-' => 'fa-regular:',
            'fal fa-' => 'fa-light:',
            'fa fa-'  => 'fa-solid:',
            // Other icon libraries
            'im im-icon-' => 'im:',
            'material-icons ' => 'material-symbols:',
        );

        foreach ($icon_map as $prefix => $collection) {
            if (strpos($icon_name, $prefix) === 0) {
                $name = trim(str_replace($prefix, '', $icon_name));

                // Validate: only alphanumeric, dash, underscore
                if (empty($name) || preg_match('/[^a-zA-Z0-9-_]/', $name)) {
                    return '';
                }

                return "https://api.iconify.design/{$collection}{$name}.svg";
            }
        }

        return '';
    }

    /**
     * Get image value from term meta by trying multiple keys
     *
     * @param int $term_id Term ID
     * @param array $meta_keys Array of meta keys to try
     * @param bool $convert_icon Whether to convert icon class to URL
     * @return string Image URL or empty string
     */
    private function get_image_from_meta($term_id, $meta_keys, $convert_icon = false) {
        foreach ($meta_keys as $key) {
            $value = get_term_meta($term_id, $key, true);

            if (empty($value)) {
                continue;
            }

            // Attachment ID - get URL
            if (is_numeric($value)) {
                $url = wp_get_attachment_url($value);
                if ($url) {
                    return $url;
                }
                continue;
            }

            // String value - convert if needed
            if ($convert_icon) {
                $url = $this->convert_icon_class_to_url($value);
                if (!empty($url)) {
                    return $url;
                }
            } else {
                return $value;
            }
        }

        return '';
    }

    /**
     * Process ListingPro category meta value
     * Handles Font Awesome classes, Base64 images, and direct URLs
     *
     * @param string $value Meta value to process
     * @return string Processed image URL or empty string
     */
    private function process_listingpro_meta($value) {
        if (empty($value)) {
            return '';
        }

        // Check if it's Font Awesome class (e.g., "fa-solid fa-car")
        if (preg_match('/\b(fa(-[a-z]+)?)\b/', $value)) {
            return $this->convert_icon_class_to_url($value);
        }

        // Base64 or direct URL
        return $value;
    }

    /**
     * Extract image URL from MyListing field value
     * Handles ACF array formats and attachment IDs
     *
     * @param mixed $icon_image Field value (array, ID, or string)
     * @return string|false Image URL or false if not found
     */
    private function extract_mylisting_image_url($icon_image) {
        if (empty($icon_image)) {
            return false;
        }

        // ACF array with direct URL
        if (is_array($icon_image) && isset($icon_image['url'])) {
            return $icon_image['url'];
        }

        // ACF array with sizes
        if (is_array($icon_image) && isset($icon_image['sizes']['large'])) {
            return $icon_image['sizes']['large'];
        }

        // ACF array with ID
        if (is_array($icon_image) && isset($icon_image['id'])) {
            return wp_get_attachment_url($icon_image['id']) ?: false;
        }

        // Direct attachment ID
        if (is_numeric($icon_image)) {
            return wp_get_attachment_url($icon_image) ?: false;
        }

        return false;
    }

    /**
     * Get MyListing field value (term_meta or ACF fallback)
     *
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name
     * @param string $field_name Field name
     * @return mixed Field value or false
     */
    private function get_mylisting_field($term_id, $taxonomy, $field_name) {
        // First try: get_term_meta (MyListing default)
        $value = get_term_meta($term_id, $field_name, true);
        if (!empty($value)) {
            return $value;
        }

        // Second try: ACF fallback for some installations
        if (function_exists('get_field')) {
            try {
                // ACF stores term fields with format: {taxonomy}_{term_id}
                return get_field($field_name, $taxonomy . '_' . $term_id);
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get default meta keys for theme-specific taxonomies
     * Used as fallback when wp-rest-api-controller settings are not available
     *
     * @param string $taxonomy Taxonomy name
     * @return array Default meta keys for the taxonomy
     */
    private function get_default_taxonomy_meta_keys($taxonomy) {
        $defaults = array();

        // Listeo theme taxonomies
        if ($this->_isListeo) {
            $listeo_taxonomies = array(
                'listing_category', 'event_category', 'service_category',
                'rental_category', 'classifieds_category', 'listing_feature', 'region'
            );

            if (in_array($taxonomy, $listeo_taxonomies)) {
                $defaults = array('icon', '_cover', '_icon_svg');
            }
        }

        // MyListing theme taxonomies
        if ($this->_isMyListing) {
            $mylisting_taxonomies = array('job_listing_category', 'case27_job_listing_tags', 'region');

            if (in_array($taxonomy, $mylisting_taxonomies)) {
                $defaults = array('icon', 'image', 'icon_image');
            }
        }

        // ListingPro theme taxonomies
        if ($this->_isListingPro) {
            $listingpro_taxonomies = array('listing-category', 'list-tags', 'location', 'features');

            if (in_array($taxonomy, $listingpro_taxonomies)) {
                $defaults = array('lp_category_image2', 'lp_features_icon');
            }
        }

        return $defaults;
    }

    /**
     * Get taxonomy meta fields from wp-rest-api-controller settings
     * Falls back to theme-specific defaults if no settings found
     *
     * @param WP_Term $term The term object
     * @return array Meta fields for the term
     */
    private function get_taxonomy_meta_fields($term) {
        // Try to get settings from wp-rest-api-controller options first
        $taxonomy_options = get_option(
            "wp_rest_api_controller_taxonomies_{$term->taxonomy}",
            false
        );

        // If wp-rest-api-controller settings exist, use them
        if ($taxonomy_options !== false && !empty($taxonomy_options['meta_data'])) {
            return $this->get_taxonomy_meta_fields_from_options($term, $taxonomy_options['meta_data']);
        }

        // Fallback: Use theme-specific defaults
        $default_meta_keys = $this->get_default_taxonomy_meta_keys($term->taxonomy);
        if (empty($default_meta_keys)) {
            return array();
        }

        return $this->get_taxonomy_meta_fields_from_defaults($term, $default_meta_keys);
    }

    /**
     * Get taxonomy meta fields from wp-rest-api-controller options
     *
     * @param WP_Term $term The term object
     * @param array $meta_data_options Meta data from options
     * @return array Meta fields
     */
    private function get_taxonomy_meta_fields_from_options($term, $meta_data_options) {
        $meta_fields = array();

        foreach ($meta_data_options as $meta_key => $meta_data) {
            // Skip if not active
            if (empty($meta_data['active'])) {
                continue;
            }

            // Use custom key if provided, otherwise use original meta key
            $rest_api_key = !empty($meta_data['custom_key']) ? $meta_data['custom_key'] : $meta_key;
            $original_key = !empty($meta_data['original_meta_key']) ? $meta_data['original_meta_key'] : $meta_key;

            // Get meta value
            $meta_value = get_term_meta($term->term_id, $original_key, true);

            if (empty($meta_value)) {
                $meta_fields[$rest_api_key] = '';
                continue;
            }

            // Process meta value based on type
            $meta_fields[$rest_api_key] = $this->process_taxonomy_meta_value($meta_value, $original_key);

            // Apply filter for custom processing (compatibility with wp-rest-api-controller)
            $meta_fields[$rest_api_key] = apply_filters(
                'wp_rest_api_controller_api_property_value',
                $meta_fields[$rest_api_key],
                $term->term_id,
                $original_key,
                true // is_tax
            );
        }

        return $meta_fields;
    }

    /**
     * Get taxonomy meta fields from theme defaults (fallback)
     *
     * @param WP_Term $term The term object
     * @param array $meta_keys Default meta keys for the taxonomy
     * @return array Meta fields
     */
    private function get_taxonomy_meta_fields_from_defaults($term, $meta_keys) {
        $meta_fields = array();

        foreach ($meta_keys as $meta_key) {
            $meta_value = get_term_meta($term->term_id, $meta_key, true);

            if (empty($meta_value)) {
                $meta_fields[$meta_key] = '';
                continue;
            }

            // Process meta value
            $meta_fields[$meta_key] = $this->process_taxonomy_meta_value($meta_value, $meta_key);
        }

        return $meta_fields;
    }

    /**
     * Process taxonomy meta value (convert attachment IDs, icon classes, etc.)
     *
     * @param mixed $value Meta value to process
     * @param string $meta_key Original meta key name
     * @return mixed Processed value
     */
    private function process_taxonomy_meta_value($value, $meta_key) {
        // Attachment ID - convert to URL
        if (is_numeric($value)) {
            $url = wp_get_attachment_url($value);
            return $url ? $url : $value;
        }

        // Check if it's an icon class (for icon, _icon_svg fields)
        if (is_string($value) && (strpos($meta_key, 'icon') !== false || strpos($meta_key, 'svg') !== false)) {
            // Try to convert icon class to Iconify URL
            $url = $this->convert_icon_class_to_url($value);
            return $url ? $url : $value;
        }

        return $value;
    }

    /**
     * Remove ACF field reference keys from term data
     * ACF stores meta with pattern: field_name (value) and _field_name (field key reference)
     * We only need the actual values, not the internal ACF references
     *
     * @param stdClass $term_data Term data object
     * @return stdClass Cleaned term data
     */
    private function cleanup_acf_fields($term_data) {
        if (!is_object($term_data)) {
            return $term_data;
        }

        // List of ACF internal fields to remove (underscore prefix)
        $acf_keys_to_remove = array(
            '_icon_type',
            '_icon',
            '_color',
            '_text_color',
            '_image',
            '_listing_type', '__landing_page', '_yoast_term_redirect_info'
        );

        foreach ($acf_keys_to_remove as $key) {
            if (property_exists($term_data, $key)) {
                unset($term_data->$key);
            }
        }

        return $term_data;
    }

    /**
     * Add image field to term data
     * Priority by theme:
     * - ListingPro: lp_category_image > lp_category_banner > lp_category_image2
     * - MyListing: icon_type='image' (image) > icon_type='icon' (icon class)
     * - Listeo: _icon_svg (custom SVG) > icon (FA class)
     * - All: Regular icon with FA conversion
     *
     * @param WP_Term $term The term object
     * @return stdClass Term data with image field
     */
    private function add_image_field($term) {
        if (!is_object($term)) {
            return $term;
        }

        // Build term data object
        $term_data = (object) array(
            'term_id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'term_group' => $term->term_group,
            'term_taxonomy_id' => $term->term_taxonomy_id,
            'taxonomy' => $term->taxonomy,
            'description' => $term->description,
            'parent' => $term->parent,
            'count' => $term->count,
            'filter' => $term->filter,
            'image' => '',
        );

        // Add dynamic meta fields from wp-rest-api-controller settings
        $meta_fields = $this->get_taxonomy_meta_fields($term);
        if (!empty($meta_fields)) {
            foreach ($meta_fields as $key => $value) {
                $term_data->$key = $value;
            }
        }

        // Cleanup ACF internal field references (MyListing theme)
        if ($this->_isMyListing) {
            $term_data = $this->cleanup_acf_fields($term_data);
        }

        // ListingPro theme - check multiple meta keys with priority
        if ($this->_isListingPro) {
            $meta_keys = array('lp_category_image', 'lp_category_banner', 'lp_category_image2');

            foreach ($meta_keys as $meta_key) {
                $value = get_term_meta($term->term_id, $meta_key, true);
                $image = $this->process_listingpro_meta($value);

                if (!empty($image)) {
                    $term_data->image = $image;
                    return $term_data;
                }
            }
        }

        // MyListing theme - check icon_type first (term_meta or ACF)
        if ($this->_isMyListing) {
            $icon_type = $this->get_mylisting_field($term->term_id, $term->taxonomy, 'icon_type');

            if ($icon_type === 'image') {
                $icon_image = $this->get_mylisting_field($term->term_id, $term->taxonomy, 'image');
                $image_url = $this->extract_mylisting_image_url($icon_image);

                if ($image_url) {
                    $term_data->image = $image_url;
                    return $term_data;
                }
            } elseif ($icon_type === 'icon') {
                // MyListing font icon
                $icon_class = $this->get_mylisting_field($term->term_id, $term->taxonomy, 'icon');
                if (!empty($icon_class)) {
                    $url = $this->convert_icon_class_to_url($icon_class);
                    if (!empty($url)) {
                        $term_data->image = $url;
                        return $term_data;
                    }
                    // If icon class cannot be converted (e.g., custom theme icons like "icon-pin-2"),
                    // fallback to check if there's an 'image' field as backup
                }

                // Fallback to image field when icon conversion fails
                $fallback_image = $this->get_mylisting_field($term->term_id, $term->taxonomy, 'image');
                $image_url = $this->extract_mylisting_image_url($fallback_image);

                if ($image_url) {
                    $term_data->image = $image_url;
                    return $term_data;
                }
            }
        }

        // Listeo theme - Custom SVG icon
        if ($this->_isListeo) {
            $custom_icon_keys = array('_icon_svg', 'custom_icon', 'svg_icon', '_custom_icon');
            $image = $this->get_image_from_meta($term->term_id, $custom_icon_keys, false);

            if (!empty($image)) {
                $term_data->image = $image;
                return $term_data;
            }
        }

        // Fallback: Regular icon with class-to-URL conversion (all themes)
        $icon_keys = array('icon', 'lp_category_icon', 'image', '_icon');
        $image = $this->get_image_from_meta($term->term_id, $icon_keys, true);

        if (!empty($image)) {
            $term_data->image = $image;
        }

        return $term_data;
    }

    /**
     * Get all taxonomies for a post
     *
     * @param array $object The post object
     * @param string $field_name The field name
     * @param WP_REST_Request $request The request object
     * @return array The taxonomy data
     */
    public function get_all_taxonomies($object, $field_name, $request) {
        $return = array();

        // Get categories
        $post_categories = wp_get_post_categories($object['id']);
        if (!empty($post_categories)) {
            foreach ($post_categories as $category_id) {
                $category = get_category($category_id);
                if ($category && !is_wp_error($category)) {
                    $return['categories'][] = $this->add_image_field($category);
                }
            }
        }

        // Get tags
        $post_tags = wp_get_post_tags($object['id']);
        if (!empty($post_tags)) {
            foreach ($post_tags as $tag) {
                $return['tags'][] = $this->add_image_field($tag);
            }
        }

        // Get custom taxonomies
        $taxonomies = get_taxonomies(array(
            'public' => true,
            '_builtin' => false
        ), 'names', 'and');

        if (!empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy_name) {
                $terms = get_the_terms($object['id'], $taxonomy_name);

                if (is_array($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        if ($term && !is_wp_error($term)) {
                            $return[$taxonomy_name][] = $this->add_image_field($term);
                        }
                    }
                }
            }
        }

        return $return;
    }
}

// Initialize the helper
new MStore_Pure_Taxonomies_Helper();
