<?php

declare(strict_types=1);

/**
 * Save-time validation for the google section: when the effective transport
 * is the Data Manager API, the destination fields must be structurally valid
 * (package present, numeric property id, parseable service-account key)
 * before anything persists. Offline by design — the live validate-only probe
 * is the Validate Destination button's job, so saving the section never
 * blocks on Google availability.
 */
class Hirale_GAMeasurementProtocol_Model_Adminhtml_Observer
{
    private ?Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester $_tester = null;

    public function validateConfigOnSave(Varien_Event_Observer $observer): void
    {
        $request = Mage::app()->getRequest();
        if ((string) $request->getParam('section') !== 'google') {
            return;
        }

        $groups = (array) $request->getParam('groups', []);
        if ($groups === []) {
            return;
        }

        $tester = $this->_getTester();
        $cfg = $tester->buildConfigFromForm(
            $groups,
            $this->_scopeParam($request, 'website'),
            $this->_scopeParam($request, 'store'),
        );

        if ($cfg['transport'] !== Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER) {
            return;
        }

        try {
            $tester->validateStructure($cfg);
        } catch (Throwable $e) {
            throw new Mage_Core_Exception('Data Manager configuration invalid: ' . $e->getMessage());
        }
    }

    public function setTester(Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester $tester): self
    {
        $this->_tester = $tester;

        return $this;
    }

    private function _getTester(): Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester
    {
        if ($this->_tester === null) {
            $this->_tester = new Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester();
        }

        return $this->_tester;
    }

    private function _scopeParam(object $request, string $name): ?string
    {
        $value = $request->getParam($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
