<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AppleSignInHelper
{
    public static function generate_secret_key($bundle_id, $team_id){
        $file_name = get_option("mstore_apple_sign_in_file_name");
        $file_path =  FlutterAppleSignInUtils::get_config_file_path($file_name);

        $key_id = get_option("mstore_apple_sign_in_key_id");
        $private_key = file_get_contents($file_path);
        $current_time = time();
        $payload = [
            'iss' => $team_id,
            'aud' => 'https://appleid.apple.com',
            'sub' => $bundle_id,
            'iat' => $current_time,
            'exp' => $current_time + 86400 * 180
        ];

        $headers = [
            'alg' => 'ES256',
            'kid' => $key_id
        ];

        return JWT::encode($payload, $private_key, 'ES256', null, $headers);
    }

    public static function generate_token($bundle_id, $team_id, $code){
        // Apple's token endpoint URL
        $tokenEndpoint = 'https://appleid.apple.com/auth/token';

        // Prepare the request data
        $requestData = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $bundle_id,
            'client_secret' => AppleSignInHelper::generate_secret_key($bundle_id,$team_id),
        );

        $response = wp_remote_post(
            $tokenEndpoint,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => $requestData,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if(isset($data['error_description'])){
            return new WP_Error($data['error_description']);
        }
        if (isset($data['id_token'])) {
            return $data['id_token'];
        } else {
            return false;
        }
    }
}


?>
