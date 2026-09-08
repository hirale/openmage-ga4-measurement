<?php

declare(strict_types=1);

use Hirale\Queue\Bus;
use Maho\Queue\QueueManager;

class Hirale_GAMeasurementProtocol_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const GA4_MEASUREMENT_PROTOCOL_URL = 'https://www.google-analytics.com/mp/collect';

    public const TRANSPORT_MEASUREMENT_PROTOCOL = 'measurement_protocol';
    public const TRANSPORT_DATA_MANAGER = 'data_manager';

    /** Queue GA4 uploads ride; config.xml routes it off the resident fast pool on Maho. */
    public const QUEUE_ANALYTICS = 'analytics';

    private const DISPATCHER_MAHO = 'maho';
    private const DISPATCHER_HIRALE = 'hirale';
    private const DISPATCHER_NONE = 'none';

    private const DEFAULT_LOG_FILE = 'ga_measurement.log';
    private const CACHE_KEY_NULL = '__current__';

    /** @var array<string, bool> */
    private array $_isMeasurementEnabled = [];

    /** @var array<string, string|null> */
    private array $_measurementId = [];

    /** @var array<string, string|null> */
    private array $_apiSecret = [];

    /** @var array<string, string> */
    private array $_logFile = [];

    /** @var array<string, bool> */
    private array $_isDebugMode = [];

    /** @var array<string, string> */
    private array $_transport = [];

    /** @var array<string, string|null> */
    private array $_dataManagerPropertyId = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $_serviceAccountKey = [];

    private ?string $_dispatcher = null;

    public function isMeasurementEnabled(?int $storeId = null): bool
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_isMeasurementEnabled)) {
            $this->_isMeasurementEnabled[$cacheKey] = (bool) Mage::getStoreConfig('google/measurement/enabled', $storeId);
        }

        return $this->_isMeasurementEnabled[$cacheKey];
    }

    public function isDebugMode(?int $storeId = null): bool
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_isDebugMode)) {
            $this->_isDebugMode[$cacheKey] = (bool) Mage::getStoreConfig('google/measurement/debug_mode', $storeId);
        }

        return $this->_isDebugMode[$cacheKey] && $this->isAllowedIp();
    }

    public function isAllowedIp(): bool
    {
        $raw = Mage::getStoreConfig(Mage_Core_Helper_Data::XML_PATH_DEV_ALLOW_IPS);
        if (empty($raw)) {
            return false;
        }

        return Mage::helper('core')->isDevAllowed();
    }

    public function getLogFile(?int $storeId = null): string
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_logFile)) {
            $value = (string) Mage::getStoreConfig('google/measurement/log_file', $storeId);
            $this->_logFile[$cacheKey] = $value !== '' ? $value : self::DEFAULT_LOG_FILE;
        }

        return $this->_logFile[$cacheKey];
    }

    public function getMeasurementProtocolUrl(): string
    {
        return self::GA4_MEASUREMENT_PROTOCOL_URL;
    }

    public function getMeasurementId(?int $storeId = null): ?string
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_measurementId)) {
            $value = Mage::getStoreConfig('google/measurement/measurement_id', $storeId);
            $this->_measurementId[$cacheKey] = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->_measurementId[$cacheKey];
    }

    /**
     * Effective transport for a store. Unknown or empty values fall back to
     * the Measurement Protocol so pre-v3 stores keep their behavior.
     */
    public function getTransport(?int $storeId = null): string
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_transport)) {
            $value = Mage::getStoreConfig('google/measurement/transport', $storeId);
            $this->_transport[$cacheKey] = $value === self::TRANSPORT_DATA_MANAGER
                ? self::TRANSPORT_DATA_MANAGER
                : self::TRANSPORT_MEASUREMENT_PROTOCOL;
        }

        return $this->_transport[$cacheKey];
    }

    public function getDataManagerPropertyId(?int $storeId = null): ?string
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_dataManagerPropertyId)) {
            $value = Mage::getStoreConfig('google/measurement/dm_property_id', $storeId);
            $this->_dataManagerPropertyId[$cacheKey] = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->_dataManagerPropertyId[$cacheKey];
    }

    /**
     * Decoded service-account key file for the Data Manager transport.
     *
     * @return array<string, mixed>|null
     */
    public function getServiceAccountKey(?int $storeId = null): ?array
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_serviceAccountKey)) {
            $this->_serviceAccountKey[$cacheKey] = null;
            $raw = Mage::getStoreConfig('google/measurement/dm_service_account_key', $storeId);
            if (is_string($raw) && $raw !== '') {
                $json = $this->resolveServiceAccountKeyJson($raw);
                $decoded = $json !== null ? json_decode($json, true) : null;
                $this->_serviceAccountKey[$cacheKey] = is_array($decoded) ? $decoded : null;
            }
        }

        return $this->_serviceAccountKey[$cacheKey];
    }

    /**
     * Resolve a stored dm_service_account_key config value to the key-file
     * JSON string. The backend model encrypts on every admin save, so the
     * normal path is decryption; a value seeded unencrypted (direct DB
     * write) still works but logs a warning, because silently honoring it
     * forever would hide that the credential is not encrypted at rest.
     */
    public function resolveServiceAccountKeyJson(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        $decrypted = (string) Mage::helper('core')->decrypt($stored);
        if (is_array(json_decode($decrypted, true))) {
            return $decrypted;
        }

        if (is_array(json_decode($stored, true))) {
            Mage::log(
                'google/measurement/dm_service_account_key is stored unencrypted; re-save the section in admin to encrypt it at rest.',
                null,
                '',
                true,
            );

            return $stored;
        }

        return null;
    }

    public function getApiSecret(?int $storeId = null): ?string
    {
        $cacheKey = $this->_cacheKey($storeId);
        if (!array_key_exists($cacheKey, $this->_apiSecret)) {
            $value = Mage::getStoreConfig('google/measurement/api_secret', $storeId);
            $this->_apiSecret[$cacheKey] = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->_apiSecret[$cacheKey];
    }

    /**
     * Resolve the GA4 client_id for the current visitor. Reuses the value
     * from the `_ga` cookie when set so client-side gtag.js and server-side
     * Measurement Protocol attribute to the same GA4 user.
     */
    public function getClientId(): string
    {
        $session = Mage::getSingleton('core/session');
        $clientId = $session->getData('ga_client_id');
        if (!$clientId) {
            if (isset($_COOKIE['_ga'])) {
                $ga = explode('.', $_COOKIE['_ga']);
                $clientId = ($ga[2] ?? '') . '.' . ($ga[3] ?? '');
            } else {
                $randomNumber = mt_rand(1000000000, 9999999999);
                $timestamp = time();
                $clientId = $randomNumber . '.' . $timestamp;
            }
            $session->setData('ga_client_id', $clientId);
        }

        return (string) $clientId;
    }

    /**
     * Extract the GA4 session_id from the `_ga_<MEASUREMENT_ID>` cookie set
     * by gtag.js on the storefront. Including session_id in server-side
     * Measurement Protocol events joins them to the same GA4 session as the
     * client-side gtag events, instead of GA4 inferring a fresh session per
     * server event.
     *
     * Returns null when measurement_id is unconfigured or the cookie is
     * absent (typical for non-storefront requests like API/cron).
     */
    public function getSessionId(?int $storeId = null): ?string
    {
        $measurementId = $this->getMeasurementId($storeId);
        if ($measurementId === null) {
            return null;
        }

        // _ga_<MID> cookies drop the `G-` prefix from the measurement id.
        $cookieName = '_ga_' . preg_replace('/^G-/', '', $measurementId);
        if (!isset($_COOKIE[$cookieName])) {
            return null;
        }

        // Cookie shape: GS1.1.<session_id>.<session_count>.<engagement>...
        $parts = explode('.', (string) $_COOKIE[$cookieName]);
        $sessionId = $parts[2] ?? '';

        return $sessionId !== '' ? $sessionId : null;
    }

    /**
     * @param int|string|float $price
     */
    public function formatPrice($price): float
    {
        return (float) number_format((float) $price, 2, '.', '');
    }

    public function isQueueEnabled(): bool
    {
        return $this->_resolveDispatcher() !== self::DISPATCHER_NONE;
    }

    /**
     * Hand one GA4 event envelope to whichever queue backend this install has.
     * Returns false when there is none, so an observer on the request path
     * stays silent instead of failing the page it is measuring.
     *
     * @param array<string, mixed> $events
     */
    public function enqueueMeasurementEvent(array $events, int $storeId, bool $debugMode): bool
    {
        $dispatcher = $this->_resolveDispatcher();
        if ($dispatcher === self::DISPATCHER_NONE) {
            return false;
        }

        try {
            $this->_dispatch($dispatcher, new Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage(
                events: $events,
                storeId: $storeId,
                debugMode: $debugMode,
            ));

            return true;
        } catch (Throwable $e) {
            Mage::logException($e);

            return false;
        }
    }

    /**
     * Which queue backend this install dispatches through. Maho's core queue
     * wins when the platform ships it, so a Maho store needs no third-party
     * queue package at all; hirale/queue remains the OpenMage backend.
     */
    private function _resolveDispatcher(): string
    {
        // Memoized: a page view can dispatch several event batches, and the
        // helper is a per-request singleton.
        if ($this->_dispatcher === null) {
            $this->_dispatcher = match (true) {
                $this->_isMahoQueueAvailable() => self::DISPATCHER_MAHO,
                $this->_isHiraleQueueAvailable() => self::DISPATCHER_HIRALE,
                default => self::DISPATCHER_NONE,
            };
        }

        return $this->_dispatcher;
    }

    private function _isMahoQueueAvailable(): bool
    {
        if (!class_exists(QueueManager::class)) {
            return false;
        }

        $core = Mage::helper('core');

        return $core instanceof Mage_Core_Helper_Abstract && $core->isModuleEnabled('Maho_Queue');
    }

    /** Protected only so the unit suite can simulate an install with no queue package at all. */
    protected function _isHiraleQueueAvailable(): bool
    {
        return class_exists(Bus::class);
    }

    private function _dispatch(string $dispatcher, object $message): void
    {
        if ($dispatcher === self::DISPATCHER_MAHO) {
            QueueManager::dispatch(message: $message, queue: self::QUEUE_ANALYTICS);

            return;
        }

        // hirale/queue takes the queue from its own <routing> in config.xml,
        // which already puts this message class on the analytics queue.
        Bus::dispatch($message);
    }

    private function _cacheKey(?int $storeId): string
    {
        return $storeId === null ? self::CACHE_KEY_NULL : (string) $storeId;
    }
}
