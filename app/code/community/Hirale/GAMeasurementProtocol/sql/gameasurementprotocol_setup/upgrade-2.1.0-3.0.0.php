<?php

/**
 * v3.0.0 introduces the Data Manager API transport. It adds admin
 * configuration (core_config_data rows) only — no schema changes. This
 * no-op advances core_resource to 3.0.0 so setup stops re-evaluating.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
