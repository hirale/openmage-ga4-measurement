<?php

declare(strict_types=1);

use Google\Ads\DataManager\V1\Client\IngestionServiceClient;

/**
 * Builds the Data Manager ingestion client from a decoded service-account
 * key. REST transport always: it works without ext-grpc, and queue-consumer
 * volume does not need gRPC streams.
 */
class Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory
{
    /**
     * @param array<string, mixed> $serviceAccountKey decoded key file
     */
    public function create(array $serviceAccountKey): IngestionServiceClient
    {
        return new IngestionServiceClient([
            'credentials' => $serviceAccountKey,
            'transport' => 'rest',
        ]);
    }
}
