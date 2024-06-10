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
        $event = $data['data']['event'];
        $url = $this->helper->getMeasurementProtocolUrl();
        $measurementId = $this->helper->getMeasurementId();
        $apiSecret = $this->helper->getApiSecret();

        if (!$url || !$measurementId || !$apiSecret) {
            return;
        }

        $url .= "?measurement_id=$measurementId&api_secret=$apiSecret";
        $ch = curl_init($url);
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response = curl_exec($ch);
        if ($this->helper->isDebugMode()) {
            Mage::log($payload);
            Mage::log($response);
        }
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        curl_close($ch);
    }
}
