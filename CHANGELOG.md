# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [v1.11.0] - 2026-10-06

### In short
- Emporiqa's ready-made **Order status** rule now works with Sylius and is
  how the chat answers "where is my order?". The older order tracking
  endpoint is removed.
- A full-page cache can no longer hand one shopper's identity to another in
  the chat, and the shopper's identity no longer appears in the widget URL.
- Price changes from catalog promotions reach Emporiqa without a full sync.
- Prices are synced in every currency your channel shows, and a signed-in
  shopper's prices can be asked for (`actions/customer-prices`).
- Sylius 1.x shops send `order.completed` again (it never fired on Winzou).
- Prices in currencies like JPY or KWD are synced at their real value.
- Sylius 2.3 on Symfony 8 is supported. Sylius 1.12 to 2.2 keep working.
- A product attribute that is a number, a percent, a checkbox, a date or a
  select no longer stops its whole batch of products from syncing.

### Added
- Ready-made rules: two signed endpoints under the actions base URL
  `https://<shop>/emporiqa/api/`:
  - `actions/order-status`: a read-only order lookup. Requests are signed
    with per-purpose HKDF keys (scheme 2), and every answer is signed over
    the exact bytes sent. While you change your secret, a request signed
    with either the new or the old secret is accepted.
  - Requests more than 5 minutes off are refused with 401. A `request_id`
    gets the same answer for 10 minutes without a second lookup.
  - Lookups are limited to 10 per order number and 10 per email per 10
    minutes, and 300 per store. Over a limit the answer is a signed 429
    `rate_limited`, with `data.scope` (`value` or `store`) and a
    `Retry-After` header. If a counter cannot be stored, the limit counts
    as hit.
  - An unknown order and a wrong email get the same answer, and missing or
    malformed fields are refused before the lookup.
  - A signed-in shopper is matched by Sylius customer id. A shopper who is
    signed in can still look up a guest order by giving its email.
  - Tracking numbers come from the order's shipments, with the shipping
    method's name.
  - A found order also answers its full details, so the chat can tell a
    shopper what they ordered: the order number, the customer's name, the
    currency, the lines (product name with its options or variant name, the
    variant code as SKU, quantity, unit price and line price, up to 50),
    the totals (subtotal, shipping, tax, discount, total), the payment
    method and payment state, the shipping method and its delivery time
    (Sylius 2.2 and later), and the shipping and billing addresses. Names
    and states are in the order's language, as the shop's order page shows
    them. Line prices are before promotions; every promotion (unit, order
    and shipping) is in the discount, so the lines add up to the subtotal.
    Nothing is sent before the order is proved (number and email, or the
    signed-in customer's own order), and no email, customer id or other
    internal id is sent back. A detail that cannot be read is left out
    and logged; the rest of the answer still goes.
  - The new `emporiqa.order_status` event (`OrderStatusEvent`) runs after
    all of that, so a listener can change any of it (for example add a
    carrier link) and put its own fields under `extra`.
  - `actions/verify` answers Emporiqa's signed endpoint challenge, which
    proves this shop holds the store's secret.
  - `actions/customer-prices`: what one signed-in customer pays for up to
    20 synced products (`product-…` / `variation-…`) in a channel and
    currency, signed, deduplicated and limited like Order status (30 calls
    per customer and 600 per store per 10 minutes). Sylius core prices by
    channel only, so the answer is the channel price with its catalog
    promotions, computed by Sylius's own price calculator while the customer
    is signed in for the calculation (an extension that prices by customer
    is included); the request's identity is always put back. Products the
    customer cannot buy in that channel are left out, an unknown customer or
    one without an enabled account is `not_found`, and no customer data is
    returned. Cart promotions (including customer-group ones) are not catalog
    prices and are not included.
- `bin/console emporiqa:test-connection` shows a Ready-made rules section
  once Emporiqa offers rules. It shows whether Order status is On or Not
  added, and the Order status address to paste when Emporiqa asks for it.
- Test connection warns when this server's clock is more than 2 minutes off
  Emporiqa's. Emporiqa refuses signatures more than 5 minutes off.
- Sync webhooks carry the scheme-2 `X-Emporiqa-Webhook-Signature` beside the
  old `X-Webhook-Signature`, plus `X-Emporiqa-Plugin-Version: sylius/<version>`
  (`EmporiqaPlugin::VERSION`). Both are signed again on every retry.
- Product prices are also sent in each other currency of the channel that
  has an exchange rate, converted as the storefront shows them. A currency
  without a rate is left out (Sylius would show the base amount under its
  symbol).
- When the channel has a default tax zone, prices carry `price_incl_tax` and
  `price_excl_tax` from the tax rate Sylius resolves for the variant there.
- A channel price change re-sends its product, so a catalog promotion that
  starts, ends or is edited reaches Emporiqa. Sylius applies those from a
  Messenger handler with no resource event, so until now the price only
  changed at the next full sync.

- Sylius 2.3 (PHP 8.3+, Symfony 8 or 7.4, API Platform 5) is supported:
  `symfony/http-client` and `symfony/security-bundle` now allow `^8.0`
  beside `^6.4 || ^7.0`. Checked on Sylius 2.3.0 with Symfony 8.1 and PHP
  8.4, Sylius 2.2 with Symfony 7.4 and PHP 8.3, and Sylius 1.12 with Symfony
  6.4 and PHP 8.1.

### Changed
- A variation no longer sends `descriptions`, `categories`, `brands`,
  `variation_attributes` or `is_parent`: Emporiqa takes them from the
  parent product, so nothing changes in the chat. A product's description
  and categories are built once, not once more per variation.
- Order status and customer prices: a call over the limit of its own order
  number, email or customer is refused without counting against the store's
  limit, so one shopper cannot use up everyone's lookups. A remembered
  answer is only replayed on the endpoint that gave it.
- When Emporiqa refuses to start a sync session (for example while an
  interrupted sync's session is still open, for up to 10 minutes), the sync
  says why. It still sends everything and deletes nothing in Emporiqa.
- **The customer token is no longer written into the page or the widget URL.**
  - A signed-in shopper's token now comes from an uncached POST endpoint
    (`/emporiqa/api/customer-token`, `no-store, private`) when the chat opens.
  - It names the Sylius customer id instead of the login email, and carries
    `aud`.
  - `public/js/emporiqa-customer-token.js` hands it to the widget on a
    `MessageChannel` port, so other scripts on the page never see it. A
    request without a port, from an older embed.js, is still answered on
    the window.
  - A guest is answered without any request, and a page view that never
    opens the chat makes none either.
  - A signed-in shopper's token is reused for at most five minutes, and a
    failed answer is never reused.
  - `UserTokenGenerator` is deprecated and unused.
- Product payloads are built once per product, after the response, however
  many of its variants were saved in the request. Variant saves now go
  through `PostFormatEvent` like product saves.
- Webhooks are sent in this order and batching:
  - `order.completed` goes first and is never skipped.
  - If Emporiqa refuses one, it is kept for 30 days and sent again when the
    order's payment reaches paid.
  - The rest go in batches of at most 50 events, with a product's parent and
    variations kept together.
  - After one failed batch, the rest of the request's product and page
    webhooks are dropped instead of waiting on each one.
    `emporiqa:sync:all` catches up.
- The plugin's JS URLs carry the plugin version (`?v=1.11.0`), so browsers
  load the new files after an update.
- A locale left out of `enabled_languages` is neither synced nor offered:
  its pages show no chat (`emporiqa_widget()` and `emporiqa_cart_widget()`
  render nothing there).

### Removed
- Removed: the older order tracking endpoint; use the ready-made Order
  status rule. `POST /emporiqa/api/order/tracking` now answers 404. Removed
  with it: its route and
  controller, `OrderProviderInterface` and `OrderProvider`,
  `OrderTrackingEvent` (`emporiqa.order_tracking`; listen to
  `OrderStatusEvent` instead), the `emporiqa.order_tracking.enabled`
  parameter, and the test-connection note about switching it off. The
  `emporiqa.order_tracking` config key is still accepted, ignored, so a
  1.10.x configuration loads.

### Fixed
- **Product attributes are sent as text, as the shop shows them.** Emporiqa
  takes attribute values as strings only, so one integer, percent, checkbox,
  date or select attribute refused the whole batch of up to 50 products
  (Sylius's own sample data has a percent attribute). A percent is sent as
  "10%", a checkbox as yes or no, a date as YYYY-MM-DD, a select as its
  choice labels in the payload's language (it sent the internal choice keys),
  and a translatable attribute in the payload's own language, falling back to
  the channel's default language as the shop does (each language used to get
  whichever translation came last). The brand and condition attributes are
  read the same way.
- **`order.completed` is sent on Sylius 1.x.** The subscriber listened for
  `winzou.state_machine.sylius_order_checkout.post_transition.complete`, a name
  Winzou never dispatches: it dispatches one generic
  `winzou.state_machine.post_transition` with the order on the state machine.
  It now listens to that and filters by graph and transition.
- **Prices in zero- and three-decimal currencies are correct.** Sylius
  stores every amount in hundredths whatever the currency, but the plugin
  divided by the currency's own minor unit. A 1,000 JPY product went out as
  100,000 JPY, and KWD, BHD or OMR prices came out ten times too small.
  Product prices, the cart API and `order.completed` totals
  were all affected. Amounts are now always divided by 100, then rounded as
  the shop shows them.
- **A configurable product's price is the one its page shows.** The parent
  carried the first variant's price even when that variant was disabled; it
  now carries the first enabled variant's, as Sylius's product page does.
- Price entries no longer carry `minimum_price`. It is Sylius's floor for
  catalog promotions, a margin setting rather than a price a shopper pays,
  and Emporiqa does not read it.
- Order status rate limits count one order number once however it is
  typed: `42`, `#42` and `000000042` share a limit.
- A failed lookup is logged with the error's class only, never its message,
  which for a database error can quote the shopper's email or order number.
- The customer token URL comes from the router, so a shop installed under a
  sub-path asks the right address.
- A malformed action request without a `request_id` gets an unsigned 400:
  the response signature covers the `request_id`, so there is nothing to
  sign it over.

### Upgrade notes
- Run `bin/console assets:install` and clear the cache. **If you use a
  full-page cache or a CDN that caches HTML, purge it**: cached pages still
  carry the old widget URL with the shopper's token.
- To use the Order status rule, `framework.router.default_uri` must be your
  shop's https address. Run `bin/console emporiqa:test-connection`: it
  prints the Order status address. Add the Order status rule in Emporiqa
  (Settings > Rules); it asks for this address: paste it there, then click
  the link Emporiqa emails you to confirm it.
- The older order tracking endpoint (`/emporiqa/api/order/tracking`) is
  gone and answers 404. If your Emporiqa dashboard still has an Order
  tracking API URL (Settings > Integration > For your developer), clear it
  and add the Order status rule instead. A leftover
  `emporiqa.order_tracking` setting is ignored (Symfony logs a deprecation);
  remove it from `config/packages/emporiqa.yaml`.
- The first sync after the update re-sends every variation without the
  fields above; Emporiqa does not embed them again (measured: 1 embedding
  call for a 126-product, 210-variation shop), and later unchanged syncs
  make none.
- **Downgrading to 1.10.x needs a new secret.** Once 1.11.0 has synced,
  Emporiqa refuses the old signature for this store. After a rollback,
  regenerate the secret in Emporiqa and update `EMPORIQA_WEBHOOK_SECRET`.

### Known issues (deferred)
- `On` / `Not added` is as fresh as the last `emporiqa:test-connection` run.
- Sylius marks its pages private, so a cache in front of the shop does not
  store them. A cache configured to store shop pages for everyone can serve
  a signed-in shopper a guest's copy: the chat then treats them as a guest
  (it asks for the order email) but never shows anyone else's identity.
- The rate-limit counters live in the app cache pool (`cache.app`), which
  must be shared by every web server: the filesystem default is shared on
  one host, Redis or Memcached across hosts; APCu or an array pool would
  count per worker. An increment is a read then a write, so two lookups in
  the same instant can count once, and `cache:clear` resets the counters.
- A dated catalog promotion starts and ends from Sylius's Messenger worker
  (`messenger:consume main`); without one running, neither the shop nor
  Emporiqa changes price on that date.
- Editing an exchange rate does not re-send products; run
  `emporiqa:sync:products` afterwards to update the converted prices.

## [v1.10.1] - 2026-09-02

### Fixed
- **Composer no longer permits a Symfony version this bundle does not support.**
  `symfony/security-bundle` was constrained to `^6.2 || ^7.0`, which allowed
  Symfony 6.2 and 6.3 even though the README states, correctly, that 6.0 to 6.3
  are not supported. `symfony/http-client` was already `^6.4`. The constraint is
  now `^6.4 || ^7.0`, so a resolver cannot install a combination the project
  documents as unsupported.

## [v1.10.0] - 2026-07-09

### Security
- **Full syncs can no longer delete valid remote items after a partial
  failure.** `sync.complete` marks items unseen in the session as deleted on
  the Emporiqa side, but it was sent regardless of batch outcomes, so one
  failed batch (a transient 5xx, a rate-limit burst, or a 400 on a malformed
  product) silently wiped the remaining, still-existing items from the
  Emporiqa index. `emporiqa:sync:products` and `emporiqa:sync:pages` now skip
  session completion when any batch errored (or when nothing was synced) and
  warn the merchant to re-run the sync after resolving the errors. Matches
  the behavior of the other Emporiqa integrations.
- **Order tracking now always requires email verification.** The order
  lookup only checked the customer email when Emporiqa supplied one, and
  Sylius order numbers are sequential, so a chat user could probe other
  customers' orders by number alone. An order is now only returned when the
  request carries a verification email matching the order's customer.

### Fixed
- **HTTP 429 (rate-limit) responses are retried instead of dropped.** The
  webhook sender treated 429 like any other client error and gave up
  immediately, so rate-limit bursts during a large sync silently dropped
  whole batches. 429 is now retried with the server's `Retry-After` delay
  when present (capped at 10s), falling back to the standard backoff.
- **`order.completed` webhooks no longer overwrite each other.** The
  request-scoped event queue deduplicated on `identification_number`, which
  order events lack, so all order events in one request collapsed onto a
  single key and only the last survived. Order events are now keyed by
  order id, and events with no identifier at all are never collapsed.

## [v1.9.0] - 2026-06-29

### Added
- **Per-variant image resolution.** Variant events now emit the images linked
  to that specific variant in Sylius rather than always repeating the full
  product gallery. When a variant has its own linked images, only those are
  emitted; when it has none, it falls back to the full product gallery so a
  variant is never left without an image. The parent row continues to carry the
  full product gallery. This lets the Emporiqa assistant show the correct photo
  for the exact variant a shopper is looking at (e.g. the red shirt, not the
  blue one).

### Fixed
- **Images with an empty path no longer produce empty-string URLs.** The
  product gallery now filters out images whose path is empty instead of
  emitting a blank URL. An empty linked path on a variant is likewise filtered,
  so the variant correctly falls back to the product gallery rather than
  reporting a single empty image.

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
    state. Distinct from stock, because out-of-stock is still expressed via
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
  product's enabled flag, so the parent's `availability_statuses` read
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
  the parent showing as available until the next full product save. The
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
  `WorkerMessageFailedEvent` (discard pending events, because the handler's Doctrine
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

Earlier versions (v1.0.0 through v1.6.2): see git tags for release
history.
