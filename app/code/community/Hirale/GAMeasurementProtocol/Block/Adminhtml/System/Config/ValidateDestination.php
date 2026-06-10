<?php

declare(strict_types=1);

/**
 * "Validate Destination" button rendered below the Data Manager fields in
 * System > Configuration > Sales > Google API.
 *
 * Modeled on Hirale_Queue's Test Connection button: serializes the
 * measurement group's on-screen form values (not the saved config) and POSTs
 * them — plus the current website/store scope — to
 * GameasurementController::validateDestinationAction, which runs a
 * validate-only ingest of a synthetic event. Nothing is recorded in GA4.
 */
class Hirale_GAMeasurementProtocol_Block_Adminhtml_System_Config_ValidateDestination extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    #[\Override]
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    #[\Override]
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $buttonLabel = $this->escapeHtml('Validate Destination');
        $testingLabel = $this->jsQuoteEscape('Validating...');
        $failedLabel = $this->jsQuoteEscape('Request failed. Check the error log.');
        $resetLabel = $this->jsQuoteEscape('Validate Destination');

        $scopeParams = array_filter([
            'website' => (string) $this->getRequest()->getParam('website'),
            'store' => (string) $this->getRequest()->getParam('store'),
        ], static fn (string $value): bool => $value !== '');
        $ajaxUrl = Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/gameasurement/validateDestination', $scopeParams);

        return <<<HTML
<style>
    #hirale_gam_validate_destination_result {
        display: none;
        margin-top: 8px;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: 13px;
    }
    #hirale_gam_validate_destination_result.success {
        display: block;
        background: #ecfdf5;
        border: 1px solid #10b981;
    }
    #hirale_gam_validate_destination_result.error {
        display: block;
        background: #fef2f2;
        border: 1px solid #ef4444;
    }
</style>
<script>
    function hiraleGamValidateDestination() {
        const button = document.getElementById('hirale_gam_validate_destination_button');
        const label = document.getElementById('hirale_gam_validate_destination_label');
        const result = document.getElementById('hirale_gam_validate_destination_result');

        result.className = '';
        label.textContent = '{$testingLabel}';
        button.disabled = true;

        const formData = new FormData();
        formData.append('form_key', FORM_KEY);
        document.querySelectorAll('#config_edit_form input, #config_edit_form select, #config_edit_form textarea').forEach((el) => {
            if (!el.name || el.disabled) {
                return;
            }
            if (el.name.startsWith('groups[measurement]')) {
                formData.append(el.name, el.value);
            }
        });

        fetch('{$ajaxUrl}', { method: 'POST', body: formData, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((response) => {
                result.textContent = response.message;
                result.className = response.success ? 'success' : 'error';
            })
            .catch((error) => {
                console.error('Validate destination error:', error);
                result.textContent = '{$failedLabel}';
                result.className = 'error';
            })
            .finally(() => {
                label.textContent = '{$resetLabel}';
                button.disabled = false;
            });
    }
</script>
<button onclick="hiraleGamValidateDestination(); return false;" class="scalable" type="button" id="hirale_gam_validate_destination_button">
    <span id="hirale_gam_validate_destination_label">{$buttonLabel}</span>
</button>
<div id="hirale_gam_validate_destination_result"></div>
HTML;
    }
}
