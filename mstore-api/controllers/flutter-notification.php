<?php
require_once(__DIR__ . '/flutter-base.php');

/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package Notification
 */

class FlutterNotification extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_notification';

    /**
     * Register all routes related with stores
     *
     * @return void
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_notification_routes'));
    }

    public function register_flutter_notification_routes()
    {
        register_rest_route($this->namespace, '/test_push_notification', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'test_push_notification'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));

        register_rest_route($this->namespace, '/test_push_notification_created_order', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'test_push_notification_created_order'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));

        register_rest_route($this->namespace, '/test_booking_notification_to_owner', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'test_booking_notification_to_owner'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));

        register_rest_route($this->namespace, '/test_booking_notification_to_customer', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'test_booking_notification_to_customer'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));

        register_rest_route($this->namespace, '/list_bookings', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'list_bookings'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));

        register_rest_route($this->namespace, '/check_user_notification_status', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'check_user_notification_status'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));

        register_rest_route($this->namespace, '/settings', array(
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_settings'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_settings'),
                'permission_callback' => array($this, 'get_settings_permissions_check'),
            ),
        ));
    }

    public function test_push_notification()
    {
        try {
            $json = file_get_contents('php://input');
            $params = json_decode($json);

            // Validate input
            if (!isset($params->email)) {
                return $this->sendError('missing_email', 'Email is required', 400);
            }

            $email = $params->email;
            $is_manager = isset($params->is_manager) ? $params->is_manager : false;
            $is_delivery = isset($params->is_delivery) ? $params->is_delivery : false;

            // Get user
            $user = get_user_by('email', $email);
            if (!$user) {
                return $this->sendError('notification_request_failed', 'Unable to process notification request', 404);
            }

            $user_id = $user->ID;
            $is_onesignal = isset($params->is_onesignal) ? $params->is_onesignal : false;

            // Check if OneSignal
            if($is_onesignal){
                // Check if function exists
                if (!function_exists('one_signal_push_notification')) {
                    return $this->sendError('function_not_found', 'one_signal_push_notification function not found. Make sure functions/index.php is loaded.', 500);
                }

                // Check if OneSignal plugin is active
                if (!is_plugin_active('onesignal-free-web-push-notifications/onesignal.php')) {
                    return $this->sendError('plugin_not_active', 'OneSignal plugin is not active', 400);
                }

                // Check if OneSignal is configured (using WordPress options directly)
                $onesignal_wp_settings = get_option('OneSignalWPSetting');
                if (empty($onesignal_wp_settings) || empty($onesignal_wp_settings['app_id']) || empty($onesignal_wp_settings['app_rest_api_key'])) {
                    return $this->sendError('onesignal_not_configured', 'OneSignal App ID or API Key is not configured in WordPress Settings', 400);
                }

                $result = one_signal_push_notification("Fluxstore", "Test push notification", array($user_id));

                $response = [
                    'success' => $result['success'],
                    'message' => $result['success'] ? 'Notification sent successfully' : ($result['message'] ?: "Failed to send notification"),
                    'user_id' => $user_id,
                    'user_email' => $email,
                    'notification_id' => $result['notification_id']
                ];

                if (!empty($result['errors'])) {
                    $response['errors'] = $result['errors'];
                }

                return $response;
            }

            // Firebase notification
            if ($is_manager) {
                $result = pushNotificationForVendor($user_id, "Fluxstore", "Test push notification");
            } else if ($is_delivery) {
                $result = pushNotificationForDeliveryBoy($user_id, "Fluxstore", "Test push notification");
            } else {
                $result = pushNotificationForUser($user_id, "Fluxstore", "Test push notification");
            }

            if (is_wp_error($result)) {
                return $result;
            }

            return [
                'success' => $result === true,
                'message' => $result === true ? 'Notification sent successfully' : 'Failed to send notification',
                'user_id' => $user_id,
                'type' => 'firebase'
            ];

        } catch (\Throwable $e) {
            return $this->sendError('exception', $e->getMessage(), 500);
        }
    }

    function test_push_notification_created_order(){
        $json = file_get_contents('php://input');
        $params = json_decode($json);
        return trackNewOrder($params->order_id);
    }

    /**
     * Test sending booking notification to owner
     * POST: {"booking_id": 123}
     */
    function test_booking_notification_to_owner() {
        try {
            global $wpdb;

            $json = file_get_contents('php://input');
            $params = json_decode($json);

            // Validate input
            if (!isset($params->booking_id)) {
                return $this->sendError('missing_booking_id', 'Booking ID is required', 400);
            }

            $booking_id = intval($params->booking_id);

            // Get booking from custom table
            $table_name = $wpdb->prefix . 'bookings_calendar';
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE ID = %d",
                $booking_id
            ));

            // Validate booking exists
            if (!$booking) {
                return $this->sendError('invalid_booking', 'Booking not found in custom table', 404);
            }

            // Get listing and owner info
            $listing_id = $booking->listing_id;
            if (!$listing_id) {
                return $this->sendError('missing_listing', 'Listing ID not found in booking', 400);
            }

            $listing = get_post($listing_id);
            if (!$listing) {
                return $this->sendError('invalid_listing', 'Listing not found', 404);
            }

            $owner_id = $booking->owner_id;
            if (!$owner_id) {
                return $this->sendError('missing_owner', 'Owner ID not found in booking', 400);
            }

            $owner = get_userdata($owner_id);
            if (!$owner) {
                return $this->sendError('invalid_owner', 'Owner not found', 404);
            }

            // Send notification
            sendNewBookingNotificationToOwner($booking_id);

            return [
                'success' => true,
                'message' => 'Test notification sent to owner successfully',
                'booking_id' => $booking_id,
                'booking_status' => $booking->status,
                'listing_id' => $listing_id,
                'listing_title' => $listing->post_title,
                'owner_id' => $owner_id,
                'owner_name' => $owner->display_name,
                'owner_email' => $owner->user_email
            ];

        } catch (Exception $e) {
            return $this->sendError('exception', $e->getMessage(), 500);
        }
    }

    /**
     * Test sending booking notification to customer
     * POST: {"booking_id": 123}
     */
    function test_booking_notification_to_customer() {
        try {
            global $wpdb;

            $json = file_get_contents('php://input');
            $params = json_decode($json);

            // Validate input
            if (!isset($params->booking_id)) {
                return $this->sendError('missing_booking_id', 'Booking ID is required', 400);
            }

            $booking_id = intval($params->booking_id);

            // Get booking from custom table
            $table_name = $wpdb->prefix . 'bookings_calendar';
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE ID = %d",
                $booking_id
            ));

            // Validate booking exists
            if (!$booking) {
                return $this->sendError('invalid_booking', 'Booking not found in custom table', 404);
            }

            // Get customer info
            $customer_id = $booking->bookings_author;
            if (!$customer_id) {
                return $this->sendError('missing_customer', 'Customer ID not found in booking', 400);
            }

            $customer = get_userdata($customer_id);
            if (!$customer) {
                return $this->sendError('invalid_customer', 'Customer not found', 404);
            }

            // Get listing info
            $listing_id = $booking->listing_id;
            $listing_title = 'Unknown';
            if ($listing_id) {
                $listing = get_post($listing_id);
                if ($listing) {
                    $listing_title = $listing->post_title;
                }
            }

            // Send notification with current booking status
            sendBookingStatusChangedNotificationToCustomer($booking_id, $booking->status);

            return [
                'success' => true,
                'message' => 'Test notification sent to customer successfully',
                'booking_id' => $booking_id,
                'booking_status' => $booking->status,
                'listing_id' => $listing_id,
                'listing_title' => $listing_title,
                'customer_id' => $customer_id,
                'customer_name' => $customer->display_name,
                'customer_email' => $customer->user_email
            ];

        } catch (Exception $e) {
            return $this->sendError('exception', $e->getMessage(), 500);
        }
    }

    /**
     * List available bookings for testing
     * GET: /list_bookings?limit=10
     */
    function list_bookings($request) {
        try {
            global $wpdb;

            $limit = isset($request['limit']) ? intval($request['limit']) : 10;
            $limit = min($limit, 50); // Max 50 bookings

            // Query from Listeo custom table
            $table_name = $wpdb->prefix . 'bookings_calendar';

            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY ID DESC LIMIT %d",
                $limit
            ));

            if (empty($bookings)) {
                return [
                    'success' => true,
                    'message' => 'No bookings found in custom table: ' . $table_name,
                    'total' => 0,
                    'bookings' => []
                ];
            }

            $result = array();
            foreach ($bookings as $booking) {
                $customer_id = $booking->bookings_author;
                $owner_id = $booking->owner_id;
                $listing_id = $booking->listing_id;

                // Get customer info
                $customer_name = '';
                $customer_email = '';
                if ($customer_id) {
                    $customer = get_userdata($customer_id);
                    if ($customer) {
                        $customer_name = $customer->display_name;
                        $customer_email = $customer->user_email;
                    }
                }

                // Get owner info
                $owner_name = '';
                $owner_email = '';
                if ($owner_id) {
                    $owner = get_userdata($owner_id);
                    if ($owner) {
                        $owner_name = $owner->display_name;
                        $owner_email = $owner->user_email;
                    }
                }

                // Get listing info
                $listing_title = '';
                if ($listing_id) {
                    $listing = get_post($listing_id);
                    if ($listing) {
                        $listing_title = $listing->post_title;
                    }
                }

                $result[] = array(
                    'booking_id' => $booking->ID,
                    'booking_status' => $booking->status,
                    'booking_type' => $booking->type,
                    'date_start' => $booking->date_start,
                    'date_end' => $booking->date_end,
                    'price' => $booking->price,
                    'comment' => $booking->comment,
                    'order_id' => $booking->order_id,
                    'listing_id' => $listing_id,
                    'listing_title' => $listing_title,
                    'customer_id' => $customer_id,
                    'customer_name' => $customer_name,
                    'customer_email' => $customer_email,
                    'owner_id' => $owner_id,
                    'owner_name' => $owner_name,
                    'owner_email' => $owner_email,
                    'created_date' => $booking->created
                );
            }

            return [
                'success' => true,
                'message' => 'Bookings retrieved successfully from custom table',
                'total' => count($result),
                'bookings' => $result
            ];

        } catch (Exception $e) {
            return $this->sendError('exception', $e->getMessage(), 500);
        }
    }

    /**
     * Check user notification status and settings
     * GET: /check_user_notification_status?user_id=1&email=user@example.com
     */
    function check_user_notification_status($request) {
        try {
            $user_id = isset($request['user_id']) ? intval($request['user_id']) : null;
            $email = isset($request['email']) ? sanitize_email($request['email']) : null;

            // Get user by ID or email
            if ($user_id) {
                $user = get_userdata($user_id);
            } elseif ($email) {
                $user = get_user_by('email', $email);
            } else {
                return $this->sendError('missing_param', 'Either user_id or email is required', 400);
            }

            if (!$user) {
                return $this->sendError('user_not_found', 'User not found', 404);
            }

            // Check notification enabled status
            $is_notification_enabled = isNotificationEnabled($user->ID);

            // Get user meta for debugging
            $notification_status_meta = get_user_meta($user->ID, 'mstore_notification_status', true);

            // Check OneSignal settings
            $onesignal_wp_settings = get_option('OneSignalWPSetting');
            $onesignal_configured = !empty($onesignal_wp_settings) &&
                                   !empty($onesignal_wp_settings['app_id']) &&
                                   !empty($onesignal_wp_settings['app_rest_api_key']);

            return [
                'success' => true,
                'user_id' => $user->ID,
                'user_email' => $user->user_email,
                'user_name' => $user->display_name,
                'user_roles' => $user->roles,
                'notification_enabled' => $is_notification_enabled,
                'notification_status_meta' => $notification_status_meta ?: 'not set (defaults to on)',
                'onesignal_configured' => $onesignal_configured,
                'onesignal_app_id' => $onesignal_configured ? substr($onesignal_wp_settings['app_id'], 0, 10) . '...' : null,
                'note' => 'If notification_enabled is true, user should receive notifications if they have set External User ID in the app via OneSignal.login(externalId)'
            ];

        } catch (Exception $e) {
            return $this->sendError('exception', $e->getMessage(), 500);
        }
    }

    function get_settings_permissions_check($request)
    {
        $cookie = get_header_user_cookie($request->get_header("User-Cookie"));
        if (isset($cookie) && $cookie != null) {
            $user_id = validateCookieLogin($cookie);
            if (is_wp_error($user_id)) {
                return false;
            }
            $request["user_id"] = $user_id;
            return true;
        } else {
            return false;
        }
    }

    function update_settings($request){
        $json = file_get_contents('php://input');
        $params = json_decode($json, TRUE);
        $user_id = $request["user_id"];
        update_user_meta($user_id, "mstore_notification_status", $params['is_on'] == true ? 'on' : 'off');

        return ['is_on' => isNotificationEnabled($user_id)];
    }

    function get_settings($request){
        $user_id = $request["user_id"];
        return ['is_on' => isNotificationEnabled($user_id)];
    }
}

new FlutterNotification;