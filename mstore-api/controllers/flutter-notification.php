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
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));

        register_rest_route($this->namespace, '/test_push_notification_created_order', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'test_push_notification_created_order'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
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
                pushNotificationForVendor($user_id, "Fluxstore", "Test push notification");
            } else if ($is_delivery) {
                pushNotificationForDeliveryBoy($user_id, "Fluxstore", "Test push notification");
            } else {
                pushNotificationForUser($user_id, "Fluxstore", "Test push notification");
            }

            return [
                'success' => true,
                'message' => 'Notification sent',
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