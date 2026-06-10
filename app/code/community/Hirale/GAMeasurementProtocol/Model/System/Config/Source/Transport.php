<?php

declare(strict_types=1);

class Hirale_GAMeasurementProtocol_Model_System_Config_Source_Transport
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_MEASUREMENT_PROTOCOL,
                'label' => 'Measurement Protocol (API secret)',
            ],
            [
                'value' => Hirale_GAMeasurementProtocol_Helper_Data::TRANSPORT_DATA_MANAGER,
                'label' => 'Data Manager API (OAuth service account)',
            ],
        ];
    }
}
