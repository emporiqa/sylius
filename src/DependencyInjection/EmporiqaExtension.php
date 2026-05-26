<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\DependencyInjection;

use Emporiqa\SyliusPlugin\Command\SyncPagesCommand;
use Emporiqa\SyliusPlugin\EventListener\PageDoctrineListener;
use Emporiqa\SyliusPlugin\EventSubscriber\OrderCompleteSubscriber;
use Emporiqa\SyliusPlugin\Service\PageFormatter;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\PageUrlResolver;
use Emporiqa\SyliusPlugin\Service\PageUrlResolverInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class EmporiqaExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('emporiqa.store_id', $config['store_id']);
        $container->setParameter('emporiqa.webhook_url', $config['webhook_url']);
        $container->setParameter('emporiqa.webhook_secret', $config['webhook_secret']);
        $container->setParameter('emporiqa.base_url', $config['base_url']);
        $container->setParameter('emporiqa.media_base_path', $config['media_base_path']);
        $container->setParameter('emporiqa.enabled_languages', $config['enabled_languages']);
        $container->setParameter('emporiqa.brand_attribute_code', $config['brand_attribute_code']);
        $container->setParameter('emporiqa.min_order_quantity_attribute', $config['min_order_quantity_attribute']);
        $container->setParameter('emporiqa.sync.products', $config['sync']['products']);
        $container->setParameter('emporiqa.sync.pages', $config['sync']['pages']);
        $container->setParameter('emporiqa.page_entity_classes', $config['page_entity_classes']);
        $container->setParameter('emporiqa.order_tracking.enabled', $config['order_tracking']['enabled']);
        $container->setParameter('emporiqa.cart.enabled', $config['cart']['enabled']);

        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config'));
        $loader->load('services.yaml');

        if (empty($config['page_entity_classes'])) {
            $this->removeServiceIfExists($container, PageFormatter::class);
            $this->removeAliasIfExists($container, PageFormatterInterface::class);
            $this->removeServiceIfExists($container, PageDoctrineListener::class);
            $this->removeServiceIfExists($container, PageUrlResolver::class);
            $this->removeAliasIfExists($container, PageUrlResolverInterface::class);
            $this->removeServiceIfExists($container, SyncPagesCommand::class);
        }

        if (!$config['cart']['enabled']) {
            $this->removeServiceIfExists($container, OrderCompleteSubscriber::class);
        }
    }

    public function getAlias(): string
    {
        return 'emporiqa';
    }

    private function removeServiceIfExists(ContainerBuilder $container, string $id): void
    {
        if ($container->hasDefinition($id)) {
            $container->removeDefinition($id);
        }
    }

    private function removeAliasIfExists(ContainerBuilder $container, string $id): void
    {
        if ($container->hasAlias($id)) {
            $container->removeAlias($id);
        }
    }
}
