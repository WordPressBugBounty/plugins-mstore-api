<?php
class FlutterStripeHelper {
    public $headers;
    public $url = 'https://api.stripe.com/v1/';
    public $method = null;
    public $fields = array();

    function __construct ($apiKey) {
        $this->headers = array(
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        );
    }

    function call () {
        $url = $this->url;
        $args = array(
            'method' => $this->method ? $this->method : 'GET',
            'headers' => $this->headers,
            'timeout' => 15,
        );

        switch ($this->method){
           case "POST":
              if ($this->fields)
                 $args['body'] = $this->fields;
              break;
           case "PUT":
              if ($this->fields)
                 $args['body'] = $this->fields;
              break;
           default:
              if ($this->fields)
                 $url = sprintf("%s?%s", $url, http_build_query($this->fields));
        }

        $response = wp_remote_request($url, $args);
        if ( is_wp_error( $response ) ) {
            return array(
                'error' => $response->get_error_message(),
            );
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
?>
