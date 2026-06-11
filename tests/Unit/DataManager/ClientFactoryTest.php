<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit\DataManager;

use PHPUnit\Framework\TestCase;

/**
 * Constructs real IngestionServiceClient instances: with an array keyfile
 * and the REST transport this stays offline (the PEM is only touched at
 * token fetch).
 */
class ClientFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory::reset();
    }

    protected function tearDown(): void
    {
        \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function key(string $email): array
    {
        return [
            'type' => 'service_account',
            'client_email' => $email,
            'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'project_id' => 'demo',
        ];
    }

    public function testSameKeyReusesTheSameClientInstance(): void
    {
        $factory = new \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory();

        $first = $factory->create($this->key('a@demo.iam.gserviceaccount.com'));
        $second = $factory->create($this->key('a@demo.iam.gserviceaccount.com'));

        self::assertSame($first, $second, 'token cache only amortizes when the client survives across messages');
    }

    public function testCacheIsSharedAcrossFactoryInstances(): void
    {
        $first = (new \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory())->create($this->key('a@demo.iam.gserviceaccount.com'));
        $second = (new \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory())->create($this->key('a@demo.iam.gserviceaccount.com'));

        self::assertSame($first, $second, 'handler and factory are rebuilt per message — the cache must be process-level');
    }

    public function testDifferentKeysGetDistinctClients(): void
    {
        $factory = new \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory();

        $first = $factory->create($this->key('store-a@demo.iam.gserviceaccount.com'));
        $second = $factory->create($this->key('store-b@demo.iam.gserviceaccount.com'));

        self::assertNotSame($first, $second);
    }

    public function testResetDropsTheCache(): void
    {
        $factory = new \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory();
        $first = $factory->create($this->key('a@demo.iam.gserviceaccount.com'));

        \Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory::reset();

        self::assertNotSame($first, $factory->create($this->key('a@demo.iam.gserviceaccount.com')));
    }
}
