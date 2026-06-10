<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use HiraleGAMeasurementProtocol\Tests\Support\AppStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use PHPUnit\Framework\TestCase;

class AdminhtmlObserverTest extends TestCase
{
    private AppStub $app;

    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        $this->app = new AppStub();
        \Mage::$app = $this->app;
        \Mage::$config = ['__null__' => [], '0' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    private function observer(): \Hirale_GAMeasurementProtocol_Model_Adminhtml_Observer
    {
        return new \Hirale_GAMeasurementProtocol_Model_Adminhtml_Observer();
    }

    private function event(): \Varien_Event_Observer
    {
        return new \Varien_Event_Observer(new \Varien_Event());
    }

    /**
     * @param array<string, array{value: mixed}|null> $fields
     */
    private function setRequest(string $section, array $fields = [], array $extraParams = []): void
    {
        $this->app->request->params = $extraParams + [
            'section' => $section,
            'groups' => $fields === [] ? [] : ['measurement' => ['fields' => array_filter($fields, static fn ($f) => $f !== null)]],
        ];
    }

    public function testIgnoresOtherConfigSections(): void
    {
        $this->setRequest('payment', ['transport' => ['value' => 'data_manager']]);

        $this->observer()->validateConfigOnSave($this->event());

        $this->addToAssertionCount(1);
    }

    public function testIgnoresMeasurementProtocolTransport(): void
    {
        $this->setRequest('google', [
            'transport' => ['value' => 'measurement_protocol'],
            'dm_service_account_key' => ['value' => 'broken'],
        ]);

        $this->observer()->validateConfigOnSave($this->event());

        $this->addToAssertionCount(1);
    }

    public function testRejectsBrokenDataManagerConfigBeforeSave(): void
    {
        $this->setRequest('google', [
            'transport' => ['value' => 'data_manager'],
            'measurement_id' => ['value' => 'G-TEST'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => 'not a key file'],
        ]);

        $this->expectException(\Mage_Core_Exception::class);
        $this->expectExceptionMessageMatches('/Data Manager configuration invalid/');

        $this->observer()->validateConfigOnSave($this->event());
    }

    public function testAcceptsCompleteDataManagerConfig(): void
    {
        $key = (string) json_encode([
            'type' => 'service_account',
            'private_key' => 'k',
            'client_email' => 'events@demo.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
        $this->setRequest('google', [
            'transport' => ['value' => 'data_manager'],
            'measurement_id' => ['value' => 'G-TEST'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => $key],
        ]);

        $this->observer()->validateConfigOnSave($this->event());

        $this->addToAssertionCount(1);
    }

    public function testTransportInheritedFromSavedStoreScopeTriggersValidation(): void
    {
        // Transport field not on the form (scope inherit); the saved store
        // value selects the Data Manager — so the broken key must reject.
        \Mage::$config['storefront_de']['google/measurement/transport'] = 'data_manager';
        $this->setRequest('google', [
            'transport' => null,
            'measurement_id' => ['value' => 'G-TEST'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => 'broken'],
        ], ['store' => 'storefront_de']);

        $this->expectException(\Mage_Core_Exception::class);

        $this->observer()->validateConfigOnSave($this->event());
    }
}
