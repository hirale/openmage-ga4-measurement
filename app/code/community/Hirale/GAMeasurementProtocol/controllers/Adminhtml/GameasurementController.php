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
            $scope = Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester::requestScope($request);
            $cfg = $tester->buildConfigFromForm(
                (array) $request->getParam('groups', []),
                $scope['website'],
                $scope['store'],
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

    /**
     * Maho's response has setBodyJson(); OpenMage's does not. Kept local so
     * the module carries no runtime dependency on the queue package.
     *
     * @param array<string, mixed> $payload
     */
    private function _jsonResponse(array $payload): void
    {
        $response = $this->getResponse();
        if (method_exists($response, 'setBodyJson')) {
            $response->setBodyJson($payload);

            return;
        }

        $response->setHeader('Content-Type', 'application/json', true);
        $response->setBody((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/google');
    }
}
