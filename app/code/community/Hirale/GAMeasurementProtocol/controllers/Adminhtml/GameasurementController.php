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
            $this->_jsonResponse([
                'success' => false,
                'message' => 'Invalid form key. Reload the page and try again.',
            ]);

            return;
        }

        try {
            $request = $this->getRequest();
            $tester = new Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester();
            $cfg = $tester->buildConfigFromForm(
                (array) $request->getParam('groups', []),
                $this->_scopeParam('website'),
                $this->_scopeParam('store'),
            );

            if ($cfg['transport'] !== Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER) {
                $this->_jsonResponse([
                    'success' => false,
                    'message' => 'Select the Data Manager API transport first — there is nothing to validate for the Measurement Protocol.',
                ]);

                return;
            }

            $requestId = $tester->probe($cfg);
            $this->_jsonResponse([
                'success' => true,
                'message' => sprintf('Validation passed — Google accepted a validate-only test event (requestId %s). Nothing was recorded in GA4.', $requestId),
            ]);
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->_jsonResponse([
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

    private function _scopeParam(string $name): ?string
    {
        $value = $this->getRequest()->getParam($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array{success: bool, message: string} $payload
     */
    private function _jsonResponse(array $payload): void
    {
        $response = $this->getResponse();
        // Maho responses expose setBodyJson; OpenMage builds the header by
        // hand (same seam as Hirale_Queue_Model_Compat::jsonResponse).
        if (method_exists($response, 'setBodyJson')) {
            $response->setBodyJson($payload);

            return;
        }

        $response->setHeader('Content-Type', 'application/json', true);
        $response->setBody((string) json_encode($payload));
    }
}
