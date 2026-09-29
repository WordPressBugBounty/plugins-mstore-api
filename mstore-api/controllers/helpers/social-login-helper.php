<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Audience verification for the social-login routes.
 *
 * A Google / Facebook token proves that *some* application was authorised by
 * the user, not that *this* store's app was. Before an email taken from a
 * token is trusted to log the caller in, the token's audience (Google
 * `aud` / `azp`, Facebook app id) must match the client ids configured for
 * this site. Without that check anyone who can obtain a token for a victim
 * through their own OAuth app could log in as that victim.
 */
class MStoreSocialLoginHelper
{
    const OPTION_GOOGLE_CLIENT_IDS = 'mstore_google_client_ids';
    const OPTION_FACEBOOK_APP_ID = 'mstore_facebook_app_id';
    const OPTION_FACEBOOK_APP_SECRET = 'mstore_facebook_app_secret';

    /**
     * Split a newline / comma / whitespace separated list of ids into an array.
     *
     * @param string|array $raw
     * @return string[]
     */
    public static function parse_id_list($raw)
    {
        if (is_array($raw)) {
            $raw = implode("\n", $raw);
        }

        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }

        $parts = preg_split('/[\s,;]+/', $raw);
        $ids = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $ids[] = $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return string[]
     */
    public static function get_google_client_ids()
    {
        $ids = array();

        if (defined('MSTORE_GOOGLE_CLIENT_IDS')) {
            $ids = array_merge($ids, self::parse_id_list(constant('MSTORE_GOOGLE_CLIENT_IDS')));
        }

        $ids = array_merge($ids, self::parse_id_list(get_option(self::OPTION_GOOGLE_CLIENT_IDS, '')));

        if (function_exists('apply_filters')) {
            $ids = apply_filters('mstore_google_client_ids', $ids);
        }

        return self::parse_id_list(is_array($ids) ? $ids : array());
    }

    public static function get_facebook_app_id()
    {
        $app_id = '';

        if (defined('MSTORE_FACEBOOK_APP_ID')) {
            $app_id = (string) constant('MSTORE_FACEBOOK_APP_ID');
        }

        if ($app_id === '') {
            $app_id = (string) get_option(self::OPTION_FACEBOOK_APP_ID, '');
        }

        if (function_exists('apply_filters')) {
            $app_id = apply_filters('mstore_facebook_app_id', $app_id);
        }

        return trim((string) $app_id);
    }

    public static function get_facebook_app_secret()
    {
        $secret = '';

        if (defined('MSTORE_FACEBOOK_APP_SECRET')) {
            $secret = (string) constant('MSTORE_FACEBOOK_APP_SECRET');
        }

        if ($secret === '') {
            $secret = (string) get_option(self::OPTION_FACEBOOK_APP_SECRET, '');
        }

        return trim((string) $secret);
    }

