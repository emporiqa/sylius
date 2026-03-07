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
                    ->defaultValue('%env(EMPORIQA_STORE_ID)%')
                    ->cannotBeEmpty()
                    ->info('The Emporiqa store identifier')
                ->end()
                ->scalarNode('webhook_url')
                    ->defaultValue('%env(EMPORIQA_WEBHOOK_URL)%')
                    ->cannotBeEmpty()
                    ->info('The Emporiqa webhook endpoint URL')
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
                ->arrayNode('enabled_languages')
                    ->scalarPrototype()->end()
                    ->defaultValue(['en_US', 'de_DE'])
                    ->info('List of Sylius locale codes to sync (e.g., en_US, de_DE)')
                ->end()
                ->arrayNode('channel_mapping')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Maps Sylius channel codes to Emporiqa channel keys (e.g., FASHION_WEB: "", FASHION_B2B: "b2b")')
                ->end()
                ->scalarNode('brand_attribute_code')
                    ->defaultValue('brand')
                    ->info('Product attribute code used for brand/manufacturer (e.g., brand, manufacturer)')
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
