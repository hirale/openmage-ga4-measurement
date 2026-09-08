<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use Hirale\Queue\Bus;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreSessionStub;
use Maho\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class HelperDataTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        Bus::reset();
        QueueManager::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$singletons['core/session'] = new CoreSessionStub();
        \Mage::$config = [
            '__null__' => [],
            '1' => [],
            '2' => [],
        ];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
        Bus::reset();
        QueueManager::reset();
        unset($_COOKIE['_ga'], $_COOKIE['_ga_GZTEST']);
    }

    public function testIsMeasurementEnabledReadsStoreScopedConfig(): void
    {
        \Mage::$config['1']['google/measurement/enabled'] = '1';
        \Mage::$config['2']['google/measurement/enabled'] = '0';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertTrue($helper->isMeasurementEnabled(1));
        self::assertFalse($helper->isMeasurementEnabled(2));
    }

    public function testStoreScopedConfigIsCachedPerStoreNotGlobally(): void
    {
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-AAA';
        \Mage::$config['2']['google/measurement/measurement_id'] = 'G-BBB';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        // Prime store 1 first; switching to store 2 must not return the cached store-1 value.
        self::assertSame('G-AAA', $helper->getMeasurementId(1));
        self::assertSame('G-BBB', $helper->getMeasurementId(2));
        // Re-read to confirm cache hit returns the right value for each scope.
        self::assertSame('G-AAA', $helper->getMeasurementId(1));
        self::assertSame('G-BBB', $helper->getMeasurementId(2));
    }

    public function testGetMeasurementIdReturnsNullWhenUnconfigured(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertNull($helper->getMeasurementId(1));
    }

    public function testGetApiSecretIsStoreScoped(): void
    {
        \Mage::$config['1']['google/measurement/api_secret'] = 'secret-A';
        \Mage::$config['2']['google/measurement/api_secret'] = 'secret-B';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame('secret-A', $helper->getApiSecret(1));
        self::assertSame('secret-B', $helper->getApiSecret(2));
    }

    public function testGetLogFileFallsBackToDefault(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame('ga_measurement.log', $helper->getLogFile(1));
    }

    public function testGetLogFileUsesStoreOverride(): void
    {
        \Mage::$config['2']['google/measurement/log_file'] = 'ga_store_2.log';
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame('ga_store_2.log', $helper->getLogFile(2));
        self::assertSame('ga_measurement.log', $helper->getLogFile(1));
    }

    public function testIsDebugModeRequiresAllowedIp(): void
    {
        \Mage::$config['1']['google/measurement/debug_mode'] = '1';
        $core = new CoreHelperStub();
        $core->devAllowed = false;
        \Mage::$helpers['core'] = $core;
        \Mage::$config['__null__'][\Mage_Core_Helper_Data::XML_PATH_DEV_ALLOW_IPS] = '127.0.0.1';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        // debug flag is set but the IP is not on the allow list → debug suppressed
        self::assertFalse($helper->isDebugMode(1));

        $core->devAllowed = true;
        self::assertTrue($helper->isDebugMode(1));
    }

    public function testGetSessionIdParsesGaCookieForConfiguredMeasurementId(): void
    {
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-GZTEST';
        $_COOKIE['_ga_GZTEST'] = 'GS1.1.1700000000.5.0.1700000010.0.0';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame('1700000000', $helper->getSessionId(1));
    }

    public function testGetSessionIdReturnsNullWhenMeasurementIdMissing(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertNull($helper->getSessionId(1));
    }

    public function testGetSessionIdReturnsNullWhenCookieMissing(): void
    {
        \Mage::$config['1']['google/measurement/measurement_id'] = 'G-GZTEST';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertNull($helper->getSessionId(1));
    }

    public function testGetClientIdReusesGaCookieValue(): void
    {
        $_COOKIE['_ga'] = 'GA1.2.1234567890.1700000000';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame('1234567890.1700000000', $helper->getClientId());
    }

    public function testGetClientIdGeneratesAndStoresOneWhenCookieAbsent(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();
        $first = $helper->getClientId();

        self::assertNotSame('', $first);
        self::assertMatchesRegularExpression('/^\d+\.\d+$/', $first);
        // Calling again returns the session-cached value.
        self::assertSame($first, $helper->getClientId());
    }

    public function testFormatPriceRoundsToTwoDecimals(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame(1.23, $helper->formatPrice(1.234));
        self::assertSame(1.24, $helper->formatPrice(1.236));
    }

    public function testGetTransportDefaultsToMeasurementProtocol(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame(\Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_MEASUREMENT_PROTOCOL, $helper->getTransport(1));
    }

    public function testGetTransportFallsBackOnUnknownValue(): void
    {
        \Mage::$config['1']['google/measurement/transport'] = 'carrier_pigeon';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame(\Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_MEASUREMENT_PROTOCOL, $helper->getTransport(1));
    }

    public function testGetTransportIsStoreScoped(): void
    {
        \Mage::$config['1']['google/measurement/transport'] = 'data_manager';
        \Mage::$config['2']['google/measurement/transport'] = 'measurement_protocol';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame(\Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER, $helper->getTransport(1));
        self::assertSame(\Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_MEASUREMENT_PROTOCOL, $helper->getTransport(2));
        // Cache hit must stay scope-correct.
        self::assertSame(\Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER, $helper->getTransport(1));
    }

    public function testGetDataManagerPropertyIdReturnsNullWhenUnconfigured(): void
    {
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertNull($helper->getDataManagerPropertyId(1));
    }

    public function testGetDataManagerPropertyIdIsStoreScoped(): void
    {
        \Mage::$config['1']['google/measurement/dm_property_id'] = '213025502';
        \Mage::$config['2']['google/measurement/dm_property_id'] = '987654321';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertSame('213025502', $helper->getDataManagerPropertyId(1));
        self::assertSame('987654321', $helper->getDataManagerPropertyId(2));
    }

    public function testGetServiceAccountKeyDecryptsAndDecodes(): void
    {
        $json = (string) json_encode([
            'type' => 'service_account',
            'client_email' => 'events@project.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
        \Mage::$config['1']['google/measurement/dm_service_account_key'] = 'enc:' . base64_encode($json);

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();
        $key = $helper->getServiceAccountKey(1);

        self::assertIsArray($key);
        self::assertSame('events@project.iam.gserviceaccount.com', $key['client_email']);
        self::assertSame('service_account', $key['type']);
    }

    public function testGetServiceAccountKeyAcceptsPlainJsonStoredValueWithWarning(): void
    {
        // Decrypting a never-encrypted value yields garbage; the helper then
        // falls back to decoding the raw stored string — and warns, because
        // the credential is not encrypted at rest.
        $json = (string) json_encode(['type' => 'service_account', 'client_email' => 'plain@x.iam.gserviceaccount.com']);
        \Mage::$config['1']['google/measurement/dm_service_account_key'] = $json;

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();
        $key = $helper->getServiceAccountKey(1);

        self::assertIsArray($key);
        self::assertSame('plain@x.iam.gserviceaccount.com', $key['client_email']);
        self::assertNotEmpty(\Mage::$logs);
        self::assertStringContainsString('unencrypted', (string) \Mage::$logs[0]['message']);
    }

    public function testGetServiceAccountKeyReturnsNullForStoredNullLiteral(): void
    {
        \Mage::$config['1']['google/measurement/dm_service_account_key'] = 'null';

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertNull($helper->getServiceAccountKey(1));
        self::assertSame([], \Mage::$logs, 'a non-key value must not trigger the plaintext-fallback warning');
    }

    public function testGetServiceAccountKeyReturnsNullWhenMissingOrGarbage(): void
    {
        \Mage::$config['2']['google/measurement/dm_service_account_key'] = 'enc:' . base64_encode('not json');

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertNull($helper->getServiceAccountKey(1));
        self::assertNull($helper->getServiceAccountKey(2));
        // Cached negative result stays null.
        self::assertNull($helper->getServiceAccountKey(2));
    }

    public function testBridgePrefersMahoCoreQueueWhenTheModuleIsEnabled(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertTrue($helper->isQueueEnabled());
        self::assertTrue($helper->enqueueMeasurementEvent(['events' => [['name' => 'login']]], 7, false));
        self::assertCount(1, QueueManager::$dispatches);
        self::assertSame([], Bus::$dispatches);

        $call = QueueManager::$dispatches[0];
        self::assertSame(\Hirale_GAMeasurementProtocol_Helper_Data::QUEUE_ANALYTICS, $call['queue']);
        self::assertNull($call['delaySeconds']);

        $message = $call['message'];
        self::assertInstanceOf(\Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage::class, $message);
        self::assertSame(['events' => [['name' => 'login']]], $message->events);
        self::assertSame(7, $message->storeId);
        self::assertFalse($message->debugMode);
    }

    public function testBridgeFallsBackToHiraleQueueWhenMahoQueueIsDisabled(): void
    {
        // The stubbed QueueManager class exists either way, so the module flag
        // is what actually decides the branch.
        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertTrue($helper->isQueueEnabled());
        self::assertTrue($helper->enqueueMeasurementEvent(['events' => [['name' => 'login']]], 7, true));
        self::assertCount(1, Bus::$dispatches);
        self::assertSame([], QueueManager::$dispatches);

        // hirale/queue routes by message class from its own config.xml.
        self::assertSame('dispatch', Bus::$dispatches[0]['method']);
        self::assertTrue(Bus::$dispatches[0]['message']->debugMode);
    }

    public function testBridgeReportsQueueUnavailableWithoutAnyBackend(): void
    {
        $helper = new class extends \Hirale_GAMeasurementProtocol_Helper_Data {
            #[\Override]
            protected function _isHiraleQueueAvailable(): bool
            {
                return false;
            }
        };

        self::assertFalse($helper->isQueueEnabled());
        self::assertFalse($helper->enqueueMeasurementEvent(['events' => []], 1, false));
        self::assertSame([], Bus::$dispatches);
        self::assertSame([], QueueManager::$dispatches);
        // Silently declining is the point: no exception reaches the observer.
        self::assertSame([], \Mage::$exceptions);
    }

    public function testDispatchFailureIsSwallowedAndLogged(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        QueueManager::$nextException = new \RuntimeException('queue table is gone');

        $helper = new \Hirale_GAMeasurementProtocol_Helper_Data();

        self::assertFalse($helper->enqueueMeasurementEvent(['events' => []], 1, false));
        self::assertCount(1, \Mage::$exceptions);
        self::assertSame('queue table is gone', \Mage::$exceptions[0]->getMessage());
    }
}
