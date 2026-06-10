<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit\DataManager;

use Google\Ads\DataManager\V1\IngestEventsRequest;
use Google\Ads\DataManager\V1\IngestEventsResponse;
use HiraleGAMeasurementProtocol\Tests\Support\AppStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\WebsiteStub;
use PHPUnit\Framework\TestCase;

class RecordingDestinationTester extends \Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester
{
    /** @var list<array{request:IngestEventsRequest,key:array<string,mixed>}> */
    public array $ingests = [];

    public ?\Throwable $nextIngestException = null;

    public string $nextRequestId = 'req-validate-1';

    public bool $packageInstalled = true;

    #[\Override]
    protected function ingest(IngestEventsRequest $request, array $serviceAccountKey): IngestEventsResponse
    {
        if ($this->nextIngestException !== null) {
            $exception = $this->nextIngestException;
            $this->nextIngestException = null;
            throw $exception;
        }

        $this->ingests[] = ['request' => $request, 'key' => $serviceAccountKey];

        $response = new IngestEventsResponse();
        $response->setRequestId($this->nextRequestId);

        return $response;
    }

    #[\Override]
    protected function isPackageInstalled(): bool
    {
        return $this->packageInstalled;
    }
}

class DestinationTesterTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$app = new AppStub();
        \Mage::$config = ['__null__' => [], '0' => [], 'storefront_de' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    private function validKeyJson(): string
    {
        return (string) json_encode([
            'type' => 'service_account',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
            'client_email' => 'events@demo.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formGroups(array $overrides = []): array
    {
        $fields = $overrides + [
            'transport' => ['value' => 'data_manager'],
            'measurement_id' => ['value' => 'G-FORM'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => $this->validKeyJson()],
        ];

        return ['measurement' => ['fields' => array_filter($fields, static fn ($f) => $f !== null)]];
    }

    public function testBuildsConfigFromOnScreenFormValues(): void
    {
        $tester = new RecordingDestinationTester();
        $cfg = $tester->buildConfigFromForm($this->formGroups());

        self::assertSame('data_manager', $cfg['transport']);
        self::assertSame('G-FORM', $cfg['measurement_id']);
        self::assertSame('213025502', $cfg['property_id']);
        self::assertSame($this->validKeyJson(), $cfg['service_account_key']);
    }

    public function testObscuredSecretFallsBackToSavedDecryptedValueAtStoreScope(): void
    {
        \Mage::$config['storefront_de']['google/measurement/dm_service_account_key'] = 'enc:' . base64_encode($this->validKeyJson());

        $tester = new RecordingDestinationTester();
        $cfg = $tester->buildConfigFromForm(
            $this->formGroups(['dm_service_account_key' => ['value' => '******']]),
            null,
            'storefront_de',
        );

        self::assertSame($this->validKeyJson(), $cfg['service_account_key']);
    }

    public function testMissingFormFieldFallsBackToWebsiteScopeValue(): void
    {
        \Mage::$app->websites['base'] = new WebsiteStub([
            'google/measurement/dm_property_id' => '987654321',
        ]);

        $tester = new RecordingDestinationTester();
        $cfg = $tester->buildConfigFromForm(
            $this->formGroups(['dm_property_id' => null]),
            'base',
        );

        self::assertSame('987654321', $cfg['property_id']);
    }

    public function testMissingFormFieldFallsBackToDefaultScopeValue(): void
    {
        \Mage::$config['0']['google/measurement/measurement_id'] = 'G-DEFAULT';

        $tester = new RecordingDestinationTester();
        $cfg = $tester->buildConfigFromForm($this->formGroups(['measurement_id' => null]));

        self::assertSame('G-DEFAULT', $cfg['measurement_id']);
    }

    public function testValidateStructureSkipsForMeasurementProtocolTransport(): void
    {
        $tester = new RecordingDestinationTester();
        $tester->packageInstalled = false;

        $tester->validateStructure([
            'transport' => 'measurement_protocol',
            'measurement_id' => '',
            'property_id' => '',
            'service_account_key' => '',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testValidateStructureRequiresThePackage(): void
    {
        $tester = new RecordingDestinationTester();
        $tester->packageInstalled = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/composer require googleads\/data-manager/');

        $tester->validateStructure($tester->buildConfigFromForm($this->formGroups()));
    }

    public function testValidateStructureRejectsNonNumericPropertyId(): void
    {
        $tester = new RecordingDestinationTester();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/numeric property id/');

        $tester->validateStructure($tester->buildConfigFromForm(
            $this->formGroups(['dm_property_id' => ['value' => 'G-NOPE']]),
        ));
    }

    public function testValidateStructureRequiresMeasurementId(): void
    {
        $tester = new RecordingDestinationTester();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Measurement ID is required/');

        $tester->validateStructure($tester->buildConfigFromForm(
            $this->formGroups(['measurement_id' => ['value' => '']]),
        ));
    }

    public function testValidateStructureRejectsBrokenKeyJson(): void
    {
        $tester = new RecordingDestinationTester();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON key file/');

        $tester->validateStructure($tester->buildConfigFromForm(
            $this->formGroups(['dm_service_account_key' => ['value' => 'oops']]),
        ));
    }

    public function testProbeSendsValidateOnlySyntheticEventAndReturnsRequestId(): void
    {
        $tester = new RecordingDestinationTester();

        $requestId = $tester->probe($tester->buildConfigFromForm($this->formGroups()));

        self::assertSame('req-validate-1', $requestId);
        self::assertCount(1, $tester->ingests);

        $request = $tester->ingests[0]['request'];
        self::assertTrue($request->getValidateOnly(), 'probe must never record real data');
        self::assertSame('hirale_dm_validation', $request->getEvents()[0]->getEventName());
        self::assertSame('hirale.validation', $request->getEvents()[0]->getClientId());
        self::assertSame('G-FORM', $request->getDestinations()[0]->getProductDestinationId());
        self::assertSame('events@demo.iam.gserviceaccount.com', $tester->ingests[0]['key']['client_email']);
    }

    public function testProbePropagatesIngestFailures(): void
    {
        $tester = new RecordingDestinationTester();
        $tester->nextIngestException = new \RuntimeException('PERMISSION_DENIED: property access missing');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PERMISSION_DENIED/');

        $tester->probe($tester->buildConfigFromForm($this->formGroups()));
    }
}
