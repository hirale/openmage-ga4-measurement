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
