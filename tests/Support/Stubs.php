<?php

declare(strict_types=1);

namespace HiraleGAMeasurementProtocol\Tests\Support;

use Throwable;

class HttpHelperStub
{
    public string $userAgent = 'Mozilla/5.0 (test)';

    public function getHttpUserAgent(): string
    {
        return $this->userAgent;
    }
}

class StringHelperStub
{
    public function cleanString(?string $value): string
    {
        return (string) $value;
    }
}

class UrlHelperStub
{
    public string $currentUrl = 'https://example.test/test';

    public function getCurrentUrl(): string
    {
        return $this->currentUrl;
    }
}

class CoreHelperStub
{
    public bool $devAllowed = false;

    public function isDevAllowed(): bool
    {
        return $this->devAllowed;
    }

    public function encrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return 'enc:' . base64_encode($value);
    }

    /**
     * Mirrors the platform behavior closely enough for tests: decrypting a
     * value that was never encrypted yields garbage, not the input.
     */
    public function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (str_starts_with($value, 'enc:')) {
            return (string) base64_decode(substr($value, 4), true);
        }

        return md5($value);
    }
}

class GoogleAnalyticsHelperStub
{
    public function getLastCategoryName($product): ?string
    {
        return null;
    }
}

class StoreStub
{
    public int $id = 1;
    public string $baseCurrencyCode = 'USD';

    public function __construct(int $id = 1, string $currency = 'USD')
    {
        $this->id = $id;
        $this->baseCurrencyCode = $currency;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBaseCurrencyCode(): string
    {
        return $this->baseCurrencyCode;
    }
}

class RequestStub
{
    /** @var array<string, mixed> */
    public array $server = [];

    /**
     * @return mixed
     */
    public function getServer(string $key)
    {
        return $this->server[$key] ?? null;
    }
}

class AppStub
{
    /** @var array<int, StoreStub> */
    public array $stores = [];

    public StoreStub $currentStore;
    public RequestStub $request;

    public function __construct(int $currentStoreId = 1)
    {
        $this->currentStore = new StoreStub($currentStoreId);
        $this->stores[$currentStoreId] = $this->currentStore;
        $this->request = new RequestStub();
    }

    public function getStore(?int $storeId = null): StoreStub
    {
        if ($storeId === null || !isset($this->stores[$storeId])) {
            return $this->currentStore;
        }
        return $this->stores[$storeId];
    }

    public function getRequest(): RequestStub
    {
        return $this->request;
    }
}

class CoreSessionStub
{
    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * @return mixed
     */
    public function getData(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }
}

class CustomerStub
{
    public function __construct(public ?int $id = null, public ?int $storeId = null)
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }
}

class RecordingApi extends \Hirale_GAMeasurementProtocol_Model_Api
{
    /** @var list<array{url:string,body:string}> */
    public array $posts = [];

    /** @var array{http_code:int,curl_errno:int,curl_error:string} */
    public array $nextResponse = ['http_code' => 200, 'curl_errno' => 0, 'curl_error' => ''];

    #[\Override]
    protected function _postToGa4(string $url, string $body): array
    {
        $this->posts[] = ['url' => $url, 'body' => $body];

        return $this->nextResponse;
    }
}

class ProductStub
{
    public function __construct(
        private string $sku = 'SKU-1',
        private string $name = 'Item One',
    ) {
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getData(string $key): mixed
    {
        return null;
    }
}

class QuoteStub
{
    public function __construct(
        private int $id = 100,
        private int $storeId = 1,
        private string $currency = 'USD',
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getBaseCurrencyCode(): string
    {
        return $this->currency;
    }
}

class CheckoutSessionStub
{
    public function __construct(private QuoteStub $quote)
    {
    }

    public function getQuote(): QuoteStub
    {
        return $this->quote;
    }
}

class CartItemStub
{
    public function __construct(
        private int $id,
        private float $qty,
        private ?float $origQty,
        private float $basePrice,
        private float $baseRowTotal,
        private int $quoteId,
        private int $storeId,
        private bool $isNew,
        private bool $hasChanges,
        private ProductStub $product,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getOrigData(string $key): ?float
    {
        return $key === 'qty' ? $this->origQty : null;
    }

    public function getBasePrice(): float
    {
        return $this->basePrice;
    }

    public function getBaseRowTotal(): float
    {
        return $this->baseRowTotal;
    }

    public function getQuoteId(): int
    {
        return $this->quoteId;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getProduct(): ProductStub
    {
        return $this->product;
    }

    public function getParentItem(): ?object
    {
        return null;
    }

    public function isObjectNew(): bool
    {
        return $this->isNew;
    }

    public function isDeleted(): bool
    {
        return false;
    }

    public function hasDataChanges(): bool
    {
        return $this->hasChanges;
    }
}

/**
 * Varien-style magic data bag for orders.
 */
class OrderStub
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data = [])
    {
    }

    public function __call(string $method, array $args): mixed
    {
        $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));
        if (str_starts_with($method, 'get')) {
            return $this->data[$key] ?? null;
        }
        if (str_starts_with($method, 'set')) {
            $this->data[$key] = $args[0];
            return $this;
        }
        return null;
    }
}

class CreditmemoItemStub
{
    public function __construct(
        private string $sku,
        private string $name,
        private float $basePrice,
        private float $qty,
        private ?int $parentItemId = null,
    ) {
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBasePrice(): float
    {
        return $this->basePrice;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getOrderItem(): object
    {
        $parentItemId = $this->parentItemId;
        return new class ($parentItemId) {
            public function __construct(private ?int $parentItemId)
            {
            }

            public function getParentItemId(): ?int
            {
                return $this->parentItemId;
            }
        };
    }
}

class CreditmemoStub
{
    /** @param list<CreditmemoItemStub> $items */
    public function __construct(
        private OrderStub $order,
        private float $baseGrandTotal,
        private array $items,
    ) {
    }

    public function getOrder(): OrderStub
    {
        return $this->order;
    }

    public function getBaseGrandTotal(): float
    {
        return $this->baseGrandTotal;
    }

    /** @return list<CreditmemoItemStub> */
    public function getAllItems(): array
    {
        return $this->items;
    }
}
