<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use Hirale\Queue\Bus;
use HiraleGAMeasurementProtocol\Tests\Support\AppStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\EncryptedConfigValue;
use HiraleGAMeasurementProtocol\Tests\Support\CoreSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\CartItemStub;
use HiraleGAMeasurementProtocol\Tests\Support\CheckoutSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\CustomerStub;
use HiraleGAMeasurementProtocol\Tests\Support\ThrowingHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\CreditmemoItemStub;
use HiraleGAMeasurementProtocol\Tests\Support\CreditmemoStub;
use HiraleGAMeasurementProtocol\Tests\Support\GoogleAnalyticsHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\OrderStub;
use HiraleGAMeasurementProtocol\Tests\Support\ProductStub;
use HiraleGAMeasurementProtocol\Tests\Support\QuoteStub;
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
        \Mage::$config['1']['google/measurement/api_secret'] = EncryptedConfigValue::of('secret-1');
        \Mage::$config['7']['google/measurement/enabled'] = '1';
        \Mage::$config['7']['google/measurement/measurement_id'] = 'G-STORE7';
        \Mage::$config['7']['google/measurement/api_secret'] = EncryptedConfigValue::of('secret-7');
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

    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    public function testAddToCartValueReflectsOnlyAddedUnits(): void
    {
        \Mage::$helpers['core/http']->userAgent = self::CHROME_UA;
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub(100));

        // Existing row raised from qty 2 -> 3: one unit added, row total 30.
        $item = new CartItemStub(
            id: 5,
            qty: 3.0,
            origQty: 2.0,
            basePrice: 10.0,
            baseRowTotal: 30.0,
            quoteId: 100,
            storeId: 1,
            isNew: false,
            hasChanges: true,
            product: new ProductStub('SKU-1', 'Item One'),
        );

        $observer = new \Hirale_GAMeasurementProtocol_Model_Observer();
        $observer->addOrRemoveItemsFromCart(new \Varien_Event_Observer(new \Varien_Event(['item' => $item])));

        self::assertCount(1, Bus::$dispatches);
        $event = Bus::$dispatches[0]['message']->events['events'][0];
        self::assertSame('add_to_cart', $event['name']);
        // 1 added unit * 10.00, NOT the full base row total (30.00).
        self::assertSame(10.0, $event['params']['value']);
        self::assertSame(1.0, $event['params']['items'][0]['quantity']);
    }

    public function testCaptureOrderClientIdPersistsVisitorIds(): void
    {
        \Mage::$singletons['core/session']->setData('ga_client_id', '333.444');
        $_COOKIE['_ga_STORE1'] = 'GS1.1.1700000123.5.0.1700000150.0.0';

        try {
            $order = new OrderStub(['store_id' => 1]);
            $observer = new \Hirale_GAMeasurementProtocol_Model_Observer();
            $observer->captureOrderClientId(new \Varien_Event_Observer(new \Varien_Event(['order' => $order])));

            self::assertSame('333.444', $order->data['ga_client_id']);
            self::assertSame('1700000123', $order->data['ga_session_id']);
        } finally {
            unset($_COOKIE['_ga_STORE1']);
        }
    }

    public function testCaptureOrderClientIdSkipsWhenAlreadySet(): void
    {
        \Mage::$singletons['core/session']->setData('ga_client_id', '333.444');
        $order = new OrderStub(['store_id' => 1, 'ga_client_id' => 'kept.value']);

        $observer = new \Hirale_GAMeasurementProtocol_Model_Observer();
        $observer->captureOrderClientId(new \Varien_Event_Observer(new \Varien_Event(['order' => $order])));

        self::assertSame('kept.value', $order->data['ga_client_id']);
    }

    public function testRefundDispatchesEventWithStoredClientId(): void
    {
        \Mage::$helpers['core/http']->userAgent = self::CHROME_UA;
        $order = new OrderStub([
            'store_id' => 1,
            'increment_id' => '100000001',
            'base_currency_code' => 'USD',
            'customer_id' => 42,
            'ga_client_id' => '111.222',
            'ga_session_id' => '1700000123',
        ]);
        $memo = new CreditmemoStub($order, 25.5, [
            new CreditmemoItemStub('SKU-1', 'Item One', 10.0, 2.0),
            new CreditmemoItemStub('SKU-CHILD', 'Simple child', 5.0, 1.0, parentItemId: 7),
            new CreditmemoItemStub('SKU-ZERO', 'Not refunded', 5.0, 0.0),
        ]);

        $observer = new \Hirale_GAMeasurementProtocol_Model_Observer();
        $observer->refund(new \Varien_Event_Observer(new \Varien_Event(['creditmemo' => $memo])));

        self::assertCount(1, Bus::$dispatches);
        $message = Bus::$dispatches[0]['message'];
        self::assertSame(1, $message->storeId);

        $envelope = $message->events;
        self::assertSame('111.222', $envelope['client_id']);
        self::assertSame('1700000123', $envelope['session_id']);
        self::assertSame('42', $envelope['user_id']);

        $event = $envelope['events'][0];
        self::assertSame('refund', $event['name']);
        self::assertSame('100000001', $event['params']['transaction_id']);
        self::assertSame(25.5, $event['params']['value']);
        // Child rows and zero-qty rows are filtered out.
        self::assertCount(1, $event['params']['items']);
        self::assertSame('SKU-1', $event['params']['items'][0]['item_id']);
        self::assertSame(2.0, $event['params']['items'][0]['quantity']);
    }

    public function testRefundFallsBackToDeterministicClientId(): void
    {
        \Mage::$helpers['core/http']->userAgent = self::CHROME_UA;
        $order = new OrderStub([
            'store_id' => 1,
            'increment_id' => '100000002',
            'base_currency_code' => 'USD',
        ]);
        $memo = new CreditmemoStub($order, 9.99, []);

        $observer = new \Hirale_GAMeasurementProtocol_Model_Observer();
        $observer->refund(new \Varien_Event_Observer(new \Varien_Event(['creditmemo' => $memo])));

        $envelope = Bus::$dispatches[0]['message']->events;
        $expected = sprintf('%u.%u', crc32('ga_cid_100000002'), crc32('100000002_ga_cid'));
        self::assertSame($expected, $envelope['client_id']);
        self::assertMatchesRegularExpression('/^\d+\.\d+$/', $envelope['client_id']);
        self::assertArrayNotHasKey('session_id', $envelope);
        self::assertArrayNotHasKey('user_id', $envelope);
    }
    private function routeObserver(string $module, string $controller, string $action): \Varien_Event_Observer
    {
        \Mage::$app->request->setRoute($module, $controller, $action);

        return new \Varien_Event_Observer(new \Varien_Event(['app' => \Mage::$app]));
    }

    public function testPurchaseIsReportedOnlyOncePerOrder(): void
    {
        $order = new OrderStub(['increment_id' => '100000001', 'store_id' => 1]);
        $session = new CheckoutSessionStub(new QuoteStub());
        $session->lastRealOrder = $order;
        \Mage::$singletons['checkout/session'] = $session;

        $observer = new class extends \Hirale_GAMeasurementProtocol_Model_Observer {
            #[\Override]
            protected function getPurchaseEvent($currency)
            {
                return ['name' => 'purchase', 'params' => ['transaction_id' => '100000001']];
            }
        };

        $event = $this->routeObserver('checkout', 'onepage', 'success');
        $observer->dispatchRouteEvent($event);
        $observer->dispatchRouteEvent($event);

        self::assertCount(2, Bus::$dispatches, 'both renders still report the page view');
        self::assertSame(
            ['purchase', 'page_view'],
            array_column(Bus::$dispatches[0]['message']->events['events'], 'name'),
        );
        // The reload must not send a second purchase for the same order:
        // last_real_order_id survives on the session, so getLastRealOrder()
        // keeps resolving it.
        self::assertSame(
            ['page_view'],
            array_column(Bus::$dispatches[1]['message']->events['events'], 'name'),
        );
    }

    public function testPurchaseIsReportedAgainForADifferentOrder(): void
    {
        $session = new CheckoutSessionStub(new QuoteStub());
        $session->lastRealOrder = new OrderStub(['increment_id' => '100000001', 'store_id' => 1]);
        \Mage::$singletons['checkout/session'] = $session;

        $observer = new class extends \Hirale_GAMeasurementProtocol_Model_Observer {
            #[\Override]
            protected function getPurchaseEvent($currency)
            {
                return ['name' => 'purchase', 'params' => []];
            }
        };

        $observer->dispatchRouteEvent($this->routeObserver('checkout', 'onepage', 'success'));
        $session->lastRealOrder = new OrderStub(['increment_id' => '100000002', 'store_id' => 1]);
        $observer->dispatchRouteEvent($this->routeObserver('checkout', 'onepage', 'success'));

        foreach (Bus::$dispatches as $dispatch) {
            self::assertContains('purchase', array_column($dispatch['message']->events['events'], 'name'));
        }
    }

    public function testRedirectResponseReportsNothingAtAll(): void
    {
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());
        // Empty cart bouncing back to the basket: begin_checkout used to be
        // built and queued anyway, with a zero value and no items.
        \Mage::$app->response->httpResponseCode = 302;
        \Mage::$app->response->body = '';

        (new \Hirale_GAMeasurementProtocol_Model_Observer())
            ->dispatchRouteEvent($this->routeObserver('checkout', 'onepage', 'index'));

        self::assertSame([], Bus::$dispatches);
    }

    public function testNonHtmlSuccessResponseReportsNothingAtAll(): void
    {
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());
        \Mage::$app->response->body = '{"ok":true}';

        (new \Hirale_GAMeasurementProtocol_Model_Observer())
            ->dispatchRouteEvent($this->routeObserver('checkout', 'cart', 'index'));

        self::assertSame([], Bus::$dispatches);
    }

    public function testRenderedPageStillReportsPageView(): void
    {
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());

        (new \Hirale_GAMeasurementProtocol_Model_Observer())
            ->dispatchRouteEvent($this->routeObserver('cms', 'index', 'index'));

        self::assertCount(1, Bus::$dispatches);
        self::assertSame(
            ['page_view'],
            array_column(Bus::$dispatches[0]['message']->events['events'], 'name'),
        );
    }

    /**
     * Analytics must never break the flow it observes: a payload-building
     * failure is logged and dropped, never propagated into a cart save, a
     * login or a credit memo.
     *
     * @dataProvider guardedEntryPoints
     */
    public function testEntryPointSwallowsPayloadFailures(string $method, callable $eventFactory): void
    {
        \Mage::$helpers['gameasurementprotocol'] = new ThrowingHelperStub();
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub(100));

        $observer = new \Hirale_GAMeasurementProtocol_Model_Observer();
        $observer->{$method}($eventFactory());

        self::assertSame([], Bus::$dispatches);
        self::assertCount(1, \Mage::$exceptions);
        self::assertInstanceOf(\TypeError::class, \Mage::$exceptions[0]);
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function guardedEntryPoints(): array
    {
        $bare = static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(new \Varien_Event([]));
        $customer = static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
            new \Varien_Event(['customer' => new CustomerStub(7, 1)]),
        );

        return [
            'generateClientId' => ['generateClientId', $bare],
            'addOrRemoveItemsFromCart' => ['addOrRemoveItemsFromCart', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['item' => new CartItemStub(
                    id: 5,
                    qty: 1.0,
                    origQty: null,
                    basePrice: 10.0,
                    baseRowTotal: 10.0,
                    quoteId: 100,
                    storeId: 1,
                    isNew: true,
                    hasChanges: true,
                    product: new ProductStub(),
                )]),
            )],
            'signUp' => ['signUp', $customer],
            'login' => ['login', $customer],
            'addToWishlist' => ['addToWishlist', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['items' => [new ProductStub()]]),
            )],
            'dispatchRouteEvent' => ['dispatchRouteEvent', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['app' => \Mage::$app]),
            )],
            'captureOrderClientId' => ['captureOrderClientId', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['order' => new OrderStub(['store_id' => 1])]),
            )],
            'refund' => ['refund', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['creditmemo' => new CreditmemoStub(new OrderStub(['store_id' => 1]), 10.0, [])]),
            )],
        ];
    }


    public function testDoctypeBeyondAnyLeadingWindowStillCountsAsARenderedPage(): void
    {
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());
        // A BOM, a licence comment or plain whitespace can push the doctype
        // well past the head of the body; this guard now gates every route
        // event, so a fixed window would silently stop all reporting.
        \Mage::$app->response->body = str_repeat(' ', 200) . '<!DOCTYPE html><html><body>ok</body></html>';

        (new \Hirale_GAMeasurementProtocol_Model_Observer())
            ->dispatchRouteEvent($this->routeObserver('cms', 'index', 'index'));

        self::assertCount(1, Bus::$dispatches);
        self::assertSame(
            ['page_view'],
            array_column(Bus::$dispatches[0]['message']->events['events'], 'name'),
        );
    }

    public function testReloadedSuccessPageIsBlockedByTheResponseGuardAlone(): void
    {
        // OpenMage closes the session before core_app_run_after dispatches, so
        // claimPurchaseReport() never persists there. The 302 a reloaded
        // success page returns is what has to stop the duplicate on both
        // platforms — assert it does, with no mark in play.
        $session = new CheckoutSessionStub(new QuoteStub());
        $session->lastRealOrder = new OrderStub(['increment_id' => '100000001', 'store_id' => 1]);
        \Mage::$singletons['checkout/session'] = $session;
        \Mage::$app->response->httpResponseCode = 302;
        \Mage::$app->response->body = '';

        $observer = new class extends \Hirale_GAMeasurementProtocol_Model_Observer {
            #[\Override]
            protected function getPurchaseEvent($currency)
            {
                return ['name' => 'purchase', 'params' => []];
            }
        };
        $observer->dispatchRouteEvent($this->routeObserver('checkout', 'onepage', 'success'));

        self::assertSame([], Bus::$dispatches);
        self::assertNull(
            $session->getData(\Hirale_GAMeasurementProtocol_Model_Observer::SESSION_REPORTED_PURCHASE),
            'the guard must hold before the claim is ever consulted',
        );
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
