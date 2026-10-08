<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Event\CustomerInfoEvent;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Service\CustomerInfo;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUser;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The repository query is replaced by a fixed list: what the service must
 * still refuse on its own (carts, another customer's orders, channels not
 * synced) is in that list on purpose.
 */
class FixedOrdersCustomerInfo extends CustomerInfo
{
    /** @var list<OrderInterface> */
    public array $loaded = [];

    /** @var list<string> */
    public array $askedChannels = [];

    protected function loadPlacedOrders(CustomerInterface $customer, array $channelCodes): iterable
    {
        $this->askedChannels = $channelCodes;

        return $this->loaded;
    }
}

class CustomerInfoTest extends TestCase
{
    private Customer $anna;

    private Channel $web;

    private Channel $b2b;

    protected function setUp(): void
    {
        $this->anna = $this->customer(77, 'Anna', 'Berg', 'anna@example.com');
        $this->web = $this->channel('default');
        $this->b2b = $this->channel('B2B');
    }

    private function customer(int $id, string $first, string $last, string $email, bool $account = true): Customer
    {
        $customer = new Customer();
        (new \ReflectionProperty($customer, 'id'))->setValue($customer, $id);
        $customer->setFirstName($first);
        $customer->setLastName($last);
        $customer->setEmail($email);
        if ($account) {
            $user = new ShopUser();
            $user->setEnabled(true);
            $customer->setUser($user);
        }

        return $customer;
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);

