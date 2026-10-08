<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Event\OrderStatusEvent;
use Emporiqa\SyliusPlugin\Service\OrderStatusLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodTranslation;
use Sylius\Component\Product\Model\ProductOption;
use Sylius\Component\Product\Model\ProductOptionValue;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * A found order answers the full order data of the catalog (2026-10
 * addendum) from Sylius's own order, in the order's currency and language:
 * lines that add up to the subtotal, every promotion in one positive
 * discount, both addresses, payment and shipping in the shop's wording.
 * No email, customer id or internal id goes back. The OrderStatusEvent runs
 * after all of it, so a merchant can change any key and add `extra`.
 */
class OrderStatusLookupTest extends TestCase
{
    private const EMAIL = 'anna@example.com';

    private function lookup(Order $order, ?EventDispatcher $dispatcher = null, bool $translator = true, ?AbstractLogger $logger = null): OrderStatusLookup
    {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findOneByNumber')->willReturnCallback(
            fn (string $number) => $number === $order->getNumber() ? $order : null,
        );

        return new OrderStatusLookup($orders, $dispatcher, $translator ? $this->translator() : null, $logger);
    }

    private function translator(): Translator
    {
        $translator = new Translator('en_US');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'sylius.ui.paid' => 'Paid',
            'sylius.ui.awaiting_payment' => 'Awaiting payment',
            'sylius.ui.delivery_time.range' => '%min%-%max% days',
        ], 'en_US');
        $translator->addResource('array', ['sylius.ui.paid' => 'Bezahlt'], 'de_DE');

        return $translator;
    }

    private function find(OrderStatusLookup $lookup, string $number = '000000042'): array
    {
        return $lookup->handle(['fields' => ['order_number' => $number, 'email' => self::EMAIL]]);
    }

    private function adjustment(string $type, int $amount, bool $neutral = false): Adjustment
    {
        $adjustment = new Adjustment();
        $adjustment->setType($type);
        $adjustment->setAmount($amount);
        $adjustment->setNeutral($neutral);
        $adjustment->setLabel($type);

        return $adjustment;
    }

    private function item(string $productName, ?string $variantName, string $code, int $unitPrice, int $quantity, array $options = []): OrderItem
    {
        $product = new Product();
        $variant = new ProductVariant();
        $variant->setCode($code);
        $product->addVariant($variant);
        foreach ($options as $optionName => $value) {
            $option = new ProductOption();
            $option->setCurrentLocale('en_US');
            $option->setFallbackLocale('en_US');
            $option->setName($optionName);
            $product->addOption($option);
            $optionValue = new ProductOptionValue();
            $optionValue->setCurrentLocale('en_US');
            $optionValue->setFallbackLocale('en_US');
            $optionValue->setOption($option);
            $optionValue->setValue($value);
            $variant->addOptionValue($optionValue);
        }

        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setProductName($productName);
        $item->setVariantName($variantName);
        $item->setUnitPrice($unitPrice);
        for ($i = 0; $i < $quantity; ++$i) {
            new OrderItemUnit($item);
        }

        return $item;
    }

    private function address(string $first, string $last, ?string $company = null): Address
    {
        $address = new Address();
        $address->setFirstName($first);
        $address->setLastName($last);
        $address->setCompany($company);
        $address->setStreet('Hauptstraße 5');
        $address->setPostcode('10115');
        $address->setCity('Berlin');
        $address->setProvinceName('Berlin');
        $address->setCountryCode('DE');
        $address->setPhoneNumber('+49 30 1234567');

        return $address;
    }

    private function method(PaymentMethod|ShippingMethod $method, string $name, array $translations = []): void
    {
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName($name);
        foreach ($translations as $locale => $translated) {
            $translation = new PaymentMethodTranslation();
            $translation->setLocale($locale);
            $translation->setName($translated);
            $method->addTranslation($translation);
        }
    }

    /**
     * Two lines (one with options, one with a variant name), a 10% unit
     * promotion, an order promotion spread on the units, a shipping charge
     * with a shipping promotion, and tax added on top.
     */
    private function order(): Order
    {
        $customer = new Customer();
        $customer->setEmail(self::EMAIL);
        $customer->setFirstName('Anna');
        $customer->setLastName('Schmidt');

        $order = new Order();
        $order->setNumber('000000042');
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        $order->setCustomer($customer);
        $order->setCheckoutCompletedAt(new \DateTime('2026-10-01T10:00:00+00:00'));
        $order->setState('new');
        $order->setPaymentState('paid');
        $order->setShippingState('ready');

        $shirt = $this->item('Linen shirt', null, 'SHIRT-BLUE-XL', 4000, 2, ['Color' => 'Blue', 'Size' => 'XL']);
        $mug = $this->item('Mug', 'Large', 'MUG-L', 1250, 1);
        $order->addItem($shirt);
        $order->addItem($mug);
        foreach ($shirt->getUnits() as $unit) {
            $unit->addAdjustment($this->adjustment(AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT, -400));
            $unit->addAdjustment($this->adjustment(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT, -200));
            $unit->addAdjustment($this->adjustment(AdjustmentInterface::TAX_ADJUSTMENT, 646));
        }
        $mug->getUnits()->first()->addAdjustment($this->adjustment(AdjustmentInterface::TAX_ADJUSTMENT, 238));

        $shippingMethod = new ShippingMethod();
        $this->method($shippingMethod, 'DHL Express');
        $shippingMethod->setMinDeliveryTimeDays(2);
        $shippingMethod->setMaxDeliveryTimeDays(4);
        $shipment = new Shipment();
        $shipment->setMethod($shippingMethod);
        $shipment->setTracking('JD0123');
        $order->addShipment($shipment);
        $shipment->addAdjustment($this->adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, 990));
        $order->addAdjustment($this->adjustment(AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT, -490));

        $paymentMethod = new PaymentMethod();
        $this->method($paymentMethod, 'Bank transfer', ['de_DE' => 'Banküberweisung']);
        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setCurrencyCode('EUR');
        $order->addPayment($payment);

        $order->setShippingAddress($this->address('Anna', 'Schmidt'));
        $order->setBillingAddress($this->address('Anna', 'Schmidt', 'Schmidt GmbH'));

        return $order;
    }

    public function testAMultiItemOrderAnswersTheFullOrderData(): void
    {
        $answer = $this->find($this->lookup($this->order()));

        $this->assertSame('found', $answer['status']);
        $data = $answer['data'];
        $this->assertSame('000000042', $data['order_number']);
        $this->assertSame('Anna Schmidt', $data['customer_name']);
        $this->assertSame('EUR', $data['currency']);
        $this->assertEquals([
            [
                'name' => 'Linen shirt - Blue / XL',
                'sku' => 'SHIRT-BLUE-XL',
                'quantity' => 2,
                'unit_price' => 40.0,
                'total_price' => 80.0,
                'variant' => 'Blue / XL',
            ],
            [
                'name' => 'Mug - Large',
                'sku' => 'MUG-L',
                'quantity' => 1,
                'unit_price' => 12.5,
                'total_price' => 12.5,
                'variant' => 'Large',
            ],
        ], $data['items']);
        $this->assertSame('Paid', $data['payment_status']);
        $this->assertSame('Bank transfer', $data['payment_method']);
        $this->assertSame('DHL Express', $data['shipping_method']);
        $this->assertSame('2-4 days', $data['delivery_time']);
        $this->assertSame([['carrier' => 'DHL Express', 'number' => 'JD0123']], $data['tracking']);
        $this->assertSame([
            'name' => 'Anna Schmidt',
            'address1' => 'Hauptstraße 5',
            'postcode' => '10115',
            'city' => 'Berlin',
            'region' => 'Berlin',
            'country' => 'DE',
            'phone' => '+49 30 1234567',
        ], $data['shipping_address']);
        $this->assertSame('Schmidt GmbH', $data['billing_address']['company']);
    }

    public function testTotalsAddUpToWhatTheCustomerPaid(): void
    {
        $order = $this->order();
        $totals = $this->find($this->lookup($order))['data']['totals'];

        // 2 x 40.00 + 12.50; promotions 2 x 4.00 + 2 x 2.00 + 4.90 shipping;
        // tax 2 x 6.46 + 2.38 on top; shipping 9.90.
        $this->assertEquals([
            'subtotal' => 92.5,
            'shipping' => 9.9,
            'tax' => 15.3,
            'discount' => 16.9,
            'total' => 100.8,
        ], $totals);
        $this->assertSame(10080, $order->getTotal(), 'Sylius computed the same total');
        $this->assertEqualsWithDelta(
            $totals['total'],
            $totals['subtotal'] + $totals['shipping'] + $totals['tax'] - $totals['discount'],
            0.001,
        );
    }

    /**
     * Sylius keeps one customer per email, so a guest checkout with an
     * account's email is on the account's customer record. The signed-in
     * customer id alone proves only orders placed signed in; a guest order
     * still needs its email, signed in or not.
     */
    public function testTheCustomerIdAloneDoesNotProveAGuestOrder(): void
    {
        $order = $this->order();
        (new \ReflectionProperty($order->getCustomer(), 'id'))->setValue($order->getCustomer(), 77);
        $this->assertTrue($order->isCreatedByGuest());
        $lookup = $this->lookup($order);

        $byId = $lookup->handle(['fields' => ['order_number' => '000000042'], 'customer' => ['id' => '77']]);
        $byEmail = $lookup->handle(['fields' => ['order_number' => '000000042', 'email' => self::EMAIL], 'customer' => ['id' => '77']]);

        $this->assertSame(['status' => 'not_found'], $byId);
        $this->assertSame('found', $byEmail['status']);
    }

    public function testTheCustomerIdProvesAnOrderPlacedSignedIn(): void
    {
        $order = $this->order();
        $customer = $order->getCustomer();
        (new \ReflectionProperty($customer, 'id'))->setValue($customer, 77);
        $order->setCustomerWithAuthorization($customer);

        $this->assertSame('found', $this->lookup($order)->handle(['fields' => ['order_number' => '000000042'], 'customer' => ['id' => '0077']])['status']);
        $this->assertSame(['status' => 'not_found'], $this->lookup($order)->handle(['fields' => ['order_number' => '000000042'], 'customer' => ['id' => '78']]));
    }

    public function testNoEmailOrIdGoesBack(): void
    {
        $json = (string) json_encode($this->find($this->lookup($this->order())));

        $this->assertStringNotContainsString(self::EMAIL, $json);
        $this->assertStringNotContainsStringIgnoringCase('"id"', $json);
        $this->assertStringNotContainsString('token', $json);
    }

    public function testAGuestWithoutAShippingAddressIsNamedFromTheBillingAddress(): void
    {
        $order = $this->order();
        $order->getCustomer()->setFirstName(null);
        $order->getCustomer()->setLastName(null);
        $order->setShippingAddress(null);

        $data = $this->find($this->lookup($order))['data'];

        $this->assertSame('Anna Schmidt', $data['customer_name']);
        $this->assertArrayNotHasKey('shipping_address', $data);
        $this->assertArrayHasKey('billing_address', $data);
    }

    public function testAnOrderWithoutPromotionsOrTaxSendsNeither(): void
    {
        $order = new Order();
        $order->setNumber('000000042');
        $order->setCurrencyCode('JPY');
        $customer = new Customer();
        $customer->setEmail(self::EMAIL);
        $order->setCustomer($customer);
        $order->setCheckoutCompletedAt(new \DateTime());
        $order->setPaymentState('awaiting_payment');
        $order->addItem($this->item('Teapot', null, 'TEAPOT', 300000, 1));

        $data = $this->find($this->lookup($order, null, false))['data'];

        $this->assertEquals(['subtotal' => 3000, 'total' => 3000], $data['totals']);
        $this->assertSame('awaiting payment', $data['payment_status'], 'no translator: the state, readable');
        $this->assertSame('pending_payment', $data['status_code']);
        $this->assertArrayNotHasKey('shipping_method', $data);
        $this->assertArrayNotHasKey('customer_name', $data);
        $this->assertSame([['name' => 'Teapot', 'sku' => 'TEAPOT', 'quantity' => 1, 'unit_price' => 3000.0, 'total_price' => 3000.0]], $data['items']);
    }

    public function testNamesAreInTheOrdersLanguage(): void
    {
        $order = $this->order();
        $order->setLocaleCode('de_DE');

        $data = $this->find($this->lookup($order))['data'];

        $this->assertSame('Banküberweisung', $data['payment_method']);
        $this->assertSame('Bezahlt', $data['payment_status']);
        $this->assertSame('DHL Express', $data['shipping_method'], 'no German name: the current one');
        $this->assertFalse(
            $order->getShipments()->first()->getMethod()->getTranslations()->containsKey('de_DE'),
            'reading a name never adds an empty translation',
        );
    }

    public function testItemsAreCappedAtFifty(): void
    {
        $order = $this->order();
        for ($i = 0; $i < 60; ++$i) {
            $order->addItem($this->item('Sticker ' . $i, null, 'STICKER-' . $i, 100, 1));
        }

        $this->assertCount(50, $this->find($this->lookup($order))['data']['items']);
    }

    public function testTheEventRunsAfterTheDataAndCanAddExtra(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = null;
        $dispatcher->addListener(OrderStatusEvent::NAME, function (OrderStatusEvent $event) use (&$seen): void {
            $data = $event->getData();
            $seen = $data;
            $data['estimated_delivery'] = '2026-10-09';
            $data['extra'] = ['gift_wrap' => true, 'loyalty_points' => 120];
            unset($data['billing_address']);
            $event->setData($data);
        });

        $data = $this->find($this->lookup($this->order(), $dispatcher))['data'];

        $this->assertArrayHasKey('items', $seen, 'the listener sees the filled data');
        $this->assertSame(['gift_wrap' => true, 'loyalty_points' => 120], $data['extra']);
        $this->assertSame('2026-10-09', $data['estimated_delivery']);
        $this->assertArrayNotHasKey('billing_address', $data);
    }

    public function testAPartThatFailsIsLeftOutAndLogged(): void
    {
        $order = $this->order();
        $broken = $this->createMock(AddressInterface::class);
        $broken->method('getFullName')->willThrowException(new \RuntimeException('proxy not loaded'));
        $order->setBillingAddress($broken);
        $order->getCustomer()->setFirstName(null);
        $order->getCustomer()->setLastName(null);
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $context['part'] ?? '';
            }
        };

        $answer = $this->find($this->lookup($order, null, true, $logger));

        $this->assertSame('found', $answer['status']);
        $this->assertArrayNotHasKey('billing_address', $answer['data']);
        $this->assertArrayNotHasKey('shipping_address', $answer['data']);
        $this->assertArrayNotHasKey('customer_name', $answer['data']);
        $this->assertSame('000000042', $answer['data']['order_number']);
        $this->assertArrayHasKey('items', $answer['data']);
        $this->assertSame(['customer', 'addresses'], $logger->records);
    }

    public function testAFailedPartLogsTheExceptionClassNotItsMessage(): void
    {
        $order = $this->order();
        $broken = $this->createMock(AddressInterface::class);
        $broken->method('getFullName')->willThrowException(new \RuntimeException('Entity of type Address for IDs id(123) was not found'));
        $order->setBillingAddress($broken);
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        $this->find($this->lookup($order, null, true, $logger));

        $this->assertNotEmpty($logger->records);
        foreach ($logger->records as [$message, $context]) {
            $this->assertSame(\RuntimeException::class, $context['exception_class']);
            $this->assertStringNotContainsString('id(123)', $message . json_encode($context));
        }
    }
}
