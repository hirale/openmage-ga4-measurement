<?php
class Hirale_GAMeasurementProtocol_Helper_Data extends Mage_Core_Helper_Abstract
{
    const GA4_MEASUREMENT_PROTOCOL_URL = 'https://www.google-analytics.com/mp/collect';
    const GTAG_URL = 'https://www.googletagmanager.com/gtag/destination';
    protected $_isMeasurementEnabled = null;
    protected $_measurementId = null;
    protected $_apiSecret = null;
    protected $_isDebugMode = null;

    public function isMeasurementEnabled()
    {
        if (is_null($this->_isMeasurementEnabled)) {
            $this->_isMeasurementEnabled = Mage::getStoreConfig('google/measurement/enabled');
        }
        return $this->_isMeasurementEnabled;
    }

    public function isDebugMode()
    {
        if (is_null($this->_isDebugMode)) {
            $this->_isDebugMode = Mage::getStoreConfig('google/measurement/debug_mode');
        }
        return $this->_isDebugMode;
    }

    public function getMeasurementProtocolUrl()
    {
        return self::GA4_MEASUREMENT_PROTOCOL_URL;
    }


    public function getMeasurementId()
    {
        if (is_null($this->_measurementId)) {
            $this->_measurementId = Mage::getStoreConfig('google/measurement/measurement_id');
        }
        return $this->_measurementId;
    }

    public function getApiSecret()
    {
        if (is_null($this->_apiSecret)) {
            $this->_apiSecret = Mage::getStoreConfig('google/measurement/api_secret');
        }
        return $this->_apiSecret;
    }


    public function getClientId()
    {
        $session = Mage::getSingleton('core/session');
        $clientId = $session->getData('ga_client_id');
        if (!$clientId) {
            if (isset($_COOKIE['_ga'])) {
                $ga = explode('.', $_COOKIE['_ga']);
                $clientId = $ga[2] . '.' . $ga[3];
            } else {
                $randomNumber = mt_rand(1000000000, 9999999999);
                $timestamp = time();
                $clientId = $randomNumber . '.' . $timestamp;
            }
            $session->setData('ga_client_id', $clientId);
        }

        return $clientId;
    }

    public function formatPrice($price)
    {
        return (float) number_format($price, 2, '.', '');
    }
}
