<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OneSignalNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        mstore_test_reset_state();
    }

    public function testDoesNothingWhenOneSignalPluginIsInactive(): void
    {
        self::assertFalse(one_signal_push_notification('Title', 'Message', array(10)));
        self::assertSame(array(), $GLOBALS['mstore_test_state']['remote_post_calls']);
    }

    public function testDoesNothingWhenOneSignalCredentialsAreMissing(): void
    {
        $GLOBALS['mstore_test_state']['active_plugins'][] = 'onesignal-free-web-push-notifications/onesignal.php';

        self::assertFalse(one_signal_push_notification('Title', 'Message', array(10)));
        self::assertSame(array(), $GLOBALS['mstore_test_state']['remote_post_calls']);
    }

    public function testBuildsSafePayloadAndPreservesValidExternalIds(): void
    {
        $GLOBALS['mstore_test_state']['active_plugins'][] = 'onesignal-free-web-push-notifications/onesignal.php';
        $GLOBALS['mstore_test_state']['options']['OneSignalWPSetting'] = array(
            'app_id' => 'app-id',
            'app_rest_api_key' => 'secret',
        );
        $GLOBALS['mstore_test_state']['remote_post_response']['body'] = json_encode(array('id' => 'notification-id'));

        $result = one_signal_push_notification('Title', 'Message', array(0, 1, 124, null, ''));

        self::assertTrue($result['success']);
        self::assertSame('notification-id', $result['notification_id']);
        self::assertCount(1, $GLOBALS['mstore_test_state']['remote_post_calls']);

        list($url, $args) = $GLOBALS['mstore_test_state']['remote_post_calls'][0];
        $payload = json_decode($args['body'], true);

        self::assertSame('https://onesignal.com/api/v1/notifications', $url);
        self::assertSame('Basic secret', $args['headers']['Authorization']);
        self::assertSame(array('user_0', 'user_1', '124'), $payload['include_external_user_ids']);
        self::assertSame(array('title' => 'Title', 'message' => 'Message'), $payload['data']);
    }

    public function testReturnsStructuredFailureForInvalidProviderResponse(): void
    {
        $GLOBALS['mstore_test_state']['active_plugins'][] = 'onesignal-free-web-push-notifications/onesignal.php';
        $GLOBALS['mstore_test_state']['options']['OneSignalWPSetting'] = array(
            'app_id' => 'app-id',
            'app_rest_api_key' => 'secret',
        );
        $GLOBALS['mstore_test_state']['remote_post_response'] = array(
            'response' => array('code' => 502),
            'body' => '<html>Bad gateway</html>',
        );

        $result = one_signal_push_notification('Title', 'Message', array(124));

        self::assertFalse($result['success']);
        self::assertSame(array('invalid_response'), $result['errors']);
        self::assertSame('OneSignal response is not valid JSON (HTTP 502)', $result['message']);
    }
}
