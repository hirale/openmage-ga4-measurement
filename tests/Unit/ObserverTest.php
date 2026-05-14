<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use HiraleGAMeasurementProtocol\Tests\Support\AppStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\GoogleAnalyticsHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\HttpHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\QueueStub;
use HiraleGAMeasurementProtocol\Tests\Support\StringHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\StoreStub;
use HiraleGAMeasurementProtocol\Tests\Support\UrlHelperStub;
use PHPUnit\Framework\TestCase;

class ObserverTest extends TestCase
{
    private QueueStub $queue;

    protected function setUp(): void
    {
        \Mage::reset();
        $this->queue = new QueueStub();
        \Mage::$helpers['gameasurementprotocol'] = new \Hirale_GAMeasurementProtocol_Helper_Data();
        \Mage::$helpers['googleanalytics'] = new GoogleAnalyticsHelperStub();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/string'] = new StringHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$models['hirale_queue/queue'] = $this->queue;
        \Mage::$singletons['core/session'] = new CoreSessionStub();
        \Mage::$singletons['customer/session'] = new \Mage_Customer_Model_Session();
        \Mage::$app = new AppStub(1);
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
        \Mage::$config['1']['google/measurement/enabled'] = '1';
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-STORE1';
        \Mage::$config['1']['google/measurement/api_secret'] = 'secret-1';
        \Mage::$config['7']['google/measurement/enabled'] = '1';
        \Mage::$config['7']['google/measurement/measurement_id'] = 'G-STORE7';
        \Mage::$config['7']['google/measurement/api_secret'] = 'secret-7';
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    public function testAddToQueueIncludesStoreIdAndDebugFlagInPayload(): void
    {
        $observer = new ObserverAccessor();
        $observer->callAddToQueue(['events' => [['name' => 'login', 'params' => []]]], 7);

        self::assertCount(1, $this->queue->calls);
        $call = $this->queue->calls[0];
        self::assertSame('Hirale_GAMeasurementProtocol_Model_Api', $call['handler']);
        self::assertSame(7, $call['payload']['_store_id']);
        self::assertFalse($call['payload']['_debug_mode']);
        self::assertSame('hirale_gameasurementprotocol', $call['options']['metadata']['source']);
        self::assertSame(7, $call['options']['metadata']['store_id']);
        self::assertSame(['login'], $call['options']['metadata']['event_names']);
    }

    public function testAddToQueueFallsBackToCurrentStoreWhenStoreIdMissing(): void
    {
        \Mage::$app = new AppStub(42);
        \Mage::$config['42'] = ['google/measurement/enabled' => '1'];

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(['events' => [['name' => 'page_view', 'params' => []]]], null);

        self::assertSame(42, $this->queue->calls[0]['payload']['_store_id']);
    }

    public function testAddToQueueStampsUserAgentAndPlatformOnEachEvent(): void
    {
        \Mage::$app->request->server['HTTP_SEC_CH_UA_PLATFORM'] = '"macOS"';
        \Mage::$helpers['core/http']->userAgent = 'TestUA/1.0';

        $observer = new ObserverAccessor();
        $observer->callAddToQueue([
            'events' => [
                ['name' => 'view_item', 'params' => []],
                ['name' => 'view_item_list', 'params' => []],
            ],
        ], 1);

        $events = $this->queue->calls[0]['payload']['events'];
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('TestUA/1.0', $event['params']['user_agent']);
            self::assertSame('"macOS"', $event['params']['platform']);
        }
    }

    public function testAddToQueueSwallowsQueueExceptions(): void
    {
        $this->queue->nextException = new \RuntimeException('redis down');

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(['events' => [['name' => 'login', 'params' => []]]], 1);

        // Exception was swallowed by addToQueue's try/catch and logged.
        self::assertCount(1, \Mage::$exceptions);
        self::assertSame('redis down', \Mage::$exceptions[0]->getMessage());
    }

    public function testGetBaseEventDataIncludesSessionIdWhenCookiePresent(): void
    {
        $_COOKIE['_ga_STORE1'] = 'GS1.1.1700000000.5.0.1700000010.0.0';

        try {
            $observer = new ObserverAccessor();
            $base = $observer->callGetBaseEventData(1);

            self::assertSame('1700000000', $base['session_id']);
            self::assertSame(true, $base['non_personalized_ads']);
        } finally {
            unset($_COOKIE['_ga_STORE1']);
        }
    }

    public function testGetBaseEventDataOmitsSessionIdWhenCookieAbsent(): void
    {
        $observer = new ObserverAccessor();
        $base = $observer->callGetBaseEventData(1);

        self::assertArrayNotHasKey('session_id', $base);
    }

    public function testGetBaseEventDataAttachesUserIdWhenLoggedIn(): void
    {
        $customerSession = \Mage::$singletons['customer/session'];
        $customerSession->loggedIn = true;
        $customerSession->customer = new class {
            public function getId(): int
            {
                return 42;
            }
        };

        $observer = new ObserverAccessor();
        $base = $observer->callGetBaseEventData(1);

        self::assertSame('42', $base['user_id']);
    }
}

class ObserverAccessor extends \Hirale_GAMeasurementProtocol_Model_Observer
{
    /**
     * @param array<string, mixed> $events
     */
    public function callAddToQueue(array $events, ?int $storeId): void
    {
        $this->addToQueue($events, $storeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function callGetBaseEventData(?int $storeId): array
    {
        return $this->getBaseEventData($storeId);
    }
}
