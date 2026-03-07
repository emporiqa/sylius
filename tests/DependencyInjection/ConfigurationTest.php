<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\DependencyInjection;

use Emporiqa\SyliusPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    public function testDefaultConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['webhook_secret' => 'test-secret'],
        ]);

        $this->assertSame('%env(EMPORIQA_STORE_ID)%', $config['store_id']);
        $this->assertSame('%env(EMPORIQA_WEBHOOK_URL)%', $config['webhook_url']);
        $this->assertSame('test-secret', $config['webhook_secret']);
        $this->assertSame('', $config['base_url']);
        $this->assertSame('brand', $config['brand_attribute_code']);
        $this->assertSame(['en_US', 'de_DE'], $config['enabled_languages']);
        $this->assertTrue($config['sync']['products']);
        $this->assertTrue($config['sync']['pages']);
        $this->assertSame([], $config['channel_mapping']);
        $this->assertSame([], $config['page_entity_classes']);
        $this->assertTrue($config['order_tracking']['enabled']);
        $this->assertTrue($config['cart']['enabled']);
    }

    public function testWebhookSecretIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [[]]);
    }

    public function testWebhookSecretCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [
            ['webhook_secret' => ''],
        ]);
    }

    public function testCustomConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'store_id' => 'my-store',
                'webhook_url' => 'https://example.com/webhook',
                'webhook_secret' => 'secret123',
                'enabled_languages' => ['en_US', 'fr_FR'],
                'sync' => [
                    'products' => true,
                    'pages' => false,
                ],
            ],
        ]);

        $this->assertSame('my-store', $config['store_id']);
        $this->assertSame('https://example.com/webhook', $config['webhook_url']);
        $this->assertSame('secret123', $config['webhook_secret']);
        $this->assertSame(['en_US', 'fr_FR'], $config['enabled_languages']);
        $this->assertTrue($config['sync']['products']);
        $this->assertFalse($config['sync']['pages']);
    }

    public function testSyncDefaultsToEnabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['store_id' => 'test', 'webhook_secret' => 'test-secret'],
        ]);

        $this->assertTrue($config['sync']['products']);
        $this->assertTrue($config['sync']['pages']);
    }

    public function testPageEntityClassesConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'webhook_secret' => 'test-secret',
                'page_entity_classes' => [
                    'App\\Entity\\StaticPage',
                    'App\\Entity\\BlogPost',
                ],
            ],
        ]);

        $this->assertSame([
            'App\\Entity\\StaticPage',
            'App\\Entity\\BlogPost',
        ], $config['page_entity_classes']);
    }

    public function testOrderTrackingConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'webhook_secret' => 'test-secret',
                'order_tracking' => ['enabled' => false],
            ],
        ]);

        $this->assertFalse($config['order_tracking']['enabled']);
    }

    public function testOrderTrackingDefaultsToEnabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['webhook_secret' => 'test-secret'],
        ]);

        $this->assertTrue($config['order_tracking']['enabled']);
    }

    public function testCartDefaultsToEnabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['webhook_secret' => 'test-secret'],
        ]);

        $this->assertTrue($config['cart']['enabled']);
    }

    public function testCartCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'webhook_secret' => 'test-secret',
                'cart' => ['enabled' => false],
            ],
        ]);

        $this->assertFalse($config['cart']['enabled']);
    }

    public function testChannelMappingConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'webhook_secret' => 'test-secret',
                'channel_mapping' => [
                    'FASHION_WEB' => '',
                    'FASHION_B2B' => 'b2b',
                ],
            ],
        ]);

        $this->assertSame([
            'FASHION_WEB' => '',
            'FASHION_B2B' => 'b2b',
        ], $config['channel_mapping']);
    }

    public function testChannelMappingDefaultsToEmptyArray(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['webhook_secret' => 'test-secret'],
        ]);

        $this->assertSame([], $config['channel_mapping']);
    }

    public function testBaseUrlConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'webhook_secret' => 'test-secret',
                'base_url' => 'https://myshop.com',
            ],
        ]);

        $this->assertSame('https://myshop.com', $config['base_url']);
    }
}
