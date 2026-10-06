<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Service\CustomerPrices;
use Emporiqa\SyliusPlugin\Service\PriceEntryBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Taxation\Calculator\DefaultCalculator;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * customer_prices answers what that customer pays in that channel, leaves
 * out anything they cannot buy there without saying why, refuses unknown
 * customers with one answer, and always puts the request's identity back.
 * Every entry carries its own `prices_include_tax`; the top-level flag is
 * true only when every entry's is.
 */
class CustomerPricesTest extends TestCase
{
    private ChannelInterface $channel;
    private ChannelInterface $otherChannel;
    private CustomerRepositoryInterface $customers;
    private ProductRepositoryInterface $products;
    private ProductVariantRepositoryInterface $variants;
    private TokenStorage $tokens;
    /** @var array<int, ProductInterface> */
    private array $productById = [];
    /** @var array<int, ProductVariantInterface> */
    private array $variantById = [];

    protected function setUp(): void
    {
        $this->channel = $this->channel('default', 1);
        $this->otherChannel = $this->channel('B2B', 2);
        $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->products = $this->createMock(ProductRepositoryInterface::class);
        $this->products->method('find')->willReturnCallback(fn ($id) => $this->productById[$id] ?? null);
        $this->variants = $this->createMock(ProductVariantRepositoryInterface::class);
        $this->variants->method('find')->willReturnCallback(fn ($id) => $this->variantById[$id] ?? null);
        $this->tokens = new TokenStorage();
        $this->customers->method('find')->willReturnCallback(fn ($id) => $id === 77 ? $this->customer(true) : ($id === 78 ? $this->customer(false) : null));
    }

    private function channel(string $code, int $id): ChannelInterface
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn('USD');
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getId')->willReturn($id);
        $channel->method('getCode')->willReturn($code);
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('getBaseCurrency')->willReturn($currency);
        $channel->method('getCurrencies')->willReturn(new ArrayCollection([$currency]));

