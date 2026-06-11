<?php

declare(strict_types=1);

use Google\Ads\DataManager\V1\CartData;
use Google\Ads\DataManager\V1\Consent;
use Google\Ads\DataManager\V1\ConsentStatus;
use Google\Ads\DataManager\V1\Destination;
use Google\Ads\DataManager\V1\DeviceInfo;
use Google\Ads\DataManager\V1\Event;
use Google\Ads\DataManager\V1\EventParameter;
use Google\Ads\DataManager\V1\EventSource;
use Google\Ads\DataManager\V1\IngestEventsRequest;
use Google\Ads\DataManager\V1\Item;
use Google\Ads\DataManager\V1\ItemParameter;
use Google\Ads\DataManager\V1\ProductAccount;
use Google\Ads\DataManager\V1\ProductAccount\AccountType;
use Google\Protobuf\Timestamp;

/**
 * Translates the Measurement Protocol envelope built by the Observer into a
 * Data Manager API IngestEventsRequest, following Google's official MP→DM
 * field mappings. Pure (no Mage access) so it can be tested against the real
 * proto classes, and the only place besides the handler/tester that touches
 * the alpha googleads/data-manager surface.
 */
class Hirale_GAMeasurementProtocol_Model_DataManager_Translator
{
    /**
     * MP params that map to typed Event fields; everything else becomes an
     * additional event parameter.
     */
    private const TYPED_EVENT_PARAMS = ['currency', 'value', 'transaction_id', 'user_agent', 'items'];

    /**
     * MP item keys that map to typed Item fields; everything else becomes an
     * additional item parameter.
     */
    private const TYPED_ITEM_KEYS = ['item_id', 'quantity', 'price'];

    /**
     * @param array<string, mixed> $envelope the queued MP envelope
     */
    public function toIngestEventsRequest(array $envelope, string $propertyId, string $measurementId, bool $validateOnly = false): IngestEventsRequest
    {
        $request = new IngestEventsRequest();
        $request->setDestinations([$this->buildDestination($propertyId, $measurementId)]);
        $request->setEvents($this->toEvents($envelope));
        $request->setValidateOnly($validateOnly);

        return $request;
    }