    /**
     * Strict audience comparison: every candidate must be a non-empty string
     * and match one of the allowed ids exactly.
     *
     * @param string|string[] $audience
     * @param string[]        $allowed
     */
    public static function audience_matches($audience, $allowed)
    {
        $allowed = self::parse_id_list($allowed);
        if (empty($allowed)) {
            return false;
        }

        $candidates = is_array($audience) ? $audience : array($audience);
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_numeric($candidate)) {
                continue;
            }
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            foreach ($allowed as $id) {
                if (hash_equals($id, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function not_configured_error($provider)
    {
        return new WP_Error(
            'social_login_not_configured',
            sprintf(
                '%s login is disabled because no %s is configured. Add it under WordPress admin > MStore API before using this login method.',
                $provider,
                $provider === 'Google' ? 'Google client ID' : 'Facebook App ID'
            ),
            array('status' => 403)
        );
    }

    public static function invalid_token_error($message = '')
    {
        if ($message === '') {
            $message = 'The provided token could not be verified.';
        }

        return new WP_Error('invalid_login', $message, array('status' => 401));
    }

    private static function remote_get_json($url, $args = array())
    {
        $defaults = array('timeout' => 10);
        $response = wp_remote_get($url, array_merge($defaults, $args));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return new WP_Error('invalid_response', 'Unexpected response from the identity provider.', array('status' => 502));
        }

        if ($code < 200 || $code >= 300) {
            $message = '';
            if (isset($body['error_description'])) {
                $message = (string) $body['error_description'];
            } elseif (isset($body['error']['message'])) {
                $message = (string) $body['error']['message'];
            } elseif (isset($body['error']) && is_string($body['error'])) {
                $message = $body['error'];
            }
            return self::invalid_token_error($message !== '' ? $message : 'The identity provider rejected the token.');
        }

        return $body;
    }

    /**
     * Verify a Google access token (or ID token) and return the profile it
     * belongs to, but only when the token was issued to one of this site's
     * Google client ids.
     *
     * @return array|WP_Error {email, name, first_name, last_name}
     */
    public static function verify_google_token($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return self::invalid_token_error('Missing access_token.');
        }

        $client_ids = self::get_google_client_ids();
        if (empty($client_ids)) {
            return self::not_configured_error('Google');
        }

        $is_id_token = count(explode('.', $token)) === 3;
        $param = $is_id_token ? 'id_token' : 'access_token';

        $tokeninfo = self::remote_get_json(add_query_arg($param, $token, 'https://oauth2.googleapis.com/tokeninfo'));
        if (is_wp_error($tokeninfo)) {
            return $tokeninfo;
        }

        if (!empty($tokeninfo['error']) || !empty($tokeninfo['error_description'])) {
            return self::invalid_token_error('Google rejected the token.');
        }

        $audiences = array();
        if (isset($tokeninfo['aud'])) {
            $audiences[] = $tokeninfo['aud'];
        }
        if (isset($tokeninfo['azp'])) {
            $audiences[] = $tokeninfo['azp'];
        }
        if (!self::audience_matches($audiences, $client_ids)) {
            return new WP_Error('invalid_audience', 'The Google token was not issued for this application.', array('status' => 401));
        }

        if ($is_id_token) {
            $issuer = isset($tokeninfo['iss']) ? (string) $tokeninfo['iss'] : '';
            if (!in_array($issuer, array('https://accounts.google.com', 'accounts.google.com'), true)) {
                return self::invalid_token_error('The Google token has an invalid issuer.');
            }
        }

        if (isset($tokeninfo['exp']) && is_numeric($tokeninfo['exp']) && (int) $tokeninfo['exp'] < time()) {
            return self::invalid_token_error('The Google token has expired.');
        }

        $email = isset($tokeninfo['email']) ? sanitize_email($tokeninfo['email']) : '';
        if ($email === '' || !is_email($email)) {
            return self::invalid_token_error("Your 'token' did not return email of the user. Without 'email' user can't be logged in or registered. Get user email extended permission while joining the Google app.");
        }

        $verified = isset($tokeninfo['email_verified']) ? $tokeninfo['email_verified'] : null;
        if ($verified === null || !in_array($verified, array(true, 'true', 1, '1'), true)) {
            return self::invalid_token_error('The Google account email address is not verified.');
        }

        $profile = array(
            'email' => $email,
            'first_name' => isset($tokeninfo['given_name']) ? (string) $tokeninfo['given_name'] : '',
            'last_name' => isset($tokeninfo['family_name']) ? (string) $tokeninfo['family_name'] : '',
        );

        if (!$is_id_token && ($profile['first_name'] === '' && $profile['last_name'] === '')) {
            $userinfo = self::remote_get_json('https://www.googleapis.com/oauth2/v3/userinfo', array(
                'headers' => array('Authorization' => 'Bearer ' . $token),
            ));
            if (!is_wp_error($userinfo) && isset($userinfo['email']) && strtolower(sanitize_email($userinfo['email'])) === strtolower($email)) {
                $profile['first_name'] = isset($userinfo['given_name']) ? (string) $userinfo['given_name'] : '';
                $profile['last_name'] = isset($userinfo['family_name']) ? (string) $userinfo['family_name'] : '';
            }
        }

        $profile['name'] = trim($profile['first_name'] . ' ' . $profile['last_name']);
        if ($profile['name'] === '') {
            $profile['name'] = explode('@', $email)[0];
        }

        return $profile;
    }

    /**
     * Verify a Facebook access token / Limited Login authentication token and
     * return the profile it belongs to, but only when the token was issued to
     * this site's Facebook app.
     *
     * @return array|WP_Error {email, name, first_name, last_name}
     */
    public static function verify_facebook_token($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return self::invalid_token_error('Missing access_token.');
        }

        $app_id = self::get_facebook_app_id();
        if ($app_id === '') {
            return self::not_configured_error('Facebook');
        }

        // Limited Login (iOS) returns a signed JWT instead of a Graph token.
        if (count(explode('.', $token)) === 3 && class_exists('FacebookJWTHelper')) {
            $jwt = FacebookJWTHelper::validateJWT($token, null, $app_id);
            if (!empty($jwt['success'])) {
                $payload = $jwt['decoded']['payload'];
                $email = isset($payload->email) ? sanitize_email($payload->email) : '';
                if ($email === '' || !is_email($email)) {
                    return self::invalid_token_error("Your 'access_token' did not return email of the user. Without 'email' user can't be logged in or registered. Get user email extended permission while joining the Facebook app.");
                }
                return array(
                    'email' => $email,
                    'name' => isset($payload->name) ? (string) $payload->name : explode('@', $email)[0],
                    'first_name' => isset($payload->given_name) ? (string) $payload->given_name : '',
                    'last_name' => isset($payload->family_name) ? (string) $payload->family_name : '',
                );
            }

            $signature_ok = isset($jwt['message']) && strpos((string) $jwt['message'], 'Audience') !== false;
            if ($signature_ok) {
                return new WP_Error('invalid_audience', 'The Facebook token was not issued for this application.', array('status' => 401));
            }
            // Not a Facebook JWT: fall through to Graph API verification.
        }

        $app_secret = self::get_facebook_app_secret();
        $graph = 'https://graph.facebook.com';

        if ($app_secret !== '') {
            $debug = self::remote_get_json(add_query_arg(array(
                'input_token' => $token,
                'access_token' => $app_id . '|' . $app_secret,
            ), $graph . '/debug_token'));
            if (is_wp_error($debug)) {
                return $debug;
            }
            $data = isset($debug['data']) && is_array($debug['data']) ? $debug['data'] : array();
            if (empty($data['is_valid']) || !isset($data['app_id']) || !self::audience_matches($data['app_id'], array($app_id))) {
                return new WP_Error('invalid_audience', 'The Facebook token was not issued for this application.', array('status' => 401));
            }
        } else {
            $app = self::remote_get_json(add_query_arg('access_token', $token, $graph . '/app'));
            if (is_wp_error($app)) {
                return $app;
            }
            if (!isset($app['id']) || !self::audience_matches($app['id'], array($app_id))) {
                return new WP_Error('invalid_audience', 'The Facebook token was not issued for this application.', array('status' => 401));
            }
        }

        $me_args = array(
            'fields' => 'id,name,first_name,last_name,email',
            'access_token' => $token,
        );
        if ($app_secret !== '') {
            $me_args['appsecret_proof'] = hash_hmac('sha256', $token, $app_secret);
        }

        $me = self::remote_get_json(add_query_arg($me_args, $graph . '/me'));
        if (is_wp_error($me)) {
            return $me;
        }

        $email = isset($me['email']) ? sanitize_email($me['email']) : '';
        if ($email === '' || !is_email($email)) {
            return self::invalid_token_error("Your 'access_token' did not return email of the user. Without 'email' user can't be logged in or registered. Get user email extended permission while joining the Facebook app.");
        }

        return array(
            'email' => $email,
            'name' => isset($me['name']) ? (string) $me['name'] : explode('@', $email)[0],
            'first_name' => isset($me['first_name']) ? (string) $me['first_name'] : '',
            'last_name' => isset($me['last_name']) ? (string) $me['last_name'] : '',
        );
    }
}
