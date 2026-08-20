<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && php_sapi_name() !== 'cli' ) {
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$GLOBALS['mstore_test_state'] = array();

function mstore_test_reset_state()
{
    $GLOBALS['mstore_test_state'] = array(
        'active_plugins' => array(),
        'options' => array(),
        'remote_post_response' => array(
            'response' => array('code' => 200),
            'body' => '{}',
        ),
        'remote_post_calls' => array(),
    );
}

function is_plugin_active($plugin)
{
    return in_array($plugin, $GLOBALS['mstore_test_state']['active_plugins'], true);
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['mstore_test_state']['options'])
        ? $GLOBALS['mstore_test_state']['options'][$name]
        : $default;
}

function wp_remote_post($url, $args = array())
{
    $GLOBALS['mstore_test_state']['remote_post_calls'][] = array($url, $args);
    return $GLOBALS['mstore_test_state']['remote_post_response'];
}

function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'];
}

function wp_remote_retrieve_body($response)
{
    return $response['body'];
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = array())
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

mstore_test_reset_state();

require_once dirname(__DIR__) . '/functions/index.php';
require_once dirname(__DIR__) . '/controllers/flutter-base.php';