        return $channel;
    }

    /**
     * As Sylius places it: a signed-in checkout through
     * setCustomerWithAuthorization(), a guest checkout through setCustomer()
     * (createdByGuest stays true), on the customer record of that email.
     */
    private function order(Customer $customer, string $number, ?string $placedAt, Channel $channel, int $total = 4999, string $state = OrderInterface::STATE_NEW, bool $guest = false): Order
    {
        $order = new Order();
        if ($guest) {
            $order->setCustomer($customer);
        } else {
            $order->setCustomerWithAuthorization($customer);
        }
        $order->setNumber($number);
        $order->setChannel($channel);
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        $order->setState($state);
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);
        $order->setShippingState(OrderShippingStates::STATE_SHIPPED);
        if ($placedAt !== null) {
            $order->setCheckoutCompletedAt(new \DateTimeImmutable($placedAt));
        }
        (new \ReflectionProperty($order, 'total'))->setValue($order, $total);

        return $order;
    }

    /** @param list<string> $syncedChannels */
    private function service(array $syncedChannels = ['default', 'B2B'], ?EventDispatcher $dispatcher = null, ?Customer $found = null): FixedOrdersCustomerInfo
    {
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $customers->method('find')->willReturnCallback(fn ($id) => $id === 77 ? ($found ?? $this->anna) : null);
        $channels = $this->createMock(ChannelRepositoryInterface::class);
        $channels->method('findAll')->willReturn(array_map(fn (string $code) => $this->channel($code), $syncedChannels));
        return new FixedOrdersCustomerInfo(
            $customers,
            $this->createMock(OrderRepositoryInterface::class),
            new ChannelMappingResolver($channels),
            $dispatcher,
        );
    }

    private static function payload(mixed $customerId = '77', bool $test = false): array
    {
        return ['rule' => 'customer_info', 'request_id' => 'r-1', 'customer' => ['id' => $customerId], 'test' => $test];
    }

    public function testTheAccountAndItsPlacedOrdersNewestFirst(): void
    {
        $service = $this->service();
        $service->loaded = [
            $this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web, 4999),
            $this->order($this->anna, '000000031', '2026-10-02T08:30:00+00:00', $this->b2b, 120000, OrderInterface::STATE_FULFILLED),
        ];

        $answer = $service->handle(self::payload());

        $this->assertSame('found', $answer['status']);
        $this->assertSame(['name' => 'Anna Berg', 'first_name' => 'Anna', 'last_name' => 'Berg', 'email' => 'anna@example.com'], $answer['data']['customer']);
        $this->assertSame([
            ['order_number' => '000000031', 'placed_at' => '2026-10-02T08:30:00+00:00', 'status_code' => 'shipped', 'total' => 1200.0, 'currency' => 'EUR'],
            ['order_number' => '000000020', 'placed_at' => '2026-09-01T10:00:00+00:00', 'status_code' => 'shipped', 'total' => 49.99, 'currency' => 'EUR'],
        ], $answer['data']['orders']);
        $this->assertSame(['default', 'B2B'], $service->askedChannels);
    }

    /** Identity: customer B's orders never reach customer A's answer. */
    public function testAnotherCustomersOrdersAreNeverIncluded(): void
    {
        $bob = $this->customer(78, 'Bob', 'Stone', 'bob@example.com');
        $service = $this->service();
        $service->loaded = [
            $this->order($bob, '000000040', '2026-10-03T08:00:00+00:00', $this->web),
            $this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web),
        ];

        $answer = $service->handle(self::payload());

        $this->assertSame(['000000020'], array_column($answer['data']['orders'], 'order_number'));
        $this->assertStringNotContainsString('bob', strtolower((string) json_encode($answer)));
    }

    /**
     * Sylius keeps one customer per email: a guest checkout with the
     * account's email lands on the account's own customer record, told apart
     * only by createdByGuest. Whoever registers with an email that has guest
     * orders must not see them.
     */
    public function testAGuestOrderOnTheAccountsCustomerRecordIsNotIncluded(): void
    {
        $service = $this->service();
        $service->loaded = [
            $this->order($this->anna, '000000050', '2026-10-03T08:00:00+00:00', $this->web, 4999, OrderInterface::STATE_NEW, true),
            $this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web),
        ];

        $this->assertSame(['000000020'], array_column($service->handle(self::payload())['data']['orders'], 'order_number'));
    }

    public function testCustomerIdsAreReadStrictly(): void
    {
        $this->assertSame('found', $this->service()->handle(self::payload('0077'))['status']);
        $this->assertSame('found', $this->service()->handle(self::payload(77))['status']);
        foreach ([true, 77.0, '7e1', ' 77', '-77', '0', ['77']] as $bad) {
            $this->assertSame(['status' => 'rejected', 'message_code' => 'missing_field'], $this->service()->handle(self::payload($bad)), var_export($bad, true));
        }
    }

    public function testCartsAreExcluded(): void
    {
        $service = $this->service();
        $service->loaded = [
            $this->order($this->anna, '', null, $this->web, 1000, OrderInterface::STATE_CART),
            $this->order($this->anna, '000000060', null, $this->web),
            $this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web),
        ];

        $this->assertSame(['000000020'], array_column($service->handle(self::payload())['data']['orders'], 'order_number'));
    }

    /** Only channels this plugin syncs to the store that signed the call. */
    public function testOrdersInChannelsNotSyncedAreExcluded(): void
    {
        $service = $this->service(['default']);
        $service->loaded = [
            $this->order($this->anna, '000000031', '2026-10-02T08:30:00+00:00', $this->b2b),
            $this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web),
        ];

        $answer = $service->handle(self::payload());

        $this->assertSame(['000000020'], array_column($answer['data']['orders'], 'order_number'));
        $this->assertSame(['default'], $service->askedChannels);
    }

    public function testAtMostTenOrders(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= 12; ++$i) {
            $service->loaded[] = $this->order($this->anna, sprintf('%09d', $i), sprintf('2026-09-%02dT10:00:00+00:00', $i), $this->web);
        }

        $orders = $service->handle(self::payload())['data']['orders'];

        $this->assertCount(CustomerInfo::MAX_ORDERS, $orders);
        $this->assertSame('000000012', $orders[0]['order_number']);
    }

    public function testNoOrdersIsAnEmptyList(): void
    {
        $answer = $this->service()->handle(self::payload());

        $this->assertSame('found', $answer['status']);
        $this->assertSame([], $answer['data']['orders']);
    }

    public function testWithoutACustomerIdTheCallIsRejected(): void
    {
        $this->assertSame(['status' => 'rejected', 'message_code' => 'missing_field'], $this->service()->handle(['rule' => 'customer_info', 'request_id' => 'r']));
        $this->assertSame(['status' => 'rejected', 'message_code' => 'missing_field'], $this->service()->handle(self::payload('abc')));
    }

    public function testAnUnknownCustomerIsNotFound(): void
    {
        $this->assertSame(['status' => 'not_found'], $this->service()->handle(self::payload('999')));
    }

    /** A customer record without a shop account is a guest: not found. */
    public function testAGuestRecordIsNotFound(): void
    {
        $guest = $this->customer(77, 'Gus', 'Guest', 'gus@example.com', false);

        $this->assertSame(['status' => 'not_found'], $this->service(['default'], null, $guest)->handle(self::payload()));
    }

    public function testADisabledAccountIsNotFound(): void
    {
        $this->anna->getUser()->setEnabled(false);

        $this->assertSame(['status' => 'not_found'], $this->service()->handle(self::payload()));
    }

    /** The owner's test panel gets the real answer; nothing has side effects. */
    public function testATestCallGetsTheSameAnswer(): void
    {
        $service = $this->service();
        $service->loaded = [$this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web)];

        $this->assertSame($service->handle(self::payload()), $service->handle(self::payload('77', true)));
    }

    public function testEmptyAccountFieldsAreLeftOut(): void
    {
        $this->anna->setLastName(null);

        $customer = $this->service()->handle(self::payload())['data']['customer'];

        $this->assertSame(['name' => 'Anna', 'first_name' => 'Anna', 'email' => 'anna@example.com'], $customer);
    }

    public function testAListenerCanRemoveFieldsAndAddExtra(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = null;
        $dispatcher->addListener(CustomerInfoEvent::NAME, function (CustomerInfoEvent $event) use (&$seen): void {
            $seen = $event->getCustomer();
            $data = $event->getData();
            unset($data['customer']['email']);
            $data['extra'] = ['loyalty_tier' => 'gold'];
            $event->setData($data);
        });
        $service = $this->service(['default', 'B2B'], $dispatcher);

        $answer = $service->handle(self::payload());

        $this->assertSame($this->anna, $seen);
        $this->assertArrayNotHasKey('email', $answer['data']['customer']);
        $this->assertSame(['loyalty_tier' => 'gold'], $answer['data']['extra']);
    }

    /** No addresses, phone, ids or anything beyond the schema. */
    public function testOnlyTheSchemaLeavesTheShop(): void
    {
        $this->anna->setPhoneNumber('+359 888 123 456');
        $service = $this->service();
        $service->loaded = [$this->order($this->anna, '000000020', '2026-09-01T10:00:00+00:00', $this->web)];

        $data = $service->handle(self::payload())['data'];

        $this->assertSame(['customer', 'orders'], array_keys($data));
        $this->assertSame(['order_number', 'placed_at', 'status_code', 'total', 'currency'], array_keys($data['orders'][0]));
        $this->assertStringNotContainsString('888', (string) json_encode($data));
    }
}
