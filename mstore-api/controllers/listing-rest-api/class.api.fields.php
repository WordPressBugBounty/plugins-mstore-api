<?php
require_once(__DIR__ . '/mylisting-functions.php');

class FlutterTemplate extends WP_REST_Posts_Controller
{

    protected $_template = 'listable'; // get_template
    protected $_listable = 'listable';
    protected $_listify = 'listify';
    protected $_listingPro = 'listingpro';
    protected $_myListing = 'my listing';
    protected $_jobify = 'jobify';
    protected $_listeo = 'listeo';
    protected $_customPostType = ['job_listing', 'listing']; // all custom post type
    protected $_isListable, $_isListify, $_isMyListing, $_isListingPro, $_isListeo;

    public function __construct()
    {
        // Delay theme detection to init hook to avoid premature textdomain loading
        add_action('init', array($this, 'detect_theme'));

        add_action('init', array(
            $this,
            'add_custom_type_to_rest_api'
        ));

        add_action('rest_api_init', array(
            $this,
            'register_add_more_fields_to_rest_api'
        ));
    }

    /**
     * Detect the theme and set related properties
     */
    public function detect_theme()
    {
        $theme = wp_get_theme(get_template());
        $this->_template = strtolower($theme->get('Name'));

        if (strpos($this->_template, $this->_listeo) !== false)
        {
            $this->_isListeo = 1;
        }
        if (strpos($this->_template, $this->_myListing) !== false)
        {
            $this->_isMyListing = 1;
        }
        if (strpos($this->_template, $this->_listingPro) !== false)
        {
            $this->_isListingPro = 1;
        }
        if (strpos($this->_template, $this->_listable) !== false)
        {
            $this->_isListable = 1;
        }
        if (strpos($this->_template, $this->_listify) !== false)
        {
            $this->_isListify = 1;
        }

        if($this->_isListeo != 1 && $this->_isListingPro != 1 && $this->_isListable != 1 && $this->_isListify != 1){
            $this->_isMyListing = 1;
        }

        if($this->_isListeo){
             add_filter('rest_listing_query', array(
                    $this,
                    'custom_rest_listing_query'
                ), 10, 2);
        }
    }

    /**
     * Add custom type to rest api
     */
    public function add_custom_type_to_rest_api()
    {
        global $wp_post_types, $wp_taxonomies, $post;
        if (isset($wp_post_types['job_listing']))
        {
            $wp_post_types['job_listing']->show_in_rest = true;
            $wp_post_types['job_listing']->rest_base = 'job_listing';
            $wp_post_types['job_listing']->rest_controller_class = 'WP_REST_Posts_Controller';
        }

        //be sure to set this to the name of your taxonomy!
        $taxonomy_name = array(
            'job_listing_category',
            'region',
            'features',
            'job_listing_type',
            'job_listing_region',
            'location',
            'list-tags'
        );
        if (isset($wp_taxonomies))
        {
            foreach ($taxonomy_name as $k => $name):
                if (isset($wp_taxonomies[$name]))
                {
                    $wp_taxonomies[$name]->show_in_rest = true;
                    $wp_taxonomies[$name]->rest_base = $name;
                    $wp_taxonomies[$name]->rest_controller_tclass = 'WP_REST_Terms_Controller';
                }
            endforeach;
        }

    }

    /**
     * Register more field to rest api
     */
    public function register_add_more_fields_to_rest_api()
    {

        // Blog rest api fields
        register_rest_field('post', 'image_feature', array(
            'get_callback' => array(
                $this,
                'get_blog_image_feature'
            ) ,
        ));

        register_rest_field('post', 'author_name', array(
            'get_callback' => array(
                $this,
                'get_blog_author_name'
            ) ,
        ));

        // Get Field Category Custom
        $field_cate = $this->_isListingPro ? 'listing-category' : 'job_listing_category';
        register_rest_field($field_cate, 'term_image', array(
            'get_callback' => array(
                $this,
                'get_term_meta_image'
            ) ,
        ));

        register_rest_field('listing_category', 'term_image', array(
            'get_callback' => array(
                $this,
                'get_term_meta_image'
            ) ,
        ));

        if ($this->_isListable)
        {
            register_rest_field($this->_customPostType, 'author_name', array(
                'get_callback' => array(
                    $this,
                    'get_author_meta'
                ) ,
                'update_callback' => null,
                'schema' => null,
            ));
        }

        // Listing Pro
        if ($this->_isListingPro)
        {
            register_rest_field('lp-reviews', 'author_name', array(
                'get_callback' => array(
                    $this,
                    'get_author_meta'
                ) ,
                'update_callback' => null,
                'schema' => null,
            ));

            register_rest_field($this->_customPostType, 'gallery_images', array(
                'get_callback' => array(
                    $this,
                    'get_post_gallery_images_listingPro'
                ) ,
            ));

            register_rest_route('wp/v2', '/lp-reviews/(?P<listing_id>\d+)', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_listingpro_reviews_by_id'),
                'permission_callback' => function () {
                    return true;
                }
            ));
        }

