<?php

/**
 * Adds GA id snapshot columns to the order table. Captured at order
 * placement (frontend cookies) and consumed by server-side events that
 * fire without the visitor's cookies — currently the `refund` event.
 *
 * Runs as an install script even on stores that already use the module:
 * the setup resource is introduced in this version, so core_resource has
 * no prior row. Guarded so re-runs and pre-provisioned columns are safe.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$orderTable = $installer->getTable('sales/order');

if (!$connection->tableColumnExists($orderTable, 'ga_client_id')) {
    $connection->addColumn($orderTable, 'ga_client_id', [
        'type' => Varien_Db_Ddl_Table::TYPE_VARCHAR,
        'length' => 64,
        'nullable' => true,
        'comment' => 'GA4 client id captured at order placement',
    ]);
}

if (!$connection->tableColumnExists($orderTable, 'ga_session_id')) {
    $connection->addColumn($orderTable, 'ga_session_id', [
        'type' => Varien_Db_Ddl_Table::TYPE_VARCHAR,
        'length' => 32,
        'nullable' => true,
        'comment' => 'GA4 session id captured at order placement',
    ]);
}

$installer->endSetup();
