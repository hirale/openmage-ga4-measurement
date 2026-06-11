<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use Google\ApiCore\ApiException;
use Google\Rpc\Code;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\RecordingDataManagerApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class DataManagerApiTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$singletons['core/session'] = new CoreSessionStub();
        \Mage::$helpers['gameasurementprotocol'] = new \Hirale_GAMeasurementProtocol_Helper_Data();
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    private function configureDataManagerStore(string $storeId = '7'): void
    {
        $key = \HiraleGAMeasurementProtocol\Tests\Support\ServiceAccountKeyFixture::asJson();

        \Mage::$config[$storeId]['google/measurement/transport'] = 'data_manager';
        \Mage::$config[$storeId]['google/measurement/measurement_id'] = 'G-STORE' . $storeId;
        \Mage::$config[$storeId]['google/measurement/dm_property_id'] = '213025502';
        \Mage::$config[$storeId]['google/measurement/dm_service_account_key'] = 'enc:' . base64_encode($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'client_id' => '111.222',
            'timestamp_micros' => (int) (microtime(true) * 1_000_000),
            'events' => [['name' => 'purchase', 'params' => ['currency' => 'USD', 'value' => 10.0]]],
        ];
    }

    private function message(int $storeId = 7, bool $debugMode = false, ?array $events = null): \Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage
    {
        return new \Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage(
            events: $events ?? $this->envelope(),
            storeId: $storeId,
            debugMode: $debugMode,
        );
    }

    public function testDataManagerTransportIngestsTranslatedRequestInsteadOfPosting(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api($this->message());

        self::assertSame([], $api->posts, 'MP endpoint must not be hit on the Data Manager transport');
        self::assertCount(1, $api->ingests);

        $request = $api->ingests[0]['request'];
        $destination = $request->getDestinations()[0];
        self::assertSame('G-STORE7', $destination->getProductDestinationId());
        self::assertSame('213025502', $destination->getOperatingAccount()->getAccountId());
        self::assertCount(1, $request->getEvents());
        self::assertSame('purchase', $request->getEvents()[0]->getEventName());
        self::assertFalse($request->getValidateOnly());
    }

    public function testMeasurementProtocolRemainsDefaultTransport(): void
    {
        \Mage::$config['7']['google/measurement/measurement_id'] = 'G-STORE7';
        \Mage::$config['7']['google/measurement/api_secret'] = 'secret-7';

        $api = new RecordingDataManagerApi();
        $api($this->message());

        self::assertCount(1, $api->posts);
        self::assertSame([], $api->ingests);
    }

    public function testSkipsQuietlyWhenDataManagerConfigIncomplete(): void
    {
        $this->configureDataManagerStore();
        unset(\Mage::$config['7']['google/measurement/dm_service_account_key']);

        $api = new RecordingDataManagerApi();
        $api($this->message());

        self::assertSame([], $api->ingests);
        self::assertSame([], $api->posts);
    }

    public function testStaleEnvelopeIsDroppedWithWarningInsteadOfIngested(): void
    {
        $this->configureDataManagerStore();

        $events = $this->envelope();
        $events['timestamp_micros'] = (time() - 73 * 3600) * 1_000_000;

        $api = new RecordingDataManagerApi();
        $api($this->message(events: $events));

        self::assertSame([], $api->ingests);
        self::assertNotEmpty(\Mage::$logs);
        self::assertStringContainsString('[stale]', (string) \Mage::$logs[0]['message']);
    }

    public function testRetryableApiErrorBecomesPlainRuntimeException(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->nextIngestException = new ApiException('backend unavailable', Code::UNAVAILABLE, 'UNAVAILABLE');

        try {
            $api($this->message());
            self::fail('Expected a RuntimeException for a retryable API error');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e, 'retryable errors must keep the queue retry path open');
            self::assertStringContainsString('UNAVAILABLE', $e->getMessage());
        }
    }

    public function testPermanentApiErrorFailsUnrecoverably(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->nextIngestException = new ApiException('event name is reserved', Code::INVALID_ARGUMENT, 'INVALID_ARGUMENT');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/INVALID_ARGUMENT/');

        $api($this->message());
    }

    public function testPermissionDeniedFailsUnrecoverably(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->nextIngestException = new ApiException('service account lacks property access', Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $api($this->message());
    }

    public function testTranslationFailureFailsUnrecoverably(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->setTranslator(new class () extends \Hirale_GAMeasurementProtocol_Model_DataManager_Translator {
            #[\Override]
            public function toIngestEventsRequest(array $envelope, string $propertyId, string $measurementId, bool $validateOnly = false): \Google\Ads\DataManager\V1\IngestEventsRequest
            {
                throw new \Exception('Expect utf-8 encoding.');
            }
        });

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/translation failed/');

        $api($this->message());
    }

    public function testMalformedCredentialErrorFailsUnrecoverably(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->nextIngestException = new \InvalidArgumentException('json key is missing the private_key field');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/credentials invalid/');

        $api($this->message());
    }

    public function testGaxValidationFailureFailsUnrecoverably(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        // gax throws this (a plain Exception subclass) for deterministic
        // config mismatches, e.g. a key with a foreign universe_domain.
        $api->nextIngestException = new \Google\ApiCore\ValidationException('The configured universe domain (googleapis.com) does not match the credential universe domain (custom.example.goog)');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/credentials invalid/');

        $api($this->message());
    }

    public function testOauthTokenRejectionFailsUnrecoverably(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->nextIngestException = new \GuzzleHttp\Exception\ClientException(
            '400 invalid_grant',
            new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token'),
            new \GuzzleHttp\Psr7\Response(400),
        );

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessageMatches('/auth rejected/');

        $api($this->message());
    }

    public function testCredentialExchangeFailureIsRetryable(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api->nextIngestException = new \Exception('could not fetch access token');

        try {
            $api($this->message());
            self::fail('Expected a RuntimeException for a transport-level failure');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e);
            self::assertStringContainsString('could not fetch access token', $e->getMessage());
        }
    }

    public function testDebugModeLogsRequestIdAndRequestJson(): void
    {
        $this->configureDataManagerStore();
        \Mage::$config['7']['google/measurement/log_file'] = 'ga_store_7.log';

        $api = new RecordingDataManagerApi();
        $api->nextRequestId = 'req-smoke-1';
        $api($this->message(debugMode: true));

        self::assertNotEmpty(\Mage::$logs);
        self::assertSame('ga_store_7.log', \Mage::$logs[0]['file']);
        $logMessage = (string) \Mage::$logs[0]['message'];
        self::assertStringContainsString('DM requestId=req-smoke-1', $logMessage);
        self::assertStringContainsString('purchase', $logMessage);
    }

    public function testNonUtf8EnvelopeIsSanitizedBeforeTranslation(): void
    {
        $this->configureDataManagerStore();

        $events = $this->envelope();
        $events['events'][0]['params']['search_term'] = "caf\xE9";

        $api = new RecordingDataManagerApi();
        $api($this->message(events: $events));

        self::assertCount(1, $api->ingests);
        $params = $api->ingests[0]['request']->getEvents()[0]->getAdditionalEventParameters();
        $map = [];
        foreach ($params as $parameter) {
            $map[$parameter->getParameterName()] = $parameter->getValue();
        }
        self::assertSame(1, preg_match('//u', $map['search_term']), 'envelope must reach the protos as valid UTF-8');
        self::assertStringStartsWith('caf', $map['search_term']);
    }

    public function testServiceAccountKeyIsDecryptedBeforeReachingTheClient(): void
    {
        $this->configureDataManagerStore();

        $api = new RecordingDataManagerApi();
        $api($this->message());

        self::assertSame('events@demo.iam.gserviceaccount.com', $api->ingests[0]['key']['client_email']);
    }
}
