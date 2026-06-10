<?php

declare(strict_types=1);

use Google\Ads\DataManager\V1\IngestEventsRequest;
use Google\Ads\DataManager\V1\IngestEventsResponse;
use Google\ApiCore\ApiException;
use Google\Rpc\Code;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class Hirale_GAMeasurementProtocol_Model_Api
{
    /**
     * Google rejects GA events older than 72 hours; the margin keeps a
     * message that aged out while queued from being posted only to be
     * refused.
     */
    public const DM_STALENESS_LIMIT_SECONDS = 72 * 3600 - 600;

    /**
     * gRPC codes worth a queue retry: transient server or network
     * conditions. Everything else (bad request, auth, missing destination)
     * cannot succeed on replay and must fail the job immediately.
     */
    private const DM_RETRYABLE_CODES = [
        Code::UNKNOWN,
        Code::DEADLINE_EXCEEDED,
        Code::RESOURCE_EXHAUSTED,
        Code::ABORTED,
        Code::INTERNAL,
        Code::UNAVAILABLE,
    ];

    private ?Hirale_GAMeasurementProtocol_Helper_Data $_helper = null;

    private ?Hirale_GAMeasurementProtocol_Model_DataManager_Translator $_translator = null;

    private ?Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory $_clientFactory = null;

    public function __invoke(Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage $message): void
    {
        $transport = $this->_getHelper()->getTransport($message->storeId);
        if ($transport === Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER) {
            $this->_sendViaDataManager($message->events, $message->storeId, $message->debugMode);

            return;
        }

        $this->_sendViaMeasurementProtocol($message->events, $message->storeId, $message->debugMode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function _sendViaMeasurementProtocol(array $payload, int $storeId, bool $shouldLogDebugEvent): void
    {
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
            Mage::log(
                sprintf('[%s] HTTP %d %s', $this->_eventTime($payload), $result['http_code'], $body),
                null,
                $helper->getLogFile($storeId),
            );
        }

        if ($result['curl_errno'] !== 0) {
            throw new RuntimeException($result['curl_error']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function _sendViaDataManager(array $payload, int $storeId, bool $shouldLogDebugEvent): void
    {
        $helper = $this->_getHelper();

        $timestampMicros = $payload['timestamp_micros'] ?? null;
        if (is_numeric($timestampMicros)) {
            $ageSeconds = time() - intdiv((int) $timestampMicros, 1_000_000);
            if ($ageSeconds > self::DM_STALENESS_LIMIT_SECONDS) {
                // Data Manager refuses GA events older than 72h — drop and
                // acknowledge instead of burning retries on a lost cause.
                Mage::log(
                    sprintf('[stale] dropped Data Manager batch aged %ds (limit %ds)', $ageSeconds, self::DM_STALENESS_LIMIT_SECONDS),
                    null,
                    $helper->getLogFile($storeId),
                );

                return;
            }
        }

        $measurementId = $helper->getMeasurementId($storeId);
        $propertyId = $helper->getDataManagerPropertyId($storeId);
        $serviceAccountKey = $helper->getServiceAccountKey($storeId);

        if ($measurementId === null || $propertyId === null || $serviceAccountKey === null) {
            return;
        }

        $request = $this->_getTranslator()->toIngestEventsRequest($payload, $propertyId, $measurementId);

        try {
            $response = $this->_ingestEvents($request, $serviceAccountKey);
        } catch (ApiException $e) {
            $this->_handleApiException($e);
        } catch (Throwable $e) {
            // Credential exchange / transport failures surface as plain
            // exceptions (google/auth, Guzzle). Treat as transient.
            throw new RuntimeException('Data Manager ingest failed: ' . $e->getMessage(), 0, $e);
        }

        if ($shouldLogDebugEvent) {
            Mage::log(
                sprintf('[%s] DM requestId=%s %s', $this->_eventTime($payload), $response->getRequestId(), $request->serializeToJsonString()),
                null,
                $helper->getLogFile($storeId),
            );
        }
    }

    /**
     * Performs the ingest call. Factored out so unit tests can override
     * without hitting the network (mirrors _postToGa4).
     */
    protected function _ingestEvents(IngestEventsRequest $request, array $serviceAccountKey): IngestEventsResponse
    {
        $client = $this->_getClientFactory()->create($serviceAccountKey);
        try {
            return $client->ingestEvents($request);
        } finally {
            $client->close();
        }
    }

    protected function _handleApiException(ApiException $e): never
    {
        $message = sprintf(
            'Data Manager ingest failed (%s): %s',
            $e->getStatus() ?? (string) $e->getCode(),
            (string) ($e->getBasicMessage() ?: $e->getMessage()),
        );

        if (in_array($e->getCode(), self::DM_RETRYABLE_CODES, true)) {
            throw new RuntimeException($message, 0, $e);
        }

        throw new UnrecoverableMessageHandlingException($message, 0, $e);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function _eventTime(array $payload): string
    {
        return isset($payload['timestamp_micros'])
            ? date('Y-m-d H:i:s', (int) ($payload['timestamp_micros'] / 1000000))
            : 'unknown';
    }

    public function setHelper(Hirale_GAMeasurementProtocol_Helper_Data $helper): self
    {
        $this->_helper = $helper;

        return $this;
    }

    public function setTranslator(Hirale_GAMeasurementProtocol_Model_DataManager_Translator $translator): self
    {
        $this->_translator = $translator;

        return $this;
    }

    public function setClientFactory(Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory $clientFactory): self
    {
        $this->_clientFactory = $clientFactory;

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

    private function _getTranslator(): Hirale_GAMeasurementProtocol_Model_DataManager_Translator
    {
        if ($this->_translator === null) {
            $this->_translator = new Hirale_GAMeasurementProtocol_Model_DataManager_Translator();
        }

        return $this->_translator;
    }

    private function _getClientFactory(): Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory
    {
        if ($this->_clientFactory === null) {
            $this->_clientFactory = new Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory();
        }

        return $this->_clientFactory;
    }
}