        return $channel;
    }

    private function customer(bool $enabled): CustomerInterface
    {
        $user = $this->createMock(ShopUserInterface::class);
        $user->method('isEnabled')->willReturn($enabled);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($user);

        return $customer;
    }

    /**
     * @param array<int, array{0: int, 1: bool}> $variants id => [price in cents, enabled]
     */
    private function product(int $id, array $variants, bool $enabled = true, bool $inChannel = true): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        $product->method('isEnabled')->willReturn($enabled);
        $product->method('hasChannel')->willReturnCallback(fn ($c) => $inChannel && $c === $this->channel);

        $all = [];
        foreach ($variants as $variantId => [$price, $variantEnabled]) {
            $pricing = $this->createMock(ChannelPricingInterface::class);
            $pricing->method('getPrice')->willReturn($price);
            $pricing->method('getOriginalPrice')->willReturn(null);
            $variant = $this->createMock(ProductVariantInterface::class);
            $variant->method('getId')->willReturn($variantId);
            $variant->method('isEnabled')->willReturn($variantEnabled);
            $variant->method('getProduct')->willReturn($product);
            $variant->method('getChannelPricingForChannel')->willReturnCallback(fn ($c) => $c === $this->channel ? $pricing : null);
            $all[] = $variant;
            $this->variantById[$variantId] = $variant;
        }
        $product->method('getVariants')->willReturn(new ArrayCollection($all));
        $product->method('getEnabledVariants')->willReturn(new ArrayCollection(array_values(array_filter($all, fn ($v) => $v->isEnabled()))));
        $this->productById[$id] = $product;

        return $product;
    }

    private function service(?ProductVariantPricesCalculatorInterface $calculator = null, ?TaxRateResolverInterface $taxRates = null): CustomerPrices
    {
        $channels = $this->createMock(ChannelRepositoryInterface::class);
        $channels->method('findBy')->willReturn([$this->channel, $this->otherChannel]);

        return new CustomerPrices(
            $this->customers,
            $this->products,
            $this->variants,
            new ChannelMappingResolver($channels),
            new PriceEntryBuilder($calculator, null, null, $taxRates, $taxRates !== null ? new DefaultCalculator() : null),
            $this->tokens,
        );
    }

    private function payload(array $products, string $customerId = '77', string $channel = 'default'): array
    {
        return [
            'rule' => 'customer_prices',
            'request_id' => 'r1',
            'currency' => 'USD',
            'country' => 'US',
            'channel' => $channel,
            'language' => 'en',
            'customer' => ['id' => $customerId],
            'products' => $products,
        ];
    }

    public function testPricesASimpleProductAndAConfigurableOneWithItsVariations(): void
    {
        $this->product(1, [11 => [1999, true]]);
        $this->product(2, [21 => [5000, false], 22 => [4500, true], 23 => [4000, true]]);

        $answer = $this->service()->handle($this->payload(['product-1', 'product-2', 'variation-23']));

        $this->assertSame('found', $answer['status']);
        $this->assertSame('USD', $answer['data']['currency']);
        $this->assertTrue($answer['data']['prices_include_tax']);
        $products = $answer['data']['products'];
        $this->assertSame(['current_price' => 19.99, 'regular_price' => 19.99, 'prices_include_tax' => true], $products['product-1']);
        $this->assertSame(45.0, $products['product-2']['current_price'], 'the first enabled variant, as the product page shows');
        $this->assertSame(['variation-22', 'variation-23'], array_keys($products['product-2']['variations']));
        $this->assertSame(40.0, $products['variation-23']['current_price']);
    }

    public function testEachEntrySaysWhetherItsOwnPricesIncludeTax(): void
    {
        $this->channel->method('getDefaultTaxZone')->willReturn($this->createMock(ZoneInterface::class));
        $this->product(1, [11 => [1999, true]]);
        $this->product(2, [21 => [5000, true], 22 => [4500, true]]);
        $taxRates = $this->createMock(TaxRateResolverInterface::class);
        $taxRates->method('resolve')->willReturnCallback(function ($variant) {
            $rate = $this->createMock(TaxRateInterface::class);
            $rate->method('getAmount')->willReturn(0.2);
            $rate->method('isIncludedInPrice')->willReturn($variant->getId() !== 22);
            $rate->method('getCalculator')->willReturn('default');

            return $rate;
        });

        $data = $this->service(null, $taxRates)->handle($this->payload(['product-1', 'product-2', 'variation-22']))['data'];

        $this->assertTrue($data['products']['product-1']['prices_include_tax']);
        $this->assertTrue($data['products']['product-2']['prices_include_tax'], 'the product entry is its first variant');
        $this->assertTrue($data['products']['product-2']['variations']['variation-21']['prices_include_tax']);
        $this->assertFalse($data['products']['product-2']['variations']['variation-22']['prices_include_tax']);
        $this->assertFalse($data['products']['variation-22']['prices_include_tax']);
        $this->assertFalse($data['prices_include_tax'], 'true only when every entry includes tax');
    }

    public function testWhatTheCustomerCannotBuyIsLeftOutSilently(): void
    {
        $this->product(3, [31 => [1000, true]], false);
        $this->product(4, [41 => [1000, true]], true, false);
        $this->product(5, [51 => [1000, false], 52 => [1000, false]]);
        $this->product(6, [61 => [1000, true], 62 => [1200, false]]);

        $answer = $this->service()->handle($this->payload([
            'product-3', 'product-4', 'product-5', 'variation-62', 'product-999', 'variation-999', 'sku-1', 'product-6',
        ]));

        $this->assertSame('found', $answer['status']);
        $this->assertSame(['product-6'], array_keys($answer['data']['products']));
    }

    public function testNothingBuyableIsAnEmptyObjectNotAList(): void
    {
        $answer = $this->service()->handle($this->payload(['product-404']));

        $this->assertSame('{}', json_encode($answer['data']['products']));
    }

    public function testAnotherChannelOnlySeesItsOwnProducts(): void
    {
        $this->product(1, [11 => [1999, true]]);

        $answer = $this->service()->handle($this->payload(['product-1'], '77', 'B2B'));

        $this->assertSame('{}', json_encode($answer['data']['products']));
    }

    public function testUnknownOrDisabledCustomerIsNotFound(): void
    {
        $this->product(1, [11 => [1999, true]]);

        $this->assertSame(['status' => 'not_found'], $this->service()->handle($this->payload(['product-1'], '404')));
        $this->assertSame(['status' => 'not_found'], $this->service()->handle($this->payload(['product-1'], '78')));
    }

    public function testMalformedRequestsAreRejected(): void
    {
        $service = $this->service();

        $this->assertSame('missing_field', $service->handle($this->payload([]))['message_code']);
        $this->assertSame('missing_field', $service->handle($this->payload(['product-1'], 'abc'))['message_code']);
        $this->assertSame('invalid_field', $service->handle($this->payload(array_fill(0, 21, 'product-1')))['message_code']);
        $this->assertSame('invalid_field', $service->handle($this->payload([['id' => 1]]))['message_code']);
        $this->assertSame('invalid_field', $service->handle($this->payload(['product-1'], '77', 'nope'))['message_code']);
    }

    public function testThePriceIsComputedAsTheCustomerAndTheIdentityIsRestored(): void
    {
        $this->product(1, [11 => [1999, true]]);
        $before = $this->createMock(TokenInterface::class);
        $this->tokens->setToken($before);
        $seen = null;
        $calculator = $this->createMock(ProductVariantPricesCalculatorInterface::class);
        $calculator->method('calculate')->willReturnCallback(function ($variant, array $context) use (&$seen) {
            $seen = [$this->tokens->getToken()?->getUser(), $context['customer'] ?? null];

            return 1500;
        });
        $calculator->method('calculateOriginal')->willReturn(1999);

        $answer = $this->service($calculator)->handle($this->payload(['product-1']));

        $this->assertSame(15.0, $answer['data']['products']['product-1']['current_price']);
        $this->assertInstanceOf(ShopUserInterface::class, $seen[0], 'the customer is signed in during the calculation');
        $this->assertInstanceOf(CustomerInterface::class, $seen[1]);
        $this->assertSame($before, $this->tokens->getToken());
    }

    public function testTheIdentityIsRestoredWhenTheCalculationFails(): void
    {
        $this->product(1, [11 => [1999, true]]);
        $calculator = $this->createMock(ProductVariantPricesCalculatorInterface::class);
        $calculator->method('calculate')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->service($calculator)->handle($this->payload(['product-1']));
            $this->fail('the exception reaches the controller, which answers a generic 500');
        } catch (\RuntimeException) {
        }

        $this->assertNull($this->tokens->getToken());
    }

    public function testTheAnswerCarriesNoCustomerData(): void
    {
        $this->product(1, [11 => [1999, true]]);

        $json = (string) json_encode($this->service()->handle($this->payload(['product-1'])));

        $this->assertStringNotContainsString('77', $json);
        $this->assertSame(['status', 'data'], array_keys(json_decode($json, true)));
    }
}
