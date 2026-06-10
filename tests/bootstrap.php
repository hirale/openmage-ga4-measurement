<?php

declare(strict_types=1);

if (!class_exists('Mage_Core_Helper_Abstract')) {
    class Mage_Core_Helper_Abstract {}
}

if (!class_exists('Mage_Core_Helper_Data')) {
    class Mage_Core_Helper_Data extends Mage_Core_Helper_Abstract
    {
        public const XML_PATH_DEV_ALLOW_IPS = 'dev/restrict/allow_ips';

        public function isDevAllowed(): bool
        {
            return false;
        }
    }
}

if (!class_exists('Mage_Customer_Model_Session')) {
    class Mage_Customer_Model_Session
    {
        public bool $loggedIn = false;
        public ?object $customer = null;

        public function isLoggedIn(): bool
        {
            return $this->loggedIn;
        }

        public function getCustomer(): ?object
        {
            return $this->customer;
        }
    }
}

if (!class_exists('Mage')) {
    class Mage
    {
        /** @var array<string, object> */
        public static array $helpers = [];

        /** @var array<string, object> */
        public static array $models = [];

        /** @var array<string, object> */
        public static array $singletons = [];

        /** @var array<string, mixed> */
        public static array $registry = [];

        /** @var array<string, array<string, mixed>> */
        public static array $config = [];

        public static ?object $app = null;

        /** @var list<array{message:mixed,level:mixed,file:string}> */
        public static array $logs = [];

        /** @var list<Throwable> */
        public static array $exceptions = [];

        public static function reset(): void
        {
            self::$helpers = [];
            self::$models = [];
            self::$singletons = [];
            self::$registry = [];
            self::$config = [];
            self::$app = null;
            self::$logs = [];
            self::$exceptions = [];
        }

        public static function helper(string $alias): object
        {
            if (!isset(self::$helpers[$alias])) {
                throw new RuntimeException(sprintf('Helper %s is unavailable.', $alias));
            }
            return self::$helpers[$alias];
        }

        public static function getModel(string $alias): object
        {
            if (!isset(self::$models[$alias])) {
                throw new RuntimeException(sprintf('Model %s is unavailable.', $alias));
            }
            return self::$models[$alias];
        }

        public static function getSingleton(string $alias): object
        {
            if (!isset(self::$singletons[$alias])) {
                throw new RuntimeException(sprintf('Singleton %s is unavailable.', $alias));
            }
            return self::$singletons[$alias];
        }

        public static function register(string $key, mixed $value, bool $graceful = false): void
        {
            self::$registry[$key] = $value;
        }

        public static function unregister(string $key): void
        {
            unset(self::$registry[$key]);
        }

        public static function registry(string $key): mixed
        {
            return self::$registry[$key] ?? null;
        }

        /**
         * @return mixed
         */
        public static function getStoreConfig(string $path, $store = null)
        {
            $storeKey = $store === null ? '__null__' : (string) $store;
            return self::$config[$storeKey][$path] ?? self::$config['__null__'][$path] ?? null;
        }

        public static function getStoreConfigFlag(string $path, $store = null): bool
        {
            return !empty(self::getStoreConfig($path, $store));
        }

        public static function app(): object
        {
            if (self::$app === null) {
                throw new RuntimeException('Mage app is unavailable.');
            }
            return self::$app;
        }

        public static function log($message, $level = null, string $file = '', bool $forceLog = false): void
        {
            self::$logs[] = ['message' => $message, 'level' => $level, 'file' => $file];
        }

        public static function logException(Throwable $e): void
        {
            self::$exceptions[] = $e;
        }
    }
}

if (!class_exists('Varien_Event')) {
    class Varien_Event
    {
        /** @param array<string, mixed> $data */
        public function __construct(private array $data = [])
        {
        }

        public function __call(string $method, array $args): mixed
        {
            if (str_starts_with($method, 'get')) {
                $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));
                return $this->data[$key] ?? null;
            }
            return null;
        }
    }

    class Varien_Event_Observer
    {
        public function __construct(private Varien_Event $event)
        {
        }

        public function getEvent(): Varien_Event
        {
            return $this->event;
        }
    }
}

require_once __DIR__ . '/Support/QueueBusStub.php';
require_once __DIR__ . '/../app/code/community/Hirale/GAMeasurementProtocol/Helper/Data.php';
require_once __DIR__ . '/../app/code/community/Hirale/GAMeasurementProtocol/Message/MeasurementEventMessage.php';
require_once __DIR__ . '/../app/code/community/Hirale/GAMeasurementProtocol/Model/Api.php';
require_once __DIR__ . '/Support/Stubs.php';
require_once __DIR__ . '/../app/code/community/Hirale/GAMeasurementProtocol/Model/Observer.php';
