# Emporiqa Sylius Plugin

Integrates [Sylius](https://sylius.com) with [Emporiqa](https://emporiqa.com?utm_source=gitlab&utm_medium=readme&utm_campaign=sylius_plugin) chat assistant. This plugin provides webhook-based synchronization of products and pages, in-chat cart operations with checkout, an embeddable chat widget, order tracking, and order completion webhooks — enabling a chat assistant that can answer customer questions, manage their cart, and track orders.

## Features

- **Product Sync** — Real-time synchronization of Sylius products and variants via webhooks
- **Page Sync** — Synchronization of any translatable page entity (policies, FAQ, blog posts, etc.)
- **Cart & Checkout** — REST API for in-chat cart operations (add, update, remove, clear, view, checkout URL)
- **Order Tracking** — API endpoint for order lookup during chat conversations
- **Order Completion** — Webhook notification when checkout completes, with session-based conversion tracking
- **Chat Widget** — Embeddable Emporiqa chat widget with AJAX-based loading, user token caching, and cart integration
- **Multi-language** — Syncs content in all configured Sylius locales
- **Console Commands** — Full sync commands with batching, dry-run, and session management
- **Fully Extensible** — Decorate any service interface to customize behavior without touching plugin code

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
| `product.created` | Product created in Sylius admin | Sends product + all variant data |
| `product.updated` | Product or variant updated | Sends full product + variant data |
| `product.deleted` | Product or variant deleted | Sends deletion marker per language |
| `page.created` | Page entity persisted via Doctrine | Sends page data per language |
| `page.updated` | Page entity updated via Doctrine | Sends page data per language |
| `page.deleted` | Page entity removed via Doctrine | Sends deletion marker per language |
| `sync.start` | CLI sync command begins a language session | Includes `session_id`, `entity`, `language` |
| `sync.complete` | CLI sync command finishes a language session | Items not in session can be marked deleted |
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

```json
{
  "identification_number": "product-123",
  "name": "Product Name",
  "sku": "PROD-123",
  "link": "https://store.com/en_US/products/product-name",
  "category": "Electronics",
  "brand": "Brand Name",
  "regular_price": 99.99,
  "current_price": 79.99,
  "description": "Product description...",
  "language": "en",
  "availability_status": "available",
  "stock_quantity": 25,
  "attributes": {"Color": "Blue", "Size": "Large"},
  "images": ["product_image.jpg"],
  "parent_sku": null,
  "is_parent": true,
  "variation_attributes": ["Color", "Size"]
}
```

For variable products, the parent is synced with `is_parent: true` and each variant is synced separately with `parent_sku` referencing the parent.

### Page Data Structure

```json
{
  "identification_number": "page-45",
  "name": "Shipping Policy",
  "link": "https://store.com/en_US/pages/shipping-policy",
  "description": "Page content (stripped HTML)...",
  "language": "en"
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

The plugin exposes an API endpoint that Emporiqa calls during chat conversations when a customer asks about their order. The request is authenticated via HMAC-SHA256 signature.

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

No CSRF tokens are needed — Symfony's SameSite cookies provide protection for same-origin JSON APIs.

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
2. When checkout completes (`workflow.sylius_order_checkout.completed.complete`), the subscriber reads the cookie
3. An `order.completed` webhook is sent with order data and the session ID
4. Emporiqa uses the session ID to attribute the conversion to the chat interaction

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

1. `sync.start` — Notifies Emporiqa that a sync is beginning (includes `session_id`, `entity`, `language`)
2. Entity events — Batched data with `sync_session_id` attached to each event
3. `sync.complete` — Signals the sync has finished (items not included in the session can be marked as deleted)

Sessions are language-specific. When syncing two languages, the command runs separate sessions for each:

```
EN session: sync.start -> all EN data -> sync.complete
DE session: sync.start -> all DE data -> sync.complete
```

Use `--no-session` to skip session management for incremental updates.

## Chat Widget

The plugin provides Twig functions to embed the Emporiqa chat widget:

### With Cart Support (Recommended)

```twig
{{ emporiqa_cart_widget() }}
```

This renders:
1. A `<script>` block setting `window.emporiqaConfig` with store ID, widget base URL, language, user ID, and cart flag
2. `emporiqa-cart.js` — Registers `window.EmporiqaCartHandler` for in-chat cart operations
3. `emporiqa-widget-loader.js` — Loads the widget script, fetching and caching user tokens via AJAX for authenticated users

The widget loader:
- For **authenticated users**: Fetches a signed token from `/emporiqa/api/user-token` and caches it in `sessionStorage`
- For **anonymous users**: Loads the widget without a token and clears any stale cached tokens

### Simple Embed (Without Cart)

```twig
{# Simple inline script — no cart support #}
{{ emporiqa_widget() }}

{# Get just the store ID #}
{{ emporiqa_store_id() }}

{# Get the widget URL (for custom embed markup) #}
{{ emporiqa_widget_url() }}
```

The widget URL includes:

- `store_id` — Your Emporiqa store identifier
- `language` — Auto-detected from the current Sylius locale
- `user_id` — Signed token for logged-in customers (generated server-side using the webhook secret)

The `user_id` token is a base64url-encoded JSON payload (`uid` + `ts`) with an HMAC-SHA256 signature. Emporiqa verifies this token to securely identify customers for features like order tracking.

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

All behavior can be customized via Symfony service decoration without modifying plugin code:

| Interface | Default | What You Can Customize |
|-----------|---------|----------------------|
| `ProductFormatterInterface` | `ProductFormatter` | Product/variant data formatting, custom attributes |
| `PageFormatterInterface` | `PageFormatter` | Page data formatting, custom fields |
| `PageUrlResolverInterface` | `PageUrlResolver` | Page URL generation (default returns empty string) |
| `OrderProviderInterface` | `OrderProvider` | Order lookup logic, response format, verification |
| `WebhookSenderInterface` | `WebhookSender` | HTTP transport, retry logic, logging |

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
│   ├── Service/
│   │   ├── WebhookSender.php               # HTTP client for webhooks
│   │   ├── WebhookSenderInterface.php
│   │   ├── ProductFormatter.php            # Format product data
│   │   ├── ProductFormatterInterface.php
│   │   ├── PageFormatter.php               # Format page data
│   │   ├── PageFormatterInterface.php
│   │   ├── PageUrlResolver.php             # Page URL generation
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
    │   └── EmporiqaExtensionTest.php
    ├── Service/
    │   ├── WebhookSenderTest.php
    │   ├── ProductFormatterTest.php
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

- **Documentation**: [https://emporiqa.com/docs/](https://emporiqa.com/docs/)
- **Issues**: [https://gitlab.com/emporiqa/integrations/sylius/-/issues](https://gitlab.com/emporiqa/integrations/sylius/-/issues)
- **Email**: support@emporiqa.com

## License

MIT License - see [LICENSE](LICENSE) file for details.