        // Listeo
        if ($this->_isListeo)
        {
            register_rest_field($this->_customPostType, 'gallery_images', array(
                'get_callback' => array(
                    $this,
                    'get_post_gallery_images_listeo'
                ) ,
            ));
            register_rest_field($this->_customPostType, 'time_slots', array(
                'get_callback' => array(
                    $this,
                    'get_service_slots'
                ) ,
            ));
            register_rest_field($this->_customPostType, 'gallery_images', array(
                'get_callback' => array(
                    $this,
                    'get_post_gallery_images_listeo'
                ) ,
            ));
            register_rest_route('wp/v2', '/check-availability', array(
                'methods' => 'POST',
                'callback' => array(
                    $this,
                    'check_availability'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));
            register_rest_route('wp/v2', '/get-slots', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'check_availability'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            register_rest_route('wp/v2', '/booking', array(
                'methods' => 'POST',
                'callback' => array(
                    $this,
                    'booking'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));
            register_rest_route('wp/v2', '/get-bookings', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'get_bookings'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            register_rest_route('wp/v2', '/get-ticket/(?P<booking_id>\d+)', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'get_ticket'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            register_rest_route('wp/v2', '/cancel-booking', array(
                'methods' => 'POST',
                'callback' => array(
                    $this,
                    'cancel_booking'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            register_rest_route('wp/v2', '/delete-booking', array(
                'methods' => 'POST',
                'callback' => array(
                    $this,
                    'delete_booking'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            register_rest_route('wp/v2', '/payment', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'get_payment_methods'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));
        }

        // My Listing
        if ($this->_isMyListing)
        {
            /* get listing by tags for case myListing */
            register_rest_route('tags/v1', '/job_listing', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'get_job_listing_by_tags'
                ) ,
                'args' => array(
                    'tag' => array() ,
                    'page' => array(
                        'validate_callback' => function ($param, $request, $key)
                        {
                            return is_numeric($param);
                        }
                    ) ,
                    'limit' => array(
                        'validate_callback' => function ($param, $request, $key)
                        {
                            return is_numeric($param);
                        }
                    ) ,
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            //add address
            register_rest_field('job_listing',
                'newaddress',
                array(
                    'get_callback'  => array($this,'_rest_get_address_data'),
                )
            );

            //add lat
            register_rest_field( 'job_listing',
                'newlat',
                array(
                    'get_callback'  => array($this,'_rest_get_lat_data'),
                )
            );

            register_rest_field( 'job_listing',
                'newlng',
                array(
                    'get_callback'  => array($this,'_rest_get_lng_data'),
                )
            );

            register_rest_route('wp/v2', '/job_listing/(?P<id>\d+)/contents', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'get_listing_tabs'
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));
        }

        /* --- meta field for gallery image --- */

        register_rest_field($this->_customPostType, 'comments_ratings', array(
            'get_callback' => array(
                $this,
                'get_comments_ratings'
            ) ,
            'update_callback' => null,
            'schema' => null,
        ));

        register_rest_field($this->_customPostType, 'listing_data', array(
            'get_callback' => array(
                $this,
                'get_post_meta_for_api'
            ) ,
            'schema' => null,
        ));

        register_rest_field($this->_customPostType, 'cost', array(
            'get_callback' => array(
                $this,
                'get_cost_for_booking'
            ) ,
            'schema' => null,
        ));

        register_rest_field($this->_customPostType, 'pure_taxonomies', array(
            'get_callback' => array(
                $this,
                'get_pure_taxonomies'
            ) ,
            'schema' => null,
        ));

        register_rest_field($this->_customPostType, 'featured_image', array(
            'get_callback' => array(
                $this,
                'get_blog_image_feature'
            ) ,
            'schema' => null,
        ));

        /* Register for custom routes to rest API */
        register_rest_route('wp/v2', '/getRating/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_rating'
            ) ,
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/getReviews/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_reviews'
            ) ,
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/submitReview', array(
            'methods' => 'POST',
            'callback' => array(
                $this,
                'submitReview'
            ) ,
            'args' => array(
                'post_author' => array(
                    'validate_callback' => function ($param, $request, $key)
                    {
                        return is_numeric($param);
                    }
                ) ,
                'post_title' => array() ,
                'post_content' => array() ,
                'listing_id' => array(
                    'validate_callback' => function ($param, $request, $key)
                    {
                        return is_numeric($param);
                    }
                ) ,
                'rating' => array(
                    'validate_callback' => function ($param, $request, $key)
                    {
                        return is_numeric($param);
                    }
                )
                ),
                'permission_callback' => function () {
                    return true;
                }
        ));

        register_rest_route('wp/v2', '/get-nearby-listings', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_nearby_listings'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-countries-with-listings', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_countries_with_listings'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-provinces-with-listings', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_provinces_with_listings'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-listings-by-province', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_listings_by_province'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2/dokan', '/orders', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_dokan_orders'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-listing-types', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_listing_types'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-listing-regions', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_listing_regions'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-submit-listing', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_submit_listing'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));

        register_rest_route('wp/v2', '/get-contact-fields', array(
            'methods' => 'GET',
            'callback' => array(
                $this,
                'get_contact_fields'
            ),
            'permission_callback' => function () {
                return true;
            }
        ));
    }


    function get_dokan_orders($request)
    {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        if (isset($cookie) && $cookie != null) {
            $user_id = validateCookieLogin($cookie);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            $page = isset($request["page"]) ? $request["page"] : 1;
            $page = $page - 1;
            $limit = isset($request["limit"]) ? $request["limit"] : 10;
            $page = $page * $limit;

            global $wpdb;
            $postmeta_tb = $wpdb->prefix . "postmeta";
            $posts_tb = $wpdb->prefix . "posts";
            $dokan_orders_tb = $wpdb->prefix . "dokan_orders";
            $sql = $wpdb->prepare("SELECT $posts_tb.ID FROM $postmeta_tb INNER JOIN $posts_tb ON $postmeta_tb.post_id=$posts_tb.ID INNER JOIN $dokan_orders_tb ON $posts_tb.ID = $dokan_orders_tb.order_id WHERE $postmeta_tb.meta_key = '_customer_user' AND $postmeta_tb.meta_value=%s LIMIT %d OFFSET %d",$user_id,$limit,$page);
            $items = $wpdb->get_results($sql);
            if(empty($items)){
                return [];
            }
            $ids = [];
            foreach ($items as $item) {
                $ids[] = $item->ID;
            }
            add_filter( 'woocommerce_rest_check_permissions', '__return_true' );
            $controller = new CUSTOM_WC_REST_Orders_Controller();
            $req = new WP_REST_Request('GET');
            $params = ['include'=>$ids,'page' => 1, 'per_page' => $limit, 'status'=>['any']];
            $req->set_query_params($params);
            $response = $controller->get_items($req);
            remove_filter( 'woocommerce_rest_check_permissions', '__return_true' );
            return $response->get_data();
        }else{
            return new WP_Error("no_permission",  "You need to add User-Cookie in header request", array('status' => 400));
        }
    }


    private function get_icon_url($type) {
        $icon_id = get_option("listeo_{$type}_type_icon");
        if ($icon_id) {
            $icon = wp_get_attachment_image_src($icon_id, 'full');
            return $icon ? $icon[0] : '';
        }
        return '';
    }

    public function get_submit_listing($request){
		if ($this->_isListeo) {
            $response = [
                'supported_listing_types' => get_option('listeo_listing_types', ['service', 'rental', 'event', 'classifieds']),

                // Type icons
                'service_type_icon' => $this->get_icon_url('service'),
                'rental_type_icon' => $this->get_icon_url('rental'),
                'event_type_icon' => $this->get_icon_url('event'),
                'classifieds_type_icon' => $this->get_icon_url('classifieds'),

                // Global settings
                'disable_bookings_module' => (bool)get_option('listeo_bookings_disabled', false),
                'admin_approval_required_for_new_listings' => (bool)get_option('listeo_new_listing_requires_approval', false),
                'admin_approval_required_for_editing_listing' => (bool)get_option('listeo_edit_listing_requires_approval', false),
                'notify_admin_by_mail_about_new_listing_waiting_for_approval' => (bool)get_option('listeo_new_listing_admin_notification', false),
                'listing_duration_days' => (int)get_option('listeo_default_duration', 30),
                'listing_images_upload_limit' => (int)get_option('listeo_max_files', 10),
                'listing_image_maximum_size_mb' => (int)get_option('listeo_max_filesize', 15),
                'submit_listing_map_center_point' => get_option('listeo_submit_center_point')
            ];

            return [$response];

        } else {
            return new WP_Error("not_found",  "get_submit_listing is not implemented", array('status' => 404));
        }
    }

    public function get_listing_types($request){
        if ($this->_isListeo) {
            $supported_types = get_option('listeo_listing_types', ['service', 'rental', 'event', 'classifieds']);

            $types = [];
            foreach ($supported_types as $type) {
                $types[] = [
                    'post_title' => ucfirst($type),
                    'post_name' => $type,
                    'icon' => $this->get_icon_url($type),
                ];
            }
            return $types;

        }
        if ($this->_isMyListing) {
            $types = get_posts( [
				'post_type' => 'case27_listing_type',
				'posts_per_page' => -1,
			] );
            return  $types;
        } else {
            return new WP_Error("not_found",  "get_listing_types is not implemented", array('status' => 404));
        }
    }

    public function get_listing_regions($request){
        if ($this->_isMyListing) {
            $regions = get_terms([
                'taxonomy' => 'region',
                'hide_empty' => false,
            ]);

            return $regions;
        } else {
            return new WP_Error("not_found",  "get_listing_regions is not implemented", array('status' => 404));
        }
    }

    public function get_nearby_listings($request){
        $current_lat = $request['lat'];
        $current_long = $request['long'];
        $search_location = $request['search_location'];
        $radius = 100; //in km
        if(isset($request['radius'])){
            $radius =  $request['radius'];
        }
        $limit = 10;
        $offset = 0;
        if(isset($request['per_page'])){
            $limit = absint( $request['per_page'] );
        }
        if(isset($request['page'])){
            $offset = absint($request['page']);
            $offset= ($offset -1) * $limit;
        }

        $data = array();
        global $wpdb;
        if($this->_isListeo){

            $sql = "SELECT p.*, ";
            $sql .= " (6371 * acos (cos (radians(%f)) * cos(radians(t.lat)) * cos(radians(t.lng) - radians(%f)) + ";
            $sql .= "sin (radians(%f)) * sin(radians(t.lat)))) AS distance FROM (SELECT b.post_id, a.post_status, sum(if(";
            $sql .= "meta_key = '_geolocation_lat', meta_value, 0)) AS lat, sum(if(meta_key = '_geolocation_long', ";
            $sql .= "meta_value, 0)) AS lng FROM {$wpdb->prefix}posts a, {$wpdb->prefix}postmeta b WHERE a.id = b.post_id AND (";
            $sql .= "b.meta_key='_geolocation_lat' OR b.meta_key='_geolocation_long') AND a.post_status='publish' GROUP BY b.post_id) AS t INNER ";
            $sql .= "JOIN {$wpdb->prefix}posts as p on (p.ID=t.post_id) HAVING distance < %f";

            $sql = $wpdb->prepare($sql, $current_lat, $current_long, $current_lat, $radius);
            $posts = $wpdb->get_results($sql);
            $items = (array)($posts);
            // return $items;
            foreach ($items as $item):
                $itemdata = $this->prepare_item_for_response($item, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            endforeach;
        }
        if( $this->_isMyListing){
            $listing_type = $request['listing_type'] ?? '';
            $listing_category = $request['listing_category'] ?? '';
            $listing_region = $request['listing_region'] ?? '';


            if (!empty($listing_category)) {
                // Filter by listing category
                $bodyReq = [
                    'listing_category' => $listing_category,
                    'form_data' => [
                        'page' => $offset / $limit,
                        'per_page' => $limit,
                        'orderby' => 'date',
                        'order' => 'DESC'
                    ]
                ];
            } else if (!empty($listing_type)) {
                // Filter by listing type
                $bodyReq = [
                    'proximity_units' => 'km',
                    'listing_type' => $listing_type,
                    'form_data' => [
                        'search_keywords' => '',
                        'proximity' => $radius,
                        'lat' => $current_lat,
                        'lng' => $current_long,
                        'search_location' => $search_location ?? '',
                        'region' => '',
                        'tags' => '',
                        'sort' => 'nearby',
                        'page' => $offset / $limit,
                        'per_page' => $limit
                    ]
                ];
            } else if (!empty($listing_region)) {
                // Filter by listing region
                $bodyReq = [
                    'listing_region' => $listing_region,
                    'form_data' => [
                        'page' => $offset / $limit,
                        'per_page' => $limit,
                        'orderby' => 'date',
                        'order' => 'DESC'
                    ]
                ];
            }
            else {
                $bodyReq = [];
            }

            $posts = myListingExploreListings($bodyReq);
            $items = (array)($posts);
            foreach ($items as $item):
                $itemdata = $this->prepare_item_for_response($item, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            endforeach;

            // $sql = "SELECT p.*, ";
            // $sql .= " (6371 * acos (cos (radians($current_lat)) * cos(radians(t.lat)) * cos(radians(t.lng) - radians($current_long)) + ";
            // $sql .= "sin (radians($current_lat)) * sin(radians(t.lat)))) AS distance FROM (SELECT b.post_id, a.post_status, sum(if(";
            // $sql .= "meta_key = 'geolocation_lat', meta_value, 0)) AS lat, sum(if(meta_key = 'geolocation_long', ";
            // $sql .= "meta_value, 0)) AS lng FROM {$wpdb->prefix}posts a, {$wpdb->prefix}postmeta b WHERE a.id = b.post_id AND (";
            // $sql .= "b.meta_key='geolocation_lat' OR b.meta_key='geolocation_long') AND a.post_status='publish' GROUP BY b.post_id) AS t INNER ";
            // $sql .= "JOIN {$wpdb->prefix}posts as p on (p.ID=t.post_id) HAVING distance < {$radius}";
            // $posts = $wpdb->get_results($sql);
            // $items = (array)($posts);
            // // return $items;
            // foreach ($items as $item):
            //     $itemdata = $this->prepare_item_for_response($item, $request);
            //     $data[] = $this->prepare_response_for_collection($itemdata);
            // endforeach;
        }
        if($this->_isListingPro){
            $args = array(
                'post_type' => 'listing',
                'posts_per_page' => -1,
                'paged' => 1,
                'post_status' => 'publish'
            );
            $posts = query_posts($args);

            $items = (array)($posts);
            foreach ($items as $item):
                $this_lat = listing_get_metabox_by_ID('latitude',$item->ID);
                $this_long = listing_get_metabox_by_ID('longitude',$item->ID);
                if( !empty($this_lat) && !empty($this_long) ){

                    $calDistance = GetDrivingDistance($current_lat, $this_lat, $current_long, $this_long, 'km');
                    if(!empty($calDistance['distance'])){
                        if( $calDistance['distance'] < $radius){
                            $itemdata = $this->prepare_item_for_response($item, $request);
                            $data[] = $this->prepare_response_for_collection($itemdata);
                        }
                    }
                }

            endforeach;
        }
        return $data;
    }

    /**
    * Reverse geocode latitude and longitude to get country and province using Nominatim (OpenStreetMap).
     *
     * @param float $lat Latitude coordinate.
     * @param float $lng Longitude coordinate.
     * @return array{country: ?string, country_code: ?string, province: ?string} Associative array with country, country_code, and province (all may be null or string).
     *
     * Note: This function enforces a 1-second delay per request to comply with Nominatim's usage policy (max 1 request per second).
     */
    private function reverse_geocode($lat, $lng) {
        // Validate that lat and lng are numeric
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return ['country' => null, 'country_code' => null, 'province' => null];
        }
        // Nominatim API endpoint
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&addressdetails=1";

        // Important: Nominatim requires User-Agent header
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'FluxStore/1.0 (https://inspireui.com)'
            )
        ));

        if (is_wp_error($response)) {
            return ['country' => null, 'country_code' => null, 'province' => null];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($response_code != 200) {
            return ['country' => null, 'country_code' => null, 'province' => null];
        }

        if (empty($body) || !isset($body['address'])) {
            return ['country' => null, 'country_code' => null, 'province' => null];
        }

        $address = $body['address'];
        $country = null;
        $country_code = null;
        $province = null;

        // Extract country
        if (isset($address['country'])) {
            $country = $address['country'];
        }
        if (isset($address['country_code'])) {
            $country_code = strtoupper($address['country_code']);
        }

        if (isset($address['state'])) {
            $province = $address['state'];
        } elseif (isset($address['province'])) {
            $province = $address['province'];
        } elseif (isset($address['region'])) {
            $province = $address['region'];
        } elseif (isset($address['county'])) {
            $province = $address['county'];
        }

        // Respect Nominatim usage policy: max 1 request per second
        sleep(1);

        $result = [
            'country' => $country,
            'country_code' => $country_code,
            'province' => $province
        ];

        return $result;
    }
    /**
     * Get or update cached country/province for a listing
     */
    private function get_listing_location($listing_id, $lat, $lng) {
        // Check if already cached - MUST have both country AND province
        $cached_country = get_post_meta($listing_id, '_listing_country', true);
        $cached_country_code = get_post_meta($listing_id, '_listing_country_code', true);
        $cached_province = get_post_meta($listing_id, '_listing_province', true);

        // Only return cached if we have BOTH country and province
        if (!empty($cached_country_code) && !empty($cached_province)) {
            return [
                'country' => $cached_country,
                'country_code' => $cached_country_code,
                'province' => $cached_province
            ];
        }

        // If we have country but not province, or nothing at all - geocode
        $location = $this->reverse_geocode($lat, $lng);

        update_post_meta($listing_id, '_listing_country', $location['country']);
        update_post_meta($listing_id, '_listing_country_code', $location['country_code']);
        update_post_meta($listing_id, '_listing_province', $location['province']);

        return $location;
    }

    private function get_countries_aggregated() {
        global $wpdb;

        if ($this->_isListeo) {
            $sql = "SELECT
                        pm_country_code.meta_value as country_code,
                        pm_country.meta_value as country_name,
                        COUNT(DISTINCT p.ID) as listings_count,
                        MIN(CAST(pm_lat.meta_value AS DECIMAL(10,8))) as min_lat,
                        MAX(CAST(pm_lat.meta_value AS DECIMAL(10,8))) as max_lat,
                        MIN(CAST(pm_lng.meta_value AS DECIMAL(11,8))) as min_lng,
                        MAX(CAST(pm_lng.meta_value AS DECIMAL(11,8))) as max_lng
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key = '_geolocation_lat'
                    INNER JOIN {$wpdb->prefix}postmeta pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key = '_geolocation_long'
                    INNER JOIN {$wpdb->prefix}postmeta pm_country_code ON p.ID = pm_country_code.post_id AND pm_country_code.meta_key = '_listing_country_code'
                    LEFT JOIN {$wpdb->prefix}postmeta pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_listing_country'
                    WHERE p.post_type = 'listing' AND p.post_status = 'publish'
                    AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''
                    AND pm_country_code.meta_value != '' AND pm_country_code.meta_value IS NOT NULL
                    GROUP BY pm_country_code.meta_value, pm_country.meta_value
                    ORDER BY listings_count DESC";

            return $wpdb->get_results($sql);

        } elseif ($this->_isMyListing) {
            $sql = "SELECT
                        pm_country_code.meta_value as country_code,
                        pm_country.meta_value as country_name,
                        COUNT(DISTINCT p.ID) as listings_count,
                        MIN(CAST(pm_lat.meta_value AS DECIMAL(10,8))) as min_lat,
                        MAX(CAST(pm_lat.meta_value AS DECIMAL(10,8))) as max_lat,
                        MIN(CAST(pm_lng.meta_value AS DECIMAL(11,8))) as min_lng,
                        MAX(CAST(pm_lng.meta_value AS DECIMAL(11,8))) as max_lng
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key = 'geolocation_lat'
                    INNER JOIN {$wpdb->prefix}postmeta pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key = 'geolocation_long'
                    INNER JOIN {$wpdb->prefix}postmeta pm_country_code ON p.ID = pm_country_code.post_id AND pm_country_code.meta_key = '_listing_country_code'
                    LEFT JOIN {$wpdb->prefix}postmeta pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_listing_country'
                    WHERE p.post_type = 'job_listing' AND p.post_status = 'publish'
                    AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''
                    AND pm_country_code.meta_value != '' AND pm_country_code.meta_value IS NOT NULL
                    GROUP BY pm_country_code.meta_value, pm_country.meta_value
                    ORDER BY listings_count DESC";

            return $wpdb->get_results($sql);

        } elseif ($this->_isListingPro) {
            $sql = "SELECT p.ID,
                           pm_opts.meta_value as opts,
                           pm_country_code.meta_value as country_code,
                           pm_country.meta_value as country_name
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_opts ON p.ID = pm_opts.post_id AND pm_opts.meta_key = 'lp_listingpro_options'
                    INNER JOIN {$wpdb->prefix}postmeta pm_country_code ON p.ID = pm_country_code.post_id AND pm_country_code.meta_key = '_listing_country_code'
                    LEFT JOIN {$wpdb->prefix}postmeta pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_listing_country'
                    WHERE p.post_type = 'listing' AND p.post_status = 'publish'
                    AND pm_opts.meta_value != ''
                    AND pm_country_code.meta_value != '' AND pm_country_code.meta_value IS NOT NULL";

            $listings = $wpdb->get_results($sql);
            $aggregated = array();

            foreach ($listings as $listing) {
                $opts = maybe_unserialize($listing->opts);
                if (!is_array($opts) || !isset($opts['latitude']) || !isset($opts['longitude'])) continue;

                $code = $listing->country_code;
                if (!isset($aggregated[$code])) {
                    $aggregated[$code] = (object) array(
                        'country_code' => $code,
                        'country_name' => $listing->country_name ?: $code,
                        'listings_count' => 0,
                        'min_lat' => $opts['latitude'],
                        'max_lat' => $opts['latitude'],
                        'min_lng' => $opts['longitude'],
                        'max_lng' => $opts['longitude']
                    );
                }

                $agg = &$aggregated[$code];
                $agg->listings_count++;
                $agg->min_lat = min($agg->min_lat, $opts['latitude']);
                $agg->max_lat = max($agg->max_lat, $opts['latitude']);
                $agg->min_lng = min($agg->min_lng, $opts['longitude']);
                $agg->max_lng = max($agg->max_lng, $opts['longitude']);
            }

            return array_values($aggregated);
        }

        return array();
    }

    public function get_countries_with_listings($request) {
        $cache_key = 'countries_listing_summary';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $aggregated_data = $this->get_countries_aggregated();

        $countries = array();
        foreach ($aggregated_data as $row) {
            $countries[] = array(
                'country_code' => $row->country_code,
                'country_name' => $row->country_name ?: $row->country_code,
                'listings_count' => intval($row->listings_count),
                'bounds' => array(
                    'min_lat' => floatval($row->min_lat),
                    'max_lat' => floatval($row->max_lat),
                    'min_lng' => floatval($row->min_lng),
                    'max_lng' => floatval($row->max_lng)
                )
            );
        }

        $result = array('countries' => $countries);

        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);

        return $result;
    }

    private function get_provinces_aggregated($country_code) {
        global $wpdb;

        if ($this->_isListeo) {
            $sql = "SELECT
                        pm_province.meta_value as province_name,
                        COUNT(DISTINCT p.ID) as listings_count,
                        AVG(CAST(pm_lat.meta_value AS DECIMAL(10,8))) as avg_lat,
                        AVG(CAST(pm_lng.meta_value AS DECIMAL(11,8))) as avg_lng
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key = '_geolocation_lat'
                    INNER JOIN {$wpdb->prefix}postmeta pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key = '_geolocation_long'
                    INNER JOIN {$wpdb->prefix}postmeta pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_listing_country_code'
                    INNER JOIN {$wpdb->prefix}postmeta pm_province ON p.ID = pm_province.post_id AND pm_province.meta_key = '_listing_province'
                    WHERE p.post_type = 'listing' AND p.post_status = 'publish'
                    AND pm_country.meta_value = %s
                    AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''
                    AND pm_province.meta_value != '' AND pm_province.meta_value IS NOT NULL
                    GROUP BY pm_province.meta_value
                    ORDER BY listings_count DESC";

            return $wpdb->get_results($wpdb->prepare($sql, $country_code));

        } elseif ($this->_isMyListing) {
            $sql = "SELECT
                        pm_province.meta_value as province_name,
                        COUNT(DISTINCT p.ID) as listings_count,
                        AVG(CAST(pm_lat.meta_value AS DECIMAL(10,8))) as avg_lat,
                        AVG(CAST(pm_lng.meta_value AS DECIMAL(11,8))) as avg_lng
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key = 'geolocation_lat'
                    INNER JOIN {$wpdb->prefix}postmeta pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key = 'geolocation_long'
                    INNER JOIN {$wpdb->prefix}postmeta pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_listing_country_code'
                    INNER JOIN {$wpdb->prefix}postmeta pm_province ON p.ID = pm_province.post_id AND pm_province.meta_key = '_listing_province'
                    WHERE p.post_type = 'job_listing' AND p.post_status = 'publish'
                    AND pm_country.meta_value = %s
                    AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''
                    AND pm_province.meta_value != '' AND pm_province.meta_value IS NOT NULL
                    GROUP BY pm_province.meta_value
                    ORDER BY listings_count DESC";

            return $wpdb->get_results($wpdb->prepare($sql, $country_code));

        } elseif ($this->_isListingPro) {
            $sql = "SELECT p.ID,
                           pm_opts.meta_value as opts,
                           pm_province.meta_value as province_name
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_opts ON p.ID = pm_opts.post_id AND pm_opts.meta_key = 'lp_listingpro_options'
                    INNER JOIN {$wpdb->prefix}postmeta pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_listing_country_code'
                    INNER JOIN {$wpdb->prefix}postmeta pm_province ON p.ID = pm_province.post_id AND pm_province.meta_key = '_listing_province'
                    WHERE p.post_type = 'listing' AND p.post_status = 'publish'
                    AND pm_country.meta_value = %s
                    AND pm_opts.meta_value != ''
                    AND pm_province.meta_value != '' AND pm_province.meta_value IS NOT NULL";

            $listings = $wpdb->get_results($wpdb->prepare($sql, $country_code));
            $aggregated = array();

            foreach ($listings as $listing) {
                $opts = maybe_unserialize($listing->opts);
                if (!is_array($opts) || !isset($opts['latitude']) || !isset($opts['longitude'])) continue;

                $prov = $listing->province_name;
                if (!isset($aggregated[$prov])) {
                    $aggregated[$prov] = (object) array(
                        'province_name' => $prov,
                        'listings_count' => 0,
                        'lat_sum' => 0,
                        'lng_sum' => 0
                    );
                }

                $agg = &$aggregated[$prov];
                $agg->listings_count++;
                $agg->lat_sum += floatval($opts['latitude']);
                $agg->lng_sum += floatval($opts['longitude']);
            }

            $results = array();
            foreach ($aggregated as $prov => $data) {
                $results[] = (object) array(
                    'province_name' => $data->province_name,
                    'listings_count' => $data->listings_count,
                    'avg_lat' => $data->lat_sum / $data->listings_count,
                    'avg_lng' => $data->lng_sum / $data->listings_count
                );
            }

            return $results;
        }

        return array();
    }

    public function get_provinces_with_listings($request) {
        $country_code = $request['country'];

        if (empty($country_code)) {
            return new WP_Error('missing_country', 'Country parameter is required', array('status' => 400));
        }

        $cache_key = 'provinces_listing_summary_' . $country_code;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $aggregated_data = $this->get_provinces_aggregated($country_code);

        $provinces = array();
        foreach ($aggregated_data as $row) {
            $provinces[] = array(
                'province_id' => sanitize_title($row->province_name),
                'province_name' => $row->province_name,
                'listings_count' => intval($row->listings_count),
                'lat' => floatval($row->avg_lat),
                'lng' => floatval($row->avg_lng)
            );
        }

        $result = array(
            'country' => $country_code,
            'provinces' => $provinces
        );

        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);

        return $result;
    }

    public function get_listings_by_province($request) {
        $province_id = $request['province_id'];

        if (empty($province_id)) {
            return new WP_Error('missing_province', 'Province ID parameter is required', array('status' => 400));
        }

        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: $request->get_param('limit') ?: 10);
        $offset = ($page - 1) * $per_page;

        global $wpdb;
        $data = [];

        // Convert province_id to province name for matching
        $province_name = str_replace('-', ' ', $province_id);
        $province_name = ucwords($province_name);

        if ($this->_isListeo) {
            $sql = "SELECT p.*
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_province ON p.ID = pm_province.post_id AND pm_province.meta_key = '_listing_province'
                    WHERE p.post_type = 'listing' AND p.post_status = 'publish'
                    AND (pm_province.meta_value LIKE %s OR pm_province.meta_value LIKE %s)
                    LIMIT %d OFFSET %d";

            $search_pattern = '%' . $wpdb->esc_like($province_name) . '%';
            $sql = $wpdb->prepare($sql, $search_pattern, $search_pattern, $per_page, $offset);
            $posts = $wpdb->get_results($sql);

            foreach ($posts as $post) {
                $itemdata = $this->prepare_item_for_response($post, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            }
        }

        if ($this->_isMyListing) {
            $sql = "SELECT p.*
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_province ON p.ID = pm_province.post_id AND pm_province.meta_key = '_listing_province'
                    WHERE p.post_type = 'job_listing' AND p.post_status = 'publish'
                    AND (pm_province.meta_value LIKE %s OR pm_province.meta_value LIKE %s)
                    LIMIT %d OFFSET %d";

            $search_pattern = '%' . $wpdb->esc_like($province_name) . '%';
            $sql = $wpdb->prepare($sql, $search_pattern, $search_pattern, $per_page, $offset);
            $posts = $wpdb->get_results($sql);

            foreach ($posts as $post) {
                $itemdata = $this->prepare_item_for_response($post, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            }
        }

        if ($this->_isListingPro) {
            $sql = "SELECT p.*
                    FROM {$wpdb->prefix}posts p
                    INNER JOIN {$wpdb->prefix}postmeta pm_province ON p.ID = pm_province.post_id AND pm_province.meta_key = '_listing_province'
                    WHERE p.post_type = 'listing' AND p.post_status = 'publish'
                    AND (pm_province.meta_value LIKE %s OR pm_province.meta_value LIKE %s)
                    LIMIT %d OFFSET %d";

            $search_pattern = '%' . $wpdb->esc_like($province_name) . '%';
            $sql = $wpdb->prepare($sql, $search_pattern, $search_pattern, $per_page, $offset);
            $posts = $wpdb->get_results($sql);

            foreach ($posts as $post) {
                $itemdata = $this->prepare_item_for_response($post, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            }
        }

        return $data;
    }

    // Listeo theme functions
    public function get_service_slots($object)
    {
        $slots = [];
        if ( isset($object['_slots_status']) && $object['_slots_status'] == 'on')
        {
            $slots = json_decode($object['_slots']);

        }
        return $slots;
    }

    public function get_contact_fields() {
        if ($this->_isListeo) {
            // Get contact fields from options saved by Listeo_Fields_Editor
            $contact_fields = get_option('listeo_contact_tab_fields');

            if(empty($contact_fields)) {
                // If there are no custom fields, get default fields from Listeo_Core_Meta_Boxes
                $default_fields = Listeo_Core_Meta_Boxes::meta_boxes_contact();
                if(isset($default_fields['fields'])) {
                    $contact_fields = $default_fields['fields'];
                }
            }

            // Format the data to return via API
            $formatted_fields = array();
            if(!empty($contact_fields)) {
                foreach($contact_fields as $key => $field) {
                    $formatted_field = array(
                        'id' => $field['id'],
                        'name' => $field['name'],
                        'type' => $field['type'],
                        'required' => isset($field['required']) ? $field['required'] : false,
                        'placeholder' => isset($field['placeholder']) ? $field['placeholder'] : '',
                        'icon' => isset($field['icon']) ? $field['icon'] : '',
                        'desc' => isset($field['desc']) ? $field['desc'] : '',
                    );

                    // Handle options for select/multicheck fields
                    if(isset($field['options']) && !empty($field['options'])) {
                        if(is_array($field['options'])) {
                            $formatted_field['options'] = array_map(function($key, $value) {
                                return array(
                                    'key' => $key,
                                    'value' => $value
                                );
                            }, array_keys($field['options']), $field['options']);
                        }
                    }

                    // Process repeatable fields
                    if($field['type'] == 'repeatable' && isset($field['options'])) {
                        $formatted_field['repeatable_fields'] = array_map(function($key, $value) {
                            return array(
                                'id' => $key,
                                'name' => $value
                            );
                        }, array_keys($field['options']), $field['options']);
                    }

                    $formatted_fields[] = $formatted_field;
                }
            }

            return $formatted_fields;
        }

        return new WP_Error("not_found", "get_contact_fields is not implemented", array('status' => 404));
    }

    // ListingPro theme functions
    public function get_listingpro_reviews_by_id(WP_REST_Request $request) {
        $listing_id = $request['listing_id'];
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 100);

        $query = new WP_Query([
            'post_type' => 'lp-reviews',
            'posts_per_page' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'meta_query' => [[
                'key' => 'lp_listingpro_options',
                'value' => serialize($request['listing_id']),
                'compare' => 'LIKE'
            ]]
        ]);

        $results = [];
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $author_id = get_post_field('post_author');
            $avatar = get_user_meta($author_id, 'user_avatar', true);
            $avatar_url = (!empty($avatar) && !is_bool($avatar)) ? $avatar[0] : get_avatar_url($author_id);

            $results[] = [
                'id' => $post_id,
                'title' => get_the_title(),
                'content' => get_the_content(),
                'date' => get_the_date('Y-m-d\TH:i:s'),
                'rating' => get_post_meta($post_id, 'lp_listingpro_options', true)['rating'] ?? 0,
                'author_name' => $this->get_author_meta(['author' => $author_id]),
                'author_email' => ($user = get_user_by('ID', $author_id)) ? $user->user_email : '',
                'author_avatar' => $avatar_url ?: '',
                'gallery_images' => $this->get_post_gallery_images_listingPro(['id' => $post_id])
            ];
        }

        wp_reset_postdata();
        return $results;
    }

    public function get_payment_methods($object)
    {
        $cookie = $object['cookie'];
        if (!isset($cookie))
        {
            return new WP_REST_Response('You are unauthorized to do this', 401);
        }
        $user_id = wp_validate_auth_cookie($cookie, 'logged_in');
        if (!$user_id)
        {
            return new WP_REST_Response('Invalid request', 401);
        }
        $payment_methods = WC()
            ->payment_gateways
            ->get_available_payment_gateways();
        return array_values($payment_methods);
    }

    public function check_availability($request)
    {
        if (!isset($request['slot']))
        {
            $slot = false;
        }
        else
        {
            $slot = $request['slot'];
        }
        if (isset($request['hour']))
        {
            $data['free_places'] = 1;
        }
        else
        {
            $data['free_places'] = Listeo_Core_Bookings_Calendar::count_free_places($request['listing_id'], $request['date_start'], $request['date_end'], json_encode($slot));
        }

        $listing_id = $request['listing_id'];
        $multiply = (int) ($request['tickets'] ?? $request['adults'] ?? 1);
        $children_count = isset($request['children']) ? (int)$request['children'] : 0;
        $animals_count  = isset($request['animals']) ? (int)$request['animals'] : 0;

        $coupon = (isset($request['coupon'])) ? $request['coupon'] : false;
        $services = (isset($request['services'])) ? $request['services'] : false;
        if (is_array($services) && count($services) > 0) {
            $services = array_map(function($item){
                return ['service' => sanitize_title($item['service']), 'value'=>$item['value']];
            }, $services);
        }

        try {
            $args = array(
                $listing_id,
                $request['date_start'],
                $request['date_end'],
                $multiply,
                $children_count,
                $animals_count,
                $services,
                ''
            );

            $data['price'] = call_user_func_array(
                array('Listeo_Core_Bookings_Calendar', 'calculate_price'),
                $args
            );

            if (!empty($coupon)) {
                $args[count($args)-1] = $coupon;
                $price_with_coupon = call_user_func_array(
                    array('Listeo_Core_Bookings_Calendar', 'calculate_price'),
                    $args
                );
                if ($price_with_coupon <= $data['price']) {
                    $data['price_discount'] = $price_with_coupon;
                }
            }
        } catch (Error $e) {
            $data['price'] = Listeo_Core_Bookings_Calendar::calculate_price(
                $listing_id,
                $request['date_start'],
                $request['date_end'],
                $multiply,
                $children_count,
                $animals_count,
                $services,
                ''
            );

            if (!empty($coupon))
            {
                $price_with_coupon = Listeo_Core_Bookings_Calendar::calculate_price(
                    $request['listing_id'],
                    $request['date_start'],
                    $request['date_end'],
                    $multiply,
                    $children_count,
                    $animals_count,
                    $services,
                    $coupon
                );
                if ($price_with_coupon <= $data['price']) {
                    $data['price_discount'] = $price_with_coupon;
                }
            }
        }
        // $_slots = $this->update_slots($request);
        return $data;
    }

    public function get_bookings($request)
    {
        $user_id = $request['user_id'];

        $args = array(
            'bookings_author' => $user_id,
            'type' => 'reservation'
        );
        $limit = 10;
        $offset = 0;
        if (isset($request['per_page']) && isset($request['page']))
        {
            $limit = $request['per_page'];
            $offset = $request['page'];
        }
        $bookings = Listeo_Core_Bookings_Calendar::get_newest_bookings($args, $limit, $offset);

        $data = [];

        foreach ($bookings as $booking)
        {
            $item = $booking;
            // decoded normalized map under comment_obj
            if (isset($item['comment']) && is_string($item['comment'])) {
                $decoded_comment = json_decode($item['comment'], true);
                if (is_array($decoded_comment)) {
                    foreach ($decoded_comment as $cKey => $cVal) {
                        if ($cVal === false || $cVal === null) {
                            $decoded_comment[$cKey] = '';
                        }
                    }
                    $item['comment_obj'] = $decoded_comment;
                }
            }
            if (isset($booking['order_id']))
            {
                $order_id = $booking['order_id'];
                $order = wc_get_order($order_id);
                $order_data = $order->get_data();
                $item['order_status'] = $order_data['status'];
            }
            $post_id = $booking['listing_id'];
            $listing_type = get_post_meta($post_id, '_listing_type', true);
            $item['listing_type'] = $listing_type ? $listing_type : 'service';
            $item['featured_image'] = get_the_post_thumbnail_url($post_id);
            $item['title'] = get_the_title($post_id);
            $item['gallery_images'] = $this->get_post_gallery_images_listeo(['id' => $post_id]);

            // For event listings, also expose event start/end (listing's own dates, not booking date)
            if ($item['listing_type'] === 'event') {
                $event_start = get_post_meta($post_id, '_event_date', true);
                $event_end = get_post_meta($post_id, '_event_date_end', true);

                // Convert to Y-m-d H:i:s format (same as date_start/date_end)
                // Handle timestamp, m/d/Y H:i, or already correct format
                if (!empty($event_start) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event_start)) {
                    $ts = is_numeric($event_start) ? intval($event_start) : strtotime($event_start);
                    $event_start = $ts !== false ? date('Y-m-d H:i:s', $ts) : $event_start;
                }
                if (!empty($event_end) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event_end)) {
                    $ts = is_numeric($event_end) ? intval($event_end) : strtotime($event_end);
                    $event_end = $ts !== false ? date('Y-m-d H:i:s', $ts) : $event_end;
                }

                $item['event_start'] = $event_start;
                $item['event_end'] = $event_end;
            }
            if (isset($booking['owner_id'])) {
                $owner_data = get_userdata($booking['owner_id']);

                $item['owner_name'] = $owner_data ? $owner_data->display_name : '';
                $item['owner_email'] = $owner_data ? $owner_data->user_email : '';
                $item['owner_phone'] = get_user_meta($booking['owner_id'], 'billing_phone', true);
            }

            $data[] = $item;

        }

        return $data;
    }

    public function get_ticket($request)
    {
        global $wpdb;

        $booking_id = isset($request['booking_id']) ? intval($request['booking_id']) : 0;

        if (!$booking_id) {
            wp_die('Invalid booking ID');
        }

        // Get booking details
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bookings_calendar WHERE id = %d",
                $booking_id
            ),
            ARRAY_A
        );

        if (!$booking) {
            wp_die('Booking not found');
        }

        // Check if user is authorized (booking owner or listing owner)
        $current_user_id = get_current_user_id();

        // Try to get user from cookie parameter if not logged in via WP
        if (!$current_user_id && isset($_GET['cookie'])) {
            $cookie = base64_decode($_GET['cookie']);
            if ($cookie) {
                $cookie_parts = explode('|', $cookie);
                if (count($cookie_parts) >= 1) {
                    $username = $cookie_parts[0];
                    $user = get_user_by('login', $username);
                    if ($user) {
                        $current_user_id = $user->ID;
                        // Set current user for WordPress session
                        wp_set_current_user($current_user_id);
                    }
                }
            }
        }

        if (!$current_user_id ||
            ($current_user_id != $booking['bookings_author'] &&
             $current_user_id != $booking['owner_id'])) {
            wp_die('You are not authorized to access this ticket');
        }

        // Check if booking is paid
        // Case 1: status = 'paid' directly (no order involved)
        // Case 2: status = 'confirmed' and order exists with status completed/processing
        $is_paid = false;

        if ($booking['status'] === 'paid') {
            // Direct paid status (no WooCommerce order)
            $is_paid = true;
        } elseif ($booking['status'] === 'confirmed' && !empty($booking['order_id'])) {
            // Has order - check order status
            $order = wc_get_order($booking['order_id']);
            if ($order) {
                $order_status = $order->get_status();
                $is_paid = in_array($order_status, array('completed', 'processing'));
            }
        }

        if (!$is_paid) {
            wp_die('This booking is not paid yet');
        }

        // Check if ticket option is enabled in Listeo settings
        $ticket_status = get_option('listeo_ticket_status');
        if (!$ticket_status) {
            wp_die('Ticket feature is not enabled. Please contact the administrator.');
        }

        // Ensure phpqrcode library is loaded
        if (!defined('LISTEO_PLUGIN_DIR')) {
            define('LISTEO_PLUGIN_DIR', WP_PLUGIN_DIR . '/listeo-core/');
        }

        if (!class_exists('QRcode')) {
            $qrlib_path = LISTEO_PLUGIN_DIR . 'lib/phpqrcode/qrlib.php';
            if (file_exists($qrlib_path)) {
                require_once $qrlib_path;
            } else {
                wp_die('QR Code library not found at: ' . $qrlib_path);
            }
        }

        // Use Listeo's native QR system class
        if (!class_exists('Listeo_Core_QR')) {
            wp_die('Listeo QR system not available');
        }

        // Clear any previous output buffers to prevent JSON wrapping
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set proper HTML content type header before rendering
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        $listeo_qr = new Listeo_Core_QR();

        // Use Listeo's get_ticket() method - it handles everything internally
        // This ensures exact same output as admin (ticket code, QR code, HTML)
        $listeo_qr->get_ticket($booking_id);
    }

