<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use PHPUnit\Framework\TestCase;

class ServiceAccountKeyBackendTest extends TestCase
{
    private CoreHelperStub $core;

    protected function setUp(): void
    {
        \Mage::reset();
        $this->core = new CoreHelperStub();
        \Mage::$helpers['core'] = $this->core;
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    private function validKeyJson(): string
    {
        return (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
            'client_email' => 'events@demo-project.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    public function testValidKeyIsEncryptedOnSave(): void
    {
        $backend = new \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey();
        $backend->setValue($this->validKeyJson())->save();

        self::assertSame($this->core->encrypt($this->validKeyJson()), $backend->getValue());
    }

    public function testObscuredPlaceholderKeepsStoredValue(): void
    {
        $stored = $this->core->encrypt($this->validKeyJson());

        $backend = new \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey();
        $backend->setOldValue($stored);
        $backend->setValue('******')->save();

        self::assertSame($stored, $backend->getValue());
    }

    public function testEmptyValueIsAllowedAndLeftEmpty(): void
    {
        $backend = new \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey();
        $backend->setValue('')->save();

        self::assertSame('', $backend->getValue());
    }

    public function testRejectsNonJsonValue(): void
    {
        $backend = new \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey();
        $backend->setValue('definitely not a key file');

        $this->expectException(\Mage_Core_Exception::class);
        $this->expectExceptionMessageMatches('/JSON key file/');
        $backend->save();
    }

    public function testRejectsNonServiceAccountCredentials(): void
    {
        $backend = new \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey();
        $backend->setValue((string) json_encode(['type' => 'authorized_user', 'client_id' => 'x']));

        $this->expectException(\Mage_Core_Exception::class);
        $this->expectExceptionMessageMatches('/service_account/');
        $backend->save();
    }

    public function testRejectsKeyMissingRequiredFields(): void
    {
        $key = json_decode($this->validKeyJson(), true);
        unset($key['private_key']);

        $backend = new \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey();
        $backend->setValue((string) json_encode($key));

        $this->expectException(\Mage_Core_Exception::class);
        $this->expectExceptionMessageMatches('/private_key/');
        $backend->save();
    }

    public function testAssertValidKeyJsonPassesForValidKey(): void
    {
        \Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey::assertValidKeyJson($this->validKeyJson());

        $this->addToAssertionCount(1);
    }
}
