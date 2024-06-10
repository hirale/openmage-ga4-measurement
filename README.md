# Hirale GAMeasurementProtocol

A module for integrating with the [Google Analytics 4 Measurement Protocol API](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference?client_type=gtag#overview), sending events from server side.

This module can work with Mage_GoogleAnalytics module. 
For duplicate key events, you can consult this page [https://support.google.com/analytics/answer/12313109?hl=en](https://support.google.com/analytics/answer/12313109?hl=en)

## Supported Events

 - `page_view`
 - `begin_checkout`
 - `add_to_cart`
 - `remove_from_cart`
 - `view_cart`
 - `purchase`
 - `view_item`
 - `view_item_list`
 - `add_to_wishlist`
 - `sign_up`
 - `login`
 - `search`
 - `view_search_results`

You can check more events in the [events section](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference/events).

## Install

> [!NOTE]
> This module depends on [`openmage-redis-queue`](https://github.com/hirale/openmage-redis-queue). It has been added to composer requirements.

### Install with [Magento Composer Installer](https://github.com/Cotya/magento-composer-installer)

```bash
composer require hirale/openmage-ga4-measurement
```

## Usage

### Setup

1. Generate an API SECRET in the Google Analytics UI. To create a new secret, navigate to `Admin > Data Streams > choose your stream > Measurement Protocol > Create`.
2. Get measurement ID associated with a stream, found in the Google Analytics UI under `Admin > Data Streams > choose your stream > Measurement ID`.
3. Go to openmage system config `System > Configuration > Sales > Google API > Measurement Protocol`. Insert the parameters from step 1 and 2, save.


### Debug

Enable debug mode in the openmage system config `System > Configuration > Sales > Google API > Measurement Protocol`.

```log
2024-06-10T18:28:24+00:00 DEBUG (7): {"client_id":"2131884568.1715846325","timestamp_micros":1718044092903759,"non_personalized_ads":false,"user_id":"140","events":[{"name":"page_view","params":{"engagement_time_msec":1,"page_location":"https://example.com/customer/account/index/","page_title":"Create New Customer Account"}}]}
2024-06-10T18:28:24+00:00 DEBUG (7): {
  "validationMessages": [ ]
}
```

## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.
