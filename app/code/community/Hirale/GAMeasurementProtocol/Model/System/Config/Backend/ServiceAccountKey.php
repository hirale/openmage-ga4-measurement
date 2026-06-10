<?php

declare(strict_types=1);

/**
 * Encrypted backend for the Data Manager service-account key. On top of the
 * stock encrypted backend (obscured placeholder keeps the stored value,
 * plaintext gets encrypted) it rejects values that are not a usable
 * service-account key file, so a broken paste fails the section save instead
 * of surfacing later as an opaque consumer-side auth error.
 */
class Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey extends Mage_Adminhtml_Model_System_Config_Backend_Encrypted
{
    #[Override]
    protected function _beforeSave()
    {
        $value = (string) $this->getValue();
        if ($value !== '' && !preg_match('/^\*+$/', $value)) {
            self::assertValidKeyJson($value);
        }

        return parent::_beforeSave();
    }

    /**
     * Shared with the save-time observer and the Validate Destination probe
     * so every entry point judges the key by the same rules.
     *
     * @throws Mage_Core_Exception when the value is not a service-account key
     */
    public static function assertValidKeyJson(string $json): void
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new Mage_Core_Exception('Service Account Key must be the raw JSON key file content downloaded from the Google Cloud console.');
        }

        if (($decoded['type'] ?? null) !== 'service_account') {
            throw new Mage_Core_Exception('Service Account Key JSON must have "type": "service_account" — OAuth client or API-key credentials cannot call the Data Manager API.');
        }

        foreach (['private_key', 'client_email', 'token_uri'] as $field) {
            if (!is_string($decoded[$field] ?? null) || $decoded[$field] === '') {
                throw new Mage_Core_Exception(sprintf('Service Account Key JSON is missing "%s" — download a fresh key for the service account and paste the whole file.', $field));
            }
        }
    }
}
