<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Auction
 */

class FlutterPointsOfflineStore extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_osp';

    /**
     * Register all routes releated with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'osp_register_api_routes'));
        add_filter( 'wc_points_rewards_event_description', array($this, 'osp_add_action_event_descriptions'), 10, 3 );
    }

    public function osp_register_api_routes() {
        register_rest_route( $this->namespace, '/points', array(
            'methods' => 'GET',
            'callback' => array($this, 'osp_get_user_points_api'),
            'permission_callback' => array($this, 'osp_api_auth_permission_check'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_numeric( $param );
                    },
                ),
            ),
        ));
    
        register_rest_route( $this->namespace, '/points/add', array(
            'methods' => 'POST',
            'callback' => array($this, 'osp_add_points_api'),
            'permission_callback' => array($this, 'osp_api_manager_permission_check'),
        ));
    
        register_rest_route( $this->namespace, '/points/subtract', array(
            'methods' => 'POST',
            'callback' => array($this, 'osp_subtract_points_api'),
            'permission_callback' => array($this, 'osp_api_manager_permission_check'),
        ));
    }


    public function osp_add_action_event_descriptions( $event_description, $event_type, $event ) {
        global $wc_points_rewards;

        if($event_type == 'offline-store'){
            return !$event->data['desc'] ? 'Offline store' : $event->data['desc'];
        }
        
        return $event_description;
    }

    public function osp_get_user_points_api( $request ) {
        $user_id = (int) $request['user_id'];

        if ( ! $user_id ) {
            return new WP_Error( 'invalid_user', 'User not found', array( 'status' => 404 ) );
        }

        if (!class_exists('WC_Points_Rewards_Manager')) {
            return parent::send_invalid_plugin_error("You need to install WooCommerce Points and Rewards plugin to use this api");
        }

        //get user info
        $user = get_userdata($user_id);
        $avatar = get_user_meta($user_id, 'user_avatar', true);
        if (!isset($avatar) || $avatar == "" || is_bool($avatar)) {
            $avatar = get_avatar_url($user_id);
        } else {
            $avatar = $avatar[0];
        }
        $display_name = $user->display_name;

        $points = WC_Points_Rewards_Manager::get_users_points($user_id);
        
        return rest_ensure_response( array( 'user_id' => $user_id, 'display_name'=> $display_name, 'avatar' => $avatar, 'points' => $points ) );
    }

    public function osp_add_points_api( $data ) {
        $user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : null;
        $points = isset( $data['points'] ) ? (int) $data['points'] : null;
        $description = isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : 'Add points at offline store.';

        if ( ! $user_id || ! $points ) {
            return new WP_Error( 'missing_data', 'User ID or Points missing', array( 'status' => 400 ) );
        }

        if (!class_exists('WC_Points_Rewards_Manager')) {
            return parent::send_invalid_plugin_error("You need to install WooCommerce Points and Rewards plugin to use this api");
        }

        // Add points
        WC_Points_Rewards_Manager::increase_points( $user_id, $points, 'offline-store',['desc' => $description] );

        //Push notification to client
        pushNotificationForUser($user_id, 'Points Successfully Added!', "Congratulations! You've Earned ".$points." Points!",['type' => 'points_added']);

        return rest_ensure_response( array( 'status' => 'success', 'message' => 'Points added successfully' ) );
    }

    public function osp_subtract_points_api( $data ) {
        $user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : null;
        $points = isset( $data['points'] ) ? (int) $data['points'] : null;
        $description = isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : 'Use points at offline store.';

        if ( ! $user_id || ! $points ) {
            return new WP_Error( 'missing_data', 'User ID or Points missing', array( 'status' => 400 ) );
        }

        if (!class_exists('WC_Points_Rewards_Manager')) {
            return parent::send_invalid_plugin_error("You need to install WooCommerce Points and Rewards plugin to use this api");
        }

        // Subtract points
        WC_Points_Rewards_Manager::decrease_points( $user_id, $points, 'offline-store',['desc' => $description] );
        
        //Push notification to client
        pushNotificationForUser($user_id, 'Points Successfully Redeemed!', "You've Redeemed ".$points." Points!", ['type' => 'points_redeemed']);

        return rest_ensure_response( array( 'status' => 'success', 'message' => 'Points subtracted successfully' ) );
    }

    // Permission check for adding and subtracting points via API
    function osp_api_manager_permission_check($request) {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        $user_id = validateCookieLogin($cookie);
        if (is_wp_error($user_id)) {
            return false;
        }else{
            $user_data = get_userdata($user_id);
            // Check if the user data is valid
            if (!$user_data) {
                return false;
            }
            // Check if the user has the 'manager' role
            return in_array('shop_manager', $user_data->roles) || in_array('administrator', $user_data->roles);
        }
    }

    function osp_api_auth_permission_check($request) {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        $user_id = validateCookieLogin($cookie);
        if (is_wp_error($user_id)) {
            return false;
        }else{
            $user_data = get_userdata($user_id);
            if (!$user_data) {
                return false;
            }
            return true;
        }
    }
}

new FlutterPointsOfflineStore;