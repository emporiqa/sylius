# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [v1.8.0] - 2026-06-05

### Added
- **Four new product-contract fields on product and variant events**, aligning
  the Sylius payload with the WooCommerce integration:
  - `max_order_quantities`: per-channel dict of the maximum order quantity, or
    `null` for no limit. Sylius has no native max-per-order, so the cap is read
    from the product-level `max_order_quantity_attribute` attribute (default
    code `max_order_qty`). Because the source is product-level, the same value
    is reported under every channel key; there is no per-channel override event
    (unlike `min_order_quantities`). A non-positive value (0 or negative) is
    treated as `null` (no limit) rather than emitted verbatim, so a
    misconfigured attribute can never make a product un-orderable.
  - `available_for_order`: boolean derived from the product's `isEnabled()`
    state. Distinct from stock — out-of-stock is still expressed via
    `stock_quantities` / `availability_statuses`.
  - `condition`: string (`new` / `used` / `refurbished`) or `null`, read from
    the `condition_attribute` attribute (default code `condition`).
  - `is_virtual`: boolean read from the `virtual_attribute` attribute (default
    code `virtual`); `false` when absent.
- New configuration options `max_order_quantity_attribute`,
  `condition_attribute`, and `virtual_attribute`, each with sensible defaults
  so existing installs need no config change.

### Fixed
- **A configurable product whose variants are all out of stock was reported
  as available on the parent.** `formatParentProduct` called
  `getAvailabilityStatus($product)` with no variant, which only checks the
  product's enabled flag — so the parent's `availability_statuses` read
  `available` regardless of variant stock. The Emporiqa backend trusts the
  parent point's stored availability when filtering search results, so
  sold-out configurable products were surfaced and recommended, then failed
  at add-to-cart. The parent now aggregates across variants (available when
  at least one variant is available, otherwise out of stock), matching the
  WooCommerce, Drupal, Magento, and PrestaShop formatters. Sylius has no
  backorder state, so the aggregation stays binary.
- **Variant stock changes left the parent's stored availability stale.** The
  lightweight `product.availability` event (emitted on an inventory-only
  variant change, e.g. an order-driven stock decrement) only carried the
  `variation-{id}` point. The Emporiqa backend updates exactly the
  identification_number it receives and never re-derives the parent, so a
  sellout of the last in-stock variant would update that variation but leave
  the parent showing as available until the next full product save — the
  full-event fix above only corrected the full-sync path. `VariantStockFormatter`
  now also emits a `product-{id}` parent availability event (with the
  re-aggregated status and `null` stock, mirroring the full parent payload)
  for multi-variant products, so order-driven sellouts keep the parent
  correct in real time. `VariantStockFormatterInterface::format()` now returns
  a list of events instead of a single nullable event.

### Migration notes
- `VariantStockFormatterInterface::format()` changed signature from
  `format(ProductVariantInterface): ?array` to
  `format(ProductVariantInterface): array` (a list of event arrays, empty when
  nothing to send). Custom implementations or decorators of this interface must
  return a list; callers consuming a single event must read `$events[0]`.

## [v1.7.0] - 2026-06-03

### Fixed
- **Availability events queued during async Messenger handling are now
  flushed per message.** `WebhookEventQueue` only flushed on
  `kernel.terminate` / `console.terminate`. When stock-affecting operations
  are processed by a long-running `messenger:consume` worker, neither fires
  per message, so queued `product.availability` events stacked up until the
  worker stopped (or were lost if it was killed). The queue now subscribes
  to `WorkerMessageHandledEvent` (flush after each handled message) and
  `WorkerMessageFailedEvent` (discard pending events — the handler's Doctrine
  transaction is rolled back, so the change never persisted). Hooks are
  registered only when `symfony/messenger` is installed.
- **`product.availability` inventory-only detection now tolerates Gedmo
  audit fields.** Sylius's `ProductVariant` is Timestampable, so every
  update writes `updatedAt` alongside the inventory field. The previous
  `isInventoryOnlyChange()` required the changeset to contain *only*
  `{onHand, onHold, tracked}`, so the production changeset
  `{onHand, updatedAt}` was rejected and the lightweight event never fired
  for real order-driven decrements. Detection is now: inventory-only iff at
  least one inventory field changed AND every other changed field is a
  neutral audit field (`updatedAt`, `createdAt`, `createdBy`, `updatedBy`).
- **No double-emit on admin pure-stock saves.** In `ResourceController`
  the Doctrine flush (`VariantStockDoctrineListener` →
  `product.availability`) runs before the resource `post_update`
  (`ProductEventSubscriber` → full product event), so the queue-time
  `hasPendingFor()` guard could not see the not-yet-queued full event.
  `WebhookEventQueue` now enforces full-event precedence for the same
  `identification_number` regardless of queue order: a full product event
  always supersedes a `product.availability` event, while an order-driven
  decrement (no resource event) still emits the availability event.

## [v1.6.5] - 2026-05-27

