<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlutterBaseControllerTest extends TestCase
{
    public function testSendErrorReturnsAWordPressErrorWithStatus(): void
    {
        $error = (new FlutterBaseController())->sendError('forbidden', 'Forbidden', 403);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('forbidden', $error->get_error_code());
        self::assertSame('Forbidden', $error->get_error_message());
        self::assertSame(array('status' => 403), $error->get_error_data());
    }

    public function testInvalidPluginErrorUsesStableApiContract(): void
    {
        $error = (new FlutterBaseController())->send_invalid_plugin_error('Missing extension');

        self::assertSame('invalid_plugin', $error->get_error_code());
        self::assertSame('Missing extension', $error->get_error_message());
        self::assertSame(array('status' => 403), $error->get_error_data());
    }

    public function testApiPermissionFollowsPurchaseVerification(): void
    {
        self::assertTrue((new FlutterBaseController())->checkApiPermission());
    }
}
