<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('emporiqa');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('store_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The Emporiqa store identifier. Can use env var: "%env(EMPORIQA_STORE_ID)%"')
                ->end()
                ->scalarNode('webhook_url')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The Emporiqa webhook endpoint URL. Can use env var: "%env(EMPORIQA_WEBHOOK_URL)%"')
                ->end()
                ->scalarNode('webhook_secret')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Required HMAC-SHA256 signing key for webhook authentication')
                ->end()
                ->scalarNode('base_url')
                    ->defaultValue('')
                    ->info('Base URL for CLI context (e.g., https://myshop.com). Used when router context is unavailable.')
                ->end()
                ->scalarNode('media_base_path')
                    ->defaultValue('/media/image/')
                    ->info('Base path for product/variant images (e.g., /media/image/, /media/cache/resolve/sylius_shop_product_thumbnail/). Customize if using CDN or non-default media storage.')
                ->end()
                ->arrayNode('enabled_languages')
                    ->scalarPrototype()->end()
                    ->defaultValue(['en_US', 'de_DE'])
                    ->info('List of Sylius locale codes to sync (e.g., en_US, de_DE)')
                ->end()
                ->scalarNode('brand_attribute_code')
                    ->defaultValue('brand')
                    ->info('Product attribute code used for brand/manufacturer (e.g., brand, manufacturer)')
                ->end()
                ->scalarNode('min_order_quantity_attribute')
                    ->defaultValue('min_order_qty')
                    ->info('Product attribute code used for the minimum order quantity. Sylius has no native field for this; integers from this attribute are forwarded as `min_order_quantities` per channel. Listeners on emporiqa.min_order_quantity may override the resolved value.')
                ->end()
                ->arrayNode('sync')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('products')
                            ->defaultTrue()
                            ->info('Enable product synchronization')
                        ->end()
                        ->booleanNode('pages')
                            ->defaultTrue()
                            ->info('Enable page synchronization')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('page_entity_classes')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('FQCNs of page entities implementing PageInterface (empty = page sync disabled)')
                ->end()
                ->arrayNode('order_tracking')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable order tracking API endpoint')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cart')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable cart API endpoints')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
