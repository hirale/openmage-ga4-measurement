<?php

declare(strict_types=1);

namespace Hirale\Queue {
    if (!class_exists(Bus::class)) {
        /**
         * Recording stub for the static Bus accessor from hirale/queue. The
         * real package is not installed in the unit suite; tests assert
         * against the recorded dispatches.
         */
        class Bus
        {
            /** @var list<array{method:string,message:object,queue:?string,delaySeconds:?int}> */
            public static array $dispatches = [];

            public static ?\Throwable $nextException = null;

            public static function reset(): void
            {
                self::$dispatches = [];
                self::$nextException = null;
            }

            /** @param list<object> $stamps */
            public static function dispatch(object $message, array $stamps = []): object
            {
                return self::record('dispatch', $message, null, null);
            }

            /** @param list<object> $stamps */
            public static function dispatchOnQueue(object $message, string $queueName, array $stamps = []): object
            {
                return self::record('dispatchOnQueue', $message, $queueName, null);
            }

            /** @param list<object> $stamps */
            public static function dispatchDelayed(object $message, int $delaySeconds, array $stamps = []): object
            {
                return self::record('dispatchDelayed', $message, null, $delaySeconds);
            }

            private static function record(string $method, object $message, ?string $queue, ?int $delaySeconds): object
            {
                if (self::$nextException !== null) {
                    $e = self::$nextException;
                    self::$nextException = null;
                    throw $e;
                }
                self::$dispatches[] = [
                    'method' => $method,
                    'message' => $message,
                    'queue' => $queue,
                    'delaySeconds' => $delaySeconds,
                ];
                return $message;
            }
        }
    }
}

namespace Symfony\Component\Messenger\Stamp {
    if (!class_exists(DelayStamp::class)) {
        class DelayStamp
        {
            public function __construct(public readonly int $delay)
            {
            }
        }
    }
}
