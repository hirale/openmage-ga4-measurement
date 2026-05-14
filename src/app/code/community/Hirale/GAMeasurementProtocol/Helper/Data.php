<?php

declare(strict_types=1);

class Hirale_GAMeasurementProtocol_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const GA4_MEASUREMENT_PROTOCOL_URL = 'https://www.google-analytics.com/mp/collect';
    public const GTAG_URL = 'https://www.googletagmanager.com/gtag/destination';

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

    private function _cacheKey(?int $storeId): string
    {
        return $storeId === null ? self::CACHE_KEY_NULL : (string) $storeId;
    }
}
