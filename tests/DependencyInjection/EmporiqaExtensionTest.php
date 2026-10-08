<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\DependencyInjection;

use Emporiqa\SyliusPlugin\DependencyInjection\EmporiqaExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class EmporiqaExtensionTest extends TestCase
{
    private function load(string $webhookUrl): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new EmporiqaExtension())->load([[
            'store_id' => 'test-store',
            'webhook_url' => $webhookUrl,
            'webhook_secret' => 'test-secret',
        ]], $container);

        return $container;
    }

    public function testAPlainHttpWebhookUrlIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('https://');

        $this->load('http://emporiqa.com/webhooks/sync/');
    }

    public function testAnHttpsWebhookUrlLoads(): void
    {
        $container = $this->load('https://emporiqa.com/webhooks/sync/');

        $this->assertSame('https://emporiqa.com/webhooks/sync/', $container->getParameter('emporiqa.webhook_url'));
    }

    public function testAnEnvVarWebhookUrlLoadsAndIsCheckedWhenSent(): void
    {
        // The real value is unknown until runtime; WebhookSender refuses it there.
        $container = new ContainerBuilder();
        $placeholder = $container->getParameterBag()->get('env(EMPORIQA_WEBHOOK_URL)');
        (new EmporiqaExtension())->load([[
            'store_id' => 'test-store',
            'webhook_url' => $placeholder,
            'webhook_secret' => 'test-secret',
        ]], $container);

        $this->assertSame($placeholder, $container->getParameter('emporiqa.webhook_url'));
    }
}
