<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Event\OrderStatusEvent;
use Psr\Log\LoggerInterface;
use Sylius\Component\Addressing\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The `order_status` ready-made rule: request fields in, response envelope out.
 *
 * Read-only. "No such order" and "the email or customer does not match" are
 * one answer on purpose, and required fields are checked before the lookup,
 * so the answer never reveals whether an order exists.
 */
class OrderStatusLookup
{
    private const MAX_TRACKING = 10;
    private const MAX_ITEMS = 50;
    private const MAX_TEXT = 255;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?TranslatorInterface $translator = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * A request field as a trimmed string, '' when missing or not scalar.
     */
    public static function field(array $payload, string $name): string
    {
        $value = $payload['fields'][$name] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array $payload decoded, signature-verified request body
     *
     * @return array response envelope
     */
    public function handle(array $payload): array
    {
        $customerId = (int) CustomerPrices::customerId($payload);

        $orderNumber = ltrim(self::field($payload, 'order_number'), '#');
        $email = self::field($payload, 'email');

        // The email proves the order unless a verified customer stands in for it.
        $missing = [];
        if ($orderNumber === '') {
            $missing[] = 'order_number';
        }
        if ($email === '' && $customerId <= 0) {
            $missing[] = 'email';
        }
        if ($missing !== []) {
            return ['status' => 'rejected', 'ask' => $missing, 'message_code' => 'missing_field'];
        }
        if (!self::isValidOrderNumber($orderNumber)
            || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)
        ) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }

        $order = $this->findOrder($orderNumber);
        if ($order === null || !$this->ownedBy($order, $email, $customerId)) {
            return ['status' => 'not_found'];
        }

        $data = $this->buildData($order);
        if ($this->eventDispatcher !== null) {
            $event = new OrderStatusEvent($data, $order);
            $this->eventDispatcher->dispatch($event, OrderStatusEvent::NAME);
            $data = $event->getData();
        }

