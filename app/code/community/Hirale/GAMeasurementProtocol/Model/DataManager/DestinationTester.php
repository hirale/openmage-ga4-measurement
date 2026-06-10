<?php

declare(strict_types=1);

use Google\Ads\DataManager\V1\IngestEventsRequest;
use Google\Ads\DataManager\V1\IngestEventsResponse;

/**
 * Shared Data Manager destination validation used by both the save-time
 * observer (structural checks, offline) and the Validate Destination button
 * (validate-only ingest of a synthetic event against the live API). Parses
 * the raw admin form values (groups[measurement][fields][...][value]) with
 * scope-aware fallback to saved config, mirroring the queue module's
 * ConnectionTester pattern — extended for the website/store scopes this
 * section is saved at.
 */
class Hirale_GAMeasurementProtocol_Model_DataManager_DestinationTester
{
    public const PACKAGE = 'googleads/data-manager';

    /**
     * @param array<string, mixed> $groups Raw groups[] param from the system config form.
     * @return array{transport: string, measurement_id: string, property_id: string, service_account_key: string}
     */
    public function buildConfigFromForm(array $groups, ?string $websiteCode = null, ?string $storeCode = null): array
    {
        $field = static function (string $name) use ($groups): mixed {
            return $groups['measurement']['fields'][$name]['value'] ?? null;
        };

        return [
            'transport' => $this->formValue($field('transport'), 'google/measurement/transport', $websiteCode, $storeCode),
            'measurement_id' => $this->formValue($field('measurement_id'), 'google/measurement/measurement_id', $websiteCode, $storeCode),
            'property_id' => $this->formValue($field('dm_property_id'), 'google/measurement/dm_property_id', $websiteCode, $storeCode),
            'service_account_key' => $this->formSecret($field('dm_service_account_key'), 'google/measurement/dm_service_account_key', $websiteCode, $storeCode),
        ];
    }

    /**
     * Offline checks only — safe to run on every section save. Throws with
     * an operator-actionable message on the first problem found.
     *
     * @param array{transport: string, measurement_id: string, property_id: string, service_account_key: string} $cfg
     */
    public function validateStructure(array $cfg): void
    {
        if ($cfg['transport'] !== Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER) {
            return;
        }
        if (!$this->isPackageInstalled()) {
            throw new RuntimeException(sprintf(
                'The Data Manager API transport requires the "%s" package. Run: composer require %s',
                self::PACKAGE,
                self::PACKAGE,
            ));
        }
        if ($cfg['measurement_id'] === '') {
            throw new RuntimeException('Measurement ID is required — the Data Manager API ingests into the web data stream it identifies (G-XXXXXXX).');
        }
        if ($cfg['property_id'] === '' || !ctype_digit($cfg['property_id'])) {
            throw new RuntimeException('GA4 Property ID must be the numeric property id (GA Admin > Property Settings), e.g. 213025502 — not the G-XXXXXXX measurement id.');
        }
        if ($cfg['service_account_key'] === '') {
            throw new RuntimeException('Service Account Key is required — paste the JSON key file of the service account.');
        }

        try {
            Hirale_GAMeasurementProtocol_Model_System_Config_Backend_ServiceAccountKey::assertValidKeyJson($cfg['service_account_key']);
        } catch (Mage_Core_Exception $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Live probe: validate-only ingest of one synthetic event. Exercises
     * OAuth, API enablement, property access and the destination pairing
     * without recording anything in GA4.
     *
     * @param array{transport: string, measurement_id: string, property_id: string, service_account_key: string} $cfg
     * @return string the Google-assigned request id
     */
    public function probe(array $cfg): string
    {
        $this->validateStructure($cfg);

        /** @var array<string, mixed> $key */
        $key = (array) json_decode($cfg['service_account_key'], true);

        $envelope = [
            'client_id' => 'hirale.validation',
            'timestamp_micros' => (int) (microtime(true) * 1_000_000),
            'events' => [['name' => 'hirale_dm_validation', 'params' => []]],
        ];
        $request = $this->translator()->toIngestEventsRequest(
            $envelope,
            $cfg['property_id'],
            $cfg['measurement_id'],
            true,
        );

        return (string) $this->ingest($request, $key)->getRequestId();
    }

    /**
     * Network seam — overridable in tests (mirrors Api::_ingestEvents).
     *
     * @param array<string, mixed> $serviceAccountKey
     */
    protected function ingest(IngestEventsRequest $request, array $serviceAccountKey): IngestEventsResponse
    {
        $client = (new Hirale_GAMeasurementProtocol_Model_DataManager_ClientFactory())->create($serviceAccountKey);
        try {
            return $client->ingestEvents($request);
        } finally {
            $client->close();
        }
    }

    /**
     * Package availability, checked the way Maho core checks mailer bridges.
     * Overridable so tests can simulate a copy-deployed tree whose vendor
     * was never rebuilt.
     */
    protected function isPackageInstalled(): bool
    {
        return class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled(self::PACKAGE);
    }

    protected function translator(): Hirale_GAMeasurementProtocol_Model_DataManager_Translator
    {
        return new Hirale_GAMeasurementProtocol_Model_DataManager_Translator();
    }

    /**
     * Plain form field: absent (scope inherit) falls back to the saved value
     * at the requested scope.
     */
    private function formValue(mixed $value, string $path, ?string $websiteCode, ?string $storeCode): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return trim($this->savedValue($path, $websiteCode, $storeCode));
    }

    /**
     * Admin form sends a literal "******" for unchanged obscure fields; an
     * absent field means scope inherit. Both fall back to the stored value
     * (decrypted) so validation judges the credentials that will actually be
     * in effect.
     */
    private function formSecret(mixed $value, string $path, ?string $websiteCode, ?string $storeCode): string
    {
        if (is_string($value) && $value !== '' && preg_match('/^\*+$/', $value) !== 1) {
            return $value;
        }
        if (is_string($value) && $value === '') {
            // Explicitly cleared on screen.
            return '';
        }

        $stored = $this->savedValue($path, $websiteCode, $storeCode);
        if ($stored === '') {
            return '';
        }

        $decrypted = (string) Mage::helper('core')->decrypt($stored);
        if (json_decode($decrypted, true) === null && json_decode($stored, true) !== null) {
            // Value was seeded unencrypted (dev fixture) — use it as-is.
            return $stored;
        }

        return $decrypted;
    }

    private function savedValue(string $path, ?string $websiteCode, ?string $storeCode): string
    {
        if ($storeCode !== null && $storeCode !== '') {
            return (string) Mage::getStoreConfig($path, $storeCode);
        }
        if ($websiteCode !== null && $websiteCode !== '') {
            return (string) Mage::app()->getWebsite($websiteCode)->getConfig($path);
        }

        // Store 0 (admin) reflects default-scope values for this section.
        return (string) Mage::getStoreConfig($path, 0);
    }
}
