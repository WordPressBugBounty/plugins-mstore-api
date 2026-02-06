<?php
require_once(__DIR__ . '/flutter-base.php');
require_once(__DIR__ . '/helpers/blog-helper.php');
/*
 * Base REST Controller for flutter
 *
 * @since 1.4.0
 *
 * @package App
 */

class FlutterApp extends FlutterBaseController
{
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'api/flutter_app';


    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_flutter_app_routes'));
    }

    public function register_flutter_app_routes()
    {
        register_rest_route($this->namespace, 'plugin_details', array(
            array(
                'methods' => "GET",
                'callback' => array($this, 'get_plugin_details'),
                'permission_callback' => function () {
                    return parent::checkApiPermission();
                }
            ),
        ));
    }

    function get_plugin_details($request)
    {
        return ['success' => true, 'version' => MSTORE_CHECKOUT_VERSION];
    }
}

new FlutterApp;
