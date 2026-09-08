# Hirale GAMeasurementProtocol

Server-side Google Analytics 4 events for OpenMage and Maho, delivered by a background queue worker over your choice of transport:

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

## Queue backend

Events are never posted from the request that generated them: the observer
hands a message to a queue and a worker uploads it. There is no shared queue
package any more — the module picks a backend at runtime, in this order:

| Platform | Backend | Package to install |
| --- | --- | --- |
| Maho with the core `Maho_Queue` module | `\Maho\Queue\QueueManager` | none — it ships with the platform |
| OpenMage | `\Hirale\Queue\Bus` | [`hirale/queue`](https://github.com/hirale/queue) `^3.0` |
| Neither | — | events are not queued; the storefront is unaffected |

`Maho_Queue` wins whenever it is present and enabled, even on a store that also
has `hirale/queue` installed.

> **4.0.0 is a breaking change.** `hirale/queue` moved from `require` to
> `suggest` — OpenMage installs that upgrade from 3.x must require it
> explicitly, or events stop being queued — and `googleads/data-manager` did
> the same, so stores on the Data Manager transport must require it too. The
> messages, transports, config paths and `analytics` queue name are unchanged.

## Install

The default Measurement Protocol transport needs nothing beyond `ext-curl`.
The Data Manager API transport needs one extra package — see
[Setup — Data Manager API](#setup--data-manager-api).

**Maho** (26.5+, with core `Maho_Queue`):

```bash
composer require hirale/openmage-ga4-measurement
composer dump-autoload
```

`composer dump-autoload` is required: it compiles the
`#[\Maho\Config\MessageHandler]` attribute into
`vendor/composer/maho_attributes.php`. Without it the message has no registered
handler, and the queue refuses to decode it.

**OpenMage** (20.17+, PHP 8.3+) — one-time tweaks first; details in the
[hirale/queue README](https://github.com/hirale/queue#openmage-one-time-composer-adjustments):

```bash
composer config platform.php 8.3
composer config allow-plugins.hirale/magento-module-installer true
composer require hirale/magento-module-installer hirale/queue hirale/openmage-ga4-measurement
```

## Usage

Configuration lives in `System > Configuration > Sales > Google API > GA4 Server-Side Events`. Enable the module, pick the **API Transport**, and follow the matching setup below. The **Measurement ID** (`G-XXXXXXX`, from `Admin > Data Streams > choose your stream`) is needed by both transports.

### Setup — Measurement Protocol (default)

1. Generate an API SECRET in the Google Analytics UI: `Admin > Data Streams > choose your stream > Measurement Protocol > Create`.
2. Enter the Measurement ID and API Secret, save. The secret is stored
   encrypted and shown obscured afterwards; installs upgrading from 4.0.0 or
   earlier have their existing secret encrypted in place by the upgrade
   script, across every scope.

### Setup — Data Manager API

This transport is optional, so its client is not installed by default:

```bash
composer require googleads/data-manager
```

It uses the REST transport, so neither ext-grpc nor ext-protobuf is required.
Without the package the module keeps working on the Measurement Protocol;
switching a store to Data Manager is refused at save time, and any message
already queued for it fails with the same `composer require` hint instead of
retrying forever.

One-time Google-side setup (detailed in [Google's guide](https://developers.google.com/data-manager/api/devguides/quickstart/set-up-access)):

1. Create (or pick) a Google Cloud project and enable the **Data Manager API** in it.
2. Create a **service account** in that project (no Cloud IAM roles needed for ingestion) and download a **JSON key** for it.
3. In GA Admin, open the property's **Access Management** and add the service account's `client_email` as **Editor**.
4. Note the numeric **Property ID** (`Admin > Property Settings`) — this is not the `G-` Measurement ID.

Then in the store admin:

5. Set **API Transport** to *Data Manager API (OAuth service account)*.
6. Enter the Measurement ID, the **GA4 Property ID**, and paste the full JSON key file into **Service Account Key (JSON)**. The key is validated at save time and stored encrypted.
7. Click **Validate Destination** — it sends a validate-only test event with the values on screen (nothing is recorded in GA4) and reports the Google `requestId` on success.

### Queues and worker pools

Messages ride the `analytics` queue on both platforms. On Maho, `config.xml`
routes that queue to the catch-all `slow` pool, so a GA4 upload — one outbound
HTTP call that can block on Google — never competes with the resident `fast`
pool that carries order mail. A host can retarget it from its own `config.xml`
or `local.xml`. On OpenMage the same queue name is declared under
`<hirale_queue><routing>` and has to exist in that module's configuration.

### Event reporting rules

- Route events (`page_view`, `purchase`, `begin_checkout`, `view_cart`,
  `view_item`, `view_item_list`, `search`) are reported only from a rendered
  `200` HTML response. A redirect, a JSON endpoint or an error page reports
  nothing — an empty cart bounced back from checkout is not a `begin_checkout`.
- `purchase` is reported once per order. A reloaded success page returns a
  redirect, which the rule above already stops; on Maho a mark on the checkout
  session backs that up. **On OpenMage that mark is not persisted** — the
  platform closes the session before `core_app_run_after` dispatches — so there
  the redirect rule is the only thing preventing a duplicate.
- An observer that fails while building a payload logs and gives up. It never
  interrupts the action it is measuring: a cart save, a login, a credit memo.

### Transport semantics

- The transport is store-view scoped: different stores can post to MP and Data Manager side by side from the same queue consumer.
- Queued events are transport-agnostic; the transport is chosen at consume time, so switching it also applies to messages already in the queue.
- Data Manager rejects GA events older than **72 hours** — messages that aged past the window (e.g. a consumer outage) are dropped with a log entry instead of being retried forever.
- Data Manager item quantities are integers; fractional quantities (partial refunds) are rounded, while the monetary value stays exact.
- Permanent errors fail the queue job immediately and show up in the queue's failure list; transient errors retry with backoff. Permanent means a replay cannot succeed: a Measurement Protocol `4xx` (wrong measurement id or API secret, malformed body), or a Data Manager invalid argument, credential or missing-property-access error. `5xx` and network failures retry.
- **Restart queue workers after credential or transport changes.** Long-running consumers snapshot configuration (and cache the decoded service-account key plus its OAuth client) at boot. After rotating the service-account key — especially if the old key is revoked in Google Cloud — Data Manager messages fail as unrecoverable (`auth rejected`) and land in the failure list until the workers are restarted with the new config.

### Debug

Enable debug mode in the system config (gated by `System > Developer > Developer Client Restrictions`). Both transports log to the same file; Data Manager entries include the Google-assigned `requestId`.

```log
2024-06-10T18:28:24+00:00 DEBUG (7): {"client_id":"2131884568.1715846325","timestamp_micros":1718044092903759,"non_personalized_ads":false,"user_id":"140","events":[{"name":"page_view","params":{"engagement_time_msec":1,"page_location":"https://example.com/customer/account/index/","page_title":"Create New Customer Account"}}]}
2024-06-10T18:28:24+00:00 DEBUG (7): {
  "validationMessages": [ ]
}
```

## Upgrading

**From 3.x** — Maho: nothing to do beyond `composer dump-autoload`; `hirale/queue`
can be removed. OpenMage: add `hirale/queue` to your own `composer.json`, since
this package no longer requires it. **Both platforms:** if any store uses the
Data Manager API transport, add `googleads/data-manager` explicitly — it is no
longer a hard requirement of this package, so an upgrade drops it.

**From 2.x** — no config action needed: the transport defaults to Measurement
Protocol and the existing `measurement_id`/`api_secret` config keeps working
unchanged. The 2.x branch remains the MP-only maintenance line.

## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.
