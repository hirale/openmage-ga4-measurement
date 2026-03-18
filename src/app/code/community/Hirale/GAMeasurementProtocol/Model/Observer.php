<?php

use Jaybizzle\CrawlerDetect\CrawlerDetect;

class Hirale_GAMeasurementProtocol_Model_Observer
{
    protected $helper;
    protected $gaHelper;
    protected $queue;
    protected $baseEventData;
    protected $CrawlerDetect;

    public function __construct()
    {
        $this->helper = Mage::helper('gameasurementprotocol');
        $this->gaHelper = Mage::helper('googleanalytics');
        $this->queue = Mage::getModel('hirale_queue/task');
        $this->CrawlerDetect = new CrawlerDetect();
    }

    public function generateClientId(Varien_Event_Observer $observer)
    {
        $this->helper->getClientId();
    }

    protected function isBot()
    {
        return $this->CrawlerDetect->isCrawler(Mage::helper('core/http')->getHttpUserAgent());
    }

    protected function canSend()
    {
        if ($this->helper->isMeasurementEnabled() && !$this->isBot()) {
            return true;
        }
        return false;
    }


    /**
     * Add a task to the queue for processing by the Hirale_GAMeasurementProtocol_Model_Api class.
     *
     * @param array $events The name of the event to be processed.
     */
    protected function addToQueue($events)
    {
        try {
            foreach ($events['events'] as &$event) {
                $params = &$event['params'];
                if ($this->helper->isDebugMode()) {
                    $params['debug_mode'] = true;
                }
                $params['user_agent'] = Mage::helper('core/http')->getHttpUserAgent();
                $params['platform'] = Mage::helper('core/string')->cleanString(Mage::app()->getRequest()->getServer('HTTP_SEC_CH_UA_PLATFORM'));
            }

            $this->queue->addTask(
                'Hirale_GAMeasurementProtocol_Model_Api',
                $events
            );
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    protected function getBaseEventData()
    {
        if (!$this->baseEventData) {
            $this->baseEventData = [
                'client_id' => $this->helper->getClientId(),
                'timestamp_micros' => floor(microtime(true) * 1000000),
                'non_personalized_ads' => true
            ];
            if (Mage::getSingleton('customer/session')->isLoggedIn()) {
                $customer = Mage::getSingleton('customer/session')->getCustomer();
                $this->baseEventData['user_id'] = $customer->getId();
            }
        }
        return $this->baseEventData;
    }
    public function addOrRemoveItemsFromCart(Varien_Event_Observer $observer)
    {
        if (!$this->canSend()) {
            return;
        }

        /** @var Mage_Sales_Model_Quote_Item $item */
        $item = $observer->getEvent()->getItem();
        $quote = Mage::getSingleton('checkout/session')->getQuote();
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
            $eventData = $this->getBaseEventData();
            $items = [];
            $currency = $quote->getBaseCurrencyCode();
            if ($addedQty) {
                $items[] = $this->prepareItemData($item->getProduct(), $item->getBasePrice(), $currency, $addedQty, 0);
                $eventData['events'][] = [
                    'name' => 'add_to_cart',
                    'params' => [
                        'currency' => $currency,
                        'engagement_time_msec' => 1,
                        'value' => $this->helper->formatPrice($item->getBaseRowTotal()),
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
                        'value' => $this->helper->formatPrice($item->getBaseRowTotal()),
                        'items' => $items
                    ]
                ];
            }
            $this->addToQueue($eventData);
        }
    }

    public function addToWishlist(Varien_Event_Observer $observer)
    {
        if (!$this->canSend()) {
            return;
        }
        $items = $observer->getEvent()->getItems();
        if (count($items) > 0) {
            $eventData = $this->getBaseEventData();
            $value = 0;
            $currency = Mage::app()->getStore()->getBaseCurrencyCode();
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
            $this->addToQueue($eventData);
        }
    }

    public function signUp(Varien_Event_Observer $observer)
    {
        if (!$this->canSend()) {
            return;
        }
        $eventData = $this->getBaseEventData();
        $eventData['events'][] = [
            'name' => 'sign_up',
            'params' => [
                'engagement_time_msec' => 1,
            ]
        ];
        $this->addToQueue($eventData);
    }

    public function login(Varien_Event_Observer $observer)
    {
        if (!$this->canSend()) {
            return;
        }
        $eventData = $this->getBaseEventData();
        $eventData['events'][] = [
            'name' => 'login',
            'params' => [
                'engagement_time_msec' => 1,
            ]
        ];
        $this->addToQueue($eventData);
    }

    public function dispatchRouteEvent(Varien_Event_Observer $observer)
    {
        if (!$this->canSend()) {
            return;
        }
        $currency = Mage::app()->getStore()->getBaseCurrencyCode();
        $request = $observer->getEvent()->getApp()->getRequest();
        $route = $request->getModuleName() . '_' . $request->getControllerName() . '_' . $request->getActionName();
        $eventData = $this->getBaseEventData();

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
            $this->addToQueue($eventData);
        }
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
            'quantity' => $this->helper->formatPrice($quantity)
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
            ->addAttributeToSelect('manufacturer')
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
