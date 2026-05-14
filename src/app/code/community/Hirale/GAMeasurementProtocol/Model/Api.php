<?php

declare(strict_types=1);

class Hirale_GAMeasurementProtocol_Model_Api implements Hirale_Queue_Model_TaskHandlerInterface
{
    public const META_STORE_ID = '_store_id';
    public const META_DEBUG_MODE = '_debug_mode';

    private ?Hirale_GAMeasurementProtocol_Helper_Data $_helper = null;

    /**
     * @param array<string, mixed> $task
     */
    public function handle(array $task): void
    {
        $payload = is_array($task['data'] ?? null) ? $task['data'] : [];
        $storeId = isset($payload[self::META_STORE_ID]) ? (int) $payload[self::META_STORE_ID] : null;
        $shouldLogDebugEvent = !empty($payload[self::META_DEBUG_MODE]);

        unset($payload[self::META_STORE_ID], $payload[self::META_DEBUG_MODE]);

        $helper = $this->_getHelper();
        $url = $helper->getMeasurementProtocolUrl();
        $measurementId = $helper->getMeasurementId($storeId);
        $apiSecret = $helper->getApiSecret($storeId);

        if ($measurementId === null || $apiSecret === null || $url === '') {
            return;
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $result = $this->_postToGa4(
            $url . '?measurement_id=' . rawurlencode($measurementId) . '&api_secret=' . rawurlencode($apiSecret),
            $body,
        );

        if ($shouldLogDebugEvent) {
            $eventTime = isset($payload['timestamp_micros'])
                ? date('Y-m-d H:i:s', (int) ($payload['timestamp_micros'] / 1000000))
                : 'unknown';
            Mage::log(
                sprintf('[%s] HTTP %d %s', $eventTime, $result['http_code'], $body),
                null,
                $helper->getLogFile($storeId),
            );
        }

        if ($result['curl_errno'] !== 0) {
            throw new RuntimeException($result['curl_error']);
        }
    }

    /**
     * Performs the POST to GA4 Measurement Protocol. Factored out so unit
     * tests can override without hitting the network.
     *
     * @return array{http_code:int,curl_errno:int,curl_error:string}
     */
    protected function _postToGa4(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $result = [
            'http_code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'curl_errno' => (int) curl_errno($ch),
            'curl_error' => (string) curl_error($ch),
        ];
        curl_close($ch);

        return $result;
    }

    public function setHelper(Hirale_GAMeasurementProtocol_Helper_Data $helper): self
    {
        $this->_helper = $helper;

        return $this;
    }

    private function _getHelper(): Hirale_GAMeasurementProtocol_Helper_Data
    {
        if ($this->_helper === null) {
            $helper = Mage::helper('gameasurementprotocol');
            if (!$helper instanceof Hirale_GAMeasurementProtocol_Helper_Data) {
                throw new RuntimeException('Hirale GAMeasurementProtocol helper is unavailable.');
            }
            $this->_helper = $helper;
        }

        return $this->_helper;
    }
}
