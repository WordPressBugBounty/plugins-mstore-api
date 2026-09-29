<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/controllers/helpers/social-login-helper.php';

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array())
    {
        $GLOBALS['mstore_test_state']['remote_get_calls'][] = array($url, $args);
        $queue = &$GLOBALS['mstore_test_state']['remote_get_responses'];
        if (!is_array($queue) || empty($queue)) {
            return array('response' => array('code' => 404), 'body' => '{}');
        }
        return array_shift($queue);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args)
    {
        if (is_array($args[0])) {
            $params = $args[0];
            $url = $args[1];
        } else {
            $params = array($args[0] => $args[1]);
            $url = $args[2];
        }
        $pairs = array();
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . implode('&', $pairs);
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        return trim((string) $email);
    }
}

if (!function_exists('is_email')) {
    function is_email($email)
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

final class SecurityHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        mstore_test_reset_state();
        $GLOBALS['mstore_test_state']['remote_get_calls'] = array();
        $GLOBALS['mstore_test_state']['remote_get_responses'] = array();
    }

    private function queueJsonResponse(int $code, array $body): void
    {
        $GLOBALS['mstore_test_state']['remote_get_responses'][] = array(
            'response' => array('code' => $code),
            'body' => json_encode($body),
        );
    }

    /**
     * @dataProvider customerStatusTransitions
     */
    public function testCustomerOrderStatusTransitions(string $current, string $requested, bool $expected): void
    {
        self::assertSame($expected, mstore_is_customer_order_status_change_allowed($current, $requested));
    }

    public function customerStatusTransitions(): array
    {
        return array(
            'unpaid order cannot be completed' => array('pending', 'completed', false),
            'unpaid order cannot be marked processing' => array('pending', 'processing', false),
            'unpaid order cannot be refunded' => array('pending', 'refunded', false),
            'custom fulfilment statuses are blocked' => array('pending', 'driver-assigned', false),
            'pending order can be cancelled' => array('pending', 'cancelled', true),
            'failed order can be cancelled' => array('failed', 'cancelled', true),
            'on-hold order cannot be cancelled' => array('on-hold', 'cancelled', false),
            'paid order cannot be cancelled' => array('processing', 'cancelled', false),
            'paid order cannot be moved back to pending' => array('processing', 'pending', false),
            'completed order cannot be failed' => array('completed', 'failed', false),
            'pending can move to on-hold' => array('pending', 'on-hold', true),
            'wc- prefixes are normalised' => array('wc-pending', 'wc-failed', true),
            'same status is a harmless no-op' => array('completed', 'completed', true),
            'empty status is rejected' => array('pending', '', false),
        );
    }

    public function testTapChargeValidationAcceptsMatchingCapturedCharge(): void
    {
        $charge = array('id' => 'chg_abc', 'status' => 'CAPTURED', 'amount' => 99.5, 'currency' => 'usd');

        self::assertTrue(mstore_validate_tap_charge($charge, 'chg_abc', 99.5, 'USD'));
    }

    /**
     * @dataProvider invalidTapCharges
     */
    public function testTapChargeValidationRejectsMismatches($charge, string $charge_id, float $total, string $currency, string $expected_code): void
    {
        $result = mstore_validate_tap_charge($charge, $charge_id, $total, $currency);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($expected_code, $result->get_error_code());
    }

    public function invalidTapCharges(): array
    {
        $valid = array('id' => 'chg_abc', 'status' => 'CAPTURED', 'amount' => 10, 'currency' => 'USD');

        return array(
            'non-array response' => array('nope', 'chg_abc', 10.0, 'USD', 'tap_invalid_response'),
            'different charge id' => array($valid, 'chg_other', 10.0, 'USD', 'tap_charge_mismatch'),
            'charge not captured' => array(array_merge($valid, array('status' => 'INITIATED')), 'chg_abc', 10.0, 'USD', 'tap_not_captured'),
            'amount too low' => array(array_merge($valid, array('amount' => 1)), 'chg_abc', 10.0, 'USD', 'tap_amount_mismatch'),
            'missing amount' => array(array('id' => 'chg_abc', 'status' => 'CAPTURED', 'currency' => 'USD'), 'chg_abc', 10.0, 'USD', 'tap_amount_missing'),
            'currency mismatch' => array(array_merge($valid, array('currency' => 'EUR')), 'chg_abc', 10.0, 'USD', 'tap_currency_mismatch'),
        );
    }

    public function testParseIdListSplitsAndDeduplicates(): void
    {
        $ids = MStoreSocialLoginHelper::parse_id_list(" a.apps.googleusercontent.com, b\n b ;c\td ");

        self::assertSame(array('a.apps.googleusercontent.com', 'b', 'c', 'd'), $ids);
        self::assertSame(array(), MStoreSocialLoginHelper::parse_id_list(''));
        self::assertSame(array('x', 'y'), MStoreSocialLoginHelper::parse_id_list(array('x', ' y ')));
    }

    public function testAudienceMatchingIsStrict(): void
    {
        $allowed = array('123-abc.apps.googleusercontent.com');

        self::assertTrue(MStoreSocialLoginHelper::audience_matches('123-abc.apps.googleusercontent.com', $allowed));
        self::assertTrue(MStoreSocialLoginHelper::audience_matches(array('other', '123-abc.apps.googleusercontent.com'), $allowed));
        self::assertFalse(MStoreSocialLoginHelper::audience_matches('123-ABC.apps.googleusercontent.com', $allowed));
        self::assertFalse(MStoreSocialLoginHelper::audience_matches('123-abc.apps.googleusercontent.com', array()));
        self::assertFalse(MStoreSocialLoginHelper::audience_matches('', $allowed));
        self::assertFalse(MStoreSocialLoginHelper::audience_matches(null, $allowed));
    }

    public function testGoogleLoginFailsClosedWithoutConfiguredClientIds(): void
    {
        $result = MStoreSocialLoginHelper::verify_google_token('ya29.token');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('social_login_not_configured', $result->get_error_code());
        self::assertSame(array(), $GLOBALS['mstore_test_state']['remote_get_calls']);
    }

    public function testGoogleTokenIssuedToAnotherAppIsRejected(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS] = "my-app.apps.googleusercontent.com";
        $this->queueJsonResponse(200, array(
            'aud' => 'attacker-app.apps.googleusercontent.com',
            'azp' => 'attacker-app.apps.googleusercontent.com',
            'email' => 'victim@example.com',
            'email_verified' => 'true',
        ));

        $result = MStoreSocialLoginHelper::verify_google_token('ya29.token');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_audience', $result->get_error_code());
    }

    public function testGoogleTokenIssuedToThisAppIsAccepted(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS] = "web.apps.googleusercontent.com\nandroid.apps.googleusercontent.com";
        $this->queueJsonResponse(200, array(
            'aud' => 'android.apps.googleusercontent.com',
            'email' => 'user@example.com',
            'email_verified' => 'true',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
        ));

        $result = MStoreSocialLoginHelper::verify_google_token('ya29.token');

        self::assertIsArray($result);
        self::assertSame('user@example.com', $result['email']);
        self::assertSame('Ada', $result['first_name']);
        self::assertSame('Lovelace', $result['last_name']);
        self::assertSame('Ada Lovelace', $result['name']);
        self::assertStringContainsString('oauth2.googleapis.com/tokeninfo?access_token=', $GLOBALS['mstore_test_state']['remote_get_calls'][0][0]);
    }

    public function testSocialLoginTokensAreEncodedOnlyOnceInProviderRequests(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS] = 'web.apps.googleusercontent.com';
        $this->queueJsonResponse(200, array(
            'aud' => 'web.apps.googleusercontent.com',
            'email' => 'user@example.com',
            'email_verified' => 'true',
        ));

        MStoreSocialLoginHelper::verify_google_token('ya29.a+b/c=');

        $url = $GLOBALS['mstore_test_state']['remote_get_calls'][0][0];
        self::assertStringContainsString('access_token=ya29.a%2Bb%2Fc%3D', $url);
        self::assertStringNotContainsString('%252B', $url);
        self::assertStringNotContainsString('%252F', $url);
        self::assertStringNotContainsString('%253D', $url);
    }

    public function testGoogleTokenWithUnverifiedEmailIsRejected(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS] = 'web.apps.googleusercontent.com';
        $this->queueJsonResponse(200, array(
            'aud' => 'web.apps.googleusercontent.com',
            'email' => 'user@example.com',
            'email_verified' => 'false',
        ));

        $result = MStoreSocialLoginHelper::verify_google_token('ya29.token');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_login', $result->get_error_code());
    }

    public function testGoogleTokenRejectedByGoogleIsRejected(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_GOOGLE_CLIENT_IDS] = 'web.apps.googleusercontent.com';
        $this->queueJsonResponse(400, array('error' => 'invalid_token', 'error_description' => 'Invalid Value'));

        $result = MStoreSocialLoginHelper::verify_google_token('ya29.token');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_login', $result->get_error_code());
    }

    public function testFacebookLoginFailsClosedWithoutConfiguredAppId(): void
    {
        $result = MStoreSocialLoginHelper::verify_facebook_token('EAABtoken');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('social_login_not_configured', $result->get_error_code());
    }

    public function testFacebookTokenIssuedToAnotherAppIsRejected(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_ID] = '111222333';
        $this->queueJsonResponse(200, array('id' => '999888777', 'name' => 'Attacker App'));

        $result = MStoreSocialLoginHelper::verify_facebook_token('EAABtoken');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_audience', $result->get_error_code());
        self::assertCount(1, $GLOBALS['mstore_test_state']['remote_get_calls']);
    }

    public function testFacebookTokenIssuedToThisAppIsAccepted(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_ID] = '111222333';
        $this->queueJsonResponse(200, array('id' => '111222333', 'name' => 'My App'));
        $this->queueJsonResponse(200, array('id' => '42', 'name' => 'Grace Hopper', 'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.com'));

        $result = MStoreSocialLoginHelper::verify_facebook_token('EAABtoken');

        self::assertIsArray($result);
        self::assertSame('grace@example.com', $result['email']);
        self::assertSame('Grace Hopper', $result['name']);
        self::assertStringContainsString('graph.facebook.com/app?access_token=', $GLOBALS['mstore_test_state']['remote_get_calls'][0][0]);
        self::assertStringContainsString('graph.facebook.com/me?', $GLOBALS['mstore_test_state']['remote_get_calls'][1][0]);
    }

    public function testFacebookDebugTokenIsUsedWhenAppSecretIsConfigured(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_ID] = '111222333';
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_SECRET] = 'shh';
        $this->queueJsonResponse(200, array('data' => array('app_id' => '111222333', 'is_valid' => true)));
        $this->queueJsonResponse(200, array('id' => '42', 'name' => 'Grace', 'email' => 'grace@example.com'));

        $result = MStoreSocialLoginHelper::verify_facebook_token('EAABtoken');

        self::assertIsArray($result);
        self::assertStringContainsString('graph.facebook.com/debug_token?', $GLOBALS['mstore_test_state']['remote_get_calls'][0][0]);
        self::assertStringContainsString('appsecret_proof=', $GLOBALS['mstore_test_state']['remote_get_calls'][1][0]);
    }

    public function testFacebookDebugTokenInvalidTokenIsRejected(): void
    {
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_ID] = '111222333';
        $GLOBALS['mstore_test_state']['options'][MStoreSocialLoginHelper::OPTION_FACEBOOK_APP_SECRET] = 'shh';
        $this->queueJsonResponse(200, array('data' => array('app_id' => '111222333', 'is_valid' => false)));

        $result = MStoreSocialLoginHelper::verify_facebook_token('EAABtoken');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_audience', $result->get_error_code());
    }
}
