# Emporiqa Sylius Plugin

[![Packagist Version](https://img.shields.io/packagist/v/emporiqa/sylius-plugin.svg)](https://packagist.org/packages/emporiqa/sylius-plugin)
[![Packagist Downloads](https://img.shields.io/packagist/dt/emporiqa/sylius-plugin.svg)](https://packagist.org/packages/emporiqa/sylius-plugin)
[![License](https://img.shields.io/packagist/l/emporiqa/sylius-plugin.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](composer.json)

Integrates [Sylius](https://sylius.com) with [Emporiqa](https://emporiqa.com?utm_source=github&utm_medium=readme&utm_campaign=sylius_plugin), an AI chat assistant that acts as an online salesperson on your storefront. The plugin provides webhook-based synchronization of products and pages, in-chat cart operations with checkout, an embeddable chat widget, order tracking, and order completion webhooks.

The chat reads your synced catalog and pages: a shopper describes what they need (or uploads a photo), and it returns matching products, answers questions from your own content, and drives cart and checkout through the plugin's APIs. It answers in 65+ languages, whichever locale the shopper writes in.

Try it yourself on the [live demo store](https://demo.emporiqa.com), or watch the [30-second demo](https://www.youtube.com/watch?v=as54_uvk038) (recommends, handles objections, closes).

The chat runs on your storefront, reads your catalog and answers in the shopper's own words:

![Emporiqa on a Sylius storefront. The shopper asks for wireless headphones under 500 euros with great sound, and the chat recommends the Sennheiser Momentum 4 at 379.00 euros, naming the 42 mm transducer and the 60 hours of battery with ANC, then offers to add it to the cart](docs/images/product-search.jpg)

## Documentation

Everything beyond this page lives in the full developer documentation at [emporiqa.com/docs/sylius/](https://emporiqa.com/docs/sylius/). It covers the configuration reference, webhook events and payloads, the cart and checkout API, order tracking, console commands, customization, and troubleshooting.

## Features

- **Product Sync**: Real-time synchronization of Sylius products and variants via webhooks
- **Page Sync**: Synchronization of any translatable page entity (policies, FAQ, blog posts, etc.)
- **Multi-Channel**: Consolidated events with per-channel pricing, availability, and content across all languages
- **Cart & Checkout**: REST API for in-chat cart operations (add, update, remove, clear, view, checkout URL) with event hooks
- **Order Tracking**: API endpoint for order lookup with HMAC signature and replay protection
- **Order Completion**: Webhook notification when checkout completes (supports both Sylius 1.x and 2.x)
- **Chat Widget**: Cache-safe embeddable chat widget with inline signed user tokens and currency/channel awareness
- **Visual Search**: Shoppers upload a photo in the widget; the chatbot matches it against your synced Sylius catalog (no extra config required)
- **Multi-language**: Syncs content in all configured Sylius locales with currency switcher support. The chat itself answers in 65+ languages, independent of which locales you sync
- **Console Commands**: Memory-efficient sync commands with batching, dry-run, and session management
- **Webhook Retry**: Automatic retry with exponential backoff for transient failures
- **Fully Extensible**: Decorate any service interface, listen to events (`PostFormatEvent`, `CartOperationEvent`, `PreSyncEvent`, etc.)

Emporiqa also works with Drupal Commerce, WooCommerce, Magento, PrestaShop, Shopware, and any store via webhook API. One Emporiqa account and dashboard runs across all of them.

## Requirements

- PHP 8.1+
- Sylius 1.12, 1.13 or 2.0
- Symfony 6.4+ or 7.x (6.0 to 6.3 are not supported)
- An Emporiqa account ([sign up](https://emporiqa.com?utm_source=github&utm_medium=readme&utm_campaign=sylius_plugin))

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

All other settings have sensible defaults. See the [Configuration Reference](https://emporiqa.com/docs/sylius/#configuration) for the full list.

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

### Set the Router Default URI

Console commands run without an HTTP request, so the router needs a base URI to generate absolute product links. In `config/packages/framework.yaml`:

```yaml
framework:
    router:
        default_uri: '%env(SITE_URL)%'
```

And in `.env`:

```env
SITE_URL=https://your-store.com
```

Verify the connection with `bin/console emporiqa:test-connection`, then run a first full sync with `bin/console emporiqa:sync:all`. The [developer documentation](https://emporiqa.com/docs/sylius/) has the widget variants and the rest of the reference.

## The chat tells shoppers it is an AI

The default greeting says the shopper is talking to the store's AI assistant, in
every language the chat speaks. A merchant who rewrites that greeting has to keep
the disclosure: one that drops it is refused when saved. Emporiqa's
[Terms of Service](https://emporiqa.com/terms-of-service/) section 8.6 treats
removing it, including through custom CSS or custom code, as a breach.

## Keeping your catalog in sync

Product, variant and page changes reach Emporiqa automatically through Sylius resource events and Doctrine listeners. Delivery is synchronous: events queue per request and flush on `kernel.terminate`, so no `messenger:consume` worker, supervisord setup, or background cron is required. Re-run `bin/console emporiqa:sync:all` after changes that do not re-save products (a new channel, locale or currency, a moved taxon, a changed tax rate or promotion, a renamed brand attribute, a bulk import that bypasses Doctrine events, or an extended outage on the Emporiqa side), and once a week as a safety net. The commands and their flags are documented in [Console Commands](https://emporiqa.com/docs/sylius/#console-commands).

## Pricing

The plugin is free. Emporiqa is Pay-as-you-go: you pay only when the chat talks to a shopper. $0/month base + $0.25/conversation. New accounts get $25 of signup credit (about 100 conversations on us), no card required at signup. After the credit, the monthly cap defaults to $59 and is customer-adjustable from the billing dashboard. Enterprise option for catalogs over 100,000 products. Full pricing at [emporiqa.com/pricing/](https://emporiqa.com/pricing/).

## Support

- **Integration overview**: [https://emporiqa.com/integrations/sylius/](https://emporiqa.com/integrations/sylius/)
- **Documentation**: [https://emporiqa.com/docs/sylius/](https://emporiqa.com/docs/sylius/)
- **Issues**: [https://github.com/emporiqa/sylius/issues](https://github.com/emporiqa/sylius/issues)
- **Email**: [support@emporiqa.com](mailto:support@emporiqa.com)

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Who makes Emporiqa

Emporiqa is built by [Rosel Group LTD](https://emporiqa.com/about/), an EU company based in Sofia, Bulgaria, founded by [Rosen Hristov](https://www.linkedin.com/in/rosen-hristov/), who has built e-commerce software for 15 years. It is GDPR-compliant and never uses shopper data to train AI models. This plugin is listed on [Sylius Addons](https://addons.sylius.com/en_US/products/emporiqa), which reviews every submission before it goes on the shelf, and it installs from Packagist with Composer.
