# Emporiqa Sylius Plugin

[![Packagist Version](https://img.shields.io/packagist/v/emporiqa/sylius-plugin.svg)](https://packagist.org/packages/emporiqa/sylius-plugin)
[![Packagist Downloads](https://img.shields.io/packagist/dt/emporiqa/sylius-plugin.svg)](https://packagist.org/packages/emporiqa/sylius-plugin)
[![License](https://img.shields.io/packagist/l/emporiqa/sylius-plugin.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](composer.json)

Integrates [Sylius](https://sylius.com) with [Emporiqa](https://emporiqa.com?utm_source=github&utm_medium=readme&utm_campaign=sylius_plugin), an AI chatbot that works as an online salesperson on your storefront. The plugin provides webhook-based synchronization of products and pages, in-chat cart operations with checkout, an embeddable chat widget, order status lookups, and order completion webhooks.

The chat reads your synced catalog and pages: a shopper describes what they need (or uploads a photo), and it returns matching products, answers questions from your own content, and drives cart and checkout through the plugin's APIs. It answers in 65+ languages, whichever locale the shopper writes in.

Try it yourself on the [live demo store](https://demo.emporiqa.com), or watch the [30-second demo](https://www.youtube.com/watch?v=y7ARSIUxuXI) (a shopper's question, the recommendation, the cart).

The chat runs on your storefront, reads your catalog and answers in the shopper's own words:

![Emporiqa chat widget open on a storefront, answering which noise cancelling headphones under 400 euros suit long flights: it names the Sennheiser Momentum 4 for up to 60 hours with ANC and the Sony WH-1000XM5 at 250 g, and shows both as product cards with photo, price and a Cart button, above a message box with a photo button and a voice button](docs/images/lead-answer.webp)

## Documentation

Everything beyond this page lives in the full developer documentation at [emporiqa.com/docs/sylius/](https://emporiqa.com/docs/sylius/). It covers the configuration reference, webhook events and payloads, the cart and checkout API, order status, console commands, customization, and troubleshooting.

## Features

- **Product Sync**: Real-time synchronization of Sylius products and variants via webhooks
- **Page Sync**: Synchronization of any translatable page entity (policies, FAQ, blog posts, etc.)
- **Multi-Channel**: Consolidated events with per-channel pricing, availability, and content across all languages
- **Cart & Checkout**: REST API for in-chat cart operations (add, update, remove, clear, view, checkout URL) with event hooks
- **Order Status rule**: Emporiqa's ready-made Order status rule looks orders up through a signed, rate-limited endpoint
- **Order Completion**: Webhook notification when checkout completes (supports both Sylius 1.x and 2.x)
- **Chat Widget**: Cache-safe embeddable chat widget with currency/channel awareness; a signed-in shopper's token comes from an uncached endpoint, never from the page
- **Visual Search**: Shoppers upload a photo in the widget; the chat matches it against your synced Sylius catalog (no extra config required)
- **Voice Conversations** (optional, off by default): the shopper taps the microphone, speaks, and hears the answer read aloud while the product cards stay on screen. $0.15 more per voice conversation
- **Widget Appearance**: up to four starter questions per language under the welcome message, your store's picture in the chat header and next to the chat's answers, and your team's own names and photos on their replies
- **Multi-language**: Syncs content in all configured Sylius locales with currency switcher support. The chat itself answers in 65+ languages, independent of which locales you sync. A locale left out of `enabled_languages` is not synced, and its pages show no chat
- **Console Commands**: Memory-efficient sync commands with batching, dry-run, and session management
- **Webhook Retry**: Automatic retry with exponential backoff for transient failures
- **Fully Extensible**: Decorate any service interface, listen to events (`PostFormatEvent`, `CartOperationEvent`, `PreSyncEvent`, etc.)

Emporiqa also works with Drupal Commerce, WooCommerce, Magento, PrestaShop, Shopware, and any store via webhook API. One Emporiqa account and dashboard runs across all of them.

## Requirements

- PHP 8.1+ (Sylius 2.3 itself needs PHP 8.3+, and Symfony 8 needs PHP 8.4+)
- Sylius 1.12 or later 1.x, or Sylius 2.0 to 2.3
- Symfony 6.4, 7.x or 8.x (6.0 to 6.3 are not supported)

Checked on Sylius 2.3 with Symfony 8.1 and PHP 8.4, Sylius 2.2 with Symfony
7.4 and PHP 8.3, and Sylius 1.12 with Symfony 6.4 and PHP 8.1. Sylius 1.12
and 1.13 no longer get security fixes, and a current Composer refuses to
install them unless their advisories are ignored: upgrade Sylius first.
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

This registers the Order status endpoints, the cart API endpoints, and the customer token endpoint. If you don't need some features, you can disable them individually in configuration.

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

This copies the plugin's JavaScript files (`emporiqa-cart.js`, `emporiqa-customer-token.js`) to `public/bundles/emporiqaplugin/js/`.

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

## Order status

Emporiqa's ready-made **Order status** rule is how the chat answers "where
is my order?" from your Sylius orders.

1. Set `framework.router.default_uri` to your shop's https address (the
   address must be https).
2. Run `bin/console emporiqa:test-connection`. Under Ready-made rules it
   prints your Order status address (`https://your-store.com/emporiqa/api/`)
   and whether the rule is On.
3. In Emporiqa, add the Order status rule (Settings > Rules). It asks for
   this address: paste it there, then click the link Emporiqa emails you to
   confirm it.
4. Try it with one of your recent order numbers and its email, then click
   Go live.

Shoppers who are not signed in prove an order with its number and email. A
signed-in shopper is matched by their Sylius customer id.

Once the order is proved, the chat can answer its status and tracking, and
its details when the shopper asks: what was ordered, the totals, payment,
shipping method and delivery time, and the shipping and billing addresses.

To change that answer or add your own details, listen to
`OrderStatusEvent` (`emporiqa.order_status`). It runs after the answer is
filled. Put your own fields under `extra` (at most 3 levels deep, 30 keys,
strings up to 500 characters):

```php
use Emporiqa\SyliusPlugin\Event\OrderStatusEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderStatusEvent::NAME)]
final class GiftWrapOrderStatusListener
{
    public function __invoke(OrderStatusEvent $event): void
    {
        $data = $event->getData();
        $data['extra']['gift_wrap'] = true;
        $event->setData($data);
    }
}
```

The same address also answers `actions/customer-prices`, which gives
Emporiqa the prices a signed-in customer pays. In Sylius core that is the
channel price with its catalog promotions, the same as the synced price.

The rate limits of these endpoints are counted in `cache.app`. If your shop
runs on more than one web server, that pool must be shared (Redis or
Memcached), not APCu.

## The chat tells shoppers it is an AI

The default greeting says the shopper is talking to the store's AI assistant, in
every language the chat speaks. A merchant who rewrites that greeting has to keep
the disclosure: one that drops it is refused when saved. Emporiqa's
[Terms of Service](https://emporiqa.com/terms-of-service/) section 8.6 treats
removing it, including through custom CSS or custom code, as a breach.

## Keeping your catalog in sync

Product, variant and page changes reach Emporiqa automatically through Sylius resource events and Doctrine listeners. Delivery is synchronous: events queue per request and flush on `kernel.terminate`, so no `messenger:consume` worker, supervisord setup, or background cron is required. Re-run `bin/console emporiqa:sync:all` after changes that do not re-save products (a new channel, locale or currency, a moved taxon, a changed tax rate or promotion, a renamed brand attribute, a bulk import that bypasses Doctrine events, or an extended outage on the Emporiqa side), and once a week as a safety net. The commands and their flags are documented in [Console Commands](https://emporiqa.com/docs/sylius/#console-commands).

## Pricing

The plugin is free. Emporiqa is Pay-as-you-go: you pay only when the chat talks to a shopper. $0/month base + $0.25/conversation, +$0.15 per voice conversation (optional, off by default). New accounts get $25 of signup credit (about 100 conversations on us), no card required at signup. After the credit, the monthly cap defaults to $59 and is customer-adjustable from the billing dashboard. Prices exclude VAT. Enterprise option for catalogs over 100,000 products. Full pricing at [emporiqa.com/pricing/](https://emporiqa.com/pricing/).

## Support

- **Integration overview**: [https://emporiqa.com/integrations/sylius/](https://emporiqa.com/integrations/sylius/)
- **Documentation**: [https://emporiqa.com/docs/sylius/](https://emporiqa.com/docs/sylius/)
- **Issues**: [https://github.com/emporiqa/sylius/issues](https://github.com/emporiqa/sylius/issues)
- **Email**: [support@emporiqa.com](mailto:support@emporiqa.com)

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Who makes Emporiqa

Emporiqa is built by [Rosel Group LTD](https://emporiqa.com/about/), an EU company based in Sofia, Bulgaria, founded by [Rosen Hristov](https://www.linkedin.com/in/rosen-hristov/), who has built e-commerce software for 15 years. It is GDPR-compliant and never uses shopper data to train AI models. This plugin is listed on [Sylius Addons](https://addons.sylius.com/en_US/products/emporiqa), which reviews every submission before it goes on the shelf, and it installs from Packagist with Composer.
