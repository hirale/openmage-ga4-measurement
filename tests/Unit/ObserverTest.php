<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use Hirale\Queue\Bus;
use HiraleGAMeasurementProtocol\Tests\Support\AppStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\GoogleAnalyticsHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\HttpHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\StringHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\StoreStub;
use HiraleGAMeasurementProtocol\Tests\Support\UrlHelperStub;
use PHPUnit\Framework\TestCase;

class ObserverTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        Bus::reset();
        \Mage::$helpers['gameasurementprotocol'] = new \Hirale_GAMeasurementProtocol_Helper_Data();
        \Mage::$helpers['googleanalytics'] = new GoogleAnalyticsHelperStub();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/string'] = new StringHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
        \Mage::$helpers['core'] = new CoreHelperStub();
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
        Bus::reset();
    }

    public function testAddToQueueDispatchesMessageWithStoreIdAndDebugFlag(): void
    {
        $observer = new ObserverAccessor();
        $observer->callAddToQueue(['events' => [['name' => 'login', 'params' => []]]], 7);

        self::assertCount(1, Bus::$dispatches);
        $message = Bus::$dispatches[0]['message'];
        self::assertInstanceOf(\Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage::class, $message);
        self::assertSame(7, $message->storeId);
        self::assertFalse($message->debugMode);
        // Internal flags stay on the message; the posted envelope is clean.
        self::assertArrayNotHasKey('_store_id', $message->events);
        self::assertArrayNotHasKey('_debug_mode', $message->events);
    }

    public function testAddToQueueFallsBackToCurrentStoreWhenStoreIdMissing(): void
    {
        \Mage::$app = new AppStub(42);
        \Mage::$config['42'] = ['google/measurement/enabled' => '1'];

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(['events' => [['name' => 'page_view', 'params' => []]]], null);

        self::assertSame(42, Bus::$dispatches[0]['message']->storeId);
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

        $events = Bus::$dispatches[0]['message']->events['events'];
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('TestUA/1.0', $event['params']['user_agent']);
            self::assertSame('"macOS"', $event['params']['platform']);
        }
    }

    public function testAddToQueueSwallowsDispatchExceptions(): void
    {
        Bus::$nextException = new \RuntimeException('redis down');

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
