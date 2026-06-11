<?php

declare(strict_types=1);

/**
 * AJAX backend for the Validate Destination button: builds the Data Manager
 * destination config from the on-screen form values (scope-aware) and runs a
 * validate-only ingest of a synthetic event. Nothing is recorded in GA4.
 */
class Hirale_GAMeasurementProtocol_Adminhtml_GameasurementController extends Mage_Adminhtml_Controller_Action
{
    public function validateDestinationAction(): void
    {
        if (!$this->_validateFormKey()) {
            Hirale_Queue_Model_Compat::jsonResponse($this->getResponse(), [
                'success' => false,
                'message' => 'Invalid form key. Reload the page and try again.',
            ]);

            return;
        }

        try {
            $request = $this->getRequest();
            $tester = new Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester();
            $scope = Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester::requestScope($request);
            $cfg = $tester->buildConfigFromForm(
                (array) $request->getParam('groups', []),
                $scope['website'],
                $scope['store'],
            );

            if ($cfg['transport'] !== Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER) {
                Hirale_Queue_Model_Compat::jsonResponse($this->getResponse(), [
                    'success' => false,
                    'message' => 'Select the Data Manager API transport first — there is nothing to validate for the Measurement Protocol.',
                ]);

                return;
            }

            $requestId = $tester->probe($cfg);
            Hirale_Queue_Model_Compat::jsonResponse($this->getResponse(), [
                'success' => true,
                'message' => sprintf('Validation passed — Google accepted a validate-only test event (requestId %s). Nothing was recorded in GA4.', $requestId),
            ]);
        } catch (Throwable $e) {
            Mage::logException($e);
            Hirale_Queue_Model_Compat::jsonResponse($this->getResponse(), [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/google');
    }
}
