# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
- **`session_id` key mismatch on per-entity sync events.** Per-entity
  events emitted by `bin/console emporiqa:sync:products` /
  `:sync:pages` were using the legacy `sync_session_id` key while the
  enveloping `sync.start` / `sync.complete` used `session_id`. The
  Django webhook schema only accepts `session_id`, so per-entity events
  were silently rejected and never attached to the session. Sync now
  works as documented.
- **CSRF bypass for anonymous cart operations.** When
  `security.csrf.token_manager` was not wired (stripped-down installs,
  mis-wired DI, partial test fixtures), `CartController::validateCsrf()`
  returned no error and let the request through. Now fails closed with
  `403 CSRF protection unavailable`. Any cart-write must carry a valid
  `X-CSRF-Token` issued by the host store.

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
