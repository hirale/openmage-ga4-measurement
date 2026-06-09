# Plan: Make GA4 Queue Processing Store-Aware

## Summary
Harden the existing design rather than refactor it broadly. Keep the current observer -> queue -> API flow, but make queue jobs carry the originating `store_id`, and make config reads explicit and store-scoped inside the worker.

## Key Changes
- Add internal queue metadata for the originating store.
- Include `_store_id` alongside `_debug_mode` when enqueuing a task.
- Do not send `_store_id` to GA4; strip it in the API handler before building the request.
- Resolve store scope at enqueue time.
- For cart and wishlist events, use the quote/item store when available.
- For purchase events, use the order store.
- For route-based/page events, use the current frontend store.
- Keep one store id per queued payload; do not mix events from multiple stores in one job.
- Make helper config APIs explicitly store-aware.
- Update helper methods to accept optional `$storeId = null` for `isMeasurementEnabled`, `getMeasurementId`, `getApiSecret`, and `getLogFile`.
- Call `Mage::getStoreConfig($path, $storeId)` instead of relying on ambient store state.
- Keep current call sites working by defaulting `$storeId` to `null`.
- Fix helper caching so it remains correct with store scope.
- Replace single cached scalars with per-store caches keyed by store id, or remove caching for these config reads.
- Do not reuse one cached measurement id / secret across different stores in the same PHP process.
- Update the API worker to use the queued store context.
- Read `_store_id` from `$data['data']`.
- Resolve measurement id, API secret, and log file for that store id.
- Keep `_debug_mode` as an observer-time decision; do not recompute debug eligibility in the worker.

## Public / Internal Interface Changes
- Internal queue payload gains `_store_id: int`.
- Helper method signatures gain an optional store parameter:
- `isMeasurementEnabled($storeId = null)`
- `getMeasurementId($storeId = null)`
- `getApiSecret($storeId = null)`
- `getLogFile($storeId = null)`

## Test Plan
- Single-store regression: add to cart, remove from cart, wishlist, login, signup, product view, category view, search, checkout, and purchase still enqueue and send successfully.
- Multi-store correctness: configure distinct measurement ids/secrets per store and verify events from Store A and Store B send with the right credentials.
- Queue-worker regression: process jobs for different stores sequentially in the same worker process and confirm helper caching does not leak config across jobs.
- Debug logging regression: confirm `_debug_mode` still controls logging behavior and that the log file path resolves for the originating store.

## Assumptions
- `store_id` is sufficient for config lookup because this module's settings are store-scoped in `system.xml`.
- The queue payload may safely include internal metadata keys prefixed with `_`.
- This change is intentionally narrow: no event taxonomy changes, no transport retry redesign, and no observer-class refactor in this pass.
