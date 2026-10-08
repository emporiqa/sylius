<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\DependencyInjection;

use Emporiqa\SyliusPlugin\DependencyInjection\EmporiqaExtension;
use Emporiqa\SyliusPlugin\Service\CurrentStateBuilder;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The plugin's services compile into a container as a shop has them.
 * Everything outside the plugin (Sylius, Doctrine, Symfony) is a synthetic
 * stub, so a plugin service that needs one of the plugin's own services the
 * extension removed fails here as it fails in the shop.
 */
class ContainerCompileTest extends TestCase
{
    private const OWN_NAMESPACE = 'Emporiqa\\SyliusPlugin\\';

    private const BASE_CONFIG = [
        'store_id' => 'test-store',
        'webhook_url' => 'https://emporiqa.com/webhooks/sync/',
        'webhook_secret' => 'test-secret',
    ];

    public function testItCompilesWithTheDefaultConfiguration(): void
    {
        $container = $this->compile([]);

        $this->assertFalse($container->has(PageFormatterInterface::class));
        $this->assertSame([], $container->get(CurrentStateBuilder::class)->build(['page-1']));
    }

    public function testItCompilesWithPageClasses(): void
    {
        $container = $this->compile(['page_entity_classes' => ['App\\Entity\\Page']]);

        $this->assertTrue($container->has(PageFormatterInterface::class));
    }

    public function testItCompilesWithTheCartDisabled(): void
    {
        $container = $this->compile(['cart' => ['enabled' => false]]);

        $this->assertInstanceOf(CurrentStateBuilder::class, $container->get(CurrentStateBuilder::class));
    }

    private function compile(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('sylius.order_item_quantity_modifier.limit', 9999);
        (new EmporiqaExtension())->load([self::BASE_CONFIG + $config], $container);

        $stubs = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, self::OWN_NAMESPACE)) {
                $definition->setPublic(true);
                $this->collectStubs($definition->getClass() ?? $id, $definition, $stubs);
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if (str_starts_with($id, self::OWN_NAMESPACE)) {
                $alias->setPublic(true);
            }
        }
        foreach ($stubs as $id => $type) {
            if (!$container->has($id)) {
                $container->setDefinition($id, (new Definition($type))->setSynthetic(true)->setPublic(true));
            }
        }

        $container->compile();

        foreach ($stubs as $id => $type) {
            if ($type !== null) {
                $container->set($id, $this->createStub($type));
            }
        }

        return $container;
    }

    /**
     * The services from outside the plugin a plugin service's constructor
     * needs, explicitly wired or autowired, keyed by id with their type.
     *
     * @param array<string, ?string> $stubs
     */
    private function collectStubs(string $class, Definition $definition, array &$stubs): void
    {
        if (!class_exists($class)) {
            return;
        }
        $arguments = $definition->getArguments();
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $i => $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
            $argument = $arguments['$' . $parameter->getName()] ?? $arguments[$i] ?? null;
            if ($argument instanceof Reference) {
                if ($argument->getInvalidBehavior() === ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE
                    && !str_starts_with((string) $argument, self::OWN_NAMESPACE)) {
                    $stubs[(string) $argument] = $typeName;
                }
            } elseif ($argument === null && $definition->isAutowired() && $typeName !== null
                && !str_starts_with($typeName, self::OWN_NAMESPACE)) {
                $stubs[$typeName] = $typeName;
            }
        }
    }
}