    public function cancel_booking($request)
    {
        global $wpdb;

        $body = json_decode($request->get_body(), true);
        $booking_id = isset($body['booking_id']) ? intval($body['booking_id']) : 0;

        if (!$booking_id) {
            return new WP_Error('invalid_booking', 'Invalid booking ID', array('status' => 400));
        }

        // Get booking details
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bookings_calendar WHERE id = %d",
                $booking_id
            ),
            ARRAY_A
        );

        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found', array('status' => 404));
        }

        // Update booking status to cancelled
        $wpdb->update(
            $wpdb->prefix . 'bookings_calendar',
            array('status' => 'cancelled'),
            array('id' => $booking_id),
            array('%s'),
            array('%d')
        );

        // If there's an order associated, optionally cancel it
        if (!empty($booking['order_id'])) {
            $order = wc_get_order($booking['order_id']);
            if ($order && in_array($order->get_status(), array('pending', 'on-hold'))) {
                $order->update_status('cancelled', 'Booking cancelled by user.');
            }
        }

        return array(
            'success' => true,
            'message' => 'Booking cancelled successfully'
        );
    }

    public function delete_booking($request)
    {
        global $wpdb;

        $body = json_decode($request->get_body(), true);
        $booking_id = isset($body['booking_id']) ? intval($body['booking_id']) : 0;

        if (!$booking_id) {
            return new WP_Error('invalid_booking', 'Invalid booking ID', array('status' => 400));
        }

        // Get booking details to check status
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bookings_calendar WHERE id = %d",
                $booking_id
            ),
            ARRAY_A
        );

        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found', array('status' => 404));
        }

        // Only allow deletion of expired bookings
        if ($booking['status'] !== 'expired') {
            return new WP_Error('invalid_status', 'Only expired bookings can be deleted', array('status' => 400));
        }

        // Delete the booking
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'bookings_calendar',
            array('id' => $booking_id),
            array('%d')
        );

        if ($deleted === false) {
            return new WP_Error('delete_failed', 'Failed to delete booking', array('status' => 500));
        }

        return array(
            'success' => true,
            'message' => 'Booking deleted successfully'
        );
    }

    public function update_slots($request)
    {
        $listing_id = $request['listing_id'];

        $date_end = $request['date_start'];
        $date_start = $request['date_end'];

        $dayofweek = date('w', strtotime($date_start));
        $un_slots = get_post_meta($listing_id, '_slots', true);
        $_slots = Listeo_Core_Bookings_Calendar::get_slots_from_meta($listing_id);
        if ($dayofweek == 0)
        {
            $actual_day = 6;
        }
        else
        {
            $actual_day = $dayofweek - 1;
        }
        $_slots_for_day = $_slots[$actual_day];
        $new_slots = array();

        if (is_array($_slots_for_day) && !empty($_slots_for_day))
        {
            foreach ($_slots_for_day as $key => $slot)
            {
                $places = explode('|', $slot);
                $free_places = $places[1];
                $hours = explode(' - ', $places[0]);
                $hour_start = date("H:i:s", strtotime($hours[0]));
                $hour_end = date("H:i:s", strtotime($hours[1]));
                $date_start = $date_start . ' ' . $hour_start;
                $date_end = $date_end . ' ' . $hour_end;

                $result = Listeo_Core_Bookings_Calendar::get_slots_bookings($date_start, $date_end, array(
                    'listing_id' => $listing_id,
                    'type' => 'reservation'
                ));
                $reservations_amount = count($result);
                $free_places -= $reservations_amount;
                if ($free_places > 0)
                {
                    $new_slots[] = $places[0] . '|' . $free_places;
                }
            }
        }
        return $new_slots;
    }

    static function insert_booking($args)
    {

        global $wpdb;

        $insert_data = array(
            'bookings_author' => $args['bookings_author'],
            'owner_id' => $args['owner_id'],
            'listing_id' => $args['listing_id'],
            'date_start' => date("Y-m-d H:i:s", strtotime($args['date_start'])) ,
            'date_end' => date("Y-m-d H:i:s", strtotime($args['date_end'])) ,
            'comment' => $args['comment'],
            'type' => $args['type'],
            'created' => current_time('mysql')
        );

        if (isset($args['order_id'])) $insert_data['order_id'] = $args['order_id'];
        if (isset($args['expiring'])) $insert_data['expiring'] = $args['expiring'];
        if (isset($args['status'])) $insert_data['status'] = $args['status'];
        if (isset($args['price'])) $insert_data['price'] = $args['price'];

        $wpdb->insert($wpdb->prefix . 'bookings_calendar', $insert_data);

        return $wpdb->insert_id;

    }

    public function booking($object)
    {
        $_user_id = $object['user_id'];
        $user_info = get_user_meta($_user_id);
        $u_data = get_user_by('id', $_user_id);

        $first_name = isset($user_info['billing_first_name']) ? $user_info['billing_first_name'][0] : $user_info['first_name'][0];
        $last_name = isset($user_info['billing_last_name']) ? $user_info['billing_last_name'][0] : $user_info['last_name'][0];
        $email = isset($user_info['billing_email']) ? $user_info['billing_email'][0] : $u_data->user_email;
        $billing_address_1 = (isset($user_info['billing_address_1'][0])) ? $user_info['billing_address_1'][0] : false;
        $billing_postcode = (isset($user_info['billing_postcode'][0])) ? $user_info['billing_postcode'][0] : false;
        $billing_city = (isset($user_info['billing_city'][0])) ? $user_info['billing_city'][0] : false;
        $billing_country = (isset($user_info['billing_country'][0])) ? $user_info['billing_country'][0] : false;
    	$billing_phone = isset($user_info['billing_phone'][0]) ? $user_info['billing_phone'][0] : false;

        $data = json_decode($object['value']);
        $date_start = isset($data->date_start) ? $data->date_start : null;
        $date_end = isset($data->date_end) ? $data->date_end : null;
        $adults = isset($data->adults) ? $data->adults : null;
        $tickets = isset($data->tickets) ? $data->tickets : null;
        $listing_id = isset($data->listing_id) ? $data->listing_id : null;
        $slot = isset($data->slot) ? $data->slot : null;
        $_hour_end = isset($data->_hour_end) ? $data->_hour_end : null;
        $_hour = isset($data->_hour) ? $data->_hour : null;
        $services = isset($data->services) ? $data->services : false;
        $services = is_array($services) || is_object($services)
        ? array_values(array_filter(array_map(
            fn($item) => is_object($item)
                ? ['service' => sanitize_title($item->service), 'value' => $item->value]
                : (is_array($item) && isset($item['service'], $item['value'])
                    ? ['service' => sanitize_title($item['service']), 'value' => $item['value']]
                    : null),
            is_object($services) ? (array)$services : $services
        )))
        : [];
        $comment_services = false;
        $coupon = isset($data->coupon) ? $data->coupon : null;
        $message = '';
        $calculate_price = function($listing_id, $date_start, $date_end, $multiply, $services, $coupon) use ($data) {
            try {
                $args = array(
                    $listing_id,
                    $date_start,
                    $date_end,
                    $multiply,
                    isset($data->children) ? (int)$data->children : 0,
                    isset($data->animals) ? (int)$data->animals : 0,
                    $services,
                    $coupon
                );
                return call_user_func_array(
                    array('Listeo_Core_Bookings_Calendar', 'calculate_price'),
                    $args
                );
            } catch (Error $e) {
                return Listeo_Core_Bookings_Calendar::calculate_price(
                    $listing_id,
                    $date_start,
                    $date_end,
                    $multiply,
                    $services,
                    $coupon
                );
            }
        };

        if (!empty($services))
        {

            $currency_abbr = get_option('listeo_currency');
            $currency_postion = get_option('listeo_currency_postion');
            $currency_symbol = Listeo_Core_Listing::get_currency_symbol($currency_abbr);
            $comment_services = array();
            $bookable_services = listeo_get_bookable_services($listing_id);

            $firstDay = new DateTime($date_start);
            $lastDay = new DateTime($date_start . '23:59:59');

            $days_between = $lastDay->diff($firstDay)->format("%a");
            $days_count = ($days_between == 0) ? 1 : $days_between;

            //since 1.3 change comment_service to json
            $countable = array_column($services, 'value');
            if (isset($adults))
            {
                $guests = $adults;
            }
            else if (isset($tickets))
            {
                $guests = $tickets;
            }
            else
            {
                $guests = 1;
            }
            $i = 0;
            foreach ($bookable_services as $key => $service)
            {

                if (in_array(sanitize_title($service['name']), array_column($services, 'service')))
                {
                    try {
                        $price = listeo_calculate_service_price(
                            $service,
                            $adults,
                            isset($data->children) ? (int)$data->children : 0,
                            isset($service['children_discount']) ? $service['children_discount'] : 0,
                            $days_count,
                            $countable[$i]
                        );
                    } catch (Error $e) {
                        $price = listeo_calculate_service_price(
                            $service,
                            $adults,
                            $days_count,
                            $countable[$i]
                        );
                    }

                    $comment_services[] = array(
                        'service' => $service,
                        'guests' => $adults,
                        'days' => $days_count,
                        'countable' => $countable[$i],
                        'price' => $price
                    );

                    $i++;
                }

            }

        }
        $listing_meta = get_post_meta($listing_id, '', true);
        $instant_booking = get_post_meta($listing_id, '_instant_booking', true);

        if (get_transient('listeo_last_booking' . $_user_id) == $listing_id . ' ' . $date_start . ' ' . $date_end)
        {
            $message = 'booked';
            return $message;
        }

        set_transient('listeo_last_booking' . $_user_id, $listing_id . ' ' . $date_start . ' ' . $date_end, 60 * 15);

        $listing_meta = get_post_meta($listing_id, '', true);

        $listing_owner = get_post_field('post_author', $listing_id);
        $listing_address = get_post_meta($listing_id, '_address', true);

        switch ($listing_meta['_listing_type'][0])
        {
            case 'event':
                $comment = array(
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $billing_phone,
                    'message' => $object['message'],
                    'tickets' => $tickets,
                    'service' => $comment_services,
                    'booking_location' => $listing_address,
                    'billing_address_1' => $billing_address_1,
                    'billing_postcode' => $billing_postcode,
                    'billing_city' => $billing_city,
                    'billing_country' => $billing_country
                );
                // Normalize boolean false values to empty strings for mobile client consistency
                foreach ($comment as $key => $value) {
                    if ($value === false) {
                        $comment[$key] = '';
                    }
                }

                $booking_id = self::insert_booking(array(
                    'owner_id' => $listing_owner,
                    'bookings_author' => $_user_id,
                    'listing_id' => $listing_id,
                    'date_start' => $date_start,
                    'date_end' => $date_start,
                    'comment' => json_encode($comment),
                    'type' => 'reservation',
                    'price' => $calculate_price($listing_id, $date_start, $date_end, $tickets, $services, $coupon),
                ));

                $already_sold_tickets = (int)get_post_meta($listing_id, '_event_tickets_sold', true);
                $sold_now = $already_sold_tickets + $tickets;
                update_post_meta($listing_id, '_event_tickets_sold', $sold_now);

                $status = apply_filters('listeo_event_default_status', 'waiting');
                if ($instant_booking == 'check_on' || $instant_booking == 'on')
                {
                    $status = 'confirmed';
                }
                $changed_status = Listeo_Core_Bookings_Calendar::set_booking_status($booking_id, $status);
            break;
            case 'rental':
                // get default status
                $status = apply_filters('listeo_rental_default_status', 'waiting');
                // count free places
                $free_places = Listeo_Core_Bookings_Calendar::count_free_places($listing_id, $date_start, $date_end);
                if ($free_places > 0)
                {
                    $count_per_guest = get_post_meta($listing_id, "_count_per_guest", true);
                    if ($count_per_guest)
                    {
                        $multiply = isset($adults) ? $adults : 1;
                        $price = $calculate_price($listing_id, $date_start, $date_end, $multiply, $services, $coupon);
                    }
                    else
                    {
                        $price = $calculate_price($listing_id, $date_start, $date_end, 1, $services, $coupon);
                    }

                    $comment_arr = array(
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'email' => $email,
                        'phone' => $billing_phone,
                        'message' => $object['message'],
                        'adults' => $adults,
                        'service' => $comment_services,
                        'booking_location' => $listing_address,
                        'billing_address_1' => $billing_address_1,
                        'billing_postcode' => $billing_postcode,
                        'billing_city' => $billing_city,
                        'billing_country' => $billing_country
                    );
                    if (is_iterable($comment_arr)) {
                    foreach ($comment_arr as $key => $value) {
                        if ($value === false) {
                            $comment_arr[$key] = '';
                        }
                    }
                    }
                    $booking_id = self::insert_booking(array(
                        'owner_id' => $listing_owner,
                        'listing_id' => $listing_id,
                        'bookings_author' => $_user_id,
                        'date_start' => $date_start,
                        'date_end' => $date_end,
                        'comment' => json_encode($comment_arr),
                        'type' => 'reservation',
                        'price' => $price,
                    ));
                    $status = apply_filters('listeo_event_default_status', 'waiting');
                    if ($instant_booking == 'check_on' || $instant_booking == 'on')
                    {
                        $status = 'confirmed';
                    }
                    $changed_status = Listeo_Core_Bookings_Calendar::set_booking_status($booking_id, $status);

                }
                else
                {
                    $message = 'unavailable';
                }
                break;
            case 'service':
                $status = apply_filters('listeo_service_default_status', 'waiting');
                if ($instant_booking == 'check_on' || $instant_booking == 'on')
                {
                    $status = 'confirmed';
                }
                if (!isset($slot))
                {
                    $count_per_guest = get_post_meta($listing_id, "_count_per_guest", true);
                    if ($count_per_guest)
                    {
                        $multiply = isset($adults) ? $adults : 1;
                        $price = $calculate_price($listing_id, $date_start, $date_end, $multiply, $services, $coupon);
                    }
                    else
                    {
                        $price = $calculate_price($listing_id, $date_start, $date_end, 1, $services, $coupon);
                    }
                    $hour_end = (isset($_hour_end) && !empty($_hour_end)) ? $_hour_end : $_hour;
                    $comment_arr = array(
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'email' => $email,
                        'phone' => $billing_phone,
                        'adults' => $adults,
                        'message' => $object['message'],
                        'service' => $comment_services,
                        'booking_location' => $listing_address,
                        'billing_address_1' => $billing_address_1,
                        'billing_postcode' => $billing_postcode,
                        'billing_city' => $billing_city,
                        'billing_country' => $billing_country
                    );
                    if (is_iterable($comment_arr)) {
                    foreach ($comment_arr as $key => $value) {
                        if ($value === false) {
                            $comment_arr[$key] = '';
                        }
                    }
                }
                    $booking_id = self::insert_booking(array(
                        'bookings_author' => $_user_id,
                        'owner_id' => $listing_owner,
                        'listing_id' => $listing_id,
                        'date_start' => $date_start . ' ' . $_hour . ':00',
                        'date_end' => $date_end . ' ' . $hour_end . ':00',
                        'comment' => json_encode($comment_arr),
                        'type' => 'reservation',
                        'price' => $price,
                    ));

                    $changed_status = Listeo_Core_Bookings_Calendar::set_booking_status($booking_id, $status);

                }
                else
                {
                    $free_places = Listeo_Core_Bookings_Calendar::count_free_places($listing_id, $date_start, $date_end, json_encode($slot));
                    if ($free_places > 0)
                    {
                        $slot = is_array($slot) ? $slot : json_encode($slot);
                        $hours = explode(' - ', $slot[0]);
                        $hour_start = date("H:i:s", strtotime($hours[0]));
                        $hour_end = date("H:i:s", strtotime($hours[1]));
                        $count_per_guest = get_post_meta($listing_id, "_count_per_guest", true);

                        if ($count_per_guest)
                        {
                            $multiply = isset($adults) ? $adults : 1;
                            $price = $calculate_price($listing_id, $date_start, $date_end, $multiply, $services, $coupon);
                        }
                        else
                        {
                            $price = $calculate_price($listing_id, $date_start, $date_end, 1, $services, $coupon);
                        }

                        $comment_arr = array(
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'email' => $email,
                            'phone' => $billing_phone,
                            'adults' => $adults,
                            'message' => $object['message'],
                            'service' => $comment_services,
                            'billing_address_1' => $billing_address_1,
                            'billing_postcode' => $billing_postcode,
                            'billing_city' => $billing_city,
                            'billing_country' => $billing_country
                        );
                        if (is_iterable($comment_arr)) {
                        foreach ($comment_arr as $key => $value) {
                            if ($value === false) {
                                $comment_arr[$key] = '';
                            }
                        }
                        }
                        $booking_id = self::insert_booking(array(
                            'bookings_author' => $_user_id,
                            'owner_id' => $listing_owner,
                            'listing_id' => $listing_id,
                            'date_start' => $date_start . ' ' . $hour_start,
                            'date_end' => $date_end . ' ' . $hour_end,
                            'comment' => json_encode($comment_arr),
                            'type' => 'reservation',
                            'price' => $price,
                        ));

                        $status = apply_filters('listeo_service_slots_default_status', 'waiting');
                        if ($instant_booking == 'check_on' || $instant_booking == 'on')
                        {
                            $status = 'confirmed';
                        }

                        $changed_status = Listeo_Core_Bookings_Calendar::set_booking_status($booking_id, $status);

                    }
                    else
                    {
                        $message = 'unavailable';
                    }
                }
                break;
            }

            // when we have database problem with statuses
            if (!isset($changed_status))
            {
                $message = 'error';
            }

            switch ($status)
            {
                case 'waiting':
                    $message = 'waiting';
                break;
                case 'confirmed':
                    $message = 'confirmed';
                break;
                case 'cancelled':
                    $message = 'cancelled';
                break;
            }

            return $message;

        }

        public function get_post_gallery_images_listeo($object)
        {
            $results = [];
            $gallery = get_post_meta($object['id'], '_gallery', true);
            if ($gallery)
            {
                foreach ($gallery as $key => $val)
                {
                    if ($key)
                    {
                        $getVal = get_post_meta($key, '_wp_attached_file', true);
                        if (!empty($getVal))
                        {
                            $results[] = get_bloginfo('url') . '/wp-content/uploads/' . $getVal;
                        }
                    };
                }
            }
            return $results;
        }

        // End of Listeo theme functions


        function _rest_get_address_data( $object ) {
            //get the Post Id
            $listing_id = $object['id'];
            global $wpdb;
            $sql = "SELECT * FROM {$wpdb->prefix}mylisting_locations WHERE listing_id = %s"; //wp_it_job_details is job table
            $sql = $wpdb->prepare($sql, $listing_id);
            $results = $wpdb->get_row($sql);
                if($results) {
                    return $results->address;
            } else return ""; //return nothing
        }

        function _rest_get_lat_data( $object ) {
            //get the Post Id
            $listing_id = $object['id'];
            global $wpdb;
            $sql = "SELECT * FROM {$wpdb->prefix}mylisting_locations WHERE listing_id = %s"; //wp_it_job_details is job table
            $sql = $wpdb->prepare($sql, $listing_id);
            $results = $wpdb->get_row($sql);
                if($results) {
                    return $results->lat;
            } else return ""; //return nothing
        }

        function _rest_get_lng_data( $object ) {
            //get the Post Id
            $listing_id = $object['id'];
            global $wpdb;
            $sql = "SELECT * FROM {$wpdb->prefix}mylisting_locations WHERE listing_id = %s"; //wp_it_job_details is job table
            $sql = $wpdb->prepare($sql, $listing_id);
            $results = $wpdb->get_row($sql);
                if($results) {
                    return $results->lng;
            } else return ""; //return nothing
        }

        // Blog section
        public function get_blog_image_feature($object)
        {
            $image_feature = wp_get_attachment_image_src($object['featured_media'], 'full');
            return is_array($image_feature) && count($image_feature) > 0 ? $image_feature[0] : null;
        }

        public function get_blog_author_name($object)
        {
            $user = get_userdata($object['author']);
            return $user->display_name;
        }

        /* --- - MyListing - ---*/
        public function get_job_listing_by_tags($request)
        {
            $args = ['post_type' => 'job_listing', 'paged' => $request['page'] ? $request['page'] : 1, 'posts_per_page' => $request['limit'] ? $request['limit'] : 10, ];
            if ($request['tag'])
            {
                $args['tax_query'][] = array(
                    'taxonomy' => 'case27_job_listing_tags',
                    'field' => 'term_id',
                    'terms' => explode(',', $request['tag'])
                );
            }
            global $wpdb;
            $posts = query_posts($args);
            $data = array();
            $items = (array)($posts);
            // return $items;
            foreach ($items as $item):
                $itemdata = $this->prepare_item_for_response($item, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            endforeach;

            return new WP_REST_Response($data, 200);

        }

        function get_listing_tabs($request){
            $listing_id = $request['id'];
            $post = get_post($listing_id);
            $listing = MyListing\Src\Listing::get( $post );

            if ( ! $listing->type ) {
                return [];
            }

            $layout = $listing->type->get_layout();

            $blocks = [];
            foreach ((array) $layout['menu_items'] as $key => &$menu_item){
                if ($menu_item['page'] == 'main' || $menu_item['page'] == 'custom'){
                    if ( empty( $menu_item['layout'] ) ) {
                        $menu_item['layout'] = [];
                    }

                    if ( empty( $menu_item['sidebar'] ) ) {
                        $menu_item['sidebar'] = [];
                    }

                    if ( in_array( $menu_item['template'], ['two-columns', 'content-sidebar', 'sidebar-content'] ) ) {
                        $first_col = $menu_item['template'] === 'sidebar-content' ? 'sidebar' : 'layout';
                        $second_col = $first_col === 'layout' ? 'sidebar' : 'layout';

                        $menu_item[ 'layout' ] = array_merge( $menu_item[ $first_col ], $menu_item[ $second_col ] );
                    }

                    foreach ( $menu_item['layout'] as $block ){
                        if ( empty( $block['type'] ) ) {
                            $block['type'] = 'default';
                        }

                        if ( empty( $block['id'] ) ) {
                            $block['id'] = '';
                        }
                        $block->set_listing( $listing );

                        $block['type'] = $block->get_type();

                        $valid = true;
                        switch ($block['type']) {
                            case 'gallery':
                                if ( ! ( $field = $listing->get_field_object( $block->get_prop( 'show_field' ) ) ) ) {
                                    $valid = false;
                                    break;
                                }
                                $block['gallery'] = $field->get_value();
                                break;
                            case 'text':
                                if ( ! ( $listing->has_field( $block->get_prop( 'show_field' ) ) ) ) {
                                    $valid = false;
                                    break;
                                }
                                $field = $listing->get_field( $block['show_field'], true );
                                $block['text'] = $field->get_value();
                                break;
                            case 'table':
                            case 'accordion':
                                $rows = $block->get_formatted_rows( $listing );
                                if ( empty( $rows ) ) {
                                    $valid = false;
                                    break;
                                }
                                $block['rows'] = $rows;
                                break;
                            case 'tags':
                                $terms = $listing->get_field( 'tags' );
                                if ( empty( $terms ) || is_wp_error( $terms ) ) {
                                    $valid = false;
                                    break;
                                }
                                $block['tags'] = $terms;
                                break;
                            case 'categories':
                                $terms = $listing->get_field( 'category' );
                                if ( empty( $terms ) || is_wp_error( $terms ) ) {
                                    $valid = false;
                                    break;
                                }
                                $block['categories'] = $terms;
                                break;
                            case 'author':
                                $author = $listing->get_author();
                                if ( ! ( $author instanceof \MyListing\Src\User && $author->exists() ) ) {
                                    $valid = false;
                                    $block['author'] = null;
                                    break;
                                }else{
                                    $avatar = get_user_meta($author->ID, 'user_avatar', true);
                                    if (!isset($avatar) || $avatar == "" || is_bool($avatar)) {
                                        $avatar = get_avatar_url($author->ID);
                                    } else {
                                        $avatar = $avatar[0];
                                    }
                                    $block['author'] = array(
                                        "id" => $author->ID,
                                        "displayname" => $author->display_name,
                                        "firstname" => $author->user_firstname,
                                        "lastname" => $author->last_name,
                                        "nickname" => $author->nickname,
                                        "avatar" => $avatar,
                                    );
                                }
                                break;
                            case 'work_hours':
                                $work_hours = $listing->get_field( 'work_hours' ) ;
                                $schedule = new MyListing\Src\Work_Hours( $work_hours );
                                if ( ! $work_hours || $schedule->is_empty() ) {
                                    $valid = false;
                                    break;
                                }
                                $block['work_hours'] = $work_hours ;
                                break;
                            case 'video':
                                $video_url = $listing->get_field( $block->get_prop( 'show_field' ) );
                                $video = \MyListing\Helpers::get_video_embed_details( $video_url );
                                if ( ! ( $video_url && $video ) ) {
                                    $valid = false;
                                    break;
                                }
                                $block['video'] = $video;
                                break;
                            case 'location':
                                $field = $listing->get_field_object( $block->get_prop( 'show_field' ) );
                                if ( ! $field || ! $field->get_value() ) {
                                    $valid = false;
                                    break;
                                }
                                $block['locations'] = $field->get_value();
                                break;
                            default:
                                break;
                        }

                        if ($valid) {
                            $blocks[] = $block;
                        }
                    }

                }
            }

            return $blocks;
        }

        function _rest_get_address_lat_lng_data($object)
        {
            //get the Post Id
            $listing_id = $object['id'];
            global $wpdb;
            $sql = "SELECT * FROM {$wpdb->prefix}mylisting_locations WHERE listing_id = %s"; //wp_it_job_details is job table
            $sql = $wpdb->prepare($sql, $listing_id);
            $results = $wpdb->get_row($sql);
            $data = [];
            if ($results) {
                $data['address'] = $results->address;
                $data['lat'] = $results->lat;
                $data['lng'] = $results->lng;
            }
            return $data;
        }

        /* --- - ListingPro - ---*/
        public function get_post_gallery_images_listingPro($object)
        {
            $results = [];
            $gallery = get_post_meta($object['id'], 'gallery_image_ids', true);

            $gallery = explode(',', $gallery);
            if ($gallery)
            {
                foreach ($gallery as $value)
                {
                    $getVal = get_post_meta($value, '_wp_attached_file', true);

                    if (!empty($getVal))
                    {
                        $results[] = get_bloginfo('url') . '/wp-content/uploads/' . $getVal;
                    }
                }
            }

            return $results;
        }

        /*- --- - Listable - ---- */

        public function get_author_meta($object)
        {
            $user = get_user_meta($object['post_author']);
            if ($this->_isListingPro)
            {
                $user = get_user_meta($object['author']);
                $user = $user['first_name'][0];
            }
            return $user;

        }
        /* Meta Fields Rest API */
        /**
         * Get term meta images
         * @param $object
         * @param $field_name
         * @param $request
         * @return mixed
         */
        public function get_term_meta_image($object)
        {

            if ($this->_isListable)
            {
                $name = 'pix_term_image';
            }
            elseif ($this->_isListify)
            {
                $name = 'thumbnail_id';
            }
            elseif ($this->_isListingPro)
            {
                $name = 'lp_category_banner';
                return get_term_meta($object['id'], $name, true);
            }
            elseif ($this->_isListeo)
            {
                $name = '_cover';
                $image_id =  get_term_meta($object['id'], $name, true);
                return wp_get_original_image_url($image_id);
            }
            else
            {
                $name = 'image';
            }
            $term_meta_id = get_term_meta($object['id'], $name, true);
            return get_post_meta($term_meta_id, '_wp_attachment_metadata');
        }

        /**
         * Get comment rating
         * @param $object
         * @param $field_name
         * @param $request
         * @return array|bool
         */
        public function get_comments_ratings($object)
        {
            $meta_key = $commentKey = 'pixrating';

            if ($this->_isListify)
            {
                $meta_key = $commentKey = 'rating';
            }
            else if ($this->_isMyListing)
            {
                $meta_key = '_case27_ratings';
                $commentKey = '_case27_post_rating';
            }

            $post_id = isset($object[0]) ? $object[0] : '';
            $decimals = 1;

            if (empty($post_id))
            {
                $post_id = get_the_ID();
            }

            $comments = get_comments(array(
                'post_id' => $post_id,
                // 'meta_key' => $meta_key,
                'status' => 'approve'
            ));

            if (empty($comments))
            {
                return false;
            }

            $total = 0;
            foreach ($comments as $comment)
            {
                $current_rating = get_comment_meta($comment->comment_ID, $commentKey, true);
                $total = $total + (double)$current_rating;
            }

            $average = $total / count($comments);

            return ['totalReview' => count($comments) , 'totalRate' => number_format($average, $decimals) ];
    }

        public function get_reviews(WP_REST_Request $request)
        {
            $post_id = $request['id'];

            if (empty($post_id))
            {
                $post_id = get_the_ID();
            }
            $comments = get_comments(array(
                'post_id' => $post_id
            ));

            $results = [];
            if ($this->_isMyListing)
            {
                $commentKey = '_case27_post_rating';
            }
            else if ($this->_isListeo)
            {
                $commentKey = 'listeo-rating';
            }
            foreach ($comments as & $item)
            {
                $status = wp_get_comment_status($item->comment_ID);
                $countRating = get_comment_meta($item->comment_ID, $commentKey, true);
                $current_rating = get_comment_meta($item->comment_ID, $commentKey, true);

                // Get review images
                $gallery_images = [];
                if ($this->_isListeo) {
                    // Listeo stores attachment IDs in 'listeo-attachment-id' meta
                    $attachment_ids = get_comment_meta($item->comment_ID, 'listeo-attachment-id', true);

                    if (!empty($attachment_ids)) {
                        // Can be single ID or comma-separated IDs
                        $ids = is_array($attachment_ids) ? $attachment_ids : explode(',', $attachment_ids);

                        foreach ($ids as $attachment_id) {
                            $attachment_id = trim($attachment_id);
                            if (is_numeric($attachment_id)) {
                                $url = wp_get_attachment_url($attachment_id);
                                if ($url) {
                                    $gallery_images[] = $url;
                                }
                            }
                        }
                    }
                } else if ($this->_isMyListing) {
                    $images = get_comment_meta($item->comment_ID, '_case27_review_images', true);
                    if (is_array($images)) {
                        $gallery_images = $images;
                    }
                }

                // Get author avatar
                $author_avatar = '';
                if (!empty($item->user_id)) {
                    $avatar = get_user_meta($item->user_id, 'user_avatar', true);
                    $author_avatar = (!empty($avatar) && !is_bool($avatar)) ? $avatar[0] : get_avatar_url($item->user_id);
                } else {
                    $author_avatar = get_avatar_url($item->comment_author_email);
                }

                $results[] = [
                    "id" => $item->comment_ID,
                    "rating" => $countRating,
                    "status" => $status,
                    "author_name" => $item->comment_author,
                    "date" => $item->comment_date,
                    "content" => $item->comment_content,
                    "author_email" => $item->comment_author_email,
                    "author_avatar" => $author_avatar,
                    "gallery_images" => $gallery_images
                ];
            }
            return $results;
        }

        public function submitReview(WP_REST_Request $request)
        {
            if ($this->_isListingPro)
            {
                // Override comment status if needed
                // Respect status from mobile app
                // 'approved' -> 'publish', 'hold' -> 'pending'
                $post_status = 'publish'; // default
                if (isset($request['status']) && !empty($request['status'])) {
                    $post_status = ($request['status'] === 'approved') ? 'publish' : 'pending';
                }

                $post_information = array(
                    'post_author' => $request['post_author'],
                    'post_title' => $request['post_title'],
                    'post_content' => $request['post_content'],
                    'post_type' => 'lp-reviews',
                    'post_status' => $post_status
                );
                $postID = wp_insert_post($post_information);

                listing_set_metabox('rating', (double)$request['rating'], $postID);
                listing_set_metabox('listing_id', $request['listing_id'], $postID);
                listingpro_set_listing_ratings($postID, $request['listing_id'], $request['rating'], 'add');
                listingpro_total_reviews_add($request['listing_id']);

                // Handle review images from mobile app
                if (isset($request['images']) && !empty($request['images'])) {
                    $images = explode(',', $request['images']);
                    $uploaded_image_ids = array();

                    foreach ($images as $index => $base64_image) {
                        if (empty($base64_image)) continue;

                        try {
                            $attachment_id = upload_image_from_mobile($base64_image, $index, $request['post_author']);
                            if ($attachment_id) {
                                $uploaded_image_ids[] = $attachment_id;
                            }
                        } catch (Exception $e) {}
                    }

                    if (!empty($uploaded_image_ids)) {
                        update_post_meta($postID, 'gallery_image_ids', implode(',', $uploaded_image_ids));
                    }
                }

                return 'Success';
            }

            if ($this->_isListeo || $this->_isMyListing)
            {
                $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
                if (isset($cookie) && $cookie != null) {
                    $user_id = validateCookieLogin($cookie);
                    if (is_wp_error($user_id)) {
                        return $user_id;
                    }
                    wp_set_current_user( $user_id );
                }
                $comment = wp_handle_comment_submission( wp_unslash( $_POST ) );

                // Override comment status if needed
                // Respect status from mobile app
                // 'approved' -> 'publish', 'hold' -> 'pending'
                if (!is_wp_error($comment) && isset($request['status']) && !empty($request['status'])) {
                    $desired_status = $request['status'];
                    if ($comment->comment_approved !== $desired_status) {
                        wp_set_comment_status($comment->comment_ID, $desired_status === 'approved' ? 'approve' : 'hold');
                    }
                }

                // Handle review images from mobile app
                if (!is_wp_error($comment) && isset($request['images']) && !empty($request['images'])) {
                    $images = explode(',', $request['images']);
                    $uploaded_images = array();

                    $user_id = wp_validate_auth_cookie($cookie, 'logged_in');

                    foreach ($images as $index => $base64_image) {
                        if (empty($base64_image)) continue;

                        try {
                            $attachment_id = upload_image_from_mobile($base64_image, $index, $user_id);
                            if ($attachment_id) {
                                $image_url = wp_get_attachment_url($attachment_id);
                                if ($image_url) {
                                    $uploaded_images[] = $image_url;
                                }
                            }
                        } catch (Exception $e) {}
                    }

                    // Save images to comment meta
                    if (!empty($uploaded_images)) {
                        if ($this->_isListeo) {
                            update_comment_meta($comment->comment_ID, 'listeo-review-images', $uploaded_images);
                            $attachment_ids = array();
                            foreach ($uploaded_images as $img_url) {
                                $attachment_id = attachment_url_to_postid($img_url);
                                if ($attachment_id) {
                                    $attachment_ids[] = $attachment_id;
                                }
                            }
                            if (!empty($attachment_ids)) {
                                update_comment_meta($comment->comment_ID, 'listeo-attachment-id', implode(',', $attachment_ids));
                            }
                        } elseif ($this->_isMyListing) {
                            update_comment_meta($comment->comment_ID, '_case27_review_images', $uploaded_images);
                        }
                    }
                }

                return $comment;
            }

            return 'Failed';
        }

        /**
         * Get meta for api
         * @param $object
         * @return mixed
         */
        public function get_post_meta_for_api($object)
        {
            $post_id = $object['id'];
            $meta = get_post_meta($post_id);
            foreach ($meta as $k => $item):
                $meta[$k] = get_post_meta($post_id, $k, true);
            endforeach;
            if($this->_isMyListing){
                $meta['_job_description'] = get_the_content($post_id);
                $listing_type = $meta['_case27_listing_type'];
                $listing_type = \MyListing\Src\Listing_Type::get_by_name( $listing_type );
                $meta['_case27_listing_type_name'] = $listing_type->get_name();
            }

            if (array_key_exists('_menu', $meta)) {
                $meta['_menu'] = array_map(function($item){
                    if (isset($item['menu_elements']) && !empty($item['menu_elements'])) {
                        $item['menu_elements'] = array_map(function($element){
                            if (isset($element['cover']) && !empty($element['cover'])) {
                                $image = wp_get_attachment_image_src($element['cover'], 'listeo-gallery');
								$thumb = wp_get_attachment_image_src($element['cover'], 'thumbnail');
                                $element['image'] = !empty($image) ? $image[0] : null;
                                $element['thumb'] = !empty($thumb) ? $thumb[0] : null;
                            }
                            return $element;
                        }, $item['menu_elements']);
                    }
                    return $item;
                }, $meta['_menu']);
            }

            return $meta;
        }

        /**
         * Get rating
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public function get_rating(WP_REST_Request $request)
        {
            $name = 'pixrating';
            if ($this->_isListify)
            {
                $name = 'rating';
            }
            elseif ($this->_isMyListing)
            {
                $name = '_case27_post_rating';
            }
            $id = $request['id'];
            $countRating = get_comment_meta($id, $name, true);
            if ($countRating)
            {
                return new WP_REST_Response($countRating, 200);
            }
            return new WP_REST_Response(["status" => 404, "message" => "Not Found"], 404);
        }

        /**
         * Get cost for booking
         * @param $object
         * @param $field_name
         * @param $request
         * @return string|void
         */
        public function get_cost_for_booking($object)
        {
            $currency = get_option('woocommerce_currency');
            if ($currency)
            {
                $product_id = get_post_meta($object['id'], '_products', true);
                if ($this->_isListable)
                {
                    $_product = wc_get_product($product_id[0]);

                    if (!$_product) return;
                    return $currency . ' ' . $_product->get_price();
                }
                elseif ($this->_isListify)
                {
                    $_product = new WC_Product($product_id[0]);
                    return ['currency' => $currency, 'price' => $_product->get_price() , 'merge' => $currency . ' ' . $_product->get_price() ];
                }
                else
                {
                    $price = get_post_meta($object['id'], '_price-per-day', true);
                    return ['currency' => $currency, 'price' => $price, 'merge' => $currency != 'USD' ? $currency . ' ' . $price : $price . ' ' . $currency];
                }
            }
            return [];

        }

        public function protected_title_format()
        {
            return '%s';
        }

        public function prepare_item_for_response($post, $request)
        {
            $GLOBALS['post'] = $post;

            setup_postdata($post);

            $schema = $this->get_item_schema();
            $this->add_additional_fields_schema($schema);
            // Base fields for every post.
            $data = array();
            // echo "<pre>";
            // print_r($post);
            // echo "</pre>";
            // return;
            if (!empty($schema['properties']['id']))
            {
                $data['id'] = $post->ID;
            }

            if (!empty($schema['properties']['date']))
            {
                $data['date'] = $this->prepare_date_response($post->post_date_gmt, $post->post_date);
            }

            if (!empty($schema['properties']['date_gmt']))
            {
                // For drafts, `post_date_gmt` may not be set, indicating that the
                // date of the draft should be updated each time it is saved (see
                // #38883).  In this case, shim the value based on the `post_date`
                // field with the site's timezone offset applied.
                if ('0000-00-00 00:00:00' === $post->post_date_gmt)
                {
                    $post_date_gmt = get_gmt_from_date($post->post_date);
                }
                else
                {
                    $post_date_gmt = $post->post_date_gmt;
                }
                $data['date_gmt'] = $this->prepare_date_response($post_date_gmt);
            }

            if (!empty($schema['properties']['guid']))
            {
                $data['guid'] = array(
                    /** This filter is documented in wp-includes/post-template.php */
                    'rendered' => apply_filters('get_the_guid', $post->guid, $post->ID) ,
                    'raw' => $post->guid,
                );
            }

            if (!empty($schema['properties']['modified']))
            {
                $data['modified'] = $this->prepare_date_response($post->post_modified_gmt, $post->post_modified);
            }

            if (!empty($schema['properties']['modified_gmt']))
            {
                // For drafts, `post_modified_gmt` may not be set (see
                // `post_date_gmt` comments above).  In this case, shim the value
                // based on the `post_modified` field with the site's timezone
                // offset applied.
                if ('0000-00-00 00:00:00' === $post->post_modified_gmt)
                {
                    $post_modified_gmt = date('Y-m-d H:i:s', strtotime($post->post_modified) - (get_option('gmt_offset') * 3600));
                }
                else
                {
                    $post_modified_gmt = $post->post_modified_gmt;
                }
                $data['modified_gmt'] = $this->prepare_date_response($post_modified_gmt);
            }

            if (!empty($schema['properties']['password']))
            {
                $data['password'] = $post->post_password;
            }

            if (!empty($post->distance))
            {

                $data['distance'] = $post->distance;
            }

            $data['listing_data'] = $this->get_post_meta_for_api($data);
            if (!empty($schema['properties']['slug']))
            {
                $data['slug'] = $post->post_name;
            }

            if (!empty($schema['properties']['status']))
            {
                $data['status'] = $post->post_status;
            }

            if (!empty($schema['properties']['type']))
            {
                $data['type'] = $post->post_type;
            }

            if (!empty($schema['properties']['link']))
            {
                $data['link'] = get_permalink($post->ID);
            }

            if (!empty($schema['properties']['title']))
            {

                add_filter('protected_title_format', array(
                    $this,
                    'protected_title_format'
                ));

                $data['title'] = array(
                    'raw' => $post->post_title,
                    'rendered' => get_the_title($post->ID) ,
                );

                remove_filter('protected_title_format', array(
                    $this,
                    'protected_title_format'
                ));
            }
            else
            {
                // case for this is listing pro
                $data['title'] = array(
                    'raw' => $post->post_title,
                    'rendered' => get_the_title($post->ID) ,
                );
            }

            if ($this->_isListeo)
            {
                $gallery = get_post_meta($post->ID, '_gallery', true);
                if ($gallery)
                {
                    foreach ($gallery as $key => $val)
                    {
                        if ($key)
                        {
                            $getVal = get_post_meta($key, '_wp_attached_file', true);
                            if (!empty($getVal))
                            {
                                $results[] = get_bloginfo('url') . '/wp-content/uploads/' . $getVal;
                            }
                        };
                    }
                }
                $data['gallery_images'] = $results;
            }

            if($this->_isListingPro){
                $gallery = get_post_meta($post->ID, 'gallery_image_ids', true);

                $gallery = explode(',', $gallery);
                if ($gallery)
                {
                    foreach ($gallery as $value)
                    {
                        $getVal = get_post_meta($value, '_wp_attached_file', true);

                        if (!empty($getVal))
                        {
                            $results[] = get_bloginfo('url') . '/wp-content/uploads/' . $getVal;
                        }
                    }
                }
                $data['gallery_images'] = $results;
            }

            if ($this->_isMyListing) {
                if (!empty($schema['properties']['id'])) {
                    $location = $this->_rest_get_address_lat_lng_data($data);
                    $data['newaddress'] = $location['address'];
                    $data['newlat'] = $location['lat'];
                    $data['newlng'] = $location['lng'];
                }
            }

            $has_password_filter = false;

            if ($this->can_access_password_content($post, $request))
            {
                // Allow access to the post, permissions already checked before.
                add_filter('post_password_required', '__return_false');

                $has_password_filter = true;
            }

            if (!empty($schema['properties']['content']))
            {
                $data['content'] = array(
                    'raw' => $post->post_content,
                    /** This filter is documented in wp-includes/post-template.php */
                    'rendered' => post_password_required($post) ? '' : apply_filters('the_content', $post->post_content) ,
                    'protected' => (bool)$post->post_password,
                );
            }
            else
            {
                // case for this is a listing pro
                $data['content'] = array(
                    'raw' => $post->post_content,
                    /** This filter is documented in wp-includes/post-template.php */
                    'rendered' => post_password_required($post) ? '' : apply_filters('the_content', $post->post_content) ,
                    'protected' => (bool)$post->post_password,
                );
            }

            if (!empty($schema['properties']['excerpt']))
            {
                /** This filter is documented in wp-includes/post-template.php */
                $excerpt = apply_filters('the_excerpt', apply_filters('get_the_excerpt', $post->post_excerpt, $post));
                $data['excerpt'] = array(
                    'raw' => $post->post_excerpt,
                    'rendered' => post_password_required($post) ? '' : $excerpt,
                    'protected' => (bool)$post->post_password,
                );
            }

            if ($has_password_filter)
            {
                // Reset filter.
                remove_filter('post_password_required', '__return_false');
            }

            if (!empty($schema['properties']['author']))
            {
                $data['author'] = (int)$post->post_author;
            }

            $image = wp_get_attachment_image_src((int)get_post_thumbnail_id($post->ID));
            $data['featured_image'] = $image[0];

            if (!empty($schema['properties']['parent']))
            {
                $data['parent'] = (int)$post->post_parent;
            }

            if (!empty($schema['properties']['menu_order']))
            {
                $data['menu_order'] = (int)$post->menu_order;
            }

            if (!empty($schema['properties']['comment_status']))
            {
                $data['comment_status'] = $post->comment_status;
            }

            if (!empty($schema['properties']['ping_status']))
            {
                $data['ping_status'] = $post->ping_status;
            }

            if (!empty($schema['properties']['sticky']))
            {
                $data['sticky'] = is_sticky($post->ID);
            }

            if (!empty($schema['properties']['template']))
            {
                if ($template = get_page_template_slug($post->ID))
                {
                    $data['template'] = $template;
                }
                else
                {
                    $data['template'] = '';
                }
            }

            if (!empty($schema['properties']['format']))
            {
                $data['format'] = get_post_format($post->ID);

                // Fill in blank post format.
                if (empty($data['format']))
                {
                    $data['format'] = 'standard';
                }
            }

            if (!empty($schema['properties']['meta']))
            {
                $data['meta'] = $this
                    ->meta
                    ->get_value($post->ID, $request);

            }

            $taxonomies = wp_list_filter(get_object_taxonomies($this->post_type, 'objects') , array(
                'show_in_rest' => true
            ));

            foreach ($taxonomies as $taxonomy)
            {
                $base = !empty($taxonomy->rest_base) ? $taxonomy->rest_base : $taxonomy->name;

                if (!empty($schema['properties'][$base]))
                {
                    $terms = get_the_terms($post, $taxonomy->name);
                    $data[$base] = $terms ? array_values(wp_list_pluck($terms, 'term_id')) : array();
                }
            }

            $context = !empty($request['context']) ? $request['context'] : 'view';
            $data = $this->add_additional_fields_to_object($data, $request);
            $data = $this->filter_response_by_context($data, $context);

            // Wrap the data in a response object.
            $response = rest_ensure_response($data);

            $response->add_links($this->prepare_links($post));

            /**
             * Filters the post data for a response.
             *
             * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
             *
             * @since 4.7.0
             *
             * @param WP_REST_Response $response The response object.
             * @param WP_Post          $post     Post object.
             * @param WP_REST_Request  $request  Request object.
             */
            return apply_filters("rest_prepare_job_listing", $response, $post, $request);
        }

        public function get_pure_taxonomies($object)
        {
            if (empty($object['id'])) {
                return [];
            }

            $post_id = $object['id'];

            if ($this->_isListeo) {
                $taxonomies = ['listing_category', 'region', 'listing_feature'];
            } elseif ($this->_isListingPro) {
                $taxonomies = ['listing-category', 'location', 'list-tags'];
            } elseif ($this->_isMyListing) {
                $taxonomies = ['job_listing_category', 'region', 'case27_job_listing_tags'];
            } else {
                $taxonomies = ['listing_category', 'region', 'listing_feature'];
            }

            $result = [];

            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy);
                $result[$taxonomy] = is_array($terms)
                    ? array_map(function ($term) {
                        return [
                            'term_id'            => $term->term_id,
                            'name'               => $term->name,
                            'slug'               => $term->slug,
                            'term_group'         => $term->term_group,
                            'term_taxonomy_id'   => $term->term_taxonomy_id,
                            'taxonomy'           => $term->taxonomy,
                            'description'        => $term->description,
                            'parent'             => $term->parent,
                            'count'              => $term->count,
                            'filter'             => 'raw',
                        ];
                    }, $terms)
                    : [];
            }

            return $result;
        }

        /**
         * Prepare a response for inserting into a collection.
         *
         * @param WP_REST_Response $response Response object.
         * @return array Response data, ready for insertion into collection data.
         */

        public function prepare_response_for_collection($response)
        {
            if (!($response instanceof WP_REST_Response))
            {
                return $response;
            }

            $data = (array)$response->get_data();
            $server = rest_get_server();

            if (method_exists($server, 'get_compact_response_links'))
            {
                $links = call_user_func(array(
                    $server,
                    'get_compact_response_links'
                ) , $response);
            }
            else
            {
                $links = call_user_func(array(
                    $server,
                    'get_response_links'
                ) , $response);
            }

            if (!empty($links))
            {
                $data['_links'] = $links;
            }

            return $data;
        }

        public function get_job_listing_by_type($request)
        {
            $posts = query_posts(array(
                'meta_key' => '_case27_listing_type',
                'meta_value' => $request['type'],
                'post_type' => 'job_listing',
                'paged' => $request['page'],
                'posts_per_page' => $request['limit']
            ));

            $data = array();
            $items = (array)($posts);

            foreach ($items as $item):
                $itemdata = $this->prepare_item_for_response($item, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            endforeach;

            return new WP_REST_Response($data, 200);

        }

        public function custom_rest_listing_query($args, $request){
            $is_featured = $request['featured'] == 'true';
            if($is_featured == true){
             $args['meta_key'] = '_featured';
             $args['meta_query'] = array( 'key' => '_featured', 'value' => 'on', 'compare' => '=' );
            }
            return $args;
        }
    } // end Class


    // class For get case27_job_listing_tags for get All Tags to show in Filter Search
    class TemplateExtendMyListing extends WP_REST_Terms_Controller
    {
        protected $_template = 'listable'; // get_template
        protected $_listable = 'listable';
        protected $_listify = 'listify';
        protected $_myListing = 'my listing';

        protected $_customPostType = ['job_listing']; // all custom post type
        protected $_isListable, $_isListify, $_isMyListing;

        public function __construct()
        {
            global $wp_version;
            if(floatval($wp_version) < 6.0){
                /* extends from parent */
                parent::__construct('job_listing');
            }

            // Delay theme detection to init hook to avoid premature textdomain loading
            add_action('init', array($this, 'detect_theme'));

            // Register REST API endpoints
            add_action('rest_api_init', array(
                $this,
                'register_add_more_fields_to_rest_api_listing'
            ));
        }

        /**
         * Detect the theme and set related properties
         */
        public function detect_theme()
        {
            $isChild = strstr(strtolower(wp_get_theme()), "child");
            if ($isChild == 'child') {
                $string = explode(" ", wp_get_theme());
                if (count($string) > 1) {
                $this->_template = strtolower($string[0] . ' ' . $string[1]);
                } else {
                    $this->_template = strtolower($string[0]);
            }
            } else {
                $this->_template = strtolower(wp_get_theme());
            }

            $this->_isListable = $this->_template == $this->_listable ? 1 : 0;
            $this->_isListify = $this->_template == $this->_listify ? 1 : 0;
            $this->_isMyListing = $this->_template == $this->_myListing ? 1 : 0;
        }

        public function register_add_more_fields_to_rest_api_listing()
        {
            // case for myListing with job_listing_type
            if ($this->_isMyListing)
            {

                register_rest_route('listing/v1', 'case27_job_listing_tags', array(
                    'methods' => 'GET',
                    'callback' => array(
                        $this,
                        'get_case27_job_listing_tags'
                    ) ,
                    'permission_callback' => function () {
                        return true;
                    }
                ));
            }

        }
        public function prepare_item_for_response($item, $request)
        {

            $schema = $this->get_item_schema();
            $data = array();

            if (!empty($schema['properties']['id']))
            {
                $data['id'] = (int)$item->term_id;
            }

            if (!empty($schema['properties']['count']))
            {
                $data['count'] = (int)$item->count;
            }

            if (!empty($schema['properties']['description']))
            {
                $data['description'] = $item->description;
            }

            if (!empty($schema['properties']['link']))
            {
                $data['link'] = get_term_link($item);
            }

            if (!empty($schema['properties']['name']))
            {
                $data['name'] = $item->name;
            }

            if (!empty($schema['properties']['slug']))
            {
                $data['slug'] = $item->slug;
            }

            if (!empty($schema['properties']['taxonomy']))
            {
                $data['taxonomy'] = $item->taxonomy;
            }

            if (!empty($schema['properties']['parent']))
            {
                $data['parent'] = (int)$item->parent;
            }

            if (!empty($schema['properties']['meta']))
            {
                $data['meta'] = $this
                    ->meta
                    ->get_value($item->term_id, $request);
            }

            $context = !empty($request['context']) ? $request['context'] : 'view';
            // $data    = $this->add_additional_fields_to_object( $data, $request );
            $data = $this->filter_response_by_context($data, $context);

            $response = rest_ensure_response($data);

            $response->add_links($this->prepare_links($item));

            /**
             * Filters a term item returned from the API.
             *
             * The dynamic portion of the hook name, `$this->taxonomy`, refers to the taxonomy slug.
             *
             * Allows modification of the term data right before it is returned.
             *
             * @since 4.7.0
             *
             * @param WP_REST_Response  $response  The response object.
             * @param object            $item      The original term object.
             * @param WP_REST_Request   $request   Request used to generate the response.
             */
            return apply_filters("rest_prepare_case27_job_listing_tags", $response, $item, $request);
        }

        public function get_case27_job_listing_tags($request)
        {
            $posts = get_terms(['case27_job_listing_tags']);
            $data = array();
            $items = (array)($posts);
            foreach ($items as $item):
                $itemdata = $this->prepare_item_for_response($item, $request);
                $data[] = $itemdata;
            endforeach;
            $result = [];
            foreach ($data as $item):
                $result[] = $item->data;
            endforeach;

            return new WP_REST_Response($result, 200);
        }

    }

    class TemplateSearch extends FlutterTemplate
    {

        public function __construct()
        {
            // Initialize rest_api_init hook immediately
            add_action('rest_api_init', array(
                $this,
                'register_fields_for_search_advance'
            ));

            // Call parent constructor to ensure proper initialization
            parent::__construct();
        }

        /*
         * define for method for search
        */
        public function register_fields_for_search_advance()
        {
            /* get search by tags & categories for case myListing */
            register_rest_route('search/v1', $this->_isListingPro ? 'listing' : 'job_listing', array(
                'methods' => 'GET',
                'callback' => array(
                    $this,
                    'search_by_myParams'
                ) ,
                'args' => array(
                    'tags' => array(
                        'validate_callback' => function ($param, $request, $key)
                        {
                            return is_string($param);
                        }
                    ) ,
                    'categories' => array(
                        // 'validate_callback' => function($param, $request, $key) {
                        // 	return is_string( $param );
                        // }

                    ) ,
                    'type' => array(
                        // 'validate_callback' => function($param, $request, $key) {
                        // 	return is_string( $param );
                        // }

                    ) ,
                    'regions' => array(
                        // 'validate_callback' => function($param, $request, $key) {
                        // 	return is_string( $param );
                        // }

                    ) , // for listify
                    'typeListable' => array() , // for listable
                    'search' => array(
                        // 'validate_callback' => function($param, $request, $key) {
                        // 	return is_string( $param );
                        // }

                    ) ,
                    'author' => array(
                        // 'validate_callback' => function($param, $request, $key) {
                        // 	return is_string( $param );
                        // }

                    ) ,
                    'isGetLocate' => array(
                        'validate_callback' => function ($param, $request, $key)
                        {
                            return is_string($param);
                        }
                    ) ,
                    'lat' => array() ,
                    'long' => array() ,
                    'page' => array(
                        'validate_callback' => function ($param, $request, $key)
                        {
                            return is_numeric($param);
                        }
                    ) ,
                    'limit' => array(
                        'validate_callback' => function ($param, $request, $key)
                        {
                            return is_numeric($param);
                        }
                    ) ,
                ) ,
                'permission_callback' => function () {
                    return true;
                }
            ));

            if ($this->_isMyListing)
            {
                register_rest_route('searchExtends/v1', '/job_listing', array(
                    'methods' => 'GET',
                    'callback' => array(
                        $this,
                        'searchQuery'
                    ) ,
                    'args' => array(

                        'search' => array(
                            'validate_callback' => function ($param, $request, $key)
                            {
                                return is_string($param);
                            }
                        ) ,
                        'page' => array(
                            'validate_callback' => function ($param, $request, $key)
                            {
                                return is_numeric($param);
                            }
                        ) ,
                        'limit' => array(
                            'validate_callback' => function ($param, $request, $key)
                            {
                                return is_numeric($param);
                            }
                        ) ,
                    ) ,
                    'permission_callback' => function () {
                        return true;
                    }
                ));
            }
        }

        public function search_by_myParams($request)
        {
            $args = ['post_type' => $this->_customPostType, 'paged' => $request['page'] ? $request['page'] : 1, 'post_status' => 'publish', 'posts_per_page' => $request['limit'] ? $request['limit'] : 10, ];
            if ($request['tags'])
            {
                $args['tax_query'][] = array(
                    'taxonomy' => 'case27_job_listing_tags',
                    'field' => 'term_id',
                    'terms' => explode(',', $request['tags'])
                );
            }
            if ($request['categories'])
            {
                $args['tax_query'][] = array(
                    'taxonomy' => 'job_listing_category',
                    'field' => 'term_id',
                    'terms' => explode(',', $request['categories']) ,
                );

            }
            if ($request['type'])
            {
                $args['meta_query'] = [['key' => '_case27_listing_type', 'value' => $request['type'], 'compare' => 'LIKE', ]];
            }
            //case for listify
            if ($request['regions'])
            {
                $args['tax_query'][] = array(
                    'taxonomy' => 'job_listing_region',
                    'field' => 'term_id',
                    'terms' => explode(',', $request['regions']) ,
                );
            }
            //case for listable
            if ($request['typeListable'])
            {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => 'job_listing_type',
                        'field' => 'term_id',
                        'terms' => explode(',', $request['typeListable']) ,
                    ) ,
                );
            }
            if ($request['search'])
            {
                $args['s'] = $request['search'];
            }
            if ($request['author'])
            {
                $args['author'] = $request['author'];
            }

            global $wpdb;
            $posts = query_posts($args);

            if ($request['isGetLocate'])
            {
                $lat = $request['lat'];
                $long = $request['long'];
                $sql = "SELECT p.*, ";
                $sql .= " (6371 * acos (cos (radians(%f)) * cos(radians(t.lat)) * cos(radians(t.lng) - radians(%f)) + ";
                $sql .= "sin (radians(%f)) * sin(radians(t.lat)))) AS distance FROM (SELECT b.post_id, a.post_status, sum(if(";
                $sql .= "meta_key = 'geolocation_lat', meta_value, 0)) AS lat, sum(if(meta_key = 'geolocation_long', ";
                $sql .= "meta_value, 0)) AS lng FROM {$wpdb->prefix}posts a, {$wpdb->prefix}postmeta b WHERE a.id = b.post_id AND (";
                $sql .= "b.meta_key='geolocation_lat' OR b.meta_key='geolocation_long') AND a.post_status='publish' GROUP BY b.post_id) AS t INNER ";
                $sql .= "JOIN {$wpdb->prefix}posts as p on (p.ID=t.post_id)  ORDER BY distance LIMIT 30";

                $sql = $wpdb->prepare($sql,$lat,$long,$lat);
                $posts = $wpdb->get_results($sql, OBJECT);
                if ($wpdb->last_error)
                {
                    return 'Error: ' . $wpdb->last_error;
                }
                // return $posts;

            }

            $data = array();
            $items = (array)($posts);
            // return $items;
            if (count($items) > 0)
            {
                foreach ($items as $item):
                    $itemdata = $this->prepare_item_for_response($item, $request);
                    $data[] = $this->prepare_response_for_collection($itemdata);
                endforeach;
            }

            return new WP_REST_Response($data, 200);
        }

        public function searchQuery($request)
        {
            $args = ['post_type' => 'job_listing', 'paged' => $request['page'] ? $request['page'] : 1, 'post_status' => 'publish', 'posts_per_page' => $request['limit'] ? $request['limit'] : 10, ];
            if ($request['search'])
            {
                $args['s'] = $request['search'];
            }

            $categories = get_terms(['taxonomy' => 'job_listing_category', 'search' => isset($request['search']) ? $request['search'] : '', ]);

            $args['meta_query'] = [['key' => '_case27_listing_type', 'value' => '', 'compare' => '!=', ]];

            global $wpdb;
            $listings = query_posts($args);

            $data = array();
            $items = (array)($listings);
            // return $items;
            foreach ($items as $item):
                $itemdata = $this->prepare_item_for_response($item, $request);
                $data[] = $this->prepare_response_for_collection($itemdata);
            endforeach;

            $listings_grouped = [];

            foreach ($data as $listing)
            {
                // return $listing['job_listing_category'][0];
                foreach ($listing['job_listing_category'] as $value)
                {
                    $type = get_term_by('id', $value, 'job_listing_category')->name;
                    if (!isset($listings_grouped[$type])) $listings_grouped[$type] = [];

                    $listings_grouped[$type][] = $listing;
                }

            }

            return new WP_REST_Response($listings_grouped, 200);
        }


    }

    new FlutterTemplate;
    new TemplateExtendMyListing;
    new TemplateSearch;