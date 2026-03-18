<?php
class Hirale_GAMeasurementProtocol_Model_Api implements Hirale_Queue_Model_TaskHandlerInterface
{
    protected $helper;

    public function __construct()
    {
        $this->helper = Mage::helper('gameasurementprotocol');
    }

    public function handle($data)
    {
        $event = $data['data'];
        $url = $this->helper->getMeasurementProtocolUrl();
        $measurementId = $this->helper->getMeasurementId();
        $apiSecret = $this->helper->getApiSecret();

        if (!$url || !$measurementId || !$apiSecret) {
            return;
        }

        $url .= "?measurement_id=$measurementId&api_secret=$apiSecret";
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
        ]);

        curl_exec($ch);
        if ($this->helper->isDebugMode()) {
            $eventTime = isset($event['timestamp_micros'])
                ? date('Y-m-d H:i:s', (int) ($event['timestamp_micros'] / 1000000))
                : 'unknown';
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Mage::log("[$eventTime] HTTP $httpCode $payload", null, $this->helper->getLogFile());
        }
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
    }
}