        return ['status' => 'found', 'data' => $data];
    }

    /**
     * Sylius order numbers are zero-padded digits by default ("000000042"),
     * but a number generator may add letters or separators.
     */
    public static function isValidOrderNumber(string $orderNumber): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/D', $orderNumber) === 1;
    }

    /**
     * One spelling per order, for rate-limit buckets: "#42", "042" and
     * "000000042" all reach the same order through findOrder().
     */
    public static function normalizeOrderNumber(string $orderNumber): string
    {
        $orderNumber = mb_strtolower(ltrim(trim($orderNumber), '#'));
        if ($orderNumber !== '' && ctype_digit($orderNumber)) {
            return ltrim($orderNumber, '0') ?: '0';
        }

        return $orderNumber;
    }

    /**
     * A placed order with this number, trying the default 9-digit zero
     * padding when a shopper types "42" for "000000042". A cart never counts.
     */
    private function findOrder(string $orderNumber): ?OrderInterface
    {
        $order = $this->orderRepository->findOneByNumber($orderNumber);
        if (!$order instanceof OrderInterface && ctype_digit($orderNumber) && strlen($orderNumber) < 9) {
            $order = $this->orderRepository->findOneByNumber(str_pad($orderNumber, 9, '0', STR_PAD_LEFT));
        }
        if (!$order instanceof OrderInterface || $order->getCheckoutCompletedAt() === null) {
            return null;
        }

        return $order;
    }

    /**
     * With an email, the order's customer email must match it, whoever is
     * signed in: a signed-in shopper looking up a guest order they placed
     * proves it the same way as when signed out. Without one, the verified
     * customer must be the order's customer (by id, never by email), and
     * the order must have been placed signed in: Sylius keeps one customer
     * per email, so a guest checkout with an account's email is on that
     * account's customer record, and only createdByGuest tells it apart.
     */
    private function ownedBy(OrderInterface $order, string $email, int $customerId): bool
    {
        $customer = $order->getCustomer();
        if ($customer === null) {
            return false;
        }
        if ($email === '') {
            return $customerId > 0
                && (int) $customer->getId() === $customerId
                && !self::placedAsGuest($order);
        }
        $orderEmail = (string) $customer->getEmail();

        return $orderEmail !== '' && mb_strtolower($orderEmail) === mb_strtolower($email);
    }

    /**
     * `data` per the catalog schema. Sylius has no store wording for its
     * states, so status_label is left out. The order details are filled
     * part by part: a part that fails (a broken relation, a missing
     * translation) is left out and logged, never the whole answer.
     */
    private function buildData(OrderInterface $order): array
    {
        $data = [
            'status_code' => self::statusCode($order),
            'placed_at' => $order->getCheckoutCompletedAt()?->format('c'),
            'tracking' => [],
        ];

        $seen = [];
        foreach ($order->getShipments() as $shipment) {
            $number = trim((string) $shipment->getTracking());
            if ($number === '' || isset($seen[$number]) || count($data['tracking']) >= self::MAX_TRACKING) {
                continue;
            }
            $seen[$number] = true;
            // Sylius core has no carrier tracking-link template, so no url.
            $data['tracking'][] = [
                'carrier' => (string) $shipment->getMethod()?->getName(),
                'number' => $number,
            ];
        }

        $parts = [
            'order' => fn (): array => [
                'order_number' => self::text($order->getNumber()),
                'currency' => strtoupper(self::text($order->getCurrencyCode())),
            ],
            'customer' => fn (): array => ['customer_name' => self::customerName($order)],
            'items' => fn (): array => ['items' => $this->itemsPart($order)],
            'totals' => fn (): array => ['totals' => $this->totalsPart($order)],
            'payment' => fn (): array => $this->paymentPart($order),
            'shipping' => fn (): array => $this->shippingPart($order),
            'addresses' => fn (): array => [
                'shipping_address' => self::address($order->getShippingAddress()),
                'billing_address' => self::address($order->getBillingAddress()),
            ],
        ];
        foreach ($parts as $name => $part) {
            try {
                $data += array_filter($part(), static fn ($value): bool => $value !== '' && $value !== [] && $value !== null);
            } catch (\Throwable $e) {
                $this->logger?->warning('Emporiqa order_status left out the order {part}', [
                    'part' => $name,
                    'exception_class' => $e::class,
                ]);
            }
        }

        return $data;
    }

    /**
     * The customer's name, or the billing address's for a guest who left
     * none on their customer record.
     */
    private static function customerName(OrderInterface $order): string
    {
        $name = self::text($order->getCustomer()?->getFullName());

        return $name !== '' ? $name : self::text($order->getBillingAddress()?->getFullName());
    }

    /**
     * The ordered lines. `name` is the product name stored on the order,
     * with the variant label the shop shows next to it: the option values
     * for a product with options, else the variant name. Prices are the
     * line's before promotions, which go to totals.discount, so the
     * lines add up to totals.subtotal.
     */
    private function itemsPart(OrderInterface $order): array
    {
        $currency = (string) $order->getCurrencyCode();
        $items = [];
        foreach ($order->getItems() as $item) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            $variant = $this->variantLabel($item, $order->getLocaleCode());
            $name = self::text($item->getProductName());
            if ($name === '') {
                $name = $variant;
                $variant = '';
            } elseif ($variant !== '' && $variant !== $name) {
                $name = self::text($name . ' - ' . $variant);
            }
            $line = [
                'name' => $name,
                'sku' => self::text($item->getVariant()?->getCode()),
                'quantity' => $item->getQuantity(),
                'unit_price' => CurrencyHelper::toCurrencyUnits($item->getUnitPrice(), $currency),
                'total_price' => CurrencyHelper::toCurrencyUnits($item->getUnitPrice() * $item->getQuantity(), $currency),
                'variant' => $variant,
            ];
            $items[] = array_filter($line, static fn ($value): bool => $value !== '');
        }

        return $items;
    }

    /**
     * Without a variant name stored on the line, Sylius reads the live
     * variant's translated name, which can throw when no locale is set; the
     * line then goes without a label rather than the order without lines.
     */
    private function variantLabel(OrderItemInterface $item, ?string $locale): string
    {
        try {
            return $this->readVariantLabel($item, $locale);
        } catch (\Throwable) {
            return '';
        }
    }

    private function readVariantLabel(OrderItemInterface $item, ?string $locale): string
    {
        $variant = $item->getVariant();
        if ($variant !== null && $variant->getProduct()?->hasOptions()) {
            $values = [];
            foreach ($variant->getOptionValues() as $optionValue) {
                $values[] = self::translated($optionValue, $locale, 'getValue');
            }
            $label = self::text(implode(' / ', array_filter($values)));
            if ($label !== '') {
                return $label;
            }
        }

        return self::text($item->getVariantName());
    }

    /**
     * Subtotal, shipping, tax, discount and total, as Sylius computed them
     * when the order was placed. Shipping is the charge before a shipping
     * promotion; discount is every promotion on the order (unit, item,
     * order and shipping) as a positive amount; tax counts tax included in
     * prices too. Discount and tax are sent only when there is some.
     */
    private function totalsPart(OrderInterface $order): array
    {
        $currency = (string) $order->getCurrencyCode();
        $subtotal = 0;
        foreach ($order->getItems() as $item) {
            $subtotal += $item->getUnitPrice() * $item->getQuantity();
        }
        $discount = $order->getOrderPromotionTotal()
            + $order->getAdjustmentsTotal(AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT);
        $tax = $order->getTaxTotal();

        $totals = ['subtotal' => CurrencyHelper::toCurrencyUnits($subtotal, $currency)];
        if (!$order->getShipments()->isEmpty()) {
            $totals['shipping'] = CurrencyHelper::toCurrencyUnits(
                $order->getAdjustmentsTotal(AdjustmentInterface::SHIPPING_ADJUSTMENT),
                $currency,
            );
        }
        if ($tax !== 0) {
            $totals['tax'] = CurrencyHelper::toCurrencyUnits($tax, $currency);
        }
        if ($discount !== 0) {
            $totals['discount'] = CurrencyHelper::toCurrencyUnits(abs($discount), $currency);
        }
        $totals['total'] = CurrencyHelper::toCurrencyUnits($order->getTotal(), $currency);

        return $totals;
    }

    /**
     * The newest payment's method, and the order's payment state in the
     * shop's own wording (the label its order page shows).
     */
    private function paymentPart(OrderInterface $order): array
    {
        $method = $order->getLastPayment()?->getMethod();

        return [
            'payment_method' => $method !== null ? self::translated($method, $order->getLocaleCode(), 'getName') : '',
            'payment_status' => $this->stateLabel($order->getPaymentState(), $order->getLocaleCode()),
        ];
    }

    /**
     * The shipping methods of the order's shipments, and the first one's
     * delivery time (Sylius 2.2 and later), as the shop's order page shows it.
     */
    private function shippingPart(OrderInterface $order): array
    {
        $locale = $order->getLocaleCode();
        $names = [];
        $first = null;
        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getMethod();
            if ($method === null) {
                continue;
            }
            $first ??= $method;
            $names[] = self::translated($method, $locale, 'getName');
        }

        return [
            'shipping_method' => self::text(implode(', ', array_unique(array_filter($names)))),
            'delivery_time' => $first !== null ? $this->deliveryTime($first, $locale) : '',
        ];
    }

    /**
     * Typed as object on purpose: delivery days arrived in Sylius 2.2, and
     * an older ShippingMethodInterface has no such getters.
     */
    private function deliveryTime(object $method, ?string $locale): string
    {
        if (!is_callable([$method, 'getMinDeliveryTimeDays']) || !is_callable([$method, 'getMaxDeliveryTimeDays'])) {
            return '';
        }
        $min = $method->getMinDeliveryTimeDays();
        $max = $method->getMaxDeliveryTimeDays();
        $min = is_int($min) ? $min : null;
        $max = is_int($max) ? $max : null;
        if ($min === null && $max === null) {
            return '';
        }
        if ($min !== null && $max !== null) {
            [$min, $max] = [min($min, $max), max($min, $max)];
            if ($min === $max) {
                return $this->trans('sylius.ui.delivery_time.exact', ['%count%' => $min], $locale, $min . ' days');
            }

            return $this->trans('sylius.ui.delivery_time.range', ['%min%' => $min, '%max%' => $max], $locale, $min . '-' . $max . ' days');
        }
        if ($min !== null) {
            return $this->trans('sylius.ui.delivery_time.min_plus', ['%min%' => $min], $locale, $min . '+ days');
        }

        return $this->trans('sylius.ui.delivery_time.max_until', ['%max%' => $max], $locale, 'up to ' . $max . ' days');
    }

    /**
     * A Sylius order state ("awaiting_payment") as the shop labels it.
     */
    private function stateLabel(?string $state, ?string $locale): string
    {
        if ($state === null || $state === '' || $state === OrderPaymentStates::STATE_CART) {
            return '';
        }

        return $this->trans('sylius.ui.' . $state, [], $locale, str_replace('_', ' ', $state));
    }

    /**
     * The shop's translation, or the fallback when there is no translator
     * or the key has no translation.
     */
    private function trans(string $key, array $parameters, ?string $locale, string $fallback): string
    {
        if ($this->translator !== null) {
            $text = $this->translator->trans($key, $parameters, 'messages', $locale ?: null);
            if ($text !== $key && $text !== '') {
                return self::text($text);
            }
        }

        return self::text($fallback);
    }

    /**
     * A translatable name in the order's language, read from the stored
     * translations: getTranslation($locale) would create and attach a new,
     * empty translation for a missing locale. Falls back to the entity's
     * current-locale value.
     */
    private static function translated(
        PaymentMethodInterface|ShippingMethodInterface|ProductOptionValueInterface $entity,
        ?string $locale,
        string $getter,
    ): string {
        $translation = $locale !== null && $locale !== '' ? $entity->getTranslations()->get($locale) : null;
        if (is_object($translation) && is_callable([$translation, $getter])) {
            $value = self::text($translation->{$getter}());
            if ($value !== '') {
                return $value;
            }
        }

        return self::text($entity->{$getter}());
    }

    /**
     * An address in the catalog shape; empty parts are left out. Sylius has
     * one street line, so there is no address2.
     */
    private static function address(?AddressInterface $address): array
    {
        if ($address === null) {
            return [];
        }
        $region = self::text($address->getProvinceName());

        return array_filter([
            'name' => self::text($address->getFullName()),
            'company' => self::text($address->getCompany()),
            'address1' => self::text($address->getStreet()),
            'postcode' => self::text($address->getPostcode()),
            'city' => self::text($address->getCity()),
            'region' => $region !== '' ? $region : self::text($address->getProvinceCode()),
            'country' => self::text($address->getCountryCode()),
            'phone' => self::text($address->getPhoneNumber()),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * One line of text, whitespace collapsed, at most MAX_TEXT characters.
     */
    private static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return mb_substr($text, 0, self::MAX_TEXT);
    }

    /**
     * Whether a guest checkout placed the order (the Core order flag, on
     * every Sylius this plugin supports, 1.12 and later).
     */
    public static function placedAsGuest(OrderInterface $order): bool
    {
        return $order->isCreatedByGuest();
    }

    public static function statusCode(OrderInterface $order): string
    {
        $payment = $order->getPaymentState();
        $shipping = $order->getShippingState();

        if ($order->getState() === OrderInterface::STATE_CANCELLED || $payment === OrderPaymentStates::STATE_CANCELLED) {
            return 'cancelled';
        }
        if ($payment === OrderPaymentStates::STATE_REFUNDED) {
            return 'refunded';
        }
        if ($shipping === OrderShippingStates::STATE_SHIPPED) {
            return 'shipped';
        }
        if ($shipping === OrderShippingStates::STATE_PARTIALLY_SHIPPED) {
            return 'partially_shipped';
        }
        if (in_array($payment, [
            OrderPaymentStates::STATE_AWAITING_PAYMENT,
            OrderPaymentStates::STATE_PARTIALLY_AUTHORIZED,
            OrderPaymentStates::STATE_PARTIALLY_PAID,
        ], true)) {
            return 'pending_payment';
        }

        return 'processing';
    }
}
