<?php

declare(strict_types=1);

namespace Maho\Config {
    if (!class_exists(MessageHandler::class, false)) {
        /** Attribute-only stub of Maho's handler marker; the platform ships the real one. */
        #[\Attribute(\Attribute::TARGET_METHOD)]
        readonly class MessageHandler
        {
            public function __construct(
                public ?string $message = null,
                public int $priority = 0,
            ) {}
        }
    }
}

namespace Maho\Queue {
    if (!class_exists(QueueManager::class, false)) {
        /**
         * Recording stub for Maho's core queue entry point. The real class ships
         * with the platform, which the unit suite does not boot; tests assert
         * against the recorded dispatches.
         */
        class QueueManager
        {
            /** @var list<array{message:object,delaySeconds:?int,queue:string,dedupeKey:?string,stamps:list<object>}> */
            public static array $dispatches = [];

            public static ?\Throwable $nextException = null;

            public static function reset(): void
            {
                self::$dispatches = [];
                self::$nextException = null;
            }

            /** @param list<object> $stamps */
            public static function dispatch(
                object $message,
                ?int $delaySeconds = null,
                string $queue = 'default',
                ?string $dedupeKey = null,
                array $stamps = [],
            ): object {
                if (self::$nextException !== null) {
                    $e = self::$nextException;
                    self::$nextException = null;
                    throw $e;
                }

                self::$dispatches[] = [
                    'message' => $message,
                    'delaySeconds' => $delaySeconds,
                    'queue' => $queue,
                    'dedupeKey' => $dedupeKey,
                    'stamps' => $stamps,
                ];

                return $message;
            }
        }
    }
}
