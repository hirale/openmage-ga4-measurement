<?php

use Hirale\Queue\Bus;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

class Hirale_GAMeasurementProtocol_Model_Observer
{
    /**
     * @var Hirale_GAMeasurementProtocol_Helper_Data
     */
    protected $helper;
    /**
     * @var Mage_GoogleAnalytics_Helper_Data
     */
    protected $gaHelper;
    protected $CrawlerDetect;

    public function __construct()
    {
        $this->helper = Mage::helper('gameasurementprotocol');
        $this->gaHelper = Mage::helper('googleanalytics');
    }

    public function generateClientId(Varien_Event_Observer $observer)
    {
        $this->helper->getClientId();
    }

    protected function isBot()
    {
        return $this->getCrawlerDetect()->isCrawler(Mage::helper('core/http')->getHttpUserAgent());
    }

    protected function canSend(?int $storeId = null)
    {
        return $this->helper->isMeasurementEnabled($storeId) && !$this->isBot();
    }

    /**
     * Resolve the storefront store id to scope config (measurement_id /
     * api_secret) reads. Falls back to the current store when nothing more
     * specific is supplied.
     */
    protected function resolveStoreId($candidate = null)
    {
        if ($candidate !== null && $candidate !== '' && (int) $candidate > 0) {
            return (int) $candidate;
        }
        return (int) Mage::app()->getStore()->getId();
    }

