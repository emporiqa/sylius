# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
