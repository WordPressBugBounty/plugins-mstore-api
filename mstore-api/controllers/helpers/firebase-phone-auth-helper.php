<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class FirebasePhoneAuthHelper
{
    const FIREBASE_PUBLIC_KEYS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public function verify_id_token($id_token)
    {
        try {
            // Fetch public keys
            $public_keys = $this->get_public_keys();
            if (!$public_keys) {
                return new WP_Error('firebase_auth_error', 'Could not retrieve public keys for verification.', array('status' => 500));
            }

            // The firebase/php-jwt v6+ library requires Key objects.
            $key_objects = [];
            foreach ($public_keys as $kid => $key) {
                $key_objects[$kid] = new Key($key, 'RS256');
            }

            // Decode and verify signature (JWT::decode automatically checks 'exp' and 'nbf')
            $decoded_token = JWT::decode($id_token, $key_objects);

            // Fetch actual project ID from config (Blocker: Prevents cross-project token attacks)
            if (!FirebaseMessageHelper::is_file_existed()) {
                return new WP_Error('firebase_auth_error', 'Firebase private key file is not found', array('status' => 500));
            }
            $json = json_decode(file_get_contents(
                FirebaseMessageHelper::get_config_file_path(FirebaseMessageHelper::get_file_name())
            ), true);
            $actual_project_id = $json['project_id'];

            // Verify Claims securely against our actual project configuration
            if ($decoded_token->aud !== $actual_project_id) {
                return new WP_Error('firebase_auth_error', 'Invalid token audience.', array('status' => 401));
            }

            if ($decoded_token->iss !== 'https://securetoken.google.com/' . $actual_project_id) {
                return new WP_Error('firebase_auth_error', 'Invalid token issuer.', array('status' => 401));
            }

            // Verify mandatory claims per Google Spec
            if (empty($decoded_token->sub)) {
                return new WP_Error('firebase_auth_error', 'Token subject (sub) is missing.', array('status' => 401));
            }

            if (!isset($decoded_token->auth_time) || $decoded_token->auth_time > time()) {
                return new WP_Error('firebase_auth_error', 'Invalid auth_time.', array('status' => 401));
            }

            if ($decoded_token->iat > (time() + 5)) { // 5 seconds leeway for clock skew
                return new WP_Error('firebase_auth_error', 'Token issued in the future.', array('status' => 401));
            }

            if (empty($decoded_token->phone_number)) {
                 return new WP_Error('firebase_auth_error', 'Phone number not found in token.', array('status' => 401));
            }

            return trim($decoded_token->phone_number);

        } catch (Exception $e) {
            return new WP_Error('firebase_auth_error', 'Token verification failed: ' . $e->getMessage(), array('status' => 401));
        }
    }

    private function get_public_keys()
    {
        // Namespaced transient key to prevent conflicts
        $keys = get_transient('mstore_firebase_public_keys');

        if ($keys === false) {
            // Set timeout for login hot path
            $response = wp_remote_get(self::FIREBASE_PUBLIC_KEYS_URL, array('timeout' => 5));

            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $keys = json_decode($body, true);

            // Only cache valid JSON arrays to prevent wedging logins
            if (is_array($keys) && !empty($keys)) {
                $headers = wp_remote_retrieve_headers($response);
                $cache_control = isset($headers['cache-control']) ? $headers['cache-control'] : 'max-age=3600';

                preg_match('/max-age=(\d+)/', $cache_control, $matches);
                $max_age = isset($matches[1]) ? (int)$matches[1] : 3600;

                set_transient('mstore_firebase_public_keys', $keys, $max_age);
            } else {
                return false;
            }
        }
        return $keys;
    }
}
?>