<?php

/**
 * v4.1.0 stores google/measurement/api_secret encrypted at rest. Rows written
 * before this version hold the secret in clear text, so re-encrypt them in
 * place across every scope.
 *
 * Rows that already decrypt to a plausible secret are left alone, which makes
 * the script idempotent: re-running it — or running it on a table that mixes
 * migrated and unmigrated scopes — encrypts nothing twice.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table = $installer->getTable('core/config_data');
$coreHelper = Mage::helper('core');

$rows = $connection->fetchAll(
    $connection->select()
        ->from($table, ['config_id', 'value'])
        ->where('path = ?', 'google/measurement/api_secret')
        ->where('value IS NOT NULL')
        ->where('value != ?', ''),
);

foreach ($rows as $row) {
    $stored = (string) $row['value'];
    $decrypted = (string) $coreHelper->decrypt($stored);
    if (Hirale_GAMeasurementProtocol_Helper_Data::isPlausibleApiSecret($decrypted)) {
        // Decrypts cleanly: already migrated.
        continue;
    }

    $connection->update(
        $table,
        ['value' => $coreHelper->encrypt($stored)],
        ['config_id = ?' => (int) $row['config_id']],
    );
}

$installer->endSetup();
