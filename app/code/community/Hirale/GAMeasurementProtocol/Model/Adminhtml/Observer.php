<?php

declare(strict_types=1);

/**
 * Save-time validation for the google section: when the effective transport
 * is the Data Manager API, the destination fields must be structurally valid
 * (package present, numeric property id, parseable service-account key)
 * before anything persists. Offline by design — the live validate-only probe
 * is the Validate Destination button's job, so saving the section never
 * blocks on Google availability.
 *
 * On failure the save is aborted the polite way: error message in the admin
 * session, FLAG_NO_DISPATCH on the controller, redirect back to the section.
 * Throwing from a predispatch observer would bubble past the action's error
 * handling straight to Mage::run()'s generic exception page.
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

            return;
        } catch (Throwable $e) {
            $message = 'Data Manager configuration invalid: ' . $e->getMessage();
        }

        $controller = $observer->getEvent()->getControllerAction();
        if (!is_object($controller)) {
            // Defensive: no controller on the event — abort hard rather than
            // letting a broken config persist.
            throw new Mage_Core_Exception($message);
        }

        Mage::getSingleton('adminhtml/session')->addError($message);
        $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
        $controller->getResponse()->setRedirect($this->_configEditUrl($request));
    }

    private function _configEditUrl(object $request): string
    {
        $params = ['section' => 'google'];
        foreach (['website', 'store'] as $scope) {
            $value = $this->_scopeParam($request, $scope);
            if ($value !== null) {
                $params[$scope] = $value;
            }
        }

        return (string) Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/system_config/edit', $params);
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