    /**
     * Dispatch a payload of GA4 events for the originating store. Store id
     * and debug flag travel as message fields (not inside the payload), so
     * the handler posts the events body to GA4 exactly as built here.
     *
     * @param array $events
     */
    protected function addToQueue($events, ?int $storeId = null)
    {
        try {
            $storeId = $this->resolveStoreId($storeId);
            $shouldDebug = $this->helper->isDebugMode($storeId);

            $userAgent = (string) Mage::helper('core/http')->getHttpUserAgent();
            $platform = (string) Mage::helper('core/string')->cleanString(
                (string) Mage::app()->getRequest()->getServer('HTTP_SEC_CH_UA_PLATFORM'),
            );

            foreach ($events['events'] as &$event) {
                $params = &$event['params'];
                if ($shouldDebug) {
                    $params['debug_mode'] = true;
                }
                $params['user_agent'] = $userAgent;
                $params['platform'] = $platform;
            }
            unset($event, $params);

            Bus::dispatch(new Hirale_GAMeasurementProtocol_Message_MeasurementEventMessage(
                events: $events,
                storeId: (int) $storeId,
                debugMode: $shouldDebug,
            ));
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Build the shared per-event envelope (client_id, session_id, user_id,
     * timestamp). Regenerated per call rather than cached on the observer
     * instance, because store_id may vary between events handled in the
     * same request lifetime.
     */
    protected function getBaseEventData(?int $storeId = null)
    {
        $storeId = $this->resolveStoreId($storeId);
        $base = [
            'client_id' => $this->helper->getClientId(),
            'timestamp_micros' => (int) floor(microtime(true) * 1000000),
            'non_personalized_ads' => true,
        ];

        $sessionId = $this->helper->getSessionId($storeId);
        if ($sessionId !== null) {
            $base['session_id'] = $sessionId;
        }

        $customerSession = Mage::getSingleton('customer/session');
        if ($customerSession instanceof Mage_Customer_Model_Session && $customerSession->isLoggedIn()) {
            $customer = $customerSession->getCustomer();
            if ($customer && $customer->getId()) {
                $base['user_id'] = (string) $customer->getId();
            }
        }

        return $base;
    }


    protected function getCrawlerDetect()
    {
        if ($this->CrawlerDetect === null) {
            $this->CrawlerDetect = new CrawlerDetect();
        }
        return $this->CrawlerDetect;
    }
    public function addOrRemoveItemsFromCart(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Quote_Item $item */
        $item = $observer->getEvent()->getItem();
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $storeId = $this->resolveStoreId($item->getStoreId() ?: $quote->getStoreId());
        if (!$this->canSend($storeId)) {
            return;
        }
        if ($item->getParentItem()) {
            return;
        }
        if ($item->getQuoteId() != $quote->getId()) {
            return;
        }
        $processedProductsRegistry = Mage::registry('processed_quote_items_for_gameasurementprotocol') ?? new ArrayObject();
        if ($processedProductsRegistry->offsetExists($item->getId())) {
            return;
        }
        $processedProductsRegistry[$item->getId()] = true;
        Mage::register('processed_quote_items_for_gameasurementprotocol', $processedProductsRegistry, true);

        $addedQty = 0;
        $removedQty = 0;
        if ($item->isObjectNew()) {
            $addedQty = $item->getQty();
        } elseif ($item->isDeleted()) {
            $removedQty = $item->getQty();
        } elseif ($item->hasDataChanges()) {
            $newQty = $item->getQty();
            $oldQty = $item->getOrigData('qty');
            if ($newQty > $oldQty) {
                $addedQty = $newQty - $oldQty;
            } elseif ($newQty < $oldQty) {
                $removedQty = $oldQty - $newQty;
            }
        }

        if ($addedQty || $removedQty) {
            $eventData = $this->getBaseEventData($storeId);
            $items = [];
            $currency = $quote->getBaseCurrencyCode();
            if ($addedQty) {
                $items[] = $this->prepareItemData($item->getProduct(), $item->getBasePrice(), $currency, $addedQty, 0);
                $eventData['events'][] = [
                    'name' => 'add_to_cart',
                    'params' => [
                        'currency' => $currency,
                        'engagement_time_msec' => 1,
                        // Only the units added by this change, not the whole row.
                        'value' => $this->helper->formatPrice($item->getBasePrice() * $addedQty),
                        'items' => $items
                    ]
                ];
            } else {
                $items[] = $this->prepareItemData($item->getProduct(), $item->getBasePrice(), $currency, $removedQty, 0);
                $eventData['events'][] = [
                    'name' => 'remove_from_cart',
                    'params' => [
                        'currency' => $currency,
                        'engagement_time_msec' => 1,
                        // Only the units removed by this change, not the whole row.
                        'value' => $this->helper->formatPrice($item->getBasePrice() * $removedQty),
                        'items' => $items
                    ]
                ];
            }
            $this->addToQueue($eventData, $storeId);
        }
    }

    public function addToWishlist(Varien_Event_Observer $observer)
    {
        $items = $observer->getEvent()->getItems();
        if (!$items || count($items) === 0) {
            return;
        }

        $firstItem = is_array($items) ? reset($items) : $items[0];
        $storeId = $this->resolveStoreId(is_object($firstItem) && method_exists($firstItem, 'getStoreId') ? $firstItem->getStoreId() : null);
        if (!$this->canSend($storeId)) {
            return;
        }

        $eventData = $this->getBaseEventData($storeId);
        $value = 0;
        $currency = Mage::app()->getStore($storeId)->getBaseCurrencyCode();
        $newItems = [];
        foreach ($items as $item) {
            $_product = $item->getProduct();
            $_price = $_product->getFinalPrice();
            $newItems[] = $this->prepareItemData($item->getProduct(), $_price, $currency, 1, 0);
            $value += $_price;
        }
        $eventData['events'][] = [
            'name' => 'add_to_wishlist',
            'params' => [
                'currency' => $currency,
                'engagement_time_msec' => 1,
                'value' => $this->helper->formatPrice($value),
                'items' => $newItems
            ]
        ];
        $this->addToQueue($eventData, $storeId);
    }

    public function signUp(Varien_Event_Observer $observer)
    {
        $customer = $observer->getEvent()->getCustomer();
        $storeId = $this->resolveStoreId($customer ? $customer->getStoreId() : null);
        if (!$this->canSend($storeId)) {
            return;
        }
        $eventData = $this->getBaseEventData($storeId);
        $eventData['events'][] = [
            'name' => 'sign_up',
            'params' => [
                'engagement_time_msec' => 1,
            ]
        ];
        $this->addToQueue($eventData, $storeId);
    }

    public function login(Varien_Event_Observer $observer)
    {
        $customer = $observer->getEvent()->getCustomer();
        $storeId = $this->resolveStoreId($customer ? $customer->getStoreId() : null);
        if (!$this->canSend($storeId)) {
            return;
        }
        $eventData = $this->getBaseEventData($storeId);
        $eventData['events'][] = [
            'name' => 'login',
            'params' => [
                'engagement_time_msec' => 1,
            ]
        ];
        $this->addToQueue($eventData, $storeId);
    }

    public function dispatchRouteEvent(Varien_Event_Observer $observer)
    {
        $request = $observer->getEvent()->getApp()->getRequest();
        $route = $request->getModuleName() . '_' . $request->getControllerName() . '_' . $request->getActionName();

        // Purchase events scope to the order's store, not the current
        // storefront store — in a multi-store checkout flow these can differ.
        if ($route === 'checkout_onepage_success') {
            $order = Mage::getSingleton('checkout/session')->getLastRealOrder();
            $storeId = $this->resolveStoreId($order ? $order->getStoreId() : null);
        } else {
            $storeId = $this->resolveStoreId();
        }

        if (!$this->canSend($storeId)) {
            return;
        }

        $currency = Mage::app()->getStore($storeId)->getBaseCurrencyCode();
        $eventData = $this->getBaseEventData($storeId);

        $events = [];
        switch ($route) {
            case 'firecheckout_index_index':
            case 'checkout_onepage_index':
                $events[] = $this->getBeginCheckoutEvent($currency);
                break;

            case 'checkout_onepage_success':
                $events[] = $this->getPurchaseEvent($currency);
                break;

            case 'checkout_cart_index':
                $events[] = $this->getViewCartEvent($currency);
                break;

            case 'catalog_product_view':
                if (Mage::registry('current_product')) {
                    $events[] = $this->getViewItemEvent($currency);
                }
                break;

            case 'catalog_category_view':
                if (Mage::registry('current_category')) {
                    $events[] = $this->getViewItemListEvent($currency);
                }
                break;
            case 'catalogsearch_result_index':
                $searchEvents = $this->getSearchEvent($request->getParam('q'));
                $events = array_merge($events, $searchEvents);
                break;
        }
        $response = $observer->getEvent()->getApp()->getResponse();
        $body = substr($response->getBody(), 0, 100);
        $statusCode = $response->getHttpResponseCode();
        if (strpos($body, '<!DOCTYPE html') !== false && $statusCode == 200) {
            array_push(
                $events,
                [
                    'name' => 'page_view',
                    'params' => [
                        'engagement_time_msec' => 1,
                        'page_location' => Mage::helper('core/url')->getCurrentUrl(),
                        'page_title' => Mage::app()->getLayout()->getBlock('head')->getTitle()
                    ]
                ]
            );
        }
        if ($events) {
            $eventData['events'] = $events;
            $this->addToQueue($eventData, $storeId);
        }
    }

    /**
     * sales_order_place_after (frontend): persist the visitor's GA ids on
     * the order so server-side events that fire later without the visitor's
     * cookies (refunds from admin, API flows) attribute to the original
     * client instead of whoever triggered them.
     */
    public function captureOrderClientId(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || $order->getGaClientId()) {
            return;
        }
        $storeId = $this->resolveStoreId($order->getStoreId());
        if (!$this->helper->isMeasurementEnabled($storeId)) {
            return;
        }

        $order->setGaClientId($this->helper->getClientId());
        $sessionId = $this->helper->getSessionId($storeId);
        if ($sessionId !== null) {
            $order->setGaSessionId($sessionId);
        }
    }

    /**
     * sales_order_payment_refund (global): GA4 `refund` event, full or
     * partial. Runs in admin/API context, so the envelope is built from the
     * order's stored GA ids — never from the current request's cookies,
     * which would belong to the admin user. No bot check for the same
     * reason. GA4 reverses revenue by transaction_id; the client id only
     * has to be stable and well-formed.
     */
    public function refund(Varien_Event_Observer $observer)
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        $order = $creditmemo ? $creditmemo->getOrder() : null;
        if (!$order) {
            return;
        }
        $storeId = $this->resolveStoreId($order->getStoreId());
        if (!$this->helper->isMeasurementEnabled($storeId)) {
            return;
        }

        $currency = $order->getBaseCurrencyCode();
        $items = [];
        $index = 0;
        foreach ($creditmemo->getAllItems() as $memoItem) {
            $qty = (float) $memoItem->getQty();
            $orderItem = $memoItem->getOrderItem();
            if ($qty <= 0 || ($orderItem && $orderItem->getParentItemId())) {
                continue;
            }
            // Snapshot data from the credit memo line — independent of the
            // current catalog state (the product may be gone by refund time).
            $items[] = [
                'item_id' => (string) $memoItem->getSku(),
                'item_name' => (string) $memoItem->getName(),
                'currency' => $currency,
                'index' => $index++,
                'price' => $this->helper->formatPrice($memoItem->getBasePrice()),
                'quantity' => round($qty, 2),
            ];
        }

        $eventData = [
            'client_id' => $this->getOrderClientId($order),
            'timestamp_micros' => (int) floor(microtime(true) * 1000000),
            'non_personalized_ads' => true,
        ];
        if ($order->getGaSessionId()) {
            $eventData['session_id'] = (string) $order->getGaSessionId();
        }
        if ($order->getCustomerId()) {
            $eventData['user_id'] = (string) $order->getCustomerId();
        }
        $eventData['events'][] = [
            'name' => 'refund',
            'params' => [
                'currency' => $currency,
                'transaction_id' => $order->getIncrementId(),
                'engagement_time_msec' => 1,
                'value' => $this->helper->formatPrice($creditmemo->getBaseGrandTotal()),
                'items' => $items
            ]
        ];
        $this->addToQueue($eventData, $storeId);
    }

