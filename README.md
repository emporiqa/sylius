# Emporiqa Sylius Plugin

Integrates [Sylius](https://sylius.com) with [Emporiqa](https://emporiqa.com?utm_source=gitlab&utm_medium=readme&utm_campaign=sylius_plugin) chat assistant. This plugin provides webhook-based synchronization of products and pages, in-chat cart operations with checkout, an embeddable chat widget, order tracking, and order completion webhooks — enabling a chat assistant that can answer customer questions, manage their cart, and track orders.

## Features

- **Product Sync** — Real-time synchronization of Sylius products and variants via webhooks
- **Page Sync** — Synchronization of any translatable page entity (policies, FAQ, blog posts, etc.)
- **Multi-Channel** — Consolidated events with per-channel pricing, availability, and content across all languages
- **Cart & Checkout** — REST API for in-chat cart operations (add, update, remove, clear, view, checkout URL) with event hooks
- **Order Tracking** — API endpoint for order lookup with HMAC signature and replay protection
- **Order Completion** — Webhook notification when checkout completes (supports both Sylius 1.x and 2.x)
- **Chat Widget** — Cache-safe embeddable chat widget with inline signed user tokens and currency/channel awareness
- **Multi-language** — Syncs content in all configured Sylius locales with currency switcher support
- **Console Commands** — Memory-efficient sync commands with batching, dry-run, and session management
- **Webhook Retry** — Automatic retry with exponential backoff for transient failures
- **Fully Extensible** — Decorate any service interface, listen to events (`PostFormatEvent`, `CartOperationEvent`, `PreSyncEvent`, etc.)

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

This copies the plugin's JavaScript files (`emporiqa-cart.js`, `emporiqa-widget-loader.js`) to `public/bundles/emporiqa/js/`.

### Clear Cache

```bash
bin/console cache:clear
```

## Configuration Reference

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `store_id` | string | `%env(EMPORIQA_STORE_ID)%` | Emporiqa store identifier |
| `webhook_url` | string | `%env(EMPORIQA_WEBHOOK_URL)%` | Emporiqa webhook endpoint |
| `webhook_secret` | string | **required** | HMAC-SHA256 signing key for webhook authentication |
| `enabled_languages` | string[] | `['en_US', 'de_DE']` | Sylius locale codes to sync |
| `channel_mapping` | map | `{}` | Maps Sylius channel codes to Emporiqa channel keys |
| `sync.products` | bool | `true` | Enable automatic product synchronization |
| `sync.pages` | bool | `true` | Enable automatic page synchronization |
| `page_entity_classes` | string[] | `[]` | FQCNs of page entities implementing `PageInterface` |
| `order_tracking.enabled` | bool | `true` | Enable the order tracking API endpoint |
| `cart.enabled` | bool | `true` | Enable cart API endpoints, user token, and order completion webhook |

### Full Configuration Example

```yaml
emporiqa:
    store_id: '%env(EMPORIQA_STORE_ID)%'
    webhook_url: '%env(EMPORIQA_WEBHOOK_URL)%'
    webhook_secret: '%env(EMPORIQA_WEBHOOK_SECRET)%'
    enabled_languages: ['en_US', 'de_DE']
    channel_mapping:
        FASHION_WEB: ''       # store-wide (default channel)
        FASHION_B2B: 'b2b'    # B2B channel
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

The `enabled_languages` setting must match your Sylius locale codes exactly. The plugin extracts the short language code (e.g., `en` from `en_US`) for webhook payloads.

```yaml
emporiqa:
    enabled_languages: ['en_US', 'de_DE']    # Syncs EN and DE content
```

### Channel Mapping

The `channel_mapping` maps Sylius channel codes to Emporiqa channel keys. This controls how multi-channel data is organized in webhook payloads:

```yaml
emporiqa:
    channel_mapping:
        FASHION_WEB: ''       # maps to store-wide (default)
        FASHION_B2B: 'b2b'    # maps to "b2b" channel in Emporiqa
```

When `channel_mapping` is empty (the default), all Sylius channels map to `""` (store-wide). The mapping also determines the `channel` parameter sent with the chat widget.

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

| Event | Trigger | Description |
|-------|---------|-------------|
| `product.created` | Product created in Sylius admin | Sends consolidated product + variant data |
| `product.updated` | Product or variant updated | Sends consolidated product + variant data |
| `product.deleted` | Product or variant deleted | Sends `identification_number` only |
| `page.created` | Page entity persisted via Doctrine | Sends consolidated page data |
| `page.updated` | Page entity updated via Doctrine | Sends consolidated page data |
| `page.deleted` | Page entity removed via Doctrine | Sends `identification_number` only |
| `sync.start` | CLI sync command begins | Includes `session_id` and `entity` |
| `sync.complete` | CLI sync command finishes | Items not in session can be marked deleted |
| `order.completed` | Checkout workflow completes | Order total, items, currency, session ID |

### Sylius Events Listened To

Products use Sylius resource events:

| Sylius Event | Plugin Handler |
|-------------|----------------|
| `sylius.product.post_create` | Sends `product.created` |
| `sylius.product.post_update` | Sends `product.updated` |
| `sylius.product.pre_delete` | Sends `product.deleted` |
| `sylius.product_variant.post_create` | Sends parent product update |
| `sylius.product_variant.post_update` | Sends parent product update |
| `sylius.product_variant.pre_delete` | Sends variant `product.deleted` |

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
      "": {"en": "Product Name", "de": "Produktname"},
      "b2b": {"en": "Product Name"}
    },
    "descriptions": {
      "": {"en": "Description...", "de": "Beschreibung..."}
    },
    "links": {
      "": {"en": "https://store.com/en_US/products/product-name", "de": "https://store.com/de_DE/products/produktname"}
    },
    "categories": {
      "": ["Electronics"],
      "b2b": ["Electronics"]
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
    "images": {
      "": ["https://store.com/media/image/product.jpg"]
    },
    "attributes": {
      "": {"en": {"Color": "Blue"}, "de": {"Farbe": "Blau"}}
    },
    "parent_sku": null,
    "is_parent": false,
    "variation_attributes": []
  }
}
```

For variable products, the parent is synced with `is_parent: true` and each variant is synced separately with `parent_sku` referencing the parent.

### Delete Events

Delete events are simplified — they contain only the `identification_number`:

```json
{
  "type": "product.deleted",
  "data": {
    "identification_number": "product-123"
  }
}
```

### Page Data Structure

```json
{
  "type": "page.updated",
  "data": {
    "identification_number": "page-45",
    "channels": [""],
    "titles": {
      "": {"en": "Shipping Policy", "de": "Versandrichtlinie"}
    },
    "contents": {
      "": {"en": "Page content...", "de": "Seiteninhalt..."}
    },
    "links": {
      "": {"en": "https://store.com/en_US/pages/shipping-policy", "de": "https://store.com/de_DE/pages/versandrichtlinie"}
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

2. Register the entity class in configuration:

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

If you don't need page sync, simply leave `page_entity_classes` empty (the default). When empty, the plugin will not register the `PageFormatter`, `PageDoctrineListener`, or `PageUrlResolver` services at all.

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

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `order_identifier` | string | yes | Order number provided by the customer |
| `timestamp` | integer | yes | Unix timestamp of the request |
| `user_id` | string | no | Customer's user ID (if identified) |
| `verification_fields` | object | no | Additional verification (e.g. email) |

The `X-Emporiqa-Signature` header contains the HMAC-SHA256 signature of the raw request body, signed with your `webhook_secret`.

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

| Sylius State | Returned Status |
|-------------|-----------------|
| Awaiting payment | `pending_payment` |
| Paid, not shipped | `processing` |
| Partially shipped | `partially_shipped` |
| Shipped | `shipped` |
| Refunded | `refunded` |
| Cancelled | `cancelled` |

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

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/emporiqa/api/cart` | View current cart |
| `POST` | `/emporiqa/api/cart/add` | Add items to cart |
| `POST` | `/emporiqa/api/cart/update` | Update item quantity |
| `POST` | `/emporiqa/api/cart/remove` | Remove item from cart |
| `POST` | `/emporiqa/api/cart/clear` | Clear all items |
| `GET` | `/emporiqa/api/cart/checkout-url` | Get checkout URL |
| `GET` | `/emporiqa/api/user-token` | Get signed user identity token |

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

CSRF protection is enforced for authenticated users via the `X-CSRF-Token` header. Anonymous users skip CSRF validation (no session to hijack). Fetch a CSRF token from `GET /emporiqa/api/csrf-token` and include it in mutation requests.

### Disabling Cart

```yaml
emporiqa:
    cart:
        enabled: false
```

When disabled, the `CartController`, `UserTokenController`, and `OrderCompleteSubscriber` services are all removed from the container.

## Order Completion

When a customer completes checkout, the plugin sends an `order.completed` webhook to Emporiqa for conversion tracking.

### How It Works

1. The Emporiqa embed script sets an `emporiqa_sid` cookie with the chat session ID
2. When checkout completes, the subscriber reads the cookie and queues the webhook
3. An `order.completed` webhook is sent with order data and the session ID
4. Emporiqa uses the session ID to attribute the conversion to the chat interaction

The subscriber works with both state machine engines:
- **Sylius 2.x** — Listens to `workflow.sylius_order_checkout.completed.complete` (Symfony Workflow)
- **Sylius 1.x** — Listens to `winzou.state_machine.sylius_order_checkout.post_transition.complete` (Winzou)

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

The `emporiqa_sid` cookie value is validated (alphanumeric + `_-.`, max 256 chars) and sanitized before inclusion. Webhook failures are logged but never block the checkout flow.

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

```bash
bin/console emporiqa:test-connection
```

### Command Options

All sync commands support:

| Option | Description |
|--------|-------------|
| `--batch-size=50` | Number of events per webhook request |
| `--dry-run` | Format data without sending webhooks |
| `--no-session` | Skip `sync.start`/`sync.complete` session events |

### Sync Sessions

Full sync operations use sessions for reconciliation:

1. `sync.start` — Notifies Emporiqa that a sync is beginning (includes `session_id` and `entity`)
2. Entity events — Batched consolidated data with `sync_session_id` attached to each event
3. `sync.complete` — Signals the sync has finished (items not included in the session can be marked as deleted)

One session is created per entity type (e.g. one for products, one for pages):

```
Products session: sync.start -> all product data (all channels/languages) -> sync.complete
Pages session: sync.start -> all page data -> sync.complete
```

Use `--no-session` to skip session management for incremental updates.

## Chat Widget

The plugin provides Twig functions to embed the Emporiqa chat widget:

### With Cart Support (Recommended)

```twig
{{ emporiqa_cart_widget() }}
```

This renders:
1. A `<script>` block setting `window.emporiqaConfig` — for anonymous users contains no user data (safe for Varnish/CDN), for authenticated users includes a signed token (Symfony serves these as `Cache-Control: private`)
2. `emporiqa-cart.js` — Registers `window.EmporiqaCartHandler` for in-chat cart operations
3. `emporiqa-widget-loader.js` — Reads the config and loads the widget script

The widget config:

```javascript
// Anonymous user (cacheable by Varnish/CDN)
window.emporiqaConfig = {
  storeId: "...",
  widgetBaseUrl: "...",
  language: "en",
  currency: "EUR",
  channel: "",
  authenticated: false,
  cartEnabled: true
}

// Authenticated user (Cache-Control: private, includes signed token)
window.emporiqaConfig = {
  storeId: "...",
  widgetBaseUrl: "...",
  language: "en",
  currency: "EUR",
  channel: "",
  authenticated: true,
  cartEnabled: true,
  userId: "eyJ1aWQiOiJ1c2VyQGV4YW1wbGUuY29tIiwidHMiOjE3MDk...hmac_signature"
}
```

- **`language`** — Auto-detected from the current Sylius request locale
- **`currency`** — Resolved from the user's selected currency (`CurrencyContextInterface`), falling back to the channel's base currency. Automatically reflects the currency switcher selection.
- **`channel`** — Emporiqa channel key resolved via `channel_mapping` from the current Sylius channel
- **`authenticated`** — Boolean flag indicating login state
- **`userId`** — HMAC-SHA256 signed token containing the user identifier and timestamp (only present for authenticated users with a configured `webhook_secret`). The token is embedded directly in the page — no AJAX calls needed. This is safe because Symfony serves authenticated pages with `Cache-Control: private` (never shared-cached by Varnish/CDN).

The `/emporiqa/api/user-token` endpoint is still available for API consumers or custom integrations that need to fetch the token separately.

### Simple Embed (Without Cart)

```twig
{# Simple inline script — no cart support #}
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

| Interface | Default | What You Can Customize |
|-----------|---------|----------------------|
| `ProductFormatterInterface` | `ProductFormatter` | Product/variant data formatting, custom attributes |
| `PageFormatterInterface` | `PageFormatter` | Page data formatting, custom fields |
| `PageUrlResolverInterface` | `PageUrlResolver` | Page URL generation (default returns empty string and logs a warning) |
| `OrderProviderInterface` | `OrderProvider` | Order lookup logic, response format, verification |
| `WebhookSenderInterface` | `WebhookSender` | HTTP transport, retry logic, logging |

### Events

Listen to these Symfony events for fine-grained control:

| Event | Constant | When | What You Can Do |
|-------|----------|------|-----------------|
| `emporiqa.pre_sync` | `PreSyncEvent::NAME` | Before each entity is synced | Cancel sync per entity |
| `emporiqa.post_format` | `PostFormatEvent::NAME` | After product/page is formatted | Modify formatted data, add custom fields |
| `emporiqa.pre_webhook_send` | `PreWebhookSendEvent::NAME` | Before batch is sent to Emporiqa | Filter or modify events before delivery |
| `emporiqa.cart_operation` | `CartOperationEvent::NAME` | Before cart add/update/remove/clear | Cancel operation, enforce business rules |
| `emporiqa.order_tracking` | `OrderTrackingEvent::NAME` | Before order tracking response is returned | Modify order data, add custom fields |

Example — modify product data before sending:

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

Example — block cart operations for specific roles:

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
│       ├── emporiqa-cart.js                # EmporiqaCartHandler for chat widget
│       └── emporiqa-widget-loader.js       # Widget loader with token caching
├── src/
│   ├── EmporiqaPlugin.php                  # Plugin entry point
│   ├── DependencyInjection/
│   │   ├── Configuration.php               # Config tree definition
│   │   └── EmporiqaExtension.php           # Container extension
│   ├── Model/
│   │   └── PageInterface.php               # Page entity contract
│   ├── Event/
│   │   ├── CartOperationEvent.php          # Dispatched before cart operations
│   │   ├── OrderTrackingEvent.php          # Dispatched before order tracking response
│   │   ├── PostFormatEvent.php             # Dispatched after entity formatting
│   │   ├── PreSyncEvent.php                # Dispatched before entity sync
│   │   └── PreWebhookSendEvent.php         # Dispatched before webhook delivery
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
│   │   └── OrderProviderInterface.php
│   ├── Controller/
│   │   ├── CartController.php              # Cart REST API (6 endpoints)
│   │   ├── OrderTrackingController.php     # Order tracking API
│   │   └── UserTokenController.php         # AJAX user token endpoint
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
    │   ├── WebhookEventQueueTest.php
    │   └── OrderProviderTest.php
    ├── EventSubscriber/
    │   ├── OrderCompleteSubscriberTest.php
    │   └── ProductEventSubscriberTest.php
    ├── Controller/
    │   ├── CartControllerTest.php
    │   ├── OrderTrackingControllerTest.php
    │   └── UserTokenControllerTest.php
    └── Twig/
        └── EmporiqaExtensionTest.php
```

## Troubleshooting

### Connection Test Fails

```bash
bin/console emporiqa:test-connection -v
```

1. Verify your Store ID in the Emporiqa dashboard
2. Check that your server can make outbound HTTPS requests
3. Review Symfony logs for detailed error messages

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

### Cache Issues

After configuration changes:

```bash
bin/console cache:clear
```

## Support

- **Documentation**: [https://emporiqa.com/docs/sylius/](https://emporiqa.com/docs/sylius/)
- **Issues**: [https://gitlab.com/emporiqa/integrations/sylius/-/issues](https://gitlab.com/emporiqa/integrations/sylius/-/issues)
- **Email**: support@emporiqa.com

## License

MIT License - see [LICENSE](LICENSE) file for details.