    public function buildDestination(string $propertyId, string $measurementId): Destination
    {
        $account = new ProductAccount();
        $account->setAccountType(AccountType::GOOGLE_ANALYTICS_PROPERTY);
        $account->setAccountId($propertyId);

        $destination = new Destination();
        $destination->setOperatingAccount($account);
        $destination->setProductDestinationId($measurementId);

        return $destination;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<Event>
     */
    public function toEvents(array $envelope): array
    {
        // Proto string fields reject non-UTF8 bytes (GPBUtil checkString) —
        // sanitize at the boundary so a poisoned envelope can never abort
        // translation, even when this class is called outside the handler
        // (Validate Destination probe).
        $envelope = Hirale_GAMeasurementProtocol_Model_Utf8::deep($envelope);

        $events = [];
        $mpEvents = $envelope['events'] ?? [];
        if (is_array($mpEvents)) {
            foreach ($mpEvents as $mpEvent) {
                if (is_array($mpEvent) && isset($mpEvent['name'])) {
                    $events[] = $this->toEvent($mpEvent, $envelope);
                }
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $mpEvent
     * @param array<string, mixed> $envelope
     */
    private function toEvent(array $mpEvent, array $envelope): Event
    {
        $event = new Event();
        $event->setEventName((string) $mpEvent['name']);
        $event->setEventSource(EventSource::WEB);

        if (isset($envelope['client_id']) && (string) $envelope['client_id'] !== '') {
            $event->setClientId((string) $envelope['client_id']);
        }
        if (isset($envelope['user_id']) && (string) $envelope['user_id'] !== '') {
            $event->setUserId((string) $envelope['user_id']);
        }
        if (isset($envelope['timestamp_micros'])) {
            $event->setEventTimestamp($this->toTimestamp((int) $envelope['timestamp_micros']));
        }
        if (($envelope['non_personalized_ads'] ?? false) === true) {
            $consent = new Consent();
            $consent->setAdPersonalization(ConsentStatus::CONSENT_DENIED);
            $event->setConsent($consent);
        }

        $params = is_array($mpEvent['params'] ?? null) ? $mpEvent['params'] : [];

        if (isset($params['currency']) && (string) $params['currency'] !== '') {
            $event->setCurrency((string) $params['currency']);
        }
        if (isset($params['value']) && is_numeric($params['value'])) {
            $event->setConversionValue((float) $params['value']);
        }
        if (isset($params['transaction_id']) && (string) $params['transaction_id'] !== '') {
            $event->setTransactionId((string) $params['transaction_id']);
        }
        if (isset($params['user_agent']) && (string) $params['user_agent'] !== '') {
            $device = new DeviceInfo();
            $device->setUserAgent((string) $params['user_agent']);
            $event->setEventDeviceInfo($device);
        }

        $items = $params['items'] ?? null;
        if (is_array($items) && $items !== []) {
            $event->setCartData($this->toCartData($items));
        }

        $event->setAdditionalEventParameters($this->toEventParameters($params, $envelope));

        return $event;
    }

    private function toTimestamp(int $timestampMicros): Timestamp
    {
        $timestamp = new Timestamp();
        $timestamp->setSeconds(intdiv($timestampMicros, 1_000_000));
        $timestamp->setNanos(($timestampMicros % 1_000_000) * 1000);

        return $timestamp;
    }

    /**
     * @param list<mixed> $items
     */
    private function toCartData(array $items): CartData
    {
        $cartItems = [];
        foreach ($items as $mpItem) {
            if (is_array($mpItem)) {
                $cartItems[] = $this->toItem($mpItem);
            }
        }

        $cartData = new CartData();
        $cartData->setItems($cartItems);

        return $cartData;
    }

    /**
     * @param array<string, mixed> $mpItem
     */
    private function toItem(array $mpItem): Item
    {
        $item = new Item();
        if (isset($mpItem['item_id'])) {
            $item->setItemId((string) $mpItem['item_id']);
        }
        if (isset($mpItem['quantity']) && is_numeric($mpItem['quantity'])) {
            // DM quantity is int64; MP allows fractional quantities (partial
            // refunds). Round and clamp — the monetary amount still travels
            // in conversion_value/unit_price.
            $item->setQuantity(max(1, (int) round((float) $mpItem['quantity'])));
        }
        if (isset($mpItem['price']) && is_numeric($mpItem['price'])) {
            $item->setUnitPrice((float) $mpItem['price']);
        }

        $additional = [];
        foreach ($mpItem as $name => $value) {
            if (in_array((string) $name, self::TYPED_ITEM_KEYS, true) || $value === null) {
                continue;
            }
            $parameter = new ItemParameter();
            $parameter->setParameterName((string) $name);
            $parameter->setValue($this->stringifyValue($value));
            $additional[] = $parameter;
        }
        if ($additional !== []) {
            $item->setAdditionalItemParameters($additional);
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $envelope
     * @return list<EventParameter>
     */
    private function toEventParameters(array $params, array $envelope): array
    {
        $parameters = [];

        // MP quirk: the Observer carries session_id at the envelope level;
        // DM expects it as a per-event parameter.
        if (isset($envelope['session_id']) && (string) $envelope['session_id'] !== '') {
            $parameters[] = $this->toEventParameter('session_id', (string) $envelope['session_id']);
        }

        foreach ($params as $name => $value) {
            if (in_array((string) $name, self::TYPED_EVENT_PARAMS, true) || $value === null) {
                continue;
            }
            $parameters[] = $this->toEventParameter((string) $name, $this->stringifyValue($value));
        }

        return $parameters;
    }

    private function toEventParameter(string $name, string $value): EventParameter
    {
        $parameter = new EventParameter();
        $parameter->setParameterName($name);
        $parameter->setValue($value);

        return $parameter;
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
