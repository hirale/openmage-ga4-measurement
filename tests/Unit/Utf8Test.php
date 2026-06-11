<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Utf8Test extends TestCase
{
    public function testValidStringsPassThroughUntouched(): void
    {
        self::assertSame('café ☕', \Hirale_GAMeasurementProtocol_Model_Utf8::clean('café ☕'));
    }

    public function testIllFormedBytesAreReplacedWithValidUtf8(): void
    {
        $cleaned = \Hirale_GAMeasurementProtocol_Model_Utf8::clean("caf\xE9");

        self::assertSame(1, preg_match('//u', $cleaned));
        self::assertStringStartsWith('caf', $cleaned);
    }

    public function testDeepSanitizesNestedValuesAndKeys(): void
    {
        $dirty = [
            "k\xE9y" => "caf\xE9",
            'nested' => ['note' => "br\xFCh", 'count' => 3, 'flag' => true],
        ];

        $clean = \Hirale_GAMeasurementProtocol_Model_Utf8::deep($dirty);

        self::assertNotFalse(json_encode($clean), 'sanitized arrays must always be JSON-encodable');
        self::assertSame(3, $clean['nested']['count']);
        self::assertTrue($clean['nested']['flag']);
        foreach (array_keys($clean) as $key) {
            self::assertSame(1, preg_match('//u', (string) $key));
        }
    }
}
