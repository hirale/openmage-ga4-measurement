<?php

/**
 * One GA4 Measurement Protocol payload to post. Carries the full event
 * envelope ($events — client_id, timestamp_micros, events[], etc.), the
 * dispatching store id (so the handler can resolve measurement_id +
 * api_secret), and whether to log the request/response for debugging.
 */
final readonly class Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage
{
    /**
     * @param array<string, mixed> $events
     */
    public function __construct(
        public array $events,
        public int $storeId,
        public bool $debugMode = false,
    ) {
    }
}
