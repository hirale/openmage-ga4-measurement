# Hirale GAMeasurementProtocol

Server-side Google Analytics 4 events for OpenMage and Maho, delivered through the [hirale/queue](https://github.com/hirale/queue) worker over your choice of transport:

- **Measurement Protocol** (default) — the classic [GA4 MP API](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference?client_type=gtag#overview), authenticated by an API secret.
- **Data Manager API** — Google's strategic path for server-side integrations ([overview](https://developers.google.com/data-manager/api)), authenticated by an OAuth service account.

The transport is selectable per store view; observers and queued payloads are identical either way, so switching transports never loses in-flight events — each queued message is sent via the transport configured at the moment it is consumed.

This module can work with Mage_GoogleAnalytics module.
For duplicate key events, you can consult this page [https://support.google.com/analytics/answer/12313109?hl=en](https://support.google.com/analytics/answer/12313109?hl=en)

## Supported Events

 - `page_view`
 - `begin_checkout`
 - `add_to_cart`
 - `remove_from_cart`
 - `view_cart`
 - `purchase`
 - `refund`
 - `view_item`
 - `view_item_list`
 - `add_to_wishlist`
 - `sign_up`
 - `login`
 - `search`
 - `view_search_results`

You can check more events in the [events section](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference/events).

## Install

Requires [`hirale/queue`](https://github.com/hirale/queue) `^3.0` and
[`googleads/data-manager`](https://packagist.org/packages/googleads/data-manager)
(both pulled in automatically; the Data Manager client uses its REST
transport, so neither ext-grpc nor ext-protobuf is required).

**Maho** (26.5+):

```bash
composer require hirale/openmage-ga4-measurement
```

**OpenMage** (20.17+, PHP 8.3+) — one-time tweaks first; details in the
[hirale/queue README](https://github.com/hirale/queue#openmage-one-time-composer-adjustments):

```bash
composer config platform.php 8.3
composer config allow-plugins.hirale/magento-module-installer true
composer require hirale/magento-module-installer hirale/openmage-ga4-measurement
```

## Usage

Configuration lives in `System > Configuration > Sales > Google API > GA4 Server-Side Events`. Enable the module, pick the **API Transport**, and follow the matching setup below. The **Measurement ID** (`G-XXXXXXX`, from `Admin > Data Streams > choose your stream`) is needed by both transports.

### Setup — Measurement Protocol (default)

1. Generate an API SECRET in the Google Analytics UI: `Admin > Data Streams > choose your stream > Measurement Protocol > Create`.
2. Enter the Measurement ID and API Secret, save.

### Setup — Data Manager API

One-time Google-side setup (detailed in [Google's guide](https://developers.google.com/data-manager/api/devguides/quickstart/set-up-access)):

1. Create (or pick) a Google Cloud project and enable the **Data Manager API** in it.
2. Create a **service account** in that project (no Cloud IAM roles needed for ingestion) and download a **JSON key** for it.
3. In GA Admin, open the property's **Access Management** and add the service account's `client_email` as **Editor**.
4. Note the numeric **Property ID** (`Admin > Property Settings`) — this is not the `G-` Measurement ID.

Then in the store admin:

5. Set **API Transport** to *Data Manager API (OAuth service account)*.
6. Enter the Measurement ID, the **GA4 Property ID**, and paste the full JSON key file into **Service Account Key (JSON)**. The key is validated at save time and stored encrypted.
7. Click **Validate Destination** — it sends a validate-only test event with the values on screen (nothing is recorded in GA4) and reports the Google `requestId` on success.

### Transport semantics

- The transport is store-view scoped: different stores can post to MP and Data Manager side by side from the same queue consumer.
- Queued events are transport-agnostic; the transport is chosen at consume time, so switching it also applies to messages already in the queue.
- Data Manager rejects GA events older than **72 hours** — messages that aged past the window (e.g. a consumer outage) are dropped with a log entry instead of being retried forever.
- Data Manager item quantities are integers; fractional quantities (partial refunds) are rounded, while the monetary value stays exact.
- Permanent Data Manager errors (invalid argument, missing property access) fail the queue job immediately and show up in the queue's failure list; transient errors retry with backoff.
- **Restart queue workers after credential or transport changes.** Long-running consumers snapshot configuration (and cache the decoded service-account key plus its OAuth client) at boot. After rotating the service-account key — especially if the old key is revoked in Google Cloud — Data Manager messages fail as unrecoverable (`auth rejected`) and land in the failure list until the workers are restarted with the new config.

### Debug

Enable debug mode in the system config (gated by `System > Developer > Developer Client Restrictions`). Both transports log to the same file; Data Manager entries include the Google-assigned `requestId`.

```log
2024-06-10T18:28:24+00:00 DEBUG (7): {"client_id":"2131884568.1715846325","timestamp_micros":1718044092903759,"non_personalized_ads":false,"user_id":"140","events":[{"name":"page_view","params":{"engagement_time_msec":1,"page_location":"https://example.com/customer/account/index/","page_title":"Create New Customer Account"}}]}
2024-06-10T18:28:24+00:00 DEBUG (7): {
  "validationMessages": [ ]
}
```

## Upgrading from v2.x

No action needed: the transport defaults to Measurement Protocol and the existing `measurement_id`/`api_secret` config keeps working unchanged. The 2.x branch remains the MP-only maintenance line.

## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.
