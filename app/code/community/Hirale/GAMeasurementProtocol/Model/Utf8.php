<?php

declare(strict_types=1);

/**
 * UTF-8 scrubbing shared by both transports. Storefront data (search terms,
 * page titles, cookies) can carry ill-formed bytes: the Data Manager protos
 * reject them and json_encode for the Measurement Protocol returns false —
 * both transports must sanitize the envelope before serializing. Pure
 * (no Mage access) so the translator stays testable in isolation.
 */
final class Hirale_GAMeasurementProtocol_Model_Utf8
{
    /**
     * Replace ill-formed UTF-8 sequences in every string key and value.
     */
    public static function deep(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $cleanKey = is_string($key) ? self::clean($key) : $key;
            if (is_array($value)) {
                $clean[$cleanKey] = self::deep($value);
            } elseif (is_string($value)) {
                $clean[$cleanKey] = self::clean($value);
            } else {
                $clean[$cleanKey] = $value;
            }
        }

        return $clean;
    }

    public static function clean(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
