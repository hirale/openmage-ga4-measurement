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
     * Clients cached for the process lifetime, keyed by credential hash.
     * The queue instantiates a fresh handler per message, so an
     * instance-level cache would never hit; the gax client carries its own
     * in-memory OAuth token cache, and reusing the client saves one token
     * round-trip per message. Cached clients must never be close()d.
     *
     * @var array<string, IngestionServiceClient>
     */
    private static array $_clients = [];

    /**
     * @param array<string, mixed> $serviceAccountKey decoded key file
     */
    public function create(array $serviceAccountKey): IngestionServiceClient
    {
        $cacheKey = hash('sha256', (string) json_encode($serviceAccountKey));

        return self::$_clients[$cacheKey] ??= new IngestionServiceClient([
            'credentials' => $serviceAccountKey,
            'transport' => 'rest',
        ]);
    }

    public static function reset(): void
    {
        self::$_clients = [];
    }
}
