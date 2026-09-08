<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the packaging seams that decide which queue backend a platform gets.
 * They live in JSON/XML, so nothing else in the suite would catch a regression.
 */
class PackageMetadataTest extends TestCase
{
    public function testQueueBackendIsNotARuntimeRequirement(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertIsArray($composer);
        // OpenMage users pull the queue backend in themselves; Maho dispatches
        // through core Maho_Queue and must not drag hirale/queue along.
        self::assertArrayNotHasKey('hirale/queue', $composer['require']);
        self::assertArrayHasKey('hirale/queue', $composer['suggest']);
        self::assertArrayNotHasKey('mahocommerce/maho', $composer['require']);
    }

    public function testModuleDeclarationHasNoQueueDependency(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/etc/modules/Hirale_GAMeasurementProtocol.xml');

        self::assertNotFalse($xml);
        // A hard Hirale_Queue dependency aborts config loading on a Maho store,
        // where the module dispatches through core Maho_Queue instead.
        self::assertFalse(isset($xml->modules->Hirale_GAMeasurementProtocol->depends->Hirale_Queue));
        self::assertSame('community', (string) $xml->modules->Hirale_GAMeasurementProtocol->codePool);
    }

    public function testConfigRegistersBothQueueBackendsOnTheAnalyticsQueue(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/GAMeasurementProtocol/etc/config.xml');

        self::assertNotFalse($xml);
        self::assertSame(
            \Hirale_GAMeasurementProtocol_Helper_Data::QUEUE_ANALYTICS,
            (string) $xml->global->hirale_queue->routing->Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage,
        );
        self::assertSame(
            'gameasurementprotocol/api',
            (string) $xml->global->hirale_queue->handlers->Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage,
        );
        // Maho pool routing: one outbound HTTP call per message never belongs
        // in the resident fast pool.
        self::assertSame('slow', (string) $xml->global->queue->routing->analytics);
    }

    public function testHandlerCarriesTheMahoMessageHandlerAttribute(): void
    {
        $method = new \ReflectionMethod(\Hirale_GAMeasurementProtocol_Model_Api::class, '__invoke');

        self::assertNotEmpty(
            $method->getAttributes(\Maho\Config\MessageHandler::class),
            'Maho compiles this attribute into vendor/composer/maho_attributes.php at dump-autoload time',
        );
    }

    public function testApiSecretIsStoredEncrypted(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/GAMeasurementProtocol/etc/system.xml');

        self::assertNotFalse($xml);
        $field = $xml->sections->google->groups->measurement->fields->api_secret;

        // The core backend encrypts on save and decrypts for the form; the
        // module must not grow its own crypto for this.
        self::assertSame('adminhtml/system_config_backend_encrypted', (string) $field->backend_model);
        self::assertSame('obscure', (string) $field->frontend_type);
    }

    public function testModuleVersionMatchesTheUpgradeScriptItShipsWith(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/GAMeasurementProtocol/etc/config.xml');

        self::assertNotFalse($xml);
        $version = (string) $xml->modules->Hirale_GAMeasurementProtocol->version;

        self::assertSame('4.1.0', $version);
        self::assertFileExists(sprintf(
            '%s/../../app/code/community/Hirale/GAMeasurementProtocol/sql/gameasurementprotocol_setup/upgrade-4.0.0-%s.php',
            __DIR__,
            $version,
        ));
    }
}
