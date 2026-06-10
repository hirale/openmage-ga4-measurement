<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit\DataManager;

use Google\Ads\DataManager\V1\ConsentStatus;
use Google\Ads\DataManager\V1\Event;
use Google\Ads\DataManager\V1\EventSource;
use Google\Ads\DataManager\V1\ProductAccount\AccountType;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the translator against the real googleads/data-manager protos —
 * if the alpha library changes its surface, these tests are the tripwire.
 */
class TranslatorTest extends TestCase
{
    private \Hirale_GAMeasurementProtocol_Model_DataManager_Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new \Hirale_GAMeasurementProtocol_Model_DataManager_Translator();
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseEnvelope(): array
    {
        return [
            'client_id' => '1234567890.1700000000',
            'timestamp_micros' => 1717171717123456,
            'non_personalized_ads' => true,
            'session_id' => '1700000000',
            'user_id' => '42',
            'events' => [
                [
                    'name' => 'purchase',
                    'params' => [
                        'transaction_id' => 'ORD-100',
                        'currency' => 'USD',
                        'value' => 59.97,
                        'coupon' => 'SUMMER',
                        'shipping' => 5.0,
                        'engagement_time_msec' => 100,
                        'debug_mode' => true,
                        'user_agent' => 'Mozilla/5.0 (test)',
                        'platform' => 'Linux',
                        'items' => [
                            [
                                'item_id' => 'SKU-1',
                                'item_name' => 'Item One',
                                'price' => 19.99,
                                'quantity' => 3,
                                'discount' => 2.5,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param iterable<object> $repeated
     * @return array<string, string>
     */
    private function parameterMap(iterable $repeated): array
    {
        $map = [];
        foreach ($repeated as $parameter) {
            $map[$parameter->getParameterName()] = $parameter->getValue();
        }

        return $map;
    }

    public function testBuildsDestinationForGaProperty(): void
    {
        $request = $this->translator->toIngestEventsRequest($this->purchaseEnvelope(), '213025502', 'G-TEST1');

        self::assertCount(1, $request->getDestinations());
        $destination = $request->getDestinations()[0];
        self::assertSame('G-TEST1', $destination->getProductDestinationId());
        self::assertSame(AccountType::GOOGLE_ANALYTICS_PROPERTY, $destination->getOperatingAccount()->getAccountType());
        self::assertSame('213025502', $destination->getOperatingAccount()->getAccountId());
        self::assertFalse($request->getValidateOnly());
    }

    public function testValidateOnlyFlagIsForwarded(): void
    {
        $request = $this->translator->toIngestEventsRequest($this->purchaseEnvelope(), '213025502', 'G-TEST1', true);

        self::assertTrue($request->getValidateOnly());
    }

    public function testMapsEnvelopeIdentityOntoEachEvent(): void
    {
        $events = $this->translator->toEvents($this->purchaseEnvelope());

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('purchase', $event->getEventName());
        self::assertSame(EventSource::WEB, $event->getEventSource());
        self::assertSame('1234567890.1700000000', $event->getClientId());
        self::assertSame('42', $event->getUserId());
    }

    public function testTimestampMicrosBecomesProtoTimestamp(): void
    {
        $event = $this->translator->toEvents($this->purchaseEnvelope())[0];

        $timestamp = $event->getEventTimestamp();
        self::assertNotNull($timestamp);
        self::assertSame(1717171717, $timestamp->getSeconds());
        self::assertSame(123456000, $timestamp->getNanos());
    }

    public function testNonPersonalizedAdsBecomesDeniedAdPersonalization(): void
    {
        $event = $this->translator->toEvents($this->purchaseEnvelope())[0];

        $consent = $event->getConsent();
        self::assertNotNull($consent);
        self::assertSame(ConsentStatus::CONSENT_DENIED, $consent->getAdPersonalization());
        // ad_user_data is intentionally left unspecified.
        self::assertSame(ConsentStatus::CONSENT_STATUS_UNSPECIFIED, $consent->getAdUserData());
    }

    public function testConsentOmittedWhenNonPersonalizedAdsAbsent(): void
    {
        $envelope = $this->purchaseEnvelope();
        unset($envelope['non_personalized_ads']);

        $event = $this->translator->toEvents($envelope)[0];

        self::assertNull($event->getConsent());
    }

    public function testTypedCommerceFieldsAreLifted(): void
    {
        $event = $this->translator->toEvents($this->purchaseEnvelope())[0];

        self::assertSame('USD', $event->getCurrency());
        self::assertSame(59.97, $event->getConversionValue());
        self::assertSame('ORD-100', $event->getTransactionId());
    }

    public function testUserAgentBecomesDeviceInfoAndIsNotDuplicatedAsParameter(): void
    {
        $event = $this->translator->toEvents($this->purchaseEnvelope())[0];

        $device = $event->getEventDeviceInfo();
        self::assertNotNull($device);
        self::assertSame('Mozilla/5.0 (test)', $device->getUserAgent());
        self::assertArrayNotHasKey('user_agent', $this->parameterMap($event->getAdditionalEventParameters()));
    }

    public function testItemsBecomeCartDataWithTypedAndAdditionalFields(): void
    {
        $event = $this->translator->toEvents($this->purchaseEnvelope())[0];

        $cartData = $event->getCartData();
        self::assertNotNull($cartData);
        self::assertCount(1, $cartData->getItems());

        $item = $cartData->getItems()[0];
        self::assertSame('SKU-1', $item->getItemId());
        self::assertSame(3, (int) $item->getQuantity());
        self::assertSame(19.99, $item->getUnitPrice());

        $itemParams = $this->parameterMap($item->getAdditionalItemParameters());
        self::assertSame('Item One', $itemParams['item_name']);
        self::assertSame('2.5', $itemParams['discount']);
    }

    public function testFractionalQuantityIsRoundedAndClampedToOne(): void
    {
        $envelope = $this->purchaseEnvelope();
        $envelope['events'][0]['params']['items'][0]['quantity'] = 0.5;
        $envelope['events'][0]['params']['items'][] = [
            'item_id' => 'SKU-2',
            'price' => 9.99,
            'quantity' => 1.5,
        ];

        $items = $this->translator->toEvents($envelope)[0]->getCartData()->getItems();

        self::assertSame(1, (int) $items[0]->getQuantity());
        self::assertSame(2, (int) $items[1]->getQuantity());
    }

    public function testCartDataOmittedWithoutItems(): void
    {
        $envelope = $this->purchaseEnvelope();
        unset($envelope['events'][0]['params']['items']);

        self::assertNull($this->translator->toEvents($envelope)[0]->getCartData());
    }

    public function testRemainingParamsAndEnvelopeSessionIdBecomeAdditionalParameters(): void
    {
        $event = $this->translator->toEvents($this->purchaseEnvelope())[0];

        $params = $this->parameterMap($event->getAdditionalEventParameters());
        self::assertSame('1700000000', $params['session_id']);
        self::assertSame('SUMMER', $params['coupon']);
        self::assertSame('5', $params['shipping']);
        self::assertSame('100', $params['engagement_time_msec']);
        self::assertSame('true', $params['debug_mode']);
        self::assertSame('Linux', $params['platform']);
        // Typed fields must not be duplicated as parameters.
        self::assertArrayNotHasKey('currency', $params);
        self::assertArrayNotHasKey('value', $params);
        self::assertArrayNotHasKey('transaction_id', $params);
        self::assertArrayNotHasKey('items', $params);
    }

    public function testMultiEventEnvelopeTranslatesEveryNamedEvent(): void
    {
        $envelope = [
            'client_id' => 'cid.1',
            'events' => [
                ['name' => 'view_item', 'params' => ['currency' => 'EUR', 'value' => 10]],
                ['name' => 'page_view', 'params' => []],
                ['params' => ['orphan' => 1]], // nameless entries are skipped
            ],
        ];

        $events = $this->translator->toEvents($envelope);

        self::assertCount(2, $events);
        self::assertSame(['view_item', 'page_view'], array_map(
            static fn (Event $event): string => $event->getEventName(),
            $events,
        ));
        self::assertSame('cid.1', $events[1]->getClientId());
    }

    public function testEnvelopeWithoutEventsYieldsEmptyList(): void
    {
        self::assertSame([], $this->translator->toEvents(['client_id' => 'cid.1']));
    }
}
