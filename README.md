# Emporiqa Sylius Plugin

Integrates [Sylius](https://sylius.com) with the [Emporiqa](https://emporiqa.com?utm_source=gitlab&utm_medium=readme&utm_campaign=sylius_plugin) AI chatbot, an online salesperson that closes sales in your Sylius store. The plugin provides webhook-based synchronization of products and pages, in-chat cart operations with checkout, an embeddable chat widget, order tracking, and order completion webhooks.

A shopper types "organic face cream for sensitive skin, under 30" into your store. Your search returns every cream you sell. The shopper wanted three specific things and got none of them filtered.

The chatbot acts like an online salesperson. Shoppers describe what they need (or upload a photo of something they like), it finds matching products from your catalog, handles objections, answers questions from your own pages, compares items, and walks them to cart and checkout.

[![Emporiqa chat widget recommending wireless headphones from the store's catalog, with a product card showing price, stock, and an add-to-cart button](docs/images/product-search.jpg)](https://demo.emporiqa.com)

Try it yourself on the [live demo store](https://demo.emporiqa.com).

**What it does:**

- Closes sales: handles objections like "too expensive" by suggesting alternatives from your catalog, instead of giving up
- Searches your product catalog by what shoppers mean, not just keywords
- Visual search: a shopper uploads a photo (something they saw on social, a style they like), the chatbot describes it and finds matching products in your catalog
- Answers questions about shipping, returns, and payment from your store pages
- Compares products side by side
- Adds to cart and sends shoppers to checkout
- Tracks which chats led to purchases (full conversion funnel with chat-attributed revenue)
- Starts conversations automatically based on shopper behavior (time on page, pages visited, checkout page)
- Rates shopper satisfaction after each conversation (thumbs up/down with aggregate scores)
- Hands off to your team when it can't help
- Works in 65+ languages
- Unlimited team members on every plan, no per-seat fees

## Features

- **Product Sync**: Real-time synchronization of Sylius products and variants via webhooks
- **Page Sync**: Synchronization of any translatable page entity (policies, FAQ, blog posts, etc.)
- **Multi-Channel**: Consolidated events with per-channel pricing, availability, and content across all languages
- **Cart & Checkout**: REST API for in-chat cart operations (add, update, remove, clear, view, checkout URL) with event hooks
- **Order Tracking**: API endpoint for order lookup with HMAC signature and replay protection
- **Order Completion**: Webhook notification when checkout completes (supports both Sylius 1.x and 2.x)
- **Chat Widget**: Cache-safe embeddable chat widget with inline signed user tokens and currency/channel awareness
- **Visual Search**: Shoppers upload a photo in the widget; the chatbot matches it against your synced Sylius catalog (no extra config required)
- **Brand-Safe Answers**: Every reply comes from your synced products and pages, never from training data. Low-confidence questions hand off to your team
- **Multi-language**: Syncs content in all configured Sylius locales with currency switcher support
- **Console Commands**: Memory-efficient sync commands with batching, dry-run, and session management
- **Webhook Retry**: Automatic retry with exponential backoff for transient failures
- **Fully Extensible**: Decorate any service interface, listen to events (`PostFormatEvent`, `CartOperationEvent`, `PreSyncEvent`, etc.)

Emporiqa also works with Drupal Commerce, WooCommerce, Magento, PrestaShop, Shopware, and any store via webhook API. Same platform, same dashboard, same salesperson.

## Requirements

- PHP 8.1+
- Sylius 1.12+ or 2.0+
- Symfony 6.4+ or 7.x
- An Emporiqa account ([sign up](https://emporiqa.com?utm_source=gitlab&utm_medium=readme&utm_campaign=sylius_plugin))

## Installation

```bash
composer require emporiqa/sylius-plugin
```

### Register the Plugin

Add to `config/bundles.php`:

```php
return [
    // ... other bundles
    Emporiqa\SyliusPlugin\EmporiqaPlugin::class => ['all' => true],
];
```

### Import Routes

Create `config/routes/emporiqa.yaml`:

```yaml
emporiqa:
    resource: '@EmporiqaPlugin/config/routes.yaml'
```

This registers the order tracking endpoint, cart API endpoints, and user token endpoint. If you don't need some features, you can disable them individually in configuration.

### Create Configuration

Create `config/packages/emporiqa.yaml`:

```yaml
emporiqa:
    webhook_secret: '%env(EMPORIQA_WEBHOOK_SECRET)%'
```

All other settings have sensible defaults. See [Configuration Reference](#configuration-reference) for the full list.

### Environment Variables

Add to your `.env` file:

```env
EMPORIQA_STORE_ID=your_store_id
EMPORIQA_WEBHOOK_URL=https://emporiqa.com/webhooks/sync/
EMPORIQA_WEBHOOK_SECRET=your_secret_key
```

### Add the Chat Widget

In your shop layout template (e.g. `templates/bundles/SyliusShopBundle/Layout/base.html.twig`), add before `</body>`:

```twig
{# With cart support (recommended) #}
{{ emporiqa_cart_widget() }}

{# Or simple inline embed without cart #}
{{ emporiqa_widget() }}
```

### Install Bundle Assets

```bash
bin/console assets:install
```

This copies the plugin's JavaScript files (`emporiqa-cart.js`) to `public/bundles/emporiqa/js/`.

### Clear Cache

```bash
bin/console cache:clear
```

## Configuration Reference


| Setting                  | Type     | Default              | Description                                                                  |
| ------------------------ | -------- | -------------------- | ---------------------------------------------------------------------------- |
| `store_id`               | string   | **required**         | Emporiqa store identifier (e.g. `'%env(EMPORIQA_STORE_ID)%'`)                |
| `webhook_url`            | string   | **required**         | Emporiqa webhook endpoint (e.g. `'%env(EMPORIQA_WEBHOOK_URL)%'`)             |
| `webhook_secret`         | string   | **required**         | Connection secret from your Emporiqa dashboard (HMAC-SHA256 signing key)     |
| `base_url`               | string   | `''`                 | Base URL for image paths in CLI context (e.g. `https://myshop.com`)          |
| `media_base_path`        | string   | `'/media/image/'`    | Base path for product images. Customize for CDN or non-default media storage |
| `brand_attribute_code`   | string   | `'brand'`            | Product attribute code used for brand/manufacturer data                      |
| `min_order_quantity_attribute` | string | `'min_order_qty'` | Product attribute code holding the minimum order quantity. Stores without this attribute defined see a default minimum of 1. Listeners on `MinOrderQuantityEvent` may override the value. |
| `enabled_languages`      | string[] | `['en_US', 'de_DE']` | Sylius locale codes to sync                                                  |
| `sync.products`          | bool     | `true`               | Enable automatic product synchronization                                     |
| `sync.pages`             | bool     | `true`               | Enable automatic page synchronization                                        |
| `page_entity_classes`    | string[] | `[]`                 | FQCNs of page entities implementing `PageInterface`                          |
| `order_tracking.enabled` | bool     | `true`               | Enable the order tracking API endpoint                                       |
| `cart.enabled`           | bool     | `true`               | Enable cart API endpoints and order completion webhook                       |


### Full Configuration Example

```yaml
emporiqa:
    store_id: '%env(EMPORIQA_STORE_ID)%'
    webhook_url: '%env(EMPORIQA_WEBHOOK_URL)%'
    webhook_secret: '%env(EMPORIQA_WEBHOOK_SECRET)%'
    base_url: 'https://myshop.com'       # optional, for CLI image URLs
    media_base_path: '/media/image/'     # optional, customize for CDN/S3/LiipImagine
    brand_attribute_code: 'brand'        # optional, product attribute for brand
    min_order_quantity_attribute: 'min_order_qty'  # optional, attribute holding the min order quantity
    enabled_languages: ['en_US', 'de_DE']
    sync:
        products: true
        pages: true
    page_entity_classes:
        - App\Entity\StaticPage
        - App\Entity\BlogPost
    order_tracking:
        enabled: true
    cart:
        enabled: true
```

### Language Configuration

The `enabled_languages` setting must match your Sylius locale codes exactly. Locale codes are passed as-is to Emporiqa (e.g., `en_US`, `de_DE`). No truncation is applied.

```yaml
emporiqa:
    enabled_languages: ['en_US', 'de_DE']    # Syncs en_US and de_DE content
```

### Channels

The plugin uses the Sylius channel code directly as the Emporiqa channel identifier. No mapping configuration is needed. The channel code from Sylius is passed as-is in webhook payloads and the chat widget.

## Webhook Events

The plugin sends webhook events as signed POST requests to the Emporiqa endpoint. Each request contains a batch of events:

```json
{
  "events": [
    {"type": "product.updated", "data": {...}},
    {"type": "product.updated", "data": {...}}
  ]
}
```

All requests include an `X-Webhook-Signature` header containing an HMAC-SHA256 signature of the request body.

### Event Types


| Event             | Trigger                            | Description                                |
| ----------------- | ---------------------------------- | ------------------------------------------ |
| `product.created` | Product created in Sylius admin    | Sends consolidated product + variant data  |
| `product.updated` | Product or variant updated         | Sends consolidated product + variant data  |
| `product.deleted` | Product or variant deleted         | Sends `identification_number` only         |
| `page.created`    | Page entity persisted via Doctrine | Sends consolidated page data               |
| `page.updated`    | Page entity updated via Doctrine   | Sends consolidated page data               |
| `page.deleted`    | Page entity removed via Doctrine   | Sends `identification_number` only         |
| `sync.start`      | CLI sync command begins            | Includes `session_id` and `entity`         |
| `sync.complete`   | CLI sync command finishes          | Items not in session can be marked deleted |
| `order.completed` | Checkout workflow completes        | Order total, items, currency, session ID   |


### Sylius Events Listened To

Products use Sylius resource events:


| Sylius Event                         | Plugin Handler                  |
| ------------------------------------ | ------------------------------- |
| `sylius.product.post_create`         | Sends `product.created`         |
| `sylius.product.post_update`         | Sends `product.updated`         |
| `sylius.product.pre_delete`          | Sends `product.deleted`         |
| `sylius.product_variant.post_create` | Sends parent product update     |
| `sylius.product_variant.post_update` | Sends parent product update     |
| `sylius.product_variant.pre_delete`  | Sends variant `product.deleted` |


Pages use Doctrine lifecycle events (`postPersist`, `postUpdate`, `preRemove`) and only fire for entities that match the configured `page_entity_classes` and implement `PageInterface`.

### Product Data Structure

Each product or variant is sent as a single consolidated event containing all channels and languages:

```json
{
  "type": "product.updated",
  "data": {
    "identification_number": "product-123",
    "sku": "PROD-123",
    "channels": ["", "b2b"],
    "names": {
      "": {"en_US": "Product Name", "de_DE": "Produktname"},
      "b2b": {"en_US": "Product Name"}
    },
    "descriptions": {
      "": {"en_US": "Description...", "de_DE": "Beschreibung..."}
    },
    "links": {
      "": {"en_US": "https://store.com/en_US/products/product-name", "de_DE": "https://store.com/de_DE/products/produktname"}
    },
    "categories": {
      "": {"en_US": ["Electronics"], "de_DE": ["Elektronik"]},
      "b2b": {"en_US": ["Electronics"]}
    },
    "brands": {
      "": "Brand Name",
      "b2b": "Brand Name"
    },
    "prices": {
      "": [{"currency": "EUR", "current_price": 79.99, "regular_price": 99.99}],
      "b2b": [{"currency": "USD", "current_price": 69.99, "regular_price": 89.99}]
    },
    "availability_statuses": {
      "": "available",
      "b2b": "available"
    },
    "stock_quantities": {
      "": 25,
      "b2b": 25
    },
    "min_order_quantities": {
      "": 1,
      "b2b": 6
    },
    "images": {
      "": ["https://store.com/media/image/product.jpg"]
    },
    "attributes": {
      "": {"en_US": {"Color": "Blue"}, "de_DE": {"Farbe": "Blau"}}
    },
    "parent_sku": null,
    "is_parent": false,
    "variation_attributes": {}
  }
}
```

For variable products, the parent is synced with `is_parent: true` and `variation_attributes` containing the translated option names (e.g., `{"": {"en_US": ["Color", "Size"], "de_DE": ["Farbe", "Größe"]}}`). Each variant is synced separately with `parent_sku` referencing the parent and `variation_attributes: {}` (empty object).

### Delete Events

Delete events are simplified. They contain only the `identification_number`:

```json
{
  "type": "product.deleted",
  "data": {
    "identification_number": "product-123"
  }
}
```

### Page Data Structure

Pages are sent to all channels (since Sylius pages don't have channel associations):

```json
{
  "type": "page.updated",
  "data": {
    "identification_number": "page-45",
    "channels": ["", "b2b"],
    "titles": {
      "": {"en_US": "Shipping Policy", "de_DE": "Versandrichtlinie"},
      "b2b": {"en_US": "Shipping Policy", "de_DE": "Versandrichtlinie"}
    },
    "contents": {
      "": {"en_US": "Page content...", "de_DE": "Seiteninhalt..."},
      "b2b": {"en_US": "Page content...", "de_DE": "Seiteninhalt..."}
    },
    "links": {
      "": {"en_US": "https://store.com/en_US/pages/shipping-policy", "de_DE": "https://store.com/de_DE/pages/versandrichtlinie"},
      "b2b": {"en_US": "https://store.com/en_US/pages/shipping-policy", "de_DE": "https://store.com/de_DE/pages/versandrichtlinie"}
    }
  }
}
```

## Page Sync

Page sync is optional and supports any number of entity classes. To enable it, your page entities must implement `Emporiqa\SyliusPlugin\Model\PageInterface`:

```php
namespace Emporiqa\SyliusPlugin\Model;

use Doctrine\Common\Collections\Collection;

interface PageInterface
{
    public function getId(): ?int;

    /** @return Collection<int, object> */
    public function getTranslations(): Collection;
}
```

Each translation object should have `getTitle()`, `getContent()`, `getSlug()`, and `getLocale()` methods.

### Setting Up Page Sync

1. Implement `PageInterface` on your entity:

```php
use Emporiqa\SyliusPlugin\Model\PageInterface;

class StaticPage implements TranslatableInterface, PageInterface
{
    // ... your existing entity code
}
```

1. Register the entity class in configuration:

```yaml
emporiqa:
    page_entity_classes:
        - App\Entity\StaticPage
```

You can register multiple entity classes if your project has different types of pages (e.g. static pages, blog posts, FAQ entries).

### Page URL Resolution

The plugin uses a `PageUrlResolverInterface` service to generate page URLs in webhook payloads. The default implementation returns an empty string. To provide real URLs, create your own resolver and decorate the plugin's service:

```php
namespace App\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Service\PageUrlResolverInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PageUrlResolver implements PageUrlResolverInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function resolveUrl(PageInterface $page, string $locale): string
    {
        $translations = $page->getTranslations();

        foreach ($translations as $translation) {
            if ($translation->getLocale() === $locale) {
                $slug = $translation->getSlug();
                if ($slug) {
                    return $this->urlGenerator->generate(
                        'app_static_page_show',
                        ['slug' => $slug, '_locale' => $locale],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    );
                }
            }
        }

        return '';
    }
}
```

Register it as a decorator in `config/services.yaml`:

```yaml
services:
    App\Service\PageUrlResolver:
        decorates: Emporiqa\SyliusPlugin\Service\PageUrlResolverInterface
```

### Disabling Page Sync

If you don't need page sync, leave `page_entity_classes` empty (the default). When empty, the plugin will not register the `PageFormatter`, `PageDoctrineListener`, or `PageUrlResolver` services at all.

## Order Tracking

The plugin exposes an API endpoint that Emporiqa calls during chat conversations when a customer asks about their order. The request is authenticated via HMAC-SHA256 signature with replay protection (requests older than 300 seconds are rejected).

### Endpoint

`POST /emporiqa/api/order/tracking`

### Request Format

Emporiqa sends a signed JSON body:

```json
{
  "order_identifier": "000001234",
  "timestamp": 1706780000,
  "user_id": "customer-42",
  "verification_fields": {
    "email": "john@example.com"
  }
}
```


| Field                 | Type    | Required | Description                           |
| --------------------- | ------- | -------- | ------------------------------------- |
| `order_identifier`    | string  | yes      | Order number provided by the customer |
| `timestamp`           | integer | yes      | Unix timestamp of the request         |
| `user_id`             | string  | no       | Customer's user ID (if identified)    |
| `verification_fields` | object  | no       | Additional verification (e.g. email)  |


The `X-Emporiqa-Signature` header contains the HMAC-SHA256 signature of the raw request body, signed with your connection secret (`webhook_secret` config value).

### Response Format

The built-in `OrderProvider` looks up orders by number via Sylius's `OrderRepositoryInterface`, verifies the customer email if provided in `verification_fields`, and returns:

```json
{
  "order_id": "000001234",
  "status": "shipped",
  "placed_at": "2026-02-01T10:30:00+00:00",
  "items": [
    {"name": "Example Product", "quantity": 1, "price": 29.99}
  ],
  "shipping": {
    "method": "DHL Express",
    "tracking_number": "DHL1234567890",
    "state": "shipped"
  },
  "total": 34.99,
  "currency": "EUR"
}
```

Order status is resolved from Sylius payment and shipping states:


| Sylius State      | Returned Status     |
| ----------------- | ------------------- |
| Awaiting payment  | `pending_payment`   |
| Paid, not shipped | `processing`        |
| Partially shipped | `partially_shipped` |
| Shipped           | `shipped`           |
| Refunded          | `refunded`          |
| Cancelled         | `cancelled`         |


### Customizing Order Lookup

Decorate `OrderProviderInterface` to customize the order lookup logic:

```php
namespace App\Service;

use Emporiqa\SyliusPlugin\Service\OrderProviderInterface;

class CustomOrderProvider implements OrderProviderInterface
{
    public function __construct(
        private OrderProviderInterface $inner,
    ) {}

    public function findOrder(string $identifier, ?string $userId, array $verificationFields): ?array
    {
        $order = $this->inner->findOrder($identifier, $userId, $verificationFields);

        if ($order !== null) {
            $order['custom_field'] = 'extra data';
        }

        return $order;
    }
}
```

```yaml
services:
    App\Service\CustomOrderProvider:
        decorates: Emporiqa\SyliusPlugin\Service\OrderProviderInterface
```

### Disabling Order Tracking

```yaml
emporiqa:
    order_tracking:
        enabled: false
```

When disabled, the `OrderTrackingController` service is removed from the container entirely.

### Enabling in Emporiqa

In your Emporiqa dashboard (Store Settings > Integration), set the **Order Tracking API URL** to:

```
https://your-store.com/emporiqa/api/order/tracking
```

## Cart & Checkout

The plugin provides a REST API for in-chat cart operations. The Emporiqa chat widget uses `window.EmporiqaCartHandler` (loaded via `emporiqa-cart.js`) to interact with these endpoints.

### Endpoints


| Method | Path                              | Description           |
| ------ | --------------------------------- | --------------------- |
| `GET`  | `/emporiqa/api/cart`              | View current cart     |
| `POST` | `/emporiqa/api/cart/add`          | Add items to cart     |
| `POST` | `/emporiqa/api/cart/update`       | Update item quantity  |
| `POST` | `/emporiqa/api/cart/remove`       | Remove item from cart |
| `POST` | `/emporiqa/api/cart/clear`        | Clear all items       |
| `GET`  | `/emporiqa/api/cart/checkout-url` | Get checkout URL      |


### Cart Response Format

All cart endpoints return a consistent JSON format:

```json
{
  "success": true,
  "checkoutUrl": "https://store.com/checkout",
  "cart": {
    "items": [
      {
        "product_id": "product-123",
        "variation_id": "variation-456",
        "name": "Product Name",
        "quantity": 1,
        "unit_price": 29.99,
        "image_url": "https://store.com/media/image/product.jpg",
        "product_url": "https://store.com/products/product-name"
      }
    ],
    "item_count": 1,
    "total": 29.99,
    "currency": "EUR"
  }
}
```

### Add Items Request

```json
{
  "items": [
    {"variation_id": 456, "quantity": 2}
  ]
}
```

### EmporiqaCartHandler

The `emporiqa-cart.js` script registers `window.EmporiqaCartHandler` which the embed script calls for cart operations. It handles:

- **Action routing**: `add`, `update`, `remove`, `clear`, `view`, `checkout`
- **ID extraction**: Strips prefixes from IDs (`"variation-456"` -> `456`)
- **Response normalization**: Returns consistent `{success, error, checkoutUrl, cart}` objects

CSRF protection is enforced on every cart-write via the `X-CSRF-Token` header. Fetch a token from `GET /emporiqa/api/csrf-token` and include it in mutation requests. If the framework CSRF token manager is not wired in the container, the controller fails closed with `403 CSRF protection unavailable` (as of v1.6.3 — earlier versions silently bypassed validation in that edge case).

### Disabling Cart

```yaml
emporiqa:
    cart:
        enabled: false
```

When disabled, the `CartController` and `OrderCompleteSubscriber` services are removed from the container.

## Order Completion

When a customer completes checkout, the plugin sends an `order.completed` webhook to Emporiqa for conversion tracking.

### How It Works

1. The Emporiqa embed script sets an `emporiqa_sid` cookie with the chat session ID
2. When checkout completes, the subscriber reads the cookie and queues the webhook
3. An `order.completed` webhook is sent with order data and the session ID
4. Emporiqa uses the session ID to attribute the conversion to the chat interaction

The subscriber works with both state machine engines:

- **Sylius 2.x**: Listens to `workflow.sylius_order_checkout.completed.complete` (Symfony Workflow)
- **Sylius 1.x**: Listens to `winzou.state_machine.sylius_order_checkout.post_transition.complete` (Winzou)

### Webhook Payload

```json
{
  "type": "order.completed",
  "data": {
    "order_id": "000123",
    "total": 149.98,
    "currency": "EUR",
    "emporiqa_session_id": "abc123",
    "items": [
      {"product_id": "456", "quantity": 2, "price": 74.99}
    ]
  }
}
```

The `emporiqa_sid` cookie value is validated (alphanumeric + `_-.`, max 256 chars) and sanitized before inclusion. Webhook failures are logged but never block the checkout flow. Orders with `paymentState === 'cancelled'` are skipped (no conversion to attribute), and unit prices are handled null-safe so partial order data never breaks the subscriber.

## Keeping your catalog in sync

Per-product changes (price, stock, description, images, status, deletion), product variant changes, and pages on configured `page_entity_classes` flow to Emporiqa automatically via Sylius resource events and Doctrine listeners (`ProductEventSubscriber`, `PageDoctrineListener`). Order completions go through `OrderCompleteSubscriber` on the Symfony Workflow or Winzou state-machine transitions.

Delivery is **synchronous**. Events queue per request and flush on `kernel.terminate`, so no `messenger:consume` worker, supervisord setup, or background cron is required. The sender retries each event up to 2 times with backoff (500 ms delay) inside the same request before logging and dropping.

There is no admin UI for sync. All operator-triggered actions are CLI commands (see Console Commands below).

**Re-run a full sync with `bin/console emporiqa:sync:all` when:**

- You **add a new channel, locale, or currency**. Existing products won't carry the new channel, language, or price entries until they're re-saved.
- You **rename, move, or delete a taxon**. Taxon data is embedded in each product's payload and only refreshes when the product itself is re-saved.
- You **change tax rates or tax categories** that affect displayed prices.
- You **create or modify promotions or promotion coupons**. Promotions are not in the product payload, but their pricing effects only appear in `current_price` when products are re-emitted.
- You **change brand / manufacturer values** on a custom attribute (Sylius has no native brand entity, so whatever you've mapped is read at product-format time only).
- You **import products in bulk** via Sylius fixtures, custom commands, or direct DB writes. Doctrine resource events do not fire for raw SQL or some bulk loaders.
- **Emporiqa was unreachable for an extended period** (network outage, planned maintenance, expired credentials). Events that exceed the 2-retry budget are dropped, so only a manual full sync recovers them.

As a safety net, run `bin/console emporiqa:sync:all` once a week to catch any drift that may have built up from background failures.

## Console Commands

### Sync Products

```bash
bin/console emporiqa:sync:products
```

### Sync Pages

```bash
bin/console emporiqa:sync:pages
```

### Sync Everything

```bash
bin/console emporiqa:sync:all
```

### Test Connection

Validates the webhook connection by sending a real product via dry run (`?dry_run=true`). Picks a product with variants when available and displays connection status, signature validation, detected languages, field coverage, and any warnings.

```bash
bin/console emporiqa:test-connection
```

All sync and test commands surface the actual error reason from the Emporiqa API ("Invalid signature", validation errors, throttle hints) rather than a generic "Request failed with status N". Repeated batch failures during sync are deduplicated and listed in the final command summary. Available on `WebhookSenderInterface` as `getLastError(): ?string` and `buildFriendlyError(array $result): string` for custom transports.

### Command Options

All sync commands support:


| Option            | Description                                      |
| ----------------- | ------------------------------------------------ |
| `--batch-size=50` | Number of events per webhook request             |
| `--dry-run`       | Format data without sending webhooks             |
| `--no-session`    | Skip `sync.start`/`sync.complete` session events |


### Sync Sessions

Full sync operations use sessions for reconciliation:

1. `sync.start`: Notifies Emporiqa that a sync is beginning (includes `session_id` and `entity`)
2. Entity events: batched consolidated data with `sync_session_id` attached to each event
3. `sync.complete`: Signals the sync has finished (items not included in the session can be marked as deleted)

One session is created per entity type (e.g. one for products, one for pages):

```
Products session: sync.start -> all product data (all channels/languages) -> sync.complete
Pages session: sync.start -> all page data (all channels/languages) -> sync.complete
```

Use `--no-session` to skip session management for incremental updates.

## Chat Widget

The plugin provides Twig functions to embed the Emporiqa chat widget:

### With Cart Support (Recommended)

```twig
{{ emporiqa_cart_widget() }}
```

This renders:

1. A `<script>` block setting `window.emporiqaConfig` with `language`, `currency`, `channel`, `authenticated`, and `cartEnabled`: used by the cart handler JS
2. `emporiqa-cart.js`: Registers `window.EmporiqaCartHandler` for in-chat cart operations
3. The widget embed `<script>` tag with all parameters (store ID, language, currency, channel, and signed user token) in the URL

The `window.emporiqaConfig` for the cart handler:

```javascript
// Anonymous user (cacheable by Varnish/CDN)
window.emporiqaConfig = {
  language: "en_US",
  currency: "EUR",
  channel: "default",
  authenticated: false,
  cartEnabled: true
}
```

- `**language**`: Full Sylius locale code from the current request (e.g., `en_US`, `de_DE`). Also sent as `X-Locale` header on cart API calls for locale-aware checkout URLs.
- `**currency**`: Resolved from the user's selected currency (`CurrencyContextInterface`), falling back to the channel's base currency.
- `**channel**`: The current Sylius channel code (e.g., `default`, `B2B`)
- `**authenticated**`: Boolean flag indicating login state

The widget embed URL includes a signed `user_id` parameter for authenticated users: an HMAC-SHA256 signed token containing the user identifier. The token is deterministic (no timestamp) so it's safe for page caching. Anonymous pages contain no user-specific data (safe for Varnish/CDN). Authenticated pages are served with `Cache-Control: private` by Symfony.

### Simple Embed (Without Cart)

```twig
{# Simple inline script, no cart support #}
{{ emporiqa_widget() }}

{# Get just the store ID #}
{{ emporiqa_store_id() }}
```

> **Note**: `emporiqa_widget_url()` is deprecated. It embeds user-specific data directly in the URL and is not safe for cached pages. Use `emporiqa_widget()` or `emporiqa_cart_widget()` instead.

### Router Configuration for CLI

For correct URL generation in console commands (product links), configure the router's default URI in `config/packages/framework.yaml`:

```yaml
framework:
    router:
        default_uri: '%env(SITE_URL)%'
```

And add to `.env`:

```env
SITE_URL=https://your-store.com
```

## Extensibility

All behavior can be customized via Symfony service decoration and event listeners without modifying plugin code.

### Service Interfaces


| Interface                   | Default            | What You Can Customize                                                |
| --------------------------- | ------------------ | --------------------------------------------------------------------- |
| `ProductFormatterInterface` | `ProductFormatter` | Product/variant data formatting, custom attributes                    |
| `PageFormatterInterface`    | `PageFormatter`    | Page data formatting, custom fields                                   |
| `PageUrlResolverInterface`  | `PageUrlResolver`  | Page URL generation (default returns empty string and logs a warning) |
| `OrderProviderInterface`    | `OrderProvider`    | Order lookup logic, response format, verification                     |
| `WebhookSenderInterface`    | `WebhookSender`    | HTTP transport, retry logic, logging                                  |


### Events

Listen to these Symfony events for fine-grained control:


| Event                       | Constant                    | When                                       | What You Can Do                          |
| --------------------------- | --------------------------- | ------------------------------------------ | ---------------------------------------- |
| `emporiqa.pre_sync`         | `PreSyncEvent::NAME`        | Before each entity is synced               | Cancel sync per entity                   |
| `emporiqa.post_format`      | `PostFormatEvent::NAME`     | After product/page is formatted            | Modify formatted data, add custom fields |
| `emporiqa.pre_webhook_send` | `PreWebhookSendEvent::NAME` | Before batch is sent to Emporiqa           | Filter or modify events before delivery  |
| `emporiqa.cart_operation`   | `CartOperationEvent::NAME`  | Before cart add/update/remove/clear        | Cancel operation, enforce business rules |
| `emporiqa.min_order_quantity` | `MinOrderQuantityEvent::NAME` | After the bundle reads the min order qty from the configured product attribute | Override the per-channel minimum (B2B rules, per-customer rules, external system lookup) |
| `emporiqa.order_tracking`   | `OrderTrackingEvent::NAME`  | Before order tracking response is returned | Modify order data, add custom fields     |


Example: modify product data before sending:

```php
use Emporiqa\SyliusPlugin\Event\PostFormatEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: PostFormatEvent::NAME)]
class CustomProductDataListener
{
    public function __invoke(PostFormatEvent $event): void
    {
        $entity = $event->getEntity();
        $events = $event->getFormattedEvents();

        foreach ($events as &$webhookEvent) {
            $webhookEvent['data']['custom_field'] = 'value';
        }

        $event->setFormattedEvents($events);
    }
}
```

Example: block cart operations for specific roles:

```php
use Emporiqa\SyliusPlugin\Event\CartOperationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: CartOperationEvent::NAME)]
class CartAccessListener
{
    public function __invoke(CartOperationEvent $event): void
    {
        if ($event->getOperation() === 'add' && $this->isBlocked()) {
            $event->cancelOperation('Cart is temporarily disabled');
        }
    }
}
```

## Plugin Structure

```
emporiqa/sylius-plugin/
├── composer.json
├── phpunit.xml.dist
├── config/
│   ├── services.yaml
│   └── routes.yaml                         # API routes
├── public/
│   └── js/
│       └── emporiqa-cart.js                # EmporiqaCartHandler for chat widget
├── src/
│   ├── EmporiqaPlugin.php                  # Plugin entry point
│   ├── DependencyInjection/
│   │   ├── Configuration.php               # Config tree definition
│   │   └── EmporiqaExtension.php           # Container extension
│   ├── Model/
│   │   └── PageInterface.php               # Page entity contract
│   ├── Event/
│   │   ├── CartOperationEvent.php          # Dispatched before cart operations
│   │   ├── MinOrderQuantityEvent.php       # Dispatched while computing per-channel min order qty
│   │   ├── OrderTrackingEvent.php          # Dispatched before order tracking response
│   │   ├── PostFormatEvent.php             # Dispatched after entity formatting
│   │   ├── PreSyncEvent.php                # Dispatched before entity sync
│   │   └── PreWebhookSendEvent.php         # Dispatched before webhook delivery
│   ├── Trait/
│   │   └── TranslationHelperTrait.php      # Shared translation lookup logic
│   ├── Service/
│   │   ├── WebhookSender.php               # HTTP client with retry logic
│   │   ├── WebhookSenderInterface.php
│   │   ├── WebhookEventQueue.php           # In-memory event deduplication
│   │   ├── ProductFormatter.php            # Format product data
│   │   ├── ProductFormatterInterface.php
│   │   ├── PageFormatter.php               # Format page data
│   │   ├── PageFormatterInterface.php
│   │   ├── PageUrlResolver.php             # Page URL generation (decorate this!)
│   │   ├── PageUrlResolverInterface.php
│   │   ├── OrderProvider.php               # Order lookup via Sylius
│   │   ├── OrderProviderInterface.php
│   │   ├── CurrencyHelper.php              # Currency-aware price conversion
│   │   ├── ChannelMappingResolver.php      # Maps Sylius channel codes to Emporiqa channel keys
│   │   └── UserTokenGenerator.php          # Signed user token generation
│   ├── Controller/
│   │   ├── CartController.php              # Cart REST API (6 endpoints)
│   │   └── OrderTrackingController.php     # Order tracking API
│   ├── EventSubscriber/
│   │   ├── OrderCompleteSubscriber.php     # Order completion webhook
│   │   └── ProductEventSubscriber.php      # Sylius product events
│   ├── EventListener/
│   │   └── PageDoctrineListener.php        # Doctrine page events
│   ├── Command/
│   │   ├── AbstractSyncCommand.php         # Shared sync logic
│   │   ├── SyncProductsCommand.php
│   │   ├── SyncPagesCommand.php
│   │   ├── SyncAllCommand.php
│   │   └── TestConnectionCommand.php
│   └── Twig/
│       └── EmporiqaExtension.php           # Twig functions
└── tests/
    ├── DependencyInjection/
    │   └── ConfigurationTest.php
    ├── Service/
    │   ├── WebhookSenderTest.php
    │   ├── ProductFormatterTest.php
    │   ├── PageFormatterTest.php
    │   ├── CurrencyHelperTest.php
    │   ├── MarketplaceCompatibilityTest.php
    │   ├── WebhookEventQueueTest.php
    │   ├── UserTokenGeneratorTest.php
    │   └── OrderProviderTest.php
    ├── EventSubscriber/
    │   ├── OrderCompleteSubscriberTest.php
    │   └── ProductEventSubscriberTest.php
    ├── Controller/
    │   ├── CartControllerTest.php
    │   └── OrderTrackingControllerTest.php
    ├── Command/
    │   ├── SyncAllCommandTest.php
    │   └── TestConnectionCommandTest.php
    └── Twig/
        └── EmporiqaExtensionTest.php
```

## Troubleshooting

### Connection Test Fails

```bash
bin/console emporiqa:test-connection -v
```

1. Read the command output. As of v1.6.3 it prints the actual reason from Emporiqa ("Invalid signature", validation errors, throttle hints) rather than just an HTTP code.
2. Verify your Store ID, webhook URL, and connection secret in the Emporiqa dashboard
3. Check that your server can make outbound HTTPS requests
4. Review Symfony logs for detailed error messages

### Cart Operations Fail With 403 "CSRF protection unavailable"

The cart controller fails closed when Symfony's CSRF token manager isn't wired in the container.

1. Enable `framework.csrf_protection: true` (or install `symfony/security-csrf` if your install stripped it)
2. Clear the cache: `bin/console cache:clear`
3. If you ship a custom DI configuration, verify `security.csrf.token_manager` resolves to a real service

### Products Not Syncing

1. Ensure `sync.products` is `true` in configuration
2. Verify the product is enabled and has at least one enabled variant
3. Run a manual sync: `bin/console emporiqa:sync:products`
4. Check Symfony logs for webhook delivery errors

### Pages Not Syncing

1. Verify `page_entity_classes` is configured with your entity FQCNs
2. Confirm your entity implements `Emporiqa\SyliusPlugin\Model\PageInterface`
3. Ensure `sync.pages` is `true` in configuration
4. Run a manual sync: `bin/console emporiqa:sync:pages`

### Widget Not Appearing

1. Confirm `store_id` is configured correctly
2. Ensure `{{ emporiqa_widget() }}` is in your layout template
3. Check browser console for JavaScript errors
4. View page source and look for the `<script async src="...emporiqa.com/chat/embed/...">` tag

### Visual Search Not Returning Matches

1. Confirm the uploaded image is JPEG, PNG, WebP, or GIF. Other formats are rejected at upload
2. Max upload size is 5 MB; larger files are rejected
3. Check the browser console for upload errors (CORS, network)
4. If matches are weak, verify your products actually sync with images. The chatbot describes the photo and searches by that description, so catalog image coverage and descriptive product names matter

### Cache Issues

After configuration changes:

```bash
bin/console cache:clear
```

## Pricing

The plugin is free. Emporiqa is Pay-as-you-go: $0/month base + $0.25/conversation. New accounts get $25 of signup credit (about 100 conversations on us), no card required at signup. After the credit, the monthly cap defaults to $59 and is customer-adjustable from the billing dashboard. Enterprise option for catalogs over 30,000 products. Full pricing at [emporiqa.com/pricing/](https://emporiqa.com/pricing/).

## Support

- **Integration overview**: [https://emporiqa.com/integrations/sylius/](https://emporiqa.com/integrations/sylius/)
- **Documentation**: [https://emporiqa.com/docs/sylius/](https://emporiqa.com/docs/sylius/)
- **Issues**: [https://gitlab.com/emporiqa/integrations/sylius/-/issues](https://gitlab.com/emporiqa/integrations/sylius/-/issues)
- **Email**: [support@emporiqa.com](mailto:support@emporiqa.com)

## License

MIT License - see [LICENSE](LICENSE) file for details.