    protected function getOrderClientId($order): string
    {
        $stored = (string) $order->getGaClientId();
        if ($stored !== '') {
            return $stored;
        }
        // Deterministic fallback for admin/API/legacy orders placed before
        // GA ids were captured.
        $seed = (string) $order->getIncrementId();

        return sprintf('%u.%u', crc32('ga_cid_' . $seed), crc32($seed . '_ga_cid'));
    }

    protected function getBeginCheckoutEvent($currency)
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $items = $this->getQuoteItems($quote, $currency);

        return [
            'name' => 'begin_checkout',
            'params' => [
                'currency' => $currency,
                'engagement_time_msec' => 1,
                'value' => $this->helper->formatPrice($quote->getBaseSubtotal()),
                'items' => $items
            ]
        ];
    }

    protected function getPurchaseEvent($currency)
    {
        $order = Mage::getSingleton('checkout/session')->getLastRealOrder();
        $items = $this->getOrderItems($order, $currency);

        return [
            'name' => 'purchase',
            'params' => [
                'currency' => $currency,
                'transaction_id' => $order->getIncrementId(),
                'engagement_time_msec' => 1,
                'value' => $this->helper->formatPrice($order->getBaseGrandTotal()),
                'coupon' => strtoupper((string) $order->getCouponCode()),
                'shipping' => $this->helper->formatPrice($order->getBaseShippingAmount()),
                'tax' => $this->helper->formatPrice($order->getBaseTaxAmount()),
                'items' => $items
            ]
        ];
    }

    protected function getViewCartEvent($currency)
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $items = $this->getQuoteItems($quote, $currency);

        return [
            'name' => 'view_cart',
            'params' => [
                'currency' => $currency,
                'value' => $this->helper->formatPrice($quote->getBaseSubtotal()),
                'engagement_time_msec' => 1,
                'items' => $items
            ]
        ];
    }

    protected function getViewItemEvent($currency)
    {
        $product = Mage::registry('current_product');

        return [
            'name' => 'view_item',
            'params' => [
                'currency' => $currency,
                'value' => $this->helper->formatPrice($product->getFinalPrice()),
                'engagement_time_msec' => 1,
                'items' => [
                    [
                        'item_id' => $product->getSku(),
                        'item_name' => $product->getName(),
                        'currency' => $currency,
                        'index' => 0,
                        'item_brand' => $this->getManufacturerLabel($product),
                        'item_category' => $this->gaHelper->getLastCategoryName($product) ?? '',
                        'price' => $this->helper->formatPrice($product->getFinalPrice()),
                        'quantity' => 1
                    ]
                ]
            ]
        ];
    }

    protected function getViewItemListEvent($currency)
    {
        $category = Mage::registry('current_category');
        $items = $this->getCategoryItems($currency);

        return [
            'name' => 'view_item_list',
            'params' => [
                'item_list_id' => $category->getId(),
                'engagement_time_msec' => 1,
                'item_list_name' => $category->getName(),
                'items' => $items
            ]
        ];
    }

    protected function getQuoteItems($quote, $currency)
    {
        $visibleItems = array_filter(
            $quote->getAllVisibleItems(),
            fn($i) => !$i->getParentItem()
        );

        $productIds = array_map(fn($i) => $i->getProductId(), $visibleItems);
        $productsById = $this->loadProductsWithManufacturer($productIds);

        $items = [];
        foreach (array_values($visibleItems) as $key => $quoteItem) {
            $product = $productsById[$quoteItem->getProductId()] ?? $quoteItem->getProduct();
            $items[] = $this->prepareItemData($product, $quoteItem->getBasePrice(), $currency, $quoteItem->getQty(), $key);
        }
        return $items;
    }

    protected function getOrderItems($order, $currency)
    {
        $visibleItems = array_filter(
            $order->getAllVisibleItems(),
            fn($i) => !$i->getParentItem()
        );

        $productIds = array_map(fn($i) => $i->getProductId(), $visibleItems);
        $productsById = $this->loadProductsWithManufacturer($productIds);

        $items = [];
        foreach (array_values($visibleItems) as $key => $orderItem) {
            $product = $productsById[$orderItem->getProductId()] ?? $orderItem->getProduct();
            $items[] = $this->prepareItemData(
                $product,
                $orderItem->getBasePrice(),
                $currency,
                $orderItem->getQtyOrdered(),
                $key,
                $orderItem->getBaseDiscountAmount()
            );
        }
        return $items;
    }

    protected function getCategoryItems($currency)
    {
        $layer = Mage::getSingleton('catalog/layer');
        $productCollection = $layer->getProductCollection();
        $toolbarBlock = Mage::app()->getLayout()->getBlock('product_list_toolbar');
        $pageSize = $toolbarBlock->getLimit();
        $currentPage = $toolbarBlock->getCurrentPage();

        // The layer collection is already loaded by the time this observer runs (core_app_run_after),
        // so setPageSize/setCurPage and addAttributeToSelect are no-ops here. The collection already
        // contains the correct page of products as rendered. Manufacturer must be loaded separately.
        if ($pageSize !== 'all') {
            $productCollection->setPageSize($pageSize)->setCurPage($currentPage);
        }
        $productIds = array_keys($productCollection->getItems());
        $manufacturerMap = $this->loadProductsWithManufacturer($productIds);

        $items = [];
        foreach ($productCollection as $key => $product) {
            if (isset($manufacturerMap[$product->getId()])) {
                $product->setData('manufacturer', $manufacturerMap[$product->getId()]->getData('manufacturer'));
            }
            $items[] = $this->prepareItemData($product, $product->getFinalPrice(), $currency, 1, $key);
        }
        return $items;
    }

    protected function prepareItemData($product, $price, $currency, $quantity, $index, $discount = null)
    {
        $item = [
            'item_id' => $product->getSku(),
            'item_name' => $product->getName(),
            'currency' => $currency,
            'index' => $index,
            'item_brand' => $this->getManufacturerLabel($product),
            'item_category' => $this->gaHelper->getLastCategoryName($product) ?? '',
            'price' => $this->helper->formatPrice($price),
            'quantity' => round((float) $quantity, 2)
        ];

        if ($discount !== null) {
            $item['discount'] = $this->helper->formatPrice($discount);
        }

        return $item;
    }

    protected function loadProductsWithManufacturer(array $productIds)
    {
        if (empty($productIds)) {
            return [];
        }
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect(['name', 'manufacturer'])
            ->addFieldToFilter('entity_id', ['in' => $productIds]);
        $map = [];
        foreach ($collection as $product) {
            $map[$product->getId()] = $product;
        }
        return $map;
    }

    protected function getManufacturerLabel($product): string
    {
        $rawValue = $product->getData('manufacturer');
        if (empty($rawValue)) {
            return '';
        }
        $value = $product->getResource()->getAttribute('manufacturer')
            ?->getFrontend()
            ->getValue($product);
        return $value ? (string) $value : '';
    }

    protected function getSearchEvent($term)
    {
        $events = [];
        foreach (['search', 'view_search_results'] as $event) {
            $events[] = [
                'name' => $event,
                'params' => [
                    'search_term' => $term,
                    'engagement_time_msec' => 1,
                ]
            ];
        }
        return $events;
    }
}
