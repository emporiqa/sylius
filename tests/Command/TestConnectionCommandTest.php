<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Command;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Command\TestConnectionCommand;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestConnectionCommandTest extends TestCase
{
    private WebhookSenderInterface $webhookSender;
    private ProductRepositoryInterface $productRepository;
    private ProductFormatterInterface $productFormatter;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->webhookSender = $this->createMock(WebhookSenderInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->productFormatter = $this->createMock(ProductFormatterInterface::class);

        $command = new TestConnectionCommand(
            $this->webhookSender,
            $this->productRepository,
            $this->productFormatter,
        );

        $app = new Application();
        $app->add($command);

        $this->commandTester = new CommandTester($app->find('emporiqa:test-connection'));
    }

    private function createProduct(int $id = 1, string $name = 'Test Product', int $variantCount = 1): ProductInterface
    {
        $variants = [];
        for ($i = 0; $i < $variantCount; $i++) {
            $variants[] = $this->createMock(ProductVariantInterface::class);
        }

        $channel = $this->createMock(ChannelInterface::class);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getCode')->willReturn('TEST-' . $id);
        $product->method('getVariants')->willReturn(new ArrayCollection($variants));
        $product->method('getChannels')->willReturn(new ArrayCollection([$channel]));

        return $product;
    }

    private function mockDryRunResponse(bool $success, int $statusCode = 200, array $responseOverrides = []): void
    {
        $defaultResponse = [
            'status' => 'dry_run',
            'signature' => 'valid',
            'events_validated' => 1,
            'events' => [
                [
                    'type' => 'product.updated',
                    'valid' => true,
                    'identification_number' => 'product-1',
                    'sku' => 'TEST-001',
                    'languages_detected' => ['en'],
                    'channels_detected' => [''],
                    'fields' => ['names' => true, 'prices' => true],
                    'is_parent' => false,
                    'parent_sku' => null,
                    'warnings' => [],
                ],
            ],
        ];

        $result = [
            'success' => $success,
            'status_code' => $statusCode,
            'url' => 'https://emporiqa.com/webhooks/sync/store-1/?dry_run=true',
            'response' => array_merge($defaultResponse, $responseOverrides),
        ];

        $this->webhookSender->method('sendDryRun')->willReturn($result);
    }

    public function testSuccessfulDryRun(): void
    {
        $product = $this->createProduct(1, 'Trail Jacket', 2);
        $this->productRepository->method('findBy')->willReturn([$product]);
        $this->productFormatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['sku' => 'JACKET']],
        ]);

        $this->mockDryRunResponse(true, 200, [
            'events' => [[
                'type' => 'product.updated',
                'valid' => true,
                'identification_number' => 'product-1',
                'sku' => 'JACKET',
                'languages_detected' => ['en', 'de'],
                'channels_detected' => [''],
                'fields' => ['names' => true, 'descriptions' => true, 'categories' => true, 'prices' => true],
                'is_parent' => true,
                'parent_sku' => null,
                'warnings' => [],
            ]],
        ]);

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Trail Jacket', $output);
        $this->assertStringContainsString('dry_run=true', $output);
        $this->assertStringContainsString('valid', $output);
        $this->assertStringContainsString('en, de', $output);
        $this->assertStringContainsString('Dry run passed', $output);
    }

    public function testDryRunWithWarnings(): void
    {
        $product = $this->createProduct();
        $this->productRepository->method('findBy')->willReturn([$product]);
        $this->productFormatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['sku' => 'TEST']],
        ]);

        $this->mockDryRunResponse(true, 200, [
            'events' => [[
                'type' => 'product.updated',
                'valid' => true,
                'identification_number' => 'product-1',
                'sku' => 'TEST',
                'languages_detected' => ['en'],
                'channels_detected' => [''],
                'fields' => ['names' => true, 'prices' => true],
                'is_parent' => false,
                'parent_sku' => null,
                'warnings' => [
                    "No description for language 'en'.",
                    "No categories for language 'en'.",
                ],
            ]],
        ]);

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString("No description for language 'en'.", $output);
        $this->assertStringContainsString("No categories for language 'en'.", $output);
        $this->assertStringContainsString('warnings', $output);
    }

    public function testAuthenticationFailure(): void
    {
        $product = $this->createProduct();
        $this->productRepository->method('findBy')->willReturn([$product]);
        $this->productFormatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['sku' => 'TEST']],
        ]);

        $this->webhookSender->method('sendDryRun')->willReturn([
            'success' => false,
            'status_code' => 401,
            'url' => 'https://emporiqa.com/webhooks/sync/store-1/?dry_run=true',
            'response' => ['error' => 'Invalid signature'],
        ]);

        $this->commandTester->execute([]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('401', $this->commandTester->getDisplay());
        $this->assertStringContainsString('webhook_secret', $this->commandTester->getDisplay());
    }

    public function testValidationFailure(): void
    {
        $product = $this->createProduct();
        $this->productRepository->method('findBy')->willReturn([$product]);
        $this->productFormatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['sku' => 'TEST']],
        ]);

        $this->webhookSender->method('sendDryRun')->willReturn([
            'success' => false,
            'status_code' => 400,
            'url' => 'https://emporiqa.com/webhooks/sync/store-1/?dry_run=true',
            'response' => ['error' => 'Missing required field: names'],
        ]);

        $this->commandTester->execute([]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('400', $this->commandTester->getDisplay());
    }

    public function testNetworkFailure(): void
    {
        $product = $this->createProduct();
        $this->productRepository->method('findBy')->willReturn([$product]);
        $this->productFormatter->method('format')->willReturn([
            ['type' => 'product.updated', 'data' => ['sku' => 'TEST']],
        ]);

        $this->webhookSender->method('sendDryRun')->willReturn([
            'success' => false,
            'error' => 'Connection refused',
            'url' => 'https://emporiqa.com/webhooks/sync/store-1/?dry_run=true',
        ]);

        $this->commandTester->execute([]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Connection refused', $this->commandTester->getDisplay());
    }

    public function testNoProductsFound(): void
    {
        $this->productRepository->method('findBy')->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No products found', $this->commandTester->getDisplay());
    }

    public function testFormatterReturnsEmptyEvents(): void
    {
        $product = $this->createProduct();
        $this->productRepository->method('findBy')->willReturn([$product]);
        $this->productFormatter->method('format')->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('no events', $this->commandTester->getDisplay());
    }

    public function testPrefersProductWithVariants(): void
    {
        $simpleProduct = $this->createProduct(1, 'Simple', 1);
        $variantProduct = $this->createProduct(2, 'Multi-Variant', 3);

        $this->productRepository->method('findBy')->willReturn([$simpleProduct, $variantProduct]);

        $this->productFormatter->expects($this->once())
            ->method('format')
            ->with($variantProduct)
            ->willReturn([['type' => 'product.updated', 'data' => ['sku' => 'MULTI']]]);

        $this->mockDryRunResponse(true);

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Multi-Variant', $output);
        $this->assertStringContainsString('3 variants', $output);
    }
}
