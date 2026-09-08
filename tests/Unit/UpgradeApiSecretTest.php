<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use HiraleGAMeasurementProtocol\Tests\Support\CoreHelperStub;
use HiraleGAMeasurementProtocol\Tests\Support\EncryptedConfigValue;
use HiraleGAMeasurementProtocol\Tests\Support\SetupConnectionStub;
use HiraleGAMeasurementProtocol\Tests\Support\SetupStub;
use PHPUnit\Framework\TestCase;

/**
 * The 4.0.0 -> 4.1.0 upgrade encrypts api_secret rows left in clear text by
 * earlier versions. It runs once per install, so the cost of getting it wrong
 * is a store that either leaks the secret or double-encrypts it into garbage.
 */
class UpgradeApiSecretTest extends TestCase
{
    private const SCRIPT = __DIR__
        . '/../../app/code/community/Hirale/GAMeasurementProtocol/sql/gameasurementprotocol_setup/upgrade-4.0.0-4.1.0.php';

    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    /**
     * @param list<array{config_id:int,value:string}> $rows
     */
    private function runUpgrade(array $rows): SetupConnectionStub
    {
        $connection = new SetupConnectionStub($rows);
        (new SetupStub($connection))->run(self::SCRIPT);

        return $connection;
    }

    public function testEncryptsPlainRowsAndLeavesMigratedOnesAlone(): void
    {
        $connection = $this->runUpgrade([
            ['config_id' => 1, 'value' => 'plain-default-secret'],
            ['config_id' => 2, 'value' => EncryptedConfigValue::of('already-migrated')],
            ['config_id' => 3, 'value' => 'plain-store-secret'],
        ]);

        self::assertCount(2, $connection->updates, 'only the two clear-text scopes are rewritten');
        self::assertSame([1, 3], array_map(
            static fn(array $update): int => $update['where']['config_id = ?'],
            $connection->updates,
        ));

        $core = new CoreHelperStub();
        foreach ($connection->updates as $i => $update) {
            $plain = [0 => 'plain-default-secret', 1 => 'plain-store-secret'][$i];
            self::assertSame($core->encrypt($plain), $update['bind']['value']);
            // Round-trips back to the original secret, so the runtime keeps working.
            self::assertSame($plain, $core->decrypt((string) $update['bind']['value']));
        }
    }

    public function testIsIdempotentWhenRunAgainOverItsOwnOutput(): void
    {
        $first = $this->runUpgrade([
            ['config_id' => 1, 'value' => 'plain-default-secret'],
            ['config_id' => 2, 'value' => EncryptedConfigValue::of('already-migrated')],
        ]);

        // Feed the migrated table straight back in: nothing left to do.
        $second = $this->runUpgrade([
            ['config_id' => 1, 'value' => (string) $first->updates[0]['bind']['value']],
            ['config_id' => 2, 'value' => EncryptedConfigValue::of('already-migrated')],
        ]);

        self::assertSame([], $second->updates);
    }

    public function testScopesTheQueryToTheApiSecretPathAndSkipsEmptyValues(): void
    {
        $connection = $this->runUpgrade([]);
        $select = $connection->lastSelect;

        self::assertNotNull($select);
        self::assertSame(['core_config_data', ['config_id', 'value']], $select->from);
        self::assertContains(['path = ?', 'google/measurement/api_secret'], $select->where);
        self::assertContains(['value IS NOT NULL', null], $select->where);
        self::assertContains(['value != ?', ''], $select->where);
    }

    public function testWrapsTheWorkInTheSetupLifecycle(): void
    {
        $connection = new SetupConnectionStub([]);
        $setup = new SetupStub($connection);
        $setup->run(self::SCRIPT);

        self::assertTrue($setup->started);
        self::assertTrue($setup->ended);
    }
}
