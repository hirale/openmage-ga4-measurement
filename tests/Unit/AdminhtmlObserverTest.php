<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use HiraleGAMeasurementProtocol\Tests\Support\AdminSessionStub;
use HiraleGAMeasurementProtocol\Tests\Support\AppStub;
use HiraleGAMeasurementProtocol\Tests\Support\ControllerActionStub;
use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\UrlModelStub;
use PHPUnit\Framework\TestCase;

class AdminhtmlObserverTest extends TestCase
{
    private AppStub $app;
    private ControllerActionStub $controller;
    private AdminSessionStub $session;

    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        $this->app = new AppStub();
        \Mage::$app = $this->app;
        $this->controller = new ControllerActionStub($this->app->request);
        $this->session = new AdminSessionStub();
        \Mage::$singletons['adminhtml/session'] = $this->session;
        \Mage::$singletons['adminhtml/url'] = new UrlModelStub();
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
        return new \Varien_Event_Observer(new \Varien_Event(['controller_action' => $this->controller]));
    }

    /**
     * @param array<string, array{value: mixed}|null> $fields
     * @param array<string, mixed> $extraParams
     */
    private function setRequest(string $section, array $fields = [], array $extraParams = []): void
    {
        $this->app->request->params = $extraParams + [
            'section' => $section,
            'groups' => $fields === [] ? [] : ['measurement' => ['fields' => array_filter($fields, static fn ($f) => $f !== null)]],
        ];
    }

    private function assertSaveNotBlocked(): void
    {
        self::assertSame([], $this->session->errors);
        self::assertArrayNotHasKey('no-dispatch', $this->controller->flags);
        self::assertNull($this->controller->response->redirect);
    }

    public function testIgnoresOtherConfigSections(): void
    {
        $this->setRequest('payment', ['transport' => ['value' => 'data_manager']]);

        $this->observer()->validateConfigOnSave($this->event());

        $this->assertSaveNotBlocked();
    }

    public function testIgnoresMeasurementProtocolTransport(): void
    {
        $this->setRequest('google', [
            'transport' => ['value' => 'measurement_protocol'],
            'dm_service_account_key' => ['value' => 'broken'],
        ]);

        $this->observer()->validateConfigOnSave($this->event());

        $this->assertSaveNotBlocked();
    }

    public function testBlocksSaveWithSessionErrorAndRedirectOnBrokenConfig(): void
    {
        $this->setRequest('google', [
            'transport' => ['value' => 'data_manager'],
            'measurement_id' => ['value' => 'G-TEST'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => 'not a key file'],
        ]);

        $this->observer()->validateConfigOnSave($this->event());

        self::assertCount(1, $this->session->errors);
        self::assertStringContainsString('Data Manager configuration invalid', $this->session->errors[0]);
        self::assertTrue($this->controller->flags['no-dispatch'] ?? false, 'save action must not dispatch');
        self::assertNotNull($this->controller->response->redirect);
        self::assertStringContainsString('system_config/edit', $this->controller->response->redirect);
        self::assertStringContainsString('section=google', $this->controller->response->redirect);
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

        $this->assertSaveNotBlocked();
    }

    public function testTransportInheritedFromSavedStoreScopeTriggersValidation(): void
    {
        // Transport field not on the form (scope inherit); the saved store
        // value selects the Data Manager — so the broken key must block the
        // save, and the redirect must keep the store scope.
        \Mage::$config['storefront_de']['google/measurement/transport'] = 'data_manager';
        $this->setRequest('google', [
            'transport' => null,
            'measurement_id' => ['value' => 'G-TEST'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => 'broken'],
        ], ['store' => 'storefront_de']);

        $this->observer()->validateConfigOnSave($this->event());

        self::assertTrue($this->controller->flags['no-dispatch'] ?? false);
        self::assertStringContainsString('store=storefront_de', (string) $this->controller->response->redirect);
    }

    public function testMissingControllerOnEventFallsBackToThrowing(): void
    {
        $this->setRequest('google', [
            'transport' => ['value' => 'data_manager'],
            'measurement_id' => ['value' => 'G-TEST'],
            'dm_property_id' => ['value' => '213025502'],
            'dm_service_account_key' => ['value' => 'broken'],
        ]);

        $this->expectException(\Mage_Core_Exception::class);

        $this->observer()->validateConfigOnSave(new \Varien_Event_Observer(new \Varien_Event()));
    }
}