### Tests
- **`CartControllerTest` updated for v1.6.3's CSRF fail-closed behavior.**
  All 21 cart write-operation tests (`add`/`update`/`remove`/`clear`)
  were instantiating the controller with `csrfTokenManager = null`,
  which since v1.6.3 returns `403 CSRF protection unavailable` before
  business logic runs. They now share a permissive CSRF mock wired in
  `setUp()`, so each test exercises the behavior it claims to test.
  The dedicated CSRF tests
  (`testCsrfValidationRejectsInvalidToken`,
  `testCsrfValidationEnforcedForAnonymousUser`,
  `testGetCsrfTokenReturnsEmptyWhenNoCsrfManager`) continue to build
  controllers with stricter setups. No production code change.

## [v1.6.4] - 2026-05-27

### Fixed
- **Per-entity sync events lost their session reference.** v1.6.3
  rewrote `AbstractSyncCommand` to set `data.session_id` on every
  `product.*` / `page.*` event, on the (incorrect) assumption that the
  Django schema only accepted `session_id`. In fact the per-entity
  schemas (`ProductEventData`, `PageEventData` in
  `core/schemas/webhooks.py`) deliberately use `sync_session_id`;
  only `sync.start` / `sync.complete` use `session_id`. The wrong
  field name caused per-entity events from `bin/console
  emporiqa:sync:products` / `:sync:pages` to drop their session tag
  silently. Reverted to `sync_session_id` for per-entity events.
  All other Emporiqa integrations (WooCommerce, Drupal, PrestaShop,
  Magento) were already using the right field name. No upgrade
  action needed.

### Documentation
- README: documented the `min_order_quantity_attribute` config node,
  the `MinOrderQuantityEvent` extensibility hook, the v1.6.3 CSRF
  fail-closed behavior, and the v1.6.3 friendly-error console output.
  Added a Troubleshooting entry for the "Cart operations fail with
  403 'CSRF protection unavailable'" case.
- `composer.json`: bumped `branch-alias.dev-main` to `1.7-dev`
  (was three minor versions behind at `1.5-dev`).

## [v1.6.3] - 2026-05-26

### Added
- **Minimum order quantity propagation.** Products and variants now ship
  with a `min_order_quantities: {channel_key: int}` payload so the chat
  respects wholesale, bulk-pack, and pack-of-N constraints when
  recommending or carting items. Reads from a configurable Sylius
  product attribute (default code `min_order_qty`); change via the new
  `min_order_quantity_attribute` config node. Listeners on the new
  `MinOrderQuantityEvent` (`emporiqa.min_order_quantity`) can override
  the computed value. Parent payloads of configurable products use the
  strictest constraint across all variants.
- **Friendly error messages on Sync and Test Connection.** Console
  commands now print the actual reason from Emporiqa ("Invalid
  signature", validation errors, throttle hints) instead of generic
  "Request failed with status N". The new `WebhookSenderInterface`
  methods `getLastError(): ?string` and `buildFriendlyError(array)`
  extract the most informative field (`error`/`detail`/`message`/
  `errors[0]`/`hint`) from the Django response body, matching
  PrestaShop and WooCommerce wording. Repeated batch failures are
  deduplicated and listed in the final command summary.
- **Defensive flush on `ConsoleEvents::TERMINATE`.** `WebhookEventQueue`
  now subscribes to both `KernelEvents::TERMINATE` (HTTP) and
  `ConsoleEvents::TERMINATE` (CLI), so any future console flow that
  queues events through `WebhookEventQueue::queue()` will reliably
  flush at command shutdown instead of silently dropping them.

### Fixed
- **CSRF bypass for anonymous cart operations.** When
  `security.csrf.token_manager` was not wired (stripped-down installs,
  mis-wired DI, partial test fixtures), `CartController::validateCsrf()`
  returned no error and let the request through. Now fails closed with
  `403 CSRF protection unavailable`. Any cart-write must carry a valid
  `X-CSRF-Token` issued by the host store.
- **`order.completed` webhooks fired on cancelled-payment orders.**
  `OrderCompleteSubscriber` now skips orders whose
  `paymentState === 'cancelled'` so a back-office cancellation that
  still reaches the checkout completion transition no longer registers
  as a conversion. Unit price reads are now null-safe, so partial
  order data can't break the subscriber.

### Migration notes
- If you've extended `WebhookSenderInterface` with a custom
  implementation, you must add `getLastError(): ?string` and
  `buildFriendlyError(array $result): string` to your class. The
  default implementation in `WebhookSender` covers all standard cases.
- If you relied on the previous CSRF bypass for an unauthenticated
  testing harness, register a real CSRF token manager service or
  inject a stub in tests.
- The `min_order_quantity_attribute` config defaults to `min_order_qty`.
  Stores that don't define that attribute on their products see a
  default minimum of 1, which is the prior behavior.

## Prior history

Earlier versions (v1.0.0 through v1.6.2) — see git tags for release
history.
