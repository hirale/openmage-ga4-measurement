<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\RecordingApi;
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$singletons['core/session'] = new CoreSessionStub();
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();
        \Mage::$helpers['gameasurementprotocol'] = $helper;
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    public function testHandleStripsStoreIdAndDebugModeBeforeSending(): void
    {
        \Mage::$config['7']['google/measurement/measurement_id'] = 'G-STORE7';
        \Mage::$config['7']['google/measurement/api_secret'] = 'secret-7';

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'client_id' => '111.222',
                'events' => [['name' => 'add_to_cart', 'params' => []]],
                '_store_id' => 7,
                '_debug_mode' => true,
            ],
        ]);

        self::assertCount(1, $api->posts);
        self::assertStringContainsString('measurement_id=G-STORE7', $api->posts[0]['url']);
        self::assertStringContainsString('api_secret=secret-7', $api->posts[0]['url']);

        $body = json_decode($api->posts[0]['body'], true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('_store_id', $body);
        self::assertArrayNotHasKey('_debug_mode', $body);
        self::assertArrayHasKey('events', $body);
    }

    public function testHandleSkipsPostWhenStoreIsMissingMeasurementId(): void
    {
        \Mage::$config['1']['google/measurement/api_secret'] = 'secret-1';
        // No measurement_id configured for store 1.

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'events' => [['name' => 'login', 'params' => []]],
                '_store_id' => 1,
            ],
        ]);

        self::assertSame([], $api->posts);
    }

    public function testHandleScopesHelperToStoreIdFromPayload(): void
    {
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-STORE1';
        \Mage::$config['1']['google/measurement/api_secret'] = 'secret-1';
        \Mage::$config['7']['google/measurement/measurement_id'] = 'G-STORE7';
        \Mage::$config['7']['google/measurement/api_secret'] = 'secret-7';

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'events' => [['name' => 'view_item', 'params' => []]],
                '_store_id' => 7,
            ],
        ]);

        self::assertStringContainsString('measurement_id=G-STORE7', $api->posts[0]['url']);
        self::assertStringNotContainsString('G-STORE1', $api->posts[0]['url']);
    }

    public function testHandleThrowsOnCurlError(): void
    {
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-STORE1';
        \Mage::$config['1']['google/measurement/api_secret'] = 'secret-1';

        $api = new RecordingApi();
        $api->nextResponse = ['http_code' => 0, 'curl_errno' => 28, 'curl_error' => 'Connection timed out'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection timed out');

        $api->handle([
            'data' => [
                'events' => [['name' => 'view_item', 'params' => []]],
                '_store_id' => 1,
            ],
        ]);
    }

    public function testHandleLogsWhenDebugModeIsSet(): void
    {
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-STORE1';
        \Mage::$config['1']['google/measurement/api_secret'] = 'secret-1';
        \Mage::$config['1']['google/measurement/log_file'] = 'ga_store_1.log';

        $api = new RecordingApi();
        $api->nextResponse = ['http_code' => 204, 'curl_errno' => 0, 'curl_error' => ''];

        $api->handle([
            'data' => [
                'events' => [['name' => 'view_item', 'params' => []]],
                'timestamp_micros' => 1700000000000000,
                '_store_id' => 1,
                '_debug_mode' => true,
            ],
        ]);

        self::assertNotEmpty(\Mage::$logs);
        self::assertSame('ga_store_1.log', \Mage::$logs[0]['file']);
        self::assertStringContainsString('HTTP 204', (string) \Mage::$logs[0]['message']);
    }
}
